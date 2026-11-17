<?php

declare(strict_types=1);

$server = stream_socket_server('unix://' . $argv[1], $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}
while (($connection = stream_socket_accept($server, 60)) !== false) {
    $request = fgets($connection);
    file_put_contents($argv[2], (string) $request, FILE_APPEND);
    fwrite($connection, json_encode(['ok' => true, 'result' => ['rateLimits' => [
        'primary' => ['usedPercent' => 41, 'resetsAt' => (int) $argv[3]],
        'secondary' => ['usedPercent' => 52, 'resetsAt' => (int) $argv[4]],
    ]]]) . "\n");
    fclose($connection);
}
