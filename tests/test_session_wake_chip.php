<?php

declare(strict_types=1);

/**
 * The pending-wake indicator ("clock chip") on the host agent's session list entries:
 * `pending_wake: {count, next_at, next_at_local}` (absent when none), which wakes it counts (scheduled and
 * overdue, never running), the cache with its WAKE_CHIP_CACHE_TTL_SECONDS (hit, TTL 0, a failed read cached),
 * invalidation by schedule, cancel and a "gone" reply, and fail-soft behaviour when `at` cannot be reached
 * (no chip, never an error on the dashboard poll). `at` is the stub of tests/lib/wake_fixture.php.
 */

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/wake_fixture.php';

$rig = new WakeRig('wake-chip');
putenv('TMUX_SOCKET=' . $rig->root . '/tmux/socket');
putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
putenv('HEADLESS_SYNC_SECONDS=0');
putenv('CLAUDE_HEADLESS_SOCKET=' . $rig->root . '/no-claude-manager.sock');
putenv('CODEX_BRIDGE_SOCKET=' . $rig->root . '/no-codex-bridge.sock');
putenv('OPENCODE_DB_PATH=' . $rig->root . '/no-opencode.db');
putenv('SOCKET_CLIENT_FAIL_FAST_IF_ABSENT=1');
putenv('SESSION_LIST_CACHE_TTL_SECONDS=0');
putenv('PUSH_SQLITE_FILE=' . $rig->root . '/push.sqlite');
putenv('WAKE_CHIP_CACHE_TTL_SECONDS');

require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\SessionWakeService;
use HostAgent\Stores\SidecarStore;

foreach (['A', 'B', 'C', 'D', 'E'] as $letter) {
    SidecarStore::write_sidecar("ses_Chip{$letter}", ['workdir' => '/tmp', 'spawned_at' => time(), 'agent_session_id' => "ses_Chip{$letter}", 'spawned_by_app' => true, 'agent' => 'opencode', 'runtime' => 'headless']);
}

/** @return array<string, array<string, mixed>> the list action's entries by session name, plus the raw reply under __reply */
function chip_list(): array
{
    $reply = dispatch_action(['action' => 'list']);
    $by = [];

    foreach ($reply['sessions'] ?? [] as $entry) {
        $by[(string)$entry['name']] = $entry;
    }

    return ['__reply' => $reply] + $by;
}

function chip_of(array $list, string $name): ?array
{
    $chip = $list[$name]['pending_wake'] ?? null;

    return is_array($chip) ? $chip : null;
}

function chip_local(int $at): string
{
    return (new DateTimeImmutable('@' . $at))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d H:i T');
}

$minute = static fn (int $secondsAhead): int => intdiv(time() + $secondsAhead, 60) * 60;
$body = static fn (string $conversation, string $prompt): string => WakeRig::body(SessionWakeService::script('opencode', $conversation, '', '', $prompt));
$names = ['ses_ChipA', 'ses_ChipB', 'ses_ChipC', 'ses_ChipD', 'ses_ChipE'];
$noChips = static function (array $list) use ($names): bool {
    foreach ($names as $n) {
        if (array_key_exists('pending_wake', $list[$n] ?? [])) {
            return false;
        }
    }

    return true;
};
$t1 = $minute(3600);
$t2 = $minute(7200);
$t3 = $minute(10800);

try {
    $rig->add(31, $t2, $body('ses_ChipA', 'SECRET PROMPT TEXT one'));
    $rig->add(32, $t1, $body('ses_ChipA', 'SECRET PROMPT TEXT two'));
    $rig->add(33, $t3, $body('ses_ChipB', 'SECRET PROMPT TEXT three'));
    $rig->add(34, $t1, WakeRig::body('echo hi'));
    $rig->add(35, $t1, $body('ses_NotListed', 'for a conversation with no card'));

    echo "Chips on the entries\n";
    $list = chip_list();
    assert_equal(true, $list['__reply']['ok'] ?? null, 'the list action is ok');
    assert_true(isset($list['ses_ChipA'], $list['ses_ChipB'], $list['ses_ChipC']), 'fixture: all sessions are listed');
    $a = chip_of($list, 'ses_ChipA');
    $keys = array_keys($a ?? []);
    sort($keys);
    assert_equal(['count', 'next_at', 'next_at_local'], $keys, 'pending_wake has exactly count, next_at and next_at_local');
    assert_equal(2, $a['count'] ?? null, 'A has two pending wake-ups: count 2');
    assert_equal($t1, $a['next_at'] ?? null, 'A: next_at is exactly the earlier time, as an int');
    assert_equal(chip_local($t1), $a['next_at_local'] ?? null, 'A: next_at_local is Y-m-d H:i T in the machine zone');
    $b = chip_of($list, 'ses_ChipB');
    assert_equal([1, $t3, chip_local($t3)], [$b['count'] ?? null, $b['next_at'] ?? null, $b['next_at_local'] ?? null], 'B: one wake-up, its own time');
    assert_true(!array_key_exists('pending_wake', $list['ses_ChipC']), 'C has none: the key is absent');
    assert_true(!str_contains(json_encode($list['ses_ChipA']), 'SECRET PROMPT'), 'the chip carries no prompt text');
    assert_true(!str_contains(json_encode($list['__reply']), 'hunter2') && !str_contains(json_encode($list['__reply']), 'atrun'), 'no raw at -c output in the list');

    echo "The cache: no at spawn per card or per poll\n";
    $afterFirst = count($rig->calls());
    assert_equal(1, $rig->count_calls('-l'), 'the first list read the queue once');
    assert_true($afterFirst <= 1 + 5, 'and inspected each row at most once (' . $afterFirst . ' at calls for 5 rows)');
    chip_list();
    chip_list();
    $third = chip_list();
    assert_equal(1, $rig->count_calls('-l'), 'three more lists inside the TTL: still one at -l');
    assert_equal($afterFirst, count($rig->calls()), 'and no at -c either: a cache hit spawns no at');
    assert_equal(2, chip_of($third, 'ses_ChipA')['count'] ?? null, 'a cache hit serves the same chips');
    $rig->clear_cache();
    $rig->clear_calls();
    chip_list();
    assert_equal(1, $rig->count_calls('-l'), 'a cold cache costs exactly one at -l, however many cards the dashboard has');

    echo "WAKE_CHIP_CACHE_TTL_SECONDS=0 turns the cache off\n";
    putenv('WAKE_CHIP_CACHE_TTL_SECONDS=0');
    $rig->clear_cache();
    $rig->clear_calls();
    chip_list();
    chip_list();
    assert_equal(2, $rig->count_calls('-l'), 'two lists run at -l twice');
    putenv('WAKE_CHIP_CACHE_TTL_SECONDS');
    $rig->clear_cache();

    echo "Invalidation by cancel\n";
    $r = dispatch_action(['action' => 'launch_wake_cancel', 'job' => 32]);
    assert_equal(true, $r['ok'] ?? null, 'fixture: the earlier wake-up of A is cancelled through the host action');
    $rig->clear_calls();
    $list = chip_list();
    $a = chip_of($list, 'ses_ChipA');
    assert_equal([1, $t2], [$a['count'] ?? null, $a['next_at'] ?? null], 'the next list shows A with one wake-up and the later time at once, not after the TTL');
    assert_equal(1, $rig->count_calls('-l'), 'the cancel dropped the cache, so the queue was read again');
    assert_equal(1, chip_of($list, 'ses_ChipB')['count'] ?? null, 'B is unchanged');

    echo "Invalidation by cancel at the service level\n";
    chip_list();
    try {
        SessionWakeService::cancel(31);
    } catch (\Error $e) {
        assert_true(false, 'SessionWakeService::cancel() exists (' . $e->getMessage() . ')');
    }
    assert_equal(null, chip_of(chip_list(), 'ses_ChipA'), 'cancelling A\'s last wake-up removes its chip on the next list');

    echo "Invalidation by schedule\n";
    chip_list();
    $when = $minute(5400);
    $r = SessionWakeService::schedule('ses_ChipC', $when, 'a new wake-up for C');
    assert_equal(true, $r['ok'] ?? null, 'fixture: schedule through the stub at works (' . json_encode($r) . ')');
    $c = chip_of(chip_list(), 'ses_ChipC');
    assert_equal([1, $when], [$c['count'] ?? null, $c['next_at'] ?? null], 'C shows its new wake-up at its time on the next list, inside the TTL');
    $when2 = $minute(1800);
    $r = dispatch_action(['action' => 'launch_wake', 'target' => 'ses_ChipD', 'at' => $when2, 'message' => 'for D']);
    assert_equal(true, $r['ok'] ?? null, 'fixture: launch_wake through the host action works (' . json_encode($r) . ')');
    assert_equal(1, chip_of(chip_list(), 'ses_ChipD')['count'] ?? null, 'D shows its wake-up after a scheduling through the action');

    echo "A refused cancel keeps the chips\n";
    $before = chip_of(chip_list(), 'ses_ChipC');
    $r = dispatch_action(['action' => 'launch_wake_cancel', 'job' => 34]);
    assert_equal(false, $r['ok'] ?? null, 'fixture: cancelling an unrelated job is refused');
    assert_equal($before, chip_of(chip_list(), 'ses_ChipC'), 'the chip is unchanged after a refusal');

    echo "Invalidation by a gone reply\n";
    $rig->reset();
    $rig->add(41, $t1, $body('ses_ChipE', 'will run before anyone cancels it'));
    assert_equal(1, chip_of(chip_list(), 'ses_ChipE')['count'] ?? null, 'fixture: E has one wake-up, now cached');
    unlink($rig->dir . '/jobs/41');
    file_put_contents($rig->dir . '/queue.txt', '');
    assert_equal(1, chip_of(chip_list(), 'ses_ChipE')['count'] ?? null, 'the job ran, but the cache still says one (inside the TTL)');
    $r = dispatch_action(['action' => 'launch_wake_cancel', 'job' => 41]);
    assert_equal([false, true], [$r['ok'] ?? null, $r['gone'] ?? null], 'fixture: cancelling it is a gone reply');
    assert_equal(null, chip_of(chip_list(), 'ses_ChipE'), 'a gone reply dropped the cache: the chip is gone');
    $rig->add(42, $t1, $body('ses_ChipE', 'second one'));
    chip_list();
    unlink($rig->dir . '/jobs/42');
    file_put_contents($rig->dir . '/queue.txt', '');
    $r = dispatch_action(['action' => 'launch_wake_show', 'job' => 42]);
    assert_equal(true, $r['gone'] ?? null, 'fixture: showing it is a gone reply');
    assert_equal(null, chip_of(chip_list(), 'ses_ChipE'), 'a gone show also drops the cache');

    echo "Which wakes count\n";
    $rig->reset();
    $rig->add(51, $minute(-30), $body('ses_ChipC', 'running now'), '=');
    $rig->add(52, $minute(-3600), $body('ses_ChipD', 'overdue'));
    $rig->add(53, $t3, $body('ses_ChipD', 'later'));
    $rig->add(54, $minute(-1800), $body('ses_ChipE', 'overdue and running'), '=');
    $rig->add(55, $t1, $body('ses_ChipE', 'scheduled'));
    $list = chip_list();
    assert_true(!array_key_exists('pending_wake', $list['ses_ChipC']), 'a session with only a running wake-up has no chip');
    $d = chip_of($list, 'ses_ChipD');
    assert_equal([2, $minute(-3600)], [$d['count'] ?? null, $d['next_at'] ?? null], 'an overdue wake-up counts, and it is the next one');
    $e = chip_of($list, 'ses_ChipE');
    assert_equal([1, $t1], [$e['count'] ?? null, $e['next_at'] ?? null], 'a running wake-up is not counted');

    echo "at cannot be reached: no chip, no error\n";
    foreach (['a missing binary' => static function () use ($rig): void {
        putenv('AT_BIN=' . $rig->root . '/no-such-dir/at');
    }, 'at -l failing' => static function () use ($rig): void {
        putenv('AT_BIN=' . $rig->dir . '/at');
        touch($rig->dir . '/fail-l');
    }] as $label => $break) {
        $rig->clear_cache();
        $break();
        $list = chip_list();
        assert_equal(true, $list['__reply']['ok'] ?? null, "{$label}: the list action is still ok");
        assert_true(!isset($list['__reply']['message']), "{$label}: and carries no error message");
        assert_true(isset($list['ses_ChipA'], $list['ses_ChipB'], $list['ses_ChipC']), "{$label}: every session is still listed");
        assert_true($noChips($list), "{$label}: no entry has a chip");
        assert_true(!str_contains(json_encode($list['__reply']), 'AT_BIN') && !str_contains(json_encode($list['__reply']), 'Could not read'), "{$label}: no at error text leaks into the list");
        @unlink($rig->dir . '/fail-l');
    }
    putenv('AT_BIN=' . $rig->dir . '/at');

    echo "A failed read is cached as no wakes for the TTL\n";
    $rig->reset();
    $rig->add(61, $t1, $body('ses_ChipA', 'queued'));
    touch($rig->dir . '/fail-l');
    chip_list();
    chip_list();
    assert_equal(1, $rig->count_calls('-l'), 'two lists with at -l failing: the failure is cached, one at -l');
    @unlink($rig->dir . '/fail-l');
    assert_true($noChips(chip_list()), 'at works again, but inside the TTL there is still no chip');
    assert_equal(1, $rig->count_calls('-l'), 'and at was not asked again');
    $rig->clear_cache();
    assert_equal(1, chip_of(chip_list(), 'ses_ChipA')['count'] ?? null, 'once the cache is gone the chip returns');

    echo "Only flags reach at\n";
    foreach ($rig->calls() as $line) {
        assert_true(preg_match('/^(-l|-c \d+|-r \d+|-t \d+)$/', $line) === 1, "at was called with flags only (saw: {$line})");
    }
    assert_true(!is_file($rig->root . '/atq-atrm.log'), 'atq and atrm were never called by name');

    echo "An empty queue gives no chips\n";
    $rig->reset();
    $list = chip_list();
    assert_true($noChips($list), 'no chips');
    assert_equal(true, $list['__reply']['ok'] ?? null, 'and the list is ok');
} finally {
    $rig->cleanup();
}

test_exit();
