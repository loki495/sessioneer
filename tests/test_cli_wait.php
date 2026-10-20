<?php
declare(strict_types=1);

/**
 * `bin/sessioneer --wait [--timeout N]`: launches, polls until the session is
 * idle and prints only its final assistant text; exits 1 with a handled
 * message on a launch refusal, a turn error, a timeout, a bad --timeout, or
 * combined with an action that has no turn to wait for. Driven by the stub
 * tests/fixtures/fake_wait_agent.php over a real socket; never spawns claude.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';

$bin = dirname(__DIR__) . '/bin/sessioneer';
$socketPath = sys_get_temp_dir() . '/sessioneer-test-cli-wait.sock';

/** @return array{int, string, string} */
function run_cli(array $args, string $socket): array
{
    $proc = proc_open(['php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['SESSIONEER_AGENT_SOCKET' => $socket] + getenv());
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);

    return [proc_close($proc), $out, $err];
}

$harness = start_harness(['php', __DIR__ . '/fixtures/fake_wait_agent.php'], $socketPath);

try {
    [$code, $out, $err] = run_cli([$bin, '--wait', '--workdir', '/tmp', 'ok'], $socketPath);
    assert_equal(0, $code, '--wait: exit 0 once idle');
    assert_equal("final answer\n", $out, '--wait: stdout is only the final assistant text');
    assert_contains('ok', $err, '--wait: the session name goes to stderr');

    [$code, $out, $err] = run_cli([$bin, '--wait', '--workdir', '/tmp', 'err'], $socketPath);
    assert_equal(1, $code, '--wait: a turn error exits 1');
    assert_contains('errored: boom', $err, '--wait: the turn error is a handled message');
    assert_equal('', $out, '--wait: nothing on stdout after an error');

    [$code, , $err] = run_cli([$bin, '--wait', '--timeout', '1', '--workdir', '/tmp', 'hang'], $socketPath);
    assert_equal(1, $code, '--wait --timeout: exit 1 when the session never goes idle');
    assert_contains('timed out after 1s', $err, '--wait --timeout: handled message');

    [$code, , $err] = run_cli([$bin, '--wait', '--workdir', '/tmp', 'refuse'], $socketPath);
    assert_equal(1, $code, '--wait: a refused launch exits 1');
    assert_contains('launch refused', $err, '--wait: the refusal reason is shown');

    [$code, , $err] = run_cli([$bin, '--wait', '--timeout', 'abc', '--workdir', '/tmp', 'ok'], $socketPath);
    assert_equal(1, $code, '--timeout abc: exit 1');
    assert_contains('positive number of seconds', $err, '--timeout abc: handled message');

    [$code, , $err] = run_cli([$bin, '--wait', '--list'], $socketPath);
    assert_equal(1, $code, '--wait with --list: refused');
    assert_contains('only applies to launching', $err, '--wait with --list: handled message');
} finally {
    stop_harness($harness, $socketPath);
}

echo "OK\n";
