<?php

declare(strict_types=1);

/**
 * Sits between a test and a ClaudeHeadlessManager socket: appends every request
 * line to a log, forwards it, and relays the one reply line. Lets a test assert
 * the exact request params a host-agent action sends.
 *
 * Usage: manager_recording_proxy.php <listen socket> <upstream socket> <log file>
 */

[, $listen, $upstream, $log] = $argv;

$server = stream_socket_server('unix://' . $listen, $errno, $error);

if ($server === false) {
    fwrite(STDERR, "proxy cannot listen on {$listen}: {$error}\n");
    exit(1);
}

while (true) {
    $client = @stream_socket_accept($server, 3600.0);

    if ($client === false) {
        continue;
    }

    stream_set_timeout($client, 30);
    $request = fgets($client);

    if ($request !== false) {
        file_put_contents($log, rtrim($request, "\n") . "\n", FILE_APPEND);
        $manager = @stream_socket_client('unix://' . $upstream, $errno, $error, 2.0);

        if ($manager !== false) {
            stream_set_timeout($manager, 30);
            fwrite($manager, $request);
            $reply = fgets($manager);

            if ($reply !== false) {
                fwrite($client, $reply);
            }

            fclose($manager);
        }
    }

    fclose($client);
}
