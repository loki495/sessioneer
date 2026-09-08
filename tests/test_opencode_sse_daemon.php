<?php
declare(strict_types=1);

/** Exercises the real daemon against finite, split-frame SSE fixture streams. */
require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$db = sys_get_temp_dir() . '/sessioneer-test-opencode-sse-daemon-' . bin2hex(random_bytes(4)) . '.sqlite';
$state = sys_get_temp_dir() . '/sessioneer-test-opencode-sse-daemon-state-' . bin2hex(random_bytes(4)) . '.json';
putenv('SESSIONS_SQLITE_FILE=' . $db);
putenv('SESSIONEER_SSE_STUB_STATE=' . $state);
SidecarStore::write_sidecar('ses_sse_a', ['agent_session_id' => 'ses_sse_a', 'agent' => 'opencode', 'runtime' => 'headless']);
SidecarStore::write_sidecar('ses_sse_b', ['agent_session_id' => 'ses_sse_b', 'agent' => 'opencode', 'runtime' => 'headless']);
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if ($sock === false) { fwrite(STDERR, "could not reserve a port: {$errstr}\n"); exit(1); }
$address = stream_socket_get_name($sock, false);
fclose($sock);
$port = (int)substr((string)$address, strrpos((string)$address, ':') + 1);
$server = proc_open(['php', '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/opencode_sse_stub.php'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $serverPipes);
if (!is_resource($server)) { fwrite(STDERR, "failed to start SSE fixture\n"); exit(1); }
putenv("OPENCODE_SERVE_URL=http://127.0.0.1:{$port}");
$daemon = null;
$daemonStopped = false;
try {
    for ($i = 0; $i < 20; $i++) { usleep(100000); if (@file_get_contents("http://127.0.0.1:{$port}/api/session") !== false) break; }
    $daemon = proc_open(['php', dirname(__DIR__) . '/host-agent/opencode_sse_consumer.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $daemonPipes);
    assert_true(is_resource($daemon), 'daemon starts against synthetic SSE server');
    for ($i = 0; $i < 80; $i++) {
        usleep(100000);
        if ((SessionStatusStore::read_status('ses_sse_a')['blocked']['request_id'] ?? null) === 'que_sse_a'
            && (SessionStatusStore::read_status('ses_sse_b')['blocked']['request_id'] ?? null) === 'que_sse_b') break;
    }
    assert_equal('que_sse_a', SessionStatusStore::read_status('ses_sse_a')['blocked']['request_id'] ?? null, 'split fixture event from directory A reaches only A');
    assert_equal('que_sse_b', SessionStatusStore::read_status('ses_sse_b')['blocked']['request_id'] ?? null, 'split fixture event from directory B reaches only B');
    for ($i = 0; $i < 140; $i++) {
        usleep(100000);
        $counts = is_file($state) ? json_decode((string)file_get_contents($state), true) : [];
        if (is_array($counts) && ($counts['/fixture/a'] ?? 0) >= 2 && ($counts['/fixture/b'] ?? 0) >= 2) break;
    }
    $counts = is_file($state) ? json_decode((string)file_get_contents($state), true) : [];
    assert_true(is_array($counts) && ($counts['/fixture/a'] ?? 0) >= 2 && ($counts['/fixture/b'] ?? 0) >= 2, 'both finite directory streams reconnect after EOF');
    assert_true((proc_get_status($daemon)['running'] ?? false) === true, 'daemon survives finite streams and reconnects');
} finally {
    if (is_resource($daemon)) {
        proc_terminate($daemon);
        for ($i = 0; $i < 20 && (proc_get_status($daemon)['running'] ?? false); $i++) usleep(100000);
        $daemonStopped = !(proc_get_status($daemon)['running'] ?? false);
        foreach ($daemonPipes ?? [] as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($daemon);
    }
    proc_terminate($server); foreach ($serverPipes as $pipe) if (is_resource($pipe)) fclose($pipe); proc_close($server);
    @unlink($db);
    @unlink($state);
}
assert_true($daemonStopped, 'SIGTERM stops daemon cleanly after stream children are reaped');
echo "OpenCode SSE daemon tests passed.\n";
test_exit();
