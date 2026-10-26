<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Minimal RFC 6455 client framing for talking to the Codex app-server daemon,
 * which serves its JSON-RPC over a WebSocket on a unix socket (the `codex
 * app-server proxy` command only relays raw bytes, so it cannot do this for us).
 * Pure: bytes in, messages out, no I/O.
 */
final class CodexWebSocketFrames
{
    private string $buffer = '';
    private string $fragments = '';

    /** The HTTP upgrade request that opens the WebSocket. */
    public static function handshake_request(): string
    {
        return "GET / HTTP/1.1\r\nHost: localhost\r\nUpgrade: websocket\r\nConnection: Upgrade\r\n"
            . 'Sec-WebSocket-Key: ' . base64_encode(random_bytes(16)) . "\r\nSec-WebSocket-Version: 13\r\n\r\n";
    }

    /** A masked frame (clients must mask); opcode 1 text, 10 pong. */
    public static function encode(string $payload, int $opcode = 1): string
    {
        $length = strlen($payload);
        $header = chr(0x80 | $opcode);
        if ($length < 126) {
            $header .= chr(0x80 | $length);
        } elseif ($length < 65536) {
            $header .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $header .= chr(0x80 | 127) . pack('J', $length);
        }
        $mask = random_bytes(4);
        return $header . $mask . ($payload ^ str_pad($mask, $length, $mask));
    }

    /**
     * Feeds received bytes; returns the complete text messages they finish
     * and any control frames to send back (`reply`) or `closed` on a close frame.
     *
     * @return array{messages: string[], reply: string, closed: bool}
     */
    public function feed(string $bytes): array
    {
        $this->buffer .= $bytes;
        $out = ['messages' => [], 'reply' => '', 'closed' => false];

        while (strlen($this->buffer) >= 2) {
            $first = ord($this->buffer[0]);
            $second = ord($this->buffer[1]);
            $length = $second & 0x7F;
            $offset = 2;
            if ($length === 126) {
                if (strlen($this->buffer) < 4) break;
                $length = unpack('n', substr($this->buffer, 2, 2))[1];
                $offset = 4;
            } elseif ($length === 127) {
                if (strlen($this->buffer) < 10) break;
                $length = unpack('J', substr($this->buffer, 2, 8))[1];
                $offset = 10;
            }
            // Server frames are unmasked, but tolerate a mask rather than desync.
            $masked = ($second & 0x80) !== 0;
            $maskLength = $masked ? 4 : 0;
            if (strlen($this->buffer) < $offset + $maskLength + $length) break;

            $payload = substr($this->buffer, $offset + $maskLength, $length);
            if ($masked) {
                $mask = substr($this->buffer, $offset, 4);
                $payload ^= str_pad($mask, $length, $mask);
            }
            $this->buffer = substr($this->buffer, $offset + $maskLength + $length);

            $opcode = $first & 0x0F;
            $final = ($first & 0x80) !== 0;
            if ($opcode === 8) {
                $out['closed'] = true;
            } elseif ($opcode === 9) {
                $out['reply'] .= self::encode($payload, 10);
            } elseif ($opcode === 1 || $opcode === 0) {
                $this->fragments .= $payload;
                if ($final) {
                    $out['messages'][] = $this->fragments;
                    $this->fragments = '';
                }
            }
        }

        return $out;
    }
}
