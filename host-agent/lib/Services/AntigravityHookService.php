<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Checks/installs this app's own Antigravity CLI hooks (PreToolUse,
 * PostToolUse, PreInvocation, Stop - see docs/history/antigravity-adapter-plan.md
 * Phase 3) into Config::antigravity_hooks_path() (~/.gemini/config/hooks.json)
 * - the Antigravity counterpart to HookService, not a shared base class
 * with it, since the on-disk schema genuinely differs (a named hook-group
 * wrapper key, and PreToolUse/PostToolUse need a `matcher` regex group
 * while PreInvocation/Stop are a flat handler list - see HOOK_DEFS below).
 * Same "data-driven off one list" shape as HookService::app_hooks_status()
 * though - adding a new hook only ever needs one HOOK_DEFS entry.
 */
class AntigravityHookService
{
    /**
     * The one top-level hook-group name this app writes everything under
     * in hooks.json - never touches any OTHER named group Andres (or a
     * plugin) might already have configured for the same events, matching
     * HookService's own "never mistake a user's own unrelated hook for
     * ours" discipline, just via a distinct group name here instead of a
     * command-string scan (this app's own commands only ever live under
     * this one group, so there's nothing else to scan).
     */
    private const HOOK_GROUP = 'sessioneer';

    /**
     * `grouped: true` (PreToolUse/PostToolUse) needs the `matcher` +
     * `hooks` wrapper; `grouped: false` (PreInvocation/Stop) is a flat
     * `{type, command}` list - see docs/history/antigravity-adapter-plan.md's
     * "Hooks" research section for the confirmed real schema difference.
     * No PostInvocation entry - not used by any Phase 3 script (see that
     * phase's own docblock for why the 4 scripts here are enough for
     * status tracking).
     *
     * @return array<int, array{event:string, command:string, grouped:bool}>
     */
    private static function hook_defs(): array
    {
        return [
            ['event' => 'PreToolUse', 'command' => Config::antigravity_pre_tool_use_hook_command(), 'grouped' => true],
            ['event' => 'PostToolUse', 'command' => Config::antigravity_post_tool_use_hook_command(), 'grouped' => true],
            ['event' => 'PreInvocation', 'command' => Config::antigravity_pre_invocation_hook_command(), 'grouped' => false],
            ['event' => 'Stop', 'command' => Config::antigravity_stop_hook_command(), 'grouped' => false],
        ];
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function hook_command_present(array $config, string $event, string $command, bool $grouped): bool
    {
        $entries = $config[self::HOOK_GROUP][$event] ?? [];

        if (!is_array($entries)) {
            return false;
        }

        if (!$grouped) {
            foreach ($entries as $handler) {
                if (is_array($handler) && ($handler['command'] ?? null) === $command) {
                    return true;
                }
            }

            return false;
        }

        foreach ($entries as $matcherGroup) {
            $hooks = is_array($matcherGroup) ? ($matcherGroup['hooks'] ?? []) : [];

            foreach ((is_array($hooks) ? $hooks : []) as $hook) {
                if (is_array($hook) && ($hook['command'] ?? null) === $command) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $config
     * @return array<int, array{event:string, command:string, grouped:bool, present:bool}>
     */
    public static function app_hooks_status(array $config): array
    {
        return array_map(
            static fn(array $def): array => $def + ['present' => self::hook_command_present($config, $def['event'], $def['command'], $def['grouped'])],
            self::hook_defs()
        );
    }

    /**
     * Same "missing file is normal, unparseable file is an error"
     * discipline as HookService::check_session_hook().
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public static function check_session_hook(): array
    {
        $raw = @file_get_contents(Config::antigravity_hooks_path());

        if ($raw === false) {
            return ['ok' => true, 'installed' => false];
        }

        $config = json_decode($raw, true);

        if (!is_array($config)) {
            return ['ok' => false, 'installed' => false, 'message' => '~/.gemini/config/hooks.json exists but is not valid JSON'];
        }

        $allPresent = true;

        foreach (self::app_hooks_status($config) as $hook) {
            if (!$hook['present']) {
                $allPresent = false;
                break;
            }
        }

        return ['ok' => true, 'installed' => $allPresent];
    }

    /**
     * Adds every missing hook_defs() entry under HOOK_GROUP, creating the
     * file (and its ~/.gemini/config/ directory) if needed. Never
     * overwrites an existing file that fails to parse, and never touches
     * any OTHER top-level hook-group key already in the file - same
     * non-destructive discipline as HookService::install_session_hook().
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public static function install_session_hook(): array
    {
        $path = Config::antigravity_hooks_path();
        $raw = @file_get_contents($path);
        $config = [];

        if ($raw !== false) {
            $config = json_decode($raw, true);

            if (!is_array($config)) {
                return ['ok' => false, 'installed' => false, 'message' => '~/.gemini/config/hooks.json exists but is not valid JSON - fix or add the hooks manually, see README'];
            }
        }

        $missing = array_filter(self::app_hooks_status($config), fn(array $hook): bool => !$hook['present']);

        if ($missing === []) {
            return ['ok' => true, 'installed' => true];
        }

        $config[self::HOOK_GROUP] ??= [];

        foreach ($missing as $hook) {
            $config[self::HOOK_GROUP][$hook['event']] ??= [];

            if ($hook['grouped']) {
                $config[self::HOOK_GROUP][$hook['event']][] = [
                    'matcher' => '.*',
                    'hooks' => [
                        ['type' => 'command', 'command' => $hook['command']],
                    ],
                ];
            } else {
                $config[self::HOOK_GROUP][$hook['event']][] = [
                    'type' => 'command',
                    'command' => $hook['command'],
                ];
            }
        }

        $encoded = json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return ['ok' => false, 'installed' => false, 'message' => 'Failed to encode updated hooks.json'];
        }

        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0700, true);
        }

        if (@file_put_contents($path, $encoded . "\n") === false) {
            return ['ok' => false, 'installed' => false, 'message' => 'Could not write ~/.gemini/config/hooks.json'];
        }

        return ['ok' => true, 'installed' => true];
    }
}
