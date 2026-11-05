<?php

declare(strict_types=1);

// AdviseRunner against isolated fixtures: sidecar rows, fixture transcripts, and injected quota/model/launch/kill.
// No tmux, no host transcripts, no real agent process, no model call.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/../host-agent/lib/Sessions.php';

use HostAgent\Services\AdviseRunner;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SqliteDb;

$root = sys_get_temp_dir() . '/sessioneer-test-advise-runner-' . getmypid();
mkdir($root . '/home/.claude-work/projects/p', 0700, true);
foreach (['SIDECAR_DIR' => '/sidecars', 'SESSIONS_SQLITE_FILE' => '/sessions.sqlite', 'PUSH_SQLITE_FILE' => '/push.sqlite', 'HOME_ROOT' => '/home'] as $key => $path) {
    putenv($key . '=' . $root . $path);
}
putenv('CLAUDE_BIN=');
putenv('CODEX_BIN=');
putenv('OPENCODE_BIN=');
register_shutdown_function(static function () use ($root): void {
    SqliteDb::reset_connections_for_tests();
    exec('rm -rf ' . escapeshellarg($root));
});

function fixture(string $root, string $name, string $id, int $tokens, int $ageSeconds, string $status = 'idle'): void
{
    $path = $root . '/home/.claude-work/projects/p/' . $id . '.jsonl';
    file_put_contents($path, json_encode(['type' => 'assistant', 'message' => ['model' => 'm1', 'usage' => ['input_tokens' => 1, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => $tokens - 1]]]) . "\n");
    touch($path, time() - $ageSeconds);
    SidecarStore::write_sidecar($name, ['workdir' => '/w/' . $name, 'agent_session_id' => $id, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work']);
    SessionStatusStore::write_status($name, ['status' => $status]);
}

fixture($root, 'small-s', '11111111-1111-4111-8111-111111111111', 20000, 7200);
fixture($root, 'big-s', '22222222-2222-4222-8222-222222222222', 200000, 7200);
fixture($root, 'busy-s', '33333333-3333-4333-8333-333333333333', 200000, 7200, 'working');
$ok = ['session' => ['pct' => 3], 'week_all' => ['pct' => 40]];
$deps = [
    'quota' => static fn (): array => ['agents' => [], 'claude_profiles' => ['work' => ['quota' => $ok]]],
    'model_runner' => static function (): never {
        throw new RuntimeException('no model call expected');
    },
];

$table = AdviseRunner::run([], $deps);
$byName = array_column($table['sessions'], null, 'name');
assert_equal(3, count($table['sessions']), 'the table lists every tracked headless Claude session');
assert_equal('resume', $byName['small-s']['advice']['verdict'], 'a small session resumes');
assert_equal('fresh', $byName['big-s']['advice']['verdict'], 'a big session goes fresh');
assert_equal(true, str_contains($byName['big-s']['advice']['prompts']['fresh'], '22222222-2222-4222-8222-222222222222.jsonl'), 'the fresh prompt points the new session at the old transcript');

assert_equal(false, AdviseRunner::run(['target' => 'nope'], $deps)['ok'], 'an unknown target is refused');
assert_equal(false, AdviseRunner::run(['act' => true], $deps)['ok'], '--act without a target is refused');
assert_equal('big-s', AdviseRunner::run(['target' => '22222222-2222-4222-8222-222222222222'], $deps)['sessions'][0]['name'], 'a conversation id selects a session too');

$calls = [];
$deps['launch'] = static function (array $r) use (&$calls): array {
    $calls[] = ['launch', $r];
    return ['ok' => true, 'name' => 'new-s', 'message' => 'ok'];
};
$deps['kill'] = static function (array $r) use (&$calls): array {
    $calls[] = ['kill', $r];
    return ['ok' => true];
};

$resume = AdviseRunner::run(['target' => 'small-s', 'act' => true], $deps);
assert_equal('resume', $resume['acted'], 'act on resume verdict resumes');
assert_equal([['launch', ['agent' => 'claude', 'workdir' => '/w/small-s', 'profile' => 'work', 'agent_session_id' => '11111111-1111-4111-8111-111111111111', 'prompt' => $calls[0][1]['prompt']]]], $calls, 'resume reuses the conversation and never kills anything');

$calls = [];
$fresh = AdviseRunner::run(['target' => 'big-s', 'act' => true, 'model' => 'sonnet'], $deps);
assert_equal(['launch', 'kill'], array_column($calls, 0), 'fresh launches first, then archives the old session');
assert_equal(false, isset($calls[0][1]['agent_session_id']), 'the fresh launch is a new conversation');
assert_equal('sonnet', $calls[0][1]['model'], 'the requested model carries to the fresh session');
assert_equal('big-s', $calls[1][1]['target'], 'the old session is the one archived');
assert_equal('big-s', $fresh['archived'], 'the reply names the archived session');

$calls = [];
$deps['launch'] = static function (array $r) use (&$calls): array {
    $calls[] = ['launch', $r];
    return ['ok' => false, 'name' => 'half', 'message' => 'prompt not delivered'];
};
$failed = AdviseRunner::run(['target' => 'big-s', 'act' => true], $deps);
assert_equal(false, $failed['ok'], 'a fresh session that did not take its prompt fails the act');
assert_equal(['launch'], array_column($calls, 0), 'and the old session is NOT archived');

$calls = [];
assert_equal(false, AdviseRunner::run(['target' => 'busy-s', 'act' => true], $deps)['ok'], 'a working session is refused');
$full = $deps;
$full['quota'] = static fn (): array => ['agents' => [], 'claude_profiles' => ['work' => ['quota' => ['week_all' => ['pct' => 95, 'resets_at' => 2000000000]]]]];
assert_equal(false, AdviseRunner::run(['target' => 'small-s', 'act' => true], $full)['ok'], 'a full quota refuses to act');
assert_equal([], $calls, 'refusals launch nothing');

echo "ok\n";
