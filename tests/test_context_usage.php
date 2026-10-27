<?php

declare(strict_types=1);

// Pure snapshot calculations plus isolated store/runtime/quota reads. No tmux,
// real bridge/manager, model calls, or host transcripts are used.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/../host-agent/lib/Sessions.php';

use HostAgent\Runtimes\ClaudeHeadlessRuntime;
use HostAgent\Runtimes\CodexBridgeClient;
use HostAgent\Services\Config;
use HostAgent\Services\ContextUsage;
use HostAgent\Services\QuotaService;
use HostAgent\Stores\GlobalStateStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SqliteDb;

$root = sys_get_temp_dir() . '/sessioneer-test-context-' . getmypid();
mkdir($root, 0700, true);
foreach (['SIDECAR_DIR' => '/sidecars', 'SESSIONS_SQLITE_FILE' => '/sessions.sqlite', 'PUSH_SQLITE_FILE' => '/push.sqlite', 'HOME_ROOT' => '/home', 'CODEX_BRIDGE_SOCKET' => '/absent.sock', 'OPENCODE_DB_PATH' => '/absent.db', 'OPENCODE_AUTH_PATH' => '/absent.json'] as $key => $path) {
    putenv($key . '=' . $root . $path);
}
putenv('OPENCODE_GO_API_KEY=');
register_shutdown_function(static function () use ($root): void {
    SqliteDb::reset_connections_for_tests();
    exec('rm -rf ' . escapeshellarg($root));
});

$assistant = ['type' => 'assistant', 'parent_tool_use_id' => null, 'message' => ['model' => 'measured-model', 'usage' => [
    'input_tokens' => 100, 'cache_creation_input_tokens' => 200, 'cache_read_input_tokens' => 700, 'output_tokens' => 9000,
]]];
$snapshot = ContextUsage::claude_assistant($assistant, null);
assert_equal(null, ContextUsage::claude_percentage($snapshot), 'Claude window remains unknown until reported');
$result = ['modelUsage' => ['measured-model' => ['contextWindow' => 10000], 'subagent-model' => ['contextWindow' => 100]], 'usage' => ['input_tokens' => 999999]];
$snapshot = ContextUsage::claude_result($snapshot, $result);
assert_equal(10.0, ContextUsage::claude_percentage($snapshot), 'Claude current input includes both caches, excludes output and result totals');
assert_equal($snapshot, ContextUsage::claude_assistant($assistant, $snapshot), 'streamed copies replace a snapshot instead of summing it');
$smaller = $assistant;
$smaller['message']['usage'] = ['input_tokens' => 50, 'cache_creation_input_tokens' => 50, 'cache_read_input_tokens' => 100];
assert_equal(2.0, ContextUsage::claude_percentage(ContextUsage::claude_assistant($smaller, $snapshot)), 'a smaller snapshot after compaction can decrease');
$otherModel = $assistant;
$otherModel['message']['model'] = 'other-model';
assert_equal(null, ContextUsage::claude_percentage(ContextUsage::claude_assistant($otherModel, $snapshot)), 'model switches cannot reuse a different model window');
$subagent = $otherModel;
$subagent['parent_tool_use_id'] = 'tool-1';
assert_equal($snapshot, ContextUsage::claude_assistant($subagent, $snapshot), 'subagent events do not replace the main snapshot');
foreach ([null, 0, -1, '10000', INF] as $badWindow) {
    assert_equal(null, ContextUsage::claude_percentage(ContextUsage::claude_result($snapshot, ['modelUsage' => ['measured-model' => ['contextWindow' => $badWindow]]])), 'invalid reported Claude window remains unknown: ' . var_export($badWindow, true));
}
assert_equal(null, ContextUsage::claude_percentage(ContextUsage::claude_result($snapshot, ['modelUsage' => ['subagent-model' => ['contextWindow' => 10000]]])), 'missing matching model window stays unknown');
$malformed = $assistant;
$malformed['message']['usage']['input_tokens'] = -1;
assert_equal(null, ContextUsage::claude_assistant($malformed, $snapshot), 'malformed current usage clears a stale snapshot');
assert_equal(null, ContextUsage::claude_percentage(null), 'missing Claude snapshot stays unknown');

$codex = ['total' => ['totalTokens' => 900000, 'inputTokens' => 800000, 'outputTokens' => 100000], 'last' => ['totalTokens' => 2500, 'inputTokens' => 2000, 'outputTokens' => 500], 'modelContextWindow' => 10000];
assert_equal(25.0, ContextUsage::codex_percentage($codex), 'Codex uses last, never cumulative total');
$codex['last']['totalTokens'] = 400;
assert_equal(4.0, ContextUsage::codex_percentage($codex), 'Codex compaction can decrease usage despite cumulative growth');
$codex['last']['totalTokens'] = 0;
assert_equal(0.0, ContextUsage::codex_percentage($codex), 'explicit zero is valid');
foreach ([null, 0, -1, '10000', INF] as $badWindow) {
    assert_equal(null, ContextUsage::codex_percentage(array_replace($codex, ['modelContextWindow' => $badWindow])), 'invalid Codex window remains unknown');
}
foreach ([null, [], ['totalTokens' => -1], ['totalTokens' => '100'], ['totalTokens' => INF]] as $badLast) {
    assert_equal(null, ContextUsage::codex_percentage(array_replace($codex, ['last' => $badLast])), 'invalid or missing Codex snapshot never falls back to total');
}
$codex['last']['totalTokens'] = 12000;
assert_equal(100.0, ContextUsage::codex_percentage($codex), 'context presentation is bounded at 100%');

class ContextQuotaBridge extends CodexBridgeClient
{
    public bool $available = true;
    public function request(string $method, array $params = []): array
    {
        return $this->available ? ['ok' => true, 'result' => ['rateLimits' => ['primary' => ['usedPercent' => 42]]]] : ['ok' => false, 'message' => 'fixture unavailable'];
    }
}
$bridge = new ContextQuotaBridge();
$codex['last']['totalTokens'] = 2500;
SessionStatusStore::update_status('codex-one', ['token_usage' => $codex]);
SessionStatusStore::update_status('codex-two', ['token_usage' => array_replace($codex, ['last' => ['totalTokens' => 1000]])]);
assert_equal(25.0, sessioneer_headless_detail_shape(['id' => 'codex-one'], 'codex')['context_used_percentage'], 'Codex detail exposes its own current context');
assert_equal(10.0, sessioneer_headless_detail_shape(['id' => 'codex-two'], 'codex')['context_used_percentage'], 'Codex sessions are isolated');
$quota = QuotaService::codex_quota_state('codex-one', $bridge);
assert_equal(25, $quota['quota']['context']['pct'], 'Codex quota overlay uses current snapshot');
assert_equal(900000, $quota['quota']['tokens_total'], 'Codex footer keeps cumulative token totals');
$bridge->available = false;
assert_equal(25, QuotaService::codex_quota_state('codex-one', $bridge)['quota']['context']['pct'], 'session context survives unavailable account rate limits');
assert_equal(null, QuotaService::codex_quota_state(null, $bridge), 'missing account and session data remains unavailable');

foreach (['personal' => 10.0, 'work' => 30.0] as $profile => $percentage) {
    $name = 'claude-headless-' . $profile;
    SidecarStore::write_sidecar($name, ['agent' => 'claude', 'runtime' => 'headless', 'profile' => $profile, 'workdir' => $root]);
    SessionStatusStore::update_status($name, ['context_usage' => array_replace($snapshot, ['input_tokens' => (int)($percentage * 100)])]);
    $entry = (new ClaudeHeadlessRuntime())->session_entry($name);
    assert_equal($percentage, $entry['context_used_percentage'], 'Claude runtime uses the requested session snapshot: ' . $profile);
    $stateKey = Config::quota_live_state_key($profile);
    GlobalStateStore::write($stateKey, ['captured_at' => time(), 'session' => ['pct' => (int)$percentage, 'resets_at' => time() + 3600]]);
    $quota = QuotaService::get_quota($name);
    assert_equal((int)$percentage, $quota['context']['pct'], 'Claude headless quota context requires no tmux: ' . $profile);
    assert_equal((int)$percentage, $quota['quota']['session']['pct'], 'selected Claude account quota matches the profile: ' . $profile);
    GlobalStateStore::delete($stateKey);
    assert_equal((int)$percentage, QuotaService::get_quota($name)['context']['pct'], 'Claude context survives missing account rate limits: ' . $profile);
}
SessionStatusStore::update_status('claude-headless-work', ['context_usage' => null]);
assert_equal(null, (new ClaudeHeadlessRuntime())->session_entry('claude-headless-work')['context_used_percentage'], 'unknown Claude usage is null, never zero');
assert_true(!isset(QuotaService::get_quota('claude-headless-work')['context']), 'unknown Claude footer context is omitted');
assert_equal(10.0, (new ClaudeHeadlessRuntime())->session_entry('claude-headless-personal')['context_used_percentage'], 'clearing one profile never affects another');

test_exit();
