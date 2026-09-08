<?php

declare(strict_types=1);

header('Content-Type: application/json');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/api/session') {
    echo json_encode(['data' => [
        ['id' => 'ses_waiting', 'location' => ['directory' => '/tmp/project-a'], 'time' => ['updated' => (time() - 7200) * 1000]],
        ['id' => 'ses_idle', 'location' => ['directory' => '/tmp/project-b'], 'time' => ['updated' => time() * 1000]],
    ]]);
} elseif ($path === '/session/status') {
    echo '{}';
} elseif ($path === '/permission' || $path === '/question') {
    echo '[]';
} elseif ($path === '/config/providers') {
    echo '{"providers":[]}';
} else {
    http_response_code(404);
    echo '{"error":"Unexpected fixture route"}';
}
