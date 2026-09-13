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
     * A plain is_dir("/proc/{$pid}") isn't enough - found live 2026-09-12
     * writing take_over_bare_process()'s own daemon-worker test: a process
     * that's just been sent SIGTERM becomes a ZOMBIE (state "Z") the
     * instant it actually exits, not gone from /proc entirely - that only
     * happens once its parent reaps it via wait()/proc_close(), which can
     * take a moment (or, in a test spawning it via proc_open() itself,
     * however long until that call happens). A zombie can never come back,
     * write to anything, or compete for a transcript file, so treating it
     * as "still alive" was exactly backwards - it made resume_agent_
     * session() reject resuming a session as "already live" against the
     * very process take_over_bare_process() had itself just killed
     * moments earlier.
     */
    private static function pid_is_alive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $stat = @file_get_contents("/proc/{$pid}/stat");

        if ($stat === false) {
            return false;
        }

        // Fields are space-separated after the comm field (2nd field,
        // itself parenthesized and possibly containing spaces or ")" -
        // e.g. a script's own name) - split on the LAST ")" rather than
        // assuming a fixed field count/format for the fields before it.
        $afterComm = strrpos($stat, ')');

        if ($afterComm === false) {
            return true;
        }

        $state = trim(substr($stat, $afterComm + 1))[0] ?? '';

        return $state !== 'Z';
    }

    /**
     * The exact value passed to --resume/--session-id for a KNOWN pid,
     * read directly from /proc rather than going through
     * ProcessInspector::find_claude_processes()'s own full host scan -
     * used by daemon_roster_pid_map() to check one specific worker pid/
     * replPid at a time, not every claude process on the box.
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
     * Every pid the daemon roster currently knows about, mapped to its own
     * best-resolved agent_session_id - built in ONE pass over the roster
     * file (rather than re-reading/re-scanning it once per pid asked
     * about), since a dashboard poll may need to resolve a dozen-plus bare
     * rows at once (enrich_bare_with_confirmed_ids()) as well as one
     * specific pid at a time (resolve_bare_process_detail(),
     * take_over_bare_process()).
     *
     * Each worker contributes an entry for EACH of its own pid/replPid
     * (not just one merged entry for the worker) - a bg-pty-host wrapper
     * and its bg-spare child are two separate real processes, and either
     * one's pid might be what a caller is actually asking about.
     *
     * Only ever populated for a pid that's still actually alive right now
     * (self::pid_is_alive()) - never trusted indefinitely just because
     * roster.json still mentions it, so a daemon crash that leaves a stale
     * roster behind (never confirmed to self-prune promptly) can't
     * permanently hide a truly-dead session from "archived" or block it
     * from ever being resumed again.
     *
     * Per pid, prefers that SAME live process's own --resume/--session-id
     * (if its argv happens to carry one) over the roster's own `sessionId`
     * field for the WORKER as a whole - found live 2026-09-12 that these
     * can genuinely disagree: one worker's roster `sessionId` pointed at a
     * transcript untouched in days, while that exact same live process had
     * since been asked to serve a completely different, actually-current
     * transcript via a fresh --resume in its own argv, and the roster's
     * own bookkeeping had simply not caught up.
     *
     * @return array<int, string>
     */
    private static function daemon_roster_pid_map(): array
    {
        $map = [];

        foreach (self::daemon_roster_workers() as $worker) {
            $workerSessionId = is_string($worker['sessionId'] ?? null) ? $worker['sessionId'] : null;

            foreach (array_filter([$worker['pid'] ?? null, $worker['replPid'] ?? null], 'is_int') as $workerPid) {
                if (!self::pid_is_alive($workerPid)) {
                    continue;
                }

                $resolved = self::agent_session_id_from_resume_arg(self::resume_arg_from_pid($workerPid)) ?? $workerSessionId;

                if ($resolved !== null) {
                    $map[$workerPid] = $resolved;
                }
            }
        }

        return $map;
    }

    /**
     * Resolves ONE known pid against the daemon roster - the pid a bare-
     * process row is actually asking about (resolve_bare_process_detail())
     * or take-over is targeting. See daemon_roster_pid_map()'s own
     * docblock for the resolution rules.
     */
    private static function resolve_via_daemon_roster(int $pid): ?string
    {
        return self::daemon_roster_pid_map()[$pid] ?? null;
    }

    /**
     * A daemon-managed worker is really TWO real, separate processes - a
     * bg-pty-host wrapper and its bg-spare/REPL child (see
     * declutter_bare_list()'s own docblock) - sharing one conversation.
     * Taking that conversation over means ending BOTH, not just whichever
     * one pid a click happened to target, or the other would be left
     * running as an orphan with nothing left pointing at it. Returns the
     * other still-alive pid(s) from the SAME roster worker entry as $pid
     * (its `pid` and `replPid` are checked against each other), or [] for
     * anything not in the roster at all (a plain bare process has no such
     * pairing to worry about).
     *
     * @return int[]
     */
    private static function daemon_roster_sibling_pids(int $pid): array
    {
        foreach (self::daemon_roster_workers() as $worker) {
            $workerPid = $worker['pid'] ?? null;
            $replPid = $worker['replPid'] ?? null;

            if ($workerPid === $pid && is_int($replPid) && self::pid_is_alive($replPid)) {
                return [$replPid];
            }

            if ($replPid === $pid && is_int($workerPid) && self::pid_is_alive($workerPid)) {
                return [$workerPid];
            }
        }

        return [];
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
     * Two categories of bare pid never reach the heuristic fallback at
     * all, found live 2026-09-13 (Andres: "show archived sessions" taking
     * ~3s - 83% of it was this one method): bare_process_take_over_
     * candidates() is not cheap (a full ArchivedSessionService::
     * list_archived_sessions() re-scan of every archived transcript on
     * every call - see its own docblock), and calling it once per
     * UNRESOLVED bare pid multiplied that one ~450ms scan into seconds on
     * a host with several such pids. Neither category below can EVER
     * resolve to a real conversation, so paying that cost for them was
     * pure waste, not a correctness trade-off:
     * - `is_daemon_supervisor` (the daemon's own `claude daemon run`
     *   process) is pure infrastructure, never a conversation.
     * - A cwd still under the daemon's own internal spare-pool path
     *   (`/tmp/...` - same check declutter_bare_list() already uses to
     *   tell a pty-host wrapper's own never-real cwd apart from its
     *   REPL child's) means this pid is either that pty-host wrapper
     *   half (whose sibling, if claimed, already resolves it via the
     *   roster/argv tiers above) or a genuinely idle, not-yet-claimed
     *   spare with no conversation assigned to guess about at all - no
     *   archived transcript will ever legitimately have that cwd either.
     *
     * Also checks each bare pid against the SAME roster pid map the union
     * above is built from (not just adding the union once and separately
     * re-deriving each pid's own tier from scratch) - found live
     * 2026-09-13 alongside the fix above: a pid the roster union already
     * covers (e.g. a claimed daemon worker with no --resume in its own
     * argv, no tmux pane for the marker tier to read) would otherwise
     * still fall through to the expensive heuristic for itself,
     * needlessly, since nothing here remembered it was already resolved.
     *
     * @return string[]
     */
    public static function live_bare_agent_session_ids(): array
    {
        $rosterPidMap = self::daemon_roster_pid_map();
        $ids = array_values(array_unique($rosterPidMap));

        foreach (SessionService::list_all_sessions()['bare'] as $b) {
            $pid = (int)($b['pid'] ?? 0);

            if ($pid <= 0 || !empty($b['is_daemon_supervisor']) || isset($rosterPidMap[$pid])) {
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

            if ($cwd === null || $startedAt === null || str_starts_with($cwd, '/tmp/')) {
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
     * Attaches `resolved_agent_session_id`/`resolved_title` to every bare
     * row this app can identify with CERTAINTY, with no extra clicking -
     * Andres's own ask (2026-09-12), once "Other claude processes on host"
     * started regularly showing over a dozen daemon-managed workers and he
     * wanted their titles visible by default to actually review/clean them
     * up, rather than clicking "Identify" on each one by one.
     *
     * Deliberately only the two CHEAP, CERTAIN tiers - the row's own
     * argv-derived --resume/--session-id (already sitting on $b['resume_arg']
     * from ProcessInspector::find_claude_processes(), no extra work at all)
     * and one single-pass daemon_roster_pid_map() lookup shared across
     * every row in the batch. NEVER the statusline-marker tier (a tmux
     * capture-pane call per candidate pane) or bare_process_take_over_
     * candidates()'s heuristic GUESS (a transcript-directory scan per
     * candidate) - both real per-row costs that are fine to pay for once,
     * on an explicit "Identify" click (see resolve_bare_process_detail()),
     * but not on every regular dashboard poll for every bare row at once.
     * A row this doesn't resolve still gets its guess (labeled as a guess)
     * the same way it always has, just still only on request.
     *
     * @param array<int, array<string, mixed>> $bare
     * @return array<int, array<string, mixed>>
     */
    public static function enrich_bare_with_confirmed_ids(array $bare): array
    {
        $rosterPidMap = self::daemon_roster_pid_map();

        return array_map(static function (array $b) use ($rosterPidMap): array {
            $pid = (int)($b['pid'] ?? 0);

            if ($pid <= 0) {
                return $b;
            }

            $agentSessionId = self::agent_session_id_from_resume_arg(is_string($b['resume_arg'] ?? null) ? $b['resume_arg'] : null)
                ?? $rosterPidMap[$pid] ?? null;

            if ($agentSessionId === null) {
                return $b;
            }

            $path = TranscriptRouter::find_transcript_path($agentSessionId);
            $cwd = is_string($b['cwd'] ?? null) ? $b['cwd'] : null;
            $title = $path !== null ? SessionService::title_cascade(TranscriptService::find_latest_ai_title($path), null, $cwd, $agentSessionId) : null;

            return $b + ['resolved_agent_session_id' => $agentSessionId, 'resolved_title' => $title];
        }, $bare);
    }

    /**
     * Filters the daemon's own noise out of an already-enriched bare[]
     * batch (see enrich_bare_with_confirmed_ids() - MUST run first, this
     * reads the `resolved_agent_session_id`/`is_daemon_supervisor` fields
     * it/ProcessInspector attach) - Andres's own ask (2026-09-12), once
     * "Other claude processes on host" started regularly showing over a
     * dozen rows that were really just the daemon's own internals, not
     * distinct conversations to review:
     *
     * 1. Drops the daemon's own supervisor process(es) outright ("claude
     *    daemon run ...") - pure infrastructure, never a conversation,
     *    never resolvable to one either.
     * 2. Collapses a claimed worker's TWO real processes (a bg-pty-host
     *    wrapper, whose own cwd stays stuck at the internal spare-pool
     *    path, plus its bg-spare/REPL child, whose cwd correctly reflects
     *    the real project folder) - which resolve to the exact SAME
     *    agent_session_id, since daemon_roster_pid_map() checks both a
     *    worker's `pid` and `replPid` - down to the single row whose own
     *    cwd looks like a real project path (not under /tmp), tagging it
     *    with how many other real pids also back that same session
     *    (`hidden_worker_count`) rather than just silently dropping them -
     *    nothing disappears without a trace, it just isn't its own
     *    separate, confusing row anymore.
     *
     * Deliberately display-only: does not change what Kill/Take-over
     * target (still exactly the one pid on the row they're clicked from),
     * and never touches an unresolved row (an idle, not-yet-claimed spare,
     * or any bare process with no confirmed id at all) - those pass
     * through completely unchanged, one row each, same as before this
     * method existed.
     *
     * @param array<int, array<string, mixed>> $bare
     * @return array<int, array<string, mixed>>
     */
    public static function declutter_bare_list(array $bare): array
    {
        $withoutSupervisors = array_values(array_filter(
            $bare,
            static fn(array $b): bool => empty($b['is_daemon_supervisor']),
        ));

        $byResolvedId = [];
        $result = [];

        foreach ($withoutSupervisors as $b) {
            $resolvedId = $b['resolved_agent_session_id'] ?? null;

            if (!is_string($resolvedId) || $resolvedId === '') {
                $result[] = $b;

                continue;
            }

            if (!isset($byResolvedId[$resolvedId])) {
                $byResolvedId[$resolvedId] = count($result);
                $result[] = $b;

                continue;
            }

            $existingIndex = $byResolvedId[$resolvedId];
            $existingCwd = is_string($result[$existingIndex]['cwd'] ?? null) ? $result[$existingIndex]['cwd'] : '';
            $thisCwd = is_string($b['cwd'] ?? null) ? $b['cwd'] : '';

            // Prefer whichever of the two looks like a real project
            // directory (not the daemon's own internal /tmp pool path) as
            // the one kept and shown - if somehow neither or both qualify,
            // the first one seen stays, arbitrarily but deterministically.
            if (str_starts_with($existingCwd, '/tmp/') && !str_starts_with($thisCwd, '/tmp/')) {
                $b['hidden_worker_count'] = ($result[$existingIndex]['hidden_worker_count'] ?? 0) + 1;
                $result[$existingIndex] = $b;
            } else {
                $result[$existingIndex]['hidden_worker_count'] = ($result[$existingIndex]['hidden_worker_count'] ?? 0) + 1;
            }
        }

        return $result;
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
     * worker at all, see daemon_roster_pid_map()'s own docblock), then
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
     *    agent_session_id_from_resume_arg(); the daemon roster, see
     *    resolve_via_daemon_roster() - added 2026-09-12, Andres explicitly
     *    accepted the plain-kill-then-resume approach for this shape once
     *    it could be resolved with certainty; or bare_process_live_agent_
     *    session_id()'s statusline marker): kills the pid (AND, for a
     *    daemon-managed worker, its sibling pid too - see
     *    daemon_roster_sibling_pids()'s own docblock for why a worker is
     *    really two real processes sharing one conversation, and both
     *    need to end together or the other is left running as an orphan)
     *    and resumes that exact session in one call - a genuine single
     *    click, nothing more needed from the caller.
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

        $matchedId = self::agent_session_id_from_resume_arg($resumeArg) ?? self::resolve_via_daemon_roster($pid) ?? self::bare_process_live_agent_session_id($pid);

        if ($matchedId !== null) {
            $siblingPids = self::daemon_roster_sibling_pids($pid);

            // A daemon-managed worker's bg-pty-host wrapper half never
            // chdirs out of the internal spare-pool location (see
            // declutter_bare_list()'s own docblock) - if THIS pid is that
            // half, resume into its sibling's real cwd instead of the
            // pool path this one's own $workdir would otherwise carry.
            if (str_starts_with($workdir, '/tmp/') && $siblingPids !== []) {
                foreach (ProcessInspector::find_claude_processes() as $proc) {
                    if ($proc['pid'] === $siblingPids[0] && $proc['cwd'] !== null && !str_starts_with($proc['cwd'], '/tmp/')) {
                        $workdir = $proc['cwd'];
                        break;
                    }
                }
            }

            $killResult = self::kill_bare_process($pid);

            if (!($killResult['ok'] ?? false)) {
                return $killResult;
            }

            foreach ($siblingPids as $siblingPid) {
                self::kill_bare_process($siblingPid);
            }

            // A tmux-hosted bare process's kill_bare_process() call is
            // synchronous (it tears down the whole tmux session, which
            // tmux itself doesn't report done until the pane's process is
            // actually gone) - but a daemon-managed worker runs on a raw
            // pty, no tmux pane at all, so this instead sends a plain
            // SIGTERM (self::kill_bare_process() -> ProcessRunner::
            // run_process(['kill', '-TERM', ...])), which only delivers
            // the signal and returns immediately, before the kernel has
            // necessarily reaped the target. Found live 2026-09-12
            // writing this exact test: resume_agent_session() right below
            // was rejecting its OWN just-killed pid as "already live",
            // since live_bare_agent_session_ids()'s daemon-roster check
            // still saw it in /proc for a brief window after kill_bare_
            // process() had already returned ok=true.
            usleep(300000);

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
     * way. Also best-effort kills a daemon-managed pid's sibling process
     * (see daemon_roster_sibling_pids()'s own docblock) so a take-over
     * reached via this picker path can't leave one half of a worker pair
     * orphaned any more than the direct one-click path can.
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

                foreach (self::daemon_roster_sibling_pids($pid) as $siblingPid) {
                    self::kill_bare_process($siblingPid);
                }

                // Same settle-delay as take_over_bare_process()'s own -
                // see its docblock for why a bare (non-tmux) SIGTERM needs
                // this but a tmux-hosted kill doesn't.
                usleep(300000);

                break;
            }
        }

        return SessionLifecycleService::resume_agent_session($workdir, $agentSessionId);
    }
}
