#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Antigravity's PreInvocation hook (see
 * AntigravityHookService, host-agent/lib/Services/AntigravityHookService.php,
 * and docs/history/antigravity-adapter-plan.md Phase 3) - fires before every model
 * turn, the closest available signal to Claude Code's UserPromptSubmit
 * (Antigravity has no direct equivalent). Marks the session working, same
 * "working/idle/blocked hook-sequence" convention host-agent/hooks/
 * user_prompt_submit.php already established for Claude Code -
 * SessionService::build_session_entry() reads this the same way
 * regardless of which agent governs the session.
 *
 * ALSO does the reactive session-id binding create_agent_session() can't do
 * up front for Antigravity (no --session-id/--conversation-id equivalent
 * exists for a fresh interactive session - confirmed live, see the plan
 * doc's "CLI flags" section). The FIRST hook to fire after spawn is what
 * actually learns this session's real conversationId, so this is where
 * the sidecar's agent_session_id gets bound for the first time. Only
 * writes when the id actually needs to change (first binding, or a
 * genuine mismatch) - every OTHER turn's firing is a cheap read-and-skip,
 * not a write on every single turn.
 *
 * SESSIONEER_SESSION_NAME gate: same convention as every Claude Code hook script
 * - set via `tmux new-session -e` in create_agent_session(), agent-agnostic.
 * A plain `agy` session started by hand (no SESSIONEER_SESSION_NAME) is a
 * deliberate no-op, same as a plain claude session - but this hook is
 * registered GLOBALLY in ~/.gemini/config/hooks.json (fires for EVERY agy
 * invocation on the machine, not just Sessioneer-spawned ones, unlike Claude
 * Code's per-project settings.json scoping), so this gate matters even
 * more here.
 *
 * Writes {} to stdout (PreInvocation's own documented "optional
 * injectSteps" shape - none needed here) and always exits 0.
 */

require __DIR__ . '/../../lib/Sessions.php';

use HostAgent\Services\SessionLifecycleService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$sessionName = getenv('SESSIONEER_SESSION_NAME');

if ($sessionName === false || $sessionName === '') {
    echo '{}';
    exit(0);
}

$input = stream_get_contents(STDIN);
$payload = json_decode((string)$input, true);

if (!is_array($payload)) {
    echo '{}';
    exit(0);
}

$conversationId = is_string($payload['conversationId'] ?? null) ? $payload['conversationId'] : null;

if ($conversationId !== null) {
    $existingSidecar = SidecarStore::read_sidecar($sessionName);

    if (($existingSidecar['agent_session_id'] ?? null) !== $conversationId) {
        // Same duplicate-binding guard Claude Code's session_start.php
        // uses - refuse to bind onto an id already live on a DIFFERENT
        // tracked session (agent-agnostic - SidecarStore's agent_session_id
        // column is just "the agent's own conversation id", whichever
        // agent that is).
        if (!SessionLifecycleService::agent_session_id_already_live($conversationId, $sessionName)) {
            $workspacePaths = is_array($payload['workspacePaths'] ?? null) ? $payload['workspacePaths'] : [];
            $fallbackWorkdir = is_string($workspacePaths[0] ?? null) ? $workspacePaths[0] : null;

            SidecarStore::write_sidecar($sessionName, [
                'workdir' => $existingSidecar['workdir'] ?? $fallbackWorkdir,
                'spawned_at' => $existingSidecar['spawned_at'] ?? time(),
                'agent_session_id' => $conversationId,
                'spawned_by_app' => $existingSidecar['spawned_by_app'] ?? true,
                'agent' => $existingSidecar['agent'] ?? 'antigravity',
            ]);
        }
    }
}

SessionStatusStore::update_status($sessionName, ['status' => 'working', 'blocked' => null, 'last_turn_error' => null]);

echo '{}';
