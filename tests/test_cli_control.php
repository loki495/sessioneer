<?php
declare(strict_types=1);

/**
 * `bin/sessioneer` session control: --show, --answer, --interrupt, --bare,
 * --take-over, --send --as-user, --kill, --wake-list and --wake-cancel. Checks the option rules (refused
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
        'a stray --at' => [['--at', '+1 hour', '--kill', 'a'], '--at only applies to --wake'],
        'wake without --at' => [['--wake', 'a'], '--wake needs --at TIME'],
        'wake with an unreadable time' => [['--wake', 'a', '--at', 'whenever'], 'is not a time I can read'],
        'wake with kill' => [['--wake', 'a', '--at', '+1 hour', '--kill', 'b'], 'cannot be combined'],
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
        'an empty --set-name' => [['--set-name', '', '--workdir', '/tmp', 'hi'], '--set-name needs a name'],
        'a --set-name with --list' => [['--set-name', 'N', '--list'], 'use --session TARGET to rename an existing one'],
        '--session without --set-name' => [['--session', 'a'], '--session needs --set-name'],
        '--session with a prompt' => [['--session', 'a', '--set-name', 'N', 'hello'], 'renames that session only'],
        '--session with --kill' => [['--session', 'a', '--set-name', 'N', '--kill', 'b'], 'renames that session only'],
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

    // Another agent's message is an entry with no role whose only block is agent_message.
    [$code, $out] = $cli(['--show', 'mid-turn', '--history', '4']);
    assert_equal(0, $code, '--show --history with an agent message: exit 0');
    assert_true(preg_match('/\n\[[^\]\n]*\] \*\*Two\*\* tests fail\n\nsecond line\n/', $out) === 1, '--show --history: an agent message prints its text, verbatim, as its entry');
    assert_true(preg_match('/\n\[[^\]\n]*\] report without a summary\n/', $out) === 1, '--show --history: an agent message without a summary prints its text too');
    assert_true(preg_match('/\n\[[^\]\n]*\] \n/', $out) === 0, '--show --history: no entry is printed empty');
    assert_true(preg_match('/first question.*Two\*\* tests fail.*report without a summary.*first answer/s', $out) === 1, '--show --history: entries stay in order');

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

    echo "--wake\n";
    @unlink($logPath);
    $before = time();
    [$code, $out] = $cli(['--wake', 'blocked', '--at', '+2 hours']);
    assert_equal(0, $code, '--wake: exit 0');
    assert_equal("claude-headless-1\n", $out, '--wake: prints the session name');
    $sent = requests($logPath)[0];
    assert_equal(['launch_wake', 'blocked', 'continue'], [$sent['action'], $sent['target'], $sent['message']], '--wake: the prompt defaults to "continue"');
    assert_true($sent['at'] >= $before + 7200 && $sent['at'] <= time() + 7200, '--wake: --at is sent as a unix time');
    $cli(['--wake', 'blocked', '--at', '+2 hours', 'check the build']);
    assert_equal('check the build', requests($logPath)[1]['message'], '--wake: a given prompt is sent as is');

    echo "--wake-list and --wake-cancel option rules\n";
    $excl = '--kill, --list, --show, --answer, --interrupt, --wake, --wake-list, --wake-cancel, --bare, --take-over, --send, --peers and --usage cannot be combined';
    @unlink($logPath);
    foreach ([
        'wake-cancel with a word' => [['--wake-cancel', 'abc'], '--wake-cancel needs an at job number'],
        'wake-cancel 0' => [['--wake-cancel', '0'], '--wake-cancel needs an at job number'],
        'wake-cancel negative' => [['--wake-cancel=-4'], '--wake-cancel needs an at job number'],
        'wake-cancel a decimal' => [['--wake-cancel', '2.5'], '--wake-cancel needs an at job number'],
        'wake-cancel trailing text' => [['--wake-cancel', '26abc'], '--wake-cancel needs an at job number'],
        'wake-cancel an empty value' => [['--wake-cancel', ''], '--wake-cancel needs an at job number'],
        'wake-list with a prompt' => [['--wake-list', 'hello'], '--wake-list and --wake-cancel take no prompt'],
        'wake-cancel with a prompt' => [['--wake-cancel', '26', 'hello'], '--wake-list and --wake-cancel take no prompt'],
        'wake-list with wake-cancel' => [['--wake-list', '--wake-cancel', '26'], $excl],
        'wake-list with kill' => [['--wake-list', '--kill', 'a'], $excl],
        'wake-cancel with kill' => [['--wake-cancel', '26', '--kill', 'a'], $excl],
        'wake-list with list' => [['--wake-list', '--list'], $excl],
        'wake-list with show' => [['--wake-list', '--show', 'a'], $excl],
        'wake-cancel with send' => [['--wake-cancel', '26', '--send', 'a', 'hi'], $excl],
        'wake-list with wake' => [['--wake-list', '--wake', 'a', '--at', '+1 hour'], $excl],
        'wake-cancel with usage' => [['--wake-cancel', '26', '--usage'], $excl],
        'wake-list with bare' => [['--wake-list', '--bare'], $excl],
        'a stray --at with wake-list' => [['--wake-list', '--at', '+1 hour'], '--at only applies to --wake'],
        'a stray --at with wake-cancel' => [['--wake-cancel', '26', '--at', '+1 hour'], '--at only applies to --wake'],
        'wake-list with --next' => [['--wake-list', '--next'], '--next cannot be combined with --wake-list'],
        'wake-cancel with --rotate' => [['--wake-cancel', '26', '--rotate'], '--rotate cannot be combined with --wake-cancel'],
        'wake-list with --offer' => [['--wake-list', '--offer'], '--offer cannot be combined with --wake-list'],
        'wake-cancel with --next' => [['--next', '--wake-cancel', '26'], '--next cannot be combined with --wake-cancel'],
        'wake-list with --session' => [['--session', 'a', '--set-name', 'N', '--wake-list'], '--session needs --set-name, and renames that session only: no prompt and no other action'],
        'wake-cancel with --session' => [['--wake-cancel', '26', '--session', 'a', '--set-name', 'N'], '--session needs --set-name, and renames that session only: no prompt and no other action'],
        'wake-cancel with a leading zero' => [['--wake-cancel', '011'], '--wake-cancel needs an at job number'],
    ] as $label => [$args, $needle]) {
        [$code, $out, $err] = $cli($args);
        assert_equal(1, $code, "{$label}: exit 1");
        assert_contains($needle, $err, "{$label}: says why");
        assert_equal('', $out, "{$label}: nothing on stdout");
    }

    assert_equal([], requests($logPath), 'none of them reached the host agent');

    echo "--wake-list\n";
    [$code, $out, $err] = $cli(['--wake-list']);
    assert_equal(0, $code, '--wake-list: exit 0');
    assert_equal(
        "26\t2026-11-15 10:30 EST\tscheduled\tclaude-headless-1794700000\ta5c36bac-1111-4222-8333-444455556666\tThe task should've ran. Can you check logs…\n"
        . "30\t2026-11-14 18:46 EST\toverdue\t-\tses_abc\tcontinue\n",
        $out,
        '--wake-list: one tab-separated line per wake: job, local time, state, session or -, conversation id, preview'
    );
    assert_equal('', $err, '--wake-list: nothing on stderr');
    $sent = requests($logPath)[0];
    assert_equal('launch_wake_list', $sent['action'] ?? null, '--wake-list: sends launch_wake_list');
    assert_true(!array_key_exists('include_prompt', $sent) && !array_key_exists('from', $sent), '--wake-list: plain, it sends neither include_prompt nor from');
    $cli(['--wake-list'], '', ['SESSIONEER_SELF' => 'claude-headless-9']);
    assert_true(!array_key_exists('from', requests($logPath)[count(requests($logPath)) - 1]), '--wake-list: inside a session it still sends no from');

    file_put_contents($logPath . '.wake-empty', '1');
    [$code, $out, $err] = $cli(['--wake-list']);
    assert_equal([0, '', ''], [$code, $out, $err], '--wake-list: an empty queue prints nothing and exits 0');
    [$code, $out] = $cli(['--wake-list', '--json']);
    assert_equal(0, $code, '--wake-list --json: an empty queue exits 0');
    assert_equal([], json_decode($out, true)['wakes'] ?? null, '--wake-list --json: with an empty wakes list');
    @unlink($logPath . '.wake-empty');

    [$code, $out, $err] = $cli(['--wake-list', '--json']);
    assert_equal(0, $code, '--wake-list --json: exit 0');
    $json = json_decode($out, true);
    assert_equal(true, $json['ok'] ?? null, '--wake-list --json: the reply is printed as JSON');
    assert_equal([26, 30], array_column($json['wakes'] ?? [], 'job'), '--wake-list --json: every wake');
    assert_equal("The task should've ran. Can you check logs\nand report \$HOME `date`", $json['wakes'][0]['prompt'] ?? null, '--wake-list --json: each wake carries its full prompt');
    assert_equal('America/New_York', $json['time_zone'] ?? null, '--wake-list --json: the whole reply, time zone included');
    assert_contains("\n    ", $out, '--wake-list --json: pretty-printed');
    assert_equal(true, requests($logPath)[count(requests($logPath)) - 1]['include_prompt'] ?? null, '--wake-list --json: sends include_prompt true');

    file_put_contents($logPath . '.wake-fail', '1');
    [$code, $out, $err] = $cli(['--wake-list', '--json']);
    assert_equal(1, $code, '--wake-list --json: a failure exits 1');
    assert_equal(['ok' => false, 'message' => 'The at command is not available (AT_BIN=at)'], json_decode($out, true), '--wake-list --json: the failure reply is on stdout');
    assert_equal('', $err, '--wake-list --json: nothing on stderr');
    [$code, $out, $err] = $cli(['--wake-list']);
    assert_equal(1, $code, '--wake-list: a failure exits 1');
    assert_equal('', $out, '--wake-list: nothing on stdout');
    assert_contains('sessioneer: The at command is not available (AT_BIN=at)', $err, '--wake-list: the message after the sessioneer: prefix');
    @unlink($logPath . '.wake-fail');

    echo "--wake-cancel\n";
    @unlink($logPath);
    [$code, $out, $err] = $cli(['--wake-cancel', '26']);
    assert_equal(0, $code, '--wake-cancel: exit 0');
    assert_equal("claude-headless-1794700000\n", $out, '--wake-cancel: prints the session name');
    assert_contains('Cancelled wake-up 26 (2026-11-15 10:30 EST)', $err, '--wake-cancel: and the message on stderr');
    $sent = requests($logPath)[0];
    assert_equal(['launch_wake_cancel', 26], [$sent['action'] ?? null, $sent['job'] ?? null], '--wake-cancel: sends launch_wake_cancel with the job as a number');

    [$code, $out, $err] = $cli(['--wake-cancel', '27']);
    assert_equal([0, "\n"], [$code, $out], '--wake-cancel: a wake-up with no session prints an empty line');
    assert_contains('Cancelled wake-up 27', $err, '--wake-cancel: with the message on stderr');

    [$code, $out, $err] = $cli(['--wake-cancel', '99']);
    assert_equal(1, $code, '--wake-cancel: a job that is gone exits 1');
    assert_equal('', $out, '--wake-cancel: nothing on stdout');
    assert_contains('sessioneer: Wake-up 99 no longer exists: it already ran or was cancelled', $err, '--wake-cancel: sessioneer: and the reason');
    foreach ([98 => 'Wake-up 98 is already running', 97 => 'Job 97 is not a Sessioneer wake-up'] as $job => $message) {
        [$code, $out, $err] = $cli(['--wake-cancel', (string)$job]);
        assert_equal([1, ''], [$code, $out], "--wake-cancel {$job}: refused, exit 1, nothing on stdout");
        assert_contains('sessioneer: ' . $message, $err, "--wake-cancel {$job}: with the reason");
    }

    [$code, $out] = $cli(['--wake-cancel', '26', '--json']);
    $json = json_decode($out, true);
    assert_equal(0, $code, '--wake-cancel --json: exit 0');
    assert_equal(['ok' => true, 'job' => 26, 'name' => 'claude-headless-1794700000', 'message' => 'Cancelled wake-up 26 (2026-11-15 10:30 EST)'], $json, '--wake-cancel --json: the reply');

    [$code, $out, $err] = $cli(['--wake-cancel', '99', '--json']);
    assert_equal(1, $code, '--wake-cancel --json: a failure exits 1');
    assert_equal(['ok' => false, 'gone' => true, 'message' => 'Wake-up 99 no longer exists: it already ran or was cancelled'], json_decode($out, true), '--wake-cancel --json: the failure reply is on stdout');
    assert_equal('', $err, '--wake-cancel --json: nothing on stderr');

    $cli(['--wake-cancel', '26']);
    assert_true(!array_key_exists('from', requests($logPath)[count(requests($logPath)) - 1]), '--wake-cancel outside a session sends no from');
    $cli(['--wake-cancel', '26'], '', ['SESSIONEER_SELF' => 'claude-headless-9']);
    $last = requests($logPath)[count(requests($logPath)) - 1];
    assert_equal(['launch_wake_cancel', 26, 'claude-headless-9'], [$last['action'] ?? null, $last['job'] ?? null, $last['from'] ?? null], '--wake-cancel inside a session is still sent, with from = SESSIONEER_SELF: the ask-first rule is an instruction, not code');
    $cli(['--wake-cancel', '26'], '', ['CODEX_THREAD_ID' => 'thread-7']);
    assert_equal('thread-7', requests($logPath)[count(requests($logPath)) - 1]['from'] ?? null, '--wake-cancel in a Codex session sends from = CODEX_THREAD_ID');
    $cli(['--wake-cancel', '26'], '', ['SESSIONEER_SELF' => 'claude-headless-9', 'CODEX_THREAD_ID' => 'thread-7']);
    assert_equal('claude-headless-9', requests($logPath)[count(requests($logPath)) - 1]['from'] ?? null, '--wake-cancel: SESSIONEER_SELF wins over CODEX_THREAD_ID');
    $cli(['--wake-list'], '', ['SESSIONEER_SELF' => 'claude-headless-9']);
    assert_equal('launch_wake_list', requests($logPath)[count(requests($logPath)) - 1]['action'] ?? null, '--wake-list works inside a session');

    echo "--help documents the new options and the ask-first rule (indentation is not pinned)\n";
    [$code, $out] = $cli(['--help']);
    assert_equal(0, $code, '--help: exit 0');
    assert_contains('--wake-list', $out, '--help: names --wake-list');
    assert_contains('--wake-cancel', $out, '--help: names --wake-cancel');
    $helpLines = array_map('trim', explode("\n", $out));

    foreach ([
        'Wake-ups:          sessioneer --wake-list [--json]',
        'Cancel a wake-up:  sessioneer --wake-cancel JOB [--json]',
        '--wake-list       list scheduled wake-ups: job, local time, state, session, conversation id, prompt preview',
        '--wake-cancel JOB cancel a scheduled wake-up; a session must ask the user (ask-user tool) before cancelling one the user did not request',
    ] as $line) {
        assert_true(in_array($line, $helpLines, true), "--help: has the line \"{$line}\"");
    }

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

    echo "--session and --set-name\n";
    @unlink($logPath);
    $cli(['--session', 'cc-123', '--set-name', 'My name']);
    $rename = requests($logPath)[0];
    assert_equal(['launch_set_name', 'cc-123', 'My name'], [$rename['action'], $rename['target'], $rename['set_name']], '--session --set-name: renames the target with the name');
    assert_true(!array_key_exists('workdir', $rename), '--session --set-name: sends no working directory');

    echo "--set-name on a new or resumed session\n";
    @unlink($logPath);
    $cli(['--workdir', '/tmp/p', '--set-name', 'Fresh name', 'do the thing']);
    $fresh = requests($logPath)[0];
    assert_equal(['launch', 'Fresh name', 'do the thing'], [$fresh['action'], $fresh['set_name'], $fresh['prompt']], 'a new session carries the name in its launch request');
    $cli(['--resume', 'conv-9', '--set-name', 'Resumed name']);
    $resumed = requests($logPath)[1];
    assert_equal(['launch', 'conv-9', 'Resumed name'], [$resumed['action'], $resumed['agent_session_id'], $resumed['set_name']], 'a resume carries the name in its launch request');
} finally {
    stop_harness($harness, $socketPath);
    @unlink($logPath);
}

test_exit();
