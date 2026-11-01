<?php

declare(strict_types=1);

namespace HostAgent\Launch;

use HostAgent\Agents\CodexAdapter;
use HostAgent\Runtimes\CodexHeadlessRuntime;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * Codex launches go through the Codex bridge (CodexHeadlessRuntime). A Codex
 * session's name IS its thread id, so a conversation id and a session name
 * are the same thing here.
 *
 * `--mode` maps to the thread's sandbox: absent = workspace-write with
 * on-request approvals (same as the dashboard's New Session), `plan` =
 * read-only, `full` = no sandbox and no approvals, for trusted unattended work.
 */
class CodexSessionLauncher implements SessionLauncher
{
    use PeerLookup;

    private const AGENT_ID = 'codex';

    public function __construct(private ?CodexHeadlessRuntime $runtime = null, private ?CodexAdapter $adapter = null)
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

        foreach (['allowed_tools' => '--allowed-tools', 'disallowed_tools' => '--disallowed-tools'] as $key => $flag) {
            if (isset($request[$key])) {
                return ['ok' => false, 'message' => "{$flag} is not supported for Codex"];
            }
        }

        if (is_string($request['profile'] ?? null) && $request['profile'] !== '') {
            return ['ok' => false, 'message' => '--profile is not supported for Codex'];
        }

        if (!in_array($mode, ['', 'plan', 'full'], true)) {
            return ['ok' => false, 'message' => "--mode '{$mode}' is not supported for Codex (only 'plan', a read-only sandbox, or 'full', no sandbox and no approvals; omit it for workspace-write)"];
        }

        $effort = is_string($request['effort'] ?? null) ? trim($request['effort']) : '';

        if ($effort !== '') {
            $refusal = $this->effort_refusal($effort, is_string($request['model'] ?? null) ? $request['model'] : '', $threadId);

            if ($refusal !== null) {
                return ['ok' => false, 'message' => $refusal];
            }
        }

        if ($threadId === '') {
            if ($prompt === '') {
                return ['ok' => false, 'message' => 'A new session needs a prompt'];
            }

            $workdir = is_string($request['workdir'] ?? null) ? $request['workdir'] : '';

            if ($workdir === '' || $workdir[0] !== '/' || !is_dir($workdir)) {
                return ['ok' => false, 'message' => 'A new session needs an existing absolute workdir'];
            }

            $created = $this->runtime()->create(['workdir' => $workdir, 'model' => $request['model'] ?? null, 'read_only' => $mode === 'plan', 'full_access' => $mode === 'full']);
            $id = $created['ok'] === true && is_string($created['id'] ?? null) ? $created['id'] : null;

            if ($id === null) {
                return ['ok' => false, 'message' => (string)($created['message'] ?? 'Codex did not create a session')];
            }

            // Tracked at once, as the dashboard's New Session does, so the name resolves before the next sync.
            $this->track($id, $workdir);
            $applied = $this->apply_effort($id, $effort);

            return $applied ?? $this->deliver($id, 'created', $prompt);
        }

        if ($this->tracked($threadId) !== null) {
            return $prompt === ''
                ? ['ok' => true, 'name' => $threadId, 'action' => 'reused', 'prompt_sent' => false, 'message' => "Session {$threadId} already tracks that thread"]
                : ($this->apply_effort($threadId, $effort) ?? $this->deliver($threadId, 'reused', $prompt));
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
        $applied = $this->apply_effort($threadId, $effort);

        if ($applied !== null) {
            return $applied;
        }

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

        $status = (string)$this->runtime()->status($name)['status'];

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
                'effort' => SidecarStore::read_sidecar($row['session_name'])['effort'] ?? null,
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

    /** @return ?string why the level cannot be used, null when the model's catalog lists it */
    private function effort_refusal(string $effort, string $model, string $threadId): ?string
    {
        $catalog = ($this->adapter ??= new CodexAdapter())->model_catalog();

        if ($catalog['ok'] !== true) {
            return 'Could not read the Codex model list to validate --effort: ' . (string)($catalog['message'] ?? 'unknown error');
        }

        $models = $catalog['models'];

        if ($model === '' && $threadId === '') {
            $models = array_values(array_filter($models, static fn (array $m): bool => $m['isDefault']));
        } elseif ($model === '') {
            // A resumed thread keeps its own model; any model that lists the level is a fair check.
            return count(array_filter($models, static fn (array $m): bool => in_array($effort, $m['efforts'], true))) > 0 ? null : "--effort '{$effort}' is not offered by any Codex model";
        } else {
            $models = array_values(array_filter($models, static fn (array $m): bool => $m['id'] === $model));

            if ($models === []) {
                return "--effort needs a model Codex lists; '{$model}' is not one";
            }
        }

        $supported = $models[0]['efforts'] ?? [];

        return in_array($effort, $supported, true) ? null : "--effort '{$effort}' is not supported by Codex model '{$models[0]['id']}' (" . ($supported === [] ? 'it lists none' : implode(', ', $supported)) . ')';
    }

    /** @return ?array<string, mixed> the failure reply, null when applied or not requested */
    private function apply_effort(string $threadId, string $effort): ?array
    {
        if ($effort === '') {
            return null;
        }

        $applied = $this->runtime()->update_settings($threadId, null, $effort);

        if ($applied['ok'] !== true) {
            return ['ok' => false, 'name' => $threadId, 'message' => "Session {$threadId} started, but its effort was not set: " . (string)($applied['message'] ?? 'unknown error')];
        }

        SidecarStore::set_effort($threadId, $effort);

        return null;
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
