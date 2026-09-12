<?php

declare(strict_types=1);

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$dir = sys_get_temp_dir() . '/sessioneer-sse-sync-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
putenv('SIDECAR_DIR=' . $dir);
putenv('SESSIONS_SQLITE_FILE=' . $dir . '/sessions.sqlite');
putenv('PUSH_SQLITE_FILE=' . $dir . '/push.sqlite');
putenv('CODEX_BRIDGE_SOCKET=' . $dir . '/missing.sock');
putenv('HEADLESS_SYNC_SECONDS=0');
putenv('HEADLESS_ACTIVE_WINDOW_SECONDS=3600');
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException($error);
}
$address = stream_socket_get_name($socket, false);
fclose($socket);
$process = proc_open(['php', '-S', $address, __DIR__ . '/fixtures/opencode_sync_stub.php'], [1 => ['file', $dir . '/http.log', 'a'], 2 => ['file', $dir . '/http.log', 'a']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start fixture server');
}
putenv('OPENCODE_SERVE_URL=http://' . $address);

try {
    $ready = false;
    for ($i = 0; $i < 30; $i++) {
        if (@file_get_contents('http://' . $address . '/api/session') !== false) {
            $ready = true;
            break;
        }
        usleep(50000);
    }
    assert_true($ready, 'sync fixture is reachable');
    $prompt = ['source' => 'opencode_sse', 'request_id' => 'que_current', 'tool_name' => 'question', 'question' => 'Choose?', 'tool_input' => ['questions' => []]];
    SessionStatusStore::update_status('ses_waiting', ['status' => 'blocked', 'blocked' => $prompt]);
    SessionStatusStore::update_status('ses_idle', ['status' => 'blocked', 'blocked' => ['tool_name' => 'permission']]);
    sessioneer_headless_sync();
    assert_equal($prompt, SessionStatusStore::read_status('ses_waiting')['blocked'], 'empty lists do not erase an event-fed prompt');
    assert_equal('blocked', SessionStatusStore::read_status('ses_waiting')['status'], 'idle status map cannot override a live question');
    assert_true(SidecarStore::read_sidecar('ses_waiting') !== null, 'question older than activity window remains tracked');
    assert_equal(null, SessionStatusStore::read_status('ses_idle')['blocked'], 'ordinary stale polled prompts still clear');
    assert_equal('idle', SessionStatusStore::read_status('ses_idle')['status'], 'idle sessions still receive polled status');

    assert_equal(false, SessionStatusStore::resolve_opencode_question('ses_waiting', 'que_old'), 'late reply cannot clear a newer request');
    assert_equal(false, SessionStatusStore::resolve_opencode_question('ses_waiting', ''), 'empty request ID does not resolve');
    assert_equal(false, SessionStatusStore::resolve_opencode_question('ses_unknown', 'que_current'), 'unknown session does not resolve');
    assert_equal($prompt, SessionStatusStore::read_status('ses_waiting')['blocked'], 'stale reply preserves prompt');
    assert_equal(true, SessionStatusStore::resolve_opencode_question('ses_waiting', 'que_current'), 'matching request clears atomically');
    assert_equal(false, SessionStatusStore::resolve_opencode_question('ses_waiting', 'que_current'), 'duplicate resolution is a no-op');
    assert_equal('working', SessionStatusStore::read_status('ses_waiting')['status'], 'answer resumes working rather than declaring idle');
    SessionStatusStore::update_status('ses_waiting', ['status' => 'idle', 'blocked' => null], true);
    assert_equal('idle', SessionStatusStore::read_status('ses_waiting')['status'], 'polls resume updating after resolution');

    SessionStatusStore::update_status('ses_idle', ['blocked' => ['tool_name' => 'permission', 'request_id' => 'que_current']]);
    assert_equal(false, SessionStatusStore::resolve_opencode_question('ses_idle', 'que_current'), 'question resolution cannot clear a permission');
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
}
test_exit();
