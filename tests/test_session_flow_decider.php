<?php

declare(strict_types=1);

// Step 1 of session-flow: HostAgent\SessionFlow\Thresholds (KEY=int file) and
// FlowDecider::decide() (pure state machine). No I/O beyond temp files in /tmp,
// no agent, no model call. Written before the implementation: fails until the
// classes exist.

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $c): void {
        $f = __DIR__ . '/../host-agent/lib/' . str_replace('\\', '/', substr($c, strlen('HostAgent\\'))) . '.php';
        if (str_starts_with($c, 'HostAgent\\') && is_file($f)) {
            require $f;
        }
    });
}
require __DIR__ . '/lib/assert.php';

use HostAgent\SessionFlow\FlowDecider;
use HostAgent\SessionFlow\Thresholds;

const NOW = 1_000_000;

$tmp = [];
$write = static function (string $body) use (&$tmp): string {
    $p = tempnam(sys_get_temp_dir(), 'sf-thr-');
    file_put_contents($p, $body);
    $tmp[] = $p;

    return $p;
};
register_shutdown_function(static function () use (&$tmp): void {
    foreach ($tmp as $p) {
        @unlink($p);
    }
});

$defaults = static fn (): Thresholds => Thresholds::fromFile('/nonexistent/sf-defaults.conf');

/** Facts: ctx tokens, idle seconds, session pct, week pct, resets_at. */
$facts = static fn (?int $ctx, ?int $idle, ?int $s = null, ?int $w = null, ?int $reset = null, string $status = 'idle'): array => [
    'source' => ['session' => 'cc-a', 'agent' => 'claude', 'profile' => 'personal', 'status' => $status],
    'context_tokens' => $ctx,
    'idle_seconds' => $idle,
    'quota' => ['session_pct' => $s, 'week_pct' => $w, 'resets_at' => $reset],
];
$roomy = ['codex/default' => ['session_pct' => 10, 'week_pct' => 20]];
$decide = static fn (array $f, array $map = [], ?string $intent = null, ?Thresholds $t = null): array
    => FlowDecider::decide($f, $map, $t ?? $defaults(), $intent, NOW);
$state = static fn (array $f, array $map = [], ?string $intent = null): string => $decide($f, $map, $intent)['state'];

echo "Thresholds\n";
$t = $defaults();
$want = ['CTX_GO_MAX' => 150000, 'QUOTA_GO_MAX' => 80, 'CTX_ROTATE' => 250000, 'QUOTA_PARK' => 90, 'QUOTA_CARRY' => 95, 'IDLE_MIN' => 60, 'IDLE_FLOOR' => 100000];
foreach ($want as $k => $v) {
    assert_equal($v, $t->get($k), "missing file: $k default");
}
assert_true(count($t->warnings()) >= 1, 'missing file adds a warning');

$t = Thresholds::fromFile($write("CTX_GO_MAX=150000\nQUOTA_GO_MAX=80\n"));
assert_equal([], $t->warnings(), 'valid file: no warnings');

$t = Thresholds::fromFile(__DIR__ . '/fixtures/session_flow_thresholds.conf');
assert_equal(1000, $t->get('CTX_GO_MAX'), 'fixture: CTX_GO_MAX read');
assert_equal(50, $t->get('QUOTA_GO_MAX'), 'fixture: QUOTA_GO_MAX read');
assert_equal(60, $t->get('QUOTA_PARK'), 'fixture: QUOTA_PARK read');
assert_equal(5, $t->get('IDLE_MIN'), 'fixture: IDLE_MIN read');
assert_equal(250000, $t->get('CTX_ROTATE'), 'fixture: absent key keeps default');
assert_equal([], $t->warnings(), 'fixture: comments and blank lines give no warnings');

$t = Thresholds::fromFile($write("CTX_GO_MAX=abc\nQUOTA_PARK=9.5\nIDLE_MIN=\nQUOTA_GO_MAX=70\n"));
assert_equal(150000, $t->get('CTX_GO_MAX'), 'non-integer falls back to default');
assert_equal(90, $t->get('QUOTA_PARK'), 'decimal falls back to default');
assert_equal(60, $t->get('IDLE_MIN'), 'empty value falls back to default');
assert_equal(70, $t->get('QUOTA_GO_MAX'), 'other valid keys still apply');
assert_true(count($t->warnings()) >= 3, 'each bad value adds a warning');

$t = Thresholds::fromFile($write("CTX_ROTATE=-5\n"));
assert_equal(250000, $t->get('CTX_ROTATE'), 'negative falls back to default');
assert_true(count($t->warnings()) >= 1, 'negative adds a warning');

echo "Result shape\n";
$r = $decide($facts(1000, 10));
foreach (['state', 'why', 'trigger', 'close_call', 'receiver', 'reset_at', 'needs_approval', 'warnings'] as $k) {
    assert_true(array_key_exists($k, $r), "result has key $k");
}
assert_equal('GO', $r['state'], 'small warm session: GO');
assert_equal('none', $r['trigger'], 'GO: trigger none');
assert_equal(false, $r['close_call'], 'GO: not a close call');
assert_equal(null, $r['receiver'], 'GO: no receiver');
assert_equal(null, $r['reset_at'], 'GO: no reset_at');
assert_true(is_string($r['why']) && $r['why'] !== '', 'why is a non-empty string');
assert_true(is_bool($r['needs_approval']) && is_array($r['warnings']), 'needs_approval bool, warnings array');
assert_equal($decide($facts(1000, 10)), $decide($facts(1000, 10)), 'pure: same input, same output');

echo "Context boundaries (warm cache)\n";
assert_equal('GO', $state($facts(149999, 60)), 'ctx 149999: GO');
assert_equal('WARM_TIGHT', $state($facts(150000, 60)), 'ctx 150000: WARM_TIGHT');
assert_equal('WARM_TIGHT', $state($facts(249999, 60)), 'ctx 249999: WARM_TIGHT');
$r = $decide($facts(250000, 60));
assert_equal('ROTATE', $r['state'], 'ctx 250000: ROTATE');
assert_equal('context', $r['trigger'], 'ctx 250000: trigger context');
assert_equal(false, $r['close_call'], 'ctx 250000: not a close call');
assert_equal('ROTATE', $state($facts(900000, 60)), 'ctx far above: ROTATE');

echo "Quota boundaries\n";
assert_equal('GO', $state($facts(1000, 60, 79, 79)), 'quota 79: GO');
$r = $decide($facts(1000, 60, 80, 10));
assert_equal('WARM_TIGHT', $r['state'], 'session 80: WARM_TIGHT');
assert_equal('quota', $r['trigger'], 'session 80: trigger quota');
assert_equal('WARM_TIGHT', $state($facts(1000, 60, 10, 80)), 'week 80: WARM_TIGHT');
assert_equal('WARM_TIGHT', $state($facts(1000, 60, 89, 89)), 'quota 89: WARM_TIGHT');
assert_equal('GO', $state($facts(1000, 60, null, 79)), 'only week reading, 79: GO');
$r = $decide($facts(1000, 60, 90, 10), $roomy);
assert_equal('ROTATE', $r['state'], 'quota 90 with a receiver: ROTATE');
assert_equal('quota', $r['trigger'], 'quota 90 rotate: trigger quota');
assert_true(is_array($r['receiver']), 'quota 90 rotate: receiver set');
assert_equal('ROTATE', $state($facts(1000, 60, 10, 90), $roomy), 'week 90 with a receiver: ROTATE');
$r = $decide($facts(1000, 60, 90, 10), []);
assert_equal('PARK', $r['state'], 'quota 90 with no receiver: PARK');
assert_equal('quota', $r['trigger'], 'PARK: trigger quota');
assert_equal(null, $r['receiver'], 'PARK: no receiver');
assert_equal('PARK', $state($facts(1000, 60, 100, 100)), 'quota 100, no receiver: PARK');

echo "Receivers\n";
$map = static fn (?int $s, ?int $w): array => ['codex/default' => ['session_pct' => $s, 'week_pct' => $w]];
assert_equal('ROTATE', $state($facts(1000, 60, 95, 95), $map(79, 79)), 'receiver at 79/79 has room');
assert_equal('PARK', $state($facts(1000, 60, 95, 95), $map(80, 10)), 'receiver session 80: no room');
assert_equal('PARK', $state($facts(1000, 60, 95, 95), $map(10, 80)), 'receiver week 80: no room');
assert_equal('PARK', $state($facts(1000, 60, 95, 95), $map(null, 10)), 'receiver missing session reading: no room');
assert_equal('PARK', $state($facts(1000, 60, 95, 95), $map(10, null)), 'receiver missing week reading: no room');
assert_equal('PARK', $state($facts(1000, 60, 95, 95), $map(null, null)), 'receiver with no readings: no room');
assert_equal('ROTATE', $state($facts(1000, 60, 95, 95), $map(null, null) + ['opencode/x' => ['session_pct' => 5, 'week_pct' => 5]]), 'one receiver with room is enough');

echo "Reset time\n";
$r = $decide($facts(1000, 60, 95, 10, NOW + 1800), []);
assert_equal(true, $r['close_call'], 'reset in 1800 s: close call');
assert_equal(NOW + 1800, $r['reset_at'], 'reset_at from quota.resets_at');
assert_equal(true, $decide($facts(1000, 60, 95, 10, NOW + 3599), [])['close_call'], 'reset in 3599 s: close call');
$r = $decide($facts(1000, 60, 95, 10, NOW + 7200), []);
assert_equal(false, $r['close_call'], 'reset in 7200 s: not a close call');
assert_equal(NOW + 7200, $r['reset_at'], 'reset_at kept when far');
$r = $decide($facts(1000, 60, 95, 10, null), []);
assert_equal('PARK', $r['state'], 'unknown reset: still PARK');
assert_equal(null, $r['reset_at'], 'unknown reset: reset_at null');
assert_equal(false, $r['close_call'], 'unknown reset: not a close call');
$r = $decide($facts(1000, 60, 95, 10, NOW - 500), []);
assert_equal('PARK', $r['state'], 'reset in the past: still PARK, no error');
assert_true($r['reset_at'] === null || $r['reset_at'] === NOW - 500, 'reset in the past: reset_at null or the given value');
$r = $decide($facts(1000, 60, 95, 10, NOW + 1800), $roomy);
assert_equal('ROTATE', $r['state'], 'close reset with a receiver: ROTATE');
assert_equal(true, $r['close_call'], 'close reset with a receiver: close call');

echo "Cold cache (idle >= 60 min)\n";
assert_equal('GO', $state($facts(99999, 3600)), 'cold, ctx 99999: GO');
assert_equal('GO', $state($facts(0, 86400)), 'cold, ctx 0: GO');
$r = $decide($facts(100000, 3600));
assert_equal('ROTATE', $r['state'], 'cold, ctx 100000: ROTATE');
assert_equal('cold_cache', $r['trigger'], 'cold, ctx 100000: trigger cold_cache');
assert_equal(true, $r['close_call'], 'cold, ctx 100000: close call');
$r = $decide($facts(249999, 3600));
assert_equal('ROTATE', $r['state'], 'cold, ctx 249999: ROTATE');
assert_equal('cold_cache', $r['trigger'], 'cold, ctx 249999: trigger cold_cache');
assert_equal(true, $r['close_call'], 'cold, ctx 249999: close call');
$r = $decide($facts(250000, 3600));
assert_equal('context', $r['trigger'], 'cold, ctx 250000: trigger context');
assert_equal('ROTATE', $r['state'], 'cold, ctx 250000: ROTATE');
assert_equal('GO', $state($facts(99999, 3599)), 'warm 59:59, ctx 99999: GO');
assert_equal('GO', $state($facts(100000, 3599)), 'warm 59:59, ctx 100000: GO (cold rule not applied)');
assert_equal('GO', $state($facts(149999, 3599)), 'warm 59:59, ctx 149999: GO');
assert_equal('WARM_TIGHT', $state($facts(150000, 3599)), 'warm 59:59, ctx 150000: WARM_TIGHT');

echo "Intent and status\n";
assert_equal('CHECKPOINT', $state($facts(160000, 60), [], 'step-end'), 'step-end + WARM_TIGHT: CHECKPOINT');
assert_equal('CHECKPOINT', $state($facts(1000, 60, 85, 10), [], 'step-end'), 'step-end + quota WARM_TIGHT: CHECKPOINT');
assert_equal('GO', $state($facts(1000, 60), [], 'step-end'), 'step-end + GO: GO');
assert_equal('ROTATE', $state($facts(260000, 60), [], 'step-end'), 'step-end + ROTATE: ROTATE');
assert_equal('PARK', $state($facts(1000, 60, 95, 10), [], 'step-end'), 'step-end + PARK: PARK');
assert_equal('WARM_TIGHT', $state($facts(160000, 60), [], null), 'no intent + WARM_TIGHT: stays WARM_TIGHT');
assert_equal('WAIT', $state($facts(1000, 60), [], 'wait'), 'intent wait: WAIT');
assert_equal('WAIT', $state($facts(1000, 60, null, null, null, 'blocked')), 'blocked source: WAIT');
assert_equal('DONE', $state($facts(1000, 60), [], 'done'), 'intent done: DONE');
assert_equal('DONE', $state($facts(1000, 60, 95, 10), [], 'done'), 'done beats PARK');
assert_equal('DONE', $state($facts(260000, 60), [], 'done'), 'done beats ROTATE');
assert_equal('DONE', $state($facts(1000, 60, null, null, null, 'blocked'), [], 'done'), 'done beats blocked WAIT');
assert_equal('PARK', $state($facts(1000, 60, 95, 10), [], 'wait'), 'PARK beats intent wait');
assert_equal('PARK', $state($facts(1000, 60, 95, 10, null, 'blocked')), 'PARK beats blocked');
assert_equal('WAIT', $state($facts(260000, 60), [], 'wait'), 'WAIT beats ROTATE (context)');
assert_equal('WAIT', $state($facts(1000, 60, 95, 10), $roomy, 'wait'), 'WAIT beats quota ROTATE');
assert_equal('WAIT', $state($facts(160000, 60), [], 'wait'), 'WAIT beats WARM_TIGHT');
$r = $decide($facts(1000, 60), [], 'bogus');
assert_equal('GO', $r['state'], 'unknown intent ignored: state as without intent');
assert_true(count($r['warnings']) >= 1, 'unknown intent adds a warning');
assert_equal([], $decide($facts(1000, 60), [], null)['warnings'], 'null intent: no warnings');
assert_equal([], $decide($facts(1000, 60), [], 'step-end')['warnings'], 'known intent: no warnings');

echo "Thresholds drive the decider\n";
$custom = Thresholds::fromFile(__DIR__ . '/fixtures/session_flow_thresholds.conf');
assert_equal('WARM_TIGHT', $decide($facts(1000, 60), [], null, $custom)['state'], 'custom CTX_GO_MAX=1000: 1000 is WARM_TIGHT');
assert_equal('GO', $decide($facts(999, 60), [], null, $custom)['state'], 'custom CTX_GO_MAX=1000: 999 is GO');
assert_equal('WARM_TIGHT', $decide($facts(10, 60, 50, 0), [], null, $custom)['state'], 'custom QUOTA_GO_MAX=50: 50 is WARM_TIGHT');
assert_equal('PARK', $decide($facts(10, 60, 60, 0), [], null, $custom)['state'], 'custom QUOTA_PARK=60: 60 is PARK');
assert_equal('ROTATE', $decide($facts(100000, 300), [], null, $custom)['state'], 'custom IDLE_MIN=5: cold at 300 s, ctx 100000: ROTATE');
assert_equal('WARM_TIGHT', $decide($facts(100000, 299), [], null, $custom)['state'], 'custom IDLE_MIN=5: 299 s is warm (ctx over custom CTX_GO_MAX)');

echo "No readable facts\n";
$r = $decide($facts(null, null));
assert_equal('UNKNOWN', $r['state'], 'all facts null: UNKNOWN');
assert_equal('none', $r['trigger'], 'UNKNOWN: trigger none');
assert_equal('UNKNOWN', $state($facts(null, null), $roomy), 'UNKNOWN with receivers in the map');
assert_equal('DONE', $state($facts(null, null), [], 'done'), 'intent done still wins with no facts');
assert_equal('GO', $state($facts(1000, null)), 'only context known: decides (GO)');
assert_equal('GO', $state($facts(null, 10)), 'only idle known: decides (GO)');
assert_equal('PARK', $state($facts(null, null, 95, 10)), 'only quota known: decides (PARK)');
assert_equal('WARM_TIGHT', $state($facts(160000, null)), 'idle unknown, ctx 160000: WARM_TIGHT');

test_exit();
