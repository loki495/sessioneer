#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Antigravity's PreToolUse hook (see AntigravityHookService
 * and docs/history/antigravity-adapter-plan.md Phase 3) - fires right before
 * every tool call, whether or not it ends up needing user approval.
 * Records the full tool_name/tool_input to PendingToolStore, same purpose
 * as Claude Code's own host-agent/hooks/pre_tool_use.php.
 *
 * ALWAYS returns {"decision":"ask"} - Antigravity's own docs mark
 * `decision` as REQUIRED, unlike Claude Code's hooks (where writing
 * nothing means "no opinion"). "ask" (not "allow") is deliberate:
 * confirmed LIVE 2026-08-24 (see docs/history/antigravity-adapter-plan.md's "Open
 * questions" finding) that "allow" does NOT actually suppress
 * Antigravity's own interactive approval UI in this version - the real
 * "Do you want to proceed?" prompt shows regardless. "ask" is the
 * decision that matches what ALREADY happens with no hook installed at
 * all (a normal prompt, respecting any cached "always allow" choice), so
 * this hook stays a true no-op today. If a future Antigravity version
 * ever makes "allow" actually bypass the prompt, "ask" is also the SAFE
 * choice - this hook is registered GLOBALLY (fires for every agy
 * invocation on the machine, not just Sessioneer-spawned ones), so "allow" would
 * silently auto-approve every tool call everywhere the moment that bug
 * got fixed, which nobody asked for. Detecting/answering the real prompt
 * from the web UI is a later phase (see that plan doc's Phase 6).
 *
 * SESSIONEER_SESSION_NAME gate: same convention as every Claude Code hook script
 * - this hook fires globally regardless, so untracked (non-Sessioneer) sessions
 * still need a valid decision returned, just skip the PendingToolStore
 * write since there's no session_name to key it by.
 */

require __DIR__ . '/../../lib/Sessions.php';

use HostAgent\Stores\PendingToolStore;
use HostAgent\Stores\SessionStatusStore;

$sessionName = getenv('SESSIONEER_SESSION_NAME');

if ($sessionName === false || $sessionName === '') {
    echo json_encode(['decision' => 'ask']);
    exit(0);
}

$input = stream_get_contents(STDIN);
$payload = json_decode((string)$input, true);

if (!is_array($payload)) {
    echo json_encode(['decision' => 'ask']);
    exit(0);
}

$toolCall = is_array($payload['toolCall'] ?? null) ? $payload['toolCall'] : null;
$toolName = is_string($toolCall['name'] ?? null) ? $toolCall['name'] : null;
$toolArgs = is_array($toolCall['args'] ?? null) ? $toolCall['args'] : null;

if ($toolName !== null && $toolArgs !== null) {
    PendingToolStore::write_pending_tool($sessionName, [
        'tool_name' => $toolName,
        'tool_input' => $toolArgs,
        'written_at' => time(),
    ]);

    // Opportunistic clear, same reasoning as Claude Code's own
    // pre_tool_use.php - a later tool call firing is itself proof any
    // earlier state has moved on. Real "blocked, waiting on the approval
    // prompt" detection doesn't exist yet (see this file's own docblock).
    SessionStatusStore::update_status($sessionName, ['status' => 'working', 'blocked' => null]);
}

echo json_encode(['decision' => 'ask']);
