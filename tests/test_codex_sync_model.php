<?php

declare(strict_types=1);

/**
 * sessioneer_codex_sync() copies each Codex thread's model and reasoning
 * effort onto the session_status row (the dashboard pill reads them there),
 * leaves a blocked row untouched, and the merged headless row carries them as
 * current_model / current_effort. A stub bridge socket serves one thread/list
 * page, so no Codex process or model is involved.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Stores\SessionStatusStore;

$dir = sys_get_temp_dir() . '/sessioneer-codex-sync-' . bin2hex(random_bytes(6));
mkdir($dir, 0700);
putenv('SIDECAR_DIR=' . $dir);
putenv('SESSIONS_SQLITE_FILE=' . $dir . '/sessions.sqlite');
putenv('PUSH_SQLITE_FILE=' . $dir . '/push.sqlite');
putenv('CODEX_BRIDGE_SOCKET=' . $dir . '/bridge.sock');
putenv('HEADLESS_SYNC_SECONDS=0');
putenv('HEADLESS_ACTIVE_WINDOW_SECONDS=3600');

$now = time();
file_put_contents($dir . '/threads.json', json_encode([
    ['id' => 'thr_effort', 'cwd' => '/tmp/a', 'createdAt' => $now - 60, 'updatedAt' => $now, 'status' => ['type' => 'active'], 'model' => 'gpt-5.5', 'reasoningEffort' => 'high'],
    ['id' => 'thr_noeffort', 'cwd' => '/tmp/b', 'createdAt' => $now - 60, 'updatedAt' => $now, 'status' => ['type' => 'idle'], 'model' => 'gpt-5.5'],
    ['id' => 'thr_blocked', 'cwd' => '/tmp/c', 'createdAt' => $now - 60, 'updatedAt' => $now, 'status' => ['type' => 'idle'], 'model' => 'gpt-new', 'reasoningEffort' => 'low'],
]));
$process = proc_open(['php', __DIR__ . '/fixtures/codex_list_stub.php', $dir . '/bridge.sock', $dir . '/threads.json'], [2 => ['file', $dir . '/stub.log', 'a']], $pipes);
if (!is_resource($process)) {
    throw new RuntimeException('Could not start the stub bridge');
}

try {
    for ($i = 0; $i < 50 && !file_exists($dir . '/bridge.sock'); $i++) {
        usleep(50000);
    }
    assert_true(file_exists($dir . '/bridge.sock'), 'stub bridge socket is up');

    SessionStatusStore::update_status('thr_blocked', ['status' => 'blocked', 'blocked' => ['tool_name' => 'exec'], 'model' => 'gpt-old']);
    sessioneer_codex_sync();

    $effort = SessionStatusStore::read_status('thr_effort');
    assert_equal(['working', 'gpt-5.5', 'high'], [$effort['status'], $effort['model'], $effort['effort']], 'sync persists thread model and reasoningEffort');
    $none = SessionStatusStore::read_status('thr_noeffort');
    assert_equal(['gpt-5.5', null], [$none['model'], $none['effort']], 'a thread with no reasoningEffort stores null effort');
    $blocked = SessionStatusStore::read_status('thr_blocked');
    assert_equal(['blocked', 'gpt-old', null], [$blocked['status'], $blocked['model'], $blocked['effort']], 'a blocked row is left untouched by the sync');

    $rows = sessioneer_merge_headless_sessions([]);
    $row = array_values(array_filter($rows, static fn (array $s): bool => ($s['name'] ?? null) === 'thr_effort'))[0] ?? [];
    assert_equal(['gpt-5.5', 'high'], [$row['current_model'] ?? null, $row['current_effort'] ?? null], 'merged Codex row carries current_model and current_effort');
} finally {
    proc_terminate($process);
    proc_close($process);
    foreach (glob($dir . '/*') ?: [] as $file) {
        @unlink($file);
    }
    @rmdir($dir);
}

test_exit();
