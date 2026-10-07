<?php

declare(strict_types=1);

/**
 * A TCP port on 127.0.0.1 that is free right now, for a test's php -S server.
 * Fixed ports collided with other worktrees' runs and with ad-hoc servers.
 */
function test_free_port(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        throw new RuntimeException("no free port: {$errstr}");
    }
    $port = (int) substr(strrchr((string) stream_socket_get_name($server, false), ':'), 1);
    fclose($server);
    return $port;
}
