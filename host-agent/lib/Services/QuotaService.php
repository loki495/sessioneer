<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\GlobalStateStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Runtimes\CodexBridgeClient;

/**
 * Claude usage/rate-limit quota - read straight from the file this app's
 * own statusLine script writes on every Claude Code status-line render
 * (see quota_from_statusline_state()), event-driven, no scraping of any
 * kind. An earlier design also fell back to scanning a live tmux pane
 * directly, and beyond that to an external `claude-quota` binary (a slow,
 * 10-40s scrape of Claude Code's own /usage panel, cached and
 * background-refreshed) - both were deleted 2026-08-22 as confirmed dead
 * code: once any session has ever written the statusline-state file even
 * once, it never returns null again, so neither fallback could actually be
 * reached anymore. See CONTRIBUTING.md/git history if any of that ever
 * needs resurrecting.
 */
class QuotaService
{
    /**
     * Reads Codex account windows directly from app-server and optionally
     * overlays the selected thread's last structured token-usage event.
     * @return array{quota:array<string,mixed>, fetched_at:int}|null
     */
    public static function codex_quota_state(?string $threadId = null): ?array
    {
        $reply = (new CodexBridgeClient())->request('account/rateLimits/read', []);
        if (($reply['ok'] ?? false) !== true) return null;
        $snapshot = is_array($reply['result']['rateLimitsByLimitId']['codex'] ?? null)
            ? $reply['result']['rateLimitsByLimitId']['codex']
            : (is_array($reply['result']['rateLimits'] ?? null) ? $reply['result']['rateLimits'] : null);
        if ($snapshot === null) return null;

        $quota = [];
        foreach (['primary' => 'session', 'secondary' => 'week_all'] as $source => $target) {
            $window = $snapshot[$source] ?? null;
            if (!is_array($window) || !is_int($window['usedPercent'] ?? null)) continue;
            $quota[$target] = ['pct' => $window['usedPercent']];
            if (is_int($window['resetsAt'] ?? null)) $quota[$target]['resets_at'] = $window['resetsAt'];
        }

        if ($threadId !== null && $threadId !== '') {
            $usage = SessionStatusStore::read_status($threadId)['token_usage'] ?? null;
            $total = is_array($usage['total'] ?? null) ? $usage['total'] : null;
            $contextWindow = is_int($usage['modelContextWindow'] ?? null) ? $usage['modelContextWindow'] : null;
            if ($total !== null) {
                $quota['tokens_input'] = (int)($total['inputTokens'] ?? 0);
                $quota['tokens_output'] = (int)($total['outputTokens'] ?? 0);
                $quota['tokens_reasoning'] = (int)($total['reasoningOutputTokens'] ?? 0);
                $quota['tokens_cached'] = (int)($total['cachedInputTokens'] ?? 0);
                $quota['tokens_total'] = (int)($total['totalTokens'] ?? 0);
                if ($contextWindow !== null && $contextWindow > 0) {
                    $quota['context'] = ['pct' => min(100, (int)round($quota['tokens_total'] * 100 / $contextWindow))];
                }
            }
        }

        if ($quota === []) return null;
        $fetchedAt = time();
        $quota['captured_at'] = date('c', $fetchedAt);
        return ['quota' => $quota, 'fetched_at' => $fetchedAt];
    }

    /**
     * Context-window usage for one specific session's own pane - unlike
     * every other bucket in this class, this is genuinely per-session, not
     * account-wide. A dedicated capture-pane call keyed to the requested
     * session.
     *
     * Reads StatuslineMarkerService's own JSON marker (parse_marker_from_pane())
     * rather than regexing Claude Code's own rendered status line text for
     * "ctx: N%" (removed 2026-08-21 - see that method's own former
     * docblock). That used to be a second, independent path to the exact
     * same underlying context_window.used_percentage number, and a
     * fragile one: it only ever matched because Claude Code's DEFAULT
     * status line happens to render that text, but this app always
     * installs a CUSTOM statusLine script (see StatuslineMarkerService),
     * which fully replaces whatever Claude Code's own default line would
     * have shown - so this only worked at all when the user's own custom
     * script happened to independently print matching "ctx:" text of its
     * own. The marker's ctx_pct is this app's OWN guaranteed, controlled
     * output format instead, always present regardless of anything else
     * the user's custom script prints.
     *
     * Returns null if the session isn't live, the marker isn't installed
     * yet, or its pane doesn't currently show a status line (e.g.
     * mid-response, or mid a slash command).
     */
    public static function live_context_pct(string $sessionName): ?int
    {
        $contextPct = StatuslineMarkerService::parse_marker_from_pane(TmuxService::tmux_capture_pane($sessionName))['context_used_percentage'];

        return $contextPct !== null ? (int)round($contextPct) : null;
    }

    /**
     * Reads account-wide rate-limit quota this app's own statusLine script
     * writes straight to disk (see StatuslineMarkerService's QUOTA_CAPTURE
     * block) on every Claude Code status-line render - event-driven, no
     * tmux/capture-pane involved at all. This is get_quota()'s ONLY source
     * now (the tmux-pane-scraping fallback, and the external `claude-quota`
     * binary scrape + its cache/background-refresh machinery behind it,
     * were both deleted 2026-08-22 as dead code - once ANY session has ever
     * written this file even once, it never returns null again below, so
     * neither of those fallback paths could ever actually be reached; see
     * the git history for what used to live here). resets_at here is a real
     * Unix epoch straight from Claude Code's own statusLine JSON
     * (rate_limits.*.resets_at), not reconstructed from rounded pane
     * duration text like "1h 53m" - the exact source of the 2026-08-05
     * jitter bug documented in PushDeliveryService::check_and_send_quota_pushes().
     *
     * The shell side already merges each write against whatever it last
     * saw (see the jq filter in StatuslineMarkerService) so a bucket only
     * ever moves DOWN when its resets_at also moved forward - a genuine
     * window rollover - rather than whichever session's script happened
     * to fire most recently. This method just reads the result; no merge
     * logic lives here.
     *
     * Returns null only on a genuinely fresh install (the script has never
     * fired at all yet, or no session has had a turn since) - get_quota()
     * reports "no quota data yet" for that case, nothing more to fall back
     * to.
     *
     * $profile selects which account's captured state to read (see
     * Config::quota_live_state_key()) - null (the default) preserves
     * today's single-account behavior unchanged.
     *
     * @return array{quota:array, fetched_at:int}|null
     */
    public static function quota_from_statusline_state(?string $profile = null): ?array
    {
        $decoded = GlobalStateStore::read(Config::quota_live_state_key($profile));

        if (!is_array($decoded) || !isset($decoded['captured_at']) || !is_int($decoded['captured_at'])) {
            return null;
        }

        $quota = [];

        foreach (['session', 'week_all'] as $key) {
            $bucket = $decoded[$key] ?? null;

            if (is_array($bucket) && isset($bucket['pct'], $bucket['resets_at']) && is_int($bucket['pct']) && is_int($bucket['resets_at'])) {
                $quota[$key] = ['pct' => $bucket['pct'], 'resets_at' => $bucket['resets_at']];
            }
        }

        if ($quota === []) {
            return null;
        }

        $fetchedAt = $decoded['captured_at'];
        $quota['captured_at'] = date('c', $fetchedAt);

        return ['quota' => $quota, 'fetched_at' => $fetchedAt];
    }

    /**
     * Reads Antigravity quota state from GlobalStateStore under
     * Config::antigravity_quota_live_state_key() (written by antigravity_quota_poll.php).
     *
     * `quota` is a bare `array`, not a stricter per-bucket shape - it holds
     * one `captured_at` string entry (an ISO-8601 timestamp, read by
     * quota-footer.js for its "Captured X ago" tooltip) alongside the real
     * bucket entries, same convention quota_from_statusline_state() above
     * already uses for the same reason.
     *
     * @return array{quota:array, fetched_at:int}|null
     */
    public static function antigravity_quota_state(): ?array
    {
        $decoded = GlobalStateStore::read(Config::antigravity_quota_live_state_key());

        if (!is_array($decoded) || !isset($decoded['captured_at']) || !is_int($decoded['captured_at'])) {
            return null;
        }

        $quota = [];

        foreach ($decoded as $key => $bucket) {
            if ($key === 'captured_at') {
                continue;
            }

            if (is_array($bucket) && isset($bucket['pct'], $bucket['resets_at']) && is_int($bucket['pct']) && is_int($bucket['resets_at'])) {
                $quota[$key] = [
                    'pct' => $bucket['pct'],
                    'resets_at' => $bucket['resets_at'],
                    'group_name' => is_string($bucket['group_name'] ?? null) ? $bucket['group_name'] : null,
                ];
            }
        }

        if ($quota === []) {
            return null;
        }

        $fetchedAt = $decoded['captured_at'];
        $quota['captured_at'] = date('c', $fetchedAt);

        return ['quota' => $quota, 'fetched_at' => $fetchedAt];
    }

    /**
     * Reads OpenCode usage stats directly from opencode.db (cost + token
     * counters). Unlike Claude Code (statusLine rate_limits.*) and
     * Antigravity (periodically polled `agy -p "/usage"`), opencode's
     * closest quota-like data is cumulative per-session cost and token
     * counts (session.cost, tokens_input/output/reasoning/cache_*), summed
     * for the dashboard or for one specific ses_* when $sessionId is given.
     *
     * Opened read-only (SQLITE_OPEN_READONLY) like OpenCodeTranscriptService,
     * so a live TUI writer is never blocked. No GlobalStateStore/polling
     * needed — the DB itself is the source of truth, read on every request.
     *
     * @return array{quota:array<string,mixed>, fetched_at:int}|null null when the DB is missing/empty
     */
    public static function opencode_quota_state(?string $sessionId = null): ?array
    {
        $path = Config::opencode_db_path();

        if (!is_file($path)) {
            return null;
        }

        try {
            $pdo = new \PDO('sqlite:' . $path, null, null, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::SQLITE_ATTR_OPEN_FLAGS => \PDO::SQLITE_OPEN_READONLY,
            ]);
            $pdo->exec('PRAGMA busy_timeout=5000');
        } catch (\PDOException $e) {
            return null;
        }

        if ($sessionId !== null && $sessionId !== '' && OpenCodeTranscriptService::is_opencode_id($sessionId)) {
            $stmt = $pdo->prepare('SELECT cost, tokens_input, tokens_output, tokens_reasoning, tokens_cache_read, tokens_cache_write, time_updated FROM session WHERE id = ? LIMIT 1');
            $stmt->execute([$sessionId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false || !is_array($row)) {
                return null;
            }

            $quota = [
                'cost' => (float)($row['cost'] ?? 0),
                'tokens_input' => (int)($row['tokens_input'] ?? 0),
                'tokens_output' => (int)($row['tokens_output'] ?? 0),
                'tokens_reasoning' => (int)($row['tokens_reasoning'] ?? 0),
                'tokens_cache_read' => (int)($row['tokens_cache_read'] ?? 0),
                'tokens_cache_write' => (int)($row['tokens_cache_write'] ?? 0),
            ];
            $fetchedAt = is_numeric($row['time_updated'] ?? null) ? (int)($row['time_updated'] / 1000) : time();
            $quota['captured_at'] = date('c', $fetchedAt);

            return ['quota' => $quota, 'fetched_at' => $fetchedAt];
        }

        // Dashboard: aggregate across all sessions (mirrors `opencode stats` totals)
        $row = $pdo->query('SELECT COUNT(*) as cnt, COALESCE(SUM(cost),0) as sum_cost, COALESCE(SUM(tokens_input),0) as sum_in, COALESCE(SUM(tokens_output),0) as sum_out, COALESCE(SUM(tokens_reasoning),0) as sum_reason, COALESCE(SUM(tokens_cache_read),0) as sum_cr, COALESCE(SUM(tokens_cache_write),0) as sum_cw FROM session')->fetch(\PDO::FETCH_ASSOC);

        if ($row === false || !is_array($row) || (int)($row['cnt'] ?? 0) === 0) {
            return null;
        }

        $fetchedAt = time();
        $quota = [
            'cost' => (float)($row['sum_cost'] ?? 0),
            'tokens_input' => (int)($row['sum_in'] ?? 0),
            'tokens_output' => (int)($row['sum_out'] ?? 0),
            'tokens_reasoning' => (int)($row['sum_reason'] ?? 0),
            'tokens_cache_read' => (int)($row['sum_cr'] ?? 0),
            'tokens_cache_write' => (int)($row['sum_cw'] ?? 0),
            'session_count' => (int)($row['cnt'] ?? 0),
            'captured_at' => date('c', $fetchedAt),
        ];

        return ['quota' => $quota, 'fetched_at' => $fetchedAt];
    }

    /**
     * Reads OpenCode Go's account-wide rolling, weekly, and monthly windows.
     * The endpoint is read-only and requires the opencode-go key OpenCode stores
     * in auth.json. Local SQLite totals remain useful alongside these windows.
     *
     * @return array{quota:array<string,mixed>, fetched_at:int}|null
     */
    public static function opencode_go_quota_state(): ?array
    {
        $raw = getenv('OPENCODE_GO_API_KEY');
        $key = is_string($raw) ? trim($raw) : '';

        if ($key === '' && is_file(Config::opencode_auth_path())) {
            $auth = json_decode((string)@file_get_contents(Config::opencode_auth_path()), true);
            $entry = is_array($auth) ? ($auth['opencode-go'] ?? null) : null;
            $key = is_array($entry) && is_string($entry['key'] ?? null) ? trim($entry['key']) : '';
        }

        if ($key === '') {
            return null;
        }

        $result = ProcessRunner::run_process([
            'curl', '--silent', '--show-error', '--max-time', '5',
            '--header', 'Authorization: Bearer ' . $key,
            'https://opencode.ai/zen/go/v1/usage',
        ]);
        $body = json_decode($result['stdout'], true);
        $usage = is_array($body) ? ($body['usage'] ?? null) : null;

        if ($result['exit'] !== 0 || !is_array($usage)) {
            return null;
        }

        $quota = [];
        foreach (['rolling' => 'session', 'weekly' => 'week_all', 'monthly' => 'month_all'] as $source => $target) {
            $window = $usage[$source] ?? null;
            if (!is_array($window) || !is_numeric($window['percent'] ?? null)) {
                continue;
            }

            $reset = $window['resetsAt'] ?? null;
            $resetAt = is_numeric($reset) ? (int)$reset : (is_string($reset) ? strtotime($reset) : false);
            $quota[$target] = ['pct' => (int)round((float)$window['percent'])];
            if ($resetAt !== false && $resetAt > 0) {
                $quota[$target]['resets_at'] = $resetAt;
            }
        }

        return $quota === [] ? null : ['quota' => $quota, 'fetched_at' => time()];
    }

    /**
     * Config::claude_profiles_to_scan() as a [jsonKey => profile] map -
     * null (the default account) can't be a JSON object key, so it's
     * spelled 'personal' here (matching agents.php's own name for it and
     * Config::claude_profile_label()'s null-collapses-to-"Personal" rule)
     * while every other entry keeps its real profile name unchanged.
     *
     * @return array<string, ?string>
     */
    private static function claude_profiles_to_scan_keyed(): array
    {
        $keyed = [];

        foreach (Config::claude_profiles_to_scan() as $profile) {
            $keyed[$profile ?? 'personal'] = $profile;
        }

        return $keyed;
    }

    /**
     * When $sessionName is given, returns the real quota for that session's
     * specific agent (Claude Code or Antigravity, looked up from its sidecar),
     * plus the full multi-agent `agents` map for consistency with the
     * dashboard. For a Claude Code session, additionally includes a top-level
     * `context` field with that session's own context-window percentage
     * (see live_context_pct()), only when readable — naturally absent for
     * every other agent and for the dashboard's own call.
     *
     * When $sessionName is omitted/empty (dashboard request), returns an
     * `agents` map with all agents' quotas so the dashboard footer can render
     * a multi-agent comparison table, plus top-level quota from the first
     * available agent.
     *
     * `cached`/`stale`/`refreshing` are always false now - kept in the
     * return shape for frontend compatibility.
     *
     * `claude_profiles` is always present too (regardless of $sessionName
     * or which agent a given session turns out to be) - one entry per
     * distinct configured Claude account, see claude_profiles_to_scan_keyed().
     *
     * @return array{ok:bool, quota:?array, agents:array<string, array{label:string, ok:bool, quota:?array, fetched_at:?int, message:?string}>, claude_profiles:array<string, array{label:string, ok:bool, quota:?array, fetched_at:?int, message:?string}>, fetched_at:?int, cached:bool, stale:bool, refreshing:bool, agent?:string, agent_label?:string, context?:array{pct:int}, message?:?string}
     */
    public static function get_quota(?string $sessionName = null): array
    {
        // Always compute the agents map for both session and dashboard requests
        $claudeLive = self::quota_from_statusline_state();
        $agLive = self::antigravity_quota_state();
        $ocLive = self::opencode_quota_state();
        $ocGoLive = self::opencode_go_quota_state();
        $codexLive = self::codex_quota_state();
        if ($ocGoLive !== null) {
            $ocLive = [
                'quota' => ($ocLive['quota'] ?? []) + $ocGoLive['quota'],
                'fetched_at' => max($ocLive['fetched_at'] ?? 0, $ocGoLive['fetched_at']),
            ];
        }

        // One entry per distinct configured Claude account (see
        // Config::claude_profiles_to_scan()'s own dedup-by-resolved-dir
        // logic), always computed regardless of $sessionName - "show every
        // profile's quota" is a footer-wide concern, not tied to which
        // single session's page happens to be open. Collapses to just the
        // one default entry (key 'personal') on an install with no work
        // profile configured, so quota-footer.js's rendering degrades back
        // to today's single "Claude Code" row automatically.
        $claudeProfiles = [];
        foreach (self::claude_profiles_to_scan_keyed() as $profileKey => $profile) {
            $live = $profile === null ? $claudeLive : self::quota_from_statusline_state($profile);
            $claudeProfiles[$profileKey] = [
                'label' => Config::claude_profile_label($profile),
                'ok' => $live !== null,
                'quota' => $live['quota'] ?? null,
                'fetched_at' => $live['fetched_at'] ?? null,
                'message' => $live === null ? 'No quota data yet - open a Claude Code session under this account to populate it' : null,
            ];
        }

        $agents = [
            'claude' => [
                'label' => 'Claude Code',
                'ok' => $claudeLive !== null,
                'quota' => $claudeLive['quota'] ?? null,
                'fetched_at' => $claudeLive['fetched_at'] ?? null,
                'message' => $claudeLive === null ? 'No quota data yet - open a Claude Code session to populate it' : null,
            ],
            'antigravity' => [
                'label' => 'Antigravity',
                'ok' => $agLive !== null,
                'quota' => $agLive['quota'] ?? null,
                'fetched_at' => $agLive['fetched_at'] ?? null,
                'message' => $agLive === null ? 'No quota data yet - sessioneer-antigravity-quota-check timer has not run' : null,
            ],
            'opencode' => [
                'label' => 'OpenCode',
                'ok' => $ocLive !== null,
                'quota' => $ocLive['quota'] ?? null,
                'fetched_at' => $ocLive['fetched_at'] ?? null,
                'message' => $ocLive === null ? 'No OpenCode sessions yet' : null,
            ],
            'codex' => [
                'label' => 'Codex',
                'ok' => $codexLive !== null,
                'quota' => $codexLive['quota'] ?? null,
                'fetched_at' => $codexLive['fetched_at'] ?? null,
                'message' => $codexLive === null ? 'Codex bridge unavailable' : null,
            ],
        ];

        // Dashboard request (no single session)
        if ($sessionName === null || $sessionName === '') {
            $hasAnyData = $claudeLive !== null || $agLive !== null || $ocLive !== null || $codexLive !== null;

            return [
                'ok' => $hasAnyData,
                'quota' => $claudeLive['quota'] ?? ($agLive['quota'] ?? ($ocLive['quota'] ?? ($codexLive['quota'] ?? null))),
                'agents' => $agents,
                'claude_profiles' => $claudeProfiles,
                'fetched_at' => $claudeLive['fetched_at'] ?? ($agLive['fetched_at'] ?? ($ocLive['fetched_at'] ?? ($codexLive['fetched_at'] ?? null))),
                'cached' => false,
                'stale' => false,
                'refreshing' => false,
                'message' => $hasAnyData ? null : 'No quota data yet - open a session or wait for the quota timer',
            ];
        }

        // Session-specific request - return the agent's own quota plus full agents map
        $sidecar = SidecarStore::read_sidecar($sessionName);
        $agent = is_string($sidecar['agent'] ?? null) ? $sidecar['agent'] : 'claude';

        if ($agent === 'antigravity') {
            if ($agLive !== null) {
                return [
                    'ok' => true,
                    'quota' => $agLive['quota'],
                    'agents' => $agents,
                'claude_profiles' => $claudeProfiles,
                    'fetched_at' => $agLive['fetched_at'],
                    'cached' => false,
                    'stale' => false,
                    'refreshing' => false,
                    'agent' => 'antigravity',
                    'agent_label' => 'Antigravity',
                ];
            }

            return [
                'ok' => false,
                'quota' => null,
                'agents' => $agents,
                'claude_profiles' => $claudeProfiles,
                'fetched_at' => null,
                'cached' => false,
                'stale' => false,
                'refreshing' => false,
                'agent' => 'antigravity',
                'agent_label' => 'Antigravity',
                'message' => 'No Antigravity quota data yet - sessioneer-antigravity-quota-check timer has not run',
            ];
        }

        if ($agent === 'opencode') {
            $opencodeSessionId = is_string($sidecar['agent_session_id'] ?? null) ? $sidecar['agent_session_id'] : null;
            $ocSessionLive = self::opencode_quota_state($opencodeSessionId);
            $goLive = self::opencode_go_quota_state();
            if ($goLive !== null) {
                $ocSessionLive = [
                    'quota' => ($ocSessionLive['quota'] ?? []) + $goLive['quota'],
                    'fetched_at' => max($ocSessionLive['fetched_at'] ?? 0, $goLive['fetched_at']),
                ];
            }

            if ($ocSessionLive !== null) {
                return [
                    'ok' => true,
                    'quota' => $ocSessionLive['quota'],
                    'agents' => $agents,
                'claude_profiles' => $claudeProfiles,
                    'fetched_at' => $ocSessionLive['fetched_at'],
                    'cached' => false,
                    'stale' => false,
                    'refreshing' => false,
                    'agent' => 'opencode',
                    'agent_label' => 'OpenCode',
                ];
            }

            return [
                'ok' => false,
                'quota' => null,
                'agents' => $agents,
                'claude_profiles' => $claudeProfiles,
                'fetched_at' => null,
                'cached' => false,
                'stale' => false,
                'refreshing' => false,
                'agent' => 'opencode',
                'agent_label' => 'OpenCode',
                'message' => 'No OpenCode quota data yet - no opencode sessions recorded',
            ];
        }

        if ($agent === 'codex') {
            $codexSessionLive = self::codex_quota_state($sessionName);
            return [
                'ok' => $codexSessionLive !== null,
                'quota' => $codexSessionLive['quota'] ?? null,
                'agents' => $agents,
                'claude_profiles' => $claudeProfiles,
                'fetched_at' => $codexSessionLive['fetched_at'] ?? null,
                'cached' => false,
                'stale' => false,
                'refreshing' => false,
                'agent' => 'codex',
                'agent_label' => 'Codex',
                'message' => $codexSessionLive === null ? 'No Codex quota data available from app-server' : null,
            ];
        }

        // Claude Code session
        $contextPct = self::live_context_pct($sessionName);

        if ($claudeLive !== null) {
            $result = [
                'ok' => true,
                'quota' => $claudeLive['quota'],
                'agents' => $agents,
                'claude_profiles' => $claudeProfiles,
                'fetched_at' => $claudeLive['fetched_at'],
                'cached' => false,
                'stale' => false,
                'refreshing' => false,
                'agent' => 'claude',
                'agent_label' => 'Claude Code',
            ];
        } else {
            $result = [
                'ok' => false,
                'quota' => null,
                'agents' => $agents,
                'claude_profiles' => $claudeProfiles,
                'fetched_at' => null,
                'cached' => false,
                'stale' => false,
                'refreshing' => false,
                'agent' => 'claude',
                'agent_label' => 'Claude Code',
                'message' => 'No quota data yet - open a Claude Code session to populate it',
            ];
        }

        if ($contextPct !== null) {
            $result['context'] = ['pct' => $contextPct];
            $result['ok'] = true;
            $result['fetched_at'] ??= time();
        }

        return $result;
    }
}
