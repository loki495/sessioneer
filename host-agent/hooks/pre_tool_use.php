#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Claude Code's PreToolUse hook (see HookService::install_session_hook()
 * in ../lib/Sessions.php, and the README) - fires right before every tool
 * call, whether or not it ends up needing user approval, and before any
 * approval prompt is shown. Its main job is to record the full,
 * untruncated tool_name/tool_input JSON to a per-session sidecar file
 * (see PendingToolStore::write_pending_tool()), so build_session_entry() can show a pending
 * permission prompt's real full command/file content instead of whatever
 * tmux's rendered pane happens to have room to display (see
 * BLOCKING_PROMPT_CONTEXT_WINDOW / TMUX_PANE_WIDTH/HEIGHT for the
 * pane-scraping fallback this complements, not replaces).
 *
 * ALSO clears SessionStatusStore's blocked state (otherwise a
 * session keeps showing a stale "waiting on input" prompt on the dashboard
 * and session page long after it was actually resolved) - permission_
 * request.php sets status=blocked for a tool that needs a decision, but
 * once approved, nothing else in this app's hook sequence ever clears it
 * unless the SAME turn also happens to fire UserPromptSubmit or Stop.
 * Claude Code processes tool calls one at a time even within one batched
 * turn, so a later tool call's own PreToolUse firing is itself proof any
 * earlier blocking has already been resolved - Claude Code would never
 * start executing tool call N+1 while tool call N's permission prompt is
 * still genuinely unanswered. If THIS tool call also needs a decision,
 * permission_request.php fires right after and sets status=blocked again,
 * so the net effect is correct either way, just briefly optimistic between
 * the two hook firings (invisible to a poll-based UI) - EXCEPT for
 * AskUserQuestion (see below), which never gets that second firing.
 *
 * AskUserQuestion special case (without it a session shows
 * "Thinking..." on the dashboard/session page while its pane is
 * actually sitting on a real, answerable question): PermissionRequest is
 * Claude Code's own hook for permission decisions specifically - per the
 * official tools reference, AskUserQuestion prompts are a distinct
 * mechanism from permission prompts (they even have their own separate
 * idle-timeout setting), and it never fires PermissionRequest
 * at all. Left to the general logic above, status would sit at "working"
 * forever - nothing else in the hook sequence would ever flip it to
 * "blocked". So for this one tool, this hook writes "blocked" directly
 * instead, using the same tool_name/tool_input shape permission_request.php
 * writes for everything else (just no permission_suggestions, since
 * AskUserQuestion never has any).
 *
 * Deliberately writes nothing to stdout and always exits 0 - Claude Code
 * treats that as "no opinion", leaving its own permission decision
 * completely untouched. This hook only ever observes.
 *
 * SESSIONEER_SESSION_NAME (set via `tmux new-session -e` in create_agent_session())
 * is how this app's own tmux-spawned sessions are told apart from any
 * other claude process on the box - inherited by this hook script as a
 * child process of that same pane's claude. Anything without it (a plain
 * claude session started by hand) is a deliberate no-op.
 */

require __DIR__ . '/../lib/Sessions.php';

use HostAgent\Stores\PendingToolStore;
use HostAgent\Stores\SessionStatusStore;

$sessionName = getenv('SESSIONEER_SESSION_NAME');

if ($sessionName === false || $sessionName === '') {
    exit(0);
}

$input = stream_get_contents(STDIN);
$payload = json_decode((string)$input, true);

if (!is_array($payload)) {
    exit(0);
}

$toolName = is_string($payload['tool_name'] ?? null) ? $payload['tool_name'] : null;
$toolInput = is_array($payload['tool_input'] ?? null) ? $payload['tool_input'] : null;

if ($toolName === null || $toolInput === null) {
    exit(0);
}

PendingToolStore::write_pending_tool($sessionName, [
    'tool_name' => $toolName,
    'tool_input' => $toolInput,
    'written_at' => time(),
]);

if ($toolName === 'AskUserQuestion') {
    SessionStatusStore::update_status($sessionName, [
        'status' => 'blocked',
        'blocked' => [
            'tool_name' => $toolName,
            'tool_input' => $toolInput,
            'permission_suggestions' => [],
        ],
    ]);
} else {
    SessionStatusStore::update_status($sessionName, ['status' => 'working', 'blocked' => null]);
}
