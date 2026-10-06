<?php

declare(strict_types=1);

/**
 * Take over stops a bare claude process and resumes its conversation under
 * Sessioneer. A real Claude can take seconds to exit after SIGTERM/SIGHUP; a
 * fixed 300ms settle used to resume too early, get refused ("already has a live
 * pane") and leave the conversation stranded in Archived. It now waits for the
 * process to really exit (bounded by TAKE_OVER_EXIT_WAIT_SECONDS), and says so
 * precisely when the deadline passes.
 *
 * The stand-in exits DELAY seconds after SIGTERM, like a TUI flushing state.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/claude_headless.php';
require_once __DIR__ . '/../host-agent/lib/Sessions.php';

use HostAgent\Services\BareProcessService;
use HostAgent\Services\Config;
use HostAgent\Services\SessionLifecycleService;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\SessionListCacheStore;
use HostAgent\Stores\SidecarStore;

$realPushSqliteFile = Config::push_sqlite_path();
$root = sys_get_temp_dir() . '/sessioneer-test-take-over-' . getmypid();
@mkdir($root . '/work', 0700, true);
@mkdir($root . '/home', 0700, true);
@mkdir($root . '/cache', 0700, true);
putenv('PUSH_SQLITE_FILE=' . $root . '/push.sqlite');
putenv('SESSIONS_SQLITE_FILE=' . $root . '/sessions.sqlite');
putenv('CACHE_DIR=' . $root . '/cache');
putenv('HOME_ROOT=' . $root . '/home');

// Unconditional, not "only if unset": a session running AS a Sessioneer
// headless session (this file's own tests can run from inside one) inherits
// the REAL manager's environment - including SIDECAR_DIR - by construction
// (the manager passes its own env to every child it spawns), so an "if
// unset" guard would silently do nothing and this test would run against
// the real host's sessions.sqlite. Found live 2026-09-27: a direct `php tests/test_X.php` run from inside such
// a session locked and corrupted real session rows this way.
$realSidecarDir = Config::sidecar_dir();
putenv('SIDECAR_DIR=' . $root . '/sidecars');
@mkdir($root . '/sidecars', 0700, true);

if (Config::sidecar_dir() === $realSidecarDir) {
    fwrite(STDERR, "REFUSING TO RUN: SIDECAR_DIR resolves to the real host sidecar dir.\n");
    exit(1);
}

if (Config::push_sqlite_path() === $realPushSqliteFile || str_contains((string)Config::tmux_socket(), '/tmux-' . getmyuid() . '/default')) {
    fwrite(STDERR, "REFUSING TO RUN: state or tmux socket resolves to the real host.\n");
    exit(1);
}

$wrapper = $root . '/slowclaude';
file_put_contents($wrapper, "#!/bin/bash\nexec -a \"\$0\" perl -e 'my \$d = \$ENV{DELAY} // 0; \$SIG{TERM} = sub { select(undef,undef,undef,\$d); exit 0 }; sleep 300;' -- \"\$@\"\n");
chmod($wrapper, 0755);
putenv('CLAUDE_BIN=' . $wrapper);

/** @var array<int, resource> $procs */
$procs = [];
$before = array_column(TmuxService::list_tracked_tmux_sessions(), 'name');

register_shutdown_function(static function () use (&$procs, $root, $before): void {
    foreach ($procs as $p) {
        @proc_terminate($p, SIGKILL);
        @proc_close($p);
    }

    foreach (TmuxService::list_tracked_tmux_sessions() as $s) {
        if (!in_array($s['name'], $before, true)) {
            TmuxService::tmux_run(['kill-session', '-t', $s['name']]);
            SidecarStore::delete_sidecar($s['name']);
        }
    }

    exec('rm -rf ' . escapeshellarg($root));
});

/** @return array{pid:int, id:string} */
function start_bare(string $wrapper, string $root, int $delay, string $id): array
{
    global $procs;

    $p = proc_open([$wrapper, '--resume', $id], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $root . '/work', ['DELAY' => (string)$delay, 'PATH' => (string)getenv('PATH')]);
    $procs[] = $p;
    $pid = (int)proc_get_status($p)['pid'];
    $seen = static fn (): bool => in_array($pid, array_column(HostAgent\Services\ProcessInspector::find_claude_processes(), 'pid'), true);
    wait_until($seen, 5.0);

    return ['pid' => $pid, 'id' => $id];
}

$paneFor = static fn (string $id): ?string => (function () use ($id): ?string {
    foreach (TmuxService::list_tracked_tmux_sessions() as $s) {
        if ((SidecarStore::read_sidecar($s['name'])['agent_session_id'] ?? null) === $id) {
            return $s['name'];
        }
    }

    return null;
})();

echo "Config\n";
putenv('TAKE_OVER_EXIT_WAIT_SECONDS');
assert_equal(15.0, Config::take_over_exit_wait_seconds(), 'the wait defaults to 15 seconds');
putenv('TAKE_OVER_EXIT_WAIT_SECONDS=2.5');
assert_equal(2.5, Config::take_over_exit_wait_seconds(), 'and is configurable');
putenv('TAKE_OVER_EXIT_WAIT_SECONDS=-3');
assert_equal(0.0, Config::take_over_exit_wait_seconds(), 'a negative value clamps to zero, not a negative deadline');

echo "SessionListCacheStore::clear\n";
SessionListCacheStore::write(['sessions' => [], 'bare' => [['pid' => 1]]]);
putenv('SESSION_LIST_CACHE_TTL_SECONDS=30');
assert_true(SessionListCacheStore::read() !== null, 'a written listing is served from the cache');
SessionListCacheStore::clear();
assert_equal(null, SessionListCacheStore::read(), 'clear() drops it');
SessionListCacheStore::clear();
assert_equal(null, SessionListCacheStore::read(), 'clearing an already-empty cache is harmless');
putenv('SESSION_LIST_CACHE_TTL_SECONDS=0');

echo "Take over: fast exit\n";
putenv('TAKE_OVER_EXIT_WAIT_SECONDS=10');
$a = start_bare($wrapper, $root, 0, '11111111-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
$r = BareProcessService::take_over_bare_process($a['pid']);
assert_true(($r['ok'] ?? false) === true, 'a process that exits at once is taken over');
assert_true($paneFor($a['id']) !== null, 'and its conversation is running under a Sessioneer pane');

echo "Take over: slow exit within the wait\n";
$b = start_bare($wrapper, $root, 2, '22222222-bbbb-4bbb-8bbb-bbbbbbbbbbbb');
$t0 = microtime(true);
$r = BareProcessService::take_over_bare_process($b['pid']);
$took = microtime(true) - $t0;
assert_true(($r['ok'] ?? false) === true, 'a process that needs ~2s to exit is still taken over (' . json_encode($r) . ')');
assert_true(!pid_alive($b['pid']), 'the old process has really exited by the time the result comes back');
assert_true($took >= 1.5, 'because take over waited for it (took ' . round($took, 1) . 's)');
assert_true($paneFor($b['id']) !== null, 'and the conversation is live under a pane');
assert_equal(1, count(array_filter(TmuxService::list_tracked_tmux_sessions(), fn (array $s) => (SidecarStore::read_sidecar($s['name'])['agent_session_id'] ?? null) === $b['id'])), 'exactly one pane owns it');

echo "Take over: the confirm step waits too\n";
$c = start_bare($wrapper, $root, 2, '33333333-cccc-4ccc-8ccc-cccccccccccc');
$r = BareProcessService::take_over_bare_process_with_id($c['pid'], $root . '/work', $c['id']);
assert_true(($r['ok'] ?? false) === true, 'the picker path resumes a slow process after it exits (' . json_encode($r) . ')');
assert_true(!pid_alive($c['pid']) && $paneFor($c['id']) !== null, 'with the old process gone and one live pane');

echo "Take over: too slow for the wait\n";
putenv('TAKE_OVER_EXIT_WAIT_SECONDS=1');
$d = start_bare($wrapper, $root, 5, '44444444-dddd-4ddd-8ddd-dddddddddddd');
$panesBefore = array_column(TmuxService::list_tracked_tmux_sessions(), 'name');
$r = BareProcessService::take_over_bare_process($d['pid']);
assert_equal(false, $r['ok'] ?? null, 'a process still running at the deadline is not resumed over');
assert_contains('had not exited after 1s', (string)$r['message'], 'the message says what happened');
assert_contains('Archived list', (string)$r['message'], 'and what to do');
assert_true(!str_contains((string)$r['message'], 'already has a live pane'), 'not the misleading "already has a live pane"');
assert_equal($panesBefore, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), 'no second writer was opened on the transcript');
assert_true((bool)wait_until(static fn (): bool => !pid_alive($d['pid']), 10.0), 'the process does finish exiting on its own');
$recovered = SessionLifecycleService::resume_agent_session($root . '/work', $d['id']);
assert_true(($recovered['ok'] ?? false) === true, 'and resuming from Archived then works, as the message says');

echo "Take over: nothing to take over\n";
$gone = BareProcessService::take_over_bare_process(999999);
assert_equal(false, $gone['ok'] ?? null, 'a pid that is not a claude process is still the ordinary rejection');
assert_contains('not a currently running claude process', (string)$gone['message'], 'with its own reason');

test_exit();
