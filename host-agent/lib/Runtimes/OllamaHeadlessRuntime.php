<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Agents\AgentRegistry;
use HostAgent\Services\Config;
use HostAgent\Services\OllamaClient;
use HostAgent\Services\OllamaTranscriptService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * A local Ollama model as a headless session: no tmux pane, no server-side
 * session object - the conversation is the JSONL file
 * OllamaTranscriptService owns, and a turn is one HTTP request to the model.
 *
 * A turn runs in a detached worker process (host-agent/ollama_turn_worker.php)
 * rather than inside this call. A local model on a small box can take minutes
 * for one answer, and the host agent answers one request per connection while
 * the web UI is a single-threaded `php -S`: streaming inline would freeze every
 * other page (and the browser) for the duration. The worker is a cache, the
 * transcript is the truth - a session with no worker running is simply idle.
 *
 * No tools exist yet. Deliberately: a small local model that invents tool calls
 * is worse than one that answers in text, and the tool loop belongs in one
 * place once we can test it against real models. pending_prompt() therefore
 * always answers "nothing pending", and nothing here can block.
 */
class OllamaHeadlessRuntime implements RuntimeProvider
{
    public const AGENT_ID = 'ollama';

    private OllamaClient $client;

    public function __construct(?OllamaClient $client = null)
    {
        $this->client = $client ?? new OllamaClient();
    }

    public function id(): string
    {
        return RuntimeType::HEADLESS;
    }

    public function isHeadless(): bool
    {
        return true;
    }

    public function isTmux(): bool
    {
        return false;
    }

    public function create(array $options): array
    {
        $workdir = is_string($options['workdir'] ?? null) ? $options['workdir'] : '';

        if ($workdir === '' || $workdir[0] !== '/' || !is_dir($workdir)) {
            return ['ok' => false, 'message' => 'A new Ollama session needs an existing absolute workdir'];
        }

        $model = is_string($options['model'] ?? null) ? trim($options['model']) : '';

        if ($model === '') {
            return ['ok' => false, 'message' => 'Choose a model for the Ollama session'];
        }

        if (!$this->client->has_model($model)) {
            return ['ok' => false, 'message' => $this->missing_model_message($model)];
        }

        $name = HeadlessSessionName::generate(self::AGENT_ID);

        // The model is per-session state, not per-request: every later turn
        // (and every resume after a restart) reads it back from here, the same
        // column the dashboard's model picker shows.
        SessionStatusStore::update_status($name, ['status' => 'idle', 'model' => $model]);

        return [
            'ok' => true,
            'id' => $name,
            'name' => $name,
            'session' => $this->session_entry($name) ?? [],
        ];
    }

    public function list(): array
    {
        $sessions = [];

        foreach (SidecarStore::list_runtime_sidecars(RuntimeType::HEADLESS) as $row) {
            if (($row['agent'] ?? null) !== self::AGENT_ID) {
                continue;
            }

            $entry = $this->session_entry($row['session_name']);

            if ($entry !== null) {
                $sessions[] = $entry;
            }
        }

        return ['ok' => true, 'sessions' => $sessions];
    }

    public function detail(string $sessionRef): array
    {
        $entry = $this->session_entry($sessionRef);

        if ($entry === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        return ['ok' => true, 'session' => $entry + [
            'has_transcript' => OllamaTranscriptService::has_turns($sessionRef),
            'todos' => null,
        ]];
    }

    /**
     * Stops any running turn and forgets the session. The conversation file
     * stays on disk (it is the only copy of the transcript), but the session
     * leaves the dashboard: there is no agent-side archive an Ollama
     * conversation could be discovered back from.
     */
    public function kill(string $sessionRef): array
    {
        $entry = $this->session_entry($sessionRef);

        if ($entry === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        $this->stop_worker($sessionRef);
        SessionStatusStore::delete_status($sessionRef);
        SidecarStore::delete_sidecar($sessionRef);

        return ['ok' => true, 'message' => "Stopped {$sessionRef}"];
    }

    public function status(string $sessionRef): array
    {
        if ($this->session_entry($sessionRef) === null) {
            return ['ok' => false, 'status' => 'idle', 'message' => 'Session not found'];
        }

        $status = SessionStatusStore::read_status($sessionRef);
        $value = is_string($status['status'] ?? null) ? $status['status'] : 'idle';

        // A worker killed by a reboot, a crash or interrupt() leaves the row
        // saying "working" with nothing running. Left alone, the session would
        // show a spinner forever and refuse every new prompt.
        if ($value === 'working' && OllamaTranscriptService::running_pid($sessionRef) === null) {
            SessionStatusStore::update_status($sessionRef, [
                'status' => 'idle',
                'last_turn_error' => 'The answer stopped before it finished',
            ]);
            $value = 'idle';
        }

        return ['ok' => true, 'status' => $value, 'blocked' => null];
    }

    /**
     * Starts one turn. Appending the prompt to the transcript, streaming the
     * answer and the final status are all the worker's job, so this returns as
     * soon as the worker is running.
     */
    public function send_message(string $sessionRef, string $text, array $attachmentPaths = []): array
    {
        $entry = $this->session_entry($sessionRef);

        if ($entry === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        $prompt = trim($text);

        if ($prompt === '') {
            return ['ok' => false, 'message' => 'Refused: the message is empty'];
        }

        // A local model cannot read a file: silently dropping an attachment
        // would look like the model ignored it.
        if ($attachmentPaths !== []) {
            return ['ok' => false, 'message' => 'Refused: an Ollama session cannot take attachments (chat only, no tools)'];
        }

        // One turn at a time: a second concurrent request would interleave two
        // answers into one transcript, and a single-model server serializes
        // them anyway. Refusing says so instead of silently queueing.
        if (OllamaTranscriptService::running_pid($sessionRef) !== null) {
            return ['ok' => false, 'message' => 'Refused: this session is still answering (interrupt it first)'];
        }

        $model = is_string($entry['current_model'] ?? null) ? $entry['current_model'] : '';

        if ($model === '' || !$this->client->has_model($model)) {
            return ['ok' => false, 'message' => $this->missing_model_message($model)];
        }

        if (file_put_contents(OllamaTranscriptService::prompt_path($sessionRef), $prompt) === false) {
            return ['ok' => false, 'message' => 'Could not stage the prompt for the worker'];
        }

        // Marked working BEFORE the worker starts: a fast model can finish
        // inside the spawn call, and a "working" written afterwards would then
        // land on top of the worker's own "idle" and strand the session.
        SessionStatusStore::update_status($sessionRef, [
            'status' => 'working',
            'last_turn_error' => null,
        ]);

        $spawned = $this->spawn_worker($sessionRef, $model);

        if ($spawned !== null) {
            SessionStatusStore::update_status($sessionRef, [
                'status' => 'idle',
                'last_turn_error' => $spawned,
            ]);

            return ['ok' => false, 'message' => $spawned];
        }

        return ['ok' => true, 'message' => 'Sent'];
    }

    public function pending_prompt(string $sessionRef): ?array
    {
        return null;
    }

    public function answer_prompt(string $sessionRef, array $answers): array
    {
        return ['ok' => false, 'message' => 'Refused: Ollama sessions never ask for permission'];
    }

    /**
     * Stops the running turn. Whatever the model had streamed is discarded
     * (nothing shows a partial answer yet), and the session stays usable.
     */
    public function interrupt(string $sessionRef): array
    {
        if ($this->session_entry($sessionRef) === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        $wasRunning = $this->stop_worker($sessionRef);
        SessionStatusStore::update_status($sessionRef, [
            'status' => 'idle',
            'last_turn_error' => $wasRunning ? 'Interrupted' : null,
        ]);

        return ['ok' => true, 'message' => $wasRunning ? 'Interrupted' : 'Nothing was running'];
    }

    /**
     * Model changes apply to the next turn; there is no effort, provider or
     * other setting to accept.
     */
    public function update_settings(string $sessionRef, ?string $model = null, ?string $effort = null, ?string $provider = null): array
    {
        if ($this->session_entry($sessionRef) === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        if ($model === null || trim($model) === '') {
            return ['ok' => false, 'message' => 'Ollama sessions only change model'];
        }

        $model = trim($model);

        if (!$this->client->has_model($model)) {
            return ['ok' => false, 'message' => $this->missing_model_message($model)];
        }

        if (OllamaTranscriptService::running_pid($sessionRef) !== null) {
            return ['ok' => false, 'message' => 'Refused: this session is still answering (interrupt it first)'];
        }

        SessionStatusStore::update_status($sessionRef, ['model' => $model]);

        return ['ok' => true, 'message' => 'Model changed'];
    }

    public function set_mode(string $sessionRef, string $mode): array
    {
        return ['ok' => false, 'message' => 'Ollama sessions have no permission modes'];
    }

    /**
     * The dashboard/detail row for one tracked Ollama session, in the same
     * shape SessionService::build_session_entry() gives a tmux session and
     * ClaudeHeadlessRuntime::session_entry() gives a headless Claude one.
     *
     * @return array<string, mixed>|null null when $name is not one of ours
     */
    public function session_entry(string $name): ?array
    {
        $sidecar = SidecarStore::read_sidecar($name);

        if ($sidecar === null || ($sidecar['agent'] ?? 'claude') !== self::AGENT_ID || ($sidecar['runtime'] ?? null) !== RuntimeType::HEADLESS) {
            return null;
        }

        $status = SessionStatusStore::read_status($name);
        $statusValue = is_string($status['status'] ?? null) ? $status['status'] : 'idle';
        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : null;
        $title = is_string($sidecar['title'] ?? null) && $sidecar['title'] !== ''
            ? $sidecar['title']
            : ($workdir !== null ? basename($workdir) : $name);
        $lastMessage = OllamaTranscriptService::last_assistant_text($name);

        return [
            'name' => $name,
            'activity' => (int)($status['updated_at'] ?? $sidecar['spawned_at'] ?? 0),
            'attached' => false,
            'pid' => null,
            'workdir' => $workdir,
            'spawned_by_app' => $sidecar['spawned_by_app'] ?? true,
            'kind' => 'user',
            'parent_session_id' => null,
            'agent' => self::AGENT_ID,
            'agent_label' => AgentRegistry::get(self::AGENT_ID)->label(),
            'profile' => null,
            'title' => $title,
            'runtime' => RuntimeType::HEADLESS,
            'status' => $statusValue,
            'working' => $statusValue === 'working',
            'blocked_reason' => null,
            'resume_hint' => null,
            'prompt_context' => null,
            'prompt_options' => [],
            'prompt_multi_question' => false,
            'prompt_is_folder_trust' => false,
            'prompt_tool_name' => null,
            'prompt_tool_input' => null,
            'prompt_questions' => null,
            'current_mode' => null,
            'current_model' => is_string($status['model'] ?? null) ? $status['model'] : null,
            'model_labels' => [],
            'current_effort' => null,
            'effort_available' => false,
            'effort_message' => null,
            'current_antigravity_model' => null,
            'last_turn_error' => is_string($status['last_turn_error'] ?? null) ? $status['last_turn_error'] : null,
            'agent_session_id' => $name,
            'last_message' => $lastMessage,
            'context_used_percentage' => null,
            'git_worktree' => null,
            'writable' => true,
            'read_only_reason' => null,
        ];
    }

    /**
     * Starts the per-turn worker as a detached process. Returns an error
     * message on failure, null once it is running and its pid is recorded.
     *
     * fork + exec rather than proc_open+proc_close: proc_close WAITS for the
     * child, which would make this call block for the whole turn and defeat
     * the point. The child must also drop every inherited stream first - the
     * accepted agent socket in particular, because AgentClient reads until EOF
     * and a lingering copy would hang the browser request that started it.
     */
    private function spawn_worker(string $sessionRef, string $model): ?string
    {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_exec')) {
            return 'Ollama sessions need PHP\'s pcntl extension to answer in the background';
        }

        $worker = Config::sessioneer_repo_root() . '/host-agent/ollama_turn_worker.php';

        if (!is_file($worker)) {
            return 'The Ollama turn worker is missing from this checkout';
        }

        $pid = pcntl_fork();

        if ($pid === -1) {
            return 'Could not start the Ollama turn worker';
        }

        if ($pid > 0) {
            @file_put_contents(OllamaTranscriptService::pid_path($sessionRef), (string)$pid);

            return null;
        }

        // Child. Every stream this request held (the agent socket above all)
        // is closed, then stdin/stdout/stderr are reopened onto /dev/null and
        // the worker log by opening them in order, since PHP's fopen() takes
        // the lowest free descriptor.
        foreach (get_resources() as $resource) {
            if (get_resource_type($resource) === 'stream') {
                @fclose($resource);
            }
        }

        $null = @fopen('/dev/null', 'r');
        $log = @fopen(OllamaTranscriptService::dir() . '/worker.log', 'a');
        $logErr = @fopen(OllamaTranscriptService::dir() . '/worker.log', 'a');

        pcntl_exec(PHP_BINARY, [$worker, $sessionRef, $model]);

        // Only reached when exec itself failed.
        exit(1);
    }

    /**
     * Stops the running worker, if any. Returns whether one was running.
     */
    private function stop_worker(string $sessionRef): bool
    {
        $pid = OllamaTranscriptService::running_pid($sessionRef);

        if ($pid === null) {
            @unlink(OllamaTranscriptService::partial_path($sessionRef));

            return false;
        }

        if (function_exists('posix_kill')) {
            @posix_kill($pid, defined('SIGTERM') ? SIGTERM : 15);
        }

        @unlink(OllamaTranscriptService::pid_path($sessionRef));
        @unlink(OllamaTranscriptService::partial_path($sessionRef));

        return true;
    }

    private function missing_model_message(string $model): string
    {
        $models = $this->client->models();

        if ($models['ok'] !== true) {
            return (string)($models['message'] ?? 'Ollama is not reachable');
        }

        $available = array_map(static fn (array $row): string => (string)$row['id'], $models['models'] ?? []);

        return $model === ''
            ? 'Choose a model for the Ollama session'
            : "Model '{$model}' is not on this Ollama server (available: " . implode(', ', $available) . ')';
    }
}
