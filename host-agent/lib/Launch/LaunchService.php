<?php

declare(strict_types=1);

namespace HostAgent\Launch;

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

        return $launcher instanceof SessionLauncher ? $launcher->launch($request) : $launcher;
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function kill(array $request): array
    {
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
     * @param array<string, mixed> $request
     * @return SessionLauncher|array{ok: false, message: string} the launcher, or the handled failure to return
     */
    private static function launcher_for(array $request): SessionLauncher|array
    {
        $agent = is_string($request['agent'] ?? null) && $request['agent'] !== '' ? $request['agent'] : 'claude';

        if (!isset(self::LAUNCHERS[$agent])) {
            return ['ok' => false, 'message' => "Launching is not supported for agent '{$agent}' (supported: " . implode(', ', array_keys(self::LAUNCHERS)) . ')'];
        }

        $class = self::LAUNCHERS[$agent];

        return new $class();
    }
}
