<?php

declare(strict_types=1);

/**
 * Quota tint, host side: the `quota_exhausted` field every `list` row gains (true, false or null), decided by
 * FlowService::overlay through the flow quota reader (deps `quota`). Written from the Contract only. Intent notes:
 *  - decision: true only when the row's own account has a known reading and the session or week bucket is at 100
 *    percent or more with a reset time that is unknown or still in the future; a bucket whose reset already passed does
 *    not count; 99 percent is false; a missing, unavailable or unparsable reading is null (never false);
 *  - account key: claude rows use claude/<profile> (personal when the profile is null), codex rows codex/default,
 *    any other agent its own id; two Claude profiles never borrow each other's reading;
 *  - reader use: one read per distinct key per call, a throwing reader gives null and never an error on the list.
 * ASSUMED READING SHAPE (the Contract does not name it): the reader returns the existing
 * {session_pct, week_pct, resets_at} extended with per-bucket {session_resets_at, week_resets_at} (int|null).
 * resets_at stays the worst bucket's reset. Rows carry `agent` and `profile`; `status` is not idle so no flow facts are read.
 * A real `list` through dispatch_action (isolated sidecar database, dead agents, no tmux) must carry the key too.
 */

$autoload = __DIR__ . '/../vendor/autoload.php';
require $autoload;
require __DIR__ . '/lib/assert.php';

$dir = sys_get_temp_dir() . '/sessioneer-test-quota-tint-rows-' . getmypid();
mkdir($dir . '/cache', 0700, true);
foreach ([
    'HOME' => $dir . '/home', 'SIDECAR_DIR' => $dir . '/sidecars', 'SESSIONS_SQLITE_FILE' => $dir . '/sessions.sqlite',
    'CACHE_DIR' => $dir . '/cache', 'PUSH_SQLITE_FILE' => $dir . '/push.sqlite', 'TMUX_SOCKET' => $dir . '/tmux/socket',
    'OPENCODE_SERVE_URL' => 'http://127.0.0.1:1', 'OPENCODE_DB_PATH' => $dir . '/no-opencode.db',
    'CLAUDE_HEADLESS_SOCKET' => $dir . '/no-claude-manager.sock', 'CODEX_BRIDGE_SOCKET' => $dir . '/no-codex-bridge.sock',
    'SOCKET_CLIENT_FAIL_FAST_IF_ABSENT' => '1', 'SESSION_LIST_CACHE_TTL_SECONDS' => '0', 'HEADLESS_SYNC_SECONDS' => '0',
    'SESSIONEER_AGENT_SOCKET' => '', 'HOME_ROOT' => $dir . '/home', 'OPENCODE_AUTH_PATH' => $dir . '/no-opencode-auth.json', 'OPENCODE_GO_API_KEY' => '',
] as $k => $v) {
    putenv("{$k}={$v}");
}
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($dir)));

use HostAgent\SessionFlow\FlowService;
use HostAgent\SessionFlow\Thresholds;
use HostAgent\Stores\SidecarStore;

$NOW = 2_000_000;
$reads = [];
$readings = [];
$deps = [
    'clock' => static fn (): int => $NOW,
    'thresholds' => Thresholds::fromFile($dir . '/none.conf'),
    'quota' => static function (string $key) use (&$reads, &$readings): ?array {
        $reads[] = $key;
        $r = $readings[$key] ?? null;
        if ($r instanceof Throwable) {
            throw $r;
        }

        return $r;
    },
];

/** A reading in the assumed shape. */
function qt_reading(?int $session, ?int $week, ?int $sessionReset, ?int $weekReset): array
{
    $worst = ($week ?? -1) > ($session ?? -1) ? $weekReset : $sessionReset;

    return ['session_pct' => $session, 'week_pct' => $week, 'resets_at' => $worst, 'session_resets_at' => $sessionReset, 'week_resets_at' => $weekReset];
}

function qt_row(string $name, string $agent = 'claude', ?string $profile = null): array
{
    return ['name' => $name, 'agent' => $agent, 'profile' => $profile, 'status' => 'working', 'activity' => 1_999_900];
}

/** @return array<string, mixed> the field of each row by name, 'missing' when absent */
function qt_run(array $rows, array $deps): array
{
    $out = [];
    foreach (FlowService::overlay($rows, $deps) as $row) {
        $out[$row['name']] = array_key_exists('quota_exhausted', $row) ? $row['quota_exhausted'] : 'missing';
    }

    return $out;
}

$future = $NOW + 3600;
$past = $NOW - 60;

echo "Decision: buckets and resets\n";
$cases = [
    'session at 100, reset ahead' => [qt_reading(100, 40, $future, $future), true],
    'session over 100' => [qt_reading(120, 40, $future, $future), true],
    'week at 100, reset ahead' => [qt_reading(10, 100, $future, $future + 86400), true],
    'both at 100' => [qt_reading(100, 100, $future, $future), true],
    'session at 99' => [qt_reading(99, 50, $future, $future), false],
    'week at 99' => [qt_reading(5, 99, $future, $future), false],
    'both well below' => [qt_reading(10, 10, $future, $future), false],
    'session at 100, reset passed' => [qt_reading(100, 10, $past, $future), false],
    'week at 100, reset passed' => [qt_reading(10, 100, $future, $past), false],
    'reset exactly now has passed' => [qt_reading(100, 10, $NOW, $future), false],
    'session at 100, reset unknown' => [qt_reading(100, 10, null, $future), true],
    'week at 100, reset unknown' => [qt_reading(10, 100, $future, null), true],
    'session reset passed but week exhausted ahead' => [qt_reading(100, 100, $past, $future), true],
    'session exhausted ahead but week reset passed' => [qt_reading(100, 100, $future, $past), true],
    'both exhausted, both reset passed' => [qt_reading(100, 100, $past, $past), false],
    'only week pct known, exhausted' => [qt_reading(null, 100, null, $future), true],
    'only session pct known, healthy' => [qt_reading(30, null, $future, null), false],
    'no pct at all' => [qt_reading(null, null, null, null), null],
    'no pct at all, resets known' => [qt_reading(null, null, $future, $future), null],
];
foreach ($cases as $label => [$reading, $expected]) {
    $readings = ['claude/personal' => $reading];
    $reads = [];
    assert_equal(['a' => $expected], qt_run([qt_row('a')], $deps), "claude personal: {$label}");
}

echo "Missing, unavailable, unparsable and throwing readings are null\n";
foreach (['no reading' => null, 'unparsable string pct' => ['session_pct' => 'lots', 'week_pct' => 'x', 'resets_at' => 'soon', 'session_resets_at' => 'soon', 'week_resets_at' => 'soon'], 'empty array' => []] as $label => $reading) {
    $readings = ['claude/personal' => $reading];
    assert_equal(['a' => null], qt_run([qt_row('a')], $deps), "null: {$label}");
}
$readings = ['claude/personal' => new RuntimeException('quota store unreadable')];
$r = qt_run([qt_row('a'), qt_row('b', 'codex')], $deps);
assert_equal(['a' => null, 'b' => null], $r, 'a throwing reader gives null for every row and the call still returns');
$readings = ['claude/personal' => new Error('boom')];
assert_equal(['a' => null], qt_run([qt_row('a')], $deps), 'a reader throwing an Error is null too');
$readings = ['claude/personal' => new RuntimeException('x'), 'codex/default' => qt_reading(100, 0, $future, $future)];
assert_equal(['a' => null, 'b' => true], qt_run([qt_row('a'), qt_row('b', 'codex')], $deps), 'one account throwing does not spoil another account\'s row');

echo "Failures are logged, successes are not\n";
$logFile = $dir . '/error.log';
$oldLog = ini_get('error_log');
ini_set('error_log', $logFile);
$readLog = static fn (): string => is_file($logFile) ? (string)file_get_contents($logFile) : '';
$readings = ['claude/personal' => qt_reading(100, 0, $future, $future), 'codex/default' => qt_reading(1, 1, $future, $future)];
qt_run([qt_row('a'), qt_row('b', 'codex')], $deps);
assert_equal('', $readLog(), 'successful reads log nothing');
$readings = ['claude/personal' => new RuntimeException('store file is corrupt')];
@unlink($logFile);
assert_equal(['a' => null], qt_run([qt_row('a')], $deps), 'a RuntimeException from the store gives null for the row');
assert_contains('claude/personal', $readLog(), 'the log names the key that failed');
assert_contains('store file is corrupt', $readLog(), 'the log carries the exception message');
$readings = ['claude/work' => new TypeError('bad quota shape')];
@unlink($logFile);
assert_equal(['w' => null], qt_run([qt_row('w', 'claude', 'work')], $deps), 'a TypeError gives null for the row');
assert_contains('claude/work', $readLog(), 'TypeError: the log names the key');
assert_contains('bad quota shape', $readLog(), 'TypeError: the log carries the message');
$readings = ['claude/personal' => new RuntimeException('only personal broke'), 'codex/default' => qt_reading(1, 1, $future, $future)];
@unlink($logFile);
qt_run([qt_row('a'), qt_row('b', 'codex')], $deps);
assert_true(!str_contains($readLog(), 'codex/default'), 'a healthy key is not named in the log');
$readings = [];
@unlink($logFile);
qt_run([qt_row('a')], $deps);
assert_equal('', $readLog(), 'a missing reading (null, no exception) logs nothing');
ini_set('error_log', $oldLog === false ? '' : (string)$oldLog);

echo "Account keys\n";
$readings = ['claude/personal' => qt_reading(100, 0, $future, $future), 'claude/work' => qt_reading(10, 10, $future, $future)];
$reads = [];
$r = qt_run([qt_row('p-null'), qt_row('p-named', 'claude', 'personal'), qt_row('w', 'claude', 'work')], $deps);
assert_equal(['p-null' => true, 'p-named' => true, 'w' => false], $r, 'two Claude profiles: only the exhausted account\'s sessions are exhausted; a null profile is personal');
$readings = ['claude/personal' => qt_reading(10, 10, $future, $future), 'claude/work' => qt_reading(100, 0, $future, $future)];
assert_equal(['p' => false, 'w' => true], qt_run([qt_row('p'), qt_row('w', 'claude', 'work')], $deps), 'the other way round: the work account is exhausted, personal is healthy');
$readings = ['claude/personal' => qt_reading(100, 100, $future, $future)];
assert_equal(['w' => null], qt_run([qt_row('w', 'claude', 'work')], $deps), 'a profile with no reading of its own is null, never another profile\'s reading');
$readings = ['codex/default' => qt_reading(100, 5, $future, $future), 'claude/personal' => qt_reading(1, 1, $future, $future)];
assert_equal(['c' => true], qt_run([qt_row('c', 'codex')], $deps), 'codex rows read codex/default');
assert_equal(['c' => true], qt_run([qt_row('c', 'codex', 'whatever')], $deps), 'a codex row ignores any profile and still reads codex/default');
$readings = ['codex/default' => qt_reading(1, 1, $future, $future), 'claude/personal' => qt_reading(100, 100, $future, $future)];
assert_equal(['c' => false], qt_run([qt_row('c', 'codex')], $deps), 'a healthy codex row is not tinted by an exhausted Claude account');
$readings = ['opencode' => qt_reading(100, 0, $future, $future), 'antigravity' => qt_reading(3, 3, $future, $future)];
assert_equal(['o' => true, 'g' => false, 'n' => null], qt_run([qt_row('o', 'opencode'), qt_row('g', 'antigravity'), qt_row('n', 'ollama')], $deps), 'other agents use their agent id; an agent with no reading is null');
$reads = [];
qt_run([qt_row('o', 'opencode'), qt_row('w', 'claude', 'work'), qt_row('c', 'codex'), qt_row('p')], $deps);
sort($reads);
assert_equal(['claude/personal', 'claude/work', 'codex/default', 'opencode'], $reads, 'the keys asked for are claude/<profile>, codex/default and the agent id');

echo "One read per key per call\n";
$readings = ['claude/personal' => qt_reading(100, 0, $future, $future), 'claude/work' => qt_reading(1, 1, $future, $future), 'codex/default' => qt_reading(1, 1, $future, $future)];
$reads = [];
$rows = [];
foreach (range(1, 6) as $i) {
    $rows[] = qt_row("p{$i}");
    $rows[] = qt_row("w{$i}", 'claude', 'work');
    $rows[] = qt_row("c{$i}", 'codex');
}
qt_run($rows, $deps);
sort($reads);
assert_equal(['claude/personal', 'claude/work', 'codex/default'], $reads, 'eighteen rows over three accounts read each account once');
$readings = ['claude/personal' => new RuntimeException('x')];
$reads = [];
qt_run([qt_row('a'), qt_row('b'), qt_row('c')], $deps);
assert_equal(['claude/personal'], $reads, 'a throwing key is read once too, not once per row');
$readings = [];
$reads = [];
qt_run([qt_row('a'), qt_row('b')], $deps);
assert_equal(['claude/personal'], $reads, 'a missing reading is read once too');
$reads = [];
qt_run([qt_row('a')], $deps);
assert_equal(1, count($reads), 'a second call reads again (the cache lives within one call)');
$readings = ['claude/personal' => qt_reading(100, 0, $future, $future)];
assert_equal(['a' => true], qt_run([qt_row('a')], $deps), 'and sees the new reading');

echo "Every row carries the field; other fields are kept\n";
$readings = ['claude/personal' => qt_reading(100, 0, $future, $future)];
$out = FlowService::overlay([qt_row('keep') + ['title' => 'T', 'starred' => true]], $deps)[0];
assert_equal([true, 'T', true, 'keep'], [$out['quota_exhausted'] ?? 'missing', $out['title'] ?? null, $out['starred'] ?? null, $out['name']], 'the field is added next to the existing row fields');
assert_true(is_bool($out['quota_exhausted'] ?? null), 'the value is a real boolean, not 1 or "true"');
assert_equal([], FlowService::overlay([], $deps), 'no rows, no reads');
$reads = [];
FlowService::overlay([], $deps);
assert_equal([], $reads, 'no rows: the reader is never called');

echo "Real list action: the field is on every row\n";
require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';
if (!str_starts_with(HostAgent\Services\Config::sessions_sqlite_path(), $dir . '/')) {
    fwrite(STDERR, "REFUSING TO RUN: sidecar database is not isolated\n");
    exit(1);
}
set_error_handler(static fn (int $no, string $msg): bool => (bool)preg_match('/Undefined array key|offset on null|Undefined variable/', $msg));
foreach (['qt-oc-1', 'qt-oc-2'] as $n) {
    SidecarStore::write_sidecar($n, ['workdir' => '/tmp', 'spawned_at' => time(), 'agent_session_id' => $n, 'spawned_by_app' => true, 'agent' => 'opencode', 'runtime' => 'headless']);
}
$list = dispatch_action(['action' => 'list'])['sessions'] ?? [];
$by = [];
foreach ($list as $row) {
    $by[(string)$row['name']] = $row;
}
assert_true(isset($by['qt-oc-1'], $by['qt-oc-2']), 'fixture: both sessions are listed');
foreach (['qt-oc-1', 'qt-oc-2'] as $n) {
    $has = array_key_exists('quota_exhausted', $by[$n] ?? []);
    assert_true($has, "list: row {$n} has the quota_exhausted key");
    assert_true($has && $by[$n]['quota_exhausted'] === null, "list: row {$n} has no reading in the isolated store, so null (not false)");
}

echo "ok\n";
test_exit();
