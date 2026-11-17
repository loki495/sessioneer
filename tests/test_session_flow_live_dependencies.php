<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';

use HostAgent\Services\Config;
use HostAgent\SessionFlow\FlowService;
use HostAgent\Stores\GlobalStateStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$dir = sys_get_temp_dir() . '/sessioneer-flow-live-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void { exec('rm -rf ' . escapeshellarg($dir)); });
foreach (['HOME_ROOT' => 'home', 'PUSH_SQLITE_FILE' => 'push.sqlite', 'SESSIONS_SQLITE_FILE' => 'sessions.sqlite', 'SIDECAR_DIR' => 'sidecars', 'CACHE_DIR' => 'cache', 'CODEX_BRIDGE_SOCKET' => 'missing.sock', 'CLAUDE_HEADLESS_SOCKET' => 'missing-claude.sock', 'OPENCODE_DB_PATH' => 'missing.db', 'OPENCODE_AUTH_PATH' => 'missing.json', 'SESSION_FLOW_THRESHOLDS' => 'thresholds.conf'] as $key => $value) {
    putenv("{$key}={$dir}/{$value}");
}
putenv('OPENCODE_GO_API_KEY=');
putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
putenv('SOCKET_CLIENT_FAIL_FAST_IF_ABSENT=1');
putenv('CLAUDE_BIN=/nonexistent/claude');
putenv('CODEX_BIN=/nonexistent/codex');
file_put_contents($dir . '/thresholds.conf', "QUOTA_GO_MAX=60\nQUOTA_PARK=70\n");
$now = time();
GlobalStateStore::write(Config::quota_live_state_key('work'), ['captured_at' => $now, 'session' => ['pct' => 70, 'resets_at' => $now + 600], 'week_all' => ['pct' => 20, 'resets_at' => $now + 700]]);
SidecarStore::write_sidecar('flow-live', ['workdir' => $dir, 'agent' => 'claude', 'profile' => 'work', 'runtime' => 'headless', 'agent_session_id' => '11111111-1111-4111-8111-111111111111']);
SessionStatusStore::write_status('flow-live', ['status' => 'idle', 'context_usage' => ['input_tokens' => 1000]]);
$deps = FlowService::live();
assert_true(is_callable($deps['launch'] ?? null), 'live assembles the production launcher');
assert_equal([\HostAgent\Launch\LaunchService::class, 'kill'], $deps['kill'] ?? null, 'live assembles the production kill for the replaced-source archiver');
$quota = ($deps['quota'])('claude/work');
assert_equal([70, 20], [$quota['session_pct'] ?? null, $quota['week_pct'] ?? null], 'live quota maps the work profile and both windows');
$result = FlowService::next(['session' => 'flow-live'], $deps);
assert_equal('work', $result['source']['profile'] ?? null, 'live facts preserve source profile');
assert_equal('PARK', $result['state'] ?? null, 'configured threshold parks without an available receiver');
assert_equal(null, $result['receiver'] ?? null, 'unavailable receiver is excluded');
assert_equal(null, ($deps['quota'])('claude/not-configured'), 'missing quota remains unknown');

$script = $dir . '/claims';
file_put_contents($script, "#!/bin/sh\nprintf '%s\\n' \"\$#\" \"\$1\" > " . escapeshellarg($dir . '/args') . "\nprintf '[]'\n");
chmod($script, 0700);
$literal = 'argument with spaces;$(touch forbidden)';
$claims = ($deps['runner'])($script, $literal);
assert_equal(0, $claims['exit'] ?? null, 'live claims adapter runs fixture');
assert_equal("1\n{$literal}\n", file_get_contents($dir . '/args'), 'conversation id arrives as one literal argument');
assert_equal('[]', trim((string) ($claims['stdout'] ?? '')), 'fixture claims output is read');

echo "live claims gate\n";
putenv("SESSIONEER_CLAIMS_CMD={$script}");
$launches = 0;
$deps['launch'] = static function () use (&$launches): array {
    $launches++;
    return ['ok' => true, 'name' => 'fake-receiver'];
};
foreach (['held' => ["[{\"id\":1}]", 0], 'malformed' => ['not-json', 0], 'nonzero' => ['[]', 1]] as $label => [$output, $exit]) {
    file_put_contents($script, "#!/bin/sh\nprintf '%s' " . escapeshellarg($output) . "\nexit {$exit}\n");
    $r = FlowService::rotate(['session' => 'flow-live', 'handoff_id' => '9'], $deps);
    assert_equal(false, $r['ok'] ?? null, "{$label} claims refuse through the real adapter");
    assert_equal(0, $launches, "{$label} claims refuse before launch");
}
file_put_contents($script, "#!/bin/sh\nprintf '[]'\n");
$r = FlowService::rotate(['session' => 'flow-live', 'handoff_id' => '9'], $deps);
assert_equal(true, $r['ok'] ?? null, 'empty claims allow dispatch through the real adapter');
assert_equal(1, $launches, 'empty claims dispatch once');
putenv('SESSIONEER_CLAIMS_CMD');

echo "live Codex quota snapshot\n";
$socket = $dir . '/bridge.sock';
$log = $dir . '/bridge.log';
$process = proc_open([PHP_BINARY, __DIR__ . '/fixtures/flow_codex_quota_stub.php', $socket, $log, (string) ($now + 1000), (string) ($now + 2000)], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', $dir . '/bridge.err', 'w']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start fixture bridge');
}
try {
    for ($i = 0; $i < 100 && !file_exists($socket); $i++) {
        usleep(10000);
    }
    assert_true(file_exists($socket), 'fixture bridge is listening');
    putenv("CODEX_BRIDGE_SOCKET={$socket}");
    $live = FlowService::live();
    $codex = ($live['quota'])('codex/main');
    assert_equal([41, 52, $now + 2000], [$codex['session_pct'] ?? null, $codex['week_pct'] ?? null, $codex['resets_at'] ?? null], 'live Codex quota maps session, week and reset');
    ($live['quota'])('claude/work');
    ($live['quota'])('codex/main');
    $requests = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
    assert_equal(1, count($requests ?: []), 'one assembled dependency set reuses its quota snapshot');
} finally {
    proc_terminate($process);
    proc_close($process);
}

test_exit();
