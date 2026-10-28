<?php

declare(strict_types=1);

/**
 * Throwaway Ollama HTTP stub for tests - the API surface OllamaClient uses,
 * with no model and no GPU involved. Started via `php -S` on a random port by
 * tests/test_ollama_agent.php; OLLAMA_URL is pointed here so the host's real
 * Ollama server is never contacted and no test spends minutes on a real model.
 *
 * Behaviour is switched by the JSON file in $argv[1] (the tests rewrite it):
 *   mode normal  - a two-chunk streamed answer
 *   mode slow    - the same answer after a delay (for "still answering")
 *   mode error   - the model reports an error mid-stream
 *
 * GET  /api/version        -> a version string
 * GET  /api/tags           -> one chat model and one embedding model
 * POST /api/show           -> capabilities per model
 * POST /api/chat           -> newline-delimited JSON events
 */

$stateFile = $argv[1] ?? (getenv('SESSIONEER_OLLAMA_STUB_STATE') ?: '/tmp/sessioneer-ollama-stub-state.json');

$state = is_file($stateFile)
    ? (json_decode((string)file_get_contents($stateFile), true) ?: [])
    : [];
$mode = is_string($state['mode'] ?? null) ? $state['mode'] : 'normal';

const CHAT_MODEL = 'chat-one:1b';
const EMBED_MODEL = 'embed-one:latest';

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && $uri === '/api/version') {
    header('Content-Type: application/json');
    echo json_encode(['version' => '0.0.0-stub']);
    exit;
}

if ($method === 'GET' && $uri === '/api/tags') {
    header('Content-Type: application/json');
    echo json_encode(['models' => [
        [
            'name' => CHAT_MODEL,
            'model' => CHAT_MODEL,
            'size' => 123456789,
            'modified_at' => '2026-10-01T10:00:00Z',
            'details' => ['family' => 'llama', 'parameter_size' => '1.0B'],
        ],
        [
            'name' => EMBED_MODEL,
            'model' => EMBED_MODEL,
            'size' => 274302450,
            'modified_at' => '2026-10-01T10:00:00Z',
            'details' => ['family' => 'nomic-bert', 'parameter_size' => '137M'],
        ],
    ]]);
    exit;
}

if ($method === 'POST' && $uri === '/api/show') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    $model = is_array($body) ? ($body['model'] ?? '') : '';

    header('Content-Type: application/json');

    if ($model === CHAT_MODEL) {
        echo json_encode(['capabilities' => ['completion']]);
        exit;
    }

    if ($model === EMBED_MODEL) {
        echo json_encode(['capabilities' => ['embedding']]);
        exit;
    }

    http_response_code(404);
    echo json_encode(['error' => 'model "' . $model . '" not found']);
    exit;
}

if ($method === 'POST' && $uri === '/api/chat') {
    $body = json_decode((string)file_get_contents('php://input'), true);
    $model = is_array($body) ? ($body['model'] ?? '') : '';

    header('Content-Type: application/x-ndjson');

    if ($model !== CHAT_MODEL) {
        http_response_code(404);
        echo json_encode(['error' => 'model "' . $model . '" not found']) . "\n";
        exit;
    }

    $messages = is_array($body['messages'] ?? null) ? $body['messages'] : [];
    $lastUser = '';

    foreach ($messages as $message) {
        if (($message['role'] ?? null) === 'user' && is_string($message['content'] ?? null)) {
            $lastUser = $message['content'];
        }
    }

    // Echoing the prompt back makes a resumed turn's history visible in the
    // transcript, which is how the test proves the whole conversation is
    // replayed rather than only the newest message.
    $reply = 'stub answer to: ' . trim($lastUser);

    if ($mode === 'slow') {
        sleep(3);
    }

    if ($mode === 'error') {
        echo json_encode(['error' => 'the model ran out of memory']) . "\n";
        exit;
    }

    $half = (int)ceil(strlen($reply) / 2);

    foreach ([substr($reply, 0, $half), substr($reply, $half)] as $chunk) {
        echo json_encode([
            'model' => CHAT_MODEL,
            'message' => ['role' => 'assistant', 'content' => $chunk],
            'done' => false,
        ]) . "\n";
        @ob_flush();
        @flush();
    }

    echo json_encode([
        'model' => CHAT_MODEL,
        'message' => ['role' => 'assistant', 'content' => ''],
        'done' => true,
        'done_reason' => 'stop',
        'eval_count' => 12,
        'prompt_eval_count' => 7,
    ]) . "\n";
    exit;
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['error' => 'no such endpoint']);
