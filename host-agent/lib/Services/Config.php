<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Host-specific paths/thresholds, overridable via env (see
 * host-agent/.env.example, loaded by systemd's EnvironmentFile= in
 * production) so tests can point at an isolated tmux socket and a fixture
 * claude binary instead of the real host session.
 *
 * No installer's own personal paths are hardcoded here (removed
 * 2026-08-09, ahead of open-sourcing this repo) - every default below is
 * either genuinely environment-derived (this process's real $HOME/uid,
 * which is correct on ANY machine, not just the original author's) or
 * empty, which downstream code/README setup steps treat as "not
 * configured yet" rather than silently trying to use someone else's
 * machine's paths. See host-agent/.env.example for what actually needs
 * setting on a fresh install.
 */
class Config
{
    public static function sessioneer_config(string $key, string $default): string
    {
        $value = getenv($key);
        return $value !== false && $value !== '' ? $value : $default;
    }

    /**
     * No safe universal guess exists for where `claude` was actually
     * installed (npm global, nvm, a native installer, ...) - unlike
     * every other path below, there's no environment-derived fallback
     * that's reliably correct, so this is empty until CLAUDE_BIN is set
     * explicitly. Run `which claude` to find the real path.
     *
     * $profile's own 'bin' override (see agents_config()) wins when set -
     * only needed if a work account genuinely runs a different binary, not
     * the common case (a different CLAUDE_CONFIG_DIR is usually enough,
     * see claude_config_dir()).
     */
    public static function claude_bin(?string $profile = null): string
    {
        $override = self::claude_profile_config($profile)['bin'] ?? null;

        return is_string($override) && $override !== '' ? $override : self::sessioneer_config('CLAUDE_BIN', '');
    }

    /**
     * host-agent/config/agents.php's raw return value, required once per
     * process and cached (same one-process-per-request lifetime as
     * SqliteDb's connection cache) - a plain PHP array rather than JSON so
     * the file can carry comments and doesn't need a parser. Missing file
     * (nothing configured yet) resolves to an empty array, not an error -
     * every profile lookup below already treats "no such profile" as
     * "use today's single-account defaults".
     */
    /** @var array<string, mixed>|null */
    private static ?array $agentsConfig = null;

    /** @return array<string, mixed> */
    public static function agents_config(): array
    {
        if (self::$agentsConfig !== null) {
            return self::$agentsConfig;
        }

        $path = self::sessioneer_repo_root() . '/host-agent/config/agents.php';
        $config = is_file($path) ? require $path : [];

        return self::$agentsConfig = is_array($config) ? $config : [];
    }

    /**
     * One Claude Code profile's raw config ('config_dir'/'bin', both
     * ?string) from agents_config()'s 'claude.profiles' map. $profile
     * null, empty, or naming a profile that doesn't exist all resolve to
     * an all-null entry - "use today's single-account defaults" - rather
     * than an error, so an unconfigured/removed profile never breaks a
     * session that already references it.
     *
     * @return array{config_dir: ?string, bin: ?string}
     */
    public static function claude_profile_config(?string $profile): array
    {
        if ($profile === null || $profile === '') {
            return ['config_dir' => null, 'bin' => null];
        }

        $entry = self::agents_config()['claude']['profiles'][$profile] ?? null;

        return [
            'config_dir' => is_string($entry['config_dir'] ?? null) && $entry['config_dir'] !== '' ? $entry['config_dir'] : null,
            'bin' => is_string($entry['bin'] ?? null) && $entry['bin'] !== '' ? $entry['bin'] : null,
        ];
    }

    /**
     * Every configured Claude Code profile NAME (agents_config()'s own
     * 'claude.profiles' keys) - lets a caller that needs to scan every
     * known account (e.g. ArchivedSessionService's dormant-session listing)
     * discover what's configured without reaching into agents_config()'s
     * raw shape itself.
     *
     * @return string[]
     */
    public static function claude_profile_names(): array
    {
        $profiles = self::agents_config()['claude']['profiles'] ?? [];

        return is_array($profiles) ? array_keys($profiles) : [];
    }

    /**
     * Every Claude Code profile worth scanning for a transcript that could
     * belong to ANY configured account, as TranscriptService-compatible
     * $profile arguments - null (the default account) plus every named
     * profile, DEDUPED by their actually-resolved config dir (two names
     * resolving to the same dir would otherwise get scanned/listed twice -
     * see claude_profile_names()'s own docblock for why that can happen).
     * The bare default (null) always wins a dedup collision.
     *
     * Used both by ArchivedSessionService's dormant-session listing (which
     * genuinely wants every account's transcripts) and by
     * TranscriptRouter::find_transcript_path_any_profile() (which wants
     * "try every account until one resolves" for a bare/untracked process
     * with no sidecar to say which one it belongs to).
     *
     * @return array<int, ?string>
     */
    public static function claude_profiles_to_scan(): array
    {
        $profiles = [null];
        $seenDirs = [self::claude_config_dir(null)];

        foreach (self::claude_profile_names() as $name) {
            $dir = self::claude_config_dir($name);

            if (in_array($dir, $seenDirs, true)) {
                continue;
            }

            $seenDirs[] = $dir;
            $profiles[] = $name;
        }

        return $profiles;
    }

    /**
     * The CLAUDE_CONFIG_DIR a given profile's session should run under -
     * $profile's own 'config_dir' when set, else this process's own
     * default (home_root() . '/.claude', i.e. today's single-account
     * behavior unchanged). This is what gets injected as the spawned tmux
     * pane's CLAUDE_CONFIG_DIR env (see SessionLifecycleService) and is
     * the base every other Claude-account-scoped path below is now built
     * from, instead of home_root() directly.
     */
    public static function claude_config_dir(?string $profile = null): string
    {
        $override = self::claude_profile_config($profile)['config_dir'] ?? null;

        return is_string($override) && $override !== '' ? $override : self::home_root() . '/.claude';
    }

    /**
     * Reverse of claude_config_dir() - given a CLAUDE_CONFIG_DIR value (as
     * read from the environment a Claude-Code-spawned process, e.g. the
     * statusLine script's own $CLAUDE_CONFIG_DIR, inherited straight from
     * ClaudeCodeAdapter::build_spawn_argv()'s env override), resolves which
     * configured profile it belongs to - null for "the default account",
     * covering both a genuinely unset/empty value AND a dir that matches
     * the default resolution explicitly (e.g. an explicit 'personal' pick,
     * whose own config_dir override is null - same dedup-by-resolved-dir
     * reasoning as claude_profiles_to_scan()'s own docblock, so 'personal'
     * and null are never treated as two different accounts here).
     */
    public static function claude_profile_for_config_dir(string $configDir): ?string
    {
        if ($configDir === '' || $configDir === self::claude_config_dir(null)) {
            return null;
        }

        foreach (self::claude_profile_names() as $name) {
            if (self::claude_config_dir($name) === $configDir) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Host-agent-side counterpart to the web UI's App\Views\SessionRowView::
     * profile_label() - duplicated, not shared, since HostAgent\ and App\
     * are two genuinely separate runtimes (see CLAUDE.md's "Architecture:
     * two runtimes" section) that never share a namespace/autoload root.
     * Needed for QuotaService's JSON 'label' field.
     */
    public static function claude_profile_label(?string $profile): string
    {
        return match ($profile) {
            null, '', 'personal' => 'Personal',
            default => ucfirst($profile),
        };
    }

    /**
     * Same reasoning as claude_bin() above - no safe universal guess for
     * where the Antigravity CLI (binary name `agy`) was installed, so this
     * is empty until ANTIGRAVITY_BIN is set explicitly. Run `which agy` to
     * find the real path. Added 2026-08-24 for AntigravityAdapter, see
     * docs/antigravity-adapter-plan.md.
     */
    public static function antigravity_bin(): string
    {
        return self::sessioneer_config('ANTIGRAVITY_BIN', '');
    }

    /**
     * Same reasoning as claude_bin() above - no safe universal guess for
     * where the OpenCode TUI CLI (binary name `opencode`) was installed, so
     * this is empty until OPENCODE_BIN is set explicitly. Run `which
     * opencode` to find the real path. Added 2026-08-25 for OpenCodeAdapter.
     */
    public static function opencode_bin(): string
    {
        return self::sessioneer_config('OPENCODE_BIN', '');
    }

    public static function codex_bin(): string
    {
        return self::sessioneer_config('CODEX_BIN', '');
    }

    public static function codex_bridge_socket(): string
    {
        return self::sessioneer_config('CODEX_BRIDGE_SOCKET', '/run/user/' . getmyuid() . '/sessioneer-codex-bridge.sock');
    }

    /** Global user hook configuration read by local Codex clients. */
    public static function codex_hooks_path(): string
    {
        return self::home_root() . '/.codex/hooks.json';
    }

    /** One event-aware command handles every Sessioneer Codex status hook. */
    public static function codex_status_hook_command(): string
    {
        return 'php ' . self::sessioneer_repo_root() . '/host-agent/hooks/codex/status.php';
    }

    /**
     * Starting folder for the New Session browser - home_root() itself is
     * a reasonable generic default (always exists, always readable by
     * this process); set WWW_ROOT explicitly to wherever projects
     * actually live if that's not directly under the home directory.
     */
    public static function www_root(): string
    {
        return self::sessioneer_config('WWW_ROOT', self::home_root());
    }

    public static function home_root(): string
    {
        return self::sessioneer_config('HOME_ROOT', getenv('HOME') ?: '');
    }

    /**
     * tmux's own real default socket path already embeds the real uid
     * (getmyuid() - core PHP, no posix extension needed) rather than any
     * one specific installer's - this is what a plain `tmux` command with
     * no -S/-L would resolve to on ANY machine, not a hardcoded guess.
     */
    public static function tmux_socket(): string
    {
        return self::sessioneer_config('TMUX_SOCKET', '/tmp/tmux-' . getmyuid() . '/default');
    }

    public static function sidecar_dir(): string
    {
        return self::sessioneer_config('SIDECAR_DIR', '/run/user/' . getmyuid() . '/sessioneer-sessions');
    }

    /**
     * Separate from sidecar_dir() on purpose - SidecarStore::
     * prune_orphaned_sidecars() globs and deletes anything in sidecar_dir()
     * that isn't a live session's own file, so a cache file dropped in
     * there would get treated as an orphan and unlinked on the very next
     * scan. Same tmpfs base, own subdirectory.
     */
    public static function cache_dir(): string
    {
        return self::sessioneer_config('CACHE_DIR', '/run/user/' . getmyuid() . '/sessioneer-cache');
    }

    /**
     * See SessionListCacheStore's own doc comment for why this is a
     * fraction of a second rather than a minutes-scale TTL - it only needs
     * to outlive the gap between concurrent callers, never a single
     * poller's own next tick.
     */
    public static function session_list_cache_ttl_seconds(): float
    {
        return (float)self::sessioneer_config('SESSION_LIST_CACHE_TTL_SECONDS', '0.9');
    }

    /**
     * Where the OpenCode Sessioneer plugin writes pending-permission records (and the
     * host-agent reads them back) - a small JSON file per ses_* id, so the
     * plugin (Bun/JS, running inside each TUI) and this PHP host-agent never
     * contend on a single shared DB connection. Sits under the same sidecar
     * dir as the rest of Sessioneer's per-session state (both runtimes can write
     * there under the same user). Overridable for tests via OPENCODE_PERMISSION_DIR.
     */
    public static function opencode_permission_dir(): string
    {
        return self::sessioneer_config('OPENCODE_PERMISSION_DIR', self::sidecar_dir() . '/opencode-permissions');
    }

    public static function cleanup_threshold_seconds(): int
    {
        return (int)self::sessioneer_config('CLEANUP_THRESHOLD_SECONDS', '43200'); // 12h
    }

    /**
     * A new session's tmux pane, with no real client ever attached to give it
     * a size to inherit, otherwise falls back to the SERVER's default-size
     * (confirmed live: 80x24, tmux's own classic default) - nowhere near
     * enough for Claude Code's own TUI to render a long tool-permission
     * preview (a big Write, a long Bash script) without cutting it short.
     * Found live: capture-pane (even with extra -S scrollback) came back
     * missing the earlier lines entirely - not truncated by this app's own
     * parsing, genuinely never rendered by Claude Code in the first place,
     * since it adapts its own output to whatever height it detects. Sized
     * generously since nothing is ever really "looking at" this pane as a
     * literal terminal window - it only ever gets read via capture-pane.
     */
    public static function new_session_pane_width(): int
    {
        return (int)self::sessioneer_config('TMUX_PANE_WIDTH', '200');
    }

    public static function new_session_pane_height(): int
    {
        return (int)self::sessioneer_config('TMUX_PANE_HEIGHT', '150');
    }

    /**
     * An Antigravity conversation's real, confirmed-live transcript file
     * path (docs/antigravity-adapter-plan.md's "Transcript format"
     * research) - unlike Claude Code's find_transcript_path() (a glob
     * against an encoded-cwd directory Claude Code names itself), this is
     * a direct, deterministic path from the conversationId alone, no
     * search needed. See AntigravityTranscriptService::find_transcript_path()
     * for the UUID-shape validation this is paired with.
     */
    public static function antigravity_transcript_path(string $conversationId): string
    {
        return self::home_root() . '/.gemini/antigravity-cli/brain/' . $conversationId . '/.system_generated/logs/transcript_full.jsonl';
    }

    /**
     * GlobalStateStore key (Config::push_sqlite_path(), NOT a file, since
     * 2026-08-24) holding account-wide rate-limit quota (session/week_all
     * pct + real epoch resets_at, straight from Claude Code's own
     * statusLine JSON) - written by host-agent/quota_live_state_write.php,
     * invoked from the statusLine script this app installs (see
     * StatuslineMarkerService) on every status-line render, event-driven,
     * no tmux/capture-pane involved. QuotaService::get_quota()'s only
     * source (see that class's own docblock - the tmux-pane-scraping
     * fallback and the external `claude-quota`-binary scrape behind it were
     * both deleted 2026-08-22 as dead code).
     *
     * $profile null (the default account) keeps the original bare key
     * unchanged - an existing install's already-captured quota history
     * stays readable under a profile-unaware key exactly as before. Any
     * other profile (resolved via claude_profile_for_config_dir() from the
     * writing statusLine script's own $CLAUDE_CONFIG_DIR) gets its own
     * separate key/row, the same "own key per distinct account" pattern
     * antigravity_quota_live_state_key() already uses for a different agent.
     */
    public static function quota_live_state_key(?string $profile = null): string
    {
        return $profile !== null ? "quota_live_state:{$profile}" : 'quota_live_state';
    }

    /**
     * GlobalStateStore key holding Antigravity's own account-wide quota -
     * a genuinely different mechanism from Claude Code's above: there is
     * no statusline-JSON equivalent, so this is written by
     * host-agent/antigravity_quota_poll.php, run periodically by the
     * (opt-in, not auto-enabled) sessioneer-antigravity-quota-check systemd
     * timer - a real, free, headless `agy -p "/usage" --output-format
     * json` call (confirmed live 2026-08-24: duration_seconds=0,
     * zero token usage, no real model turn), not an event-driven capture.
     * Own separate key, own separate table row, from quota_live_state_key()
     * above - the two agents' quota shapes don't overlap (Antigravity has
     * no five_hour/week_all session split, just per-model-group weekly
     * buckets), and there's no reason a display surface couldn't show
     * both independently once one exists.
     */
    public static function antigravity_quota_live_state_key(): string
    {
        return 'antigravity_quota_live_state';
    }

    /**
     * SidecarStore/SessionStatusStore/PendingToolStore's shared SQLite DB -
     * ephemeral, same tmpfs directory (and same wiped-on-reboot lifetime)
     * as the plain JSON sidecar files this replaced (2026-08-24). One file,
     * three tables (see SqliteDb::sessions_schema()) - a session's identity,
     * live status, and pending-tool-call state are almost always read
     * together anyway (SessionService::build_session_entry()), and a single
     * SQLite connection per request (WAL mode, see SqliteDb) removes the
     * hand-rolled read-modify-write race SessionStatusStore::update_status()
     * used to have between two hooks firing close together (found live
     * 2026-08-23).
     */
    public static function sessions_sqlite_path(): string
    {
        return self::sessioneer_config('SESSIONS_SQLITE_FILE', self::sidecar_dir() . '/sessions.sqlite');
    }

    /**
     * PushSubscriptionStore/PushSessionStateStore/PushQuotaStateStore/
     * GlobalStateStore's shared SQLite DB - persistent (host-agent/state/,
     * not tmpfs - a phone's subscription shouldn't need to be redone just
     * because the host rebooted). quota_live_state_key() (GlobalStateStore)
     * lives here too since 2026-08-24 - the shell statusLine script no
     * longer writes it directly via jq; it now shells out to
     * host-agent/quota_live_state_write.php, a small standalone PHP
     * script, once per render (see that file's own docblock for the
     * earlier two-writer-race concern this sidesteps by making PHP/SQLite
     * the only writer, same reasoning PushQuotaStateStore's own docblock
     * already used for its own table).
     */
    public static function push_sqlite_path(): string
    {
        return self::sessioneer_config('PUSH_SQLITE_FILE', self::sessioneer_repo_root() . '/host-agent/state/push.sqlite');
    }

    /**
     * OpenCode's own SQLite database - lives at ~/.local/share/opencode/opencode.db
     * by default (see `opencode debug paths`'s `data` entry), WAL mode
     * (confirmed live 2026-08-25: PRAGMA journal_mode=wal, concurrent
     * readers safe while the TUI runs). Overridable via env for tests so a
     * canned fixture DB can be pointed at without touching the real host data.
     */
    public static function opencode_db_path(): string
    {
        return self::sessioneer_config('OPENCODE_DB_PATH', self::home_root() . '/.local/share/opencode/opencode.db');
    }

    public static function opencode_auth_path(): string
    {
        return self::sessioneer_config('OPENCODE_AUTH_PATH', self::home_root() . '/.local/share/opencode/auth.json');
    }

    /**
     * Base URL of the `opencode serve` HTTP server (see host-agent/systemd/
     * opencode-serve.service, port 4096). opencode 1.18.21 exposes pending
     * permission/question state ONLY through this server's in-memory API
     * (GET /permission, GET /question and their reply endpoints return non-
     * empty arrays while a modal is live, [] when orphaned/none) - so the
     * host-agent talks to it for blocked-prompt detection/answering.
     * Overridable via env for tests (OPENCODE_SERVE_URL).
     */
    public static function opencode_server_url(): string
    {
        return rtrim(self::sessioneer_config('OPENCODE_SERVE_URL', 'http://localhost:4096'), '/');
    }

    /**
     * This app's own checkout root - derived from this very file's real
     * location (Config.php lives at host-agent/lib/Services/Config.php,
     * three levels below repo root) rather than any hardcoded path, so
     * it's correct regardless of where the repo was actually cloned to.
     * Still overridable via env for tests, same convention as every other
     * value here.
     */
    public static function sessioneer_repo_root(): string
    {
        return self::sessioneer_config('SESSIONEER_REPO_ROOT', dirname(__DIR__, 3));
    }

    public static function claude_settings_path(?string $profile = null): string
    {
        return self::claude_config_dir($profile) . '/settings.json';
    }

    /**
     * The exact `command` string this app's SessionStart hook entry is
     * registered under - both session_start_hook_present() and
     * install_session_hook() key off this same string, so "is it already
     * there" and "what do we add" can never drift apart.
     */
    public static function session_start_hook_command(): string
    {
        return 'php ' . self::sessioneer_repo_root() . '/host-agent/hooks/session_start.php';
    }

    /**
     * Same convention as session_start_hook_command(), for the PreToolUse
     * hook (see host-agent/hooks/pre_tool_use.php) that records a pending
     * tool call's full, untruncated tool_input for the blocked-prompt preview.
     */
    public static function pre_tool_use_hook_command(): string
    {
        return 'php ' . self::sessioneer_repo_root() . '/host-agent/hooks/pre_tool_use.php';
    }

    /**
     * Same convention as session_start_hook_command(), for the
     * PermissionRequest hook (host-agent/hooks/permission_request.php) that
     * feeds SessionStatusStore's blocked-prompt state.
     */
    public static function permission_request_hook_command(): string
    {
        return 'php ' . self::sessioneer_repo_root() . '/host-agent/hooks/permission_request.php';
    }

    /**
     * Same convention as session_start_hook_command(), for the
     * UserPromptSubmit hook (host-agent/hooks/user_prompt_submit.php) that
     * marks a session working in SessionStatusStore.
     */
    public static function user_prompt_submit_hook_command(): string
    {
        return 'php ' . self::sessioneer_repo_root() . '/host-agent/hooks/user_prompt_submit.php';
    }

    /**
     * Same convention as session_start_hook_command(), for the Stop hook
     * (host-agent/hooks/stop.php) that marks a session idle in
     * SessionStatusStore.
     */
    public static function stop_hook_command(): string
    {
        return 'php ' . self::sessioneer_repo_root() . '/host-agent/hooks/stop.php';
    }

    /**
     * Antigravity's real hooks config file (see
     * docs/antigravity-adapter-plan.md's "Hooks" research section) - the
     * SHARED global location (confirmed via the CLI's own embedded
     * changelog: a past bug wrote to the wrong, non-shared path and was
     * fixed to write here instead), not the per-workspace
     * `<workspace>/.agents/hooks.json` override (not needed for MVP).
     */
    public static function antigravity_hooks_path(): string
    {
        return self::home_root() . '/.gemini/config/hooks.json';
    }

    /**
     * Same convention as session_start_hook_command() etc, for the
     * Antigravity hook scripts under host-agent/hooks/antigravity/ - see
     * AntigravityHookService.
     */
    public static function antigravity_pre_tool_use_hook_command(): string
    {
        return 'php ' . self::sessioneer_repo_root() . '/host-agent/hooks/antigravity/pre_tool_use.php';
    }

    public static function antigravity_post_tool_use_hook_command(): string
    {
        return 'php ' . self::sessioneer_repo_root() . '/host-agent/hooks/antigravity/post_tool_use.php';
    }

    public static function antigravity_pre_invocation_hook_command(): string
    {
        return 'php ' . self::sessioneer_repo_root() . '/host-agent/hooks/antigravity/pre_invocation.php';
    }

    public static function antigravity_stop_hook_command(): string
    {
        return 'php ' . self::sessioneer_repo_root() . '/host-agent/hooks/antigravity/stop.php';
    }

    /**
     * Same convention as session_start_hook_command() etc, for
     * host-agent/quota_live_state_write.php - not a Claude Code hook
     * (never registered in settings.json), but invoked the same shape by
     * the statusLine script this app installs (see
     * StatuslineMarkerService::quota_capture_block()).
     */
    public static function quota_live_state_write_command(): string
    {
        return 'php ' . self::sessioneer_repo_root() . '/host-agent/quota_live_state_write.php';
    }

    /**
     * Fallback statusline script this app installs (and points
     * ~/.claude/settings.json's statusLine at) only when Andres has no
     * statusLine of his own configured yet - see StatuslineMarkerService.
     * When one already exists, its own script file is appended to instead;
     * this path is never touched in that case.
     */
    public static function statusline_fallback_script_path(): string
    {
        return self::home_root() . '/.claude/sessioneer-statusline.sh';
    }
}
