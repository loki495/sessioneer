<?php

declare(strict_types=1);

namespace HostAgent\Launch;

use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\OpenCodeTranscriptService;
use HostAgent\Services\SessionLifecycleService;
use HostAgent\Services\TmuxService;
use HostAgent\Services\TranscriptRouter;
use HostAgent\Stores\SidecarStore;

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

        $reply = $launcher->launch($request);
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
