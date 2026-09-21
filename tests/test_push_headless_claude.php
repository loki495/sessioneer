<?php

declare(strict_types=1);

/**
 * Regression: the push-check timer builds its session list through
 * sessioneer_sessions_for_push(), so a headless Claude session that is blocked
 * on a prompt is seen as BLOCKED (and notified with the prompt), not as idle -
 * which used to send a "finished - no input needed" notification the moment a
 * working session started waiting on a permission prompt.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\NotificationContentBuilder;
use HostAgent\Services\PushDeliveryService;
use HostAgent\Stores\PushSessionStateStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$realPushSqliteFile = Config::push_sqlite_path();
$fixtureDir = sys_get_temp_dir() . '/sessioneer-test-push-headless-' . bin2hex(random_bytes(4));
mkdir($fixtureDir . '/work', 0700, true);
putenv("PUSH_SQLITE_FILE={$fixtureDir}/push.sqlite");
putenv('VAPID_PUBLIC_KEY=fake-public-key');
putenv('VAPID_PRIVATE_KEY=fake-private-key');
putenv('PUSH_MIN_WORKING_SECONDS_FOR_FINISH_NOTIFY=60');

if (Config::push_sqlite_path() === $realPushSqliteFile) {
    fwrite(STDERR, "REFUSING TO RUN: PUSH_SQLITE_FILE resolves to the real host state file.\n");
    exit(1);
}

$name = 'claude-headless-t' . getmypid() . '-push';

register_shutdown_function(static function () use ($fixtureDir, $name): void {
    SidecarStore::delete_sidecar($name);
    SessionStatusStore::delete_status($name);
    exec('rm -rf ' . escapeshellarg($fixtureDir));
});

SidecarStore::write_sidecar($name, [
    'workdir' => $fixtureDir . '/work', 'spawned_at' => time(), 'agent_session_id' => null,
    'spawned_by_app' => true, 'agent' => 'claude', 'runtime' => 'headless', 'title' => null, 'profile' => null,
]);

/** @return array<string, mixed> the push list's row for our session */
function push_row(string $name): array
{
    foreach (sessioneer_sessions_for_push() as $row) {
        if (($row['name'] ?? null) === $name) {
            return $row;
        }
    }

    return [];
}

function stored_push_state(string $name): ?string
{
    return PushSessionStateStore::read_push_session_state()[$name]['state'] ?? null;
}

echo "Headless Claude: working\n";
SessionStatusStore::update_status($name, ['status' => 'working', 'blocked' => null]);
$row = push_row($name);
assert_equal('claude', $row['agent'] ?? null, 'the push list includes the headless Claude session under its own agent');
assert_equal('working', NotificationContentBuilder::push_session_state($row), 'a working headless session is working for push');
$t0 = 1_000_000;
assert_equal([], PushDeliveryService::check_and_send_pushes([$row], $t0)['notified'], 'starting work notifies nobody');

echo "Headless Claude: blocked on a permission prompt\n";
SessionStatusStore::update_status($name, ['status' => 'blocked', 'blocked' => [
    'source' => 'claude_headless', 'request_id' => 'req-1', 'tool_name' => 'Write',
    'tool_input' => ['file_path' => '/tmp/live-smoke.txt', 'content' => '1'], 'tool_use_id' => 'toolu_1',
    'description' => null, 'permission_suggestions' => [], 'requires_user_interaction' => false,
]]);
$row = push_row($name);
assert_equal('blocked', NotificationContentBuilder::push_session_state($row), 'a blocked headless prompt is BLOCKED for push, not idle');
assert_true(is_string($row['blocked_reason'] ?? null) && $row['blocked_reason'] !== '', 'it carries the prompt question');
assert_equal('Write', $row['prompt_tool_name'] ?? null, 'and the tool name');
assert_contains('live-smoke.txt', NotificationContentBuilder::push_blocked_body($row), 'the notification body shows what is being asked, not a generic message');
assert_equal(['claude-headless-t' . getmypid() . '-push'], PushDeliveryService::check_and_send_pushes([$row], $t0 + 120)['notified'], 'working -> blocked notifies');
assert_equal('blocked', stored_push_state($name), 'and is recorded as blocked (recorded as idle it would later read as "finished")');
assert_equal([], PushDeliveryService::check_and_send_pushes([$row], $t0 + 240)['notified'], 'the same prompt does not notify again');

echo "Headless Claude: blocked on a question\n";
SessionStatusStore::update_status($name, ['status' => 'working', 'blocked' => null]);
PushDeliveryService::check_and_send_pushes([push_row($name)], $t0 + 300);
SessionStatusStore::update_status($name, ['status' => 'blocked', 'blocked' => [
    'source' => 'claude_headless', 'request_id' => 'req-2', 'tool_name' => 'AskUserQuestion',
    'tool_input' => ['questions' => [['question' => 'Which color?', 'header' => 'Color', 'options' => [['label' => 'Red', 'description' => ''], ['label' => 'Blue', 'description' => '']], 'multiSelect' => false]]],
    'permission_suggestions' => [], 'requires_user_interaction' => true,
]]);
$row = push_row($name);
assert_equal('blocked', NotificationContentBuilder::push_session_state($row), 'a question prompt is blocked for push');
assert_equal('Which color?', $row['blocked_reason'] ?? null, 'with the question text');
assert_equal([$name], PushDeliveryService::check_and_send_pushes([$row], $t0 + 360)['notified'], 'a new prompt notifies again');

echo "Headless Claude: finished\n";
SessionStatusStore::update_status($name, ['status' => 'working', 'blocked' => null]);
PushDeliveryService::check_and_send_pushes([push_row($name)], $t0 + 400);
SessionStatusStore::update_status($name, ['status' => 'idle', 'blocked' => null]);
assert_equal([$name], PushDeliveryService::check_and_send_pushes([push_row($name)], $t0 + 600)['notified'], 'a long turn that finishes with no prompt still gets the finished notification');
assert_equal('idle', stored_push_state($name), 'and is recorded idle');

echo "Headless Claude: answered prompt does not read as finished\n";
SessionStatusStore::update_status($name, ['status' => 'blocked', 'blocked' => [
    'source' => 'claude_headless', 'request_id' => 'req-3', 'tool_name' => 'Write',
    'tool_input' => ['file_path' => '/tmp/x.txt', 'content' => '1'], 'permission_suggestions' => [], 'requires_user_interaction' => false,
]]);
PushDeliveryService::check_and_send_pushes([push_row($name)], $t0 + 700);
SessionStatusStore::update_status($name, ['status' => 'idle', 'blocked' => null]);
assert_equal([], PushDeliveryService::check_and_send_pushes([push_row($name)], $t0 + 900)['notified'], 'blocked -> idle (the prompt was answered/refused) is not a "finished a long task" event');

test_exit();
