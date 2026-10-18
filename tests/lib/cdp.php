<?php
declare(strict_types=1);

/**
 * A minimal, hand-rolled Chrome DevTools Protocol client - no Node/
 * Playwright/Puppeteer dependency (this repo has none, deliberately - see
 * CLAUDE.md), just `google-chrome-stable` (already required for
 * test_ui_smoke.php's own best-effort headless checks) plus PHP's raw
 * stream sockets. Exists because test_ui_smoke.php's own
 * `headless_dump_dom()` is a ONE-SHOT `chrome --dump-dom` - it can render
 * and dump a page, but can't click a button or type into a field. This
 * gives test_session_replay_browser.php exactly enough to do that:
 * navigate, evaluate JS (used for both reading rendered state and driving
 * clicks/typing via direct DOM calls rather than synthesized input events -
 * see cdp_click()/cdp_navigate()'s own doc comments for why that's enough
 * here), nothing else. No Target-domain multiplexing, no event
 * subscriptions - every page opened gets its own single WebSocket
 * connection (via Chrome's HTTP `/json/new` endpoint, which hands back
 * that one page's own `webSocketDebuggerUrl` directly), and "did the page
 * finish loading" is answered by polling `document.readyState` rather than
 * listening for `Page.loadEventFired` - both deliberately the simplest
 * thing that works for one page, one caller, fully serial.
 *
 * Same "best-effort, skip gracefully if unavailable" convention as
 * test_ui_smoke.php's find_headless_browser()/headless_dump_dom(): every
 * function here that can fail (chrome missing, the WS handshake failing,
 * a call timing out) echoes a SKIP line and returns null rather than
 * throwing - a host with no browser installed, or a chrome version with
 * different endpoint behavior, must never fail the suite outright.
 *
 * One exception to "no event subscriptions" above: cdp_open_page() enables
 * the Runtime domain and cdp_call()'s own read loop opportunistically
 * stashes any Runtime.exceptionThrown/Runtime.consoleAPICalled event frame
 * it happens to read while waiting for a DIFFERENT command's response
 * (rather than discarding it, which is what happened before) - see
 * cdp_drain_console_errors()'s own doc comment for why this matters: a JS
 * exception thrown inside a real DOM event listener (e.g. one triggered by
 * cdp_click()'s .click()) never propagates back through that call's own
 * return value, so without this, a handler silently throwing and doing
 * nothing looks IDENTICAL to it running successfully - exactly how the
 * multi-question "Send answers" regression (see
 * CONTRIBUTING.md) went unnoticed by this suite despite already clicking
 * that exact button.
 */

/**
 * The two CDP event methods cdp_call() stashes onto $page['console_events']
 * instead of discarding - see cdp_drain_console_errors().
 */
const CDP_CONSOLE_EVENT_METHODS = ['Runtime.exceptionThrown', 'Runtime.consoleAPICalled'];

/**
 * Once cdp_ws_read_frame() has read a frame's 2-byte header, Chrome has
 * already committed to sending the rest of that exact frame - on this
 * same-machine loopback connection that's for all practical purposes
 * instant, so this is a "something is genuinely wrong" ceiling, not a
 * budget meant to be brushed up against. Deliberately NOT tied to a
 * cdp_call() caller's own $timeout - see cdp_ws_read_frame()'s doc
 * comment for why abandoning a frame mid-read (rather than only ever
 * between frames) would desync the connection instead of just failing
 * this one read.
 */
const CDP_FRAME_CONTINUATION_TIMEOUT = 5;

function cdp_find_chrome(): ?string
{
    foreach (['/usr/bin/google-chrome-stable', '/usr/bin/google-chrome', '/usr/bin/chromium', '/usr/bin/chromium-browser'] as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }

    return null;
}

/**
 * @return array{process:resource, port:int, user_data_dir:string}|null
 */
function cdp_launch(string $chromeBin): ?array
{
    // --remote-debugging-port=0 (OS-assigned) + a per-process-unique
    // --user-data-dir means nothing here is a fixed/reused resource path -
    // unlike tests/lib/socket_harness.php's fixed socket, there's no
    // stale-listener case to guard against on launch.
    $userDataDir = sys_get_temp_dir() . '/sessioneer-test-cdp-profile-' . getmypid();
    @mkdir($userDataDir, 0700, true);

    // SESSIONEER_TEST_HEADED=1 (see tests/run.sh's --headed flag) drops
    // --headless=new entirely, so a real visible window opens on
    // whatever display this process inherits (DISPLAY/WAYLAND_DISPLAY,
    // same as any other GUI app run from this shell) - for watching the
    // replay happen live, still fully automated (nothing here waits on
    // real human input, unlike an actually-interactive devtools session).
    $headed = getenv('SESSIONEER_TEST_HEADED') === '1';

    $cmd = [
        $chromeBin,
        ...($headed ? ['--window-size=1280,900'] : ['--headless=new', '--disable-gpu']),
        '--no-sandbox',
        // --disable-crash-reporter: chrome's crashpad handler deliberately
        // detaches from the main chrome process (so it survives to report
        // a crash even as chrome itself dies), which means
        // proc_terminate() on the main process alone leaves it running,
        // orphaned, forever. Same accumulating-orphan shape as the
        // tmux-socket-harness incident this project's CLAUDE.md already
        // documents; this flag stops it from being spawned at all rather
        // than trying to hunt it down after the fact.
        '--disable-crash-reporter',
        '--remote-debugging-port=0', '--user-data-dir=' . $userDataDir, 'about:blank',
    ];
    // stdout/stderr go to a real FILE, not pipes - chrome is
    // chatty on stderr (its own internal logging), and NOTHING in this
    // file ever reads a piped stdout/stderr once launch succeeds. Once
    // that pipe's OS buffer (64KB) fills, chrome's own write() calls
    // block waiting for a reader that will never come - reproduced
    // exactly at the point in a run where chrome had emitted enough
    // stderr output to fill it (consistently around the same step of a
    // long scenario, not close to session start): CDP commands stopped
    // getting responses with no crash, no JS exception, no error -
    // cdp_call() just timed out every time from then on, every
    // downstream check silently failing against a technically-still-
    // alive but unresponsive browser. A file descriptor can never fill
    // the way a pipe can, so there's nothing to block on - unlike
    // /dev/null (used here previously), a real file also means a caller
    // that sees CDP go unresponsive can inspect chrome's own stderr
    // afterward instead of guessing (see test_session_replay_browser.php's
    // finally block).
    $stderrLog = $userDataDir . '/chrome-stderr.log';
    $fileSinks = [1 => ['file', '/dev/null', 'w'], 2 => ['file', $stderrLog, 'w']];
    $process = proc_open($cmd, [0 => ['pipe', 'r']] + $fileSinks, $pipes);

    if (!is_resource($process)) {
        echo "  SKIP: chrome found at {$chromeBin} but failed to launch\n";
        cdp_rmdir_recursive($userDataDir);
        return null;
    }

    fclose($pipes[0]);

    $portFile = $userDataDir . '/DevToolsActivePort';
    $deadline = microtime(true) + 5.0;

    while (!file_exists($portFile)) {
        if (microtime(true) > $deadline) {
            echo "  SKIP: chrome never wrote {$portFile} (remote-debugging-port) within 5s\n";
            proc_terminate($process);
            proc_close($process);
            cdp_rmdir_recursive($userDataDir);
            return null;
        }
        usleep(20000);
    }

    $lines = file($portFile, FILE_IGNORE_NEW_LINES);
    $port = (int)($lines[0] ?? 0);

    if ($port <= 0) {
        echo "  SKIP: DevToolsActivePort file did not contain a usable port\n";
        proc_terminate($process);
        proc_close($process);
        cdp_rmdir_recursive($userDataDir);
        return null;
    }

    return ['process' => $process, 'port' => $port, 'user_data_dir' => $userDataDir, 'stderr_log' => $stderrLog];
}

function cdp_shutdown(array $browser): void
{
    proc_terminate($browser['process']);
    proc_close($browser['process']);
    cdp_rmdir_recursive($browser['user_data_dir']);
}

function cdp_rmdir_recursive(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }

    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = "{$dir}/{$entry}";
        is_dir($path) ? cdp_rmdir_recursive($path) : @unlink($path);
    }

    @rmdir($dir);
}

/**
 * @return array{status:int, body:string}
 */
function cdp_http(string $method, string $url): array
{
    $process = proc_open(['curl', '-s', '-o', '-', '-w', '\n%{http_code}', '-X', $method, $url], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (!is_resource($process)) {
        return ['status' => 0, 'body' => ''];
    }

    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    $lastNewline = strrpos($out, "\n");

    if ($lastNewline === false) {
        return ['status' => 0, 'body' => $out];
    }

    return ['status' => (int)substr($out, $lastNewline + 1), 'body' => substr($out, 0, $lastNewline)];
}

/**
 * Opens a fresh tab via Chrome's HTTP `/json/new` endpoint (a PUT, not GET -
 * newer Chrome rejects GET here, a CSRF hardening change verified
 * against Chrome 151) and connects directly to that tab's OWN
 * `webSocketDebuggerUrl`, no Target.createTarget/attachToTarget dance
 * needed - every CDP command sent on this connection already targets this
 * one page.
 *
 * @return array{sock:resource, next_id:int, console_events:array}|null
 */
function cdp_open_page(array $browser, string $url = 'about:blank'): ?array
{
    $created = cdp_http('PUT', "http://127.0.0.1:{$browser['port']}/json/new?" . rawurlencode($url));
    $target = json_decode($created['body'], true);
    $wsUrl = is_array($target) ? ($target['webSocketDebuggerUrl'] ?? null) : null;

    if (!is_string($wsUrl)) {
        echo "  SKIP: chrome /json/new did not return a webSocketDebuggerUrl (got: {$created['body']})\n";
        return null;
    }

    $parts = parse_url($wsUrl);
    $sock = @stream_socket_client("tcp://{$parts['host']}:{$parts['port']}", $errno, $errstr, 5);

    if ($sock === false) {
        echo "  SKIP: could not connect to chrome's devtools websocket ({$errstr})\n";
        return null;
    }

    $key = base64_encode(random_bytes(16));
    $handshakeRequest = "GET {$parts['path']} HTTP/1.1\r\n"
        . "Host: {$parts['host']}:{$parts['port']}\r\n"
        . "Upgrade: websocket\r\nConnection: Upgrade\r\n"
        . "Sec-WebSocket-Key: {$key}\r\nSec-WebSocket-Version: 13\r\n\r\n";
    fwrite($sock, $handshakeRequest);

    stream_set_timeout($sock, 5);
    $response = '';

    while (!str_contains($response, "\r\n\r\n")) {
        $byte = fread($sock, 1);

        if ($byte === false || $byte === '') {
            echo "  SKIP: chrome's websocket handshake response never completed\n";
            fclose($sock);
            return null;
        }

        $response .= $byte;
    }

    if (!str_contains($response, '101')) {
        echo "  SKIP: chrome's websocket handshake did not return 101 (got: " . trim($response) . ")\n";
        fclose($sock);
        return null;
    }

    $page = ['sock' => $sock, 'next_id' => 1, 'console_events' => []];

    // Runtime.enable is required before Chrome sends EITHER
    // exceptionThrown or consoleAPICalled events over this connection at
    // all - silently, no error if skipped. Every page this client opens
    // gets it unconditionally rather than making it opt-in per caller, so
    // cdp_drain_console_errors() always has something to drain.
    cdp_call($page, 'Runtime.enable');

    return $page;
}

function cdp_close_page(array $page): void
{
    fclose($page['sock']);
}

/**
 * RFC 6455 client->server text frame - client frames MUST be masked.
 */
function cdp_ws_send(mixed $sock, string $payload): void
{
    $len = strlen($payload);
    $mask = random_bytes(4);
    $frame = chr(0x81); // FIN + opcode 0x1 (text)

    if ($len <= 125) {
        $frame .= chr($len | 0x80);
    } elseif ($len <= 0xFFFF) {
        $frame .= chr(126 | 0x80) . pack('n', $len);
    } else {
        $frame .= chr(127 | 0x80) . pack('J', $len);
    }

    $frame .= $mask;

    for ($i = 0; $i < $len; $i++) {
        $frame .= $payload[$i] ^ $mask[$i % 4];
    }

    // @-suppressed: the other end (e.g. a human closing the browser
    // window mid-run in --headed mode) going away is an expected,
    // handled condition here - cdp_call()'s own read loop already treats
    // a subsequent failed/empty read as "no response", so this would
    // otherwise just spam a "Broken pipe" PHP Notice for every single
    // call made after the connection dies, as on the
    // control panel's dead-window abort path.
    @fwrite($sock, $frame);
}

/**
 * Reads exactly one raw WebSocket frame: header, extended length if any,
 * and its full payload. Frame-type-agnostic on purpose - see
 * cdp_ws_recv()'s own doc comment for why a frame's payload must always
 * be fully consumed here regardless of its opcode, never skipped.
 *
 * $deadline (an absolute microtime(true), not a duration) is only ever
 * applied BEFORE this frame's own 2-byte header is read - i.e. only
 * between frames, never in the middle of one already in flight. Found
 * live investigating a CI-only (never reproduced as reliably locally)
 * ~30-45s stall exactly at one specific scenario step:
 * stream_set_timeout() sets an IDLE timeout, reset by every byte that
 * arrives, not a total-call-time budget. A steady trickle of real frames
 * (any opcode - here specifically a burst of Runtime.consoleAPICalled
 * events, none of them errors, so cdp_drain_console_errors() never
 * flagged it) never idles long enough to trip that timeout, so
 * cdp_ws_recv()'s own while(true) loop (below) could keep consuming whole
 * NEW frames well past its caller's intended deadline - the per-command
 * $timeout in cdp_call() was therefore advisory at best whenever the
 * OTHER end was actually chatty, not silent. Re-arming the timeout to the
 * remaining budget right before each new header read fixes that.
 *
 * Deliberately NOT re-checked against $deadline once the header is in
 * hand (extended-length bytes, payload chunks): those remaining bytes are
 * already declared as belonging to a frame this socket is mid-read on,
 * and Chrome sends a frame's bytes together as one write on this same
 * loopback connection, so waiting on them uses a short fixed
 * CDP_FRAME_CONTINUATION_TIMEOUT instead - abandoning them on the outer
 * deadline instead would leave those declared-but-unread bytes sitting in
 * the socket, and the NEXT read would misparse them as a fresh frame
 * header, permanently desyncing every frame boundary from then on -
 * exactly the same failure mode this file's own PING-frame handling
 * above already exists to avoid.
 *
 * @return array{opcode:int, payload:string}|null null on a read failure OR the deadline passing (only possible before the header)
 */
function cdp_ws_read_frame(mixed $sock, float $deadline): ?array
{
    if (!cdp_ws_arm_timeout($sock, $deadline)) {
        return null;
    }

    $header = fread($sock, 2);

    if ($header === false || strlen($header) < 2) {
        return null;
    }

    stream_set_timeout($sock, CDP_FRAME_CONTINUATION_TIMEOUT);

    $b0 = ord($header[0]);
    $b1 = ord($header[1]);
    $len = $b1 & 0x7F;

    if ($len === 126) {
        $ext = fread($sock, 2);
        $len = $ext === false || strlen($ext) < 2 ? 0 : unpack('n', $ext)[1];
    } elseif ($len === 127) {
        $ext = fread($sock, 8);
        $len = $ext === false || strlen($ext) < 8 ? 0 : unpack('J', $ext)[1];
    }

    $payload = '';

    while (strlen($payload) < $len) {
        $chunk = fread($sock, $len - strlen($payload));

        if ($chunk === false || $chunk === '') {
            break;
        }

        $payload .= $chunk;
    }

    return ['opcode' => $b0 & 0x0F, 'payload' => $payload];
}

/**
 * Re-arms $sock's read timeout (see cdp_ws_read_frame()'s doc comment) to
 * whatever real time remains until $deadline, an absolute microtime(true).
 * Returns false without touching the socket if the deadline has already
 * passed, so callers can bail out immediately instead of issuing a
 * pointless zero-duration read.
 */
function cdp_ws_arm_timeout(mixed $sock, float $deadline): bool
{
    $remaining = $deadline - microtime(true);

    if ($remaining <= 0) {
        return false;
    }

    $seconds = (int)$remaining;
    $microseconds = (int)(($remaining - $seconds) * 1_000_000);
    stream_set_timeout($sock, $seconds, $microseconds);

    return true;
}

/**
 * Reads WebSocket frames until a text-message one (opcode 0x1) arrives,
 * returning its payload - every CDP command/event response fits in one
 * frame in practice for what this client sends/receives, so this only
 * ever returns one frame's worth of payload, never reassembles
 * continuation frames.
 *
 * Every OTHER frame type read along the way is still fully consumed
 * (via cdp_ws_read_frame(), which always reads a frame's complete
 * declared-length payload regardless of opcode) rather than skipped -
 * An earlier version bailed out immediately on a non-text
 * frame without reading its payload bytes at all. Chrome periodically
 * sends WebSocket PING frames (opcode 0x9) on an otherwise-idle
 * connection; leaving one unread desynchronizes every future frame read
 * forever after (the "header" of the NEXT read call ends up landing
 * partway into the previous frame's still-unread payload bytes) - this
 * reproduced live as every cdp_call() silently timing out for the rest
 * of a run, from whatever moment a PING happened to arrive onward, with
 * no error of any kind on either side to point at why. A PING frame
 * specifically gets a real PONG (opcode 0xA) reply, per RFC 6455 - not
 * required for chrome to keep tolerating this client, but correct.
 *
 * $deadline (absolute microtime(true)) bounds the TOTAL time this call may
 * spend looping over non-text frames - see cdp_ws_read_frame()'s doc
 * comment for why a per-read idle timeout alone isn't enough once the
 * other end is sending a steady stream of real frames.
 */
function cdp_ws_recv(mixed $sock, float $deadline): ?string
{
    while (true) {
        $frame = cdp_ws_read_frame($sock, $deadline);

        if ($frame === null) {
            return null;
        }

        if ($frame['opcode'] === 0x1) {
            return $frame['payload'];
        }

        if ($frame['opcode'] === 0x8) {
            return null; // a real close frame - nothing more will ever arrive
        }

        if ($frame['opcode'] === 0x9) {
            cdp_ws_send_pong($sock, $frame['payload']);
        }

        // Any other frame (pong, continuation, binary) - already fully
        // consumed above by cdp_ws_read_frame(); loop and keep waiting
        // for the text frame this call actually wants.
    }
}

/**
 * A pong frame is a masked client->server frame just like any other
 * client-sent frame (cdp_ws_send() is text-opcode-only, hence this
 * separate minimal sender) - echoes the ping's own payload back
 * unchanged, per RFC 6455.
 */
function cdp_ws_send_pong(mixed $sock, string $payload): void
{
    $len = strlen($payload);
    $mask = random_bytes(4);
    $frame = chr(0x8A); // FIN + opcode 0xA (pong)
    $frame .= chr($len | 0x80) . $mask; // pong payloads are always tiny (<=125 bytes) in practice

    for ($i = 0; $i < $len; $i++) {
        $frame .= $payload[$i] ^ $mask[$i % 4];
    }

    @fwrite($sock, $frame);
}

/**
 * Sends one CDP command and blocks (up to $timeout) for ITS OWN response,
 * transparently skipping over any unrelated event frames (e.g.
 * Page.frameStartedLoading) that arrive first - id-matching is what tells
 * a command's response apart from an event notification (events carry no
 * "id" field at all).
 *
 * $page MUST reach here by reference at every call site, including
 * through a closure (`use (&$page)`, not `use ($page)`) - a
 * closure passed to browser_wait_until() that captured $page BY VALUE got
 * its own independent copy of next_id, incrementing separately from the
 * outer scope's calls on the same underlying socket. Both copies
 * eventually sent the same numeric id to chrome, which responded with a
 * real CDP-level "Duplicate `id` in protocol request" error - and once
 * that happens, id-matching for legitimate subsequent calls on either
 * copy silently stops working (every later cdp_call() times out with no
 * further errors), which is what actually surfaced this: a test scenario
 * that ran long enough to create enough retrying closures for a
 * collision to become likely.
 *
 * Every frame read here that ISN'T this call's own response is now also
 * checked for being a Runtime.exceptionThrown/Runtime.consoleAPICalled
 * event (CDP_CONSOLE_EVENT_METHODS below) and stashed onto
 * $page['console_events'] rather than just discarded, so a JS error that
 * happens to surface while some OTHER command is waiting on its own
 * response still gets seen - see cdp_drain_console_errors()'s own doc
 * comment for why this is the only way this client can catch that class of
 * bug at all.
 *
 * @param array{sock:resource, next_id:int, console_events:array} $page
 * @return array<string, mixed>|null the command's "result" object, or null on timeout/error
 */
function cdp_call(array &$page, string $method, array $params = [], float $timeout = 5.0): ?array
{
    $id = $page['next_id']++;
    cdp_ws_send($page['sock'], json_encode(['id' => $id, 'method' => $method, 'params' => $params]));

    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $raw = cdp_ws_recv($page['sock'], $deadline);

        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (($decoded['id'] ?? null) === $id) {
            return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
        }

        if (in_array($decoded['method'] ?? null, CDP_CONSOLE_EVENT_METHODS, true)) {
            $page['console_events'][] = $decoded;
        }
    }

    return null;
}

/**
 * Navigates, then polls document.readyState instead of subscribing to
 * Page.loadEventFired - simpler for a client with no event-buffering
 * layer, and sufficient here since every page this drives is a small,
 * already-fast local `php -S` response, not a heavy real-world page.
 *
 * Default bumped 5.0 -> 10.0 -> 20.0 (a slower, shared
 * runner than this suite was originally tuned against, confirmed by two
 * genuinely separate navigate() calls in the SAME run each occasionally
 * needing more than 10s): occasionally still mid-load a hair past the
 * prior default even though the page was never actually stuck (every
 * assertion against it right afterward still passed) - a plain "not
 * enough margin on a loaded box" gap, not the frame-desync class of bug
 * cdp_ws_read_frame()'s own doc comment covers, and not the real
 * multi-question race test_session_replay_browser.php's own doc comment
 * covers either (that one's fixed at the source now, in the test itself,
 * not by widening a timeout).
 */
function cdp_navigate(array &$page, string $url, float $timeout = 20.0): bool
{
    // A CI-only navigate() failure that
    // consistently gave up after exactly 5.0s no matter how high $timeout
    // was raised: this initial Page.navigate dispatch used to call
    // cdp_call() with NO timeout argument at all, silently falling back to
    // cdp_call()'s OWN unrelated 5.0 default - $timeout only ever reached
    // the readyState poll below, never this line, so bumping it repeatedly
    // did nothing whenever the SLOW part was Chrome acknowledging the
    // navigate command itself (e.g. still tearing down the previous page)
    // rather than the new page finishing its load.
    if (cdp_call($page, 'Page.navigate', ['url' => $url], $timeout) === null) {
        return false;
    }

    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $state = cdp_evaluate($page, 'document.readyState');

        if ($state === 'complete') {
            return true;
        }

        usleep(50000);
    }

    return false;
}

/**
 * Runtime.evaluate wrapper returning the JS value directly (decoded from
 * JSON via returnByValue) instead of CDP's {type, value, description}
 * wrapper - null on any failure (including a thrown JS exception, since no
 * caller here needs to distinguish "evaluated to null" from "failed").
 */
function cdp_evaluate(array &$page, string $expression): mixed
{
    $result = cdp_call($page, 'Runtime.evaluate', ['expression' => $expression, 'returnByValue' => true]);

    if ($result === null || isset($result['exceptionDetails'])) {
        return null;
    }

    return $result['result']['value'] ?? null;
}

/**
 * Returns one human-readable message per uncaught JS exception
 * (Runtime.exceptionThrown - includes one thrown inside a real DOM event
 * listener, e.g. from cdp_click(), which is otherwise invisible to this
 * client: exceptions inside event listeners never propagate back through
 * the dispatching .click() call's own return value, per the DOM spec, so
 * cdp_evaluate()'s exceptionDetails check never sees them either) and every
 * console.error() call (Runtime.consoleAPICalled with type "error" -
 * console.warn()/.log()/etc are deliberately not included, since e.g. the
 * Tailwind CDN script itself logs a benign "should not be used in
 * production" console.warn on every page load) recorded since the last
 * drain - "drain", not "peek": $page['console_events'] is cleared before
 * returning, so calling this once per replay step (as
 * test_session_replay_browser.php does) attributes each error to the one
 * step that actually caused it, never double-reports it on the next call.
 *
 * A trivial cdp_evaluate() first forces one more read on the underlying
 * socket - cdp_call() only ever stashes an event frame while it happens to
 * be reading frames looking for SOME command's response, so an event that
 * arrived while nothing else was mid-call (e.g. behind a plain usleep())
 * would otherwise sit unread until some later, unrelated command's own
 * cdp_call() happened to flush it - which could attribute a real error to
 * the wrong step, or leave it stranded past the last check of a run
 * entirely.
 *
 * Requires cdp_open_page() to have already sent Runtime.enable (it always
 * does) - without that, Chrome never emits either event over this
 * connection at all, and this would silently always return [].
 *
 * @return string[]
 */
function cdp_drain_console_errors(array &$page): array
{
    cdp_evaluate($page, '1');

    $messages = [];

    foreach ($page['console_events'] as $event) {
        $method = $event['method'] ?? null;
        $params = is_array($event['params'] ?? null) ? $event['params'] : [];

        if ($method === 'Runtime.exceptionThrown') {
            $exceptionDetails = is_array($params['exceptionDetails'] ?? null) ? $params['exceptionDetails'] : [];
            $exception = is_array($exceptionDetails['exception'] ?? null) ? $exceptionDetails['exception'] : [];
            $text = $exception['description'] ?? ($exceptionDetails['text'] ?? 'unknown exception');
            $messages[] = 'uncaught exception: ' . $text;
        } elseif ($method === 'Runtime.consoleAPICalled' && ($params['type'] ?? null) === 'error') {
            $args = is_array($params['args'] ?? null) ? $params['args'] : [];
            $parts = array_map(
                static fn(array $arg) => (string)($arg['description'] ?? $arg['value'] ?? $arg['unserializableValue'] ?? '?'),
                $args
            );
            $messages[] = 'console.error: ' . implode(' ', $parts);
        }
    }

    $page['console_events'] = [];

    return $messages;
}

/**
 * Clicks the first element matching $selector via the DOM's own .click()
 * rather than synthesizing real mouse events (CDP's Input.dispatchMouseEvent) -
 * simpler, and sufficient here: session.js's answer-prompt handler is a
 * real `submit` event listener on the form (see blocked-prompt/options.php),
 * and a real <button type="submit">'s .click() dispatches that same event
 * exactly as a genuine click would. Returns false if no element matched.
 */
function cdp_click(array &$page, string $selector): bool
{
    $expression = 'var __el = document.querySelector(' . json_encode($selector) . ');'
        . 'if (__el) { __el.click(); true; } else { false; }';

    return cdp_evaluate($page, $expression) === true;
}

/**
 * Raw PNG bytes of the current page, or null on failure - used only for
 * failure diagnostics (see test_session_replay_browser.php's
 * browser_assert()), never asserted on directly, so no pixel comparison
 * lives here.
 */
function cdp_screenshot(array &$page): ?string
{
    $result = cdp_call($page, 'Page.captureScreenshot', ['format' => 'png']);
    $base64 = is_string($result['data'] ?? null) ? $result['data'] : null;

    if ($base64 === null) {
        return null;
    }

    $decoded = base64_decode($base64, true);

    return $decoded === false ? null : $decoded;
}

/**
 * Emulation.setDeviceMetricsOverride - lets a caller check the SAME
 * already-rendered page at a different viewport size (e.g. a phone-sized
 * one) without a real device, by re-navigating after this call.
 * $deviceScaleFactor:0 leaves it at the device default; $mobile also
 * flips the CSS "mobile" media hint chrome reports internally, matching
 * how a real phone browser would present itself.
 */
function cdp_set_viewport(array &$page, int $width, int $height, float $deviceScaleFactor, bool $mobile): bool
{
    return cdp_call($page, 'Emulation.setDeviceMetricsOverride', [
        'width' => $width,
        'height' => $height,
        'deviceScaleFactor' => $deviceScaleFactor,
        'mobile' => $mobile,
    ]) !== null;
}
