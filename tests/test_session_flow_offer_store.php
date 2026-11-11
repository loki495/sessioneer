<?php

declare(strict_types=1);

// Step 4 of session-flow: HostAgent\Stores\SessionOfferStore (table session_offers, one row per
// session). Written before the implementation: fails until the class exists.
// Isolation: every SQLite path the host agent can use points at a temp dir; no real database.

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

$dir = sys_get_temp_dir() . '/sessioneer-test-offer-store-' . getmypid();
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

use HostAgent\Stores\SessionOfferStore;
use HostAgent\Stores\SqliteDb;

$decision = ['state' => 'ROTATE', 'why' => 'context at 260000 tokens', 'trigger' => 'context', 'close_call' => false, 'receiver' => null, 'reset_at' => null, 'needs_approval' => false, 'warnings' => []];
$T = 1_000_000;
$DAY = 86400;
$threw = static function (callable $f): bool {
    try {
        $f();
    } catch (\InvalidArgumentException) {
        return true;
    }
    return false;
};

echo "post and get\n";
assert_equal(null, SessionOfferStore::get('s1', $T), 'unknown session: no offer');
assert_equal(true, SessionOfferStore::post('s1', 'hook', $decision, '123', '456', 'Continue from the handoff', $T), 'a hook offer on an empty row is stored');
$o = SessionOfferStore::get('s1', $T + 5);
assert_equal('s1', $o['session_name'] ?? null, 'row: session_name');
assert_equal('hook', $o['origin'] ?? null, 'row: origin');
assert_equal($decision, $o['decision'] ?? null, 'row: decision is the decoded array (stored as decision_json)');
assert_equal('123', $o['handoff_id'] ?? null, 'row: handoff_id');
assert_equal('456', $o['plan_id'] ?? null, 'row: plan_id');
assert_equal('Continue from the handoff', $o['prompt'] ?? null, 'row: prompt');
assert_equal($T, $o['created_at'] ?? null, 'row: created_at is the post time');
assert_equal($T + $DAY, $o['expires_at'] ?? null, 'row: expires_at is created_at + 24h');
assert_true(array_key_exists('dismissed_at', $o) && $o['dismissed_at'] === null, 'row: dismissed_at null');
assert_true(array_key_exists('launched_at', $o) && $o['launched_at'] === null, 'row: launched_at null');
assert_equal(null, SessionOfferStore::get('other', $T), 'offers are per session');
assert_true(SessionOfferStore::post('s-null', 'agent', $decision, null, null, null, $T), 'null ids and prompt are allowed');
$n = SessionOfferStore::get('s-null', $T);
assert_true(array_key_exists('handoff_id', $n) && array_key_exists('plan_id', $n) && array_key_exists('prompt', $n) && [$n['handoff_id'], $n['plan_id'], $n['prompt']] === [null, null, null], 'null ids and prompt read back as null');

echo "expiry at the 24h boundary\n";
assert_true(SessionOfferStore::get('s1', $T + $DAY - 1) !== null, 'one second before 24h: live');
assert_equal(null, SessionOfferStore::get('s1', $T + $DAY), 'at exactly 24h: expired');
assert_equal(null, SessionOfferStore::get('s1', $T + $DAY + 1000), 'after 24h: expired');

echo "precedence\n";
$hook = ['state' => 'PARK'] + $decision;
assert_equal(true, SessionOfferStore::post('p1', 'hook', $decision, null, null, 'first hook', $T), 'p1: hook posts');
assert_equal(true, SessionOfferStore::post('p1', 'hook', $hook, null, null, 'second hook', $T + 10), 'a hook post overwrites a hook offer');
assert_equal('second hook', SessionOfferStore::get('p1', $T + 11)['prompt'] ?? null, 'the newer hook offer is the stored one');
assert_equal(true, SessionOfferStore::post('p1', 'agent', $decision, '7', null, 'agent says', $T + 20), 'an agent post overwrites a hook offer');
assert_equal('agent', SessionOfferStore::get('p1', $T + 21)['origin'] ?? null, 'origin is now agent');
assert_equal(false, SessionOfferStore::post('p1', 'hook', $hook, null, null, 'hook again', $T + 30), 'a hook post never overwrites a live agent offer');
$kept = SessionOfferStore::get('p1', $T + 31);
assert_equal(['agent', 'agent says', '7'], [$kept['origin'] ?? null, $kept['prompt'] ?? null, $kept['handoff_id'] ?? null], 'the agent offer is untouched after the refused hook post');
assert_equal(true, SessionOfferStore::post('p1', 'agent', $decision, '8', null, 'agent v2', $T + 40), 'an agent post overwrites an agent offer');
assert_equal(['agent v2', '8', $T + 40, $T + 40 + $DAY], [SessionOfferStore::get('p1', $T + 41)['prompt'] ?? null, SessionOfferStore::get('p1', $T + 41)['handoff_id'] ?? null, SessionOfferStore::get('p1', $T + 41)['created_at'] ?? null, SessionOfferStore::get('p1', $T + 41)['expires_at'] ?? null], 'overwrite restarts created_at and the 24h clock');
assert_equal(true, SessionOfferStore::post('p1', 'hook', $hook, null, null, 'after expiry', $T + 40 + $DAY), 'a hook post replaces an expired agent offer (not live)');
assert_equal('hook', SessionOfferStore::get('p1', $T + 41 + $DAY)['origin'] ?? null, 'the hook offer is now stored');
SessionOfferStore::post('p2', 'agent', $decision, null, null, 'a', $T);
SessionOfferStore::dismiss('p2', $T + 1);
assert_equal(true, SessionOfferStore::post('p2', 'hook', $decision, null, null, 'h', $T + 2), 'a hook post replaces a dismissed agent offer (not live)');
assert_equal('hook', SessionOfferStore::get('p2', $T + 3)['origin'] ?? null, 'p2: the hook offer is live');
SessionOfferStore::post('p3', 'agent', $decision, null, null, 'a', $T);
SessionOfferStore::claimLaunch('p3', $T + 1);
assert_equal(true, SessionOfferStore::post('p3', 'hook', $decision, null, null, 'h', $T + 2), 'a hook post replaces a launched agent offer (not live)');

echo "re-post revives\n";
SessionOfferStore::post('r1', 'agent', $decision, null, null, 'one', $T);
SessionOfferStore::dismiss('r1', $T + 1);
assert_equal(null, SessionOfferStore::get('r1', $T + 2), 'dismissed: not returned');
assert_equal(true, SessionOfferStore::post('r1', 'agent', $decision, null, null, 'two', $T + 3), 'an agent post after a dismissal is accepted');
$r = SessionOfferStore::get('r1', $T + 4);
assert_true(($r['prompt'] ?? null) === 'two' && array_key_exists('dismissed_at', $r) && $r['dismissed_at'] === null && array_key_exists('launched_at', $r) && $r['launched_at'] === null, 'the new offer is live with dismissed_at and launched_at reset');

echo "dismiss\n";
SessionOfferStore::post('d1', 'agent', $decision, null, null, 'x', $T);
assert_equal(true, SessionOfferStore::dismiss('d1', $T + 1), 'dismissing a live offer returns true');
assert_equal(null, SessionOfferStore::get('d1', $T + 2), 'a dismissed offer is not live');
assert_equal(false, SessionOfferStore::dismiss('d1', $T + 3), 'dismissing again returns false');
assert_equal(false, SessionOfferStore::dismiss('never-posted', $T), 'dismissing an unknown session returns false');
SessionOfferStore::post('d2', 'agent', $decision, null, null, 'x', $T);
assert_equal(false, SessionOfferStore::dismiss('d2', $T + $DAY), 'dismissing an expired offer returns false');

echo "claimLaunch\n";
SessionOfferStore::post('c1', 'agent', $decision, '5', null, 'go', $T);
assert_equal(true, SessionOfferStore::claimLaunch('c1', $T + 1), 'the first claim wins');
assert_equal(false, SessionOfferStore::claimLaunch('c1', $T + 2), 'the second claim returns false');
assert_equal(null, SessionOfferStore::get('c1', $T + 3), 'a launched offer is not live');
SqliteDb::reset_connections_for_tests();
assert_equal(false, SessionOfferStore::claimLaunch('c1', $T + 4), 'a fresh connection (another process) also loses the claim');
assert_equal(false, SessionOfferStore::claimLaunch('unknown', $T), 'claiming an unknown session returns false');
SessionOfferStore::post('c2', 'agent', $decision, null, null, 'x', $T);
SessionOfferStore::dismiss('c2', $T + 1);
assert_equal(false, SessionOfferStore::claimLaunch('c2', $T + 2), 'a dismissed offer cannot be claimed');
SessionOfferStore::post('c3', 'agent', $decision, null, null, 'x', $T);
assert_equal(false, SessionOfferStore::claimLaunch('c3', $T + $DAY), 'an expired offer cannot be claimed');
SessionOfferStore::post('c4', 'agent', $decision, null, null, 'x', $T);
assert_equal(true, SessionOfferStore::claimLaunch('c4', $T + $DAY - 1), 'one second before 24h the claim still works');
SessionOfferStore::post('c5', 'agent', $decision, null, null, 'x', $T);
SessionOfferStore::claimLaunch('c5', $T + 1);
assert_equal(true, SessionOfferStore::post('c5', 'agent', $decision, null, null, 'again', $T + 2), 'a launched offer can be replaced by a new agent offer');
assert_equal(true, SessionOfferStore::claimLaunch('c5', $T + 3), 'and the new offer can be claimed once more');

echo "clear\n";
SessionOfferStore::post('x1', 'hook', $decision, null, null, 'x', $T);
SessionOfferStore::clear('x1', 'agent');
assert_true(SessionOfferStore::get('x1', $T) !== null, 'clear with origin agent leaves a hook offer');
SessionOfferStore::clear('x1', 'hook');
assert_equal(null, SessionOfferStore::get('x1', $T), 'clear with origin hook removes it');
SessionOfferStore::post('x2', 'agent', $decision, null, null, 'x', $T);
SessionOfferStore::clear('x2', 'hook');
assert_true(SessionOfferStore::get('x2', $T) !== null, 'clear with origin hook leaves an agent offer');
SessionOfferStore::clear('x2');
assert_equal(null, SessionOfferStore::get('x2', $T), 'clear without origin removes whatever is there');
SessionOfferStore::clear('never-posted');
assert_equal(null, SessionOfferStore::get('never-posted', $T), 'clearing an unknown session is harmless');

echo "invalid input\n";
assert_true($threw(fn () => SessionOfferStore::post('v', 'hook', $decision, '12a', null, null, $T)), 'handoff_id with a letter throws');
assert_true($threw(fn () => SessionOfferStore::post('v', 'hook', $decision, null, '1 2', null, $T)), 'plan_id with a space throws');
assert_true($threw(fn () => SessionOfferStore::post('v', 'hook', $decision, '', null, null, $T)), 'an empty handoff_id throws');
assert_true($threw(fn () => SessionOfferStore::post('v', 'hook', $decision, "12\n34", null, null, $T)), 'a handoff_id with a newline throws');
assert_true($threw(fn () => SessionOfferStore::post('v', 'hook', $decision, '-5', null, null, $T)), 'a negative handoff_id throws');
assert_true($threw(fn () => SessionOfferStore::post('v', 'cron', $decision, null, null, null, $T)), 'an unknown origin throws');
assert_true($threw(fn () => SessionOfferStore::post('', 'hook', $decision, null, null, null, $T)), 'an empty session name throws');
assert_true(!$threw(fn () => SessionOfferStore::post('v-ok', 'hook', $decision, null, null, str_repeat('a', 8000), $T)), 'a prompt of exactly 8000 characters is accepted');
assert_true($threw(fn () => SessionOfferStore::post('v', 'hook', $decision, null, null, str_repeat('a', 8001), $T)), 'a prompt of 8001 characters throws');
assert_equal(null, SessionOfferStore::get('v', $T), 'no invalid post left a row behind');

echo "ok\n";
test_exit();
