<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SidecarStore;

/**
 * Every tmux command this app runs - process control (via ProcessRunner)
 * scoped specifically to tmux, plus the small amount of parsing (pane
 * title cleanup, delegated to PromptParser) needed to turn raw tmux
 * output into something callers can use directly.
 */
class TmuxService
{
    /**
     * tmux only auto-creates its socket's parent directory when using its own
     * default naming ($TMPDIR/tmux-$UID); since this app always passes an
     * explicit -S path, tmux instead expects that directory to already exist
     * and fails outright if it doesn't. /tmp is wiped on every host reboot,
     * and nothing else recreates this directory afterward - so without this,
     * every session-create attempt fails until someone notices and mkdirs it
     * by hand. Cheap enough (an is_dir check) to just do on every call.
     */
    public static function ensure_tmux_socket_dir(): void
    {
        $dir = dirname(Config::tmux_socket());

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }

    /**
     * @param string[] $args
     * @return array{exit:int,stdout:string,stderr:string}
     */
    public static function tmux_run(array $args): array
    {
        self::ensure_tmux_socket_dir();

        return ProcessRunner::run_process(array_merge(['tmux', '-S', Config::tmux_socket()], $args));
    }

    /**
     * Every real tmux session on the box, regardless of name or who started
     * it. Used where "does a tmux session by this name still actually
     * exist" is the question - e.g. create_agent_session()'s just-spawned-still-
     * alive check, which runs before any sidecar has been written, so
     * list_tracked_tmux_sessions() below (sidecar-gated) can't answer it yet.
     *
     * 'activity' is sourced from #{window_activity}, NOT tmux's own
     * #{session_activity}, since a live, actively-working
     * session's dashboard row otherwise looks stale/"detached" (per
     * tmux's own man page and a live A/B check): #{session_activity}
     * ("Time of session last activity") does NOT reliably update from
     * real, continuous pane output the way you'd expect - it stayed
     * frozen at this session's own spawn time for 8+ real hours of heavy
     * use, while #{window_activity} ("Time of window last activity") on
     * the SAME session updated correctly in real time, seconds after
     * being checked. Every session this app tracks is single-window (see
     * create_agent_session()), so the active window IS the session for this
     * purpose - #{window_activity} resolves to it directly even from a
     * list-sessions format string, no per-window query
     * needed.
     *
     * @return array<int, array{name:string, activity:int, attached:bool}>
     */
    public static function list_all_tmux_sessions(): array
    {
        $result = self::tmux_run(['list-sessions', '-F', '#{session_name}|#{window_activity}|#{session_attached}']);

        if ($result['exit'] !== 0) {
            return [];
        }

        $sessions = [];

        foreach (explode("\n", trim($result['stdout'])) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode('|', $line);

            if (count($parts) !== 3) {
                continue;
            }

            [$name, $activity, $attached] = $parts;

            $sessions[] = [
                'name' => $name,
                'activity' => (int)$activity,
                'attached' => $attached === '1',
            ];
        }

        return $sessions;
    }

    /**
     * The "tracked"/full-featured subset of list_all_tmux_sessions(): any
     * live tmux session with a sidecar file, regardless of name - a cc-*
     * session this app spawned itself, or one it adopted via the
     * SessionStart hook (see host-agent/hooks/session_start.php). Sidecar
     * existence, not the cc-* prefix, is what actually makes a session
     * full-featured (kill/send/answer-prompt/mode-toggle all key off having
     * a sidecar to read/write), so this - not the name - is the whitelist
     * every state-changing action re-checks against.
     *
     * @return array<int, array{name:string, activity:int, attached:bool}>
     */
    public static function list_tracked_tmux_sessions(): array
    {
        return array_values(array_filter(
            self::list_all_tmux_sessions(),
            static fn(array $session): bool => SidecarStore::read_sidecar($session['name']) !== null,
        ));
    }

    /**
     * @return array{pids:int[], title:?string} pane pids and the first pane's
     * title, belonging to the given tmux session
     */
    public static function tmux_session_panes(string $session): array
    {
        $result = self::tmux_run(['list-panes', '-t', $session, '-s', '-F', '#{pane_pid}|#{pane_title}']);

        if ($result['exit'] !== 0) {
            return ['pids' => [], 'title' => null];
        }

        $pids = [];
        $title = null;

        foreach (explode("\n", trim($result['stdout'])) as $line) {
            if ($line === '') {
                continue;
            }

            [$pid, $paneTitle] = array_pad(explode('|', $line, 2), 2, '');
            $pids[] = (int)$pid;

            if ($title === null) {
                $title = PromptParser::clean_pane_title($paneTitle);
            }
        }

        return ['pids' => $pids, 'title' => $title];
    }

    /**
     * The current, visible content of a tmux pane - used to detect an
     * interactive prompt Claude Code is waiting on (see
     * PromptParser::detect_blocking_prompt()) that a headless tmux session
     * will otherwise just sit in forever, since there's no human attached to
     * answer it.
     */
    public static function tmux_capture_pane(string $session): string
    {
        // -J rejoins any line the terminal soft-wrapped across multiple pane
        // rows back into one - without it, a long command (a common case for
        // the permission-prompt text this feeds into parse_blocking_prompt())
        // comes back split mid-word at the wrap point instead of intact.
        $result = self::tmux_run(['capture-pane', '-t', $session, '-p', '-J']);

        return $result['exit'] === 0 ? $result['stdout'] : '';
    }

    /**
     * Every pane on the host, across every tmux session regardless of name -
     * unlike tmux_session_panes(), which only ever looks inside one named
     * session. Used to enrich "bare" claude processes (ones
     * ProcessInspector::find_claude_processes() found but that aren't inside
     * a tracked session - see list_tracked_tmux_sessions()) with a session
     * name/title when they happen to live in some other, manually created
     * tmux session instead of a plain terminal.
     *
     * @return array<int, array{session:string, title:?string}> keyed by pane_pid
     */
    public static function all_tmux_panes(?bool &$ok = null): array
    {
        $result = self::tmux_run(['list-panes', '-a', '-F', '#{session_name}|#{pane_pid}|#{pane_title}']);

        // A failed query and a box with no sessions both used to come back as
        // an empty array, which callers cannot tell apart. prune_orphaned_
        // sidecars() treats "no live sessions" as authority to delete every
        // tracked row, so one transient tmux failure during a dashboard poll
        // wiped the whole tracking layer. $ok reports which case this was;
        // callers that destroy state on an empty result must check it.
        $ok = $result['exit'] === 0;

        if ($result['exit'] !== 0) {
            return [];
        }

        $panes = [];

        foreach (explode("\n", trim($result['stdout'])) as $line) {
            if ($line === '') {
                continue;
            }

            [$session, $pid, $title] = array_pad(explode('|', $line, 3), 3, '');
            $panes[(int)$pid] = ['session' => $session, 'title' => PromptParser::clean_pane_title($title)];
        }

        return $panes;
    }

    /**
     * The exact command to attach to a session from the host and answer
     * whatever it's waiting on - shown alongside the blocked_reason warning
     * so approving it is a copy-paste away instead of a guessing game. Never
     * sent automatically: blindly injecting an "approve" keystroke could just
     * as easily rubber-stamp a destructive tool call nobody's actually looked
     * at.
     */
    public static function tmux_attach_hint(string $sessionName): string
    {
        return 'tmux -S ' . Config::tmux_socket() . ' attach -t ' . $sessionName;
    }
}
