#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Claude Code's Stop hook (see HookService::
 * install_session_hook() in ../lib/Sessions.php, and the README) - fires
 * once Claude finishes responding (not while blocked on a permission
 * prompt, which stays "in progress" from Claude Code's own perspective
 * until answered). Marks the session idle and clears any blocked state -
 * the third leg of the working/blocked/idle inference described in
 * user_prompt_submit.php's own docblock.
 *
 * Deliberately does NOT try to use Stop firing as a transcript-freshness
 * signal (ruled out - Claude Code writes the
 * transcript file ~1.7-2s BEFORE Stop fires) - this hook only ever updates
 * the status file, nothing transcript-related. That same "transcript
 * already written by the time Stop fires" timing IS relied on for one
 * thing here: clearing SessionStatusStore's `model` optimistic override
 * (see its own docblock) - the turn that just finished already used
 * whatever model was picked, so the transcript's own current_model is
 * trustworthy again and the override would only risk going stale from here.
 *
 * Writes nothing to stdout and always exits 0 - same "no opinion"
 * convention as every other hook this app installs.
 *
 * SESSIONEER_SESSION_NAME gate: same as the other hooks - a plain claude session
 * started by hand is a deliberate no-op.
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

$fields = ['status' => 'idle', 'blocked' => null, 'model' => null];

$lastMessage = is_string($payload['last_assistant_message'] ?? null) ? $payload['last_assistant_message'] : null;

if ($lastMessage !== null) {
    $fields['last_message'] = $lastMessage;
}

$mode = PermissionMode::normalize_hook_permission_mode($payload['permission_mode'] ?? null);

if ($mode !== null) {
    $fields['mode'] = $mode;
}

SessionStatusStore::update_status($sessionName, $fields);
