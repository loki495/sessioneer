<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Cross-checks/self-heals SidecarStore's agent_session_id against a live,
 * on-screen signal - Claude Code's own statusLine feature includes
 * session_id in the JSON it feeds a configured statusline script, and that
 * script's rendered output is real terminal content, capturable the same
 * way TmuxService::tmux_capture_pane() already reads pane content for
 * everything else (blocked prompts, etc).
 *
 * This is a stronger signal than the SessionStart hook alone for the
 * exact corruption class found live 2026-08-08 (a `claude` process run
 * manually from inside a tracked pane's own Bash tool inherits
 * SESSIONEER_SESSION_NAME and fires its own genuine SessionStart, clobbering the
 * pane's sidecar with an unrelated session_id - see session_start.php's
 * own transcript-existence check, added the same day). The statusline
 * reflects whatever process currently owns the pane's TUI - once a nested
 * invocation like that exits, control returns to the real interactive
 * session and its own statusline redraws, self-correcting automatically
 * rather than depending on catching the right hook fire.
 *
 * Installs into Andres's own pre-existing statusline script (if he has
 * one - see locate_statusline_script()) rather than replacing it, using
 * the same clearly-marked, idempotent, safe-to-delete block convention as
 * a merge-safe settings.json edit (see HookService), just for a plain
 * shell script instead of JSON.
 */
class StatuslineMarkerService
{
    private const CAPTURE_BEGIN = '# >>> sessioneer: capture stdin for session-id marker (managed, safe to delete) >>>';
    private const CAPTURE_END = '# <<< sessioneer: capture stdin <<<';
    private const MARKER_BEGIN = '# >>> sessioneer: session-id marker (managed, safe to delete) >>>';
    private const MARKER_END = '# <<< sessioneer: session-id marker <<<';
    private const QUOTA_CAPTURE_BEGIN = '# >>> sessioneer: quota state capture (managed, safe to delete) >>>';
    private const QUOTA_CAPTURE_END = '# <<< sessioneer: quota state capture <<<';

    /**
     * Builds the compact JSON blob parse_marker_from_pane() reads back -
     * session_id plus whatever extra live signals are worth surfacing on
     * the dashboard/session page (context-window usage, git worktree name,
     * see SessionService::build_session_entry()). with_entries(select(...))
     * drops any key whose source value was null/absent rather than writing
     * a literal JSON null, so the object only ever carries what Claude Code
     * actually reported for this session at this moment. workspace.git_worktree
     * covers a plain linked worktree; worktree.name is the --worktree-flag
     * equivalent (docs: "Absent for hook-based worktrees" - the two are
     * mutually exclusive in practice, tried in this order).
     */
    private const JQ_FILTER = '{session_id: .session_id, ctx_pct: .context_window.used_percentage, git_worktree: (.workspace.git_worktree // .worktree.name)} | with_entries(select(.value != null))';

    // The merge-against-whatever's-already-there logic (a bucket only
    // moves DOWN when its resets_at also moved forward - a genuine window
    // rollover; otherwise the higher pct wins, since usage only climbs
    // within one window) used to live here as a jq filter string, run via
    // a shell tmp-file-then-mv - moved to PHP (merge_quota_bucket() in
    // host-agent/quota_live_state_write.php) 2026-08-24, see that file's
    // own docblock for why.

    /**
     * The live pane text carries our marker as a single compact JSON blob,
     * "sessioneer-data:{...}" on its own line - tmux capture-pane -p strips ANSI
     * escapes by default, so the dim styling written in the script never
     * has to be accounted for here. Every field is independently optional (the shell side
     * drops any key whose source value was null/absent, via jq's
     * with_entries(select(.value != null)) - see append_marker_to_script())
     * so a session with no context-window data yet, or outside a worktree,
     * still yields a usable session_id.
     *
     * Takes the LAST match, not the first (found live 2026-08-23: with a
     * tall pane - TMUX_PANE_HEIGHT=150 by default - and a session that
     * rotated its agent_session_id mid-conversation (/clear, /compact,
     * --resume), an OLDER statusline render from before the rotation can
     * still be visible higher up in the SAME captured viewport alongside
     * the current one. Matching the first occurrence fed SessionService::
     * self_heal_agent_session_id() a stale id, which then overwrote a
     * correct sidecar with a wrong one - repeatedly, every poll, since
     * nothing about the stale line ever scrolls out of view until enough
     * new output pushes it out.
     *
     * @return array{session_id:?string, context_used_percentage:?float, git_worktree:?string}
     */
    public static function parse_marker_from_pane(string $paneContent): array
    {
        $empty = ['session_id' => null, 'context_used_percentage' => null, 'git_worktree' => null];

        if (preg_match_all('/sessioneer-data:(\{[^\n]*\})/', $paneContent, $m) === 0) {
            return $empty;
        }

        $decoded = json_decode(end($m[1]), true);

        if (!is_array($decoded)) {
            return $empty;
        }

        $sessionId = isset($decoded['session_id']) && is_string($decoded['session_id'])
            && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $decoded['session_id']) === 1
            ? strtolower($decoded['session_id'])
            : null;

        $ctxPct = isset($decoded['ctx_pct']) && is_numeric($decoded['ctx_pct']) ? (float)$decoded['ctx_pct'] : null;

        $worktree = isset($decoded['git_worktree']) && is_string($decoded['git_worktree']) && $decoded['git_worktree'] !== ''
            ? $decoded['git_worktree']
            : null;

        return ['session_id' => $sessionId, 'context_used_percentage' => $ctxPct, 'git_worktree' => $worktree];
    }

    /**
     * Expands a bash-style `$HOME`/`${HOME}` reference in a statusLine
     * command TOKEN to Config::home_root() - found live 2026-09-18: a
     * command string written the normal way a user would type it (e.g.
     * "bash $HOME/.claude/statusline-command.sh", Andres's own real one)
     * is never shell-evaluated here, so is_file() on the literal,
     * unexpanded token could never match a real file - every check below
     * silently reported "not installed" against an already-fully-installed
     * script forever. Config::home_root(), not a raw getenv('HOME'), so
     * this resolves the same fixture/override path every other
     * home-directory-scoped lookup in this class already uses (see
     * test_statusline_marker.php's HOME_ROOT fixture). Only $HOME is
     * handled - the one variable a statusLine command actually needs (this
     * app's own generated fallback script never uses one at all, see
     * install_fallback_script() below, which always writes an absolute
     * path), not general shell expansion.
     */
    private static function expand_home_token(string $token): string
    {
        $home = Config::home_root();

        return $home !== '' ? str_replace(['${HOME}', '$HOME'], $home, $token) : $token;
    }

    /**
     * Finds the actual script file a `{"type":"command","command":"..."}`
     * statusLine entry invokes, so the marker can be appended to Andres's
     * own script rather than this app owning/replacing the whole thing.
     * Deliberately conservative: only ever returns a path that (a) is an
     * existing, writable regular file and (b) looks like a path (contains
     * a "/"), picking the LAST such token in the command (the interpreter,
     * e.g. "bash", normally comes first). Anything else (an inline
     * one-liner, an unrecognized shape) returns null rather than guessing.
     * Each token is checked post-$HOME-expansion (see expand_home_token())
     * and the EXPANDED, real path is what's returned - every caller needs
     * an actual filesystem path to read/write, not the literal command text.
     *
     * @param array<string, mixed> $settings
     */
    public static function locate_statusline_script(array $settings): ?string
    {
        $statusLine = $settings['statusLine'] ?? null;

        if (!is_array($statusLine) || ($statusLine['type'] ?? null) !== 'command') {
            return null;
        }

        $command = $statusLine['command'] ?? null;

        if (!is_string($command) || $command === '') {
            return null;
        }

        $tokens = preg_split('/\s+/', trim($command)) ?: [];
        $found = null;

        foreach ($tokens as $token) {
            $resolved = self::expand_home_token($token);

            if (str_contains($resolved, '/') && is_file($resolved) && is_writable($resolved)) {
                $found = $resolved;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $settings
     */
    public static function marker_installed(array $settings): bool
    {
        return self::block_installed($settings, self::MARKER_BEGIN);
    }

    /**
     * Same idea as marker_installed(), for the separate quota-state-capture
     * block (see QUOTA_CAPTURE_BEGIN) - independently checked/installed
     * since an existing script may already carry the (older) session-id
     * marker without yet having this block appended. Also false for a
     * script that already has SOME quota-capture block but a STALE one
     * (found live 2026-08-24: Andres's own already-installed script still
     * had the old jq-merge-then-mv body from before the move to
     * host-agent/quota_live_state_write.php/GlobalStateStore - block_installed()'s
     * plain str_contains($beginMarker) check alone would have reported
     * this as already installed forever, since the BEGIN/END marker text
     * itself never changed, only the body between them) - see
     * quota_capture_up_to_date() and install_into_script()'s replace path.
     *
     * @param array<string, mixed> $settings
     */
    public static function quota_capture_installed(array $settings): bool
    {
        return self::block_installed($settings, self::QUOTA_CAPTURE_BEGIN) && self::quota_capture_up_to_date($settings);
    }

    /**
     * True when the script's existing quota-capture block's BODY (not just
     * the presence of its BEGIN/END markers) matches what quota_capture_block()
     * would write today. False (needs a replace, not just an append) for a
     * script whose block predates a later change to that body - see
     * quota_capture_installed()'s own docblock.
     *
     * @param array<string, mixed> $settings
     */
    private static function quota_capture_up_to_date(array $settings): bool
    {
        $content = self::read_installed_script_content($settings);
        return $content !== null && str_contains($content, implode("\n", self::quota_capture_block()));
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function read_installed_script_content(array $settings): ?string
    {
        $existingScript = self::locate_statusline_script($settings);

        if ($existingScript !== null) {
            $content = @file_get_contents($existingScript);
            return $content !== false ? $content : null;
        }

        $fallback = Config::statusline_fallback_script_path();
        $isFallbackConfigured = is_array($settings['statusLine'] ?? null)
            && ($settings['statusLine']['type'] ?? null) === 'command'
            && ($settings['statusLine']['command'] ?? null) === 'bash ' . $fallback;

        if (!$isFallbackConfigured || !is_file($fallback)) {
            return null;
        }

        $content = @file_get_contents($fallback);
        return $content !== false ? $content : null;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function block_installed(array $settings, string $beginMarker): bool
    {
        $existingScript = self::locate_statusline_script($settings);

        if ($existingScript !== null) {
            $content = @file_get_contents($existingScript);
            return $content !== false && str_contains($content, $beginMarker);
        }

        $fallback = Config::statusline_fallback_script_path();
        $isFallbackConfigured = is_array($settings['statusLine'] ?? null)
            && ($settings['statusLine']['type'] ?? null) === 'command'
            && ($settings['statusLine']['command'] ?? null) === 'bash ' . $fallback;

        return $isFallbackConfigured && is_file($fallback) && str_contains((string)@file_get_contents($fallback), $beginMarker);
    }

    /**
     * @param array<string, mixed> $settings
     */
    private static function fully_installed(array $settings): bool
    {
        return self::marker_installed($settings) && self::quota_capture_installed($settings);
    }

    /**
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public static function check_statusline_marker(): array
    {
        $raw = @file_get_contents(Config::claude_settings_path());

        if ($raw === false) {
            return ['ok' => true, 'installed' => false];
        }

        $settings = json_decode($raw, true);

        if (!is_array($settings)) {
            return ['ok' => false, 'installed' => false, 'message' => '~/.claude/settings.json exists but is not valid JSON'];
        }

        return ['ok' => true, 'installed' => self::fully_installed($settings)];
    }

    /**
     * Idempotent: a script that already carries our marker is left alone.
     * Never overwrites a malformed settings.json (same refusal as
     * HookService::install_session_hook(), same reasoning - installing on
     * top of it risks Claude Code refusing to start).
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    public static function install_statusline_marker(): array
    {
        $path = Config::claude_settings_path();
        $raw = @file_get_contents($path);
        $settings = [];

        if ($raw !== false) {
            $settings = json_decode($raw, true);

            if (!is_array($settings)) {
                return ['ok' => false, 'installed' => false, 'message' => '~/.claude/settings.json exists but is not valid JSON - fix it manually first'];
            }
        }

        if (self::fully_installed($settings)) {
            return ['ok' => true, 'installed' => true];
        }

        $existingScript = self::locate_statusline_script($settings);

        if ($existingScript !== null) {
            return self::install_into_script($existingScript);
        }

        if (isset($settings['statusLine'])) {
            return ['ok' => false, 'installed' => false, 'message' => 'statusLine is configured but not in a recognized {"type":"command","command":"<interpreter> <path>"} shape - could not locate a script file to append to. Add the session-id marker manually, see README.'];
        }

        return self::install_fallback_script($settings, $path);
    }

    /**
     * Dispatches to whichever of the two managed blocks (see MARKER_BEGIN/
     * QUOTA_CAPTURE_BEGIN) this script is still missing or stale. A script
     * with neither gets both in one write (append_marker_to_script()); a
     * script that already has the (older) session-id marker but not the
     * newer quota-capture block gets just that one appended, reusing the
     * $sessioneer_statusline_input variable the existing CAPTURE_BEGIN block
     * already set up - it never needs a second stdin-capture prelude. A
     * script that has a quota-capture block whose BODY is stale (see
     * quota_capture_up_to_date()) gets that block replaced in place rather
     * than appended again.
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    private static function install_into_script(string $scriptPath): array
    {
        $content = @file_get_contents($scriptPath);

        if ($content === false) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not read {$scriptPath}"];
        }

        if (!str_contains($content, self::MARKER_BEGIN)) {
            return self::append_marker_to_script($scriptPath);
        }

        if (!str_contains($content, self::QUOTA_CAPTURE_BEGIN)) {
            return self::append_quota_capture_block($scriptPath);
        }

        if (!str_contains($content, implode("\n", self::quota_capture_block()))) {
            return self::replace_quota_capture_block($scriptPath);
        }

        return ['ok' => true, 'installed' => true];
    }

    /**
     * Upgrade path for a script whose quota-capture block BODY is stale
     * (present, but predates a later change to what that block does - see
     * quota_capture_up_to_date()) - replaces just the text between
     * QUOTA_CAPTURE_BEGIN/QUOTA_CAPTURE_END with the current block,
     * leaving everything else in the script (including the session-id
     * marker) untouched.
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    private static function replace_quota_capture_block(string $scriptPath): array
    {
        $content = @file_get_contents($scriptPath);

        if ($content === false) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not read {$scriptPath}"];
        }

        $pattern = '/' . preg_quote(self::QUOTA_CAPTURE_BEGIN, '/') . '.*?' . preg_quote(self::QUOTA_CAPTURE_END, '/') . '/s';
        $replacement = trim(implode("\n", self::quota_capture_block()), "\n");
        $newContent = preg_replace($pattern, $replacement, $content, 1);

        if ($newContent === null || $newContent === $content) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not locate the existing quota-capture block to replace in {$scriptPath}"];
        }

        if (@file_put_contents($scriptPath, $newContent) === false) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not write {$scriptPath}"];
        }

        return ['ok' => true, 'installed' => true];
    }

    /**
     * @return array{ok:bool, installed:bool, message?:string}
     */
    private static function append_marker_to_script(string $scriptPath): array
    {
        $content = @file_get_contents($scriptPath);

        if ($content === false) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not read {$scriptPath}"];
        }

        $lines = explode("\n", $content);
        $captureBlock = [self::CAPTURE_BEGIN, 'sessioneer_statusline_input=$(cat)', 'exec 0<<< "$sessioneer_statusline_input"', self::CAPTURE_END];

        if (isset($lines[0]) && str_starts_with($lines[0], '#!')) {
            array_splice($lines, 1, 0, $captureBlock);
        } else {
            array_splice($lines, 0, 0, $captureBlock);
        }

        $markerBlock = [
            '',
            self::MARKER_BEGIN,
            'sessioneer_json=$(printf \'%s\' "$sessioneer_statusline_input" | jq -c \'' . self::JQ_FILTER . '\' 2>/dev/null)',
            '[ -n "$sessioneer_json" ] && [ "$sessioneer_json" != "{}" ] && printf "\\033[2msessioneer-data:%s\\033[0m\\n" "$sessioneer_json"',
            self::MARKER_END,
        ];

        $newContent = implode("\n", $lines);
        if (!str_ends_with($newContent, "\n")) {
            $newContent .= "\n";
        }
        $newContent .= implode("\n", $markerBlock) . "\n";
        $newContent .= implode("\n", self::quota_capture_block()) . "\n";

        if (@file_put_contents($scriptPath, $newContent) === false) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not write {$scriptPath}"];
        }

        return ['ok' => true, 'installed' => true];
    }

    /**
     * Upgrade path for a script that already has the session-id marker
     * (and therefore the CAPTURE_BEGIN prelude that sets
     * $sessioneer_statusline_input) but predates the quota-capture block - just
     * appends that one block, nothing else.
     *
     * @return array{ok:bool, installed:bool, message?:string}
     */
    private static function append_quota_capture_block(string $scriptPath): array
    {
        $content = @file_get_contents($scriptPath);

        if ($content === false) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not read {$scriptPath}"];
        }

        $newContent = $content;
        if (!str_ends_with($newContent, "\n")) {
            $newContent .= "\n";
        }
        $newContent .= implode("\n", self::quota_capture_block()) . "\n";

        if (@file_put_contents($scriptPath, $newContent) === false) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not write {$scriptPath}"];
        }

        return ['ok' => true, 'installed' => true];
    }

    /**
     * The quota-state-capture block's lines - shared by append_marker_to_script()
     * (fresh install) and append_quota_capture_block() (upgrading an
     * existing marker install), and install_fallback_script() below.
     * Depends on $sessioneer_statusline_input already being set (see CAPTURE_BEGIN).
     * Shells out to host-agent/quota_live_state_write.php (Config::
     * quota_live_state_write_command()) for the actual merge-and-write
     * (GlobalStateStore, SQLite - see that script's own docblock for why
     * this moved off a jq-merge-then-mv plain-file dance 2026-08-24) -
     * synchronous, same as the jq pipeline it replaces: one extra PHP
     * process (PHP startup + a single SQLite upsert) is not perceptibly
     * slower than the jq calls already happening on every render, and
     * staying synchronous keeps this the same "the write has genuinely
     * landed by the time the render finishes" guarantee the old version
     * had, rather than trading it away for a backgrounding win nothing
     * here actually needs. No terminal
     * output, unlike the session-id marker block, since nothing needs to
     * scrape this back out of a rendered pane (see QuotaService::
     * quota_from_statusline_state()). Silently a no-op on a tick with no
     * rate_limits at all (context-only pane, or a plan with no Claude.ai
     * subscription rate limits) - same graceful-absence handling as the
     * session-id marker's own JQ_FILTER.
     *
     * @return string[]
     */
    private static function quota_capture_block(): array
    {
        return [
            '',
            self::QUOTA_CAPTURE_BEGIN,
            'sessioneer_quota_new=$(printf \'%s\' "$sessioneer_statusline_input" | jq -c \'{five_hour: .rate_limits.five_hour, seven_day: .rate_limits.seven_day} | with_entries(select(.value != null))\' 2>/dev/null)',
            'if [ -n "$sessioneer_quota_new" ] && [ "$sessioneer_quota_new" != "{}" ]; then',
            '  printf \'%s\' "$sessioneer_quota_new" | ' . Config::quota_live_state_write_command() . ' >/dev/null 2>&1',
            'fi',
            self::QUOTA_CAPTURE_END,
        ];
    }

    /**
     * @param array<string, mixed> $settings
     * @return array{ok:bool, installed:bool, message?:string}
     */
    private static function install_fallback_script(array $settings, string $settingsPath): array
    {
        $scriptPath = Config::statusline_fallback_script_path();
        $script = "#!/usr/bin/env bash\n"
            . "sessioneer_statusline_input=\$(cat)\n"
            . "\n"
            . self::MARKER_BEGIN . "\n"
            . "sessioneer_json=\$(printf '%s' \"\$sessioneer_statusline_input\" | jq -c '" . self::JQ_FILTER . "' 2>/dev/null)\n"
            . '[ -n "$sessioneer_json" ] && [ "$sessioneer_json" != "{}" ] && printf "\033[2msessioneer-data:%s\033[0m\n" "$sessioneer_json"' . "\n"
            . self::MARKER_END . "\n"
            . implode("\n", self::quota_capture_block()) . "\n";

        if (!is_dir(dirname($scriptPath))) {
            @mkdir(dirname($scriptPath), 0700, true);
        }

        if (@file_put_contents($scriptPath, $script) === false) {
            return ['ok' => false, 'installed' => false, 'message' => "Could not write {$scriptPath}"];
        }

        @chmod($scriptPath, 0700);

        $settings['statusLine'] = ['type' => 'command', 'command' => 'bash ' . $scriptPath];

        $encoded = json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if ($encoded === false) {
            return ['ok' => false, 'installed' => false, 'message' => 'Failed to encode updated settings'];
        }

        if (!is_dir(dirname($settingsPath))) {
            @mkdir(dirname($settingsPath), 0700, true);
        }

        if (@file_put_contents($settingsPath, HookService::reindent_json_pretty($encoded) . "\n") === false) {
            return ['ok' => false, 'installed' => false, 'message' => 'Could not write ~/.claude/settings.json'];
        }

        return ['ok' => true, 'installed' => true];
    }
}
