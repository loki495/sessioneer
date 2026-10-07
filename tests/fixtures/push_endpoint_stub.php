<?php
// Stand-in push service for tests/test_push.php, run via `php -S`. /ok
// accepts the message (201), /gone answers 410 like a push service does for
// an expired subscription. Each request's method and headers are appended
// to $SESSIONEER_STUB_LOG so the test can check what was actually sent.

$log = getenv('SESSIONEER_STUB_LOG');

if (is_string($log) && $log !== '') {
    file_put_contents($log, json_encode([
        'path' => parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH),
        'method' => $_SERVER['REQUEST_METHOD'],
        'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
        'body_bytes' => strlen((string)file_get_contents('php://input')),
    ]) . "\n", FILE_APPEND);
}

http_response_code(str_starts_with((string)$_SERVER['REQUEST_URI'], '/gone') ? 410 : 201);
