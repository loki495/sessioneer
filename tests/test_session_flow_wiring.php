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
    exec('rm -rf ' . escapeshellarg($dir));
});
putenv("SESSIONS_SQLITE_FILE={$dir}/sessions.sqlite");
putenv("PUSH_SQLITE_FILE={$dir}/push.sqlite");
putenv("SIDECAR_DIR={$dir}/sidecars");
putenv("HOME_ROOT={$dir}/home");
putenv("CACHE_DIR={$dir}/cache");
putenv("OPENCODE_DB_PATH={$dir}/missing.db");
putenv("OPENCODE_AUTH_PATH={$dir}/missing.json");
putenv("OPENCODE_GO_API_KEY=");
putenv("OPENCODE_SERVE_URL=http://127.0.0.1:1");
putenv("SOCKET_CLIENT_FAIL_FAST_IF_ABSENT=1");
putenv("SESSION_FLOW_THRESHOLDS={$dir}/none.conf");

require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SessionStatusStore;

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

echo "stored context on the list overlay\n";
putenv("CODEX_BRIDGE_SOCKET={$dir}/missing-codex.sock");
putenv("CLAUDE_HEADLESS_SOCKET={$dir}/missing-claude.sock");
$cases = [
    'claude-edge' => ['claude', 'idle', ['context_usage' => ['input_tokens' => 150000]], 150000, 'WARM_TIGHT'],
    'codex-edge' => ['codex', 'idle', ['token_usage' => ['last' => ['totalTokens' => 250000], 'total' => ['totalTokens' => 9999999]]], 250000, 'ROTATE'],
    'claude-low' => ['claude', 'idle', ['context_usage' => ['input_tokens' => 149999]], 149999, null],
    'claude-zero' => ['claude', 'idle', ['context_usage' => ['input_tokens' => 0]], 0, null],
    'claude-bad' => ['claude', 'idle', ['context_usage' => ['input_tokens' => '250000']], null, null],
    'codex-total' => ['codex', 'idle', ['token_usage' => ['total' => ['totalTokens' => 9999999]]], null, null],
    'claude-busy' => ['claude', 'working', ['context_usage' => ['input_tokens' => 250000]], 250000, null],
];
$storedRows = [];
foreach ($cases as $name => [$agent, $status, $usage]) {
    SidecarStore::write_sidecar($name, ['workdir' => $dir, 'agent' => $agent, 'runtime' => 'headless']);
    SessionStatusStore::write_status($name, ['status' => $status]);
    SessionStatusStore::update_status($name, $usage);
    foreach ($usage as $field => $value) {
        assert_equal($value, SessionStatusStore::read_status($name)[$field] ?? null, "{$name}: stored fixture {$field}");
    }
    $storedRows[] = ['name' => $name, 'agent' => $agent, 'status' => $status, 'activity' => time()];
}
// A valid high-context transcript must not supply facts for a list row with no stored usage.
$baitId = '12345678-1234-4234-8234-123456789012';
$baitDir = $dir . '/home/.claude/projects/' . preg_replace('/[^A-Za-z0-9]/', '-', $dir);
mkdir($baitDir, 0700, true);
$bait = $baitDir . '/' . $baitId . '.jsonl';
file_put_contents($bait, json_encode(['type' => 'assistant', 'message' => ['model' => 'test', 'usage' => ['input_tokens' => 260000, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0]]]) . "\n");
assert_equal(260000, \HostAgent\SessionFlow\FlowFacts::claude($bait)['context_tokens'] ?? null, 'bait transcript is readable and would trigger rotation');
SidecarStore::write_sidecar('transcript-bait', ['workdir' => $dir, 'agent' => 'claude', 'runtime' => 'headless', 'agent_session_id' => $baitId]);
SessionStatusStore::write_status('transcript-bait', ['status' => 'idle']);
$storedRows[] = ['name' => 'transcript-bait', 'agent' => 'claude', 'status' => 'idle', 'activity' => time() - 4000];
$enriched = sessioneer_with_flow(['ok' => true, 'sessions' => $storedRows])['sessions'];
foreach (array_values($cases) as $i => [$agent, $status, $usage, $tokens, $state]) {
    assert_true(array_key_exists('context_tokens', $enriched[$i]), "stored row {$i} exposes context_tokens");
    assert_equal($tokens, $enriched[$i]['context_tokens'] ?? null, "stored row {$i} context");
    assert_equal($state, $enriched[$i]['flow']['state'] ?? null, "stored row {$i} flow threshold");
}

$baitRow = $enriched[count($enriched) - 1];
assert_equal(null, $baitRow['context_tokens'] ?? null, 'list never derives context from a transcript');
assert_true(($baitRow['flow']['state'] ?? null) !== 'ROTATE', 'list flow ignores the high-context transcript');

test_exit();
