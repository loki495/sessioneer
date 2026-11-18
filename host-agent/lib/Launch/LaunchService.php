<?php

declare(strict_types=1);

namespace HostAgent\Launch;

use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\OpenCodeTranscriptService;
use HostAgent\Services\SessionLifecycleService;
use HostAgent\Services\TmuxService;
use HostAgent\Services\TranscriptRouter;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Services\Config;
use HostAgent\Services\ProcessRunner;

/**
 * Resolves the SessionLauncher for a request's agent. The one place that
 * knows which agents can be launched from outside the web UI.
 */
class LaunchService
{
    /** @var array<string, class-string<SessionLauncher>> */
    private const LAUNCHERS = [
        'claude' => ClaudeSessionLauncher::class,
        'codex' => CodexSessionLauncher::class,
        'ollama' => OllamaSessionLauncher::class,
        'opencode' => OpenCodeSessionLauncher::class,
    ];

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function launch(array $request): array
    {
        $launcher = self::launcher_for($request);

        if (!$launcher instanceof SessionLauncher) {
            return $launcher;
        }

        $parent = self::caller($request);
        $reply = $launcher->launch($request);
        if (is_string($reply['name'] ?? null)) {
            if (($reply['action'] ?? null) === 'created' && $parent !== null) {
                SidecarStore::remember_parent($reply['name'], $parent);
            }
        }
        $title = is_string($request['set_name'] ?? null) ? trim($request['set_name']) : '';

        if ($title === '' || ($reply['ok'] ?? false) !== true || !is_string($reply['name'] ?? null)) {
            return $reply;
        }

        // Applied after the launch, so a created, reused or resumed session all get the name.
        $named = self::set_name_of($reply['name'], $title);

        if (($named['ok'] ?? false) !== true) {
            return ['ok' => false, 'name' => $reply['name'], 'message' => "Session {$reply['name']} started, but naming it failed: " . (string)($named['message'] ?? 'unknown error')];
        }

        $reply['title'] = $named['title'];
        $reply['message'] = trim((string)($reply['message'] ?? '') . ' ' . $named['message']);

        return $reply;
    }

    /**
     * @param array<string, mixed> $request
     * @return array{agent:string, profile:?string, agent_session_id:string, name:string}|null
     */
    private static function caller(array $request): ?array
    {
        $name = SessionControl::resolve(is_string($request['from'] ?? null) ? $request['from'] : '');
        $row = $name !== null ? SidecarStore::read_sidecar($name) : null;
        if ($row === null || !is_string($row['agent_session_id'] ?? null) || $row['agent_session_id'] === '') return null;
        return ['agent' => $row['agent'] ?? 'claude', 'profile' => $row['profile'] ?? null, 'agent_session_id' => $row['agent_session_id'], 'name' => $name];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function spawned(array $request): array
    {
        $parent = self::caller($request);
        if ($parent === null) return ['ok' => false, 'message' => 'A tracked calling session is required'];
        $sessions = [];
        foreach (self::children($parent) as $name) {
            $detail = \sessioneer_dispatch_action(['action' => 'session_detail', 'session' => $name]);
            $sessions[] = ['name' => $name, 'spawned_by' => SidecarStore::read_sidecar($name)['spawned_by'] ?? null] + $detail;
        }
        return ['ok' => true, 'sessions' => $sessions];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function reap(array $request): array
    {
        $parent = self::caller($request);
        if ($parent === null) return ['ok' => false, 'message' => 'A tracked calling session is required'];
        $results = [];
        // No session_detail here: it reconciles a stale stored status with the
        // live process, which would hide a working child before it is checked.
        foreach (self::children($parent) as $name) {
            $row = SidecarStore::read_sidecar($name);
            $id = $row['agent_session_id'] ?? null;
            $reason = 'Status, identity, ownership or claims could not be verified';
            $archived = false;
            if (is_string($id) && $id !== '' && self::verified($request, $name, $id, $parent)) {
                $result = self::kill(['target' => $name]);
                $archived = ($result['ok'] ?? false) === true;
                $reason = (string)($result['message'] ?? ($archived ? 'Archived' : 'Archive refused'));
            }
            $results[] = ['name' => $name, 'archived' => $archived, 'reason' => $reason];
        }
        return ['ok' => true, 'results' => $results];
    }

    /**
     * @param array{agent:string, profile:?string, agent_session_id:string, name:string} $parent
     * @return list<string>
     */
    private static function children(array $parent): array
    {
        $names = [];
        foreach (SidecarStore::all_names() as $name) {
            if ($name !== $parent['name'] && (SidecarStore::read_sidecar($name)['spawned_by'] ?? null) === $parent) $names[] = $name;
        }

        return $names;
    }

    /**
     * Re-checks identity, status and claims in sequence so a change made while
     * the claims lookup ran is caught before anything is archived.
     *
     * @param array<string, mixed> $request
     * @param array{agent:string, profile:?string, agent_session_id:string, name:string} $parent
     */
    private static function verified(array $request, string $name, string $id, array $parent): bool
    {
        // The second pass runs right before the mutation: a claim, status or owner
        // taken during the first claims lookup must still be seen.
        for ($pass = 0; $pass < 2; $pass++) {
            if (!self::eligible($name, $id, $parent)) return false;
            if (!self::claims_clear($id)) return false;
            if (self::caller($request) !== $parent) return false;
        }

        return self::eligible($name, $id, $parent);
    }

    /** @param array<string, mixed> $parent */
    private static function eligible(string $name, string $id, array $parent): bool
    {
        $row = SidecarStore::read_sidecar($name);
        if ($row === null || ($row['agent_session_id'] ?? null) !== $id || ($row['spawned_by'] ?? null) !== $parent
            || (SessionStatusStore::read_status($name)['status'] ?? null) !== 'idle') return false;
        $detail = \sessioneer_dispatch_action(['action' => 'session_detail', 'session' => $name]);
        return ($detail['ok'] ?? false) === true && ($detail['status'] ?? null) === 'idle'
            && ($detail['agent_session_id'] ?? null) === $id;
    }

    private static function claims_clear(string $id): bool
    {
        $command = trim(Config::sessioneer_config('SESSIONEER_CLAIMS_CMD', ''));
        if ($command === '') return false;
        $result = ProcessRunner::run_process(['timeout', '-k', '5', '15', 'sh', '-c', $command . ' "$1"', 'sh', $id]);
        if ($result['exit'] !== 0) return false;
        $decoded = json_decode($result['stdout']);
        if (is_array($decoded)) return $decoded === [];
        return is_object($decoded) && array_keys(get_object_vars($decoded)) === ['claims'] && is_array($decoded->claims) && $decoded->claims === [];
    }

    /**
     * Renames an existing tracked session, by name or conversation id.
     *
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function set_name(array $request): array
    {
        $target = trim((string)($request['target'] ?? ''));
        $name = SessionControl::resolve($target);

        if ($name === null) {
            return ['ok' => false, 'message' => "No tracked session found for '{$target}'"];
        }

        $title = is_string($request['set_name'] ?? null) ? trim($request['set_name']) : '';

        if ($title === '') {
            return ['ok' => false, 'name' => $name, 'message' => 'Needs a name'];
        }

        return self::set_name_of($name, $title);
    }

    /** @return array<string, mixed> */
    private static function set_name_of(string $name, string $title): array
    {
        $renamed = \sessioneer_rename_session($name, $title);

        if ($renamed['ok'] !== true) {
            return ['ok' => false, 'name' => $name, 'message' => $renamed['message'] ?? 'Could not rename the session'];
        }

        $shown = (string)($renamed['title'] ?? $title);

        return ['ok' => true, 'name' => $name, 'title' => $shown, 'message' => "Session {$name} is named \"{$shown}\"."];
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function kill(array $request): array
    {
        $target = is_string($request['target'] ?? null) ? trim($request['target']) : '';
        $name = SessionControl::resolve($target);
        $sidecar = $name !== null ? SidecarStore::read_sidecar($name) : null;

        // A terminal (tmux) session of any agent has no launcher: it is a pane.
        $terminal = $sidecar !== null
            ? ($sidecar['runtime'] ?? RuntimeType::TMUX) !== RuntimeType::HEADLESS
            : in_array($target, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true);

        if ($terminal) {
            return self::kill_terminal($name ?? $target, !empty($request['force']));
        }

        $launcher = self::launcher_for($request);

        return $launcher instanceof SessionLauncher ? $launcher->kill($request) : $launcher;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function list_sessions(array $request): array
    {
        $launcher = self::launcher_for($request);

        return $launcher instanceof SessionLauncher ? $launcher->list_sessions($request) : $launcher;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function send(array $request): array
    {
        if (!empty($request['as_user'])) {
            return SessionControl::send_as_user($request);
        }

        $launcher = self::launcher_for($request);

        return $launcher instanceof SessionLauncher ? $launcher->send($request) : $launcher;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function peers(array $request): array
    {
        $launcher = self::launcher_for($request);

        return $launcher instanceof SessionLauncher ? $launcher->peers($request) : $launcher;
    }

    /**
     * Stops a tmux session the way the dashboard always has, with the same
     * refusal for work in progress that a headless kill gives.
     *
     * @return array<string, mixed>
     */
    private static function kill_terminal(string $name, bool $force): array
    {
        if (!$force) {
            $detail = \sessioneer_dispatch_action(['action' => 'session_detail', 'session' => $name]);

            if (($detail['ok'] ?? false) !== true) {
                return ['ok' => false, 'name' => $name, 'message' => "Could not read the status of {$name} (use --force to stop it anyway)"];
            }

            $status = ($detail['status'] ?? null) ?? (!empty($detail['blocked_reason']) ? 'blocked' : (!empty($detail['working']) ? 'working' : 'idle'));

            if ($status !== 'idle') {
                return ['ok' => false, 'name' => $name, 'message' => "Session {$name} is {$status}; stopping it would drop that work (use --force)"];
            }
        }

        $killed = SessionLifecycleService::kill_agent_session($name);

        return ['ok' => $killed['ok'], 'name' => $name, 'message' => $killed['ok'] ? "Session {$name} stopped; its conversation can be resumed" : (string)$killed['message']];
    }

    /**
     * The agent a request is for: the one it names, else the one that owns the
     * session or conversation it targets (so the dashboard, which only has a
     * session name or a conversation id, needs no agent), else Claude.
     *
     * @param array<string, mixed> $request
     */
    private static function agent_for(array $request): string
    {
        if (is_string($request['agent'] ?? null) && $request['agent'] !== '') {
            return $request['agent'];
        }

        $ref = trim((string)($request['target'] ?? $request['agent_session_id'] ?? ''));
        $name = SessionControl::resolve($ref);
        $tracked = $name !== null ? (SidecarStore::read_sidecar($name)['agent'] ?? null) : null;

        if (is_string($tracked) && $tracked !== '') {
            return $tracked;
        }

        if ($ref !== '' && OpenCodeTranscriptService::is_opencode_id($ref)) {
            return 'opencode';
        }

        $path = $ref !== '' ? TranscriptRouter::find_transcript_path($ref) : null;

        return $path !== null && TranscriptRouter::is_codex_path($path) ? 'codex' : 'claude';
    }

    /**
     * @param array<string, mixed> $request
     * @return SessionLauncher|array{ok: false, message: string} the launcher, or the handled failure to return
     */
    private static function launcher_for(array $request): SessionLauncher|array
    {
        $agent = self::agent_for($request);

        if (!isset(self::LAUNCHERS[$agent])) {
            return ['ok' => false, 'message' => "Launching is not supported for agent '{$agent}' (supported: " . implode(', ', array_keys(self::LAUNCHERS)) . ')'];
        }

        $class = self::LAUNCHERS[$agent];

        return new $class();
    }
}
