<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Agents\AgentRegistry;
use HostAgent\Services\BareProcessService;
use HostAgent\Services\ClaudeModelCatalog;
use HostAgent\Services\Config;
use HostAgent\Services\PermissionMode;
use HostAgent\Services\SelectableModel;
use HostAgent\Services\SessionLifecycleService;
use HostAgent\Services\SessionService;
use HostAgent\Services\TranscriptRouter;
use HostAgent\Services\TranscriptService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * Claude Code without a tmux pane: each session is a `claude -p` stream-json
 * process owned by ClaudeHeadlessManager and reached through its socket. The
 * real `claude` binary runs on the user's own logged-in account.
 *
 * A session reference is `claude-headless-<timestamp>` (HeadlessSessionName),
 * NOT Claude's own conversation id - that one rotates on /clear and lives in
 * the sidecar's agent_session_id, from which the transcript is read exactly as
 * it is for a tmux session. The sidecar row is the source of truth; the
 * manager's process is only a cache of it.
 *
 * Failure contract as everywhere in RuntimeProvider: ['ok' => false, 'message'
 * => ...] for anything expected (unknown session, manager down, a rejected
 * answer) - never a throw.
 */
class ClaudeHeadlessRuntime implements RuntimeProvider
{
    private const AGENT_ID = 'claude';

    /** Largest image attached inline; bigger files fall back to a path mention. */
    private const IMAGE_MAX_BYTES = 5242880;

    private const IMAGE_TYPES = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp'];

    /** Seconds a read-only probe (which processes the manager owns) may take: a listing must never wait on a wedged manager. */
    private const PROBE_TIMEOUT_SECONDS = 2;

    private ClaudeHeadlessManagerClient $client;

    private ClaudeHeadlessManagerClient $probeClient;

    public function __construct(?ClaudeHeadlessManagerClient $client = null, ?ClaudeHeadlessManagerClient $probeClient = null)
    {
        $this->client = $client ?? new ClaudeHeadlessManagerClient();
        $this->probeClient = $probeClient ?? new ClaudeHeadlessManagerClient(null, self::PROBE_TIMEOUT_SECONDS, false);
    }

    /**
     * Pids of the `claude` processes the manager currently owns. Empty when
     * the manager cannot be reached: its children die with it, so an
     * unreachable manager owns none.
     *
     * @return int[]
     */
    public function live_child_pids(): array
    {
        $reply = $this->probeClient->request('sessioneer/list');

        if (($reply['ok'] ?? false) !== true || !is_array($reply['children'] ?? null)) {
            return [];
        }

        $pids = [];

        foreach ($reply['children'] as $child) {
            $pid = is_array($child) ? ($child['pid'] ?? null) : null;

            if (is_int($pid) && $pid > 1) {
                $pids[] = $pid;
            }
        }

        return $pids;
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
            return ['ok' => false, 'message' => 'create() requires an existing absolute workdir'];
        }

        $profile = is_string($options['profile'] ?? null) && $options['profile'] !== '' ? $options['profile'] : null;
        $name = HeadlessSessionName::generate(self::AGENT_ID);

        // Sidecar first: it is the truth the manager validates against.
        SidecarStore::write_sidecar($name, [
            'workdir' => $workdir,
            'spawned_at' => time(),
            'agent_session_id' => null,
            'spawned_by_app' => true,
            'agent' => self::AGENT_ID,
            'runtime' => RuntimeType::HEADLESS,
            'title' => null,
            'profile' => $profile,
        ]);

        $reply = $this->client->request('sessioneer/spawn', [
            'session' => $name,
            'fresh' => true,
            'model' => is_string($options['model'] ?? null) ? $options['model'] : null,
            'starting_mode' => is_string($options['starting_mode'] ?? null) ? $options['starting_mode'] : null,
            'enable_task_tools' => !empty($options['enable_task_tools']),
        ]);

        if (($reply['ok'] ?? false) !== true) {
            SidecarStore::delete_sidecar($name);
            SessionStatusStore::delete_status($name);

            return ['ok' => false, 'message' => self::message($reply, 'Could not start the Claude session')];
        }

        return ['ok' => true, 'id' => $name, 'name' => $name, 'session' => $this->session_entry($name) ?? []];
    }

    /**
     * Continues an existing (archived) Claude conversation as a headless
     * session: a new claude-headless-<timestamp> ref bound to that
     * conversation id. Same safeguards as the tmux resume - one process per
     * transcript, whoever holds it (a pane, another headless session, or a
     * bare `claude` typed in a terminal).
     *
     * @return array{ok:bool, name?:string, id?:string, session?:array<string,mixed>, message?:string}
     */
    public function resume(string $workdir, string $agentSessionId, ?string $profile = null): array
    {
        if ($workdir === '' || $workdir[0] !== '/' || !is_dir($workdir)) {
            return ['ok' => false, 'message' => 'Working directory must be an existing absolute path'];
        }

        if ($agentSessionId === '') {
            return ['ok' => false, 'message' => 'Missing agent_session_id'];
        }

        if (!is_dir(Config::sidecar_dir())) {
            @mkdir(Config::sidecar_dir(), 0700, true);
        }

        $busy = ['ok' => false, 'message' => 'This conversation already has a live process - refusing to open a second one on the same transcript'];
        $lock = @fopen(SessionLifecycleService::resume_lock_path($agentSessionId), 'c');

        if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $busy;
        }

        try {
            if (SessionLifecycleService::agent_session_id_already_live($agentSessionId)
                || in_array($agentSessionId, BareProcessService::live_bare_agent_session_ids(), true)) {
                return $busy;
            }

            if (TranscriptRouter::find_transcript_path($agentSessionId, $profile) === null) {
                return ['ok' => false, 'message' => 'No transcript was found for that conversation'];
            }

            $name = HeadlessSessionName::generate(self::AGENT_ID);
            SidecarStore::write_sidecar($name, [
                'workdir' => $workdir,
                'spawned_at' => time(),
                'agent_session_id' => $agentSessionId,
                'spawned_by_app' => true,
                'agent' => self::AGENT_ID,
                'runtime' => RuntimeType::HEADLESS,
                'title' => null,
                'profile' => $profile,
            ]);

            $reply = $this->client->request('sessioneer/spawn', ['session' => $name]);

            if (($reply['ok'] ?? false) !== true) {
                SidecarStore::delete_sidecar($name);
                SessionStatusStore::delete_status($name);

                return ['ok' => false, 'message' => self::message($reply, 'Could not resume the Claude session')];
            }

            return ['ok' => true, 'id' => $name, 'name' => $name, 'message' => "Resumed session {$name} in {$workdir}", 'session' => $this->session_entry($name) ?? []];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
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

        $agentSessionId = is_string($entry['agent_session_id'] ?? null) ? $entry['agent_session_id'] : null;
        $path = $agentSessionId !== null ? TranscriptRouter::find_transcript_path($agentSessionId, is_string($entry['profile'] ?? null) ? $entry['profile'] : null) : null;
        $tasks = $path !== null ? TranscriptService::find_current_task_list($path) : null;
        $todos = $tasks ?? ($path !== null ? TranscriptService::find_latest_todo_list($path) : null);

        return ['ok' => true, 'session' => $entry + ['has_transcript' => $path !== null, 'todos' => $todos]];
    }

    public function kill(string $sessionRef): array
    {
        if ($this->sidecar($sessionRef) === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        $reply = $this->client->request('sessioneer/stop', ['session' => $sessionRef]);

        if (($reply['ok'] ?? false) === true) {
            return ['ok' => true, 'message' => 'Claude session stopped'];
        }

        // The manager's children die with it, so an unreachable manager means
        // there is no process left to stop: removing the session is safe.
        if (str_starts_with(self::message($reply, ''), 'Cannot reach')) {
            return ['ok' => true, 'message' => 'Claude session removed (the manager was not running)'];
        }

        return ['ok' => false, 'message' => self::message($reply, 'Could not stop the Claude session')];
    }

    public function status(string $sessionRef): array
    {
        $entry = $this->session_entry($sessionRef);

        if ($entry === null) {
            return ['ok' => false, 'status' => 'idle', 'message' => 'Session not found'];
        }

        $raw = SessionStatusStore::read_status($sessionRef)['blocked'] ?? null;
        $prompt = ($entry['status'] ?? null) === 'blocked' && is_array($raw) ? ClaudeHeadlessPromptProtocol::canonical_prompt($raw) : null;

        return ['ok' => true, 'status' => (string)$entry['status'], 'blocked' => $prompt];
    }

    public function send_message(string $sessionRef, string $text, array $attachmentPaths = []): array
    {
        $sidecar = $this->sidecar($sessionRef);

        if ($sidecar === null) {
            return ['ok' => false, 'message' => 'Session not found'];
        }

        if (trim($text) === '' && $attachmentPaths === []) {
            return ['ok' => false, 'message' => 'Rejected: empty message'];
        }

        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : '';
        $reply = $this->client->request('sessioneer/sendInput', [
            'session' => $sessionRef,
            'content' => $attachmentPaths === [] ? $text : $this->content_with_attachments($text, $attachmentPaths, $workdir),
        ]);

        return ($reply['ok'] ?? false) === true
            ? ['ok' => true, 'message' => 'Message sent']
            : ['ok' => false, 'message' => self::message($reply, 'Could not send the message')];
    }

    public function pending_prompt(string $sessionRef): ?array
    {
        $raw = $this->raw_pending($sessionRef);

        return $raw === null ? null : ClaudeHeadlessPromptProtocol::canonical_prompt($raw);
    }

    public function answer_prompt(string $sessionRef, array $answers): array
    {
        $raw = $this->raw_pending($sessionRef);

        if ($raw === null) {
            return ['ok' => false, 'message' => 'Rejected: no prompt is currently pending on this session'];
        }

        $response = ClaudeHeadlessPromptProtocol::response($raw, $answers);

        if ($response === null) {
            return ['ok' => false, 'message' => 'Rejected: that answer does not match this prompt'];
        }

        $reply = $this->client->request('sessioneer/answerPrompt', [
            'session' => $sessionRef,
            'request_id' => $raw['request_id'] ?? null,
            'response' => $response,
        ]);

        return ($reply['ok'] ?? false) === true
            ? ['ok' => true, 'message' => 'Prompt answered']
            : ['ok' => false, 'message' => self::message($reply, 'Could not answer the prompt')];
    }

    public function interrupt(string $sessionRef): array
    {
        $reply = $this->client->request('sessioneer/interrupt', ['session' => $sessionRef]);

        return ($reply['ok'] ?? false) === true
            ? ['ok' => true, 'message' => 'Interrupted']
            : ['ok' => false, 'message' => self::message($reply, 'Could not interrupt the session')];
    }

    public function update_settings(string $sessionRef, ?string $model = null, ?string $effort = null, ?string $provider = null): array
    {
        // Only Claude's model families are selectable, exactly as in the tmux
        // picker; "default" has no live in-band equivalent, so it is refused.
        if ($model === null || $model === '' || $model === 'default' || !array_key_exists($model, SelectableModel::PICKER_OPTIONS)) {
            return ['ok' => false, 'message' => 'Rejected: choose one of the model families (sonnet, fable, opus, haiku)'];
        }

        $reply = $this->client->request('sessioneer/setModel', ['session' => $sessionRef, 'model' => $model]);

        if (($reply['ok'] ?? false) !== true) {
            return ['ok' => false, 'message' => self::message($reply, 'Could not change the model')];
        }

        // Show the choice right away; the next turn's init confirms it.
        SessionStatusStore::update_status($sessionRef, ['model' => $model]);

        return ['ok' => true, 'message' => 'Model changed'];
    }

    public function set_mode(string $sessionRef, string $mode): array
    {
        if (!in_array($mode, PermissionMode::HOOK_PERMISSION_MODE_MAP, true)) {
            return ['ok' => false, 'message' => 'Rejected: unknown permission mode'];
        }

        $reply = $this->client->request('sessioneer/setMode', ['session' => $sessionRef, 'mode' => $mode]);

        return ($reply['ok'] ?? false) === true
            ? ['ok' => true, 'message' => 'Mode changed']
            : ['ok' => false, 'message' => self::message($reply, 'Could not change the mode')];
    }

    /**
     * The dashboard/detail row for one tracked headless Claude session, in
     * the same shape SessionService::build_session_entry() gives a tmux
     * session - derived from the sidecar, the status the manager keeps, and
     * the transcript, never from a pane.
     *
     * @return array<string, mixed>|null null when $name is not a headless Claude session
     */
    public function session_entry(string $name): ?array
    {
        $sidecar = $this->sidecar($name);

        if ($sidecar === null) {
            return null;
        }

        $status = SessionStatusStore::read_status($name);
        $statusValue = is_string($status['status'] ?? null) ? $status['status'] : 'idle';
        $raw = is_array($status['blocked'] ?? null) ? $status['blocked'] : null;
        $prompt = $statusValue === 'blocked' && $raw !== null ? ClaudeHeadlessPromptProtocol::canonical_prompt($raw) : null;
        $questions = $prompt !== null && ($prompt['tool_name'] ?? null) === 'AskUserQuestion' && is_array($prompt['tool_input']['questions'] ?? null)
            ? $prompt['tool_input']['questions']
            : null;

        $agentSessionId = is_string($sidecar['agent_session_id'] ?? null) ? $sidecar['agent_session_id'] : null;
        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : null;
        $profile = is_string($sidecar['profile'] ?? null) ? $sidecar['profile'] : null;
        $rawModel = is_string($status['model'] ?? null) ? $status['model'] : null;
        if ($rawModel !== null) {
            ClaudeModelCatalog::record($rawModel);
        }

        $model = $rawModel === null
            ? null
            : (SelectableModel::family_from_raw_model($rawModel) ?? (array_key_exists($rawModel, SelectableModel::PICKER_OPTIONS) ? $rawModel : null));

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
            'profile' => $profile,
            'title' => SessionService::session_title($agentSessionId, null, $workdir, $name, $profile),
            'runtime' => RuntimeType::HEADLESS,
            'status' => $statusValue,
            'working' => $statusValue === 'working',
            'blocked_reason' => $prompt['question'] ?? null,
            'resume_hint' => null,
            'prompt_context' => $prompt['context'] ?? null,
            'prompt_options' => $prompt['options'] ?? [],
            'prompt_multi_question' => $prompt['multi_question'] ?? false,
            'prompt_is_folder_trust' => false,
            'prompt_tool_name' => $prompt['tool_name'] ?? null,
            'prompt_tool_input' => $prompt['tool_input'] ?? null,
            'prompt_questions' => $questions,
            'current_mode' => is_string($status['mode'] ?? null) ? $status['mode'] : null,
            'current_model' => $model,
            'model_labels' => ClaudeModelCatalog::labels(),
            'current_antigravity_model' => null,
            'last_turn_error' => is_string($status['last_turn_error'] ?? null) ? $status['last_turn_error'] : null,
            'agent_session_id' => $agentSessionId,
            'last_message' => SessionService::session_last_message($agentSessionId, $profile),
            'context_used_percentage' => null,
            'git_worktree' => null,
            'writable' => true,
            'read_only_reason' => null,
        ];
    }

    /**
     * The sidecar of a tracked HEADLESS CLAUDE session, else null (a tmux
     * session, another agent's session, or an unknown name all read as "not
     * mine").
     *
     * @return array<string, mixed>|null
     */
    private function sidecar(string $name): ?array
    {
        $sidecar = SidecarStore::read_sidecar($name);

        if ($sidecar === null || ($sidecar['agent'] ?? 'claude') !== self::AGENT_ID || ($sidecar['runtime'] ?? null) !== RuntimeType::HEADLESS) {
            return null;
        }

        return $sidecar;
    }

    /**
     * The manager's raw pending prompt, or null when nothing is pending or the
     * manager cannot be reached.
     *
     * @return array<string, mixed>|null
     */
    private function raw_pending(string $sessionRef): ?array
    {
        if ($this->sidecar($sessionRef) === null) {
            return null;
        }

        $reply = $this->client->request('sessioneer/pendingPrompt', ['session' => $sessionRef]);
        $prompt = $reply['prompt'] ?? null;

        return ($reply['ok'] ?? false) === true && is_array($prompt) ? $prompt : null;
    }

    /**
     * Text plus attachments as Claude content blocks: an image is inlined
     * (base64) when it can be read, every other file is named by path.
     *
     * @param array<int, string> $attachmentPaths
     * @return array<int, array<string, mixed>>
     */
    private function content_with_attachments(string $text, array $attachmentPaths, string $workdir): array
    {
        $blocks = [];
        $mentions = [];

        foreach ($attachmentPaths as $path) {
            $absolute = $path !== '' && $path[0] === '/' ? $path : rtrim($workdir, '/') . '/' . $path;
            $block = $this->image_block($absolute);

            if ($block !== null) {
                $blocks[] = $block;
            } else {
                $mentions[] = '[Attached: ' . $path . ']';
            }
        }

        $body = rtrim($text);

        foreach ($mentions as $mention) {
            $body .= ($body === '' ? '' : "\n") . $mention;
        }

        return array_merge([['type' => 'text', 'text' => $body === '' ? 'See the attached image.' : $body]], $blocks);
    }

    /** @return array<string, mixed>|null */
    private function image_block(string $path): ?array
    {
        $type = self::IMAGE_TYPES[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null;

        if ($type === null || !is_file($path) || (int)filesize($path) > self::IMAGE_MAX_BYTES) {
            return null;
        }

        $bytes = @file_get_contents($path);

        return $bytes === false ? null : ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $type, 'data' => base64_encode($bytes)]];
    }

    /** @param array<string, mixed> $reply */
    private static function message(array $reply, string $fallback): string
    {
        return is_string($reply['message'] ?? null) && $reply['message'] !== '' ? $reply['message'] : $fallback;
    }
}
