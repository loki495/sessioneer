<?php

declare(strict_types=1);

// Auto-continue (Dibs 1766): FlowService::next with --post, --plan and --no-gates starts the receiver itself
// when the plan body says `Auto-continue: on` and the decision is ROTATE with a receiver.
// Written from the Contract only. Isolation: temp SQLite files, fake runner (plan command and claims command), fake launch.
// Intent notes:
//  - setting: one line `Auto-continue: on` (case-insensitive key and value, own line, optional surrounding spaces) is on;
//    absent, off, or anything else is off. The plan body comes from SESSIONEER_PLAN_CMD run as
//    sh -c '<cmd> "$1"' sh <plan id> through deps runner; exit 0 and non-empty stdout required, else auto_continue is null (fail closed).
//  - reply keys: auto_continue (true|false|null; null when no plan_id or the command is unset/failed/empty), auto_continued
//    (true only when a receiver started, then `session` is the receiver name), auto_continue_note (string on any refusal).
//  - gates: post, plan_id (not handoff_id), no_gates, auto_continue true, state ROTATE, a receiver. Each missing alone: no launch.
//  - every non-ROTATE state never launches and keeps its post/clear behaviour.
//  - a rotate refusal or launch failure: auto_continued false, note carries the message, the normal ROTATE offer is posted.
//  - without post nothing starts; all mode never auto-continues.

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

$dir = sys_get_temp_dir() . '/sessioneer-test-flow-auto-continue-' . getmypid();
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void {
    exec('rm -rf ' . escapeshellarg($dir));
});
putenv("PUSH_SQLITE_FILE={$dir}/push.sqlite");
putenv("SESSIONS_SQLITE_FILE={$dir}/sessions.sqlite");
putenv("SIDECAR_DIR={$dir}/sidecars");
putenv("CACHE_DIR={$dir}/cache");
putenv('SESSIONEER_CLAIMS_CMD=fake-claims');
putenv('SESSIONEER_PLAN_CMD=fake-plan-show');
putenv('CLAUDE_BIN=/fake/claude');
putenv('CODEX_BIN=/fake/codex');

use HostAgent\SessionFlow\FlowService;
use HostAgent\SessionFlow\Thresholds;
use HostAgent\Stores\SessionOfferStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SqliteDb;

$NOW = 2_000_000;
$UUID = '11111111-1111-4111-8111-111111111111';
$src = ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle'];
$facts = [
    'src-rot' => ['context_tokens' => 20000, 'idle_seconds' => 60, 'source' => ['profile' => 'q'] + $src],
    'src-go' => ['context_tokens' => 20000, 'idle_seconds' => 60, 'source' => $src],
    'src-warm' => ['context_tokens' => 160000, 'idle_seconds' => 60, 'source' => $src],
    'src-ctx' => ['context_tokens' => 260000, 'idle_seconds' => 60, 'source' => $src],
    'src-park' => ['context_tokens' => 20000, 'idle_seconds' => 60, 'source' => ['profile' => 'q'] + $src],
];
$seed = static function () use ($facts, $UUID): void {
    foreach (array_keys($facts) as $name) {
        SidecarStore::write_sidecar($name, ['workdir' => '/w/' . $name, 'agent_session_id' => $UUID, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => $facts[$name]['source']['profile']]);
        SessionStatusStore::write_status($name, ['status' => 'idle', 'mode' => 'plan']);
    }
};
$quotas = [
    'claude/work' => ['session_pct' => 10, 'week_pct' => 10, 'resets_at' => $NOW + 5000],
    'claude/q' => ['session_pct' => 95, 'week_pct' => 40, 'resets_at' => $NOW + 5000],
];
$reset = static function () use ($dir, $seed): void {
    foreach (glob($dir . '/*.sqlite*') ?: [] as $f) {
        @unlink($f);
    }
    SqliteDb::reset_connections_for_tests();
    $seed();
};

/**
 * @param array{exit?: int, stdout?: string}|null $plan what the plan command returns; null = the default `Auto-continue: on` body
 * @return array{0: array<string, mixed>, 1: object}
 */
$make = static function (?array $plan = null, ?callable $launch = null, array $receivers = ['claude/work'], ?array $claims = null) use ($NOW, $dir, $facts, $quotas): array {
    $log = new stdClass();
    $log->launch = [];
    $log->runner = [];
    $n = 0;
    $deps = [
        'clock' => static fn (): int => $NOW,
        'facts' => static fn (string $s, array $row): ?array => $facts[$s] ?? null,
        'quota' => static fn (string $k): ?array => $quotas[$k] ?? null,
        'receivers' => $receivers,
        'thresholds' => Thresholds::fromFile($dir . '/none.conf'),
        'sessions' => static fn (): array => ['src-rot'],
        'launch' => static function (array $req) use ($log, &$n, $launch): array {
            $log->launch[] = $req;
            if ($launch !== null) {
                return $launch($req);
            }
            $n++;
            $name = 'recv-' . $n;
            SidecarStore::write_sidecar($name, ['workdir' => $req['workdir'] ?? null, 'agent' => $req['agent'] ?? 'claude', 'runtime' => 'headless']);
            return ['ok' => true, 'name' => $name, 'message' => ''];
        },
        'runner' => static function (string $command, string $arg) use ($log, $plan, $claims): array {
            $log->runner[] = [$command, $arg];
            if ($command === 'fake-claims') {
                return $claims ?? ['exit' => 0, 'stdout' => '[]'];
            }
            return $plan ?? ['exit' => 0, 'stdout' => "# Plan\n\nAuto-continue: on\n"];
        },
    ];
    return [$deps, $log];
};
$gates = ['session' => 'src-rot', 'post' => true, 'plan_id' => '1766', 'no_gates' => true];
$ac = static fn (array $r): mixed => array_key_exists('auto_continue', $r) ? $r['auto_continue'] : 'absent';
$note = static fn (array $r): bool => is_string($r['auto_continue_note'] ?? null) && $r['auto_continue_note'] !== '';

echo "auto-continue: all gates met starts the receiver\n";
$reset();
[$deps, $log] = $make();
$r = FlowService::next($gates, $deps);
assert_equal(true, $r['ok'] ?? null, 'happy: ok');
assert_equal('ROTATE', $r['state'] ?? null, 'happy: the fixture decides ROTATE');
assert_equal('claude', $r['receiver']['agent'] ?? null, 'happy: the decision names a receiver');
assert_equal([true, true], [$r['auto_continue'] ?? null, $r['auto_continued'] ?? null], 'happy: auto_continue and auto_continued are true');
assert_equal('recv-1', $r['session'] ?? null, 'happy: session is the receiver name');
assert_equal(1, count($log->launch), 'happy: exactly one launch');
assert_equal(['claude', 'work'], [$log->launch[0]['agent'] ?? null, $log->launch[0]['profile'] ?? null], 'happy: the launch uses the decision receiver agent and profile');
assert_true(str_contains((string) ($log->launch[0]['prompt'] ?? ''), '1766'), 'happy: the receiver prompt is the plan prompt for plan 1766');
assert_equal(null, SessionOfferStore::get('src-rot', $NOW), 'happy: no offer is posted');
assert_equal('recv-1', SidecarStore::read_sidecar('src-rot')['rotated_to'] ?? null, 'happy: the rotation is recorded on the source (rotate() ran)');
$planCalls = array_values(array_filter($log->runner, static fn (array $c): bool => $c[0] === 'fake-plan-show'));
assert_equal([['fake-plan-show', '1766']], $planCalls, 'happy: the plan command ran once with the plan id as argument');

echo "auto-continue: the setting line\n";
$variants = [
    'plain' => ["Auto-continue: on", true],
    'lowercase' => ["auto-continue: on", true],
    'uppercase value' => ["Auto-continue: ON", true],
    'mixed case' => ["AUTO-CONTINUE: On", true],
    'surrounding spaces' => ["   Auto-continue: on   ", true],
    'among other lines' => ["# Plan\nsome text\nAuto-continue: on\nmore text\n", true],
    'off' => ["Auto-continue: off", false],
    'off then on' => ["Auto-continue: off\nbody\nAuto-continue: on", false],
    'on then off' => ["Auto-continue: on\nbody\nAuto-continue: off", false],
    'on then junk' => ["Auto-continue: on\nAuto-continue: maybe", false],
    'two on lines' => ["Auto-continue: on\nbody\nAuto-continue: on", true],
    'on inside a fenced block' => ["```\nAuto-continue: on\n```\n", false],
    'off inside a fenced block, on outside' => ["```\nAuto-continue: off\n```\nAuto-continue: on\n", true],
    'junk inside a fenced block, on outside' => ["```text\nAuto-continue: maybe\n```\nAuto-continue: on\n", true],
    'off uppercase' => ["AUTO-CONTINUE: OFF", false],
    'absent' => ["# Plan\n\nNothing about it.\n", false],
    'other value' => ["Auto-continue: yes", false],
    'on with a suffix' => ["Auto-continue: on please", false],
    'on-ish word' => ["Auto-continue: online", false],
    'empty value' => ["Auto-continue:", false],
    'not on its own line' => ["We said Auto-continue: on in chat", false],
    'no colon' => ["Auto-continue on", false],
];
foreach ($variants as $label => [$body, $expectOn]) {
    $reset();
    [$deps, $log] = $make(['exit' => 0, 'stdout' => $body]);
    $r = FlowService::next($gates, $deps);
    assert_equal([$expectOn, $expectOn, $expectOn ? 1 : 0], [$r['auto_continue'] ?? null, $r['auto_continued'] ?? false, count($log->launch)], "setting '$label': auto_continue, auto_continued and launches");
    if (!$expectOn) {
        assert_true(SessionOfferStore::get('src-rot', $NOW) !== null, "setting '$label': off posts the normal ROTATE offer");
    }
}

echo "auto-continue: the plan command fails closed\n";
$failing = [
    'exit non-zero' => ['exit' => 1, 'stdout' => "Auto-continue: on\n"],
    'exit 127' => ['exit' => 127, 'stdout' => ''],
    'empty stdout' => ['exit' => 0, 'stdout' => ''],
    'whitespace stdout' => ['exit' => 0, 'stdout' => "  \n"],
];
foreach ($failing as $label => $plan) {
    $reset();
    [$deps, $log] = $make($plan);
    $r = FlowService::next($gates, $deps);
    assert_equal(true, $r['ok'] ?? null, "plan command $label: next still answers");
    assert_equal(null, $ac($r), "plan command $label: auto_continue is null");
    assert_equal(false, $r['auto_continued'] ?? null, "plan command $label: nothing continued");
    assert_equal(0, count($log->launch), "plan command $label: no launch");
    assert_true($note($r), "plan command $label: a note explains it");
    assert_true(SessionOfferStore::get('src-rot', $NOW) !== null, "plan command $label: the normal ROTATE offer is posted");
}
$reset();
putenv('SESSIONEER_PLAN_CMD');
[$deps, $log] = $make();
$r = FlowService::next($gates, $deps);
assert_equal(null, $ac($r), 'plan command unset: auto_continue is null');
assert_equal([false, 0, []], [$r['auto_continued'] ?? null, count($log->launch), $log->runner], 'plan command unset: nothing continued and the runner is not called');
assert_true($note($r), 'plan command unset: a note explains it');
putenv('SESSIONEER_PLAN_CMD=fake-plan-show');

echo "auto-continue: each gate missing alone\n";
$missing = [
    'no post' => [['post' => null], true],
    'post false' => [['post' => false], true],
    'no plan_id' => [['plan_id' => null], null],
    'handoff instead of plan' => [['plan_id' => null, 'handoff_id' => '123'], null],
    'no_gates missing' => [['no_gates' => null], true],
    'no_gates false' => [['no_gates' => false], true],
];
foreach ($missing as $label => [$override, $expectedSetting]) {
    $reset();
    [$deps, $log] = $make();
    $request = array_filter($override + $gates, static fn ($v): bool => $v !== null);
    $r = FlowService::next($request, $deps);
    assert_equal(true, $r['ok'] ?? null, "gate '$label': ok");
    assert_equal(0, count($log->launch), "gate '$label': no launch");
    assert_true(($r['auto_continued'] ?? false) === false, "gate '$label': auto_continued is false");
    assert_true(!isset($r['session']), "gate '$label': no receiver session reported");
    assert_equal($expectedSetting, $ac($r), "gate '$label': auto_continue reports what was readable");
    assert_equal(!empty($request['post']), SessionOfferStore::get('src-rot', $NOW) !== null, "gate '$label': the offer is posted only when post is set");
}
$reset();
[$deps, $log] = $make();
$r = FlowService::next(['session' => 'src-rot', 'plan_id' => '1766', 'no_gates' => true], $deps);
assert_equal([true, 0], [$r['auto_continue'] ?? null, count($log->launch)], 'without post: auto_continue is still reported and nothing starts');
$reset();
[$deps, $log] = $make();
$r = FlowService::next(['session' => 'src-rot', 'post' => true, 'handoff_id' => '123', 'no_gates' => true], $deps);
assert_equal(null, $ac($r), 'no plan_id: auto_continue is null');
assert_equal([], $log->runner, 'no plan_id: the plan command is not run');
$reset();
[$deps, $log] = $make(null, null, []);
$r = FlowService::next($gates, $deps);
assert_equal(0, count($log->launch), 'no receiver available: no launch');
assert_true(($r['receiver'] ?? null) === null, 'no receiver available: the decision has no receiver');
assert_true(($r['auto_continued'] ?? false) === false, 'no receiver available: auto_continued is false');
$reset();
[$deps, $log] = $make();
$r = FlowService::next(['session' => 'src-ctx'] + $gates, $deps);
assert_equal('ROTATE', $r['state'] ?? null, 'context rotate: ROTATE');
assert_true(array_key_exists('receiver', $r) && $r['receiver'] === null, 'context rotate: no receiver');
assert_equal(0, count($log->launch), 'context ROTATE with a null receiver: no launch');

echo "auto-continue: every non-ROTATE state never starts a receiver\n";
$states = [
    'GO' => ['src-go', null, 'GO'],
    'WARM_TIGHT' => ['src-warm', null, 'WARM_TIGHT'],
    'CHECKPOINT' => ['src-warm', 'step-end', 'CHECKPOINT'],
    'WAIT' => ['src-go', 'wait', 'WAIT'],
    'WAIT beats quota rotate' => ['src-rot', 'wait', 'WAIT'],
    'DONE' => ['src-go', 'done', 'DONE'],
    'DONE beats rotate' => ['src-rot', 'done', 'DONE'],
    'UNKNOWN' => ['ghost', null, 'UNKNOWN'],
];
foreach ($states as $label => [$session, $intent, $expected]) {
    $reset();
    [$deps, $log] = $make();
    $r = FlowService::next(['session' => $session, 'intent' => $intent] + $gates, $deps);
    assert_equal($expected, $r['state'] ?? null, "$label: the fixture decides $expected");
    assert_equal(0, count($log->launch), "$label: no launch");
    assert_true(($r['auto_continued'] ?? false) === false, "$label: auto_continued is false");
    assert_true(SessionOfferStore::get($session, $NOW) === null, "$label: no offer is posted");
}
$reset();
[$deps, $log] = $make(null, null, []);
$r = FlowService::next(['session' => 'src-park'] + $gates, $deps);
assert_equal('PARK', $r['state'] ?? null, 'PARK: the fixture decides PARK (quota 95, no receiver)');
assert_equal(0, count($log->launch), 'PARK: no launch');
assert_true(($r['auto_continued'] ?? false) === false, 'PARK: auto_continued is false');
assert_true(SessionOfferStore::get('src-park', $NOW) !== null, 'PARK: the PARK offer is still posted');
$reset();
SessionOfferStore::post('src-go', 'hook', ['state' => 'ROTATE', 'why' => 'x', 'receiver' => null, 'trigger' => 'context'], null, null, null, $NOW);
[$deps, $log] = $make();
FlowService::next(['session' => 'src-go'] + $gates, $deps);
assert_equal(null, SessionOfferStore::get('src-go', $NOW), 'GO with gates: a stale hook offer is cleared as before');

echo "auto-continue: rotate refuses or fails, the offer is posted\n";
$reset();
[$deps, $log] = $make(null, null, ['claude/work'], ['exit' => 0, 'stdout' => '[{"id":"9"}]']);
$r = FlowService::next($gates, $deps);
assert_equal(true, $r['ok'] ?? null, 'claims held: next still answers ok');
assert_equal([true, false], [$r['auto_continue'] ?? null, $r['auto_continued'] ?? null], 'claims held: auto_continued is false');
assert_equal(0, count($log->launch), 'claims held: no launch');
assert_true(str_contains((string) ($r['auto_continue_note'] ?? ''), 'claims'), 'claims held: the note carries the rotate message');
$offer = SessionOfferStore::get('src-rot', $NOW);
assert_true($offer !== null, 'claims held: the normal ROTATE offer is posted');
assert_equal('1766', (string) ($offer['plan_id'] ?? ''), 'claims held: the offer carries the plan id');
$reset();
[$deps, $log] = $make(null, null, ['claude/work'], ['exit' => 1, 'stdout' => '']);
$r = FlowService::next($gates, $deps);
assert_equal([true, false, 0], [$r['auto_continue'] ?? null, $r['auto_continued'] ?? null, count($log->launch)], 'claims command failing: nothing starts');
assert_true($note($r), 'claims command failing: a note explains it');
assert_true(SessionOfferStore::get('src-rot', $NOW) !== null, 'claims command failing: the normal ROTATE offer is posted');
$reset();
[$deps, $log] = $make(null, null, ['claude/work'], ['exit' => 0, 'stdout' => 'not json']);
$r = FlowService::next($gates, $deps);
assert_equal([false, 0], [$r['auto_continued'] ?? null, count($log->launch)], 'claims command unreadable output: nothing starts');
putenv('SESSIONEER_CLAIMS_CMD');
$reset();
[$deps, $log] = $make();
$r = FlowService::next($gates, $deps);
assert_equal([true, false, 0], [$r['auto_continue'] ?? null, $r['auto_continued'] ?? null, count($log->launch)], 'claims command unset: fails closed, no launch');
assert_true(stripos((string) ($r['auto_continue_note'] ?? ''), 'not configured') !== false || str_contains((string) ($r['auto_continue_note'] ?? ''), 'SESSIONEER_CLAIMS_CMD'), 'claims command unset: the note says the claims check is not configured');
assert_true(SessionOfferStore::get('src-rot', $NOW) !== null, 'claims command unset: the normal ROTATE offer is posted');
assert_equal([], array_values(array_filter($log->runner, static fn (array $c): bool => $c[0] === 'fake-claims')), 'claims command unset: no claims runner call');
putenv('SESSIONEER_CLAIMS_CMD=fake-claims');
$reset();
[$deps, $log] = $make(null, static fn (array $req): array => ['ok' => false, 'message' => 'boom: no tmux']);
$r = FlowService::next($gates, $deps);
assert_equal([true, false], [$r['auto_continue'] ?? null, $r['auto_continued'] ?? null], 'launch error: auto_continued is false');
assert_equal(1, count($log->launch), 'launch error: one launch was attempted');
assert_true(str_contains((string) ($r['auto_continue_note'] ?? ''), 'boom: no tmux'), 'launch error: the note carries the message');
assert_true(!isset($r['session']), 'launch error: no receiver session reported');
assert_true(SessionOfferStore::get('src-rot', $NOW) !== null, 'launch error: the normal ROTATE offer is posted');
$reset();
[$deps, $log] = $make(null, static function (array $req): array {
    throw new RuntimeException('launcher exploded');
});
try {
    $r = FlowService::next($gates, $deps);
    $thrown = false;
} catch (Throwable $e) {
    $thrown = true;
    $r = [];
}
assert_equal(false, $thrown, 'launch throws: nothing escapes next');
assert_equal(false, $r['auto_continued'] ?? null, 'launch throws: auto_continued is false');
assert_true(str_contains((string) ($r['auto_continue_note'] ?? ''), 'launcher exploded'), 'launch throws: the note carries the exception message');
assert_true(SessionOfferStore::get('src-rot', $NOW) !== null, 'launch throws: the normal ROTATE offer is posted');

echo "auto-continue: no duplicate receivers\n";
$reset();
[$deps, $log] = $make();
$first = FlowService::next($gates, $deps);
$second = FlowService::next($gates, $deps);
assert_equal(true, $first['auto_continued'] ?? null, 'duplicate: the first call starts a receiver');
assert_equal(1, count($log->launch), 'duplicate: two calls in a row give exactly one launch');
assert_equal(false, $second['auto_continued'] ?? null, 'duplicate: the second call does not continue');
assert_true(str_contains((string) ($second['auto_continue_note'] ?? ''), 'recv-1'), 'duplicate: the note says already rotated to the receiver');
assert_equal(null, SessionOfferStore::get('src-rot', $NOW), 'duplicate: no offer is posted');
assert_true(!isset($second['session']), 'duplicate: no session reported');

echo "auto-continue: strict gates\n";
foreach (['post', 'no_gates'] as $key) {
    foreach (['false', 'off', '1', 'true', 'yes', 1, 'on'] as $value) {
        $reset();
        [$deps, $log] = $make();
        $r = FlowService::next([$key => $value] + $gates, $deps);
        assert_equal(0, count($log->launch), "strict: $key=" . var_export($value, true) . ' does not start a receiver');
        assert_true(($r['auto_continued'] ?? false) === false, "strict: $key=" . var_export($value, true) . ' auto_continued false');
    }
}

echo "auto-continue: all mode never continues\n";
$reset();
[$deps, $log] = $make();
$r = FlowService::next(['all' => true, 'post' => true, 'plan_id' => '1766', 'no_gates' => true], $deps);
assert_equal(true, $r['ok'] ?? null, 'all: ok');
assert_equal(0, count($log->launch), 'all: no launch');
assert_equal(null, SessionOfferStore::get('src-rot', $NOW), 'all: no offer');

echo "ok\n";
test_exit();
