<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

/**
 * One-request/one-response client for a persistent host-native service that
 * speaks newline-delimited JSON over a UNIX socket (the Codex bridge, the
 * Claude headless manager).
 *
 * Failure contract: never throws for an unreachable/slow/garbled service -
 * it returns ['ok' => false, 'message' => ...] like every other runtime call.
 */
class UnixSocketJsonClient
{
    /** Pauses (microseconds) between connection attempts; the first is immediate. */
    private const CONNECT_RETRY_DELAYS_USEC = [0, 250000, 750000, 1500000];

    public function __construct(
        private string $socketPath,
        private string $label,
        private int $timeoutSeconds = 30,
        private bool $retryConnect = true,
    ) {
    }

    /**
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public function request(string $method, array $params = []): array
    {
        $socket = false;
        $error = '';

        // Retry only connection establishment. Once any request bytes have
        // been written, replaying automatically could duplicate a turn or
        // an approval. The bounded delay bridges systemd's ordinary short
        // restart window without making a permanently-down service hang the
        // web request indefinitely. A caller that only wants to know whether
        // the service is there (a listing, a health probe) turns retrying off
        // so an absent service costs one instant refusal, not seconds.
        foreach ($this->retryConnect ? self::CONNECT_RETRY_DELAYS_USEC : [0] as $delayUsec) {
            if ($delayUsec > 0) {
                usleep($delayUsec);
            }
            $socket = @stream_socket_client('unix://' . $this->socketPath, $errno, $error, 0.5);
            if ($socket !== false) {
                break;
            }
        }

        if ($socket === false) {
            return ['ok' => false, 'message' => "Cannot reach {$this->label}: {$error}"];
        }

        stream_set_timeout($socket, $this->timeoutSeconds);
        $payload = json_encode(['method' => $method, 'params' => (object)$params], JSON_UNESCAPED_SLASHES);

        if ($payload === false || fwrite($socket, $payload . "\n") === false) {
            fclose($socket);

            return ['ok' => false, 'message' => "Failed to write to {$this->label}"];
        }

        $line = fgets($socket);
        $meta = stream_get_meta_data($socket);
        fclose($socket);

        if ($line === false) {
            return ['ok' => false, 'message' => !empty($meta['timed_out']) ? "{$this->label} timed out" : "{$this->label} closed the connection"];
        }

        $decoded = json_decode($line, true);

        return is_array($decoded) ? $decoded : ['ok' => false, 'message' => "{$this->label} returned invalid JSON"];
    }
}
