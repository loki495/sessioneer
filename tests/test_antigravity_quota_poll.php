<?php
declare(strict_types=1);

/**
 * Exercises the real host-agent/antigravity_quota_poll.php script - run
 * as a real subprocess (proc_open), never in-process, since it's a
 * standalone systemd-timer entry point, not a class - against
 * tests/fixtures/fake_agy's canned `/usage` response (see that file's own
 * docblock for why it needs to distinguish -p/--print from the
 * interactive-TUI shape every other Antigravity test already uses it
 * for). See docs/history/antigravity-adapter-plan.md's Phase 7 ("quota
 * research") for the real response shape this fixture is modeled on.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Stores\GlobalStateStore;

$realPushSqliteFile = Config::push_sqlite_path();

$pushSqliteFixture = sys_get_temp_dir() . '/sessioneer-test-agy-quota-' . bin2hex(random_bytes(4)) . '/push.sqlite';
putenv("PUSH_SQLITE_FILE={$pushSqliteFixture}");

if (Config::push_sqlite_path() === $realPushSqliteFile) {
    fwrite(STDERR, "REFUSING TO RUN: PUSH_SQLITE_FILE resolves to the real host state file.\n");
    exit(1);
}

$fakeAgyPath = dirname(__DIR__) . '/tests/fixtures/fake_agy';
$scriptPath = dirname(__DIR__) . '/host-agent/antigravity_quota_poll.php';

/**
 * @return array{exit:int, stdout:string}
 */
function run_antigravity_quota_poll(string $antigravityBin, string $pushSqliteFixture): array
{
    $env = [
        'ANTIGRAVITY_BIN' => $antigravityBin,
        'PUSH_SQLITE_FILE' => $pushSqliteFixture,
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['php', $GLOBALS['scriptPath']], $descriptors, $pipes, null, $env);

    if (!is_resource($process)) {
        return ['exit' => -1, 'stdout' => ''];
    }

    fclose($pipes[0]);
    $stdout = (string)stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);

    return ['exit' => $exit, 'stdout' => $stdout];
}

try {
    // --- no ANTIGRAVITY_BIN configured: harmless no-op, nothing written ---

    $noBin = run_antigravity_quota_poll('', $pushSqliteFixture);
    assert_equal(0, $noBin['exit'], 'antigravity_quota_poll.php: exits 0 when ANTIGRAVITY_BIN is unset');
    assert_equal('', trim($noBin['stdout']), 'antigravity_quota_poll.php: writes nothing to stdout when ANTIGRAVITY_BIN is unset');
    assert_equal(null, GlobalStateStore::read(Config::antigravity_quota_live_state_key()), 'antigravity_quota_poll.php: no state written when ANTIGRAVITY_BIN is unset');

    // --- a real (fake) successful /usage call ---

    $before = time();
    $ok = run_antigravity_quota_poll($fakeAgyPath, $pushSqliteFixture);
    assert_equal(0, $ok['exit'], 'antigravity_quota_poll.php: exits 0 on a successful poll');
    assert_equal('', trim($ok['stdout']), 'antigravity_quota_poll.php: writes nothing to stdout on success either');

    $state = GlobalStateStore::read(Config::antigravity_quota_live_state_key());
    assert_true($state !== null, 'antigravity_quota_poll.php: writes state after a successful poll');
    assert_equal(25, $state['gemini-weekly']['pct'] ?? null, 'antigravity_quota_poll.php: converts remaining_fraction (0.75) to used pct (25) - Antigravity reports remaining, this app stores used, matching Claude Code\'s own convention');
    assert_equal(0, $state['3p-weekly']['pct'] ?? null, 'antigravity_quota_poll.php: remaining_fraction=1 (fully available) converts to pct=0 (nothing used)');
    assert_equal(strtotime('2026-08-31T20:07:27Z'), $state['gemini-weekly']['resets_at'] ?? null, 'antigravity_quota_poll.php: reset_time is parsed to a real epoch, not left as a string');
    assert_equal('Gemini Models', $state['gemini-weekly']['group_name'] ?? null, 'antigravity_quota_poll.php: the human-readable group name is preserved alongside the opaque bucket id');
    assert_equal('Claude and GPT models', $state['3p-weekly']['group_name'] ?? null, 'antigravity_quota_poll.php: group_name is per-bucket, not just the first group\'s');
    assert_true(($state['captured_at'] ?? 0) >= $before, 'antigravity_quota_poll.php: captured_at is a real, current timestamp');

    // --- overwrite semantics: a later successful poll replaces the whole state, no stale merge ---

    $state2 = GlobalStateStore::read(Config::antigravity_quota_live_state_key());
    assert_equal(2, count($state2) - 1, 'antigravity_quota_poll.php: exactly the 2 real buckets plus captured_at, no leftover keys from a previous run');

    // --- a failing/nonexistent binary: no crash, no bogus state written ---

    GlobalStateStore::delete(Config::antigravity_quota_live_state_key());
    $badBin = run_antigravity_quota_poll('/definitely/does/not/exist/sessioneer-test-agy-binary', $pushSqliteFixture);
    assert_equal(0, $badBin['exit'], 'antigravity_quota_poll.php: still exits 0 (never crashes) when the configured binary does not exist');
    assert_equal(null, GlobalStateStore::read(Config::antigravity_quota_live_state_key()), 'antigravity_quota_poll.php: no state written when the underlying agy call fails outright');
} finally {
    @unlink($pushSqliteFixture);
    @unlink($pushSqliteFixture . '-wal');
    @unlink($pushSqliteFixture . '-shm');
    @rmdir(dirname($pushSqliteFixture));
}

test_exit();
