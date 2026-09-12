<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Untracked ("bare") claude process discovery and take-over. Split out of
 * SessionService.php (2026-08-24 readability audit - see the plan this
 * followed) - the one cluster with real fan-out, depending on core
 * SessionService::list_all_sessions(), ArchivedSessionService::
 * list_archived_sessions(), and SessionLifecycleService::resume_agent_session()
 * - inherent to what "take over a bare process" does (search archived
 * candidates, exclude already-tracked ones, then resume), a legitimate
 * orchestration role rather than a smell, and still a one-directional
 * dependency (nothing it depends on calls back into it). Methods/bodies
 * moved verbatim, no behavior changes.
 */
class BareProcessService
{
    /**
     * Kills a "bare" claude process (one ProcessInspector::find_claude_processes() found running
     * on the host that isn't inside a tracked - i.e. sidecar-having, see
     * TmuxService::list_tracked_tmux_sessions() - session) by pid.
     * $pid is re-scanned against a fresh ProcessInspector::find_claude_processes() rather than
     * trusted from the caller, so a stale or reused pid can't be used to kill
     * an unrelated process. If the pid lives inside some other, untracked
     * tmux session (e.g. one created by hand whose SessionStart hook hasn't
     * fired yet), the whole session is killed for a clean shutdown of that
     * pane; otherwise SIGTERM is sent directly.
     *
     * @return array{ok:bool, message:string}
     */
    public static function kill_bare_process(int $pid): array
    {
        $stillRunning = false;

        foreach (ProcessInspector::find_claude_processes() as $proc) {
            if ($proc['pid'] === $pid) {
                $stillRunning = true;
                break;
            }
        }

        if (!$stillRunning) {
            return ['ok' => false, 'message' => 'Rejected: not a currently running claude process'];
        }

        $owningPane = ProcessInspector::find_owning_pane($pid, TmuxService::all_tmux_panes(), ProcessInspector::build_ppid_map());

        if ($owningPane !== null) {
            $result = TmuxService::tmux_run(['kill-session', '-t', $owningPane['session']]);

            return $result['exit'] === 0
                ? ['ok' => true, 'message' => "Killed tmux session {$owningPane['session']} (pid {$pid})"]
                : ['ok' => false, 'message' => "Failed to kill session {$owningPane['session']}: " . trim($result['stderr'])];
        }

        $result = ProcessRunner::run_process(['kill', '-TERM', (string)$pid]);

        return $result['exit'] === 0
            ? ['ok' => true, 'message' => "Sent SIGTERM to pid {$pid}"]
            : ['ok' => false, 'message' => "Failed to kill pid {$pid}: " . trim($result['stderr'])];
    }

    /**
     * The one signal that can identify a bare (untracked) process's exact
     * agent_session_id with certainty: Claude Code's statusLine feature
     * reports session_id in the JSON it feeds a configured statusline
     * script, and StatuslineMarkerService::parse_marker_from_pane() reads
     * that back out of the pane's own rendered text - the same capture-
     * pane mechanism already used for quota scraping, just pointed at
     * whatever tmux pane this pid happens to live in rather than a
     * tracked session's own pane. Requires (a) Andres has opted into
     * installing the marker and (b) the pid actually has an owning tmux
     * pane at all - a truly bare process (no tmux, e.g. a plain terminal/
     * SSH session with no wrapper) can never be matched this way, since
     * there is no pane to capture from in the first place (checked
     * 2026-08-08: a pty has no scrollback of its own - only whatever's
     * rendering it, a real terminal emulator or a multiplexer like tmux,
     * holds that state, and this app has no access to a foreign terminal
     * emulator's memory). Returns null rather than a phantom id if the
     * marker names a session with no real transcript on disk - same
     * "must have a real transcript" rule used everywhere else a live
     * signal is trusted enough to act on (see the SessionStart hook,
     * self_heal_agent_session_id()).
     */
    private static function bare_process_live_agent_session_id(int $pid): ?string
    {
        $owningPane = ProcessInspector::find_owning_pane($pid, TmuxService::all_tmux_panes(), ProcessInspector::build_ppid_map());

        if ($owningPane === null) {
            return null;
        }

        $paneContent = TmuxService::tmux_capture_pane($owningPane['session']);
        $sessionId = StatuslineMarkerService::parse_marker_from_pane($paneContent)['session_id'];

        if ($sessionId === null || TranscriptService::find_transcript_path($sessionId) === null) {
            return null;
        }

        return $sessionId;
    }

    /**
     * Every dormant transcript for one specific cwd (a bare process's own
     * working directory), each carrying a "how likely is this the pid's
     * own session" suggestion - the picker-fallback half of take_over_
     * bare_process(), used when bare_process_live_agent_session_id()
     * can't produce a confident match. The suggestion is a heuristic, not
     * a guarantee (Andres's own idea, 2026-08-08): a bare process's OS
     * pid is never recorded in the transcript itself (see
     * TranscriptService::find_first_timestamp()'s own doc comment), but
     * comparing the process's own start time (from /proc, via
     * ProcessInspector) against each candidate's first-message
     * timestamp - i.e. when that transcript's own conversation actually
     * began - and preferring the closest match works well for the common
     * case this exists for: an untracked `claude` typed by hand starts a
     * brand new conversation, whose transcript is created within moments
     * of the process itself starting. It's deliberately just a
     * pre-selected default in a still-fully-overridable list, not a
     * forced choice - a process that's actually a bare `--resume` of a
     * much older conversation won't match this way, and the full
     * candidate list (sorted most-recent-first, same as the archived
     * list) is always there to pick a different one from.
     *
     * @return array{candidates: array<int, array{agent_session_id:string, cwd:?string, title:string, last_activity:int}>, suggested_agent_session_id: ?string}
     */
    private static function bare_process_take_over_candidates(string $workdir, int $processStartedAt, int $excludePid): array
    {
        $trackedIds = [];

        foreach (SessionService::list_all_sessions()['sessions'] as $s) {
            if (is_string($s['agent_session_id'] ?? null)) {
                $trackedIds[] = $s['agent_session_id'];
            }
        }

        // Also exclude any OTHER bare (untracked) process's own live
        // session, when its id happens to be confidently resolvable via
        // the same statusline-marker match used for the pid actually
        // being taken over (Andres's own concern, 2026-08-08): resume_
        // cc_session()'s own already-live guard only checks TRACKED
        // sessions (it reads sidecars), since an untracked bare process
        // has no sidecar to check against - without this, a candidate
        // transcript still being actively written by a different live
        // bare process could end up with two panes fighting over it the
        // moment it's resumed.
        foreach (SessionService::list_all_sessions()['bare'] as $b) {
            if (($b['pid'] ?? null) === $excludePid || ($b['cwd'] ?? null) !== $workdir) {
                continue;
            }

            $otherId = self::bare_process_live_agent_session_id((int)$b['pid']);

            if ($otherId !== null) {
                $trackedIds[] = $otherId;
            }
        }

        $candidates = array_values(array_filter(
            ArchivedSessionService::list_archived_sessions($trackedIds),
            static fn(array $a): bool => $a['cwd'] === $workdir,
        ));

        $suggestedId = null;
        $closestDelta = null;

        foreach ($candidates as $c) {
            $path = TranscriptService::find_transcript_path($c['agent_session_id']);
            $created = $path !== null ? TranscriptService::find_first_timestamp($path) : null;

            if ($created === null) {
                continue;
            }

            $delta = abs($created - $processStartedAt);

            if ($closestDelta === null || $delta < $closestDelta) {
                $closestDelta = $delta;
                $suggestedId = $c['agent_session_id'];
            }
        }

        return ['candidates' => $candidates, 'suggested_agent_session_id' => $suggestedId];
    }

    /**
     * Every agent_session_id currently live via a BARE (untracked) claude
     * process on the host - found live 2026-09-11 (Andres: his own actual,
     * currently-open terminal session showed up in the dashboard's
     * "archived" list, and "resuming" it from there wouldn't have made
     * sense - it was never dormant in the first place). Neither
     * ArchivedSessionService::list_archived_dashboard() nor
     * SessionLifecycleService::resume_agent_session() checked bare
     * processes at all before this - only TRACKED (tmux+sidecar) sessions
     * were ever excluded from "archived"/guarded against a duplicate
     * resume, so a plain `claude` typed by hand in a real terminal (no
     * tmux, no sidecar - exactly how this app's own live session runs)
     * was invisible to both checks.
     *
     * Prefers the certain signal (bare_process_live_agent_session_id() -
     * a real statusline-marker read, only available when the pid has an
     * owning tmux pane) and falls back to bare_process_take_over_
     * candidates()'s own closest-start-time guess when there's no marker
     * to read at all (the common case for a truly bare, no-tmux terminal
     * session) - that guess can occasionally be wrong (see its own
     * docblock), but the asymmetry favors erring toward inclusion here: a
     * dormant transcript wrongly hidden from "archived" for as long as an
     * unrelated bare process happens to share its cwd is a minor,
     * self-correcting annoyance, while a truly-live one left resumable
     * risks two processes silently fighting over one transcript file.
     *
     * @return string[]
     */
    public static function live_bare_agent_session_ids(): array
    {
        $ids = [];

        foreach (SessionService::list_all_sessions()['bare'] as $b) {
            $pid = (int)($b['pid'] ?? 0);

            if ($pid <= 0) {
                continue;
            }

            $markerId = self::bare_process_live_agent_session_id($pid);

            if ($markerId !== null) {
                $ids[] = $markerId;

                continue;
            }

            $cwd = is_string($b['cwd'] ?? null) ? $b['cwd'] : null;
            $startedAt = is_int($b['started_at'] ?? null) ? $b['started_at'] : null;

            if ($cwd === null || $startedAt === null) {
                continue;
            }

            $heuristicId = self::bare_process_take_over_candidates($cwd, $startedAt, $pid)['suggested_agent_session_id'];

            if ($heuristicId !== null) {
                $ids[] = $heuristicId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Best-effort identification for one bare (untracked) process's dashboard
     * row - Andres's own ask (2026-09-11), after finding that "Other claude
     * processes on host" gave no way to tell whether a row duplicated
     * something already in the archived list. Reuses the exact same
     * resolution this class already relies on elsewhere (never invented
     * fresh for this): the certain statusline-marker match
     * (bare_process_live_agent_session_id()) when the pid has an owning
     * tmux pane with the marker installed, falling back to
     * bare_process_take_over_candidates()'s own closest-start-time GUESS
     * otherwise - `confidence` tells the caller which one it got, so the
     * view can label a guess as a guess rather than asserting it. Read-only:
     * unlike take_over_bare_process(), nothing is killed or resumed here.
     *
     * Identification only, deliberately - once the caller has the resolved
     * agent_session_id back, the actual message history is one already-
     * existing call away (archived_session_history_fragment.php, the same
     * endpoint an archived row's own preview uses), rather than this method
     * re-reading and re-rendering transcript content a second, slightly
     * different way.
     *
     * @return array{ok:bool, message?:string, agent_session_id?:?string, confidence?:?string, title?:?string}
     */
    public static function resolve_bare_process_detail(int $pid): array
    {
        $cwd = null;
        $startedAt = null;

        foreach (ProcessInspector::find_claude_processes() as $proc) {
            if ($proc['pid'] === $pid) {
                $cwd = $proc['cwd'];
                $startedAt = $proc['started_at'];
                break;
            }
        }

        if ($cwd === null) {
            return ['ok' => false, 'message' => 'Rejected: not a currently running claude process, or its working directory could not be determined'];
        }

        $agentSessionId = self::bare_process_live_agent_session_id($pid);
        $confidence = $agentSessionId !== null ? 'confirmed' : null;

        if ($agentSessionId === null) {
            $agentSessionId = self::bare_process_take_over_candidates($cwd, $startedAt ?? time(), $pid)['suggested_agent_session_id'];
            $confidence = $agentSessionId !== null ? 'guess' : null;
        }

        if ($agentSessionId === null) {
            return ['ok' => true, 'agent_session_id' => null, 'confidence' => null, 'title' => null];
        }

        $path = TranscriptRouter::find_transcript_path($agentSessionId);
        $title = $path !== null ? SessionService::title_cascade(TranscriptService::find_latest_ai_title($path), null, $cwd, $agentSessionId) : null;

        return ['ok' => true, 'agent_session_id' => $agentSessionId, 'confidence' => $confidence, 'title' => $title];
    }

    /**
     * "Take over" a foreign (bare/untracked) claude process - the
     * unify-claude-sessions plan's phase 6. Two outcomes:
     *
     * 1. A confident match (see bare_process_live_agent_session_id()):
     *    kills the pid and resumes that exact session in one call - a
     *    genuine single click, nothing more needed from the caller.
     * 2. No confident match: returns the cwd's candidate sessions instead,
     *    WITHOUT killing anything - fully cancelable, no side effects,
     *    until the caller picks one and calls
     *    take_over_bare_process_with_id(). This is deliberate: killing
     *    someone's live terminal is hard to reverse, so nothing
     *    destructive happens until either a real match is found or a
     *    human explicitly confirms which conversation to resume.
     *
     * @return array{ok:bool, message?:string, name?:string, needs_choice?:bool, pid?:int, workdir?:string, candidates?:array, suggested_agent_session_id?:?string}
     */
    public static function take_over_bare_process(int $pid): array
    {
        $workdir = null;
        $startedAt = null;

        foreach (ProcessInspector::find_claude_processes() as $proc) {
            if ($proc['pid'] === $pid) {
                $workdir = $proc['cwd'];
                $startedAt = $proc['started_at'];
                break;
            }
        }

        if ($workdir === null) {
            return ['ok' => false, 'message' => 'Rejected: not a currently running claude process, or its working directory could not be determined'];
        }

        $matchedId = self::bare_process_live_agent_session_id($pid);

        if ($matchedId !== null) {
            $killResult = self::kill_bare_process($pid);

            if (!($killResult['ok'] ?? false)) {
                return $killResult;
            }

            return SessionLifecycleService::resume_agent_session($workdir, $matchedId);
        }

        $resolved = self::bare_process_take_over_candidates($workdir, $startedAt ?? time(), $pid);

        return [
            'ok' => true,
            'needs_choice' => true,
            'pid' => $pid,
            'workdir' => $workdir,
            'candidates' => $resolved['candidates'],
            'suggested_agent_session_id' => $resolved['suggested_agent_session_id'],
        ];
    }

    /**
     * The confirm step after take_over_bare_process() came back
     * needs_choice=true and a human picked a specific agent_session_id
     * from the candidates. Kills $pid only if it's still actually
     * running - it may have exited on its own in the time it took to
     * choose, and that's fine, the resume below still makes sense either
     * way.
     *
     * @return array{ok:bool, message:string, name?:string}
     */
    public static function take_over_bare_process_with_id(int $pid, string $workdir, string $agentSessionId): array
    {
        foreach (ProcessInspector::find_claude_processes() as $proc) {
            if ($proc['pid'] === $pid) {
                $killResult = self::kill_bare_process($pid);

                if (!($killResult['ok'] ?? false)) {
                    return $killResult;
                }

                break;
            }
        }

        return SessionLifecycleService::resume_agent_session($workdir, $agentSessionId);
    }
}
