<?php

declare(strict_types=1);

/**
 * Throwaway `opencode serve` HTTP stub for tests - only the v2 endpoints the
 * host agent's OpenCodeServeClient and OpenCodeQuestionService use, with no
 * real server, model or provider involved. Started via `php -S` on a random
 * port; OPENCODE_SERVE_URL is pointed here.
 *
 * Behaviour is kept in the JSON file in $argv[1]:
 *   sessions        id => {id, directory, title, model:{providerID, modelID}}
 *   prompts         list of {sessionId, text}
 *   working         {sessionId, until} - /api/session/active reports it until
 *                   that unix time; a prompt sets it now + busy_seconds
 *   busy_seconds    how long one prompt looks "working" (default 0)
 */

$stateFile = $argv[1] ?? (getenv('SESSIONEER_OPENCODE_STUB_STATE') ?: '/tmp/sessioneer-opencode-stub-state.json');
$state = is_file($stateFile) ? (json_decode((string)file_get_contents($stateFile), true) ?: []) : [];
$state += ['next_id' => 1, 'sessions' => [], 'prompts' => [], 'working' => null, 'busy_seconds' => 0.0];

$save = static function () use (&$state, $stateFile): void {
    file_put_contents($stateFile, json_encode($state));
};

$out = static function (array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
};

$body = static function (): array {
    $decoded = json_decode((string)file_get_contents('php://input'), true);

    return is_array($decoded) ? $decoded : [];
};

$uri = (string)parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = (string)($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method === 'GET' && $uri === '/api/session/active') {
    $working = is_array($state['working'] ?? null) ? $state['working'] : [];
    $id = is_string($working['sessionId'] ?? null) ? $working['sessionId'] : null;
    $until = is_float($working['until'] ?? null) || is_int($working['until'] ?? null) ? (float)$working['until'] : 0.0;

    $out(['data' => $id !== null && microtime(true) < $until ? [$id => ['type' => 'running']] : []]);
}

if ($method === 'GET' && $uri === '/api/session') {
    $out(['data' => array_values($state['sessions'])]);
}

if ($method === 'POST' && $uri === '/api/session') {
    $input = $body();
    $id = is_string($input['id'] ?? null) && $input['id'] !== '' ? $input['id'] : 'ses_cli_' . (int)$state['next_id'];
    $state['next_id'] = (int)$state['next_id'] + 1;
    $location = is_array($input['location'] ?? null) ? $input['location'] : [];
    $directory = is_string($location['directory'] ?? null) ? $location['directory'] : (is_string($input['directory'] ?? null) ? $input['directory'] : '/tmp');

    if (!isset($state['sessions'][$id])) {
        $state['sessions'][$id] = [
            'id' => $id,
            'directory' => $directory,
            'title' => 'Stub session',
            'model' => ['providerID' => 'stub', 'modelID' => 'stub-model'],
        ];
    }

    $save();
    $out(['data' => $state['sessions'][$id]]);
}

if ($method === 'GET' && preg_match('#^/api/session/([^/]+)/form$#', $uri, $m) === 1) {
    $out(['data' => []]);
}

if ($method === 'POST' && preg_match('#^/api/session/([^/]+)/prompt$#', $uri, $m) === 1) {
    $input = $body();
    $state['prompts'][] = ['sessionId' => urldecode($m[1]), 'text' => is_string($input['text'] ?? null) ? $input['text'] : ''];
    $state['working'] = ['sessionId' => urldecode($m[1]), 'until' => microtime(true) + (float)$state['busy_seconds']];
    $save();
    http_response_code(204);
    exit;
}

if ($method === 'POST' && preg_match('#^/api/session/([^/]+)/model$#', $uri, $m) === 1) {
    $input = $body();
    $id = urldecode($m[1]);

    if (isset($state['sessions'][$id]) && is_array($input['model'] ?? null)) {
        $state['sessions'][$id]['model'] = $input['model'];
        $save();
    }

    http_response_code(204);
    exit;
}

if ($method === 'POST' && preg_match('#^/api/session/([^/]+)/interrupt$#', $uri, $m) === 1) {
    $state['working'] = null;
    $save();
    http_response_code(204);
    exit;
}

if ($method === 'DELETE' && preg_match('#^/api/session/([^/]+)$#', $uri, $m) === 1) {
    unset($state['sessions'][urldecode($m[1])]);
    $save();
    http_response_code(204);
    exit;
}

if ($method === 'GET' && preg_match('#^/api/session/([^/]+)$#', $uri, $m) === 1) {
    $id = urldecode($m[1]);

    if (!isset($state['sessions'][$id])) {
        $out(['error' => 'session not found'], 404);
    }

    $out(['data' => $state['sessions'][$id]]);
}

if ($method === 'GET' && $uri === '/api/model/default') {
    $out(['data' => ['providerID' => 'stub', 'modelID' => 'stub-model']]);
}

if ($method === 'GET' && $uri === '/api/model') {
    $out(['data' => [['providerID' => 'stub', 'modelID' => 'stub-model', 'name' => 'Stub Model', 'family' => 'stub']]]);
}

if ($method === 'GET' && $uri === '/api/permission/request') {
    $out(['data' => []]);
}

$out(['error' => 'not found'], 404);
