<?php
declare(strict_types=1);

/** v2 `opencode serve` stub: the shapes OpenCodeServeClient reads. */

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$json = static function (int $code, array $body): void {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($body);
};

if ($path === '/api/session' && $method === 'POST') {
    $json(200, ['data' => ['id' => 'ses_stub_create', 'title' => 'Stub session', 'model' => null]]);
    return;
}

if ($path === '/api/session' && $method === 'GET') {
    $json(200, ['data' => [['id' => 'ses_stub_create'], ['id' => 'ses_stub_other']]]);
    return;
}

if ($path === '/api/session/active' && $method === 'GET') {
    $json(200, ['data' => ['ses_stub_create' => ['type' => 'running']]]);
    return;
}

if ($path === '/api/session/ses_stub_create' && $method === 'GET') {
    $json(200, ['data' => ['id' => 'ses_stub_create', 'model' => ['providerID' => 'stub', 'id' => 'model']]]);
    return;
}

if ($path === '/api/session/ses_stub_create/prompt' && $method === 'POST') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    $json(200, ['data' => ['id' => 'msg_stub', 'sessionID' => 'ses_stub_create', 'type' => 'user', 'payload' => ['text' => is_array($body) ? ($body['text'] ?? '') : ''], 'delivery' => 'steer']]);
    return;
}

if ($path === '/api/session/ses_stub_create/form' && $method === 'GET') {
    $json(200, ['data' => [[
        'id' => 'frm_stub',
        'sessionID' => 'ses_stub_create',
        'title' => 'Questions',
        'metadata' => ['kind' => 'question'],
        'fields' => [[
            'key' => 'q0',
            'title' => 'Approach',
            'description' => 'Which approach?',
            'type' => 'string',
            'options' => [['value' => 'alpha', 'label' => 'Alpha', 'description' => '']],
            'custom' => false,
        ]],
    ]]]);
    return;
}

if ($path === '/api/session/ses_stub_other/form' && $method === 'GET') {
    $json(200, ['data' => []]);
    return;
}

if ($path === '/api/permission/request' && $method === 'GET') {
    $directory = $_GET['location']['directory'] ?? null;

    if ($directory === '/broken') {
        $json(500, ['error' => 'permission list failed']);
        return;
    }

    if ($directory !== '/tmp/project') {
        $json(200, ['location' => ['directory' => $directory], 'data' => []]);
        return;
    }

    $json(200, ['location' => ['directory' => '/tmp/project'], 'data' => [[
        'id' => 'per_stub',
        'sessionID' => 'ses_stub_create',
        'action' => 'shell',
        'resources' => ['ls'],
    ]]]);
    return;
}

if ($path === '/api/session/ses_stub_create/permission/per_stub/reply' && $method === 'POST') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    if (!is_array($body) || ($body['decision'] ?? null) !== 'once') {
        $json(400, ['name' => 'InvalidRequestError', 'message' => 'bad decision']);
        return;
    }
    http_response_code(204);
    return;
}

if ($path === '/api/model' && $method === 'GET') {
    $json(200, ['data' => [
        ['id' => 'stub-a', 'modelID' => 'stub-a', 'providerID' => 'opencode-go', 'name' => 'Stub A', 'family' => 'stub'],
        ['id' => 'stub-a', 'modelID' => 'stub-a', 'providerID' => 'openai', 'name' => 'Stub A (openai)', 'family' => 'stub'],
        ['id' => 'stub-b', 'modelID' => 'stub-b', 'providerID' => 'openai', 'name' => 'Stub B', 'family' => null],
    ]]);
    return;
}

if ($path === '/api/model/default' && $method === 'GET') {
    $json(200, ['data' => ['id' => 'stub-a', 'modelID' => 'stub-a', 'providerID' => 'opencode-go']]);
    return;
}

if ($path === '/api/session/missing' && $method === 'GET') {
    $json(404, ['error' => 'missing session']);
    return;
}

$json(500, ['error' => 'unexpected stub request']);
