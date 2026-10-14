<?php

declare(strict_types=1);

namespace App;

/**
 * The container never touches tmux or the host process table directly.
 * It only knows how to speak a one-request-one-response JSON protocol
 * over a single UNIX socket, bind-mounted in from the host, where a
 * host-native systemd-activated agent (see host-agent/) does the actual
 * work. See README.md for why: tmux auto-starts a server as a child of
 * whichever process first talks to an unstarted socket, so that process
 * must always be a genuine host process, never this container.
 */
class AgentClient
{
    public static function agent_socket_path(): string
    {
        $path = getenv('SESSIONEER_AGENT_SOCKET');
        return $path !== false && $path !== '' ? $path : '/run/sessioneer-agent.sock';
    }

    /**
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function agent_call(array $request): array
    {
        $socket = @stream_socket_client('unix://' . self::agent_socket_path(), $errno, $errstr, 5);

        if ($socket === false) {
            return [
                'ok' => false,
                'message' => "Cannot reach host agent ({$errstr}). Is the sessioneer-agent.socket systemd unit running on the host?",
            ];
        }

        fwrite($socket, json_encode($request));
        stream_socket_shutdown($socket, STREAM_SHUT_WR);

        $raw = stream_get_contents($socket);
        fclose($socket);

        $decoded = json_decode((string)$raw, true);

        if (!is_array($decoded)) {
            return ['ok' => false, 'message' => 'Malformed response from host agent'];
        }

        return $decoded;
    }
    /** A deadline across connect/write/read, used by Quick Prompt only.
     * Closing this client socket never cancels a managed agent session.
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public static function agent_call_bounded(array $request, float $seconds): array
    {
        if ($seconds <= 0) return ['ok' => false];
        $deadline = microtime(true) + $seconds;
        $socket = @stream_socket_client('unix://' . self::agent_socket_path(), $errno, $errstr, min(0.5, $seconds));
        if ($socket === false) return ['ok' => false];
        stream_set_blocking($socket, false);
        $payload = json_encode($request, JSON_THROW_ON_ERROR);
        $offset = 0;
        $raw = '';
        try {
            while ($offset < strlen($payload)) {
                if (!self::wait_socket($socket, $deadline, true)) return ['ok' => false];
                $written = @fwrite($socket, substr($payload, $offset));
                if ($written === false) return ['ok' => false];
                $offset += $written;
            }
            stream_socket_shutdown($socket, STREAM_SHUT_WR);
            while (!feof($socket)) {
                if (!self::wait_socket($socket, $deadline, false)) return ['ok' => false];
                $chunk = @fread($socket, 65536);
                if ($chunk === false) return ['ok' => false];
                $raw .= $chunk;
                if (strlen($raw) > 1048576) return ['ok' => false];
            }
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : ['ok' => false];
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket */
    private static function wait_socket($socket, float $deadline, bool $writing): bool
    {
        $remaining = $deadline - microtime(true);
        if ($remaining <= 0) return false;
        $read = $writing ? null : [$socket];
        $write = $writing ? [$socket] : null;
        $except = null;
        $seconds = (int)$remaining;
        return @stream_select($read, $write, $except, $seconds, (int)(($remaining - $seconds) * 1000000)) > 0;
    }

}
