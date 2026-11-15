<?php
declare(strict_types=1);

/**
 * Stand-in for the host agent for test_ui_wakes.php: answers launch_wake_list / launch_wake_show /
 * launch_wake_cancel from a script keyed on the target or job number, logs every request (one JSON
 * line) to FAKE_WAKE_UI_LOG, and hands every other action to the canned agent the other UI tests use,
 * so the dashboard still renders around it.
 */

$input = (string)stream_get_contents(STDIN);
$request = json_decode($input, true);
file_put_contents((string)getenv('FAKE_WAKE_UI_LOG'), json_encode($request) . "\n", FILE_APPEND);
$action = (string)($request['action'] ?? '');

const WAKE_HOSTILE_PREVIEW = '<script>alert(1)</script> " onmouseover="alert(2)" \'x\' & <b>bold</b>';
const WAKE_HOSTILE_PROMPT = "<script>alert(1)</script>\n\" onmouseover=\"alert(2)\" 'x' & \$HOME `date` \\ end\n--workdir";

/** @return array<string, mixed> */
function wake_row(int $job, string $state, ?string $session, string $preview, ?string $title = null): array
{
    return ['job' => $job, 'at' => 1794756600 + $job, 'at_local' => '2026-11-15 10:30 EST', 'state' => $state, 'agent' => 'claude', 'agent_session_id' => 'conv-' . $job,
        'workdir' => '/tmp/p', 'profile' => null, 'session' => $session, 'title' => $title, 'prompt_preview' => $preview, 'prompt_length' => 99];
}

if ($action === 'launch_wake_list') {
    $target = (string)($request['target'] ?? '');
    $wakes = match ($target) {
        'only-running' => [wake_row(12, 'running', 'claude-headless-run', 'running now')],
        'only-scheduled' => [wake_row(11, 'scheduled', 'claude-headless-sched', 'scheduled later')],
        'with-session' => [wake_row(13, 'scheduled', 'claude-headless-uniquename9', 'has a session')],
        'empty' => [],
        'hostile' => [wake_row(14, 'scheduled', null, WAKE_HOSTILE_PREVIEW)],
        default => [wake_row(11, 'scheduled', 'claude-headless-sched', 'scheduled later', 'Mail scan designer'), wake_row(12, 'running', 'claude-headless-run', 'running now'), wake_row(15, 'overdue', null, 'overdue one', 'Old archived chat')],
    };

    if ($target === 'boom') {
        echo json_encode(['ok' => false, 'message' => 'Could not read the at queue: at is broken']);
    } elseif ($target === 'missing') {
        echo json_encode(['ok' => false, 'message' => "No tracked session found for 'missing'"]);
    } else {
        echo json_encode(['ok' => true, 'wakes' => $wakes, 'time_zone' => 'America/New_York'] + ($target === 'trunc' ? ['truncated' => true] : []));
    }

    exit;
}

if ($action === 'launch_wake_show') {
    $job = $request['job'] ?? null;
    echo json_encode(match (true) {
        $job === 14 => ['ok' => true, 'wake' => wake_row(14, 'scheduled', null, 'x') + ['prompt' => WAKE_HOSTILE_PROMPT]],
        $job === 11 => ['ok' => true, 'wake' => wake_row(11, 'scheduled', null, 'x') + ['prompt' => WAKE_HOSTILE_PROMPT]],
        $job === 76 => ['ok' => false, 'message' => 'Job 76 is not a Sessioneer wake-up'],
        is_int($job) && $job > 0 => ['ok' => false, 'gone' => true, 'message' => "Wake-up {$job} no longer exists: it already ran or was cancelled"],
        default => ['ok' => false, 'message' => 'Needs an at job number'],
    });
    exit;
}

if ($action === 'launch_wake_cancel') {
    $job = $request['job'] ?? null;
    echo json_encode(match (true) {
        $job === 11 => ['ok' => true, 'job' => 11, 'name' => 'claude-headless-sched', 'message' => 'Cancelled wake-up 11 (2026-11-15 10:30 EST)'],
        $job === 12 => ['ok' => false, 'message' => 'Wake-up 12 is already running'],
        $job === 76 => ['ok' => false, 'message' => 'Job 76 is not a Sessioneer wake-up'],
        is_int($job) && $job > 0 => ['ok' => false, 'gone' => true, 'message' => "Wake-up {$job} no longer exists: it already ran or was cancelled"],
        default => ['ok' => false, 'message' => 'Needs an at job number'],
    });
    exit;
}

$process = proc_open(['php', __DIR__ . '/canned_agent.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
fwrite($pipes[0], $input);
fclose($pipes[0]);
$output = (string)stream_get_contents($pipes[1]);
fclose($pipes[1]);
fclose($pipes[2]);
proc_close($process);

// The dashboard's card chip: one canned session gets a pending wake-up, as the host agent's list would add.
if ($action === 'list') {
    $reply = json_decode($output, true);

    if (is_array($reply) && isset($reply['sessions'][0])) {
        $reply['sessions'][0]['pending_wake'] = ['count' => 2, 'next_at' => 1794756600, 'next_at_local' => '2026-11-15 10:30 EST'];
        $output = json_encode($reply);
    }
}

echo $output;
