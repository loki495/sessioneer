<?php
declare(strict_types=1);

/**
 * `bin/sessioneer --wait [--timeout N] [--json]`: launches, polls until the
 * session is idle and prints only its final assistant text (or, with --json,
 * one envelope object); exits 1 with a handled message on a launch refusal, a
 * turn error, a timeout, a bad --timeout, or combined with an action that has no
 * turn to wait for. Driven by the stub tests/fixtures/fake_wait_agent.php over a
 * real socket; never spawns claude.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';

$bin = dirname(__DIR__) . '/bin/sessioneer';
$socketPath = sys_get_temp_dir() . '/sessioneer-test-cli-wait.sock';
$statePath = sys_get_temp_dir() . '/sessioneer-fake-wait-polls';
@unlink($statePath . '-launches');

/** @return array{int, string, string} */
function run_cli(array $args, string $socket): array
{
    $proc = proc_open(['php', ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['SESSIONEER_AGENT_SOCKET' => $socket] + getenv());
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);

    return [proc_close($proc), $out, $err];
}

/** The `--wait --json` envelope, decoded; fails the test when stdout is not one JSON object. */
function envelope(string $out): array
{
    $decoded = json_decode($out, true);

    if (!is_array($decoded)) {
        assert_true(false, 'stdout is one JSON object (got ' . var_export($out, true) . ')');

        return [];
    }

    assert_true(true, 'stdout is one JSON object');

    return $decoded;
}

/** How many launches the fake host agent has accepted so far. */
function launch_count(string $state): int
{
    $log = (string)@file_get_contents($state . '-launches');

    return $log === '' ? 0 : count(array_filter(explode("\n", $log)));
}

/** A key that must be present and explicitly null (not absent). */
function assert_key_is_null(array $envelope, string $key, string $message): void
{
    assert_true(
        array_key_exists($key, $envelope) && $envelope[$key] === null,
        $message . ' (got ' . (array_key_exists($key, $envelope) ? var_export($envelope[$key], true) : 'no key') . ')',
    );
}

$harness = start_harness(['php', __DIR__ . '/fixtures/fake_wait_agent.php'], $socketPath);

try {
    [$code, $out, $err] = run_cli([$bin, '--wait', '--workdir', '/tmp', 'ok'], $socketPath);
    assert_equal(0, $code, '--wait: exit 0 once idle');
    assert_equal("final answer\n", $out, '--wait: stdout is only the final assistant text');
    assert_contains('ok', $err, '--wait: the session name goes to stderr');

    [$code, $out] = run_cli([$bin, '--agent', 'codex', '--wait', '--workdir', '/tmp', 'late'], $socketPath);
    assert_equal(0, $code, '--wait with codex: exit 0');
    assert_equal("final answer\n", $out, '--wait with codex: an idle thread whose last message is still the user\'s is not done');

    [$code, $out] = run_cli([$bin, '--wait', '--workdir', '/tmp', 'late'], $socketPath);
    assert_equal("the prompt\n", $out, '--wait with claude: idle is trusted as before, whatever the last message is');

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

    // Caught before the request: a bad --timeout must not leave a session behind.
    $before = launch_count($statePath);
    [$code, $out, $err] = run_cli([$bin, '--wait', '--timeout', 'abc', '--workdir', '/tmp', 'ok'], $socketPath);
    assert_equal(1, $code, '--timeout abc: exit 1');
    assert_contains('positive number of seconds', $err, '--timeout abc: handled message');
    assert_equal('', $out, '--timeout abc: nothing on stdout');
    assert_equal($before, launch_count($statePath), '--timeout abc: refused before any launch');

    [$code, , $err] = run_cli([$bin, '--wait', '--list'], $socketPath);
    assert_equal(1, $code, '--wait with --list: refused');
    assert_contains('only applies to launching', $err, '--wait with --list: handled message');

    // --json: one object on stdout a program can trust, everything human on stderr.
    [$code, $out, $err] = run_cli([$bin, '--wait', '--json', '--model', 'sonnet', '--workdir', '/tmp', 'ok'], $socketPath);
    assert_equal(0, $code, '--json: exit 0 on success');
    assert_contains('ok', $err, '--json: the session name still goes to stderr');
    $envelope = envelope($out);
    assert_equal(true, $envelope['ok'] ?? null, '--json: ok is true');
    assert_equal('final answer', $envelope['answer'] ?? null, '--json: answer is the final assistant text, with no added newline');
    assert_equal('ok', $envelope['session'] ?? null, '--json: the session this call created is named');
    assert_equal('conv-ok', $envelope['conversation_id'] ?? null, '--json: the conversation id is carried');
    assert_equal('claude', $envelope['agent'] ?? null, '--json: the agent is carried');
    assert_equal('sonnet', $envelope['model'] ?? null, '--json: the requested model is carried');
    assert_equal('/tmp', $envelope['workdir'] ?? null, '--json: the workdir is carried');
    assert_equal(0, $envelope['exit_code'] ?? null, '--json: exit_code matches the process status');
    assert_key_is_null($envelope, 'error', '--json: no error on success');

    [$code, $out, $err] = run_cli([$bin, '--wait', '--json', '--workdir', '/tmp', 'err'], $socketPath);
    assert_equal(1, $code, '--json: a turn error still exits 1');
    assert_contains('errored: boom', $err, '--json: the turn error stays a human message on stderr');
    $envelope = envelope($out);
    assert_equal(false, $envelope['ok'] ?? null, '--json: a turn error is ok false');
    assert_key_is_null($envelope, 'answer', '--json: a turn error carries no answer');
    assert_equal('err', $envelope['session'] ?? null, '--json: a failed turn still names its session');
    assert_equal('turn_error', $envelope['error']['kind'] ?? null, '--json: the error kind is turn_error');
    assert_contains('errored: boom', (string)($envelope['error']['message'] ?? ''), '--json: the error message is carried');
    assert_equal(1, $envelope['exit_code'] ?? null, '--json: exit_code is 1 on failure');

    [$code, $out] = run_cli([$bin, '--wait', '--json', '--timeout', '1', '--workdir', '/tmp', 'hang'], $socketPath);
    assert_equal(1, $code, '--json: a timeout exits 1');
    $envelope = envelope($out);
    assert_equal('timeout', $envelope['error']['kind'] ?? null, '--json: the error kind is timeout');
    assert_equal('hang', $envelope['session'] ?? null, '--json: a timed-out turn names the session it left running');

    [$code, $out] = run_cli([$bin, '--wait', '--json', '--workdir', '/tmp', 'refuse'], $socketPath);
    assert_equal(1, $code, '--json: a refused launch exits 1');
    $envelope = envelope($out);
    assert_equal('launch_refused', $envelope['error']['kind'] ?? null, '--json: the error kind is launch_refused');
    assert_key_is_null($envelope, 'session', '--json: a refused launch has no session to stop');
    assert_contains('launch refused', (string)($envelope['error']['message'] ?? ''), '--json: the refusal reason is carried');

    [$code, $out, $err] = run_cli([$bin, '--wait', '--json', '--workdir', '/tmp', 'detail-error'], $socketPath);
    assert_equal(1, $code, '--json: an unreadable session exits 1');
    assert_contains('detail unavailable', $err, '--json: the session error stays a human message on stderr');
    $envelope = envelope($out);
    assert_equal('session_error', $envelope['error']['kind'] ?? null, '--json: the error kind is session_error');
    assert_equal('detail-error', $envelope['session'] ?? null, '--json: the session it could not read is named');
} finally {
    stop_harness($harness, $socketPath);
}

test_exit();
