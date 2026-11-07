<?php

declare(strict_types=1);

// Answers every Codex bridge request with the one thread/list page in argv[2]
// (a JSON file), on the UNIX socket in argv[1]. Stands in for the real bridge
// so sessioneer_codex_sync() can run with no Codex process.
$server = stream_socket_server('unix://' . $argv[1], $errno, $error);
if ($server === false) {
    fwrite(STDERR, $error . "\n");
    exit(1);
}
$threads = json_decode((string)file_get_contents($argv[2]), true);
while (($conn = stream_socket_accept($server, 60)) !== false) {
    fgets($conn);
    fwrite($conn, json_encode(['ok' => true, 'result' => ['data' => $threads, 'nextCursor' => null]]) . "\n");
    fclose($conn);
}
