#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Claude Code's SessionStart hook (see HookService::install_session_hook()
 * in ../lib/Sessions.php, and the README) - fires on every session start,
 * including /clear, /compact, --resume, and --fork-session, each of which
 * rotates Claude Code's own transcript to a brand new session-id file
 * while staying in the same tmux pane/process. Without this, a session's
 * sidecar (which records agent_session_id exactly once, at spawn) goes
 * stale the moment any of those happen, and the app silently keeps
 * reading an abandoned, no-longer-growing transcript forever after.
 *
 * Two ways a session gets tracked here, in priority order:
 *
 * 1. SESSIONEER_SESSION_NAME (set via `tmux new-session -e` in create_agent_session())
 *    - this app's own spawned pane. Only ever REBINDS an existing sidecar,
 *    never creates one - create_agent_session() already wrote it before this
 *    hook could ever fire, so a missing one here means the session was
 *    genuinely killed/cleaned up already (see the bail-out below), not a
 *    session worth resurrecting a sidecar for.
 *
 * 2. Any OTHER claude session running inside a real tmux pane (Andres
 *    started it by hand, in a pane this app never touched) - adopted by
 *    asking that pane's own tmux server "what session are you" (`tmux
 *    display-message -p '#S'`, resolved from the TMUX env var this hook
 *    inherits as a child of that pane's own shell, same as any other
 *    process running inside it) and keying a BRAND NEW sidecar off that
 *    real tmux session name, first-seen. This is the whole "unify tracked
 *    and bare sessions" mechanism - once a sidecar exists, build_session_
 *    entry() can find its transcript and every other feature just works
 *    the same as for a cc-* session.
 *
 * A claude process with NO tmux pane at all (bare terminal/SSH, no
 * wrapper) is left alone entirely in BOTH cases - it can never get send-
 * keys/capture-pane support regardless of a sidecar existing, and still
 * shows up in the archived-sessions listing once its transcript exists on
 * disk (a plain directory scan, no sidecar involved) - so there's nothing
 * a sidecar would actually buy it. Keeping tmux out of the picture
 * entirely for that case is deliberate, not an oversight.
 */

require __DIR__ . '/../lib/Sessions.php';

use HostAgent\Services\SessionLifecycleService;
use HostAgent\Services\TmuxService;
use HostAgent\Services\TranscriptService;
use HostAgent\Stores\SidecarStore;

$input = stream_get_contents(STDIN);
$payload = json_decode((string)$input, true);
$agentSessionId = is_array($payload) ? ($payload['session_id'] ?? null) : null;

if (!is_string($agentSessionId) || $agentSessionId === '') {
    exit(0);
}

$spawnedByApp = getenv('SESSIONEER_SESSION_NAME');
$createIfMissing = false;

if (is_string($spawnedByApp) && $spawnedByApp !== '') {
    $sessionName = $spawnedByApp;
} else {
    if (getenv('TMUX') === false) {
        exit(0); // no tmux pane at all - nothing to key an adopted sidecar by
    }

    $paneSession = TmuxService::tmux_run(['display-message', '-p', '#S']);
    $sessionName = $paneSession['exit'] === 0 ? trim($paneSession['stdout']) : '';

    // Never trust this as a bare tmux session/pane identifier without
    // checking its shape first - a sidecar is one row per session name
    // (SidecarStore), so anything but a plain name is refused rather than
    // risking it being used unsanitized elsewhere.
    if ($sessionName === '' || $sessionName === '.' || $sessionName === '..' || str_contains($sessionName, '/')) {
        exit(0);
    }

    $createIfMissing = true;
}

$existingSidecar = SidecarStore::read_sidecar($sessionName);

if ($existingSidecar === null && !$createIfMissing) {
    exit(0); // session already killed/cleaned up since this hook fired - nothing to rebind
}

// SESSIONEER_SESSION_NAME is inherited by every child process of the tracked
// pane, not just the one interactive conversation running in it - a
// `claude` process run manually from inside that pane's own Bash tool
// (e.g. to test `--resume` behavior live) fires its own genuine
// SessionStart with its own, unrelated session_id, and this hook can't
// otherwise tell that apart from the pane's real session rotating.
// Found live 2026-08-08: exactly this happened, clobbering a working
// sidecar with an id that had no transcript anywhere, breaking transcript
// lookup until manually repaired. Require a real transcript file to
// actually exist for the reported id before trusting it enough to
// rebind - a phantom/nested invocation that never produces real
// transcript content won't pass. Bounded retries because on a genuine
// rotation the file may not have been created yet at the exact instant
// this hook fires (SessionStart can fire before Claude Code's own first
// write to the new transcript path - same kind of ordering surprise
// already found for the Stop hook, see tests/README/todo notes on that).
// Which Claude Code account this pane's session was originally spawned
// under (see Dibs plan #230) - read from the sidecar being rebound, NOT
// re-derived from anything in this hook's own env, since CLAUDE_CONFIG_DIR
// is only ever set at spawn time (create_agent_session()), not something
// this hook receives directly. A work-profile session's transcript lives
// under that account's own CLAUDE_CONFIG_DIR, not the default ~/.claude -
// without this, every /clear, /compact, --resume, --fork-session on a
// work-profile session would search the wrong directory and never find
// it, silently breaking tracking the moment any of those happen.
$profile = is_string($existingSidecar['profile'] ?? null) ? $existingSidecar['profile'] : null;

$transcriptConfirmed = false;
for ($attempt = 0; $attempt < 4; $attempt++) {
    if (TranscriptService::find_transcript_path($agentSessionId, $profile) !== null) {
        $transcriptConfirmed = true;
        break;
    }
    usleep(150000);
}

if (!$transcriptConfirmed) {
    exit(0);
}

// Found live 2026-08-23: a real transcript existing for $agentSessionId
// isn't enough - it may be a DIFFERENT pane's own real, currently-live
// session (e.g. a nested `claude` child process spawned from this pane's
// Bash tool, inheriting SESSIONEER_SESSION_NAME from the parent pane's env,
// itself resuming or reporting some other pane's genuine session id).
// Rebinding onto it anyway clobbers this pane's sidecar so it now points
// at someone else's transcript, and the dashboard shows both panes as
// duplicates of each other. Refuse the rebind when the id is already
// live on a DIFFERENT tracked session; excludeSessionName lets this
// pane's own already-bound id re-confirm without tripping over itself.
if (SessionLifecycleService::agent_session_id_already_live($agentSessionId, $sessionName)) {
    exit(0);
}

// cwd: prefers the hook's own payload field (Claude Code sends this on
// every hook invocation), falls back to this process's own getcwd() -
// claude itself was launched with the real project dir as its cwd, and
// this hook inherits that same cwd as its child, so either source lands
// on the same real path.
$cwd = is_array($payload) && is_string($payload['cwd'] ?? null) && $payload['cwd'] !== '' ? $payload['cwd'] : (getcwd() ?: null);

SidecarStore::write_sidecar($sessionName, [
    'workdir' => $existingSidecar['workdir'] ?? $cwd,
    'spawned_at' => $existingSidecar['spawned_at'] ?? time(),
    'agent_session_id' => $agentSessionId,
    'agent' => $existingSidecar['agent'] ?? 'claude',
    // Lets the dashboard tell "this app spawned the pane" apart from "this
    // app adopted a pane Andres started by hand" without re-deriving it
    // from the session name later (e.g. to decide whether Kill should
    // just tear down a pane this app made, versus needing more care for
    // one Andres is also using directly outside the app).
    'spawned_by_app' => $existingSidecar['spawned_by_app'] ?? (is_string($spawnedByApp) && $spawnedByApp !== ''),
    // Preserved from the existing sidecar, same as agent/workdir above -
    // this hook fires on EVERY session start including the very first one
    // right after spawn, so omitting this would silently wipe the profile
    // create_agent_session() just wrote, defeating multi-account support
    // immediately.
    'profile' => $profile,
]);
