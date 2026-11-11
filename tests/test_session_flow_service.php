<?php

declare(strict_types=1);

// Step 4 of session-flow: HostAgent\SessionFlow\FlowService (next, offer, offer_dismiss, overlay).
// Written before the implementation: fails until the class exists.
// Isolation: a temp SQLite file for the offer store, injected fakes for facts, quota and clock.
//
// Deps shape pinned here (the Contract names the dependencies but not their signatures):
//   'clock'      callable(): int                       now, epoch seconds
//   'facts'      callable(string $session, array $row): ?array
//                  null = unreadable session; else ['context_tokens' => ?int, 'idle_seconds' => ?int,
//                  'source' => ['agent' => string, 'profile' => string, 'status' => string]]
//   'quota'      callable(string $key): ?array         $key is "agent/profile"; returns
//                  ['session_pct' => ?int, 'week_pct' => ?int, 'resets_at' => ?int] or null
//   'receivers'  list<string>                          candidate "agent/profile" keys, in preference order
//   'thresholds' HostAgent\SessionFlow\Thresholds      defaults (CTX_GO_MAX 150000, CTX_ROTATE 250000,
//                  QUOTA_GO_MAX 80, QUOTA_PARK 90, IDLE_MIN 60 minutes)
//   The store is the static SessionOfferStore (isolated through PUSH_SQLITE_FILE etc.).
// Handled failures are ['ok' => false, 'message' => string]; successes carry 'ok' => true.

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

$dir = sys_get_temp_dir() . '/sessioneer-test-flow-service-' . getmypid();
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
use HostAgent\SessionFlow\PromptBuilder;
use HostAgent\SessionFlow\Thresholds;
use HostAgent\Stores\SessionOfferStore;

$NOW = 2_000_000;
$thresholds = Thresholds::fromFile($dir . '/none.conf');

$facts = [
    'lowctx' => ['context_tokens' => 20000, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']],
    'bigctx' => ['context_tokens' => 260000, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']],
    'highquota' => ['context_tokens' => 20000, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']],
    'cold' => ['context_tokens' => 20000, 'idle_seconds' => 4000, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']],
];
$quotas = [
    'claude/work' => ['session_pct' => 10, 'week_pct' => 10, 'resets_at' => $NOW + 5000],
    'codex/main' => ['session_pct' => 5, 'week_pct' => 5, 'resets_at' => null],
];

/** @return array{0: array<string, mixed>, 1: object} deps plus a call log */
$make = static function (array $factsBySession, array $quotaByKey) use ($NOW, $thresholds): array {
    $log = new stdClass();
    $log->quota = [];
    $log->facts = [];
    $deps = [
        'clock' => static fn (): int => $NOW,
        'facts' => static function (string $s, array $row) use ($factsBySession, $log): ?array {
            $log->facts[] = $s;
            return $factsBySession[$s] ?? null;
        },
        'quota' => static function (string $k) use ($quotaByKey, $log): ?array {
            $log->quota[] = $k;
            return $quotaByKey[$k] ?? null;
        },
        'receivers' => ['codex/main', 'claude/work'],
        'thresholds' => $thresholds,
    ];
    return [$deps, $log];
};
$reset = static function () use ($dir): void {
    foreach (glob($dir . '/*.sqlite*') ?: [] as $f) {
        @unlink($f);
    }
    \HostAgent\Stores\SqliteDb::reset_connections_for_tests();
};

echo "next: decision shape\n";
[$deps, $log] = $make(['s-low' => $facts['lowctx']], ['claude/work' => $quotas['claude/work']]);
$r = FlowService::next(['session' => 's-low'], $deps);
foreach (['state', 'why', 'trigger', 'close_call', 'receiver', 'reset_at', 'needs_approval', 'warnings', 'source', 'facts'] as $k) {
    assert_true(array_key_exists($k, $r), "next: reply has '$k'");
}
assert_equal('GO', $r['state'] ?? null, 'next: low context and quota is GO');
assert_equal(false, array_key_exists('prompt', $r), 'next: no prompt without handoff_id or plan_id');
assert_equal(['claude/work'], $log->quota, 'next: only the source agent quota is read when the state is GO');
assert_equal('claude', $r['source']['agent'] ?? null, 'next: source carries the agent');
assert_equal(20000, $r['facts']['context_tokens'] ?? null, 'next: facts carry the context tokens');

echo "next: prompt\n";
$r = FlowService::next(['session' => 's-low', 'handoff_id' => '123'], $deps);
assert_equal(PromptBuilder::handoff('123'), $r['prompt'] ?? null, 'next: handoff_id gives the handoff prompt');
assert_equal('handoff', $r['prompt_kind'] ?? null, 'next: prompt_kind handoff');
$r = FlowService::next(['session' => 's-low', 'plan_id' => '456'], $deps);
assert_equal(PromptBuilder::plan('456'), $r['prompt'] ?? null, 'next: plan_id gives the plan prompt');
assert_equal('plan', $r['prompt_kind'] ?? null, 'next: prompt_kind plan');
foreach (['12a', '', '1 2', "1\n2"] as $bad) {
    $r = FlowService::next(['session' => 's-low', 'handoff_id' => $bad], $deps);
    assert_equal(false, $r['ok'] ?? null, 'next: handoff_id ' . json_encode($bad) . ' is a handled error');
}
$r = FlowService::next(['session' => 's-low', 'plan_id' => 'abc'], $deps);
assert_equal(false, $r['ok'] ?? null, 'next: plan_id abc is a handled error');
assert_true(is_string($r['message'] ?? null), 'next: the error has a message');

echo "next: states and quota reads\n";
$reset();
[$deps, $log] = $make(['s-big' => $facts['bigctx']], $quotas);
$r = FlowService::next(['session' => 's-big'], $deps);
assert_equal(['ROTATE', 'context'], [$r['state'] ?? null, $r['trigger'] ?? null], 'next: 260000 tokens is ROTATE by context');
assert_equal('claude/work', $log->quota[0] ?? null, 'next: the source quota is read first');
assert_true(count($log->quota) >= 2, 'next: receiver quota is read once the state is ROTATE');
assert_true(in_array('codex/main', $log->quota, true), 'next: the candidate receiver codex/main was read');

$reset();
[$deps, $log] = $make(['s-q' => $facts['highquota']], ['claude/work' => ['session_pct' => 95, 'week_pct' => 40, 'resets_at' => $NOW + 600]] + $quotas);
$r = FlowService::next(['session' => 's-q'], $deps);
assert_equal(['ROTATE', 'quota', 'codex'], [$r['state'] ?? null, $r['trigger'] ?? null, $r['receiver']['agent'] ?? null], 'next: source quota 95% with room at codex is ROTATE to codex');
assert_equal(true, $r['needs_approval'] ?? null, 'next: a quota rotate needs approval');
assert_equal(true, $r['close_call'] ?? null, 'next: reset within the hour is a close call');

$reset();
[$deps, $log] = $make(['s-q' => $facts['highquota']], ['claude/work' => ['session_pct' => 95, 'week_pct' => 40, 'resets_at' => $NOW + 9000], 'codex/main' => ['session_pct' => 99, 'week_pct' => 99, 'resets_at' => null]]);
$r = FlowService::next(['session' => 's-q'], $deps);
assert_equal('PARK', $r['state'] ?? null, 'next: no receiver with room is PARK');
assert_equal($NOW + 9000, $r['reset_at'] ?? null, 'next: PARK carries the source reset time');

$r = FlowService::next(['session' => 'ghost'], $deps);
assert_equal('UNKNOWN', $r['state'] ?? null, 'next: an unknown session (no facts) is UNKNOWN');
$reset();
$r = FlowService::next(['session' => 'ghost', 'post' => true], $deps);
assert_equal(null, SessionOfferStore::get('ghost', $NOW), 'next: post=true on an unknown session stores nothing');

echo "next: post=true\n";
$reset();
[$deps, $log] = $make(['s-big' => $facts['bigctx'], 's-low' => $facts['lowctx']], $quotas);
assert_equal(null, SessionOfferStore::get('s-big', $NOW), 'next: nothing stored before');
FlowService::next(['session' => 's-big'], $deps);
assert_equal(null, SessionOfferStore::get('s-big', $NOW), 'next: without post, nothing is stored even for ROTATE');
$r = FlowService::next(['session' => 's-big', 'post' => true, 'handoff_id' => '99'], $deps);
$o = SessionOfferStore::get('s-big', $NOW);
assert_equal('hook', $o['origin'] ?? null, 'next: post=true on ROTATE stores a hook offer');
assert_equal('ROTATE', $o['decision']['state'] ?? null, 'next: the stored decision is the returned one');
assert_equal('99', $o['handoff_id'] ?? null, 'next: the handoff id is stored');
assert_equal(PromptBuilder::handoff('99'), $o['prompt'] ?? null, 'next: the built prompt is stored');
FlowService::next(['session' => 's-low', 'post' => true], $deps);
assert_equal(null, SessionOfferStore::get('s-low', $NOW), 'next: post=true on GO stores nothing');
SessionOfferStore::post('s-low', 'hook', ['state' => 'ROTATE'], null, null, null, $NOW - 10);
FlowService::next(['session' => 's-low', 'post' => true], $deps);
assert_equal(null, SessionOfferStore::get('s-low', $NOW), 'next: post=true on GO clears a stale hook offer');
SessionOfferStore::post('s-low', 'agent', ['state' => 'ROTATE'], null, null, 'mine', $NOW - 10);
FlowService::next(['session' => 's-low', 'post' => true], $deps);
assert_equal('agent', SessionOfferStore::get('s-low', $NOW)['origin'] ?? null, 'next: GO never clears an agent offer');
SessionOfferStore::post('s-big', 'agent', ['state' => 'ROTATE'], null, null, 'agent wins', $NOW - 10);
FlowService::next(['session' => 's-big', 'post' => true], $deps);
assert_equal('agent wins', SessionOfferStore::get('s-big', $NOW)['prompt'] ?? null, 'next: a ROTATE hook post never overwrites a live agent offer');
$reset();
[$deps, $log] = $make(['s-q' => $facts['highquota']], ['claude/work' => ['session_pct' => 95, 'week_pct' => 40, 'resets_at' => null], 'codex/main' => ['session_pct' => 99, 'week_pct' => 99, 'resets_at' => null]]);
FlowService::next(['session' => 's-q', 'post' => true], $deps);
assert_equal('PARK', SessionOfferStore::get('s-q', $NOW)['decision']['state'] ?? null, 'next: post=true on PARK stores a hook offer');
$reset();
[$deps, $log] = $make(['s-cold' => $facts['cold']], $quotas);
$r = FlowService::next(['session' => 's-cold', 'post' => true], $deps);
assert_equal('GO', $r['state'] ?? null, 'next: a cold session with a small context stays GO');

echo "offer\n";
$reset();
[$deps, $log] = $make(['me' => $facts['bigctx'], 'other' => $facts['bigctx']], $quotas);
$r = FlowService::offer(['self' => 'me', 'session' => 'other', 'handoff_id' => '77'], $deps);
assert_equal(true, $r['ok'] ?? null, 'offer: ok');
assert_equal(null, SessionOfferStore::get('other', $NOW), 'offer: another session named in the request gets no offer');
$o = SessionOfferStore::get('me', $NOW);
assert_equal('agent', $o['origin'] ?? null, 'offer: stored for the caller as an agent offer');
assert_equal('77', $o['handoff_id'] ?? null, 'offer: handoff id stored');
assert_equal('ROTATE', $o['decision']['state'] ?? null, 'offer: missing decision is filled from the current decision');
assert_equal(PromptBuilder::handoff('77'), $o['prompt'] ?? null, 'offer: a missing prompt is built from the handoff id');
$r = FlowService::offer(['self' => 'me', 'handoff_id' => '78', 'prompt' => 'Custom prompt'], $deps);
assert_equal('Custom prompt', SessionOfferStore::get('me', $NOW)['prompt'] ?? null, 'offer: an explicit prompt is kept');
assert_equal('78', SessionOfferStore::get('me', $NOW)['handoff_id'] ?? null, 'offer: an agent offer overwrites an agent offer');
SessionOfferStore::clear('me');
SessionOfferStore::post('me', 'hook', ['state' => 'PARK'], null, null, 'hook', $NOW - 5);
FlowService::offer(['self' => 'me', 'plan_id' => '5'], $deps);
assert_equal(['agent', '5'], [SessionOfferStore::get('me', $NOW)['origin'] ?? null, SessionOfferStore::get('me', $NOW)['plan_id'] ?? null], 'offer: overwrites a hook offer');
$r = FlowService::offer(['self' => 'me', 'handoff_id' => 'x1'], $deps);
assert_equal(false, $r['ok'] ?? null, 'offer: an invalid id is a handled error');
assert_equal('5', SessionOfferStore::get('me', $NOW)['plan_id'] ?? null, 'offer: a failed offer leaves the earlier one');
$r = FlowService::offer(['self' => 'me', 'prompt' => str_repeat('a', 8001)], $deps);
assert_equal(false, $r['ok'] ?? null, 'offer: an oversized prompt is a handled error');
$r = FlowService::offer(['self' => 'ghost'], $deps);
assert_equal(false, $r['ok'] ?? null, 'offer: an unknown session (no facts) is a handled error');
assert_equal(null, SessionOfferStore::get('ghost', $NOW), 'offer: nothing stored for an unknown session');
$r = FlowService::offer(['handoff_id' => '1'], $deps);
assert_equal(false, $r['ok'] ?? null, 'offer: a request without self is a handled error');
$r = FlowService::offer(['self' => '', 'handoff_id' => '1'], $deps);
assert_equal(false, $r['ok'] ?? null, 'offer: an empty self is a handled error');

echo "offer_dismiss\n";
$r = FlowService::offer_dismiss(['self' => 'me'], $deps);
assert_equal(true, $r['ok'] ?? null, 'offer_dismiss: ok on a live offer');
assert_equal(null, SessionOfferStore::get('me', $NOW), 'offer_dismiss: the offer is no longer live');
$r = FlowService::offer_dismiss(['self' => 'me'], $deps);
assert_equal(false, $r['ok'] ?? null, 'offer_dismiss: nothing live to dismiss is a handled error');
$r = FlowService::offer_dismiss(['self' => 'ghost'], $deps);
assert_equal(false, $r['ok'] ?? null, 'offer_dismiss: unknown session is a handled error');

echo "overlay\n";
$reset();
$rows = [
    ['name' => 'o-active', 'status' => 'working', 'activity' => $NOW - 10, 'agent' => 'claude', 'profile' => 'work', 'starred' => true],
    ['name' => 'o-fresh', 'status' => 'idle', 'activity' => $NOW - 120, 'agent' => 'claude', 'profile' => 'work'],
];
[$deps, $log] = $make(['o-active' => $facts['lowctx'], 'o-fresh' => $facts['lowctx']], $quotas);
$out = FlowService::overlay($rows, $deps);
assert_equal(0, count($log->quota), 'overlay: no row passes the cheap pre-check, so zero quota calls');
assert_equal(0, count($log->facts), 'overlay: and the facts reader is not called either');
assert_equal(['o-active', 'o-fresh'], array_column($out, 'name'), 'overlay: order and count are kept');
foreach ($out as $row) {
    assert_true(array_key_exists('offer', $row) && $row['offer'] === null, "overlay: {$row['name']} has offer = null");
    assert_true(!isset($row['flow']), "overlay: {$row['name']} has no flow");
}
assert_equal(true, $out[0]['starred'] ?? null, 'overlay: fields the overlay does not own are untouched');
assert_equal($rows[1]['activity'], $out[1]['activity'], 'overlay: activity is untouched');
assert_equal([], FlowService::overlay([], $deps), 'overlay: an empty list stays empty');
assert_equal(0, count($log->quota), 'overlay: an empty list makes zero quota calls');

$rows = [
    ['name' => 'o-idle', 'status' => 'idle', 'activity' => $NOW - 3700, 'agent' => 'claude', 'profile' => 'work'],
    ['name' => 'o-bigctx', 'status' => 'idle', 'activity' => $NOW - 30, 'agent' => 'claude', 'profile' => 'work', 'context_tokens' => 160000],
    ['name' => 'o-busy', 'status' => 'working', 'activity' => $NOW - 9999, 'agent' => 'claude', 'profile' => 'work', 'context_tokens' => 200000],
    ['name' => 'o-edge', 'status' => 'idle', 'activity' => $NOW - 3600, 'agent' => 'claude', 'profile' => 'work'],
    ['name' => 'o-nearly', 'status' => 'idle', 'activity' => $NOW - 3599, 'agent' => 'claude', 'profile' => 'work', 'context_tokens' => 149999],
];
$f = ['context_tokens' => 20000, 'idle_seconds' => 4000, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']];
[$deps, $log] = $make(['o-idle' => $f, 'o-bigctx' => ['idle_seconds' => 30, 'context_tokens' => 160000] + $f, 'o-busy' => $f, 'o-edge' => $f, 'o-nearly' => $f], $quotas);
$before = $rows;
$out = FlowService::overlay($rows, $deps);
assert_equal($before, $rows, 'overlay: the input array is not mutated');
assert_equal(true, isset($out[0]['flow']['state']), 'overlay: an idle row idle for 61 minutes gets a flow');
assert_equal(true, isset($out[1]['flow']['state']), 'overlay: an idle row with stored context >= CTX_GO_MAX gets a flow');
assert_equal(false, isset($out[2]['flow']), 'overlay: a working row never gets a flow, whatever its context');
assert_equal(true, isset($out[3]['flow']['state']), 'overlay: idle for exactly IDLE_MIN minutes passes');
assert_equal(false, isset($out[4]['flow']), 'overlay: 59m59s idle with context just below CTX_GO_MAX does not pass');
assert_equal(['o-idle', 'o-bigctx', 'o-busy', 'o-edge', 'o-nearly'], array_column($out, 'name'), 'overlay: order kept');
assert_equal(['o-idle', 'o-bigctx', 'o-edge'], $log->facts, 'overlay: the facts reader runs only for rows that pass');
assert_equal(['claude/work', 'claude/work', 'claude/work'], $log->quota, 'overlay: a GO decision reads only the source quota, once per passing row');

echo "overlay: offers\n";
$reset();
$rows = [
    ['name' => 'f-1', 'status' => 'idle', 'activity' => $NOW - 10, 'agent' => 'claude', 'profile' => 'work'],
    ['name' => 'f-2', 'status' => 'working', 'activity' => $NOW - 10, 'agent' => 'codex', 'profile' => 'main'],
];
[$deps, $log] = $make([], $quotas);
$none = FlowService::overlay($rows, $deps);
assert_equal(null, $none[0]['offer'], 'overlay: no offer yet');
SessionOfferStore::post('f-1', 'agent', ['state' => 'ROTATE'], '9', null, 'go on', $NOW - 5);
$one = FlowService::overlay($rows, $deps);
assert_equal(['agent', '9', 'go on'], [$one[0]['offer']['origin'] ?? null, $one[0]['offer']['handoff_id'] ?? null, $one[0]['offer']['prompt'] ?? null], 'overlay: a new offer shows on the very next call');
assert_equal(null, $one[1]['offer'], 'overlay: other rows have no offer');
SessionOfferStore::post('f-2', 'hook', ['state' => 'PARK'], null, null, null, $NOW - 5);
assert_equal('hook', FlowService::overlay($rows, $deps)[1]['offer']['origin'] ?? null, 'overlay: an offer shows on a working row too (offer is independent of the pre-check)');
SessionOfferStore::dismiss('f-1', $NOW);
assert_equal(null, FlowService::overlay($rows, $deps)[0]['offer'], 'overlay: a dismissed offer disappears');
SessionOfferStore::post('f-1', 'agent', ['state' => 'ROTATE'], null, null, 'x', $NOW - 86400);
assert_equal(null, FlowService::overlay($rows, $deps)[0]['offer'], 'overlay: an offer posted exactly 24h ago is expired');
$odd = [['status' => 'idle'], ['name' => 123]];
assert_equal(2, count(FlowService::overlay($odd, $deps)), 'overlay: rows without a usable name do not break it');

echo "overlay: passing row with high quota reads receivers\n";
$reset();
[$deps, $log] = $make(['q-1' => $f], ['claude/work' => ['session_pct' => 95, 'week_pct' => 10, 'resets_at' => $NOW + 100], 'codex/main' => ['session_pct' => 5, 'week_pct' => 5, 'resets_at' => null]]);
$out = FlowService::overlay([['name' => 'q-1', 'status' => 'idle', 'activity' => $NOW - 4000, 'agent' => 'claude', 'profile' => 'work']], $deps);
assert_equal('ROTATE', $out[0]['flow']['state'] ?? null, 'overlay: source quota 95% with a receiver gives ROTATE');
assert_equal('claude/work', $log->quota[0] ?? null, 'overlay: source quota first');
assert_true(in_array('codex/main', $log->quota, true), 'overlay: receiver quota read because the state would be ROTATE');

echo "ok\n";
test_exit();
