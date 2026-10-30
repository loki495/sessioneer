<?php

declare(strict_types=1);

// Real manager + synthetic stream fixture, isolated paths, no real Claude calls.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/claude_headless.php';

use HostAgent\Services\ContextUsage;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SqliteDb;

$root = sys_get_temp_dir() . '/sessioneer-test-claude-context-' . getmypid();
mkdir($root . '/home/.claude/projects', 0700, true);
putenv('SIDECAR_DIR=' . $root . '/sidecars');
putenv('SESSIONS_SQLITE_FILE=' . $root . '/sessions.sqlite');
$managers = [];
$madeSessions = [];
register_shutdown_function(static function () use (&$managers, $root): void {
    foreach ($managers as $manager) {
        proc_terminate($manager['proc']);
        proc_close($manager['proc']);
    }
    SqliteDb::reset_connections_for_tests();
    exec('rm -rf ' . escapeshellarg($root));
});
$manager = start_manager($root, 'context');
$name = 'claude-headless-context';
make_session($root, $name);
foreach (['CONTEXT' => 10.0, 'CONTEXT_SMALL' => 9.1, 'CONTEXT_BADWINDOW' => null, 'CONTEXT_AGAIN' => 10.0, 'CONTEXT_COMPACT' => null, 'CONTEXT_AFTER_COMPACT' => 10.0] as $prompt => $expected) {
    assert_equal(true, call($manager, 'sessioneer/sendInput', ['session' => $name, 'content' => $prompt])['ok'], 'context fixture prompt delivered: ' . $prompt);
    assert_true((bool)wait_until(static fn (): bool => status_is($name, 'idle') && (call($manager, 'sessioneer/status', ['session' => $name])['last_result']['text'] ?? null) === $prompt), 'context fixture completed: ' . $prompt);
    $status = SessionStatusStore::read_status($name);
    assert_equal($expected, ContextUsage::claude_percentage($status['context_usage'] ?? null), 'manager persists current snapshot: ' . $prompt);
    assert_true(is_array($status['token_usage']), 'cumulative usage remains separate');
}
assert_equal(true, call($manager, 'sessioneer/setModel', ['session' => $name, 'model' => 'new-model'])['ok'], 'model switch accepted');
assert_equal(null, SessionStatusStore::read_status($name)['context_usage'], 'model switch clears old model snapshot immediately');
call($manager, 'sessioneer/sendInput', ['session' => $name, 'content' => 'CONTEXT_NEW_MODEL']);
assert_true((bool)wait_until(static fn (): bool => status_is($name, 'idle') && (call($manager, 'sessioneer/status', ['session' => $name])['last_result']['text'] ?? null) === 'CONTEXT_NEW_MODEL'), 'new model turn completes');
assert_equal('new-model', SessionStatusStore::read_status($name)['context_usage']['model'], 'new model uses its own measured metadata');
assert_equal(true, call($manager, 'sessioneer/stop', ['session' => $name])['ok'], 'fixture child stops');
assert_equal(10.0, ContextUsage::claude_percentage(SessionStatusStore::read_status($name)['context_usage']), 'dormant sessions retain their last known snapshot');
assert_equal(true, call($manager, 'sessioneer/spawn', ['session' => $name])['ok'], 'fixture child respawns');
assert_equal(null, SessionStatusStore::read_status($name)['context_usage'], 'respawn clears the previous process snapshot until its next response');

test_exit();
