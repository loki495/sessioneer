<?php

declare(strict_types=1);

// Runs the REAL host-agent/codex_bridge.php against a fake Codex app-server
// daemon (this process: a unix socket speaking server-side WebSocket) with
// temp sockets and a temp SQLite file. No real Codex, no paid calls. Covers:
// loaded-thread resume, a refused resume retried, a prompt held and answered
// with the request's own id, a prompt surviving an unrelated status event,
// serverRequest/resolved clearing it, a waiting flag without a request showing
// the observe-only notice until the reviewer finishes, idle dropping a prompt,
// and the private app-server fallback when the daemon socket is absent.

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use HostAgent\Services\CodexWebSocketFrames;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SqliteDb;

$dir = sys_get_temp_dir() . '/sessioneer-test-codex-bridge-' . bin2hex(random_bytes(4));
mkdir($dir, 0700, true);
$statusDb = $dir . '/status.sqlite';
putenv("SESSIONS_SQLITE_FILE={$statusDb}");
SqliteDb::reset_connections_for_tests();

/** @var resource[] $bridges */
$bridges = [];

/** @param array<string,string> $extraEnv @return array{0:resource,1:string} process and its bridge socket path */
function start_bridge(string $dir, string $name, array $extraEnv): array
{
    global $bridges;
    $socket = "{$dir}/{$name}.sock";
    $env = array_merge(getenv(), [
        'CODEX_BRIDGE_SOCKET' => $socket,
        'SESSIONS_SQLITE_FILE' => $dir . '/status.sqlite',
    ], $extraEnv);
    $process = proc_open(['php', dirname(__DIR__) . '/host-agent/codex_bridge.php'], [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', "{$dir}/{$name}.out", 'w'],
        2 => ['file', "{$dir}/{$name}.err", 'w'],
    ], $pipes, null, $env);
    $bridges[] = $process;
    return [$process, $socket];
}

function wait_until(callable $check, float $seconds = 6.0): bool
{
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        if ($check()) return true;
        usleep(50000);
    }
    return $check();
}

/** One request/response over the bridge's own socket, as the host agent does. */
function ask_bridge(string $socketPath, string $method, array $params): ?array
{
    $client = @stream_socket_client('unix://' . $socketPath, $errno, $error, 2);
    if ($client === false) return null;
    fwrite($client, json_encode(['method' => $method, 'params' => $params]) . "\n");
    stream_set_timeout($client, 3);
    $line = fgets($client);
    fclose($client);
    $decoded = $line === false ? null : json_decode($line, true);
    return is_array($decoded) ? $decoded : null;
}

/** The fake daemon side of one accepted connection. */
final class FakeDaemonConnection
{
    private CodexWebSocketFrames $frames;
    /** @var array<int,array<string,mixed>> */
    private array $queue = [];

    /** @param resource $stream */
    public function __construct(private $stream)
    {
        $this->frames = new CodexWebSocketFrames();
    }

    /** @param array<string,mixed> $message */
    public function send(array $message): void
    {
        $payload = (string)json_encode($message);
        $length = strlen($payload);
        $header = chr(0x81) . ($length < 126 ? chr($length) : chr(126) . pack('n', $length));
        fwrite($this->stream, $header . $payload);
    }

    /** Next message matching $match from the bridge, or null on timeout. @return array<string,mixed>|null */
    public function expect(callable $match, float $seconds = 6.0): ?array
    {
        $end = microtime(true) + $seconds;
        while (microtime(true) < $end) {
            foreach ($this->queue as $index => $message) {
                if ($match($message)) {
                    array_splice($this->queue, $index, 1);
                    return $message;
                }
            }
            $read = [$this->stream];
            $write = $except = [];
            if (stream_select($read, $write, $except, 0, 100000) > 0) {
                $chunk = fread($this->stream, 65536);
                if ($chunk === '' || $chunk === false) return null;
                foreach ($this->frames->feed($chunk)['messages'] as $text) {
                    $decoded = json_decode($text, true);
                    if (is_array($decoded)) $this->queue[] = $decoded;
                }
            }
        }
        return null;
    }
}

/** The bridge's pending prompt for a thread, or null when it holds none. @return array<string,mixed>|null */
function pending_prompt(string $bridgeSocket, string $threadId): ?array
{
    $reply = ask_bridge($bridgeSocket, 'sessioneer/pendingPrompt', ['threadId' => $threadId]);
    return is_array($reply['prompt'] ?? null) ? $reply['prompt'] : null;
}

/** @return array<string,mixed>|null the blocked state in the status store */
function blocked_of(string $threadId): ?array
{
    $status = SessionStatusStore::read_status($threadId);
    return is_array($status['blocked'] ?? null) ? $status['blocked'] : null;
}

function method_is(string $method): callable
{
    return static fn(array $m): bool => ($m['method'] ?? null) === $method;
}

function resume_of(string $threadId): callable
{
    return static fn(array $m): bool => ($m['method'] ?? null) === 'thread/resume' && ($m['params']['threadId'] ?? null) === $threadId;
}

try {
    // --- Daemon mode ---
    $daemonPath = "{$dir}/daemon.sock";
    $server = stream_socket_server('unix://' . $daemonPath, $errno, $error);
    assert_true($server !== false, 'fake daemon socket binds');
    [$bridge, $bridgeSocket] = start_bridge($dir, 'daemon', ['CODEX_DAEMON_SOCKET' => $daemonPath, 'CODEX_BIN' => '/bin/false']);

    $read = [$server];
    $write = $except = [];
    $accepted = stream_select($read, $write, $except, 8) > 0 ? stream_socket_accept($server, 1) : false;
    assert_true($accepted !== false, 'the bridge connects to the daemon socket');
    $request = '';
    while (!str_contains($request, "\r\n\r\n")) {
        $chunk = fread($accepted, 1);
        if ($chunk === false || $chunk === '') break;
        $request .= $chunk;
    }
    assert_contains('Upgrade: websocket', $request, 'the bridge opens with a WebSocket upgrade');
    fwrite($accepted, "HTTP/1.1 101 Switching Protocols\r\nconnection: Upgrade\r\nupgrade: websocket\r\n\r\n");
    $daemon = new FakeDaemonConnection($accepted);

    $init = $daemon->expect(method_is('initialize'));
    assert_equal(1, $init['id'] ?? null, 'the bridge initializes the connection');
    assert_equal(true, $init['params']['capabilities']['experimentalApi'] ?? null, 'it opts into the experimental API, which thread/settings/update needs');
    $daemon->send(['id' => 1, 'result' => ['userAgent' => 'fake']]);
    assert_true($daemon->expect(method_is('initialized')) !== null, 'it then sends initialized');

    // Threads already loaded in the daemon are resumed.
    $list = $daemon->expect(method_is('thread/loaded/list'));
    assert_true($list !== null, 'it asks which threads are loaded');
    $daemon->send(['id' => $list['id'] ?? 0, 'result' => ['data' => ['thread-loaded']]]);
    $resume = $daemon->expect(resume_of('thread-loaded'));
    assert_true($resume !== null, 'a thread the daemon already has loaded is resumed');
    assert_equal(true, $resume['params']['excludeTurns'] ?? null, 'resume skips the full turn history');
    $daemon->send(['id' => $resume['id'], 'result' => ['thread' => ['id' => 'thread-loaded', 'status' => ['type' => 'idle']]]]);

    assert_true(wait_until(static fn(): bool => file_exists($bridgeSocket)), 'the bridge serves its own socket');

    // A thread another client starts: the first resume is refused (no rollout yet), then retried.
    $T = 'thread-phone';
    $daemon->send(['method' => 'thread/started', 'params' => ['thread' => ['id' => $T]]]);
    $first = $daemon->expect(resume_of($T));
    assert_true($first !== null, 'thread/started (nested thread id) triggers a resume');
    $daemon->send(['id' => $first['id'], 'error' => ['code' => -32600, 'message' => "no rollout found for thread id {$T}"]]);
    $second = $daemon->expect(resume_of($T), 5.0);
    assert_true($second !== null, 'a refused resume is retried');
    $daemon->send(['id' => $second['id'], 'result' => ['thread' => ['id' => $T, 'status' => ['type' => 'active']]]]);
    // Attached now: more status events must not trigger another resume.
    $daemon->send(['method' => 'thread/status/changed', 'params' => ['threadId' => $T, 'status' => ['type' => 'active', 'activeFlags' => []]]]);
    assert_equal(null, $daemon->expect(resume_of($T), 1.5), 'an attached thread is not resumed again');

    // A real approval request becomes an answerable prompt, keyed by the request's own id.
    $daemon->send(['id' => 77, 'method' => 'item/commandExecution/requestApproval', 'params' => ['threadId' => $T, 'itemId' => 'item-1', 'command' => 'touch x']]);
    $held = wait_until(static fn(): bool => (pending_prompt($bridgeSocket, $T)['tool_name'] ?? null) === 'permission');
    assert_true($held, 'the approval is held as a permission prompt');
    assert_equal('blocked', SessionStatusStore::read_status($T)['status'] ?? null, 'the session is shown blocked');
    assert_equal('Allow Codex to run this command?', SessionStatusStore::read_status($T)['blocked']['question'] ?? null, 'with the real, answerable prompt');

    // An unrelated status event must not erase it.
    $daemon->send(['method' => 'thread/status/changed', 'params' => ['threadId' => $T, 'status' => ['type' => 'active', 'activeFlags' => []]]]);
    usleep(400000);
    assert_equal('permission', pending_prompt($bridgeSocket, $T)['tool_name'] ?? null, 'an unrelated active status event keeps the prompt');
    assert_equal('blocked', SessionStatusStore::read_status($T)['status'] ?? null, 'and the session stays blocked');

    // The phone (or anything else) answered: only that request goes away.
    $daemon->send(['method' => 'serverRequest/resolved', 'params' => ['threadId' => $T, 'requestId' => 76]]);
    usleep(400000);
    assert_equal('permission', pending_prompt($bridgeSocket, $T)['tool_name'] ?? null, 'a resolved notice for another request leaves the prompt');
    $daemon->send(['method' => 'serverRequest/resolved', 'params' => ['threadId' => $T, 'requestId' => 77]]);
    assert_true(wait_until(static fn(): bool => pending_prompt($bridgeSocket, $T) === null), 'its own resolved notice clears the prompt');
    assert_equal('working', SessionStatusStore::read_status($T)['status'] ?? null, 'and the session returns to working');
    assert_equal(null, blocked_of($T), 'with no blocked state');

    // Answering from Sessioneer replies to the daemon with the request's own id.
    $daemon->send(['id' => 78, 'method' => 'item/commandExecution/requestApproval', 'params' => ['threadId' => $T, 'itemId' => 'item-2', 'command' => 'rm x']]);
    assert_true(wait_until(static fn(): bool => pending_prompt($bridgeSocket, $T) !== null), 'a second request is held');
    $answer = ask_bridge($bridgeSocket, 'sessioneer/answerPrompt', ['threadId' => $T, 'answers' => ['option' => 3]]);
    assert_equal(true, $answer['ok'] ?? null, 'the answer is accepted');
    $reply = $daemon->expect(static fn(array $m): bool => ($m['id'] ?? null) === 78 && !isset($m['method']));
    assert_equal(['decision' => 'decline'], $reply['result'] ?? null, 'the daemon receives the decision under the request id');
    $rejected = ask_bridge($bridgeSocket, 'sessioneer/answerPrompt', ['threadId' => $T, 'answers' => ['option' => 3]]);
    assert_equal(false, $rejected['ok'] ?? null, 'answering again is rejected (no prompt pending)');

    // A waiting flag with no request this connection can answer: observe-only notice, until the reviewer finishes.
    $daemon->send(['method' => 'thread/status/changed', 'params' => ['threadId' => $T, 'status' => ['type' => 'active', 'activeFlags' => ['waitingOnApproval']]]]);
    assert_true(wait_until(static fn(): bool => (SessionStatusStore::read_status($T)['blocked']['external'] ?? false) === true), 'a waiting flag without a request shows the observe-only notice');
    assert_equal('blocked', SessionStatusStore::read_status($T)['status'] ?? null, 'and marks the session blocked');
    assert_equal('Approval required in Codex Remote.', SessionStatusStore::read_status($T)['blocked']['question'] ?? null, 'saying where to answer');
    $daemon->send(['method' => 'item/autoApprovalReview/completed', 'params' => ['threadId' => $T, 'reviewId' => 'r1']]);
    assert_true(wait_until(static fn(): bool => (SessionStatusStore::read_status($T)['status'] ?? null) === 'working'), 'a reviewer-settled approval clears the notice');
    assert_equal(null, blocked_of($T), 'leaving nothing blocked');

    // The reviewer completing must not clear a real prompt.
    $daemon->send(['id' => 79, 'method' => 'item/commandExecution/requestApproval', 'params' => ['threadId' => $T, 'itemId' => 'item-3', 'command' => 'ls']]);
    assert_true(wait_until(static fn(): bool => pending_prompt($bridgeSocket, $T) !== null), 'a third request is held');
    $daemon->send(['method' => 'item/autoApprovalReview/completed', 'params' => ['threadId' => $T, 'reviewId' => 'r2']]);
    usleep(400000);
    assert_equal('permission', pending_prompt($bridgeSocket, $T)['tool_name'] ?? null, 'a review completing leaves a real prompt alone');

    // The thread going idle drops whatever was pending.
    $daemon->send(['method' => 'thread/status/changed', 'params' => ['threadId' => $T, 'status' => ['type' => 'idle']]]);
    assert_true(wait_until(static fn(): bool => pending_prompt($bridgeSocket, $T) === null), 'an idle thread has no pending prompt');
    assert_equal('idle', SessionStatusStore::read_status($T)['status'] ?? null, 'and reads idle');

    proc_terminate($bridge);
    fclose($accepted);
    fclose($server);

    // --- Fallback: no daemon socket, so the bridge starts a private `app-server --stdio` ---
    $fakeCodex = "{$dir}/fake-codex";
    file_put_contents($fakeCodex, <<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents(__DIR__ . '/fallback-argv.txt', implode(' ', array_slice($argv, 1)));
while (($line = fgets(STDIN)) !== false) {
    $m = json_decode($line, true);
    if (is_array($m) && ($m['method'] ?? null) === 'initialize') {
        fwrite(STDOUT, json_encode(['id' => $m['id'], 'result' => ['userAgent' => 'fake']]) . "\n");
    }
}
PHP);
    chmod($fakeCodex, 0700);
    [$fallback, $fallbackSocket] = start_bridge($dir, 'fallback', ['CODEX_DAEMON_SOCKET' => "{$dir}/absent.sock", 'CODEX_BIN' => $fakeCodex]);
    assert_true(wait_until(static fn(): bool => file_exists($fallbackSocket), 8.0), 'without a daemon the bridge still comes up');
    assert_equal('app-server --stdio', @file_get_contents("{$dir}/fallback-argv.txt"), 'by starting a private app-server over stdio');
    proc_terminate($fallback);
} finally {
    foreach ($bridges as $process) {
        if (is_resource($process)) {
            $pid = proc_get_status($process)['pid'];
            @proc_terminate($process);
            // the fallback bridge's stdio child must not outlive the test
            @exec('pkill -P ' . (int)$pid . ' 2>/dev/null');
            @proc_close($process);
        }
    }
    foreach (glob("{$dir}/*") ?: [] as $file) @unlink($file);
    @rmdir($dir);
    SqliteDb::reset_connections_for_tests();
}

test_exit();
