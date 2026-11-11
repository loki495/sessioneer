<?php
declare(strict_types=1);

/**
 * Session flow wiring in the host-agent dispatcher: the flow_offer and flow_offer_dismiss actions,
 * and the offer overlay on list and session_detail replies. Every path is a temp file; nothing
 * here reads quota (the requests carry a decision and the rows are not idle).
 */

require __DIR__ . '/lib/assert.php';

$dir = sys_get_temp_dir() . '/sessioneer-test-flow-wiring-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void {
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
});
putenv("SESSIONS_SQLITE_FILE={$dir}/sessions.sqlite");
putenv("PUSH_SQLITE_FILE={$dir}/push.sqlite");
putenv("SIDECAR_DIR={$dir}");
putenv("SESSION_FLOW_THRESHOLDS={$dir}/none.conf");

require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Stores\SidecarStore;

SidecarStore::write_sidecar('wired', ['workdir' => '/tmp/wired', 'spawned_at' => 1]);

echo "flow_offer\n";
$decision = ['state' => 'ROTATE', 'why' => 'context', 'trigger' => 'context', 'close_call' => false, 'receiver' => null, 'reset_at' => null, 'needs_approval' => false, 'warnings' => []];
$r = dispatch_action(['action' => 'flow_offer', 'self' => 'wired', 'handoff_id' => '42', 'decision' => $decision]);
assert_equal(true, $r['ok'] ?? null, 'flow_offer: ok for a tracked session');
assert_equal('42', $r['offer']['handoff_id'] ?? null, 'flow_offer: the stored offer comes back');
assert_equal(false, dispatch_action(['action' => 'flow_offer', 'self' => 'nope', 'decision' => $decision])['ok'] ?? null, 'flow_offer: an unknown session is a handled error');

echo "overlay on list and detail\n";
$rows = [['name' => 'wired', 'status' => 'working', 'activity' => time()], ['name' => 'other', 'status' => 'working', 'activity' => time()]];
$list = sessioneer_with_flow(['ok' => true, 'sessions' => $rows]);
assert_equal('42', $list['sessions'][0]['offer']['handoff_id'] ?? null, 'list: the row with an offer carries it');
assert_equal(null, $list['sessions'][1]['offer'], 'list: other rows carry offer = null');
assert_equal(true, !isset($list['sessions'][0]['flow']), 'list: a working row gets no flow');
$detail = sessioneer_with_flow(['ok' => true, 'name' => 'wired', 'status' => 'working']);
assert_equal('42', $detail['offer']['handoff_id'] ?? null, 'detail: the offer is on the detail itself');
assert_equal(['ok' => false, 'message' => 'x'], sessioneer_with_flow(['ok' => false, 'message' => 'x']), 'a failed reply is untouched');

echo "flow_offer_dismiss\n";
assert_equal(true, dispatch_action(['action' => 'flow_offer_dismiss', 'self' => 'wired'])['ok'] ?? null, 'dismiss: ok on a live offer');
assert_equal(null, sessioneer_with_flow(['ok' => true, 'sessions' => $rows])['sessions'][0]['offer'], 'dismiss: the offer is gone from the list');
assert_equal(false, dispatch_action(['action' => 'flow_offer_dismiss', 'self' => 'wired'])['ok'] ?? null, 'dismiss: nothing live is a handled error');

test_exit();
