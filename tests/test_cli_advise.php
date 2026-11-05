<?php
declare(strict_types=1);

/**
 * `bin/sessioneer --advise` / `--act`: option rules and the handled failures, against the REAL host agent over a real
 * socket on the isolated fixtures (whatever sessions the fixtures track, usually none). The verdict logic itself is covered by
 * test_advise_service.php and test_advise_runner.php. Never spawns claude or a model.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';

$bin = dirname(__DIR__) . '/bin/sessioneer';
$socketPath = sys_get_temp_dir() . '/sessioneer-test-cli-advise.sock';

/** @return array{int, string, string} */
function run_cli(array $args, string $socket): array
{
    $proc = proc_open(['php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['SESSIONEER_AGENT_SOCKET' => $socket] + getenv());
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);

    return [proc_close($proc), $out, $err];
}

[$code, , $err] = run_cli([$bin, '--advise'], sys_get_temp_dir() . '/sessioneer-test-cli-advise-missing.sock');
assert_equal(1, $code, '--advise: exit 1 when the host agent is unreachable');
assert_contains('Cannot reach host agent', $err, '--advise: unreachable agent is a handled message');

$harness = start_harness(['php', dirname(__DIR__) . '/host-agent/agent.php'], $socketPath);

try {
    [$code, $out] = run_cli([$bin, '--advise'], $socketPath);
    assert_equal(0, $code, '--advise: exit 0');
    foreach (array_filter(explode("\n", $out)) as $line) {
        assert_equal(6, count(explode("\t", $line)), '--advise: every table row has name, status, verdict, context, cache and reason');
    }

    [$code, $out] = run_cli([$bin, '--advise', '--json'], $socketPath);
    $json = json_decode($out, true);
    assert_equal(0, $code, '--advise --json: exit 0');
    assert_true(is_array($json['sessions'] ?? null), '--advise --json: a sessions list');

    [$code, , $err] = run_cli([$bin, '--advise', 'no-such-session'], $socketPath);
    assert_equal(1, $code, '--advise NAME: exit 1 for an unknown session');
    assert_contains('No tracked', $err, '--advise NAME: handled message');

    [$code, , $err] = run_cli([$bin, '--advise', '--act'], $socketPath);
    assert_equal(1, $code, '--act without a session: refused');
    assert_contains('exactly one session', $err, '--act without a session: handled message');

    [$code, , $err] = run_cli([$bin, '--act', 'x'], $socketPath);
    assert_equal(1, $code, '--act without --advise: refused');
    assert_contains('only applies to --advise', $err, '--act without --advise: handled message');

    [$code, , $err] = run_cli([$bin, '--advise', '--list'], $socketPath);
    assert_equal(1, $code, '--advise with --list: refused');
    assert_contains('cannot be combined', $err, '--advise with --list: handled message');
} finally {
    stop_harness($harness, $socketPath);
}

echo "OK\n";
