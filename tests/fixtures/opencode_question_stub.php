<?php

// Throwaway HTTP stub for tests - mimics the `opencode serve` /question API
// surface (see OpenCodeQuestionService). Started via `php -S` on a random port
// by test_opencode_question_service.php; OPENCODE_SERVE_URL is pointed here so
// the real serve process is never touched.
//
// GET /question                    -> a canned QuestionRequest for the one
//                                    session we're probing, or [] for others.
// POST /question/{requestID}/reply -> records the answers to a file and
//                                    returns the {"ok":true-ish} shape the
//                                    real server returns.

$stubStateFile = $argv[1] ?? (getenv('SESSIONEER_STUB_STATE') ?: '/tmp/sessioneer-ocq-stub-state.json');

if (!file_exists($stubStateFile)) {
    file_put_contents($stubStateFile, json_encode(['replies' => []]));
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $uri === '/question') {
    // A canned pending question for session ses_stub123; empty for anything else.
    // Mirrors the real QuestionRequest shape: {id, sessionID, questions[]}.
    echo json_encode([[
        'id' => 'que_stubrequest',
        'sessionID' => 'ses_stub123',
        'questions' => [[
            'question' => 'Which approach?',
            'header' => 'Approach',
            'options' => [
                ['label' => 'Alpha', 'description' => 'first'],
                ['label' => 'Beta', 'description' => 'second'],
            ],
            'multiple' => false,
            'custom' => false,
        ]],
        'tool' => ['messageID' => 'msg_stub', 'callID' => 'call_stub'],
    ]]);
    exit;
}

if ($method === 'POST' && preg_match('#^/api/session/([^/]+)/question/([A-Za-z0-9_]+)/reply$#', $uri, $m)) {
    $body = json_decode((string)file_get_contents('php://input'), true);
    $state = json_decode((string)file_get_contents($stubStateFile), true) ?: ['replies' => []];
    $state['v2'][] = [
        'sessionID' => rawurldecode($m[1]),
        'requestID' => $m[2],
        'answers' => $body['answers'] ?? [],
        'directory' => $_SERVER['HTTP_X_OPENCODE_DIRECTORY'] ?? null,
    ];
    file_put_contents($stubStateFile, json_encode($state));

    $mode = $state['mode'] ?? 'v2_success';
    if ($mode === 'v2_not_found' || $mode === 'v1_not_found') {
        http_response_code(404);
        echo '{"error":"not found"}';
    } elseif ($mode === 'v2_server_error') {
        http_response_code(500);
        echo '{"error":"broken"}';
    } elseif ($mode === 'v2_html_200') {
        http_response_code(200);
        echo '<html>unexpected proxy response</html>';
    } else {
        http_response_code(204);
    }
    exit;
}

if ($method === 'POST' && preg_match('#^/question/([A-Za-z0-9_]+)/reply$#', $uri, $m)) {
    $body = json_decode((string)file_get_contents('php://input'), true);
    $state = json_decode((string)file_get_contents($stubStateFile), true) ?: ['replies' => []];
    $state['replies'][] = [
        'requestID' => $m[1],
        'answers' => $body['answers'] ?? [],
        'directory' => $_SERVER['HTTP_X_OPENCODE_DIRECTORY'] ?? null,
    ];
    file_put_contents($stubStateFile, json_encode($state));
    if (($state['mode'] ?? null) === 'v1_not_found') {
        http_response_code(404);
        echo '{"name":"QuestionNotFoundError","message":"Question request not found"}';
        exit;
    }
    echo 'true';
    exit;
}

http_response_code(404);
echo '{"error":"not found"}';
