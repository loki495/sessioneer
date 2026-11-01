<?php
declare(strict_types=1);

/**
 * The star action: stored in the sidecar, marks list rows, and orders the list starred first, then
 * blocked, working, idle, newest activity first; unknown sessions are a handled error.
 */

require __DIR__ . '/lib/assert.php';

$db = sys_get_temp_dir() . '/sessioneer-test-star-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv("SESSIONS_SQLITE_FILE={$db}");

require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Stores\SidecarStore;

foreach (['s-idle-old', 's-idle-new', 's-work', 's-block', 's-star-idle'] as $n) {
    SidecarStore::write_sidecar($n, ['workdir' => '/tmp/' . $n, 'spawned_at' => 1, 'title' => $n]);
}

$on = dispatch_action(['action' => 'star_session', 'session' => 's-star-idle', 'starred' => true]);
assert_equal(['ok' => true, 'starred' => true], $on, 'star: a tracked session can be starred');
assert_equal(['s-star-idle' => true], SidecarStore::starred_names(), 'star: stored in the sidecar');

$rows = [
    ['name' => 's-idle-old', 'status' => 'idle', 'activity' => 1],
    ['name' => 's-idle-new', 'status' => 'idle', 'activity' => 9],
    ['name' => 's-work', 'status' => 'working', 'activity' => 5],
    ['name' => 's-block', 'status' => 'blocked', 'activity' => 2],
    ['name' => 's-star-idle', 'status' => 'idle', 'activity' => 0],
];
$out = sessioneer_with_stars(['ok' => true, 'sessions' => $rows]);
assert_equal(['s-star-idle', 's-block', 's-work', 's-idle-new', 's-idle-old'], array_column($out['sessions'], 'name'), 'star: order is starred, blocked, working, idle by recency');
assert_equal(true, $out['sessions'][0]['starred'], 'star: the row carries starred=true');
assert_equal(false, $out['sessions'][1]['starred'], 'star: other rows carry starred=false');

dispatch_action(['action' => 'star_session', 'session' => 's-star-idle', 'starred' => false]);
assert_equal([], SidecarStore::starred_names(), 'star: unstarring clears it');

$missing = dispatch_action(['action' => 'star_session', 'session' => 'nope', 'starred' => true]);
assert_equal(['ok' => false, 'message' => 'Session not found'], $missing, 'star: an unknown session is a handled error');

@unlink($db);
