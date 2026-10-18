#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Registered as Claude Code's UserPromptSubmit hook (see HookService::
 * install_session_hook() in ../lib/Sessions.php, and the README) - fires
 * every time a message is actually submitted to Claude, whether typed by
 * hand or sent via this app's own send_message()/answer_prompt(). Marks
 * the session working and clears any previously-recorded blocked state -
 * paired with permission_request.php (-> blocked) and stop.php (-> idle),
 * these three hook-SEQUENCE transitions are the ONLY source
 * SessionService::build_session_entry() uses for working/idle/blocked - no
 * pane-scraping fallback (PromptParser::pane_title_is_working(), matching a
 * rendered spinner glyph, was removed once these hooks became
 * mandatory - see the health box and CONTRIBUTING.md).
 *
 * Deliberately writes nothing to stdout and always exits 0 - same
 * "no opinion" convention as every other hook this app installs.
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

$fields = ['status' => 'working', 'blocked' => null];

$mode = PermissionMode::normalize_hook_permission_mode($payload['permission_mode'] ?? null);

if ($mode !== null) {
    $fields['mode'] = $mode;
}

SessionStatusStore::update_status($sessionName, $fields);
