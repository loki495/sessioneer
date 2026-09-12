<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Agents\AgentRegistry;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\PendingToolStore;
use HostAgent\Stores\SessionStatusStore;

/**
 * Create/resume/kill/cleanup of managed cc-* tmux sessions. Split out of
 * SessionService.php (2026-08-24 readability audit - see the plan this
 * followed) - fully self-contained, no dependency on core SessionService or
 * any other new class. Methods/bodies moved verbatim, no behavior changes.
 */
class SessionLifecycleService
{
    /**
     * A random (v4) UUID, RFC 4122 §4.4 - used as the --session-id passed to
     * `claude` at launch, so this app controls the id up front instead of
     * having to discover whatever Claude Code would have picked on its own.
     * That's what makes TranscriptService::find_transcript_path() a plain glob instead of having
     * to reproduce Claude Code's own cwd -> directory-name encoding.
     */
    public static function generate_uuid_v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * $agentId picks which AgentAdapter governs the new session (see
     * docs/antigravity-adapter-plan.md Phase 2) - whitelisted against
     * AgentRegistry::known_agent_ids() rather than trusted from the
     * caller, same discipline $startingMode already uses below; null,
     * empty, or unrecognized all fall back to AgentRegistry::
     * default_agent_id() ('claude'), so every existing caller from before
     * this parameter existed keeps working unchanged.
     *
     * $enableTaskTools/$startingMode are passed straight through to the
     * resolved adapter's build_spawn_argv() (see ClaudeCodeAdapter's own
     * docblock for --allowedTools/--permission-mode's exact reasoning -
     * this method used to build that argv inline until the AgentAdapter
     * extraction, 2026-08-24, see docs/antigravity-adapter-plan.md Phase 1;
     * behavior is unchanged for Claude Code, only where the logic lives
     * moved). $startingMode is this app's own manual/accept edits/plan/auto
     * vocabulary (TranscriptView::MODE_OPTIONS) - each adapter whitelists
     * it against its own real mode values internally (see
     * ClaudeCodeAdapter::build_spawn_argv()/AntigravityAdapter::
     * build_spawn_argv()), an unsupported value for the chosen agent is
     * silently ignored rather than passed through raw. null (the default)
     * omits the flag entirely.
     *
     * $model is this app's own SelectableModel::PICKER_OPTIONS key
     * (sonnet/fable/opus/haiku, or null/'default' for no --model flag at
     * all) - passed straight through to the adapter the same way
     * $startingMode is; an adapter that doesn't understand 'model' (e.g.
     * AntigravityAdapter) just ignores the key, per AgentAdapter's own
     * "each adapter reads only what it understands" contract.
     *
     * @return array{ok:bool, message:string}
     */
    public static function create_agent_session(string $workdir, bool $enableTaskTools = false, ?string $startingMode = null, ?string $agentId = null, ?string $model = null): array
    {
        if ($workdir === '' || $workdir[0] !== '/') {
            return ['ok' => false, 'message' => 'Working directory must be an absolute path'];
        }

        // Found live 2026-08-23 (writing a test that creates two sessions in
        // quick succession): date('Ymd-Hi') has only MINUTE resolution, so
        // two create_agent_session() calls within the same clock-minute
        // collide on an identical name and the second `tmux new-session`
        // fails outright ("duplicate session"). resume_agent_session() already
        // uses second-level Ymd-His for the same reason - matched here too.
        $resolvedAgentId = $agentId !== null && in_array($agentId, AgentRegistry::known_agent_ids(), true) ? $agentId : AgentRegistry::default_agent_id();
        $agent = AgentRegistry::get($resolvedAgentId);
        $name = $agent->session_name_prefix() . '-' . date('Ymd-His');
        $spawn = $agent->build_spawn_argv(['enable_task_tools' => $enableTaskTools, 'starting_mode' => $startingMode, 'model' => $model]);
        $agentArgv = $spawn['argv'];
        $agentSessionId = $spawn['assigned_id'];

        $result = TmuxService::tmux_run(array_merge([
            // SESSIONEER_SESSION_NAME is how the SessionStart hook (see
            // host-agent/hooks/session_start.php) tells this pane's claude
            // process apart from any other on the box, so it knows which
            // sidecar to rebind when Claude Code rotates to a new session-id
            // transcript (/clear, /compact, --resume, --fork-session) without
            // this tmux pane itself ever restarting.
            'new-session', '-d', '-s', $name,
            '-c', $workdir,
            '-e', "SESSIONEER_SESSION_NAME={$name}",
            '-x', (string)Config::new_session_pane_width(),
            '-y', (string)Config::new_session_pane_height(),
        ], $agentArgv));

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'message' => 'Failed to create session: ' . trim($result['stderr'])];
        }

        // tmux new-session returns success as soon as the session is
        // registered, before checking whether the pane's command actually
        // stayed running (e.g. bad cwd). Confirm it actually persisted. Must
        // use list_all_tmux_sessions() here, not list_tracked_tmux_sessions()
        // - the sidecar isn't written until a few lines below, so a
        // sidecar-gated check would always report "not there yet".
        usleep(300000);

        $stillThere = in_array($name, array_column(TmuxService::list_all_tmux_sessions(), 'name'), true);

        if (!$stillThere) {
            return [
                'ok' => false,
                'message' => "Session {$name} did not stay running - check the working directory is valid and the {$agent->label()} binary starts correctly",
            ];
        }

        // spawned_by_app is set here too, not left for the SessionStart hook
        // to backfill - the hook only rebinds/confirms it on its own first
        // fire moments later, and a dashboard poll landing in that gap would
        // otherwise see this brand-new, definitely-app-spawned session
        // reported as spawned_by_app=false.
        SidecarStore::write_sidecar($name, ['workdir' => $workdir, 'spawned_at' => time(), 'agent_session_id' => $agentSessionId, 'spawned_by_app' => true, 'agent' => $agent->id()]);

        return ['ok' => true, 'message' => "Created session {$name} in {$workdir}"];
    }

    /**
     * True if $agentSessionId is already the id bound to some currently
     * live/tracked pane OTHER than $excludeSessionName - guards
     * resume_agent_session() against two panes fighting over the same
     * transcript file, and session_start.php's hook against rebinding a
     * pane's sidecar onto a DIFFERENT pane's real live session (found live
     * 2026-08-23: a nested `claude` child process inheriting the parent
     * pane's SESSIONEER_SESSION_NAME env var reported another pane's own real,
     * transcript-backed session id, which passed the hook's existing
     * transcript-exists check and clobbered the parent's sidecar onto it -
     * the transcript-exists check alone only rules out phantom ids, not
     * ids that are real but belong to someone else's live pane). Cheap:
     * only reads the sidecar of each already-tracked (sidecar-gated) tmux
     * session, no pane-scraping. $excludeSessionName lets a session's own
     * hook re-confirm its own already-bound id without tripping over
     * itself.
     */
    public static function agent_session_id_already_live(string $agentSessionId, string $excludeSessionName = ''): bool
    {
        foreach (TmuxService::list_tracked_tmux_sessions() as $tmuxSession) {
            if ($tmuxSession['name'] === $excludeSessionName) {
                continue;
            }

            $sidecar = SidecarStore::read_sidecar($tmuxSession['name']);

            if (is_string($sidecar['agent_session_id'] ?? null) && $sidecar['agent_session_id'] === $agentSessionId) {
                return true;
            }
        }

        return false;
    }

    /**
     * A per-agent_session_id lock file path for resume_agent_session()'s own
     * flock() below - sha1() rather than the id itself so this never has
     * to assume/validate a UUID shape (resume_agent_session() doesn't
     * currently enforce one), and can't be abused as a path-traversal
     * vector either way. Lives in the same tmpfs sidecar dir as every
     * other session-scoped ephemeral file this app writes.
     *
     * Deliberately never unlink()'d after use (tiny/empty, tmpfs-backed,
     * so the cost of leaving it is negligible) - unlinking a still-usable
     * flock() lock file is a real, well-known footgun: a second process
     * already blocked in fopen()/flock() on the same PATH can still end
     * up locking the OLD (now-unlinked) inode after a first process
     * deletes and a third recreates it, splitting what should be one
     * lock into two independent ones that never actually conflict. Always
     * flock()ing the same persistent path avoids that entirely.
     */
    private static function resume_lock_path(string $agentSessionId): string
    {
        return Config::sidecar_dir() . '/' . sha1($agentSessionId) . '.resume-lock';
    }

    /**
     * Resumes a known, dormant `agent_session_id` (an archived-list row,
     * per the unify-claude-sessions plan's phase 5) in a fresh, app-managed
     * tmux pane - the exact same spawn shape as create_agent_session(), just
     * `--resume <id>` instead of `--session-id <new-uuid>`. Verified live
     * 2026-08-08: unlike the no-id form (which always drops into an
     * interactive picker, even with a single candidate - see the plan
     * file's findings), `--resume <explicit-id>` goes straight to the
     * resumed conversation, no picker, so this needs no extra
     * picker-handling step the way a future bare-process Take-over will.
     *
     * Found live 2026-08-22 (codebase audit): agent_session_id_already_live()
     * only checked the sidecar store BEFORE spawning, but no sidecar exists
     * for an in-flight resume until AFTER the tmux pane is up and the
     * 300ms settle sleep below elapses - a real TOCTOU window. Two
     * near-simultaneous resume requests for the SAME agent_session_id
     * (two tabs, a flaky double-tap) could both pass the check and spawn
     * two `claude --resume <id>` processes fighting over one transcript
     * file - silent corruption, not a crash. An flock() held across the
     * whole check-spawn-write sequence closes the window: a second
     * request for the same id fails to acquire the lock immediately and
     * gets the same rejection message a fraction of a second later,
     * instead of racing past the check. Scoped to one lock file PER
     * agent_session_id (not a single global lock) so unrelated resumes
     * never contend with each other. flock()'s lock is released by the
     * OS the instant this process exits for ANY reason (normal return,
     * crash, kill -9) - host-agent is a fresh, single-request process per
     * connection anyway (see agent.php), so there's no separate "release"
     * step needed beyond letting the function return.
     *
     * @return array{ok:bool, message:string, name?:string}
     */
    public static function resume_agent_session(string $workdir, string $agentSessionId): array
    {
        if ($workdir === '' || $workdir[0] !== '/') {
            return ['ok' => false, 'message' => 'Working directory must be an absolute path'];
        }

        if ($agentSessionId === '') {
            return ['ok' => false, 'message' => 'Missing agent_session_id'];
        }

        if (!is_dir(Config::sidecar_dir())) {
            @mkdir(Config::sidecar_dir(), 0700, true);
        }

        $lockHandle = @fopen(self::resume_lock_path($agentSessionId), 'c');

        if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
            return ['ok' => false, 'message' => 'This session already has a live pane - refusing to open a second one on the same transcript'];
        }

        try {
            if (self::agent_session_id_already_live($agentSessionId)) {
                return ['ok' => false, 'message' => 'This session already has a live pane - refusing to open a second one on the same transcript'];
            }

            // agent_session_id_already_live() above only checks TRACKED
            // (tmux+sidecar) sessions - a bare process (e.g. a plain
            // `claude` typed by hand in a real terminal, no tmux at all)
            // has no sidecar to find there, so without this a resume could
            // still spawn a second process fighting over the same
            // transcript a bare one is actively writing to. Kept as its
            // own separate check rather than folded into
            // agent_session_id_already_live() itself - that method is also
            // on session_start.php's hot hook path (fires on every
            // /clear, /compact, --resume), which has no bare-process
            // angle to guard against and shouldn't pay for scanning them
            // on every hook fire. See BareProcessService::live_bare_
            // agent_session_ids()'s own docblock for the live incident.
            if (in_array($agentSessionId, BareProcessService::live_bare_agent_session_ids(), true)) {
                return ['ok' => false, 'message' => 'This session already has a live pane - refusing to open a second one on the same transcript'];
            }

            $isOpencodeResume = OpenCodeTranscriptService::is_opencode_id($agentSessionId);
            $resumeAgentId = $isOpencodeResume ? 'opencode' : 'claude';
            $resumeAgent = AgentRegistry::get($resumeAgentId);
            $name = $resumeAgent->session_name_prefix() . '-' . date('Ymd-His');

            $resumeArgv = $isOpencodeResume
                ? [Config::opencode_bin(), '--session', $agentSessionId]
                : [Config::claude_bin(), '--resume', $agentSessionId];

            $result = TmuxService::tmux_run(array_merge([
                'new-session', '-d', '-s', $name,
                '-c', $workdir,
                '-e', "SESSIONEER_SESSION_NAME={$name}",
                '-x', (string)Config::new_session_pane_width(),
                '-y', (string)Config::new_session_pane_height(),
            ], $resumeArgv));

            if ($result['exit'] !== 0) {
                return ['ok' => false, 'message' => 'Failed to resume session: ' . trim($result['stderr'])];
            }

            usleep(300000);

            $stillThere = in_array($name, array_column(TmuxService::list_all_tmux_sessions(), 'name'), true);

            if (!$stillThere) {
                return [
                    'ok' => false,
                    'message' => "Session {$name} did not stay running - check the working directory still exists and the {$resumeAgent->label()} binary starts correctly",
                ];
            }

            SidecarStore::write_sidecar($name, ['workdir' => $workdir, 'spawned_at' => time(), 'agent_session_id' => $agentSessionId, 'spawned_by_app' => true, 'agent' => $resumeAgentId]);

            return ['ok' => true, 'message' => "Resumed session {$name} in {$workdir}", 'name' => $name];
        } finally {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }
    }

    /**
     * $requested must exactly match a name from a freshly-fetched
     * TmuxService::list_tracked_tmux_sessions() call made inside this same
     * request.
     *
     * @return array{ok:bool, message:string}
     */
    public static function kill_agent_session(string $requested): array
    {
        $whitelist = array_column(TmuxService::list_tracked_tmux_sessions(), 'name');

        if (!in_array($requested, $whitelist, true)) {
            return ['ok' => false, 'message' => 'Rejected: not a currently active managed session'];
        }

        $result = TmuxService::tmux_run(['kill-session', '-t', $requested]);

        if ($result['exit'] !== 0) {
            return ['ok' => false, 'message' => "Failed to kill {$requested}: " . trim($result['stderr'])];
        }

        SidecarStore::delete_sidecar($requested);
        PendingToolStore::delete_pending_tool($requested);
        SessionStatusStore::delete_status($requested);

        return ['ok' => true, 'message' => "Killed {$requested}"];
    }

    /**
     * @return array{ok:bool, killed:string[], failed:string[]}
     */
    public static function cleanup_inactive_sessions(): array
    {
        $now = time();
        $killed = [];
        $failed = [];

        foreach (TmuxService::list_tracked_tmux_sessions() as $session) {
            if (($now - $session['activity']) <= Config::cleanup_threshold_seconds()) {
                continue;
            }

            $result = TmuxService::tmux_run(['kill-session', '-t', $session['name']]);

            if ($result['exit'] === 0) {
                SidecarStore::delete_sidecar($session['name']);
                $killed[] = $session['name'];
            } else {
                $failed[] = $session['name'];
            }
        }

        return ['ok' => empty($failed), 'killed' => $killed, 'failed' => $failed];
    }
}
