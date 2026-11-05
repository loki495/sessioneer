<?php
declare(strict_types=1);

/**
 * `bin/sessioneer` session control: --show, --answer, --interrupt, --bare,
 * --take-over, --send --as-user and --kill. Checks the option rules (refused
 * before any request is sent), the request each flag builds, and what is
 * printed, against the stub tests/fixtures/fake_control_agent.php over a real
 * socket. Never spawns claude.
 */

require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';

$bin = dirname(__DIR__) . '/bin/sessioneer';
$socketPath = sys_get_temp_dir() . '/sessioneer-test-cli-control.sock';
$logPath = sys_get_temp_dir() . '/sessioneer-test-cli-control.log';
@unlink($logPath);
putenv('FAKE_CONTROL_LOG=' . $logPath);

/** @return array{int, string, string} */
function run_cli(array $args, string $socket, string $log, string $stdin = '', array $env = []): array
{
    $proc = proc_open(['php', ...$args], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env + ['SESSIONEER_AGENT_SOCKET' => $socket] + array_diff_key(getenv(), ['SESSIONEER_SELF' => 1, 'CODEX_THREAD_ID' => 1]));
    fwrite($pipes[0], $stdin);
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);

    return [proc_close($proc), $out, $err];
}

/** The requests the stub has received so far. @return array<int, array<string, mixed>> */
function requests(string $log): array
{
    return array_map(static fn (string $l): array => json_decode($l, true), array_values(array_filter(explode("\n", (string)@file_get_contents($log)))));
}

$harness = start_harness(['php', __DIR__ . '/fixtures/fake_control_agent.php'], $socketPath);
$cli = static fn (array $args, string $stdin = '', array $env = []): array => run_cli([$bin, ...$args], $socketPath, $logPath, $stdin, $env);

try {
    echo "Option rules are enforced before any request\n";
    foreach ([
        'combined actions' => [['--show', 'a', '--kill', 'b'], 'cannot be combined'],
        'a stray --history' => [['--history', '3', '--list'], '--history only applies to --show'],
        'a bad --history' => [['--show', 'a', '--history', 'x'], '--history must be a positive number'],
        'a stray --option' => [['--option', '1', '--show', 'a'], '--option only applies to --answer'],
        'a stray --text' => [['--text', 'hi', '--kill', 'a'], '--text only applies to --answer'],
        'a stray --expect-request' => [['--expect-request', 'r', '--show', 'a'], '--expect-request only applies to --answer'],
        'a stray --expect' => [['--expect', 'Yes', '--show', 'a'], '--expect only applies to --answer'],
        'a stray --as-user' => [['--as-user', '--kill', 'a'], '--as-user only applies to --send'],
        'an answer with nothing to answer' => [['--answer', 'a'], 'exactly one of --option N or --answers JSON'],
        'an answer with both' => [['--answer', 'a', '--option', '1', '--answers', '[1]'], 'exactly one of --option N or --answers JSON'],
        'option 0' => [['--answer', 'a', '--option', '0'], '--option must be a positive option number'],
        'option abc' => [['--answer', 'a', '--option', 'abc'], '--option must be a positive option number'],
        'answers that are not JSON' => [['--answer', 'a', '--answers', 'nope'], 'non-empty JSON list'],
        'empty answers' => [['--answer', 'a', '--answers', '[]'], 'non-empty JSON list'],
        'answers with --text' => [['--answer', 'a', '--answers', '[1]', '--text', 'x'], '--text and --expect go with --option'],
        'resume with show' => [['--show', 'a', '--resume', 'b'], '--resume only applies to launching'],
        'wait with show' => [['--show', 'a', '--wait'], 'only applies to launching'],
        'no-tools with take-over' => [['--take-over', '1', '--no-tools'], '--no-tools only applies to starting a new session'],
    ] as $label => [$args, $needle]) {
        [$code, $out, $err] = $cli($args);
        assert_equal(1, $code, "{$label}: exit 1");
        assert_contains($needle, $err, "{$label}: says why");
        assert_equal('', $out, "{$label}: nothing on stdout");
    }

    assert_equal([], requests($logPath), 'none of them reached the host agent');

    echo "--show\n";
    [$code, $out] = $cli(['--show', 'idle-one']);
    assert_equal(0, $code, '--show: exit 0');
    assert_contains("claude-headless-1\tidle\tclaude\theadless\tconv-1", $out, '--show: name, status, agent, runtime and conversation id');
    assert_contains('/tmp/p (main)', $out, '--show: directory and branch');
    assert_contains("Last message (assistant):\nAll done.", $out, '--show: the last message text, without tool blocks');
    assert_true(!str_contains($out, 'Blocked'), '--show: no blocked section when nothing is waiting');
    assert_equal(['action' => 'launch_show', 'target' => 'idle-one'], array_intersect_key(requests($logPath)[0], ['action' => 1, 'target' => 1]), '--show: sends launch_show with the target');

    [, $out] = $cli(['--show', 'blocked']);
    assert_contains("Blocked: Do you want to proceed?\nWrite /tmp/x\n  1. Yes\n  2. No", $out, '--show: a blocked session prints its prompt and numbered options');

    [, $out] = $cli(['--show', 'questions']);
    assert_contains('--answers JSON', $out, '--show: a multi-question prompt says how to answer');
    assert_contains("1. Which color?\n     1. Red\n     2. Blue", $out, '--show: with each question and its options');

    [, $out] = $cli(['--show', 'idle-one', '--history', '2']);
    assert_contains("[user] first question\n\n[assistant] first answer", $out, '--show --history: the transcript entries follow');
    assert_equal(2, requests($logPath)[3]['history'] ?? null, '--show --history: N is sent as a number');

    [$code, $out] = $cli(['--show', 'blocked', '--json']);
    assert_equal('blocked', json_decode($out, true)['status'] ?? null, '--show --json: the whole reply');

    [$code, $out, $err] = $cli(['--show', 'missing']);
    assert_equal(1, $code, '--show: an unknown session exits 1');
    assert_contains('No tracked session found', $err, '--show: with the reason');
    assert_equal('', $out, '--show: and nothing on stdout');

    echo "--answer\n";
    @unlink($logPath);
    [$code, $out, $err] = $cli(['--answer', 'blocked', '--option', '2', '--text', 'not that', '--expect', 'No']);
    assert_equal(0, $code, '--answer --option: exit 0');
    assert_equal("claude-headless-1\n", $out, '--answer: prints the session name');
    assert_contains('Prompt answered', $err, '--answer: and the message on stderr');
    $sent = requests($logPath)[0];
    assert_equal(['launch_answer', 'blocked', 2, 'not that', 'No'], [$sent['action'], $sent['target'], $sent['option'], $sent['text'], $sent['expected_label']], '--answer: the option is sent as a number with its text and expected label');
    assert_true(!array_key_exists('answers', $sent), '--answer --option: no answers key');

    $cli(['--answer', 'questions', '--answers', '[2,[1,2],{"text":"free"}]']);
    assert_equal([2, [1, 2], ['text' => 'free']], requests($logPath)[1]['answers'] ?? null, '--answer --answers: the JSON list is sent decoded');
    assert_true(!array_key_exists('option', requests($logPath)[1]), '--answer --answers: no option key');

    $cli(['--answer', 'questions', '--answers', '-'], '[1]');
    assert_equal([1], requests($logPath)[2]['answers'] ?? null, '--answers -: reads the JSON from stdin');

    $cli(['--answer', 'blocked', '--option', '1'], '', ['SESSIONEER_SELF' => 'claude-headless-9']);
    assert_equal('claude-headless-9', requests($logPath)[3]['from'] ?? null, '--answer inside a session names it, so the host agent can refuse');

    [$code, , $err] = $cli(['--answer', 'idle', '--option', '1']);
    assert_equal(1, $code, '--answer: a refusal exits 1');
    assert_contains('has no prompt waiting', $err, '--answer: with the reason');

    $before = count(requests($logPath));
    $cli(['--answer', 'blocked', '--option', '1', '--expect-request', 'req-7']);
    assert_equal('req-7', requests($logPath)[$before]['expected_request_id'] ?? null, '--answer --expect-request: the request id read from --show is sent');

    echo "--interrupt\n";
    [$code, $out] = $cli(['--interrupt', 'blocked']);
    assert_equal(0, $code, '--interrupt: exit 0');
    assert_equal("claude-headless-1\n", $out, '--interrupt: prints the session name');
    [$code, , $err] = $cli(['--interrupt', 'missing']);
    assert_equal(1, $code, '--interrupt: an unknown session exits 1');
    assert_contains('No tracked session found', $err, '--interrupt: with the reason');

    echo "--bare\n";
    [$code, $out] = $cli(['--bare']);
    assert_equal(0, $code, '--bare: exit 0');
    assert_equal("4242\tconv-1\t/tmp/p\tFix the thing\n4343\t-\t/tmp/q\t-\n", $out, '--bare: pid, conversation id, directory and title, with - for an unknown one');

    echo "--take-over\n";
    @unlink($logPath);
    [$code, $out, $err] = $cli(['--take-over', '4242']);
    assert_equal(0, $code, '--take-over: exit 0');
    assert_equal("claude-headless-taken\n", $out, '--take-over: prints the new session name');
    $sent = requests($logPath)[0];
    assert_equal(['launch_take_over', '4242'], [$sent['action'], $sent['target']], '--take-over: sends the target');
    assert_true(!array_key_exists('workdir', $sent) && !array_key_exists('agent_session_id', $sent), '--take-over: no workdir (the process has its own) and no conversation yet');

    [$code, $out, $err] = $cli(['--take-over', '5555']);
    assert_equal(1, $code, '--take-over: an unsure match exits 1');
    assert_equal("conv-9\tNine\nconv-8\tEight\n", $out, '--take-over: the candidates are listed on stdout');
    assert_contains('nothing was stopped', $err, '--take-over: and it says nothing was stopped');
    assert_contains('--resume ID', $err, '--take-over: and how to choose');
    assert_contains('suggested: conv-9', $err, '--take-over: with the suggestion');

    [$code, , $err] = $cli(['--take-over', '5555', '--resume', 'conv-8']);
    assert_equal(0, $code, '--take-over --resume: the chosen conversation completes it');
    assert_equal('conv-8', requests($logPath)[2]['agent_session_id'] ?? null, '--take-over --resume: the conversation id is sent');

    [$code, , $err] = $cli(['--take-over', 'gone']);
    assert_equal(1, $code, '--take-over: a refusal exits 1');
    assert_contains('not a currently running claude process', $err, '--take-over: with the reason');

    echo "--send --as-user and --kill\n";
    @unlink($logPath);
    [$code, $out] = $cli(['--send', 'blocked', '--as-user', 'go ahead']);
    assert_equal(0, $code, '--send --as-user: exit 0');
    $sent = requests($logPath)[0];
    assert_equal(['launch_send', 'blocked', 'go ahead', true], [$sent['action'], $sent['target'], $sent['message'], $sent['as_user']], '--send --as-user: sends the flag with the message');
    assert_true(!array_key_exists('from', $sent), '--send --as-user: no sender outside a session');

    $cli(['--send', 'blocked', '--as-user', 'x'], '', ['SESSIONEER_SELF' => 'claude-headless-9']);
    assert_equal('claude-headless-9', requests($logPath)[1]['from'] ?? null, '--send --as-user inside a session still names it, so the host agent can refuse');

    [$code, $out] = $cli(['--kill', 'cc-123', '--force']);
    assert_equal(0, $code, '--kill: exit 0');
    assert_equal("cc-123\n", $out, '--kill: prints the target it stopped');
} finally {
    stop_harness($harness, $socketPath);
    @unlink($logPath);
}

test_exit();
