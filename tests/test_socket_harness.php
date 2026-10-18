<?php
declare(strict_types=1);

/**
 * Tests tests/lib/socket_harness.php's own self-cleanup safeguard
 * (kill_stale_listener()) - a real process-management behavior, exercised
 * via real subprocesses rather than mocked, since that's what it actually
 * manages. See test_agent_client_protocol.php/test_ui_smoke.php for the
 * harness used AS a stand-in for the real agent; this file tests the
 * harness script itself.
 *
 * socket_harness.php only ever unlinked the socket
 * FILE before rebinding, never touched the PROCESS still holding the old
 * listener - ten orphaned instances (some days old) had piled up from
 * repeated manual-verification runs reusing the same fixed socket path.
 * kill_stale_listener() now runs first, unconditionally, before this
 * script does anything else that could itself fail.
 */

require __DIR__ . '/lib/assert.php';

/**
 * NOT just is_dir("/proc/{$pid}") - a killed process this script itself
 * spawned (via proc_open) stays a zombie, with its /proc entry still very
 * much present, until this script's own proc_close()/proc_terminate() (or
 * exit) reaps it. That false "still alive" reading
 * looked exactly like kill_stale_listener() silently failing, when the
 * kill had actually already succeeded - real process state (excluding
 * zombies) needs the actual State field from /proc/<pid>/status.
 */
function pid_alive(int $pid): bool
{
    $status = @file_get_contents("/proc/{$pid}/status");

    if ($status === false) {
        return false;
    }

    return preg_match('/^State:\s+Z/m', $status) !== 1;
}

/**
 * @return array{process: resource, pid: int}
 */
function spawn_harness(string $harnessScript, string $socketPath): array
{
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['php', $harnessScript, $socketPath, 'cat'], $descriptors, $pipes);

    if (!is_resource($process)) {
        fwrite(STDERR, "test_socket_harness: failed to spawn harness\n");
        exit(1);
    }

    fclose($pipes[0]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $pid = proc_get_status($process)['pid'];

    $deadline = microtime(true) + 2.0;
    while (!file_exists($socketPath)) {
        if (microtime(true) > $deadline) {
            fwrite(STDERR, "test_socket_harness: socket {$socketPath} never appeared\n");
            exit(1);
        }
        usleep(10000);
    }

    return ['process' => $process, 'pid' => $pid];
}

$harnessScript = __DIR__ . '/lib/socket_harness.php';
$socketPath = sys_get_temp_dir() . '/sessioneer-test-socket-harness-cleanup-' . getmypid() . '.sock';

$firstProcess = null;
$secondProcess = null;

try {
    // --- happy path: a fresh socket path (nothing stale on it) starts
    // cleanly - kill_stale_listener() finding nothing to kill must never be
    // the reason a legitimate harness start fails ---
    $first = spawn_harness($harnessScript, $socketPath);
    $firstProcess = $first['process'];
    assert_true(pid_alive($first['pid']), 'spawn_harness: the first instance is actually running');

    // --- the real fix: starting a SECOND harness on the exact same,
    // still-in-use socket path must kill the first instance's process, not
    // just steal the socket file out from under it and leave it orphaned ---
    $second = spawn_harness($harnessScript, $socketPath);
    $secondProcess = $second['process'];

    usleep(300000); // let the SIGTERM this sent to the first instance actually land

    assert_true(!pid_alive($first['pid']), 'kill_stale_listener: starting a second harness on the same path kills the first instance\'s process, not just its socket file');
    assert_true(pid_alive($second['pid']), 'kill_stale_listener: the second (new) instance is unaffected and still running');
    // Reap it now rather than leaving a zombie around until this script
    // exits - proc_close() waits for the (already-dead) process and frees
    // its process-table entry.
    proc_close($firstProcess);
    $firstProcess = null;

    // --- the new instance actually works (proves the unlink+rebind after
    // kill_stale_listener() still lands correctly, not just that a process
    // died) ---
    $conn = @stream_socket_client('unix://' . $socketPath, $errno, $errstr, 2);
    assert_true($conn !== false, 'kill_stale_listener: the socket at the reused path is still connectable after the swap');

    if ($conn !== false) {
        fwrite($conn, "ping\n");
        stream_socket_shutdown($conn, STREAM_SHUT_WR);
        $echoed = stream_get_contents($conn);
        fclose($conn);
        assert_equal("ping\n", $echoed, 'kill_stale_listener: the new instance actually serves connections (cat echoes stdin back)');
    }
} finally {
    if ($firstProcess !== null) {
        proc_terminate($firstProcess);
        proc_close($firstProcess);
    }
    if ($secondProcess !== null) {
        proc_terminate($secondProcess);
        proc_close($secondProcess);
    }
    @unlink($socketPath);
}

test_exit();
