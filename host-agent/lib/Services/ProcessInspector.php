<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Reads /proc directly to discover and correlate real host processes -
 * find every `claude` process regardless of who started it, map
 * pid->ppid for ancestry checks, and resolve a process's actual start
 * time. Nothing here touches tmux (see TmuxService) - this is pure
 * process-table inspection.
 */
class ProcessInspector
{
    public const CLK_TCK = 100; // USER_HZ has been 100 on Linux/x86_64 since the 2.6 era

    /**
     * @return array{pid:int,ppid:int}[] keyed by pid
     */
    public static function build_ppid_map(): array
    {
        $map = [];

        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $procDir) {
            $pid = (int)basename($procDir);
            $stat = @file_get_contents("$procDir/stat");

            if ($stat === false) {
                continue;
            }

            $rparen = strrpos($stat, ')');

            if ($rparen === false) {
                continue;
            }

            $fields = preg_split('/\s+/', trim(substr($stat, $rparen + 1))) ?: [];

            // $stat fields are 1-indexed in `man proc`; after splitting off
            // "pid (comm) ", $fields[0] is field 3 (state), $fields[1] is
            // field 4 (ppid), $fields[19] is field 22 (starttime).
            if (isset($fields[1])) {
                $map[$pid] = (int)$fields[1];
            }
        }

        return $map;
    }

    public static function process_start_time(int $pid): ?int
    {
        $stat = @file_get_contents("/proc/$pid/stat");

        if ($stat === false) {
            return null;
        }

        $rparen = strrpos($stat, ')');

        if ($rparen === false) {
            return null;
        }

        $fields = preg_split('/\s+/', trim(substr($stat, $rparen + 1))) ?: [];

        if (!isset($fields[19])) {
            return null;
        }

        $startTicks = (int)$fields[19];
        $uptimeRaw = @file_get_contents('/proc/uptime');

        if ($uptimeRaw === false) {
            return null;
        }

        $uptime = (float)explode(' ', trim($uptimeRaw))[0];
        $bootEpoch = time() - (int)$uptime;

        return $bootEpoch + intdiv($startTicks, self::CLK_TCK);
    }

    public static function is_descendant(int $pid, int $ancestorPid, array $ppidMap, int $maxDepth = 25): bool
    {
        $current = $pid;

        for ($i = 0; $i < $maxDepth; $i++) {
            if ($current === $ancestorPid) {
                return true;
            }

            if (!isset($ppidMap[$current]) || $ppidMap[$current] === 0) {
                return false;
            }

            $current = $ppidMap[$current];
        }

        return false;
    }

    /**
     * Scans /proc for every real `claude` process on the host, regardless of
     * whether it was started by this tool, another tmux session, or by hand
     * in a plain terminal. argv[0] is matched rather than /proc/pid/exe,
     * because claude re-execs into a versioned binary under
     * ~/.local/share/claude/versions/*, so exe changes on every update while
     * the launcher path in argv stays stable.
     *
     * @return array{pid:int, cwd:?string, started_at:?int, resume_arg:?string, is_daemon_supervisor:bool}[]
     */
    public static function find_claude_processes(): array
    {
        $procs = [];
        // basename, not the full configured path - found live 2026-08-08:
        // typing bare `claude` in a terminal (PATH-resolved by the shell)
        // gives that process argv[0] "claude" verbatim, which never equals
        // Config::claude_bin()'s full path (e.g. ~/.local/bin/
        // claude) even though it's the exact same launcher - a real running
        // session was invisible to this scan (not in bare[], not excluded
        // from the archived list) purely because of how it happened to be
        // typed.
        $claudeBinBasename = basename(Config::claude_bin());

        // ALSO matched by realpath, not just basename - found live
        // 2026-09-11 (a real `claude --resume <path>` process launched by
        // claude-code-ui, a different tool entirely, was invisible to this
        // whole scan): Config::claude_bin() (e.g. ~/.local/bin/claude) is
        // itself a symlink to a versioned binary
        // (~/.local/share/claude/versions/<version>) that changes on every
        // update - a caller that resolves that symlink itself before
        // exec'ing (rather than exec'ing the stable launcher path) ends up
        // with argv[0] set to the versioned path directly, whose basename
        // ("2.1.269") never matches "claude" at all. Resolving both sides
        // to the same real target catches this regardless of which literal
        // path string was used to invoke it.
        $claudeBinRealpath = realpath(Config::claude_bin()) ?: null;

        foreach (glob('/proc/[0-9]*', GLOB_ONLYDIR) ?: [] as $procDir) {
            $pid = (int)basename($procDir);
            $cmdlineRaw = @file_get_contents("$procDir/cmdline");

            if ($cmdlineRaw === false || $cmdlineRaw === '') {
                continue;
            }

            $argv = explode("\0", rtrim($cmdlineRaw, "\0"));
            $argv0 = $argv[0];

            // Match argv[0] specifically, not "appears anywhere in argv": the
            // tmux server process that auto-starts to run `new-session ...
            // ~/.local/bin/claude` retains that whole command line
            // as its own argv, which would otherwise false-positive-match the
            // tmux server itself as a bare claude process. Comparing by
            // basename (or matching realpath, see above) only widens this to
            // also match "claude" (bare, PATH-resolved) or a versioned
            // binary path - the tmux server's own argv[0] is "tmux", which
            // never collides with either check, so this doesn't reopen that
            // false-positive risk.
            //
            // A THIRD shape, found live 2026-09-12: the CLI's own background
            // daemon (`claude daemon run`) rewrites its worker processes'
            // argv[0] to a single, space-containing descriptive string for
            // `ps` readability - "claude bg-spare" or "claude bg-pty-host" as
            // ONE argv element, not "claude" followed by a separate "bg-spare"
            // element. basename() of that whole string never equals "claude"
            // exactly (there's no "/" in it to split on), so it needs its own
            // explicit prefix check - anchored on a trailing space so this
            // can never partially match some unrelated "claudeXYZ" binary.
            $argv0Basename = basename($argv0);
            $matchesBasename = $argv0Basename === $claudeBinBasename;
            $matchesRealpath = $claudeBinRealpath !== null && @realpath($argv0) === $claudeBinRealpath;
            $matchesDaemonWorkerLabel = str_starts_with($argv0Basename, $claudeBinBasename . ' ');

            if ($argv0 === '' || (!$matchesBasename && !$matchesRealpath && !$matchesDaemonWorkerLabel)) {
                continue;
            }

            // The exact value passed to --resume/--session-id, when present -
            // a UUID directly, or (some third-party launchers, e.g.
            // claude-code-ui, confirmed live 2026-09-11) a full path to the
            // transcript .jsonl file itself. Found live the same day: a bare
            // process started this way has a REAL, deterministic
            // agent_session_id sitting right there in its own argv - no
            // marker or timing-heuristic guess needed at all, and this is
            // the one signal that actually identifies a `--resume`d
            // conversation correctly (see BareProcessService::
            // bare_process_take_over_candidates()'s own docblock: its
            // closest-start-time heuristic is explicitly wrong for exactly
            // this shape, since a resumed conversation's first message can
            // be arbitrarily older than the resuming process's own start
            // time).
            $resumeArg = null;

            foreach ($argv as $i => $part) {
                if (($part === '--resume' || $part === '--session-id') && isset($argv[$i + 1])) {
                    $resumeArg = $argv[$i + 1];
                    break;
                }
            }

            // True for the daemon's own supervisor process ("claude daemon
            // run ...", argv[1] === "daemon") - never a conversation, never
            // resolvable to one, purely infrastructure that spawns/manages
            // the bg-spare/bg-pty-host worker pool. Distinguished from a
            // worker itself here, at the source, rather than making every
            // caller re-derive "is this just daemon noise" its own way.
            $isDaemonSupervisor = ($argv[1] ?? null) === 'daemon';

            $procs[] = [
                'pid' => $pid,
                'cwd' => @readlink("$procDir/cwd") ?: null,
                'started_at' => self::process_start_time($pid),
                'resume_arg' => $resumeArg,
                'is_daemon_supervisor' => $isDaemonSupervisor,
            ];
        }

        return $procs;
    }

    /**
     * Finds the tmux pane (if any, from an already-fetched
     * TmuxService::all_tmux_panes() map) that $pid runs under, by walking
     * its ancestry same as the cc-* matching in list_all_sessions() does.
     *
     * @param array<int, array{session:string, title:?string}> $allPanes
     * @return array{session:string, title:?string}|null
     */
    public static function find_owning_pane(int $pid, array $allPanes, array $ppidMap): ?array
    {
        foreach ($allPanes as $panePid => $pane) {
            if (self::is_descendant($pid, $panePid, $ppidMap)) {
                return $pane;
            }
        }

        return null;
    }
}
