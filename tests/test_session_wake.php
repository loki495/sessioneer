<?php

declare(strict_types=1);

/**
 * `SessionWakeService` and `launch_wake`: the `at` job a wake-up creates. `at` is a recording stand-in
 * (AT_BIN), so no real job is ever queued; the test reads the script it was handed.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';

use HostAgent\Launch\SessionControl;
use HostAgent\Services\SessionWakeService;
use HostAgent\Stores\SidecarStore;

$root = sys_get_temp_dir() . '/sessioneer-test-wake-' . getmypid();
@mkdir($root, 0700, true);
putenv('SIDECAR_DIR=' . $root . '/sidecars');
putenv('SESSIONS_SQLITE_FILE=' . $root . '/sessions.sqlite');
putenv('SESSIONEER_AGENT_SOCKET=');

$fakeAt = $root . '/fake-at';
file_put_contents($fakeAt, <<<'SH'
#!/bin/sh
echo "$@" > "$(dirname "$0")/at-args"
cat > "$(dirname "$0")/at-script"
echo "job 42 at Sat Nov 14 18:30:00 2026" >&2
SH);
chmod($fakeAt, 0700);
putenv('AT_BIN=' . $fakeAt);

SidecarStore::write_sidecar('claude-headless-w1', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'conv-w1', 'agent' => 'claude', 'profile' => 'work']);
SidecarStore::write_sidecar('claude-headless-w2', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent' => 'claude']);
SidecarStore::write_sidecar('oc-w3', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'ses_abc', 'agent' => 'opencode']);

$now = 1_800_000_000;
$when = $now + 7200;

try {
    echo "Refusals create no job\n";
    foreach ([
        'an empty prompt' => ['claude-headless-w1', $when, '  ', 'Write the prompt'],
        'a past time' => ['claude-headless-w1', $now - 5, 'continue', 'at least a minute'],
        'too soon' => ['claude-headless-w1', $now + 10, 'continue', 'at least a minute'],
        'too far' => ['claude-headless-w1', $now + 31 * 86400, 'continue', 'within the next 30 days'],
        'no conversation yet' => ['claude-headless-w2', $when, 'continue', 'no conversation yet'],
        'unknown session' => ['nope', $when, 'continue', 'no conversation yet'],
    ] as $label => [$name, $at, $prompt, $needle]) {
        $r = SessionWakeService::schedule($name, $at, $prompt, $now);
        assert_equal(false, $r['ok'], "{$label}: refused");
        assert_contains($needle, $r['message'], "{$label}: reason");
    }
    assert_true(!file_exists($root . '/at-script'), 'no refusal reached at');

    echo "A Claude session with a profile\n";
    $r = SessionWakeService::schedule('claude-headless-w1', $when, "it's done?\nsecond line", $now);
    assert_equal(true, $r['ok'], 'scheduled');
    assert_equal(42, $r['job'], 'the at job id is returned');
    assert_contains('at job 42', $r['message'], 'the message names the job');
    assert_equal('-t ' . date('YmdHi', $when), trim((string)file_get_contents($root . '/at-args')), 'at gets the time as -t');
    $script = (string)file_get_contents($root . '/at-script');
    assert_true(str_starts_with($script, "env -i 'HOME="), 'the job starts under env -i');
    assert_contains("'--resume' 'conv-w1' '--workdir' '/tmp/proj' '--profile' 'work'", $script, 'resume, workdir and profile');
    assert_contains("'it'\\''s done?\nsecond line'", $script, 'the prompt is shell-quoted whole');
    assert_true(!str_contains($script, 'SESSIONEER_SELF'), 'no session variable is passed on');

    echo "Another agent gets no --profile\n";
    $script = SessionWakeService::script('opencode', 'ses_abc', '/tmp/proj', 'work', 'go');
    assert_true(!str_contains($script, '--profile'), 'opencode: no --profile');
    assert_contains("'--agent' 'opencode'", $script, 'the agent is named');

    echo "launch_wake resolves a conversation id\n";
    $r = SessionControl::wake(['target' => 'conv-w1', 'at' => $when, 'message' => 'continue']);
    assert_equal('claude-headless-w1', $r['name'] ?? null, 'the session name is resolved');
    $r = SessionControl::wake(['target' => 'missing', 'at' => $when, 'message' => 'continue']);
    assert_equal(false, $r['ok'], 'an unknown target is refused');
} finally {
    exec('rm -rf ' . escapeshellarg($root));
}

test_exit();
