#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Antigravity's PostToolUse hook (see AntigravityHookService
 * and docs/history/antigravity-adapter-plan.md Phase 3) - fires after a tool step
 * completes. Clears PendingToolStore, a cleaner signal than Claude Code
 * gets (it has no PostToolUse-equivalent at all - PendingToolStore
 * entries there are only ever implicitly superseded by the NEXT tool
 * call's own PreToolUse firing, never explicitly cleared).
 *
 * Writes {} to stdout (PostToolUse's own documented required response
 * shape) and always exits 0.
 *
 * SESSIONEER_SESSION_NAME gate: same convention as every hook script here - this
 * hook fires globally (every agy invocation on the machine), so an
 * untracked session is a deliberate no-op.
 */

require __DIR__ . '/../../lib/Sessions.php';

use HostAgent\Stores\PendingToolStore;

$sessionName = getenv('SESSIONEER_SESSION_NAME');

if ($sessionName !== false && $sessionName !== '') {
    PendingToolStore::delete_pending_tool($sessionName);
}

echo '{}';
