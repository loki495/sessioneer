<?php

declare(strict_types=1);

// Stored malformed usage and partially successful launches use only isolated state and fixture callbacks.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../host-agent/lib/Sessions.php';

use HostAgent\SessionFlow\FlowService;
use HostAgent\SessionFlow\Thresholds;
use HostAgent\Stores\SessionOfferStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$dir = sys_get_temp_dir() . '/sessioneer-flow-review-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void {
    exec('rm -rf ' . escapeshellarg($dir));
});
foreach ([
    'HOME_ROOT' => 'home', 'PUSH_SQLITE_FILE' => 'push.sqlite', 'SESSIONS_SQLITE_FILE' => 'sessions.sqlite',
    'SIDECAR_DIR' => 'sidecars', 'CACHE_DIR' => 'cache', 'SESSION_FLOW_THRESHOLDS' => 'thresholds.conf',
    'CODEX_BRIDGE_SOCKET' => 'missing-codex.sock', 'CLAUDE_HEADLESS_SOCKET' => 'missing-claude.sock',
    'OPENCODE_DB_PATH' => 'missing.db', 'OPENCODE_AUTH_PATH' => 'missing.json',
] as $key => $path) {
    putenv("{$key}={$dir}/{$path}");
}
putenv('OPENCODE_GO_API_KEY=');
putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
putenv('SOCKET_CLIENT_FAIL_FAST_IF_ABSENT=1');
putenv('SESSIONEER_CLAIMS_CMD');
putenv('CLAUDE_BIN=/fixture/claude');
putenv('CODEX_BIN=/fixture/codex');

$now = time();
$rows = [];
foreach (['bad-claude' => 'claude', 'bad-codex' => 'codex'] as $name => $agent) {
    SidecarStore::write_sidecar($name, ['workdir' => $dir, 'agent' => $agent, 'runtime' => 'headless']);
    SessionStatusStore::write_status($name, ['status' => 'idle']);
    $rows[] = ['name' => $name, 'agent' => $agent, 'status' => 'idle', 'activity' => $now - 4000];
}
// Insert valid JSON scalar values as persisted by an older or malformed writer.
$pdo = new PDO('sqlite:' . $dir . '/sessions.sqlite');
$update = $pdo->prepare('UPDATE session_status SET context_usage_json = ?, token_usage_json = ? WHERE session_name = ?');
$update->execute(['123', 'null', 'bad-claude']);
$update->execute(['null', '"not-an-object"', 'bad-codex']);
$pdo = null;
try {
    $result = sessioneer_with_flow(['ok' => true, 'sessions' => $rows]);
    $out = $result['sessions'] ?? [];
    assert_equal(2, count($out), 'scalar stored JSON keeps both list rows');
    $live = FlowService::live();
    foreach (['bad-claude', 'bad-codex'] as $name) {
        $decision = FlowService::next(['session' => $name], $live);
        assert_equal(null, $decision['facts']['context_tokens'] ?? null, "{$name}: stored facts leave scalar context unknown");
    }
    foreach ($out as $row) {
        assert_true(array_key_exists('context_tokens', $row), 'scalar stored JSON exposes context field');
        assert_equal(null, $row['context_tokens'] ?? null, 'scalar stored JSON leaves context unknown');
        assert_true(($row['flow']['state'] ?? null) !== 'ROTATE', 'scalar stored JSON cannot trigger context rotation');
    }
} catch (Throwable $e) {
    assert_true(false, 'scalar stored JSON is handled without exception: ' . $e::class);
}

$source = 'partial-source';
SidecarStore::write_sidecar($source, [
    'workdir' => $dir, 'agent' => 'claude', 'profile' => 'work', 'runtime' => 'headless',
    'agent_session_id' => '11111111-1111-4111-8111-111111111111',
]);
SessionStatusStore::write_status($source, ['status' => 'idle', 'mode' => 'plan']);
SessionOfferStore::post($source, 'agent', ['state' => 'ROTATE'], '42', null, 'continue', $now);
$launches = 0;
$forbidden = [];
$deps = [
    'clock' => static fn (): int => $now,
    'facts' => static fn (string $name, array $row): ?array => $name === $source
        ? ['context_tokens' => 260000, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']]
        : null,
    'quota' => static fn (string $key): ?array => null,
    'receivers' => [],
    'thresholds' => Thresholds::fromFile($dir . '/thresholds.conf'),
    'launch' => static function () use (&$launches, $dir): array {
        $launches++;
        SidecarStore::write_sidecar('partial-receiver', ['workdir' => $dir, 'agent' => 'claude', 'runtime' => 'headless']);
        return ['ok' => false, 'name' => 'partial-receiver', 'message' => 'rename failed after launch'];
    },
    'archive' => static function () use (&$forbidden): array { $forbidden[] = 'archive'; return ['ok' => true]; },
    'kill' => static function () use (&$forbidden): array { $forbidden[] = 'kill'; return ['ok' => true]; },
];
$first = FlowService::rotate(['session' => $source, 'handoff_id' => '42'], $deps);
assert_equal(false, $first['ok'] ?? null, 'partially successful launch returns handled failure');
assert_contains('partial-receiver', (string) ($first['message'] ?? ''), 'failure identifies the receiver that exists');
assert_equal(null, SessionOfferStore::get($source, $now), 'partial launch consumes the offer');
$second = FlowService::rotate(['session' => $source, 'handoff_id' => '42'], $deps);
assert_equal(false, $second['ok'] ?? null, 'stale tap after partial launch is refused');
assert_equal(1, $launches, 'stale tap cannot launch a duplicate receiver');
assert_true(SidecarStore::read_sidecar('partial-receiver') !== null, 'existing receiver remains tracked');
assert_true(SidecarStore::read_sidecar($source) !== null, 'source remains tracked');
assert_equal([], $forbidden, 'partial launch never archives or kills either session');

echo "unnamed fresh-launch failure remains retryable\n";
$retry = 'retry-source';
SidecarStore::write_sidecar($retry, [
    'workdir' => $dir, 'agent' => 'claude', 'profile' => 'work', 'runtime' => 'headless',
    'agent_session_id' => '22222222-2222-4222-8222-222222222222',
]);
SessionStatusStore::write_status($retry, ['status' => 'idle', 'mode' => 'plan']);
SessionOfferStore::post($retry, 'agent', ['state' => 'ROTATE'], '43', null, 'retry', $now);
$initialOffer = SessionOfferStore::get($retry, $now);
$retryCalls = 0;
$retryDeps = $deps;
$retryDeps['facts'] = static fn (string $name, array $row): ?array => $name === $retry
    ? ['context_tokens' => 260000, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']]
    : null;
$retryDeps['launch'] = static function () use (&$retryCalls, $dir): array {
    $retryCalls++;
    if ($retryCalls === 1) {
        return ['ok' => false, 'name' => null, 'message' => 'no process started'];
    }
    SidecarStore::write_sidecar('retry-receiver', ['workdir' => $dir, 'agent' => 'claude', 'runtime' => 'headless']);
    return ['ok' => true, 'name' => 'retry-receiver'];
};
assert_equal(false, FlowService::rotate(['session' => $retry, 'handoff_id' => '43'], $retryDeps)['ok'] ?? null, 'unnamed failure returns handled error');
assert_equal($initialOffer, SessionOfferStore::get($retry, $now), 'unnamed failure restores the exact offer');
assert_equal(true, FlowService::rotate(['session' => $retry, 'handoff_id' => '43'], $retryDeps)['ok'] ?? null, 'unnamed failure can be retried');
assert_equal(2, $retryCalls, 'retry launches once after the original failure');

echo "failed send naming its existing source remains retryable\n";
$send = 'send-source';
SidecarStore::write_sidecar($send, [
    'workdir' => $dir, 'agent' => 'claude', 'profile' => 'work', 'runtime' => 'headless',
    'agent_session_id' => '33333333-3333-4333-8333-333333333333',
]);
SessionStatusStore::write_status($send, ['status' => 'idle', 'mode' => 'plan']);
SessionOfferStore::post($send, 'agent', ['state' => 'ROTATE'], null, null, 'send', $now);
$sendOffer = SessionOfferStore::get($send, $now);
$sendCalls = 0;
$sendDeps = $deps;
$sendDeps['facts'] = static fn (string $name, array $row): ?array => $name === $send
    ? ['context_tokens' => 260000, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']]
    : null;
$sendDeps['launch'] = static function () use (&$sendCalls, $send): array {
    $sendCalls++;
    return $sendCalls === 1 ? ['ok' => false, 'name' => $send, 'message' => 'send failed'] : ['ok' => true, 'name' => $send];
};
assert_equal(false, FlowService::rotate_request(['session' => $send], $sendDeps)['ok'] ?? null, 'failed send returns handled error');
assert_equal($sendOffer, SessionOfferStore::get($send, $now), 'failed send naming source restores exact offer');
assert_equal(true, FlowService::rotate_request(['session' => $send], $sendDeps)['ok'] ?? null, 'failed send naming source can be retried');
assert_equal(2, $sendCalls, 'send retried once');
assert_equal([], $forbidden, 'neither retry path archives or kills');

test_exit();
