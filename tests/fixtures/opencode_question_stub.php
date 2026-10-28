<?php

// Throwaway HTTP stub for tests - mimics the `opencode serve` v2 form API
// surface (see OpenCodeQuestionService). Started via `php -S` on a random port
// by test_opencode_question_service.php; OPENCODE_SERVE_URL is pointed here so
// the real serve process is never touched.
//
// GET  /api/session/{id}/form            -> the canned question form for the
//                                           one session we probe, [] otherwise.
// POST /api/session/{id}/form/{fid}/reply -> records the answer map to a file
//                                           and returns the documented 204.

$stubStateFile = $argv[1] ?? (getenv('SESSIONEER_STUB_STATE') ?: '/tmp/sessioneer-ocq-stub-state.json');

if (!file_exists($stubStateFile)) {
    file_put_contents($stubStateFile, json_encode(['replies' => [], 'v2' => []]));
}

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && preg_match('#^/api/session/([^/]+)/form$#', (string)$uri, $m)) {
    header('Content-Type: application/json');
    if (rawurldecode($m[1]) !== 'ses_stub123') {
        echo '{"data":[]}';
        exit;
    }
    // Mirrors the real Form.Info the `question` tool creates.
    echo json_encode(['data' => [[
        'id' => 'frm_stubrequest',
        'sessionID' => 'ses_stub123',
        'title' => 'Questions',
        'metadata' => ['kind' => 'question', 'tool' => ['messageID' => 'msg_stub', 'id' => 'call_stub']],
        'fields' => [[
            'key' => 'q0',
            'title' => 'Approach',
            'description' => 'Which approach?',
            'type' => 'string',
            'options' => [
                ['value' => 'alpha', 'label' => 'Alpha', 'description' => 'first'],
                ['value' => 'beta', 'label' => 'Beta', 'description' => 'second'],
            ],
            'custom' => false,
        ]],
    ]]]);
    exit;
}

if ($method === 'POST' && preg_match('#^/api/session/([^/]+)/form/([A-Za-z0-9_]+)/reply$#', (string)$uri, $m)) {
    $body = json_decode((string)file_get_contents('php://input'), true);
    $state = json_decode((string)file_get_contents($stubStateFile), true) ?: ['replies' => [], 'v2' => []];
    $state['v2'][] = [
        'sessionID' => rawurldecode($m[1]),
        'requestID' => $m[2],
        'answer' => is_array($body) ? ($body['answer'] ?? []) : [],
        'directory' => $_SERVER['HTTP_X_OPENCODE_DIRECTORY'] ?? null,
    ];
    file_put_contents($stubStateFile, json_encode($state));

    $mode = $state['mode'] ?? 'v2_success';
    if ($mode === 'v2_not_found') {
        http_response_code(404);
        echo '{"name":"FormNotFoundError","message":"Form not found"}';
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

http_response_code(404);
echo '{"error":"not found"}';
