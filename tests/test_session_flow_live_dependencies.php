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
    // The earlier live() calls wrote the 30 second flow-quota.json cache with no Codex reading; drop it so this
    // section reads the bridge afresh (the cache has its own tests below).
    @unlink($dir . '/cache/flow-quota.json');
    $live = FlowService::live();
    $codex = ($live['quota'])('codex/main');
    assert_equal([41, 52, $now + 2000], [$codex['session_pct'] ?? null, $codex['week_pct'] ?? null, $codex['resets_at'] ?? null], 'live Codex quota maps session, week and reset');
    ($live['quota'])('claude/work');
    ($live['quota'])('codex/main');
    $requests = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
    assert_equal(1, count($requests ?: []), 'one assembled dependency set reuses its quota snapshot');

    echo "flow quota cache (flow-quota.json, 30 seconds)\n";
    $cachePath = $dir . '/cache/flow-quota.json';
    $bridgeRequests = static fn (): int => is_file($log) ? count(file($log, FILE_IGNORE_NEW_LINES) ?: []) : 0;
    $codexNow = static fn (array $deps): ?array => ($deps['quota'])('codex/main');
    assert_true(is_file($cachePath), 'cache: the first read wrote flow-quota.json');
    $written = json_decode((string) file_get_contents($cachePath), true);
    assert_true(is_array($written) && is_numeric($written['at'] ?? null) && is_array($written['usage'] ?? null), 'cache: the file is complete JSON with `at` and `usage`');

    $before = $bridgeRequests();
    $again = $codexNow(FlowService::live());
    assert_equal($before, $bridgeRequests(), 'cache: a second live() set within 30 seconds makes no bridge request');
    assert_equal([41, 52, $now + 2000], [$again['session_pct'] ?? null, $again['week_pct'] ?? null, $again['resets_at'] ?? null], 'cache: and returns the cached values');

    $stale = $written;
    $stale['at'] = microtime(true) - 31;
    file_put_contents($cachePath, json_encode($stale));
    $before = $bridgeRequests();
    $fresh = $codexNow(FlowService::live());
    assert_equal($before + 1, $bridgeRequests(), 'cache: an `at` older than 30 seconds triggers exactly one new read');
    assert_equal(41, $fresh['session_pct'] ?? null, 'cache: the refreshed reading is returned');
    $rewritten = json_decode((string) file_get_contents($cachePath), true);
    assert_true(is_array($rewritten) && microtime(true) - (float) ($rewritten['at'] ?? 0) < 5, 'cache: the refresh rewrote the file with a current `at`');
    $edge = $written;
    $edge['at'] = microtime(true) - 25;
    file_put_contents($cachePath, json_encode($edge));
    $before = $bridgeRequests();
    $codexNow(FlowService::live());
    assert_equal($before, $bridgeRequests(), 'cache: 25 seconds old is still fresh');

    foreach (['half written' => '{"at": 17', 'empty' => '', 'not json' => 'garbage', 'wrong shape' => '{"at":"x","usage":5}', 'no usage' => '{"at":' . microtime(true) . '}'] as $label => $content) {
        file_put_contents($cachePath, $content);
        $before = $bridgeRequests();
        $r = $codexNow(FlowService::live());
        assert_equal($before + 1, $bridgeRequests(), "cache: a {$label} file is ignored and read again");
        assert_equal(41, $r['session_pct'] ?? null, "cache: a {$label} file still yields the real reading");
        assert_true(is_array(json_decode((string) file_get_contents($cachePath), true)), "cache: a {$label} file is replaced by valid JSON");
    }

    echo "flow quota cache: atomic write\n";
    clearstatcache();
    $stale['at'] = microtime(true) - 40;
    file_put_contents($cachePath, json_encode($stale));
    clearstatcache();
    $inodeBefore = fileinode($cachePath);
    $codexNow(FlowService::live());
    clearstatcache();
    assert_true(fileinode($cachePath) !== $inodeBefore, 'cache: the file is replaced by a rename (new inode), never rewritten in place');
    $leftovers = array_values(array_diff(scandir($dir . '/cache') ?: [], ['.', '..', 'flow-quota.json']));
    assert_equal([], array_values(array_filter($leftovers, static fn (string $f): bool => str_contains($f, 'flow-quota') || str_ends_with($f, '.tmp'))), 'cache: no temp file is left in the cache directory after a successful write');
    assert_true(is_array(json_decode((string) file_get_contents($cachePath), true)), 'cache: the written file is complete JSON');

    echo "flow quota cache: failed write\n";
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        echo "  SKIP: running as root, a read-only directory cannot be simulated\n";
    } else {
        @unlink($cachePath);
        $errLog = $dir . '/php-error.log';
        $oldLog = ini_get('error_log');
        ini_set('error_log', $errLog);
        chmod($dir . '/cache', 0500);
        try {
            $before = $bridgeRequests();
            $r = $codexNow(FlowService::live());
            assert_equal(41, $r['session_pct'] ?? null, 'cache: an unwritable cache directory does not fail live(); the reading is still returned');
            assert_equal($before + 1, $bridgeRequests(), 'cache: and it was read from the bridge');
        } finally {
            chmod($dir . '/cache', 0700);
            ini_set('error_log', $oldLog === false ? '' : (string) $oldLog);
        }
        $logged = is_file($errLog) ? (string) file_get_contents($errLog) : '';
        assert_contains('flow-quota', $logged, 'cache: the failed write is logged through error_log and names the cache file');
        assert_true(!is_file($cachePath), 'cache: nothing was written');
    }
} finally {
    proc_terminate($process);
    proc_close($process);
}

test_exit();
