<?php

declare(strict_types=1);

namespace App\Views;

/**
 * The dashboard's session/bare-process list rows - shared between
 * index.php's SSR render and sessions_fragment.php's poll response so
 * both always render from the exact same markup.
 */
class SessionRowView extends View
{
    /**
     * Get the card background style for a session row based on agent ID.
     * Extracted so both row.php and sidebar-row.php use the same mapping.
     */
    public static function agent_card_style(string $agentId): string
    {
        return match ($agentId) {
            'opencode' => 'background-color: rgba(46, 16, 101, 0.22); border-color: rgba(109, 40, 217, 0.32)',
            'antigravity' => 'background-color: rgba(69, 26, 3, 0.18); border-color: rgba(180, 83, 9, 0.28)',
            'codex' => 'background-color: rgba(3, 78, 92, 0.18); border-color: rgba(8, 145, 178, 0.32)',
            default => 'background-color: rgba(15, 23, 42, 0.5); border-color: rgb(30, 41, 59)',
        };
    }

    /**
     * Get the badge CSS class for a session's agent based on agent ID.
     * Extracted so both row.php and sidebar-row.php use the same mapping.
     */
    public static function agent_badge_class(string $agentId): string
    {
        return match ($agentId) {
            'opencode' => 'bg-violet-900/50 text-violet-300 border-violet-700/50',
            'antigravity' => 'bg-amber-900/40 text-amber-300 border-amber-700/40',
            'codex' => 'bg-cyan-900/40 text-cyan-300 border-cyan-700/40',
            default => 'bg-slate-800 text-slate-400 border-slate-700',
        };
    }

    /**
     * A compact "Thinking…" badge for a dashboard row - the dashboard's own
     * version of TranscriptView::render_thinking_indicator_html() (same
     * $s['working'] source field, see SessionStatusStore and
     * build_session_entry() in host-agent/lib/Services/SessionService.php),
     * minus the Stop button: this row has no dedicated place to put a
     * per-session action button, and the session's own detail page is one
     * tap away via the row's own link for anyone who wants to actually
     * intervene. Mutually exclusive with the blocked-prompt treatment - a
     * session actively working isn't also sitting on an unanswered prompt.
     */
    public static function dashboard_thinking_indicator_html(array $s): string
    {
        if (empty($s['working']) || !empty($s['blocked_reason'])) {
            return '';
        }

        return self::render('session-row/thinking-indicator');
    }

    /**
     * One session's dashboard row - extracted verbatim from index.php so both
     * the initial SSR page and sessions_fragment.php's poll response render
     * from the exact same markup, never two copies to keep in sync.
     *
     * @param array<string, mixed> $s
     */
    public static function session_row_html(array $s, string $csrfToken): string
    {
        if (!empty($s['blocked_reason']) && !empty($s['prompt_is_folder_trust'])) {
            $blockedHtml = BlockedPromptView::blocked_prompt_panel_html($s);
        } elseif (!empty($s['blocked_reason'])) {
            $blockedHtml = BlockedPromptView::blocked_prompt_rich_html($s, $csrfToken, true);
        } else {
            $blockedHtml = self::dashboard_thinking_indicator_html($s)
                . BlockedPromptView::last_message_preview_html($s['last_message'] ?? null, 'mt-1');
        }

        $name = (string)$s['name'];
        $title = (string)($s['title'] ?? $name);

        return self::render('session-row/row', [
            'name' => $name,
            'title' => $title,
            // SessionService::build_session_entry()'s title always resolves
            // to SOMETHING now (ai-title -> live pane -> workdir basename ->
            // raw name, see SessionService::session_title()) - the raw name
            // subtitle below is only worth showing when title is genuinely
            // different text, not a second copy of the same string.
            'hasExplicitTitle' => $title !== $name,
            'workdir' => $s['workdir'] ?? null,
            'relativeTime' => self::relative_time((int)$s['activity']),
            'attached' => !empty($s['attached']),
            'contextUsedPercentage' => $s['context_used_percentage'] ?? null,
            'gitWorktree' => $s['git_worktree'] ?? null,
            'blockedHtml' => $blockedHtml,
            'csrfToken' => $csrfToken,
            'agentId' => $s['agent'] ?? 'claude',
            'agentLabel' => $s['agent_label'] ?? 'Claude Code',
            'status' => $s['status'] ?? 'idle',
            'runtime' => $s['runtime'] ?? 'tmux',
            'kind' => $s['kind'] ?? 'user',
            'parentSessionId' => $s['parent_session_id'] ?? null,
        ]);
    }

    /**
     * The dashboard's whole session list, including the "nothing running yet"
     * empty state - see session_row_html() for why this is shared between
     * index.php's SSR render and sessions_fragment.php's poll response.
     *
     * @param array<int, array<string, mixed>> $sessions
     */
    public static function sessions_list_html(array $sessions, string $csrfToken): string
    {
        if ($sessions === []) {
            return self::render('session-row/empty-state');
        }

        $rows = '';

        foreach ($sessions as $s) {
            $rows .= self::session_row_html($s, $csrfToken);
        }

        return self::render('session-row/list', [
            'rowsHtml' => $rows,
        ]);
    }

    /**
     * One "other claude process on host" (not managed by this tool) dashboard
     * row - see session_row_html() for why this is shared between SSR and the
     * poll fragment.
     *
     * @param array<string, mixed> $b
     */
    public static function bare_process_row_html(array $b, string $csrfToken): string
    {
        $tmuxSession = !empty($b['tmux_session']) ? (string)$b['tmux_session'] : null;

        return self::render('session-row/bare-process-row', [
            'pid' => (int)$b['pid'],
            'title' => $b['title'] ?? null,
            'tmuxSession' => $tmuxSession,
            'cwd' => $b['cwd'] ?? null,
            'startedAt' => ($b['started_at'] ?? null) !== null ? self::relative_time((int)$b['started_at']) : null,
            'csrfToken' => $csrfToken,
            // Set only when BareProcessService::enrich_bare_with_confirmed_ids()
            // (see its own docblock) could identify this row with certainty
            // (an argv --resume/--session-id, or the daemon roster) - never
            // the unconfirmed heuristic guess, which stays behind the
            // explicit "Identify" click below.
            'resolvedTitle' => is_string($b['resolved_title'] ?? null) ? $b['resolved_title'] : null,
            // Set only by BareProcessService::declutter_bare_list() - a
            // daemon-managed worker's OTHER real process (its bg-pty-host
            // wrapper or bg-spare child, whichever this row's own cwd
            // didn't win out over) that's backing this exact same session
            // but isn't shown as its own separate row.
            'hiddenWorkerCount' => (int)($b['hidden_worker_count'] ?? 0),
        ]);
    }

    /**
     * The dashboard's "Other claude processes on host" section - empty string
     * (nothing rendered at all) when there are none, matching index.php's own
     * $agentReachable && !empty($bare) gate.
     *
     * @param array<int, array<string, mixed>> $bare
     */
    public static function bare_processes_html(array $bare, string $csrfToken): string
    {
        if ($bare === []) {
            return '';
        }

        $rows = '';

        foreach ($bare as $b) {
            $rows .= self::bare_process_row_html($b, $csrfToken);
        }

        return self::render('session-row/bare-processes', [
            'rowsHtml' => $rows,
        ]);
    }

    /**
     * The dashboard header's "N active tracked sessions" line - shared so a
     * poll (sessions_fragment.php) can keep the count in sync with the list
     * below it without duplicating the pluralization rule in JS.
     */
    public static function session_count_label_html(int $count): string
    {
        return self::render('session-row/count-label', [
            'count' => $count,
        ]);
    }

    /**
     * One archived (dormant) session's dashboard row - title/cwd/last-active.
     * Links straight to the read-only archived transcript view; also renders
     * a "Resume" button (phase 5 of the unify-claude-sessions plan) when
     * $a['cwd'] is known - resume_agent_session() needs an absolute workdir to
     * spawn into, and a handful of archived rows have a null cwd
     * (TranscriptService::find_first_cwd() found no real message line to
     * read it from), so those rows just don't get a button rather than
     * posting a workdir-less resume the agent would only reject anyway.
     *
     * @param array<string, mixed> $a
     */
    public static function archived_session_row_html(array $a, string $csrfToken): string
    {
        return self::render('session-row/archived-row', [
            'agentSessionId' => (string)$a['agent_session_id'],
            'title' => (string)($a['title'] ?? $a['agent_session_id']),
            'cwd' => $a['cwd'] ?? null,
            'relativeTime' => self::relative_time((int)($a['last_activity'] ?? 0)),
            'csrfToken' => $csrfToken,
            'agentId' => $a['agent'] ?? 'claude',
            'agentLabel' => $a['agent_label'] ?? 'Claude Code',
            'runtime' => $a['runtime'] ?? (($a['agent'] ?? 'claude') === 'opencode' ? 'headless' : 'tmux'),
        ]);
    }

    /**
     * The dashboard's whole archived-sessions section (search field + rows,
     * or the empty state) - fetched once, lazily, only when the archived
     * toggle is actually opened (see DashboardController::archivedFragment()
     * and index.js's show-archived-btn handler).
     *
     * @param array<int, array<string, mixed>> $archived
     */
    public static function archived_sessions_html(array $archived, string $csrfToken): string
    {
        if ($archived === []) {
            return self::render('session-row/archived-empty-state');
        }

        $rows = '';

        foreach ($archived as $a) {
            $rows .= self::archived_session_row_html($a, $csrfToken);
        }

        return self::render('session-row/archived-list', [
            'rowsHtml' => $rows,
        ]);
    }

    /**
     * A compact sidebar variant of session_row_html() - reuses the dashboard's
     * rich blocked-prompt rendering (option buttons, free-text reveal) and
     * last-message preview instead of client-side JS reimplementation.
     * Omits the "Kill" button and "show last 3 messages" widget that don't
     * belong in the narrow sidebar.
     *
     * @param array<string, mixed> $s
     */
    public static function sidebar_row_html(array $s, string $csrfToken): string
    {
        if (!empty($s['blocked_reason']) && !empty($s['prompt_is_folder_trust'])) {
            $blockedHtml = BlockedPromptView::blocked_prompt_panel_html($s);
        } elseif (!empty($s['blocked_reason'])) {
            $blockedHtml = BlockedPromptView::blocked_prompt_rich_html($s, $csrfToken, true);
        } else {
            $blockedHtml = BlockedPromptView::last_message_preview_html($s['last_message'] ?? null, 'mt-1');
        }

        $name = (string)$s['name'];
        $title = (string)($s['title'] ?? $name);
        $agentId = $s['agent'] ?? 'claude';

        return self::render('session-row/sidebar-row', [
            'name' => $name,
            'title' => $title,
            'workdir' => $s['workdir'] ?? null,
            'blockedHtml' => $blockedHtml,
            'csrfToken' => $csrfToken,
            'agentId' => $agentId,
            'agentLabel' => $s['agent_label'] ?? 'Claude Code',
            'status' => $s['status'] ?? 'idle',
            'runtime' => $s['runtime'] ?? 'tmux',
            'kind' => $s['kind'] ?? 'user',
            'attached' => !empty($s['attached']),
            'contextUsedPercentage' => $s['context_used_percentage'] ?? null,
            'gitWorktree' => $s['git_worktree'] ?? null,
        ]);
    }

    /**
     * The sidebar's session list - filters out the current session ($sessionName)
     * and renders via sidebar_row_html() for each. Returns server-rendered HTML
     * instead of JSON, so sidebar.js can set .innerHTML directly without JS
     * templating.
     *
     * @param array<int, array<string, mixed>> $sessions
     */
    public static function sidebar_sessions_list_html(array $sessions, ?string $sessionName, string $csrfToken): string
    {
        $rows = '';

        foreach ($sessions as $s) {
            // Filter out the current session - sidebar only shows "other" sessions
            if ((string)($s['name'] ?? '') === $sessionName) {
                continue;
            }

            $rows .= self::sidebar_row_html($s, $csrfToken);
        }

        // No wrapping partial here on purpose - #sidebar-list (sidebar.php)
        // already IS the flex/gap container this HTML gets inserted into
        // (innerHTML), so a second nested flex wrapper around $rows would
        // only add a redundant gap value between it and its own single
        // child, never actually spacing the rows themselves.
        return $rows;
    }

    public static function relative_time(int $timestamp): string
    {
        $diff = time() - $timestamp;

        if ($diff < 60) {
            return 'just now';
        }

        if ($diff < 3600) {
            $m = intdiv($diff, 60);
            return "{$m} min ago";
        }

        if ($diff < 86400) {
            $h = intdiv($diff, 3600);
            return "{$h} hr" . ($h > 1 ? 's' : '') . ' ago';
        }

        $d = intdiv($diff, 86400);
        return "{$d} day" . ($d > 1 ? 's' : '') . ' ago';
    }
}
