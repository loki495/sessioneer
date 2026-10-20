<?php
declare(strict_types=1);

/**
 * `bin/sessioneer --no-tools`: only meaningful for a NEW session, so every
 * other mode and --task-tools is refused with a handled message before the
 * host agent is ever contacted (an unreachable socket proves it).
 */

require __DIR__ . '/lib/assert.php';

$bin = dirname(__DIR__) . '/bin/sessioneer';
$missing = sys_get_temp_dir() . '/sessioneer-test-cli-no-tools-missing.sock';

/** @return array{int, string, string} */
function run_cli(array $args, string $socket): array
{
    $proc = proc_open(['php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['SESSIONEER_AGENT_SOCKET' => $socket] + getenv());
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);

    return [proc_close($proc), $out, $err];
}

foreach ([
    '--resume' => [$bin, '--no-tools', '--resume', 'abc', 'hi'],
    '--kill' => [$bin, '--no-tools', '--kill', 'some-session'],
    '--send' => [$bin, '--no-tools', '--send', 'some-session', 'hi'],
    '--list' => [$bin, '--no-tools', '--list'],
    '--peers' => [$bin, '--no-tools', '--peers'],
] as $mode => $args) {
    [$code, $out, $err] = run_cli($args, $missing);
    assert_equal(1, $code, "--no-tools with {$mode}: exit 1");
    assert_contains('only applies to starting a new session', $err, "--no-tools with {$mode}: handled message");
    assert_equal('', $out, "--no-tools with {$mode}: nothing on stdout");
}

[$code, , $err] = run_cli([$bin, '--no-tools', '--task-tools', 'hi'], $missing);
assert_equal(1, $code, '--no-tools with --task-tools: exit 1');
assert_contains('cannot be combined', $err, '--no-tools with --task-tools: handled message');

[$code, , $err] = run_cli([$bin, '--no-tools', 'hi'], $missing);
assert_equal(1, $code, '--no-tools on a new session passes the option checks and reaches the host agent');
assert_contains('Cannot reach host agent', $err, '--no-tools on a new session: the only failure is the unreachable agent');

echo "OK\n";
