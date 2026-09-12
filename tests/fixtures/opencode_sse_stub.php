<?php
declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/api/session') {
    header('Content-Type: application/json');
    echo json_encode(['data' => [
        ['id' => 'ses_sse_a', 'location' => ['directory' => '/fixture/a']],
        ['id' => 'ses_sse_b', 'location' => ['directory' => '/fixture/b']],
    ]]);
    exit;
}
if ($path === '/question') { header('Content-Type: application/json'); echo '[]'; exit; }
if ($path === '/event') {
    header('Content-Type: text/event-stream');
    $dir = rawurldecode((string)($_SERVER['HTTP_X_OPENCODE_DIRECTORY'] ?? ''));
    if (!in_array($dir, ['/fixture/a', '/fixture/b'], true)) { http_response_code(400); echo 'unknown directory'; exit; }
    $id = $dir === '/fixture/a' ? 'ses_sse_a' : 'ses_sse_b';
    $request = $dir === '/fixture/a' ? 'que_sse_a' : 'que_sse_b';
    $statePath = getenv('SESSIONEER_SSE_STUB_STATE') ?: '';
    if ($statePath !== '') {
        $state = is_file($statePath) ? json_decode((string)file_get_contents($statePath), true) : [];
        $state = is_array($state) ? $state : [];
        $state[$dir] = (int)($state[$dir] ?? 0) + 1;
        file_put_contents($statePath, json_encode($state), LOCK_EX);
    }
    echo "data: {\"type\":\"server.connected\"}\n\n";
    $event = json_encode(['type' => 'question.asked', 'properties' => ['id' => $request, 'sessionID' => $id, 'questions' => [['question' => 'Fixture?', 'options' => [['label' => 'Yes']]]]]]);
    // Two writes split one SSE frame at the transport level, without adding
    // a second data line (which would change JSON inside a string literal).
    echo 'data: ' . substr((string)$event, 0, 35);
    flush();
    usleep(10000);
    echo substr((string)$event, 35) . "\n\n";
    flush();
    exit; // force curl EOF; consumer must reap then reconnect.
}
http_response_code(404);
echo '{}';
