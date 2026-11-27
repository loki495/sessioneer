<?php
declare(strict_types=1);

/**
 * Stand-in for host-agent/agent.php for test_cli_control.php: logs every
 * request (one JSON line) to the file named by FAKE_CONTROL_LOG and answers the
 * session-control actions from a script keyed on the target.
 */

$request = json_decode((string)stream_get_contents(STDIN), true);
file_put_contents((string)getenv('FAKE_CONTROL_LOG'), json_encode($request) . "\n", FILE_APPEND);
$target = (string)($request['target'] ?? '');

if ($target === 'missing') {
    echo json_encode(['ok' => false, 'message' => "No tracked session found for 'missing'"]);
    exit;
}

switch ($request['action'] ?? '') {
    case 'launch_show':
        $session = ['ok' => true, 'name' => 'claude-headless-1', 'status' => 'idle', 'agent' => 'claude', 'runtime' => 'headless', 'agent_session_id' => 'conv-1', 'workdir' => '/tmp/p',
            'git_branch' => 'main', 'title' => 'Fix the thing', 'last_turn_error' => null, 'prompt_options' => [], 'prompt_questions' => null,
            'last_message' => ['role' => 'assistant', 'blocks' => [['kind' => 'tool_use', 'text' => 'x'], ['kind' => 'text', 'text' => 'All done.']]]];

        if ($target === 'blocked') {
            $session = ['status' => 'blocked', 'blocked_reason' => 'Do you want to proceed?', 'prompt_context' => 'Write /tmp/x',
                'prompt_options' => [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']]] + $session;
        }

        if ($target === 'questions') {
            $session = ['status' => 'blocked', 'blocked_reason' => 'Which color?',
                'prompt_questions' => [['question' => 'Which color?', 'options' => [['label' => 'Red'], ['label' => 'Blue']]]]] + $session;
        }

        if (isset($request['history'])) {
            $session['history'] = [['role' => 'user', 'blocks' => [['kind' => 'text', 'text' => 'first question']]], ['role' => 'assistant', 'blocks' => [['kind' => 'text', 'text' => 'first answer']]]];
        }

        if ($target === 'mid-turn' && isset($request['history'])) {
            $session['history'] = [
                ['role' => 'user', 'blocks' => [['kind' => 'text', 'text' => 'first question']]],
                ['role' => null, 'blocks' => [['kind' => 'agent_message', 'text' => "**Two** tests fail\n\nsecond line", 'summary' => 'worker-b']]],
                ['role' => null, 'blocks' => [['kind' => 'agent_message', 'text' => 'report without a summary']]],
                ['role' => 'assistant', 'blocks' => [['kind' => 'text', 'text' => 'first answer']]],
            ];
        }

        echo json_encode($session);
        break;

    case 'launch_answer':
        echo json_encode($target === 'idle'
            ? ['ok' => false, 'name' => 'claude-headless-1', 'message' => 'Session claude-headless-1 has no prompt waiting for an answer']
            : ['ok' => true, 'name' => 'claude-headless-1', 'message' => 'Prompt answered']);
        break;

    case 'launch_interrupt':
        echo json_encode(['ok' => true, 'name' => 'claude-headless-1', 'message' => 'Interrupted']);
        break;

    case 'launch_wake':
        echo json_encode(['ok' => true, 'name' => 'claude-headless-1', 'job' => 7, 'message' => 'Wake-up scheduled (at job 7)']);
        break;

    case 'launch_wake_list':
        $flags = (string)getenv('FAKE_CONTROL_LOG');

        if (is_file($flags . '.wake-fail')) {
            echo json_encode(['ok' => false, 'message' => 'The at command is not available (AT_BIN=at)']);
            break;
        }

        $wakes = is_file($flags . '.wake-empty') ? [] : [
            ['job' => 26, 'at' => 1794756600, 'at_local' => '2026-11-15 10:30 EST', 'state' => 'scheduled', 'agent' => 'claude', 'agent_session_id' => 'a5c36bac-1111-4222-8333-444455556666',
                'workdir' => '/tmp/p', 'profile' => 'work', 'session' => 'claude-headless-1794700000', 'prompt_preview' => "The task should've ran. Can you check logs…", 'prompt_length' => 87],
            ['job' => 30, 'at' => 1794700000, 'at_local' => '2026-11-14 18:46 EST', 'state' => 'overdue', 'agent' => 'opencode', 'agent_session_id' => 'ses_abc',
                'workdir' => null, 'profile' => null, 'session' => null, 'prompt_preview' => 'continue', 'prompt_length' => 8],
        ];

        if (($request['include_prompt'] ?? false) === true) {
            $wakes = array_map(static fn (array $w): array => $w + ['prompt' => $w['job'] === 26 ? "The task should've ran. Can you check logs\nand report \$HOME `date`" : 'continue'], $wakes);
        }

        echo json_encode(['ok' => true, 'wakes' => $wakes, 'time_zone' => 'America/New_York']);
        break;

    case 'launch_wake_cancel':
        $job = $request['job'] ?? null;

        if ($job === 26) {
            echo json_encode(['ok' => true, 'job' => 26, 'name' => 'claude-headless-1794700000', 'message' => 'Cancelled wake-up 26 (2026-11-15 10:30 EST)']);
        } elseif ($job === 27) {
            echo json_encode(['ok' => true, 'job' => 27, 'name' => '', 'message' => 'Cancelled wake-up 27 (2026-11-16 08:00 EST)']);
        } elseif ($job === 98) {
            echo json_encode(['ok' => false, 'message' => 'Wake-up 98 is already running']);
        } elseif ($job === 97) {
            echo json_encode(['ok' => false, 'message' => 'Job 97 is not a Sessioneer wake-up']);
        } elseif (is_int($job) && $job > 0) {
            echo json_encode(['ok' => false, 'gone' => true, 'message' => "Wake-up {$job} no longer exists: it already ran or was cancelled"]);
        } else {
            echo json_encode(['ok' => false, 'message' => 'Needs an at job number']);
        }
        break;

    case 'launch_bare':
        echo json_encode(['ok' => true, 'bare' => [
            ['pid' => 4242, 'cwd' => '/tmp/p', 'resolved_agent_session_id' => 'conv-1', 'resolved_title' => 'Fix the thing'],
            ['pid' => 4343, 'cwd' => '/tmp/q'],
        ]]);
        break;

    case 'launch_take_over':
        if ($target === '5555' && !isset($request['agent_session_id'])) {
            echo json_encode(['ok' => true, 'needs_choice' => true, 'pid' => 5555, 'workdir' => '/tmp/q', 'suggested_agent_session_id' => 'conv-9',
                'candidates' => [['agent_session_id' => 'conv-9', 'title' => 'Nine'], ['agent_session_id' => 'conv-8', 'title' => 'Eight']]]);
        } elseif ($target === 'gone') {
            echo json_encode(['ok' => false, 'message' => 'Rejected: not a currently running claude process']);
        } else {
            echo json_encode(['ok' => true, 'name' => 'claude-headless-taken', 'message' => 'Resumed headless']);
        }
        break;

    case 'launch_send':
        echo json_encode(['ok' => true, 'name' => 'claude-headless-1', 'action' => 'sent', 'prompt_sent' => true, 'message' => 'Message delivered']);
        break;

    case 'launch_kill':
        echo json_encode(['ok' => true, 'name' => $target, 'message' => "Session {$target} stopped; its conversation can be resumed"]);
        break;

    default:
        echo json_encode(['ok' => false, 'message' => 'unexpected']);
}
