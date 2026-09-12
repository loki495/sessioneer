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
     * The MOST certain signal of all for a bare process's own
     * agent_session_id - more certain than the statusline marker below,
     * since it needs no tmux pane to read at all: the exact value passed
     * to --resume/--session-id, straight from ProcessInspector::
     * find_claude_processes()'s own argv read. Found live 2026-09-11
     * (Andres: a real `claude --resume <path-to-transcript>.jsonl` process,
     * launched by a different tool entirely - claude-code-ui - was still
     * showing up in the archived list): a resumed conversation's transcript
     * can be arbitrarily older than the resuming process's own start time,
     * which is exactly what makes bare_process_take_over_candidates()'s
     * closest-start-time heuristic unreliable for this shape specifically -
     * this sidesteps that guesswork entirely when the id is just sitting
     * there in argv. A bare `claude` with no such flag (an organic new
     * terminal conversation) has nothing here to read, and falls through
     * to the marker/heuristic tiers same as before.
     */
    private static function agent_session_id_from_resume_arg(?string $resumeArg): ?string
    {
        if ($resumeArg === null || $resumeArg === '') {
            return null;
        }

        $uuidPattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

        if (preg_match($uuidPattern, $resumeArg) === 1) {
            return $resumeArg;
        }

        if (str_ends_with($resumeArg, '.jsonl')) {
            $base = basename($resumeArg, '.jsonl');

            if (preg_match($uuidPattern, $base) === 1) {
                return $base;
            }
        }

        return null;
    }

    /**
     * The Claude Code CLI's own background daemon (`claude daemon run`,
     * confirmed live 2026-09-12) keeps a real-time roster of every
     * "spare"/pty-host worker pair it manages - a warm pool used to make
     * new/resumed sessions start instantly - at
     * ~/.claude/daemon/roster.json. Found live the same day (Andres: his
     * own live session, running entirely through this pool, kept showing
     * up as archived): a worker dispatched this way has NO --resume/
     * --session-id in its own process's argv at all (the daemon hands it
     * the target transcript over a private rendezvous socket instead), so
     * neither agent_session_id_from_resume_arg() nor the statusline-marker
     * tier can ever see it - this file is the only place that information
     * exists at all for this whole class of session.
     *
     * Returns the raw decoded `workers` map (keyed by short worker id),
     * or [] if the daemon isn't running or the file can't be read/parsed -
     * every caller already treats "found nothing" as a normal, silent
     * fallthrough to whatever tier comes next, same as a missing
     * statusline marker or an empty candidate list.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function daemon_roster_workers(): array
    {
        $raw = @file_get_contents(Config::home_root() . '/.claude/daemon/roster.json');

        if ($raw === false || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        $workers = is_array($decoded['workers'] ?? null) ? $decoded['workers'] : [];

        return array_filter($workers, 'is_array');
    }

    /**
     * Every agent_session_id the daemon roster currently knows about, live
     * or not - collected from TWO places per worker, not just one: the
     * roster's own `sessionId` field, and (when the worker's own pid/
     * replPid is still actually running) whatever --resume/--session-id
     * its LIVE process's real argv currently carries. Found live
     * 2026-09-12 that these can genuinely disagree: one worker's roster
     * `sessionId` pointed at a transcript untouched in days, while that
     * exact same live process had since been asked to serve a completely
     * different, actually-current transcript via a fresh --resume in its
     * own argv - the roster's own bookkeeping had simply not caught up.
     * Collecting both errs toward inclusion rather than picking one and
     * risking the wrong one.
     *
     * @return string[]
     */
    private static function daemon_roster_session_ids(): array
    {
        $ids = [];

        foreach (self::daemon_roster_workers() as $worker) {
            $workerPids = array_filter([$worker['pid'] ?? null, $worker['replPid'] ?? null], 'is_int');
            $anyAlive = false;

            foreach ($workerPids as $workerPid) {
                if (!self::pid_is_alive($workerPid)) {
                    continue;
                }

                $anyAlive = true;
                $liveResumeId = self::agent_session_id_from_resume_arg(self::resume_arg_from_pid($workerPid));

                if ($liveResumeId !== null) {
                    $ids[] = $liveResumeId;
                }
            }

            // The roster's own `sessionId` field is only trusted while at
            // least one of this worker's own pids is still actually
            // running - never indefinitely just because roster.json still
            // mentions it. Without this, a daemon crash that leaves a
            // stale roster behind (never confirmed to self-prune promptly)
            // would permanently hide a truly-dead session from "archived"
            // and refuse to ever let it be resumed again.
            if ($anyAlive && is_string($worker['sessionId'] ?? null)) {
                $ids[] = $worker['sessionId'];
            }
        }

        return array_values(array_unique($ids));
    }

    private static function pid_is_alive(int $pid): bool
    {
        return $pid > 0 && is_dir("/proc/{$pid}");
    }

    /**
     * The exact value passed to --resume/--session-id for a KNOWN pid,
     * read directly from /proc rather than going through
     * ProcessInspector::find_claude_processes()'s own full host scan -
     * used by daemon_roster_session_ids()/resolve_via_daemon_roster() to
     * check one specific worker pid/replPid at a time, not every claude
     * process on the box.
     */
    private static function resume_arg_from_pid(int $pid): ?string
    {
        $cmdlineRaw = @file_get_contents("/proc/{$pid}/cmdline");

        if ($cmdlineRaw === false || $cmdlineRaw === '') {
            return null;
        }

        $argv = explode("\0", rtrim($cmdlineRaw, "\0"));

        foreach ($argv as $i => $part) {
            if (($part === '--resume' || $part === '--session-id') && isset($argv[$i + 1])) {
                return $argv[$i + 1];
            }
        }

        return null;
    }

    /**
     * Resolves ONE known pid against the daemon roster - the pid a bare-
     * process row is actually asking about (resolve_bare_process_detail())
     * or take-over is targeting, cross-checked against every worker's
     * `pid`/`replPid` rather than scanning the whole roster's ids
     * unconditionally. Prefers that SAME worker's own live argv --resume
     * value over the roster's own `sessionId` field when both exist - see
     * daemon_roster_session_ids()'s own docblock for why the roster field
     * can lag behind it.
     */
    private static function resolve_via_daemon_roster(int $pid): ?string
    {
        foreach (self::daemon_roster_workers() as $worker) {
            if (($worker['pid'] ?? null) !== $pid && ($worker['replPid'] ?? null) !== $pid) {
                continue;
            }

            $liveResumeId = self::agent_session_id_from_resume_arg(self::resume_arg_from_pid($pid));

            if ($liveResumeId !== null) {
                return $liveResumeId;
            }

            return is_string($worker['sessionId'] ?? null) ? $worker['sessionId'] : null;
        }

        return null;
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
     * Prefers the argv-derived id above (certain, needs no tmux pane at
     * all), then the daemon roster (also certain - see its own docblock -
     * and the ONLY signal that can see a bg-spare/bg-pty-host worker at
     * all, since that shape has no --resume in its own argv anywhere),
     * then the statusline marker (certain, but only readable when the pid
     * has an owning tmux pane), and only falls back to bare_process_take_
     * over_candidates()'s own closest-start-time guess when none of those
     * found anything (the common case for a truly bare, no-tmux, NOT
     * daemon-managed, NOT explicitly --resume'd terminal session) - that
     * guess can occasionally be wrong (see its own docblock), but the
     * asymmetry favors erring toward inclusion here: a dormant transcript
     * wrongly hidden from "archived" for as long as an unrelated bare
     * process happens to share its cwd is a minor, self-correcting
     * annoyance, while a truly-live one left resumable risks two processes
     * silently fighting over one transcript file.
     *
     * The daemon roster's own ids are unioned in unconditionally (not
     * per-bare-process) - a daemon worker (bg-spare/bg-pty-host) is
     * already a real, distinct process that DOES show up in
     * SessionService::list_all_sessions()['bare'] via the loop below too,
     * but cross-referencing it there would need this exact same roster
     * lookup per pid anyway, so the whole roster is folded in once up
     * front instead.
     *
     * @return string[]
     */
    public static function live_bare_agent_session_ids(): array
    {
        $ids = self::daemon_roster_session_ids();

        foreach (SessionService::list_all_sessions()['bare'] as $b) {
            $pid = (int)($b['pid'] ?? 0);

            if ($pid <= 0) {
                continue;
            }

            $resumeId = self::agent_session_id_from_resume_arg(is_string($b['resume_arg'] ?? null) ? $b['resume_arg'] : null);

            if ($resumeId !== null) {
                $ids[] = $resumeId;

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
     * fresh for this): the certain argv-derived id
     * (agent_session_id_from_resume_arg()) first, then the daemon roster
     * (also certain - the only way to identify a bg-spare/bg-pty-host
     * worker at all, see daemon_roster_session_ids()'s own docblock), then
     * the certain statusline-marker match
     * (bare_process_live_agent_session_id()) when the pid has an owning
     * tmux pane with the marker installed, falling back to
     * bare_process_take_over_candidates()'s own closest-start-time GUESS
     * only when none of those found anything - `confidence` tells the
     * caller which tier it got, so the view can label a guess as a guess
     * rather than asserting it. Read-only:
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
        $resumeArg = null;

        foreach (ProcessInspector::find_claude_processes() as $proc) {
            if ($proc['pid'] === $pid) {
                $cwd = $proc['cwd'];
                $startedAt = $proc['started_at'];
                $resumeArg = $proc['resume_arg'] ?? null;
                break;
            }
        }

        if ($cwd === null) {
            return ['ok' => false, 'message' => 'Rejected: not a currently running claude process, or its working directory could not be determined'];
        }

        $agentSessionId = self::agent_session_id_from_resume_arg($resumeArg);
        $confidence = $agentSessionId !== null ? 'confirmed' : null;

        if ($agentSessionId === null) {
            $agentSessionId = self::resolve_via_daemon_roster($pid);
            $confidence = $agentSessionId !== null ? 'confirmed' : null;
        }

        if ($agentSessionId === null) {
            $agentSessionId = self::bare_process_live_agent_session_id($pid);
            $confidence = $agentSessionId !== null ? 'confirmed' : null;
        }

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
     * 1. A confident match (the argv-derived id, see
     *    agent_session_id_from_resume_arg(), or bare_process_live_agent_
     *    session_id()'s statusline marker): kills the pid and resumes that
     *    exact session in one call - a genuine single click, nothing more
     *    needed from the caller.
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
        $resumeArg = null;

        foreach (ProcessInspector::find_claude_processes() as $proc) {
            if ($proc['pid'] === $pid) {
                $workdir = $proc['cwd'];
                $startedAt = $proc['started_at'];
                $resumeArg = $proc['resume_arg'] ?? null;
                break;
            }
        }

        if ($workdir === null) {
            return ['ok' => false, 'message' => 'Rejected: not a currently running claude process, or its working directory could not be determined'];
        }

        $matchedId = self::agent_session_id_from_resume_arg($resumeArg) ?? self::bare_process_live_agent_session_id($pid);

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
