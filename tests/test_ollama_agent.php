<?php

declare(strict_types=1);

/**
 * The local-Ollama agent end to end, against a throwaway `php -S` stub of the
 * Ollama HTTP API (tests/fixtures/ollama_stub.php): model catalog, create,
 * a turn run by the detached worker, the transcript it produces, status,
 * interrupt, kill and every refusal.
 *
 * No real model, no GPU, no network: OLLAMA_URL points at the stub, and the
 * whole thing finishes in seconds instead of the minutes a real 8B model
 * answer takes.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/free_port.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Agents\AgentRegistry;
use HostAgent\Runtimes\OllamaHeadlessRuntime;
use HostAgent\Runtimes\RuntimeRegistry;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\Config;
use HostAgent\Services\OllamaTranscriptService;
use HostAgent\Services\TranscriptRouter;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$root = sys_get_temp_dir() . '/sessioneer-test-ollama-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($root . '/work', 0700, true);

// Unconditional, not "only if unset": a test running inside a real Sessioneer
// session inherits its SIDECAR_DIR, and an "if unset" guard would then write
// into the host's real session rows.
$realSidecarDir = Config::sidecar_dir();
putenv('SIDECAR_DIR=' . $root . '/sidecars');
putenv('SESSIONS_SQLITE_FILE=' . $root . '/sessions.sqlite');
@mkdir($root . '/sidecars', 0700, true);

if (Config::sidecar_dir() === $realSidecarDir) {
    fwrite(STDERR, "REFUSING TO RUN: SIDECAR_DIR resolves to the real host sidecar dir.\n");
    exit(1);
}

$stateFile = $root . '/stub-state.json';
file_put_contents($stateFile, json_encode(['mode' => 'normal']));
putenv("SESSIONEER_OLLAMA_STUB_STATE={$stateFile}");

// --- stub server -----------------------------------------------------------------

$port = test_free_port();
$stub = __DIR__ . '/fixtures/ollama_stub.php';
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", $stub, $stateFile],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $serverPipes
);

if (!is_resource($server)) {
    fwrite(STDERR, "failed to start the Ollama stub\n");
    exit(1);
}

$stubUrl = "http://127.0.0.1:{$port}";
putenv("OLLAMA_URL={$stubUrl}");

$runtime = RuntimeRegistry::runtime_for('ollama', RuntimeType::HEADLESS);

/** @var array<int, string> $created */
$created = [];

register_shutdown_function(static function () use (&$created, &$server, $root): void {
    foreach ($created as $name) {
        $pid = OllamaTranscriptService::running_pid($name);
        if ($pid !== null && function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
        }
        SidecarStore::delete_sidecar($name);
        SessionStatusStore::delete_status($name);
    }

    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }

    @exec('rm -rf ' . escapeshellarg($root));
});

/** Waits for a session's turn to finish; returns the final status. */
$wait_idle = static function (string $name, float $seconds = 20.0): array {
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        $status = SessionStatusStore::read_status($name);

        if (($status['status'] ?? null) !== 'working') {
            return $status ?? [];
        }

        usleep(100000);
    }

    return SessionStatusStore::read_status($name) ?? [];
};

/** Creates a session the way Sessions.php's create action does (create + sidecar). */
$make_session = static function (string $model, ?string $workdir = null) use ($runtime, &$created, $root): string {
    $workdir ??= $root . '/work';
    $result = $runtime->create(['workdir' => $workdir, 'model' => $model]);
    assert_true($result['ok'] === true, 'create: succeeds for a model the server has');

    $name = (string)$result['name'];
    $created[] = $name;

    SidecarStore::write_sidecar($name, [
        'workdir' => $workdir,
        'spawned_at' => time(),
        'agent_session_id' => $name,
        'spawned_by_app' => true,
        'agent' => 'ollama',
        'runtime' => RuntimeType::HEADLESS,
        'title' => null,
    ]);

    return $name;
};

// Give the stub a moment to bind.
$up = false;
for ($i = 0; $i < 30 && !$up; $i++) {
    usleep(100000);
    $up = @file_get_contents($stubUrl . '/api/version') !== false;
}
assert_true($up, 'stub server: reached on the reserved port');

// --- adapter + catalog -----------------------------------------------------------

$adapter = AgentRegistry::get('ollama');
assert_equal('ollama', $adapter->id(), 'adapter: id is its registry key');
assert_equal([RuntimeType::HEADLESS], $adapter->supported_runtimes(), 'adapter: headless only, no tmux pane');

$catalog = $adapter->model_catalog();
assert_true($catalog['ok'] === true, 'catalog: lists the server models');
assert_equal(['chat-one:1b'], array_column($catalog['models'], 'id'), 'catalog: only models that can hold a conversation');
assert_equal('chat-one:1b (1.0B)', $catalog['models'][0]['name'] ?? null, 'catalog: the label carries the parameter size');

$deadPort = test_free_port();
putenv("OLLAMA_URL=http://127.0.0.1:{$deadPort}");
$dead = $adapter->model_catalog();
assert_true($dead['ok'] === false, 'catalog: an unreachable server is a handled failure');
assert_true(str_contains((string)($dead['message'] ?? ''), 'not reachable'), 'catalog: says the server is unreachable');
putenv("OLLAMA_URL={$stubUrl}");

// --- create refusals -------------------------------------------------------------

$refused = $runtime->create(['workdir' => $root . '/work', 'model' => '']);
assert_true($refused['ok'] === false && str_contains((string)$refused['message'], 'Choose a model'), 'create: no model is refused, not defaulted');

$missing = $runtime->create(['workdir' => $root . '/work', 'model' => 'nope:7b']);
assert_true($missing['ok'] === false, 'create: a model the server does not have is refused');
assert_true(str_contains((string)$missing['message'], 'chat-one:1b'), 'create: the refusal names what is available');

$embed = $runtime->create(['workdir' => $root . '/work', 'model' => 'embed-one:latest']);
assert_true($embed['ok'] === false, 'create: an embedding model is refused (it cannot chat)');

$badDir = $runtime->create(['workdir' => 'relative/dir', 'model' => 'chat-one:1b']);
assert_true($badDir['ok'] === false && str_contains((string)$badDir['message'], 'absolute workdir'), 'create: a non-absolute workdir is refused');

// --- one full turn ---------------------------------------------------------------

$name = $make_session('chat-one:1b');
$entry = $runtime->session_entry($name);
assert_true($entry !== null, 'session_entry: finds the session from its sidecar');
assert_equal('chat-one:1b', $entry['current_model'] ?? null, 'session_entry: carries the session model');
assert_equal('idle', $entry['status'] ?? null, 'session_entry: a new session is idle');

$sent = $runtime->send_message($name, 'hello there');
assert_true($sent['ok'] === true, 'send_message: accepts a prompt');
$noAttachments = $runtime->send_message($name, 'look at this', ['/tmp/a.png']);
assert_true($noAttachments['ok'] === false && str_contains((string)$noAttachments['message'], 'cannot take attachments'), 'send_message: refuses an attachment instead of dropping it');

$status = $wait_idle($name);
assert_equal('idle', $status['status'] ?? null, 'turn: finishes idle');
assert_equal(null, $status['last_turn_error'] ?? null, 'turn: no error reported');
assert_true(str_contains((string)($status['last_message'] ?? ''), 'stub answer to: hello there'), 'turn: the answer becomes the last message');
assert_equal(12, (int)($status['token_usage']['completion'] ?? 0), 'turn: the model usage is recorded');

$messages = OllamaTranscriptService::messages($name);
assert_equal(2, count($messages), 'transcript: one user turn and one assistant turn');
assert_equal('user', $messages[0]['role'], 'transcript: the user turn comes first');
assert_equal('hello there', $messages[0]['content'], 'transcript: the prompt is stored verbatim');
assert_true(str_contains($messages[1]['content'], 'stub answer to: hello there'), 'transcript: the streamed chunks are joined into one answer');

$path = TranscriptRouter::find_transcript_path($name);
assert_true(is_string($path) && is_file($path), 'transcript router: resolves the session to its own file');
$page = TranscriptRouter::read_transcript_page((string)$path, null, 50);
assert_equal(2, count($page['entries'] ?? []), 'transcript router: the existing reader parses the stored turns');

// --- the dashboard list ----------------------------------------------------------

$rows = sessioneer_merge_headless_sessions([]);
$mine = null;

foreach ($rows as $row) {
    if (($row['name'] ?? null) === $name) {
        $mine = $row;
    }
}

assert_true($mine !== null, 'dashboard merge: an Ollama session appears in the session list');
assert_equal('ollama', $mine['agent'] ?? null, 'dashboard merge: the row names its agent');
assert_equal('ollama-headless-', substr((string)$mine['name'], 0, 16), 'dashboard merge: the row keeps the headless session name');
assert_equal('chat-one:1b', $mine['current_model'] ?? null, 'dashboard merge: the row carries the model');
assert_true(is_string($mine['last_message'] ?? null) && $mine['last_message'] !== '', 'dashboard merge: the row carries the last answer');
assert_equal('idle', $mine['status'] ?? null, 'dashboard merge: the row reports the live status');
assert_equal(false, (bool)($mine['blocked_reason'] ?? false), 'dashboard merge: nothing is ever blocked');

// --- second turn replays the whole conversation ----------------------------------

$runtime->send_message($name, 'and again');
$wait_idle($name);
$messages = OllamaTranscriptService::messages($name);
assert_equal(4, count($messages), 'second turn: the conversation accumulates');
assert_equal(4, count(OllamaTranscriptService::messages_for_prompt($name)), 'second turn: the whole history is replayed to the model');

// --- one turn at a time ----------------------------------------------------------

file_put_contents($stateFile, json_encode(['mode' => 'slow']));
$runtime->send_message($name, 'slow one');
assert_true(($runtime->session_entry($name)['status'] ?? null) === 'working', 'send_message: a session with a turn in flight reads as working');
$second = $runtime->send_message($name, 'too soon');
assert_true($second['ok'] === false, 'a second prompt while answering is refused');
assert_true(str_contains((string)$second['message'], 'still answering'), 'the refusal says why');

$interrupted = $runtime->interrupt($name);
assert_true($interrupted['ok'] === true, 'interrupt: stops the running turn');
assert_equal('idle', SessionStatusStore::read_status($name)['status'] ?? null, 'interrupt: the session is idle again');
assert_equal('Interrupted', SessionStatusStore::read_status($name)['last_turn_error'] ?? null, 'interrupt: says the turn was interrupted');

$again = $runtime->interrupt($name);
assert_true($again['ok'] === true && str_contains((string)$again['message'], 'Nothing was running'), 'interrupt: a second interrupt has nothing to stop');

// --- a failed turn ---------------------------------------------------------------

file_put_contents($stateFile, json_encode(['mode' => 'error']));
$runtime->send_message($name, 'this one fails');
$failed = $wait_idle($name);
assert_equal('idle', $failed['status'] ?? null, 'failed turn: the session goes back to idle, never stuck working');
assert_true(str_contains((string)($failed['last_turn_error'] ?? ''), 'ran out of memory'), 'failed turn: the model error is reported');

// --- a worker that died mid-turn ------------------------------------------------

SidecarStore::write_sidecar($name, [
    'workdir' => $root . '/work',
    'spawned_at' => time(),
    'agent_session_id' => $name,
    'spawned_by_app' => true,
    'agent' => 'ollama',
    'runtime' => RuntimeType::HEADLESS,
]);
SessionStatusStore::update_status($name, ['status' => 'working', 'last_turn_error' => null]);
@unlink(OllamaTranscriptService::pid_path($name));

$healed = $runtime->status($name);
assert_equal('idle', $healed['status'] ?? null, 'status: a working row with no worker is healed to idle');
assert_true(str_contains((string)(SessionStatusStore::read_status($name)['last_turn_error'] ?? ''), 'stopped before it finished'), 'status: the heal explains itself');

// --- nothing to answer, nothing to switch ---------------------------------------

assert_equal(null, $runtime->pending_prompt($name), 'pending_prompt: an Ollama session never blocks');
$answer = $runtime->answer_prompt($name, ['option' => 1]);
assert_true($answer['ok'] === false && str_contains((string)$answer['message'], 'never ask for permission'), 'answer_prompt: refused, with the reason');

$mode = $runtime->set_mode($name, 'plan');
assert_true($mode['ok'] === false && str_contains((string)$mode['message'], 'no permission modes'), 'set_mode: refused, with the reason');

$switch = $runtime->update_settings($name, 'embed-one:latest');
assert_true($switch['ok'] === false, 'update_settings: refuses a model that cannot chat');
$switch = $runtime->update_settings($name, 'chat-one:1b');
assert_true($switch['ok'] === true, 'update_settings: accepts a model the server has');
$switch = $runtime->update_settings($name, null, 'high');
assert_true($switch['ok'] === false && str_contains((string)$switch['message'], 'only change model'), 'update_settings: no effort level exists');

// --- kill ------------------------------------------------------------------------

$kill = $runtime->kill($name);
assert_true($kill['ok'] === true, 'kill: stops the session');
assert_equal(null, SidecarStore::read_sidecar($name), 'kill: the session leaves the dashboard');
assert_true(is_file(OllamaTranscriptService::transcript_path($name)), 'kill: the transcript file is kept (it is the only copy)');

$missingKill = $runtime->kill('ollama-headless-19700101-000000');
assert_true($missingKill['ok'] === false && str_contains((string)$missingKill['message'], 'Session not found'), 'kill: an unknown session is a handled failure');

test_exit();
