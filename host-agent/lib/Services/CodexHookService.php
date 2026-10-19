<?php

declare(strict_types=1);

namespace HostAgent\Services;

/** Installs Sessioneer's lifecycle observer in the user's Codex hooks file. */
class CodexHookService
{
    /** @return array<int, array{event:string, matcher:?string}> */
    private static function hook_defs(): array
    {
        return [
            ['event' => 'SessionStart', 'matcher' => null],
            ['event' => 'UserPromptSubmit', 'matcher' => null],
            ['event' => 'PreToolUse', 'matcher' => '^request_user_input$'],
            ['event' => 'PermissionRequest', 'matcher' => null],
            // Clears either kind of external block after the approved tool
            // or request_user_input call actually finishes.
            ['event' => 'PostToolUse', 'matcher' => '*'],
            ['event' => 'Stop', 'matcher' => null],
            ['event' => 'Interrupt', 'matcher' => null],
            ['event' => 'SessionEnd', 'matcher' => null],
        ];
    }

    /** @param array<string, mixed> $config */
    private static function hook_present(array $config, string $event, ?string $matcher): bool
    {
        $groups = $config['hooks'][$event] ?? [];

        if (!is_array($groups)) {
            return false;
        }

        foreach ($groups as $group) {
            if (!is_array($group)) {
                continue;
            }

            $configuredMatcher = $group['matcher'] ?? null;

            if ($matcher === null) {
                if (!in_array($configuredMatcher, [null, '', '*'], true)) {
                    continue;
                }
            } elseif ($configuredMatcher !== $matcher) {
                continue;
            }

            $hooks = $group['hooks'] ?? [];

            foreach (is_array($hooks) ? $hooks : [] as $hook) {
                if (is_array($hook) && Config::hook_command_matches($hook['command'] ?? null, Config::codex_status_hook_command())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array{event:string, matcher:?string, present:bool}>
     */
    public static function app_hooks_status(array $config): array
    {
        return array_map(
            static fn(array $def): array => $def + ['present' => self::hook_present($config, $def['event'], $def['matcher'])],
            self::hook_defs()
        );
    }

    /** @return array{ok:bool, installed:bool, message?:string} */
    public static function check_session_hook(): array
    {
        $raw = @file_get_contents(Config::codex_hooks_path());

        if ($raw === false) {
            return ['ok' => true, 'installed' => false];
        }

        $config = json_decode($raw, true);

        if (!is_array($config)) {
            return ['ok' => false, 'installed' => false, 'message' => '~/.codex/hooks.json exists but is not valid JSON'];
        }

        foreach (self::app_hooks_status($config) as $hook) {
            if (!$hook['present']) {
                return ['ok' => true, 'installed' => false];
            }
        }

        return ['ok' => true, 'installed' => true];
    }

    /** @return array{ok:bool, installed:bool, message?:string} */
    public static function install_session_hook(): array
    {
        $path = Config::codex_hooks_path();
        $raw = @file_get_contents($path);
        $config = [];

        if ($raw !== false) {
            $config = json_decode($raw, true);

            if (!is_array($config)) {
                return ['ok' => false, 'installed' => false, 'message' => '~/.codex/hooks.json exists but is not valid JSON; it was left unchanged'];
            }
        }

        $missing = array_filter(self::app_hooks_status($config), static fn(array $hook): bool => !$hook['present']);

        if ($missing === []) {
            return ['ok' => true, 'installed' => true];
        }

        $config['hooks'] ??= [];

        if (!is_array($config['hooks'])) {
            return ['ok' => false, 'installed' => false, 'message' => '~/.codex/hooks.json has a non-object hooks value; it was left unchanged'];
        }

        foreach ($missing as $hook) {
            $event = $hook['event'];
            $config['hooks'][$event] ??= [];

            if (!is_array($config['hooks'][$event])) {
                return ['ok' => false, 'installed' => false, 'message' => "~/.codex/hooks.json has an invalid {$event} value; it was left unchanged"];
            }

            $group = [
                'hooks' => [[
                    'type' => 'command',
                    'command' => Config::codex_status_hook_command(),
                    'timeout' => 3,
                ]],
            ];

            if ($hook['matcher'] !== null) {
                $group['matcher'] = $hook['matcher'];
            }

            $config['hooks'][$event][] = $group;
        }

        $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return ['ok' => false, 'installed' => false, 'message' => 'Failed to encode updated Codex hooks.json'];
        }

        if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            return ['ok' => false, 'installed' => false, 'message' => 'Could not create ~/.codex'];
        }

        if (@file_put_contents($path, $encoded . "\n") === false) {
            return ['ok' => false, 'installed' => false, 'message' => 'Could not write ~/.codex/hooks.json'];
        }

        return ['ok' => true, 'installed' => true];
    }
}
