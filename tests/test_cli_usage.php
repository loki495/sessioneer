<?php
declare(strict_types=1);

/**
 * `bin/sessioneer --usage`: prints the host agent's last known quota state as
 * JSON (agents, Claude profiles nested under agents.claude.profiles) against the REAL agent over a real socket
 * on the isolated fixtures, and fails with a handled message when the agent
 * is unreachable or combined with another action. Never spawns claude.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';

$bin = dirname(__DIR__) . '/bin/sessioneer';
$socketPath = sys_get_temp_dir() . '/sessioneer-test-cli-usage.sock';

/** @return array{int, string, string} */
function run_cli(array $args, string $socket): array
{
    $proc = proc_open(['php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['SESSIONEER_AGENT_SOCKET' => $socket] + getenv());
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);

    return [proc_close($proc), $out, $err];
}

[$code, , $err] = run_cli([$bin, '--usage'], sys_get_temp_dir() . '/sessioneer-test-cli-usage-missing.sock');
assert_equal(1, $code, '--usage: exit 1 when the host agent is unreachable');
assert_contains('Cannot reach host agent', $err, '--usage: unreachable agent is a handled message, not a stack trace');

$harness = start_harness(['php', dirname(__DIR__) . '/host-agent/agent.php'], $socketPath);

try {
    [$code, $out] = run_cli([$bin, '--usage'], $socketPath);
    $json = json_decode($out, true);
    assert_equal(0, $code, '--usage: exit 0');
    assert_true(is_array($json), '--usage: output is JSON');
    assert_equal(['agents'], array_keys($json), '--usage: agents only at the top level');
    assert_equal(['claude', 'antigravity', 'opencode', 'codex'], array_keys($json['agents']), '--usage: one entry per agent');
    assert_true(array_key_exists('fetched_at', $json['agents']['claude']), '--usage: entries carry fetched_at');
    assert_true(isset($json['agents']['claude']['profiles']['personal']), '--usage: the default Claude account is keyed personal');

    [$code, $out, $err] = run_cli([$bin, '--usage', '--list'], $socketPath);
    assert_equal(1, $code, '--usage with --list: refused');
    assert_contains('cannot be combined', $err, '--usage with --list: handled message');
    assert_equal('', $out, '--usage with --list: nothing on stdout');
} finally {
    stop_harness($harness, $socketPath);
}

echo "OK\n";
