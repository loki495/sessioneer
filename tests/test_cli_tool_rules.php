<?php
declare(strict_types=1);

/**
 * `bin/sessioneer --allowed-tools / --disallowed-tools`: Claude, new session only. Every
 * refusal is a handled message before the host agent is contacted (an unreachable socket
 * proves it).
 */

require __DIR__ . '/lib/assert.php';

$bin = dirname(__DIR__) . '/bin/sessioneer';
$missing = sys_get_temp_dir() . '/sessioneer-test-cli-tool-rules-missing.sock';

/** @return array{int, string, string} */
function run_cli(array $args, string $socket): array
{
    $proc = proc_open(['php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['SESSIONEER_AGENT_SOCKET' => $socket] + getenv());
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);

    return [proc_close($proc), $out, $err];
}

foreach (['--allowed-tools', '--disallowed-tools'] as $flag) {
    foreach ([
        '--resume' => [$bin, $flag, 'WebFetch', '--resume', 'abc', 'hi'],
        '--kill' => [$bin, $flag, 'WebFetch', '--kill', 'some-session'],
        '--send' => [$bin, $flag, 'WebFetch', '--send', 'some-session', 'hi'],
        '--list' => [$bin, $flag, 'WebFetch', '--list'],
        '--peers' => [$bin, $flag, 'WebFetch', '--peers'],
    ] as $mode => $args) {
        [$code, $out, $err] = run_cli($args, $missing);
        assert_equal(1, $code, "{$flag} with {$mode}: exit 1");
        assert_contains('only applies to starting a new session', $err, "{$flag} with {$mode}: handled message");
        assert_equal('', $out, "{$flag} with {$mode}: nothing on stdout");
    }

    [$code, , $err] = run_cli([$bin, $flag, 'WebFetch', '--no-tools', 'hi'], $missing);
    assert_equal(1, $code, "{$flag} with --no-tools: exit 1");
    assert_contains('cannot be combined', $err, "{$flag} with --no-tools: handled message");

    [$code, , $err] = run_cli([$bin, $flag, '', 'hi'], $missing);
    assert_equal(1, $code, "{$flag} empty: exit 1");
    assert_contains('non-empty', $err, "{$flag} empty: handled message");

    foreach (['ollama', 'opencode'] as $agent) {
        [$code, , $err] = run_cli([$bin, '--agent', $agent, $flag, 'WebFetch', 'hi'], $missing);
        assert_equal(1, $code, "{$flag} with --agent {$agent}: exit 1");
        assert_contains("{$flag} has no meaning for --agent {$agent}", $err, "{$flag} with --agent {$agent}: handled message");
    }

    [$code, , $err] = run_cli([$bin, $flag, 'WebFetch', 'hi'], $missing);
    assert_equal(1, $code, "{$flag} on a new session passes the option checks and reaches the host agent");
    assert_contains('Cannot reach host agent', $err, "{$flag} on a new session: the only failure is the unreachable agent");
}

// --task-tools is allowed alongside; the lists merge host-side.
[$code, , $err] = run_cli([$bin, '--allowed-tools', 'WebFetch', '--task-tools', '--mode', 'dontAsk', 'hi'], $missing);
assert_contains('Cannot reach host agent', $err, '--allowed-tools with --task-tools and --mode dontAsk reaches the host agent');

echo "OK\n";
