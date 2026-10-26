<?php

declare(strict_types=1);

// Pure framing for the Codex daemon WebSocket: client frames are masked and
// round-trip, server frames split across reads and fragmented messages
// reassemble, ping is answered with a pong, close is reported.

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use HostAgent\Services\CodexWebSocketFrames;

/** Server frames are unmasked; build one by hand. */
function server_frame(string $payload, int $opcode = 1, bool $final = true): string
{
    $length = strlen($payload);
    $header = chr(($final ? 0x80 : 0) | $opcode);
    $header .= $length < 126 ? chr($length) : ($length < 65536 ? chr(126) . pack('n', $length) : chr(127) . pack('J', $length));
    return $header . $payload;
}

foreach ([5, 125, 126, 70000] as $size) {
    $payload = str_repeat('x', $size);
    $frame = CodexWebSocketFrames::encode($payload);
    assert_true((ord($frame[1]) & 0x80) !== 0, "client frame of {$size} bytes is masked");
    // Decoding our own masked frame proves mask + length handling are symmetric.
    $decoded = (new CodexWebSocketFrames())->feed($frame);
    assert_equal([$payload], $decoded['messages'], "round trip of {$size} bytes");
}

$decoder = new CodexWebSocketFrames();
$frame = server_frame('{"a":1}');
assert_equal([], $decoder->feed(substr($frame, 0, 3))['messages'], 'a partial frame yields nothing yet');
assert_equal(['{"a":1}'], $decoder->feed(substr($frame, 3))['messages'], 'the rest completes it');

$both = (new CodexWebSocketFrames())->feed(server_frame('one') . server_frame('two'));
assert_equal(['one', 'two'], $both['messages'], 'two frames in one read');

$fragmented = new CodexWebSocketFrames();
$out = $fragmented->feed(server_frame('hel', 1, false) . server_frame('lo', 0, true));
assert_equal(['hello'], $out['messages'], 'a fragmented message is reassembled');

$ping = (new CodexWebSocketFrames())->feed(server_frame('p', 9));
assert_equal([], $ping['messages'], 'ping is not a message');
assert_true($ping['reply'] !== '' && (ord($ping['reply'][0]) & 0x0F) === 10, 'ping gets a pong');

assert_true((new CodexWebSocketFrames())->feed(server_frame('', 8))['closed'], 'close frame is reported');

assert_true(str_contains(CodexWebSocketFrames::handshake_request(), 'Upgrade: websocket'), 'handshake asks for an upgrade');

echo "ok\n";
