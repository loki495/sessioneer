<?php

declare(strict_types=1);

namespace HostAgent\Launch;

use HostAgent\Runtimes\CodexHeadlessRuntime;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * Codex launches go through the Codex bridge (CodexHeadlessRuntime). A Codex
 * session's name IS its thread id, so a conversation id and a session name
 * are the same thing here.
 *
 * `--mode` maps to the thread's sandbox: absent = workspace-write (what
 * Dibs-style work needs, same as the dashboard's New Session), `plan` =
 * read-only. Approvals are always on-request.
 */
class CodexSessionLauncher implements SessionLauncher
{
    use PeerLookup;

    private const AGENT_ID = 'codex';

    public function __construct(private ?CodexHeadlessRuntime $runtime = null)
    {
    }

    public function agent_id(): string
    {
        return self::AGENT_ID;
    }

    public function launch(array $request): array
    {
        $prompt = is_string($request['prompt'] ?? null) ? trim($request['prompt']) : '';
        $threadId = is_string($request['agent_session_id'] ?? null) ? trim($request['agent_session_id']) : '';
        $mode = is_string($request['starting_mode'] ?? null) ? $request['starting_mode'] : '';

        if (!empty($request['no_tools'])) {
            return ['ok' => false, 'message' => '--no-tools is not supported for Codex'];
        }

        if (is_string($request['profile'] ?? null) && $request['profile'] !== '') {
            return ['ok' => false, 'message' => '--profile is not supported for Codex'];
        }

        if ($mode !== '' && $mode !== 'plan') {
            return ['ok' => false, 'message' => "--mode '{$mode}' is not supported for Codex (only 'plan', a read-only sandbox; omit it for workspace-write)"];
        }

        if ($threadId === '') {
            if ($prompt === '') {
                return ['ok' => false, 'message' => 'A new session needs a prompt'];
            }

            $workdir = is_string($request['workdir'] ?? null) ? $request['workdir'] : '';

            if ($workdir === '' || $workdir[0] !== '/' || !is_dir($workdir)) {
                return ['ok' => false, 'message' => 'A new session needs an existing absolute workdir'];
            }

            $created = $this->runtime()->create(['workdir' => $workdir, 'model' => $request['model'] ?? null, 'read_only' => $mode === 'plan']);
            $id = $created['ok'] === true && is_string($created['id'] ?? null) ? $created['id'] : null;

            if ($id === null) {
                return ['ok' => false, 'message' => (string)($created['message'] ?? 'Codex did not create a session')];
            }

            // Tracked at once, as the dashboard's New Session does, so the name resolves before the next sync.
            $this->track($id, $workdir);

            return $this->deliver($id, 'created', $prompt);
        }

        if ($this->tracked($threadId) !== null) {
            return $prompt === ''
                ? ['ok' => true, 'name' => $threadId, 'action' => 'reused', 'prompt_sent' => false, 'message' => "Session {$threadId} already tracks that thread"]
                : $this->deliver($threadId, 'reused', $prompt);
        }

        $detail = $this->runtime()->detail($threadId);

        if ($detail['ok'] !== true) {
            return ['ok' => false, 'message' => "No Codex thread found for '{$threadId}': " . (string)($detail['message'] ?? 'unknown error')];
        }

        $workdir = is_string($request['workdir'] ?? null) && $request['workdir'] !== '' ? $request['workdir'] : ($detail['session']['cwd'] ?? null);

        if (!is_string($workdir) || $workdir === '' || $workdir[0] !== '/' || !is_dir($workdir)) {
            return ['ok' => false, 'message' => 'No existing working directory for that thread; pass a workdir'];
        }

        $this->track($threadId, $workdir);

        return $prompt === ''
            ? ['ok' => true, 'name' => $threadId, 'action' => 'resumed', 'prompt_sent' => false, 'message' => "Session {$threadId} resumed"]
            : $this->deliver($threadId, 'resumed', $prompt);
    }

    public function kill(array $request): array
    {
        $target = is_string($request['target'] ?? null) ? trim($request['target']) : '';

        if ($target === '') {
            return ['ok' => false, 'message' => 'Kill needs a session name or conversation id'];
        }

        $name = $this->tracked($target);

        if ($name === null) {
            return ['ok' => false, 'message' => "No tracked Codex session found for '{$target}'"];
        }

        $status = (string)($this->runtime()->status($name)['status'] ?? 'idle');

        if ($status !== 'idle' && empty($request['force'])) {
            return ['ok' => false, 'name' => $name, 'message' => "Session {$name} is {$status}; stopping it would drop that work (use --force)"];
        }

        $killed = $this->runtime()->kill($name);

        if ($killed['ok'] !== true) {
            return ['ok' => false, 'name' => $name, 'message' => (string)($killed['message'] ?? 'Could not stop the session')];
        }

        SidecarStore::delete_sidecar($name);
        SessionStatusStore::delete_status($name);

        return ['ok' => true, 'name' => $name, 'message' => "Session {$name} stopped; its thread can be resumed"];
    }

    public function list_sessions(array $request): array
    {
        $sessions = [];

        foreach (SidecarStore::list_runtime_sidecars(RuntimeType::HEADLESS) as $row) {
            if ($row['agent'] !== self::AGENT_ID) {
                continue;
            }

            $state = SessionStatusStore::read_status($row['session_name']);
            $sessions[] = [
                'name' => $row['session_name'],
                'agent_session_id' => $row['agent_session_id'],
                'status' => $state['status'] ?? 'idle',
                'workdir' => $row['workdir'],
                'title' => $row['title'],
                'model' => $state['model'] ?? null,
            ];
        }

        return ['ok' => true, 'sessions' => $sessions];
    }

    public function send(array $request): array
    {
        $target = is_string($request['target'] ?? null) ? trim($request['target']) : '';
        $message = is_string($request['message'] ?? null) ? trim($request['message']) : '';
        $from = is_string($request['from'] ?? null) ? trim($request['from']) : '';

        if ($target === '' || $message === '') {
            return ['ok' => false, 'message' => 'Send needs a target session and a message'];
        }

        $sender = $from !== '' ? SidecarStore::read_sidecar($from) : null;

        if ($sender === null) {
            return ['ok' => false, 'message' => 'Send must run inside a Sessioneer session (SESSIONEER_SELF or CODEX_THREAD_ID names none that is tracked); use --resume to message a session as yourself'];
        }

        $name = $this->tracked($target);

        if ($name === null) {
            return ['ok' => false, 'message' => "No tracked Codex session found for '{$target}'"];
        }

        if ($name === $from) {
            return ['ok' => false, 'message' => 'A session cannot send a message to itself'];
        }

        return $this->deliver($name, 'sent', $this->peer_stamp($from, $sender) . " {$message}");
    }

    private function runtime(): CodexHeadlessRuntime
    {
        return $this->runtime ??= new CodexHeadlessRuntime();
    }

    private function track(string $threadId, string $workdir): void
    {
        SidecarStore::write_sidecar($threadId, [
            'workdir' => $workdir,
            'spawned_at' => time(),
            'agent_session_id' => $threadId,
            'spawned_by_app' => true,
            'agent' => self::AGENT_ID,
            'runtime' => RuntimeType::HEADLESS,
        ]);
    }

    /** The session name (= thread id) of a tracked Codex session, from a name or a thread id. */
    private function tracked(string $target): ?string
    {
        $name = SidecarStore::read_sidecar($target) !== null ? $target : SidecarStore::find_by_agent_session_id($target);
        $sidecar = $name !== null ? SidecarStore::read_sidecar($name) : null;

        return $name !== null && ($sidecar['agent'] ?? null) === self::AGENT_ID ? $name : null;
    }

    /** @return array<string, mixed> */
    private function deliver(string $name, string $action, string $prompt): array
    {
        $sent = $this->runtime()->send_message($name, $prompt);

        if ($sent['ok'] !== true) {
            return ['ok' => false, 'name' => $name, 'action' => $action, 'prompt_sent' => false, 'message' => "Session {$name} {$action}, but the prompt was not delivered: " . (string)($sent['message'] ?? 'unknown error')];
        }

        return ['ok' => true, 'name' => $name, 'action' => $action, 'prompt_sent' => true, 'message' => "Session {$name} {$action}; prompt delivered"];
    }
}
