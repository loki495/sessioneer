<?php

declare(strict_types=1);

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\CodexHookService;
use HostAgent\Services\Config;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SqliteDb;

$fixtureHome = sys_get_temp_dir() . '/sessioneer-test-codex-hooks-home-' . bin2hex(random_bytes(4));
$statusDb = sys_get_temp_dir() . '/sessioneer-test-codex-hooks-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv("HOME_ROOT={$fixtureHome}");
putenv("SESSIONS_SQLITE_FILE={$statusDb}");
SqliteDb::reset_connections_for_tests();

/** @param array<string, mixed> $payload */
function run_codex_status_hook(array $payload, string $statusDb): array
{
    $process = proc_open(
        ['php', dirname(__DIR__) . '/host-agent/hooks/codex/status.php'],
        [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
        $pipes,
        null,
        array_merge($_ENV, ['SESSIONS_SQLITE_FILE' => $statusDb])
    );

    if (!is_resource($process)) {
        return ['exit' => -1, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }

    fwrite($pipes[0], (string)json_encode($payload));
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

try {
    assert_equal(false, CodexHookService::check_session_hook()['installed'], 'fresh home has no Codex status hooks');

    $path = Config::codex_hooks_path();
    mkdir(dirname($path), 0700, true);
    file_put_contents($path, json_encode([
        'description' => 'personal hooks',
        'hooks' => ['Stop' => [['hooks' => [['type' => 'command', 'command' => 'notify-send done']]]]],
    ]));

    $installed = CodexHookService::install_session_hook();
    assert_equal(true, $installed['installed'] ?? false, 'installer adds every Sessioneer Codex hook');
    $config = json_decode((string)file_get_contents($path), true);
    assert_equal('personal hooks', $config['description'] ?? null, 'installer preserves unrelated top-level settings');
    assert_equal('notify-send done', $config['hooks']['Stop'][0]['hooks'][0]['command'] ?? null, 'installer preserves an unrelated hook for the same event');
    assert_equal(2, count($config['hooks']['Stop'] ?? []), 'installer appends its Stop hook without replacing the existing one');
    assert_equal('*', $config['hooks']['PostToolUse'][0]['matcher'] ?? null, 'PostToolUse observes every completed tool so approved permissions return to working immediately');
    assert_equal(true, CodexHookService::check_session_hook()['installed'], 'checker sees the complete installed hook set');
    CodexHookService::install_session_hook();
    $second = json_decode((string)file_get_contents($path), true);
    assert_equal(2, count($second['hooks']['Stop'] ?? []), 'installer is idempotent');

    file_put_contents($path, '{broken json');
    $malformed = CodexHookService::install_session_hook();
    assert_equal(false, $malformed['ok'] ?? true, 'installer refuses malformed existing Codex config');
    assert_equal('{broken json', file_get_contents($path), 'malformed Codex config is left byte-for-byte unchanged');

    $base = ['session_id' => 'codex-hook-session'];
    $working = run_codex_status_hook($base + ['hook_event_name' => 'UserPromptSubmit', 'prompt' => 'hello'], $statusDb);
    assert_equal(0, $working['exit'], 'UserPromptSubmit observer exits successfully');
    assert_equal("{}\n", $working['stdout'], 'observer always returns neutral JSON');
    assert_equal('working', SessionStatusStore::read_status('codex-hook-session')['status'] ?? null, 'UserPromptSubmit marks the native Codex session id working');

    run_codex_status_hook($base + [
        'hook_event_name' => 'PermissionRequest',
        'tool_name' => 'Bash',
        'tool_input' => ['command' => 'deploy'],
    ], $statusDb);
    $permission = SessionStatusStore::read_status('codex-hook-session');
    assert_equal('blocked', $permission['status'] ?? null, 'PermissionRequest marks the session blocked');
    assert_equal(true, $permission['blocked']['external'] ?? false, 'Remote-owned permission is explicitly externally answerable');
    assert_equal([], $permission['blocked']['options'] ?? null, 'Remote-owned permission exposes no Sessioneer answer options');

    run_codex_status_hook($base + [
        'hook_event_name' => 'PreToolUse',
        'tool_name' => 'request_user_input',
        'tool_input' => ['questions' => [['question' => 'Which environment?']]],
    ], $statusDb);
    $question = SessionStatusStore::read_status('codex-hook-session');
    assert_equal('Input required in Codex Remote.', $question['blocked']['question'] ?? null, 'request_user_input is visible as a Remote-owned input block');
    assert_equal('Which environment?', $question['blocked']['context'] ?? null, 'request_user_input preserves readable question context');

    // A real prompt the bridge holds is answerable; the hook's observe-only notice must not replace it.
    $realPrompt = ['tool_name' => 'permission', 'question' => 'Allow Codex to run this command?', 'options' => [['number' => 1, 'label' => 'Allow once']]];
    SessionStatusStore::update_status('codex-hook-session', ['status' => 'blocked', 'blocked' => $realPrompt]);
    run_codex_status_hook($base + ['hook_event_name' => 'PermissionRequest', 'tool_name' => 'Bash', 'tool_input' => ['command' => 'deploy']], $statusDb);
    $kept = SessionStatusStore::read_status('codex-hook-session');
    assert_equal('Allow Codex to run this command?', $kept['blocked']['question'] ?? null, 'the external notice never replaces a real answerable prompt');

    run_codex_status_hook($base + ['hook_event_name' => 'PostToolUse', 'tool_name' => 'Bash'], $statusDb);
    assert_equal('working', SessionStatusStore::read_status('codex-hook-session')['status'] ?? null, 'the approved Remote tool completing returns the session to working');

    run_codex_status_hook($base + ['hook_event_name' => 'Stop', 'last_assistant_message' => 'Finished'], $statusDb);
    $stopped = SessionStatusStore::read_status('codex-hook-session');
    assert_equal('idle', $stopped['status'] ?? null, 'Stop marks the session idle');
    assert_equal(null, $stopped['blocked'] ?? null, 'Stop clears any blocked state');
    assert_equal('Finished', $stopped['last_message'] ?? null, 'Stop records the last assistant message');

    $invalid = run_codex_status_hook(['hook_event_name' => 'Stop'], '/proc/not-writable/sessioneer.sqlite');
    assert_equal(0, $invalid['exit'], 'internal storage failures never block Codex');
    assert_equal("{}\n", $invalid['stdout'], 'storage failure still returns neutral JSON');
} finally {
    @unlink(Config::codex_hooks_path());
    @rmdir(dirname(Config::codex_hooks_path()));
    @rmdir($fixtureHome);
    SessionStatusStore::delete_status('codex-hook-session');
    SqliteDb::reset_connections_for_tests();
    @unlink($statusDb);
    @unlink($statusDb . '-wal');
    @unlink($statusDb . '-shm');
}

echo "Codex hook tests passed.\n";
test_exit();
