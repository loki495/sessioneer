#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Per-request canned agent for test_opencode_question_browser.php. State is
 * deliberately a small local JSON file because socket_harness starts this
 * script once per app request, matching the real one-request host-agent
 * process boundary without talking to tmux or an OpenCode server.
 */

const OPENCODE_QUESTION_BROWSER_SESSION = 'oc-question-browser';

$statePath = (string)getenv('OPENCODE_QUESTION_BROWSER_STATE');
$requestLogPath = (string)getenv('OPENCODE_QUESTION_BROWSER_REQUEST_LOG');
$request = json_decode((string)stream_get_contents(STDIN), true);
$action = is_array($request) ? ($request['action'] ?? null) : null;
$state = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true) : null;
$state = is_array($state) ? $state : ['answered' => false, 'answer' => null, 'history_calls' => 0];

$questionBlock = static function (bool $pending, ?string $answer) use ($state): array {
    return [
        'kind' => 'question',
        'text' => 'Which fruit should the synthetic OpenCode agent use?',
        'question' => 'Which fruit should the synthetic OpenCode agent use?',
        'header' => 'Fruit',
        'options' => [
            ['number' => 1, 'label' => 'Apple'],
            ['number' => 2, 'label' => 'Pear'],
            ['number' => 3, 'label' => 'Type something.'],
        ],
        'questions' => !empty($state['multi']) ? [
            ['question' => 'Select fruits', 'multiple' => true, 'custom' => false, 'options' => [['label' => 'Apple'], ['label' => 'Pear']]],
            ['question' => 'Select color', 'custom' => false, 'options' => [['label' => 'Green']]],
        ] : [],
        'pending' => $pending,
        'answer' => $answer,
    ];
};

if ($action === 'answer_prompt' || $action === 'answer_multi_question') {
    $valid = ($request['session'] ?? null) === OPENCODE_QUESTION_BROWSER_SESSION
        && ($action === 'answer_prompt' ? ($request['option'] ?? null) === 1 : ($request['answers'] ?? null) === [[1, 2], 1]);
    file_put_contents($requestLogPath, json_encode($request) . "\n", FILE_APPEND | LOCK_EX);
    if ($valid) {
        $state['answered'] = true;
        $state['answer'] = $action === 'answer_prompt' ? 'Apple' : 'Apple, Pear; Green';
        file_put_contents($statePath, json_encode($state), LOCK_EX);
    }
    $response = ['ok' => $valid, 'message' => $valid ? 'Synthetic answer accepted' : 'Invalid synthetic selection'];
} elseif ($action === 'answer_prompt_with_text') {
    $text = trim((string)($request['text'] ?? ''));
    $entry = ['action' => $action, 'session' => $request['session'] ?? null, 'option' => $request['option'] ?? null, 'text' => $text];
    file_put_contents($requestLogPath, json_encode($entry) . "\n", FILE_APPEND | LOCK_EX);

    if (($request['session'] ?? null) !== OPENCODE_QUESTION_BROWSER_SESSION || ($request['option'] ?? null) !== 3 || $text === '') {
        $response = ['ok' => false, 'message' => 'Synthetic question request was invalid'];
    } elseif ($text === 'reject') {
        $response = ['ok' => false, 'message' => 'Synthetic OpenCode question rejected this reply'];
    } else {
        $state['answered'] = true;
        $state['answer'] = $text;
        file_put_contents($statePath, json_encode($state), LOCK_EX);
        $response = ['ok' => true, 'message' => 'Synthetic answer accepted'];
    }
} elseif ($action === 'session_detail' && ($request['session'] ?? null) === OPENCODE_QUESTION_BROWSER_SESSION) {
    $response = [
        'ok' => true,
        'name' => OPENCODE_QUESTION_BROWSER_SESSION,
        'agent' => 'opencode',
        'agent_label' => 'OpenCode',
        'activity' => time(),
        'attached' => false,
        'pid' => 4242,
        'workdir' => '/synthetic/opencode-question',
        'spawned_by_app' => true,
        'title' => 'Synthetic OpenCode question',
        'working' => false,
        'blocked_reason' => null,
        'current_mode' => null,
        'agent_session_id' => 'ses_synthetic_question',
        'has_transcript' => true,
        'todos' => [],
    ];
} elseif ($action === 'session_history' && ($request['session'] ?? null) === OPENCODE_QUESTION_BROWSER_SESSION) {
    $answered = !empty($state['answered']);
    $after = isset($request['after']) ? (int)$request['after'] : null;
    if ($answered) {
        $line = 53;
        $entries = $after !== null && $after >= $line ? [] : [[
            'type' => 'assistant',
            'role' => 'assistant',
            'timestamp' => '2026-09-07T00:00:02Z',
            'line' => $line,
            'blocks' => [$questionBlock(false, (string)$state['answer'])],
        ]];
    } elseif ($after !== null && $after < 52) {
        $entries = [[
            'type' => 'assistant',
            'role' => 'assistant',
            'timestamp' => '2026-09-07T00:00:01Z',
            'line' => 52,
            'blocks' => [$questionBlock(true, null)],
        ]];
    } else {
        $entries = [[
            'type' => 'assistant',
            'role' => 'assistant',
            'timestamp' => '2026-09-07T00:00:00Z',
            'line' => 51,
            'blocks' => [$questionBlock(true, null)],
        ]];
    }
    $response = ['ok' => true, 'entries' => $entries, 'next_before' => null, 'has_more' => false];
} else {
    $response = ['ok' => false, 'message' => 'Unsupported synthetic action'];
}

echo json_encode($response);
