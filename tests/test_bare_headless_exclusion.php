<?php

declare(strict_types=1);

/**
 * A process owned by the Claude headless manager is not a "bare" claude
 * process: it must stay out of the "other Claude processes" list, and neither
 * Kill nor Take over may act on it (taking it over used to stop the process
 * and then fail to resume the conversation, because its headless row still
 * claimed it). A hand-started process must be unaffected, and an unreachable
 * manager must degrade to "owns nothing", not crash the listing.
 *
 * Runs a REAL manager whose children are launched through a wrapper whose
 * argv[0] is the configured CLAUDE_BIN, so the process scan sees them exactly
 * as it sees a real `claude`.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/claude_headless.php';
require_once __DIR__ . '/../host-agent/lib/Sessions.php';

use HostAgent\Runtimes\ClaudeHeadlessRuntime;
use HostAgent\Services\BareProcessService;
use HostAgent\Services\Config;
use HostAgent\Services\ProcessInspector;
use HostAgent\Services\SessionService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$realPushSqliteFile = Config::push_sqlite_path();
$root = sys_get_temp_dir() . '/sessioneer-test-bare-headless-' . getmypid();
@mkdir($root . '/home/.claude/projects', 0700, true);
@mkdir($root . '/cache', 0700, true);
putenv('PUSH_SQLITE_FILE=' . $root . '/push.sqlite');
putenv('SESSIONS_SQLITE_FILE=' . $root . '/sessions.sqlite');
putenv('CACHE_DIR=' . $root . '/cache');

if ((string)getenv('SIDECAR_DIR') === '') {
    putenv('SIDECAR_DIR=' . $root . '/sidecars');
    @mkdir($root . '/sidecars', 0700, true);
}

if (Config::push_sqlite_path() === $realPushSqliteFile) {
    fwrite(STDERR, "REFUSING TO RUN: PUSH_SQLITE_FILE resolves to the real host state file.\n");
    exit(1);
}

// argv[0] of the launched process is this wrapper's own path, so a scan for CLAUDE_BIN finds it.
$wrapper = $root . '/fakeclaude';
file_put_contents($wrapper, "#!/bin/bash\nexec -a \"\$0\" " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/fake_claude_stream') . " \"\$@\"\n");
chmod($wrapper, 0755);
putenv('CLAUDE_BIN=' . $wrapper);

/** @var array<int, array{proc: resource, sock: string, log: string, fake: string}> $managers */
$managers = [];

/** @var array<int, string> $madeSessions */
$madeSessions = [];

/** @var array<int, resource> $extraProcs */
$extraProcs = [];

register_shutdown_function(static function () use (&$managers, &$madeSessions, &$extraProcs, $root): void {
    foreach ($extraProcs as $p) {
        @proc_terminate($p, SIGKILL);
        @proc_close($p);
    }

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

// ------------------------------------------------------------ pure helper

echo "ProcessInspector::is_within_any\n";
$ppids = [10 => 1, 11 => 10, 12 => 11, 20 => 1, 30 => 31, 31 => 30];
assert_true(ProcessInspector::is_within_any(10, [10], $ppids), 'a root pid is within itself');
assert_true(ProcessInspector::is_within_any(11, [10], $ppids), 'a child is within its root');
assert_true(ProcessInspector::is_within_any(12, [10], $ppids), 'so is a grandchild');
assert_true(!ProcessInspector::is_within_any(20, [10], $ppids), 'an unrelated process is not');
assert_true(!ProcessInspector::is_within_any(12, [], $ppids), 'no roots means nothing is within');
assert_true(ProcessInspector::is_within_any(20, [99, 20], $ppids), 'any of several roots counts');
assert_true(!ProcessInspector::is_within_any(30, [10], $ppids), 'a parent-pid cycle terminates and matches nothing');
assert_true(!ProcessInspector::is_within_any(777, [10], $ppids), 'a pid missing from the map is not within');

// ------------------------------------------------------- real manager, child

echo "Managed headless child vs a hand-started process\n";
$m = start_manager($root, 'mgr', ['CLAUDE_BIN' => $wrapper]);
putenv('CLAUDE_HEADLESS_SOCKET=' . $m['sock']);

$name = 'claude-headless-t' . getmypid() . '-bare';
make_session($root, $name);
assert_true((call($m, 'sessioneer/sendInput', ['session' => $name, 'content' => 'hello'])['ok'] ?? false) === true, 'a headless session starts');
assert_true((bool)wait_until(static fn (): bool => status_is($name, 'idle') && has_result($m, 'echo: hello')), 'and finishes its first turn');
$managedPid = (int)(call($m, 'sessioneer/status', ['session' => $name])['pid'] ?? 0);
assert_true($managedPid > 1 && pid_alive($managedPid), 'its process is running');

$control = proc_open([$wrapper], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $root);
$extraProcs[] = $control;
$controlPid = (int)proc_get_status($control)['pid'];
$scanned = static fn (): array => array_column(ProcessInspector::find_claude_processes(), 'pid');
assert_true((bool)wait_until(static fn (): bool => in_array($controlPid, $scanned(), true) && in_array($managedPid, $scanned(), true)), 'the process scan sees both, exactly as it would a real claude (so this test can fail)');

$runtime = new ClaudeHeadlessRuntime(new HostAgent\Runtimes\ClaudeHeadlessManagerClient($m['sock'], 30), new HostAgent\Runtimes\ClaudeHeadlessManagerClient($m['sock'], 2, false));
assert_equal([$managedPid], $runtime->live_child_pids(), 'the runtime reports the manager\'s own child pids');
assert_equal([$managedPid], BareProcessService::managed_headless_pids(), 'as does the service the listing uses');

$barePids = array_column(SessionService::list_all_sessions()['bare'], 'pid');
assert_true(in_array($controlPid, $barePids, true), 'a hand-started process is still listed as a bare one');
assert_true(!in_array($managedPid, $barePids, true), 'the headless session\'s own process is NOT listed as another Claude process');

echo "Kill / Take over refuse a managed process\n";
$killed = BareProcessService::kill_bare_process($managedPid);
assert_equal(false, $killed['ok'] ?? null, 'Kill is refused');
assert_contains('headless session', (string)$killed['message'], 'with the reason');
$took = BareProcessService::take_over_bare_process($managedPid);
assert_equal(false, $took['ok'] ?? null, 'Take over is refused');
assert_contains('Stop it, or switch it to a terminal', (string)$took['message'], 'pointing at what to do instead');
assert_true(!isset($took['needs_choice']), 'without offering a candidate list to pick from');
$tookWithId = BareProcessService::take_over_bare_process_with_id($managedPid, $root, '11111111-2222-3333-4444-555555555555');
assert_equal(false, $tookWithId['ok'] ?? null, 'the confirm step is refused too');
usleep(500000);
assert_true(pid_alive($managedPid), 'none of them touched the process');
assert_equal('idle', SessionStatusStore::read_status($name)['status'] ?? null, 'or its session state');
assert_true(SidecarStore::read_sidecar($name) !== null, 'or its session row');

$stillListed = array_column(call($m, 'sessioneer/list')['children'] ?? [], 'session');
assert_true(in_array($name, $stillListed, true), 'the manager still owns it');

echo "A hand-started process is unaffected\n";
$killControl = BareProcessService::kill_bare_process($controlPid);
assert_equal(true, $killControl['ok'] ?? null, 'Kill still works on a genuinely bare process');
assert_true((bool)wait_until(static fn (): bool => !pid_alive($controlPid)), 'and it ends');

echo "Manager unreachable\n";
proc_terminate($m['proc'], SIGTERM);
assert_true((bool)wait_until(static fn (): bool => !proc_get_status($m['proc'])['running'], 12.0), 'the manager stops');
$t0 = microtime(true);
assert_equal([], BareProcessService::managed_headless_pids(), 'an unreachable manager owns no processes (handled, not an error)');
assert_true(microtime(true) - $t0 < 1.0, 'and answering that is instant, not the client\'s multi-second connect retries (took ' . round(microtime(true) - $t0, 2) . 's)');
assert_equal(false, BareProcessService::kill_bare_process(999999)['ok'] ?? null, 'and an unrelated pid gets the ordinary rejection, not a crash');
assert_contains('not a currently running claude process', (string)BareProcessService::kill_bare_process(999999)['message'], 'naming the ordinary reason');
assert_true(is_array(SessionService::list_all_sessions()['bare']), 'the listing still works');

test_exit();
