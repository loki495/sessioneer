<?php
declare(strict_types=1);

/**
 * Stand-in for host-agent/agent.php for test_cli_wait.php: a `launch` names a
 * session after its prompt, and `session_detail` replays that scenario - the
 * first poll is working, later ones idle with a final message (scenario "ok"),
 * a turn error ("err"), never idle ("hang"), or idle at first with only the user's message ("late").
 */

$request = json_decode((string)stream_get_contents(STDIN), true);
$state = sys_get_temp_dir() . '/sessioneer-fake-wait-polls';

switch ($request['action'] ?? '') {
    case 'launch':
        if (($request['prompt'] ?? '') === 'refuse') {
            echo json_encode(['ok' => false, 'message' => 'launch refused']);
            break;
        }

        file_put_contents($state, '0');
        echo json_encode(['ok' => true, 'name' => $request['prompt'], 'message' => 'started']);
        break;

    case 'session_detail':
        $polls = (int)@file_get_contents($state) + 1;
        file_put_contents($state, (string)$polls);
        $scenario = $request['session'];
        $idle = $scenario === 'hang' ? false : $polls > 1;
        $entry = ['ok' => true, 'status' => $idle ? 'idle' : 'working', 'last_turn_error' => $idle && $scenario === 'err' ? 'boom' : null];

        if ($scenario === 'late' && $polls <= 3) {
            // A Codex thread that reads idle before its turn starts: the last message is still the user's.
            $entry = ['ok' => true, 'status' => 'idle', 'last_turn_error' => null, 'last_message' => ['role' => 'user', 'blocks' => [['kind' => 'text', 'text' => 'the prompt']]]];
        } elseif ($idle) {
            $entry['last_message'] = ['role' => 'assistant', 'blocks' => [['kind' => 'tool_use', 'text' => 'x'], ['kind' => 'text', 'text' => 'final answer']]];
        }

        echo json_encode($entry);
        break;

    default:
        echo json_encode(['ok' => false, 'message' => 'unexpected']);
}
