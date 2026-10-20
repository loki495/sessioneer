<?php
declare(strict_types=1);

/**
 * The rename action: the user's own session name is stored in the sidecar, wins over the agent's
 * title in the list and in a session's detail, survives the sync that rewrites the sidecar, and
 * rejects unknown sessions and over-long names.
 */

require __DIR__ . '/lib/assert.php';

$statusDb = sys_get_temp_dir() . '/sessioneer-test-rename-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv("SESSIONS_SQLITE_FILE={$statusDb}");

require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Stores\SidecarStore;

SidecarStore::write_sidecar('rename-a', ['workdir' => '/tmp/a', 'spawned_at' => 1, 'title' => 'Agent title A']);
SidecarStore::write_sidecar('rename-b', ['workdir' => '/tmp/b', 'spawned_at' => 1, 'title' => 'Agent title B']);

$ok = dispatch_action(['action' => 'rename_session', 'session' => 'rename-a', 'title' => "  My\x07 name \n"]);
assert_equal(true, $ok['ok'], 'rename: a tracked session can be renamed');
assert_equal('My name', $ok['title'], 'rename: surrounding space trimmed and control characters turned into spaces');
assert_equal(['rename-a' => 'My name'], SidecarStore::custom_titles(), 'rename: only the renamed session carries a custom title');

$list = sessioneer_with_custom_titles(['ok' => true, 'sessions' => [['name' => 'rename-a', 'title' => 'Agent title A'], ['name' => 'rename-b', 'title' => 'Agent title B']], 'headless' => [['id' => 'rename-a', 'title' => 'x']]]);
assert_equal('My name', $list['sessions'][0]['title'], 'list: the custom name replaces the agent title');
assert_equal('Agent title B', $list['sessions'][1]['title'], 'list: other sessions keep the agent title');
assert_equal('My name', $list['headless'][0]['title'], 'list: headless rows get the custom name too');

$detail = sessioneer_with_custom_titles(['ok' => true, 'name' => 'rename-a', 'title' => 'Agent title A'], 'rename-a');
assert_equal('My name', $detail['title'], 'detail: the custom name replaces the agent title');
$failed = sessioneer_with_custom_titles(['ok' => false, 'message' => 'nope', 'title' => 'keep'], 'rename-a');
assert_equal('keep', $failed['title'], 'detail: an error result is left untouched');

SidecarStore::write_sidecar('rename-a', ['workdir' => '/tmp/a', 'spawned_at' => 1, 'title' => 'Synced title']);
assert_equal(['rename-a' => 'My name'], SidecarStore::custom_titles(), 'the sync rewriting the sidecar keeps the custom name');

$cleared = dispatch_action(['action' => 'rename_session', 'session' => 'rename-a', 'title' => '   ']);
assert_equal(true, $cleared['ok'], 'rename: an empty name clears the custom title');
assert_equal(null, $cleared['title'], 'rename: a cleared name reports no title');
assert_equal([], SidecarStore::custom_titles(), 'rename: nothing stored after clearing');

$missing = dispatch_action(['action' => 'rename_session', 'session' => 'no-such-session', 'title' => 'x']);
assert_equal(['ok' => false, 'message' => 'Session not found'], $missing, 'rename: an unknown session is a handled error');
$blank = dispatch_action(['action' => 'rename_session', 'session' => '', 'title' => 'x']);
assert_equal(['ok' => false, 'message' => 'Session not found'], $blank, 'rename: an empty session name is a handled error');
$long = dispatch_action(['action' => 'rename_session', 'session' => 'rename-b', 'title' => str_repeat('x', SESSIONEER_CUSTOM_TITLE_MAX + 1)]);
assert_equal(false, $long['ok'], 'rename: an over-long name is rejected');
assert_contains('too long', (string)$long['message'], 'rename: the rejection says why');
assert_equal([], SidecarStore::custom_titles(), 'rename: a rejected name stores nothing');

@unlink($statusDb);
