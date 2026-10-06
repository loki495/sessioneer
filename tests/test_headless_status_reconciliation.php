<?php

declare(strict_types=1);

/**
 * A real headless session was correctly `blocked` in
 * ClaudeHeadlessManager's own memory (a genuine can_use_tool prompt) while
 * SessionStatusStore's row silently never learned that - the write threw
 * (SQLITE_BUSY under WAL contention is the leading suspect) and was
 * swallowed, and every reader (dashboard, session page, push) only ever
 * reads that row, so the session looked dead forever.
 *
 * Two independent defenses, tested here against a real manager process
 * driving tests/fixtures/fake_claude_stream, never the real `claude`:
 *
 * 1. ClaudeHeadlessManager::persist_status() retries a failed status write
 *    every housekeeping tick until it lands, instead of losing it. Forced
 *    by genuinely locking the real sessions.sqlite from a second connection
 *    (SQLite's own busy_timeout=5000ms is what actually throws) - not a
 *    mock, the same failure mode the live incident hit.
 * 2. ClaudeHeadlessRuntime::reconcile_with_live_status() cross-checks the
 *    manager's live state on every session_detail call for ONE session, and
 *    self-heals a stale row it finds - covers any other cause of the same
 *    drift, and fixes the one page a person is actually looking at even
 *    before a manager retry would.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/claude_headless.php';
require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$root = sys_get_temp_dir() . '/sessioneer-test-status-reconcile-' . getmypid();
@mkdir($root . '/home/.claude/projects', 0700, true);

// Unconditional, not "only if unset": a session running AS a Sessioneer
// headless session (this file's own tests can run from inside one) inherits
// the REAL manager's environment - including SIDECAR_DIR - by construction
// (the manager passes its own env to every child it spawns), so an "if
// unset" guard would silently do nothing and this test would run against
// the real host's sessions.sqlite. Found live 2026-09-27 - the first version
// of THIS test file did exactly that and briefly corrupted real session rows.
$realSidecarDir = Config::sidecar_dir();
putenv('SIDECAR_DIR=' . $root . '/sidecars');
@mkdir($root . '/sidecars', 0700, true);

if (Config::sidecar_dir() === $realSidecarDir) {
    fwrite(STDERR, "REFUSING TO RUN: SIDECAR_DIR resolves to the real host sidecar dir.\n");
    exit(1);
}

$realPushSqliteFile = Config::push_sqlite_path();
putenv('PUSH_SQLITE_FILE=' . $root . '/push.sqlite');

if (Config::push_sqlite_path() === $realPushSqliteFile) {
    fwrite(STDERR, "REFUSING TO RUN: PUSH_SQLITE_FILE resolves to the real host state file.\n");
    exit(1);
}

$sessionsDbPath = Config::sessions_sqlite_path();
$realSessionsDbPath = '/run/user/' . getmyuid() . '/sessioneer-sessions/sessions.sqlite';

if ($sessionsDbPath === $realSessionsDbPath) {
    fwrite(STDERR, "REFUSING TO RUN: sessions.sqlite resolves to the real host sidecar dir.\n");
    exit(1);
}

/** @var array<int, array{proc: resource, sock: string, log: string, fake: string}> $managers */
$managers = [];
/** @var array<int, string> $madeSessions */
$madeSessions = [];

register_shutdown_function(static function () use (&$managers, &$madeSessions, $root): void {
    foreach ($managers as $m) {
        if (is_resource($m['proc'])) {
            @proc_terminate($m['proc'], SIGTERM);
            usleep(300000);
            @proc_terminate($m['proc'], SIGKILL);
            @proc_close($m['proc']);
        }
    }

    foreach ($madeSessions as $name) {
        SidecarStore::delete_sidecar($name);
        SessionStatusStore::delete_status($name);
    }

    exec('rm -rf ' . escapeshellarg($root));
});

$sn = static fn (int $n): string => "sn-status-reconcile-{$n}-" . getmypid();

// ============================================================ helper: hold a
// real exclusive write lock on the shared sessions.sqlite from a SEPARATE
// connection, so the manager's own write genuinely times out
// (busy_timeout=5000ms, SqliteDb::connect()) and throws - not simulated.
function lock_sessions_db(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->exec('BEGIN IMMEDIATE');

    return $pdo;
}

// ===================================================== 1. the manager retries
// a status write it could not persist, instead of losing it forever.

echo "1. persist_status() retries a failed write\n";

$m1 = start_manager($root, 'm1');
$workdir1 = make_session($root, $sn(1));

// dispatch_action() (used in section 2) builds its own ClaudeHeadlessRuntime
// from this env var - it must point at the SAME manager $m1 talks to.
putenv('CLAUDE_HEADLESS_SOCKET=' . $m1['sock']);

// Spawn cleanly first, with NO lock held - a brand new session's first
// message also writes its sidecar (agent_session_id, spawned_at), a
// SEPARATE write this test does not touch. Only the SECOND message, to a
// child that is already alive, exercises exactly the write the live
// incident hit (ClaudeHeadlessManager::send_input()'s own persist_status()
// call for an already-running child, and on_control_request()'s for the
// can_use_tool prompt that follows).
call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'hello']);
assert_true((bool)wait_until(static fn (): bool => status_is($sn(1), 'idle')), 'the session spawns and answers a first, ordinary message cleanly');

$lock = lock_sessions_db($sessionsDbPath);

call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'PERMISSION please']);

// The manager's own in-memory view is correct immediately - the lock only
// ever blocks the DATABASE write, never event processing itself.
assert_true(
    (bool)wait_until(static fn (): bool => (call($m1, 'sessioneer/status', ['session' => $sn(1)])['state'] ?? null) === 'blocked'),
    'the manager itself reports blocked at once, lock or no lock'
);

// The real row is still stale while the lock is held - proves the write
// really did fail rather than this test racing a fast, successful one.
usleep(200000);
assert_equal('idle', SessionStatusStore::read_status($sn(1))['status'], 'the persisted row has NOT caught up while the write is genuinely blocked');

$lock->exec('COMMIT');
$lock = null;

assert_true(
    (bool)wait_until(static fn (): bool => SessionStatusStore::read_status($sn(1))['status'] === 'blocked', 8.0),
    'housekeeping retries the write and the row catches up once the lock is released'
);
assert_true(is_array(SessionStatusStore::read_status($sn(1))['blocked'] ?? null), 'with the real prompt, not an empty placeholder');

// ============================================== 2. session_detail self-heals
// a stale row for the one session being looked at, on read.

echo "2. session_detail() cross-checks and self-heals the live session\n";

$workdir2 = make_session($root, $sn(2));
call($m1, 'sessioneer/sendInput', ['session' => $sn(2), 'content' => 'PERMISSION please']);
assert_true((bool)wait_until(static fn (): bool => status_is($sn(2), 'blocked')), 'a second session reaches a real, correctly-persisted blocked state');

// Simulate the drift directly (a lost write from ANY cause, not just a lock)
// by corrupting the already-correct row back to idle with no prompt.
SessionStatusStore::update_status($sn(2), ['status' => 'idle', 'blocked' => null]);
assert_equal('idle', SessionStatusStore::read_status($sn(2))['status'], 'the row is now deliberately wrong');

$detail = dispatch_action(['action' => 'session_detail', 'session' => $sn(2)]);
assert_equal('blocked', $detail['status'] ?? null, 'session_detail reports the manager\'s real state, not the stale row');
assert_equal('Write', $detail['prompt_tool_name'] ?? null, 'with the real pending tool (fake_claude_stream\'s PERMISSION keyword asks to use Write)');
assert_true(is_array($detail['prompt_tool_input'] ?? null) && $detail['prompt_tool_input'] !== [], 'and its real input');

$healed = SessionStatusStore::read_status($sn(2));
assert_equal('blocked', $healed['status'], 'the store itself is corrected too, not just this one response');
assert_true(is_array($healed['blocked'] ?? null), 'with the real prompt now persisted');

// A session with no live manager entry (dormant, or the manager down) must
// still resolve from the store alone - the cross-check is a bonus, not a
// requirement.
$dormantClient = new HostAgent\Runtimes\ClaudeHeadlessManagerClient($root . '/does-not-exist.sock', 1, false);
$dormantRuntime = new HostAgent\Runtimes\ClaudeHeadlessRuntime($dormantClient, $dormantClient);
$dormantDetail = $dormantRuntime->detail($sn(2));
assert_true(($dormantDetail['ok'] ?? false) === true, 'an unreachable manager is not an error - the store-derived entry is used as-is');
assert_equal('blocked', $dormantDetail['session']['status'] ?? null, 'still whatever the (now-correct) store says');

test_exit();
