<?php

declare(strict_types=1);

/**
 * One Ollama turn, run as a detached process.
 *
 * Spawned by OllamaHeadlessRuntime::send_message() with the session name and
 * the model, so the prompt itself arrives in a file (a prompt can be a
 * paragraph of shell-hostile text, and nothing here should ever be re-parsed
 * as a command line):
 *
 *   php host-agent/ollama_turn_worker.php <session-name> <model>
 *
 * Why a separate process at all: a local model on a small box can take minutes
 * for one answer. The host agent answers one request per connection and the
 * web container is a single-threaded `php -S`, so streaming inside the send
 * request would freeze the whole dashboard for that time. This process is a
 * cache - the transcript file is the truth - so being killed leaves the session
 * merely idle.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

use HostAgent\Services\OllamaClient;
use HostAgent\Services\OllamaTranscriptService;
use HostAgent\Stores\SessionStatusStore;

$sessionName = $argv[1] ?? '';
$model = $argv[2] ?? '';

if ($sessionName === '' || $model === '') {
    fwrite(STDERR, "usage: ollama_turn_worker.php <session-name> <model>\n");
    exit(2);
}

// Recorded by this process rather than the spawner so the pid in the file is
// the one actually streaming; a stale file is detected (and removed) by
// OllamaTranscriptService::running_pid().
file_put_contents(OllamaTranscriptService::pid_path($sessionName), (string)getmypid());

$promptPath = OllamaTranscriptService::prompt_path($sessionName);
$prompt = @file_get_contents($promptPath);
@unlink($promptPath);

$finish = static function () use ($sessionName, &$partialHandle): void {
    if (is_resource($partialHandle)) {
        fclose($partialHandle);
    }

    @unlink(OllamaTranscriptService::partial_path($sessionName));
    @unlink(OllamaTranscriptService::pid_path($sessionName));
};

register_shutdown_function($finish);

$partialHandle = null;

if ($prompt === false || trim($prompt) === '') {
    SessionStatusStore::update_status($sessionName, [
        'status' => 'idle',
        'last_turn_error' => 'The prompt was lost before the turn started',
    ]);
    exit(1);
}

$prompt = trim($prompt);

OllamaTranscriptService::append_turn($sessionName, 'user', $prompt);

$partialHandle = @fopen(OllamaTranscriptService::partial_path($sessionName), 'w');
$reply = '';

$result = (new OllamaClient())->chat(
    $model,
    OllamaTranscriptService::messages_for_prompt($sessionName),
    static function (string $chunk) use (&$reply, &$partialHandle): void {
        $reply .= $chunk;

        // Persisted as it arrives so a long answer is visible on disk while it
        // streams, and so an interrupted turn leaves a trace rather than
        // nothing at all.
        if (is_resource($partialHandle)) {
            fwrite($partialHandle, $chunk);
            fflush($partialHandle);
        }
    }
);

if (($result['ok'] ?? false) !== true) {
    $message = trim((string)($result['message'] ?? 'The model did not answer'));

    // A partial answer still belongs in the conversation: it is what the model
    // produced before it failed, and hiding it makes a failed turn look like
    // the prompt was ignored.
    if (trim($reply) !== '') {
        OllamaTranscriptService::append_turn($sessionName, 'assistant', trim($reply) . "\n\n_(answer stopped: {$message})_");
    }

    SessionStatusStore::update_status($sessionName, [
        'status' => 'idle',
        'last_turn_error' => $message,
    ]);
    exit(1);
}

$content = trim((string)$result['content']);
OllamaTranscriptService::append_turn($sessionName, 'assistant', $content);

SessionStatusStore::update_status($sessionName, [
    'status' => 'idle',
    'last_message' => mb_substr($content, -500),
    'last_turn_error' => null,
    'model' => $model,
    'token_usage' => [
        'prompt' => (int)($result['stats']['prompt_eval_count'] ?? 0),
        'completion' => (int)($result['stats']['eval_count'] ?? 0),
    ],
]);

exit(0);
