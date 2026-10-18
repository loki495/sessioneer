#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Claude Code's PermissionRequest hook (see HookService::
 * install_session_hook() in ../lib/Sessions.php, and the README) - fires
 * only when Claude Code actually needs a human decision (an
 * intrinsically pre-allowlisted command gets PreToolUse but no
 * PermissionRequest at all - see PendingToolStore's own docblock for the
 * older mechanism this complements). Its payload already carries
 * tool_name/tool_input/permission_suggestions directly - no correlation
 * against PendingToolStore/pre_tool_use.php needed (see SessionStatusStore's
 * own docblock and the todo file's research entry for why the earlier
 * FIFO-queue design this could have needed turned out unnecessary).
 *
 * Records the full blocked-prompt state to SessionStatusStore -
 * SessionService::build_session_entry() uses this as the ONLY source for
 * every tool except AskUserQuestion (which keeps using
 * PromptParser::parse_blocking_prompt()'s pane-scraped path unchanged, see
 * build_session_entry()'s own comment on why) - no pane-scraping fallback
 * if this hook never fired for some reason (not installed yet, a script
 * error). That's the whole reason this hook is treated as mandatory by the
 * dashboard's health box.
 *
 * Deliberately writes nothing to stdout and always exits 0, same
 * "no opinion" convention as pre_tool_use.php - this hook only ever
 * observes, never decides.
 *
 * SESSIONEER_SESSION_NAME gate: same as pre_tool_use.php/session_start.php - a
 * plain claude session started by hand (no SESSIONEER_SESSION_NAME) is a
 * deliberate no-op, since there's no per-session status file to key by.
 */

require __DIR__ . '/../lib/Sessions.php';

use HostAgent\Services\PermissionMode;
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

$permissionSuggestions = is_array($payload['permission_suggestions'] ?? null) ? $payload['permission_suggestions'] : [];

$fields = [
    'status' => 'blocked',
    'blocked' => [
        'tool_name' => $toolName,
        'tool_input' => $toolInput,
        'permission_suggestions' => $permissionSuggestions,
    ],
];

$mode = PermissionMode::normalize_hook_permission_mode($payload['permission_mode'] ?? null);

if ($mode !== null) {
    $fields['mode'] = $mode;
}

SessionStatusStore::update_status($sessionName, $fields);
