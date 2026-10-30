<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/claude_headless.php';

use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SqliteDb;

$root = sys_get_temp_dir() . '/sessioneer-test-effort-' . getmypid();
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
$m = start_manager($root, 'effort', ['FAKE_CLAUDE_EFFORT_CAP' => 'high']);
$name = 'claude-headless-effort';
make_session($root, $name);
assert_equal(true, call($m, 'sessioneer/setEffort', ['session' => $name, 'effort' => 'high'])['ok'], 'dormant session can save effort without spawning');
assert_equal('high', SidecarStore::read_sidecar($name)['effort'], 'saved effort persists for lazy respawn');
assert_equal('dormant', process_of($m, $name), 'saving effort does not start a process');
assert_equal(true, call($m, 'sessioneer/spawn', ['session' => $name, 'fresh' => true, 'model' => 'sonnet'])['ok'], 'fixture child starts');
$read = call($m, 'sessioneer/status', ['session' => $name, 'include_effort' => true]);
assert_equal('high', $read['current_effort'] ?? null, 'read reports the applied launch effort');
assert_equal(true, $read['effort_available'] ?? false, 'supported effort is available');
assert_equal(false, str_contains(json_encode($read), 'fixture-private-setting'), 'effective configuration and sources never escape through status');
$pid = $read['pid'];
assert_equal(true, call($m, 'sessioneer/setEffort', ['session' => $name, 'effort' => 'low'])['ok'], 'effort changes through a live control request');
assert_equal('low', call($m, 'sessioneer/status', ['session' => $name, 'include_effort' => true])['current_effort'] ?? null, 'live read confirms the applied choice');
assert_equal($pid, call($m, 'sessioneer/status', ['session' => $name])['pid'], 'changing effort does not restart the process');
assert_equal('low', SidecarStore::read_sidecar($name)['effort'], 'acknowledged choice persists');
call($m, 'sessioneer/sendInput', ['session' => $name, 'content' => 'PERMISSION']);
assert_true((bool) wait_until(static fn (): bool => status_is($name, 'blocked')), 'fixture blocks on a tool prompt');
$prompt = call($m, 'sessioneer/pendingPrompt', ['session' => $name])['prompt'];
assert_equal(true, call($m, 'sessioneer/setEffort', ['session' => $name, 'effort' => 'max'])['ok'], 'effort can change while a prompt remains pending');
$read = call($m, 'sessioneer/status', ['session' => $name, 'include_effort' => true]);
assert_equal('high', $read['current_effort'] ?? null, 'model cap is reported as applied effort, never guessed from requested max');
assert_equal('blocked', $read['state'], 'settings reads preserve blocked state');
assert_equal($prompt['request_id'], call($m, 'sessioneer/pendingPrompt', ['session' => $name])['prompt']['request_id'], 'the open prompt is preserved');
assert_equal(true, call($m, 'sessioneer/setEffort', ['session' => $name, 'effort' => ''])['ok'], 'explicit default clears the session override');
assert_equal(null, SidecarStore::read_sidecar($name)['effort'], 'cleared effort does not force a level on respawn');
assert_equal('medium', call($m, 'sessioneer/status', ['session' => $name, 'include_effort' => true])['current_effort'] ?? null, 'default resolves to the real CLI level');
foreach ([['effort' => 'bogus'], ['effort' => []], []] as $invalid) {
    assert_equal(false, call($m, 'sessioneer/setEffort', ['session' => $name] + $invalid)['ok'], 'invalid or missing effort is rejected');
    assert_equal(null, SidecarStore::read_sidecar($name)['effort'], 'rejected input does not persist');
}
make_session($root, 'foreign', ['agent' => 'codex']);
assert_equal(false, call($m, 'sessioneer/setEffort', ['session' => 'foreign', 'effort' => 'high'])['ok'], 'another agent cannot be changed through Claude controls');
assert_equal(false, call($m, 'sessioneer/setEffort', ['session' => 'missing', 'effort' => 'high'])['ok'], 'untracked session is rejected');
$reject = start_manager($root, 'reject', ['FAKE_CLAUDE_REJECT_EFFORT' => '1']);
make_session($root, 'claude-headless-reject');
call($reject, 'sessioneer/spawn', ['session' => 'claude-headless-reject', 'fresh' => true]);
assert_equal(false, call($reject, 'sessioneer/setEffort', ['session' => 'claude-headless-reject', 'effort' => 'low'])['ok'], 'CLI rejection reaches the caller');
assert_equal(null, SidecarStore::read_sidecar('claude-headless-reject')['effort'], 'rejected CLI change does not overwrite saved effort');
$old = start_manager($root, 'old', ['FAKE_CLAUDE_NO_EFFORT' => '1']);
make_session($root, 'claude-headless-old');
call($old, 'sessioneer/spawn', ['session' => 'claude-headless-old', 'fresh' => true]);
$oldRead = call($old, 'sessioneer/status', ['session' => 'claude-headless-old', 'include_effort' => true]);
assert_equal(true, $oldRead['ok'], 'unsupported settings read does not break session status');
assert_equal(false, $oldRead['effort_available'] ?? null, 'old CLI reports effort unavailable');
test_exit();
