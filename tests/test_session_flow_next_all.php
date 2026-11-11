<?php

declare(strict_types=1);

// Step 6 of session-flow: FlowService::next in all mode ({all: true}, no session), the host side of `sessioneer --next --all`.
// Written from the Contract and the orchestrator decision: reply {ok, sessions: [{name, state, why, receiver, prompt_kind}]}.
// Assumed, not in the Contract: a new dep 'sessions' => callable(): list<string> returns every tracked session name,
// and each name is decided exactly as next() would decide it alone (same facts, quota and receivers deps).
// Intent notes: one row per tracked session in the order given, each row decided independently (an unreadable session
// is an UNKNOWN row, not a failure of the whole call); all mode never posts offers; all with a session is refused.

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $c): void {
        foreach (['HostAgent\\' => '/../host-agent/lib/', 'App\\' => '/../src/lib/'] as $prefix => $dir) {
            $f = __DIR__ . $dir . str_replace('\\', '/', substr($c, strlen($prefix))) . '.php';
            if (str_starts_with($c, $prefix) && is_file($f)) {
                require $f;
            }
        }
    });
}
require __DIR__ . '/lib/assert.php';

$dir = sys_get_temp_dir() . '/sessioneer-test-flow-next-all-' . getmypid();
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void {
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
});
putenv("PUSH_SQLITE_FILE={$dir}/push.sqlite");
putenv("SESSIONS_SQLITE_FILE={$dir}/sessions.sqlite");
putenv("SIDECAR_DIR={$dir}/sidecars");
putenv("CACHE_DIR={$dir}/cache");

use HostAgent\SessionFlow\FlowService;
use HostAgent\SessionFlow\Thresholds;
use HostAgent\Stores\SessionOfferStore;

$NOW = 2_000_000;
$src = ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle'];
$facts = [
    's-low' => ['context_tokens' => 20000, 'idle_seconds' => 60, 'source' => $src],
    's-big' => ['context_tokens' => 260000, 'idle_seconds' => 60, 'source' => $src],
    's-q' => ['context_tokens' => 20000, 'idle_seconds' => 60, 'source' => ['profile' => 'q'] + $src],
];
$quotas = ['claude/work' => ['session_pct' => 10, 'week_pct' => 10, 'resets_at' => $NOW + 5000], 'claude/q' => ['session_pct' => 95, 'week_pct' => 40, 'resets_at' => $NOW + 5000], 'codex/main' => ['session_pct' => 5, 'week_pct' => 5, 'resets_at' => null]];
$deps = [
    'clock' => static fn (): int => $NOW,
    'facts' => static fn (string $s, array $row): ?array => $facts[$s] ?? null,
    'quota' => static fn (string $k): ?array => $quotas[$k] ?? null,
    'receivers' => ['codex/main', 'claude/work'],
    'thresholds' => Thresholds::fromFile($dir . '/none.conf'),
    'sessions' => static fn (): array => ['s-low', 'ghost', 's-big', 's-q'],
];

echo "next all: one row per session\n";
$r = FlowService::next(['all' => true], $deps);
assert_equal(true, $r['ok'] ?? null, 'all: ok');
$rows = $r['sessions'] ?? [];
assert_equal(['s-low', 'ghost', 's-big', 's-q'], array_column($rows, 'name'), 'all: one row per tracked session, in order');
foreach ($rows as $row) {
    foreach (['name', 'state', 'why', 'receiver', 'prompt_kind'] as $k) {
        assert_true(array_key_exists($k, $row), "all: row {$row['name']} has '$k'");
    }
}
assert_equal(['GO', 'UNKNOWN', 'ROTATE', 'ROTATE'], array_column($rows, 'state'), 'all: each row decided as next() would (GO, UNKNOWN for an unreadable session, ROTATE by context, ROTATE by quota)');
assert_true(array_key_exists('receiver', $rows[0]) && $rows[0]['receiver'] === null, 'all: a GO row has a null receiver');
assert_true(array_key_exists('receiver', $rows[2]) && $rows[2]['receiver'] === null, 'all: a context-triggered ROTATE row has a null receiver, as next() gives');
assert_equal('codex', $rows[3]['receiver']['agent'] ?? null, 'all: a quota-triggered ROTATE row names its receiver in the same array shape as next()');
foreach (['s-low' => 0, 's-big' => 2, 's-q' => 3] as $name => $i) {
    $single = FlowService::next(['session' => $name], $deps);
    assert_equal([$single['state'], $single['why'], $single['receiver']], [$rows[$i]['state'], $rows[$i]['why'], $rows[$i]['receiver']], "all: row $name equals the single-session decision");
}

echo "next all: no sessions, no side effects, refusals\n";
$empty = FlowService::next(['all' => true], ['sessions' => static fn (): array => []] + $deps);
assert_equal([true, []], [$empty['ok'] ?? null, $empty['sessions'] ?? null], 'all: no tracked sessions gives an empty list');
FlowService::next(['all' => true, 'post' => true], $deps);
assert_equal(null, SessionOfferStore::get('s-big', $NOW), 'all: never posts an offer, even with post');
$both = FlowService::next(['all' => true, 'session' => 's-low'], $deps);
assert_equal(false, $both['ok'] ?? null, 'all with a session: refused');
assert_true(is_string($both['message'] ?? null) && $both['message'] !== '', 'all with a session: a message');

echo "ok\n";
test_exit();
