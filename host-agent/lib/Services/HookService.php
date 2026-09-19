<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Checks/installs this app's own Claude Code hooks (SessionStart,
 * PreToolUse, PermissionRequest, UserPromptSubmit, Stop) into
 * ~/.claude/settings.json - entirely data-driven off app_hooks_status(),
 * so adding a new hook only ever needs one line added there.
 */
class HookService
{
    /**
     * PHP's JSON_PRETTY_PRINT always uses 4-space indent with no way to
     * configure it - re-indented to 2 spaces here to match how
     * ~/.claude/settings.json already looks (and how Claude Code itself
     * writes it), so installing the hook doesn't reformat every other line
     * in a file that isn't otherwise ours to restyle.
     */
    public static function reindent_json_pretty(string $json): string
    {
        $lines = explode("\n", $json);

        foreach ($lines as &$line) {
            if (preg_match('/^( +)/', $line, $m) === 1) {
                $line = str_repeat(' ', intdiv(strlen($m[1]), 2)) . substr($line, strlen($m[1]));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * True if ~/.claude/settings.json already has a hook entry under $event
     * running the exact given command - checked by command string, not just
     * "a hook of this type exists", so a user's own unrelated hooks are never
     * mistaken for ours. Shared by session_start_hook_present() and
     * pre_tool_use_hook_present() below.
     *
     * @param array<string, mixed> $settings
     */
    public static function hook_command_present(array $settings, string $event, string $command): bool
    {
        $entries = $settings['hooks'][$event] ?? [];

        if (!is_array($entries)) {
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
     * @param array<string, mixed> $settings
     */
    public static function session_start_hook_present(array $settings): bool
    {
        return self::hook_command_present($settings, 'SessionStart', Config::session_start_hook_command());
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function pre_tool_use_hook_present(array $settings): bool
    {
        return self::hook_command_present($settings, 'PreToolUse', Config::pre_tool_use_hook_command());
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function permission_request_hook_present(array $settings): bool
    {
        return self::hook_command_present($settings, 'PermissionRequest', Config::permission_request_hook_command());
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function user_prompt_submit_hook_present(array $settings): bool
    {
        return self::hook_command_present($settings, 'UserPromptSubmit', Config::user_prompt_submit_hook_command());
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function stop_hook_present(array $settings): bool
    {
        return self::hook_command_present($settings, 'Stop', Config::stop_hook_command());
    }

    /**
     * Every hook event + command this app installs - check_session_hook()/
     * install_session_hook() are entirely data-driven off this list, so
     * adding a new hook only ever needs one line added here (plus the new
     * script itself and its own *_hook_command()/*_hook_present() pair, kept
     * as real named functions rather than folded into this list too, since
     * tests and other call sites reference them directly by name).
     *
     * PermissionRequest/UserPromptSubmit/Stop feed SessionStatusStore (see
     * that class and host-agent/hooks/{permission_request,user_prompt_submit,
     * stop}.php) - each appends its own matcher-group entry to
     * ~/.claude/settings.json without touching whatever Andres's own
     * personal hooks are already registered for those same events (multiple
     * hook commands coexist per event there today - confirmed before this
     * was written, see the todo file's research entry).
     *
     * @return array<int, array{event:string, command:string, present:bool}>
     */
    public static function app_hooks_status(array $settings): array
    {
        return [
            ['event' => 'SessionStart', 'command' => Config::session_start_hook_command(), 'present' => self::session_start_hook_present($settings)],
            ['event' => 'PreToolUse', 'command' => Config::pre_tool_use_hook_command(), 'present' => self::pre_tool_use_hook_present($settings)],
            ['event' => 'PermissionRequest', 'command' => Config::permission_request_hook_command(), 'present' => self::permission_request_hook_present($settings)],
            ['event' => 'UserPromptSubmit', 'command' => Config::user_prompt_submit_hook_command(), 'present' => self::user_prompt_submit_hook_present($settings)],
            ['event' => 'Stop', 'command' => Config::stop_hook_command(), 'present' => self::stop_hook_present($settings)],
        ];
    }

    /**
     * Reads ~/.claude/settings.json (if any) and reports whether every one of
     * this app's hooks (see app_hooks_status()) is already registered. A
     * missing file is a normal, expected "not set up yet" state, not an
     * error; a file that exists but fails to parse as JSON is an error, since
     * installing on top of it risks Claude Code refusing to start (or
     * install_session_hook() below refusing to touch it at all).
     *
     * $profile (see Config::claude_profile_config()) - checks that
     * account's own settings.json instead of the default one. null means
     * the default account, same as every caller from before profiles
     * existed.
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public static function check_session_hook(?string $profile = null): array
    {
        $raw = @file_get_contents(Config::claude_settings_path($profile));

        if ($raw === false) {
            return ['ok' => true, 'installed' => false];
        }

        $settings = json_decode($raw, true);

        if (!is_array($settings)) {
            return ['ok' => false, 'installed' => false, 'message' => Config::claude_settings_path($profile) . ' exists but is not valid JSON'];
        }

        $allPresent = true;

        foreach (self::app_hooks_status($settings) as $hook) {
            if (!$hook['present']) {
                $allPresent = false;
                break;
            }
        }

        return ['ok' => true, 'installed' => $allPresent];
    }

    /**
     * Adds every missing app_hooks_status() entry to ~/.claude/settings.json,
     * creating the file if it doesn't exist yet. Never overwrites an existing
     * file that fails to parse - a blind reset-to-empty-then-write would
     * silently discard every other hook/setting Andres already has configured
     * there. Idempotent per hook: each is only added if not already present,
     * so this is safe to call from a "just make sure it's there" dashboard
     * button without a separate check first, and safe to re-run after only
     * some of them were ever installed.
     *
     * $profile: same meaning as check_session_hook()'s own.
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public static function install_session_hook(?string $profile = null): array
    {
        $path = Config::claude_settings_path($profile);
        $raw = @file_get_contents($path);
        $settings = [];

        if ($raw !== false) {
            $settings = json_decode($raw, true);

            if (!is_array($settings)) {
                return ['ok' => false, 'installed' => false, 'message' => $path . ' exists but is not valid JSON - fix or add the hooks manually, see README'];
            }
        }

        $missing = array_filter(self::app_hooks_status($settings), fn(array $hook): bool => !$hook['present']);

        if ($missing === []) {
            return ['ok' => true, 'installed' => true];
        }

        $settings['hooks'] ??= [];

        foreach ($missing as $hook) {
            $settings['hooks'][$hook['event']] ??= [];
            $settings['hooks'][$hook['event']][] = [
                'matcher' => '*',
                'hooks' => [
                    ['type' => 'command', 'command' => $hook['command']],
                ],
            ];
        }

        $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return ['ok' => false, 'installed' => false, 'message' => 'Failed to encode updated settings'];
        }

        if (!is_dir(dirname($path))) {
            @mkdir(dirname($path), 0700, true);
        }

        if (@file_put_contents($path, self::reindent_json_pretty($encoded) . "\n") === false) {
            return ['ok' => false, 'installed' => false, 'message' => 'Could not write ' . $path];
        }

        return ['ok' => true, 'installed' => true];
    }

    /**
     * check_session_hook() across every configured Claude Code profile
     * (Config::claude_profiles_to_scan()) - a work profile with no hooks
     * installed in its own settings.json is exactly the "reports
     * permanently idle/unknown" failure mode Dibs plan #230 called out, so
     * the dashboard's existing single health check needs to cover every
     * account, not just the default one, for that to actually be visible.
     * `ok`/`installed` are the AND of every profile's own (any one
     * profile's hooks missing means "not fully installed" overall);
     * `by_profile` carries the per-profile breakdown so a caller that
     * wants to show WHICH account needs attention can.
     *
     * @return array{ok:bool, installed:bool, message?:string, by_profile:array<string, array{ok:bool, installed:bool, message?:string}>}
     */
    public static function check_all_claude_profiles(): array
    {
        $ok = true;
        $installed = true;
        $message = null;
        $byProfile = [];

        foreach (Config::claude_profiles_to_scan() as $profile) {
            $result = self::check_session_hook($profile);
            $byProfile[$profile ?? 'default'] = $result;
            $ok = $ok && $result['ok'];
            $installed = $installed && $result['installed'];
            $message ??= $result['message'] ?? null;
        }

        return ['ok' => $ok, 'installed' => $installed, 'message' => $message, 'by_profile' => $byProfile];
    }

    /**
     * install_session_hook() across every configured Claude Code profile -
     * same reasoning as check_all_claude_profiles() above. Each profile is
     * independently idempotent (install_session_hook()'s own contract), so
     * this is safe to call repeatedly, same as the single-profile version
     * was.
     *
     * @return array{ok:bool, installed:bool, message?:string, by_profile:array<string, array{ok:bool, installed:bool, message?:string}>}
     */
    public static function install_all_claude_profiles(): array
    {
        $ok = true;
        $installed = true;
        $message = null;
        $byProfile = [];

        foreach (Config::claude_profiles_to_scan() as $profile) {
            $result = self::install_session_hook($profile);
            $byProfile[$profile ?? 'default'] = $result;
            $ok = $ok && $result['ok'];
            $installed = $installed && $result['installed'];
            $message ??= $result['message'] ?? null;
        }

        return ['ok' => $ok, 'installed' => $installed, 'message' => $message, 'by_profile' => $byProfile];
    }
}
