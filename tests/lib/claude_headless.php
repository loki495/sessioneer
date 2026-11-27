<?php

declare(strict_types=1);

use HostAgent\Runtimes\ClaudeHeadlessManagerClient;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * Shared helpers for the tests that drive a REAL ClaudeHeadlessManager process
 * (host-agent/claude_headless_manager.php) against the scripted stand-in
 * tests/fixtures/fake_claude_stream. Requires the including test to define
 * `$managers` (the started-process registry its shutdown handler cleans up) and
 * `$madeSessions` (the sessions it wrote, which the handler deletes again).
 */

/** @param array<string, string> $env @return array{proc: resource, sock: string, log: string, fake: string} */
function start_manager(string $root, string $name, array $env = []): array
{
    global $managers;

    $sock = "{$root}/{$name}.sock";
    $log = "{$root}/{$name}.manager.log";
    $fake = "{$root}/{$name}.fake.log";
    $env = array_merge(getenv(), [
        'CLAUDE_BIN' => dirname(__DIR__) . '/fixtures/fake_claude_stream',
        'HOME_ROOT' => $root . '/home',
        'CLAUDE_HEADLESS_SOCKET' => $sock,
        'CLAUDE_HEADLESS_IDLE_SECONDS' => '0',
        'CLAUDE_HEADLESS_MAX_CHILDREN' => '6',
        'CLAUDE_HEADLESS_STOP_GRACE_SECONDS' => '3',
        'FAKE_CLAUDE_LOG' => $fake,
        // The manager writes Claude's rate-limit windows to this store; never the real host state.
        'PUSH_SQLITE_FILE' => $root . '/push.sqlite',
        // Deliberately present: the manager must strip all three from its children.
        'ANTHROPIC_API_KEY' => 'sk-leak-test',
        'ANTHROPIC_AUTH_TOKEN' => 'tok-leak-test',
        'SESSIONEER_SESSION_NAME' => 'leak',
    ], $env);

    $proc = proc_open(
        [PHP_BINARY, dirname(__DIR__, 2) . '/host-agent/claude_headless_manager.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']],
        $pipes,
        null,
        $env
    );

    if (!is_resource($proc)) {
        fwrite(STDERR, "cannot start manager {$name}\n");
        exit(1);
    }

    $m = ['proc' => $proc, 'sock' => $sock, 'log' => $log, 'fake' => $fake];
    $managers[] = $m;
    // The socket appears before the manager's startup cleanup runs; its own
    // "listening" line is written only after that.
    wait_until(
        static fn (): bool => str_contains((string)@file_get_contents($log), 'listening on') || !proc_get_status($proc)['running'],
        10.0
    );

    return $m;
}

/** SIGKILLs one process; refuses pid 0/1, where `kill -9` would hit a whole process group or init. */
function kill_hard(int $pid): void
{
    if ($pid <= 1) {
        fwrite(STDERR, "refusing to SIGKILL pid {$pid}\n");
        exit(1);
    }

    posix_kill($pid, SIGKILL);
}

function pid_alive(int $pid): bool
{
    $status = @file_get_contents("/proc/{$pid}/status");

    return $status !== false && preg_match('/^State:\s+Z/m', $status) !== 1;
}

/** @return mixed the first truthy return of $fn, or false on timeout */
function wait_until(callable $fn, float $timeout = 8.0): mixed
{
    $deadline = microtime(true) + $timeout;

    do {
        $value = $fn();

        if ($value) {
            return $value;
        }

        usleep(40000);
    } while (microtime(true) < $deadline);

    return false;
}

/** @return array<int, array<string, mixed>> */
function fake_log(array $m): array
{
    $rows = [];

    foreach (@file($m['fake'], FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);

        if (is_array($decoded)) {
            $rows[] = $decoded;
        }
    }

    return $rows;
}

function fake_results(array $m): array
{
    return array_values(array_map(static fn (array $r): string => (string)$r['result'], array_filter(fake_log($m), static fn (array $r): bool => isset($r['result']))));
}

function has_result(array $m, string $needle): bool
{
    foreach (fake_results($m) as $text) {
        if (str_contains($text, $needle)) {
            return true;
        }
    }

    return false;
}

/** Same value with every object's keys sorted, so === compares shapes without depending on key order. */
function canonical(mixed $value): mixed
{
    if (!is_array($value)) {
        return $value;
    }

    $value = array_map('canonical', $value);

    if (!array_is_list($value)) {
        ksort($value);
    }

    return $value;
}

/**
 * The user lines the fake process read from the manager that contain $token.
 *
 * @return list<array<string, mixed>>
 */
function user_lines(array $m, string $token): array
{
    $lines = [];

    foreach (fake_log($m) as $row) {
        if (isset($row['stdin']) && str_contains((string)$row['stdin'], $token)) {
            $lines[] = (array)json_decode((string)$row['stdin'], true);
        }
    }

    return $lines;
}

/**
 * Asserts the exact line written to the Claude process for the message carrying
 * $token: with `isSynthetic: true` when $synthetic, with no such key otherwise.
 */
function assert_user_line(array $m, string $token, bool $synthetic, mixed $content, string $message): void
{
    wait_until(static fn (): bool => user_lines($m, $token) !== []);
    $lines = user_lines($m, $token);

    if (count($lines) !== 1) {
        assert_true(false, "{$message} (expected one line carrying {$token} to reach the process, got " . count($lines) . ')');

        return;
    }

    $expected = ['type' => 'user', 'message' => ['role' => 'user', 'content' => $content]] + ($synthetic ? ['isSynthetic' => true] : []);
    $ok = canonical($expected) === canonical($lines[0]);

    assert_true($ok, $ok ? $message : "{$message} (expected " . ($synthetic ? 'isSynthetic: true' : 'no isSynthetic key') . ', got ' . substr((string)json_encode($lines[0]), 0, 300) . ')');
}

/** Waits until the manager has processed the fake process's BARRIER=$n / AFTER=$n marker. */
function manager_saw(array $m, int $n): bool
{
    return (bool)wait_until(static fn (): bool => abs((float)(call($m, 'sessioneer/health')['rate_limit']['windows']['five_hour']['utilization'] ?? -1) - $n / 1000) < 1e-9);
}

/** @param array<string, mixed> $over */
function make_session(string $root, string $name, array $over = []): string
{
    global $madeSessions;
    $madeSessions[] = $name;

    $workdir = $over['workdir'] ?? "{$root}/work/{$name}";

    if (!isset($over['workdir'])) {
        @mkdir($workdir, 0700, true);
    }

    SidecarStore::write_sidecar($name, array_merge([
        'workdir' => $workdir, 'spawned_at' => time(), 'agent_session_id' => null,
        'spawned_by_app' => true, 'agent' => 'claude', 'runtime' => 'headless', 'title' => null, 'profile' => null,
    ], $over));

    return (string)$workdir;
}

function client(array $m): ClaudeHeadlessManagerClient
{
    return new ClaudeHeadlessManagerClient($m['sock'], 30);
}

/** @return array<string, mixed> */
function call(array $m, string $method, array $params = []): array
{
    return client($m)->request($method, $params);
}

function status_is(string $name, string $status): bool
{
    return (SessionStatusStore::read_status($name)['status'] ?? null) === $status;
}

function process_of(array $m, string $name): string
{
    return (string)(call($m, 'sessioneer/status', ['session' => $name])['process'] ?? '?');
}

/** Sends raw bytes as one request line and returns the decoded reply. @return array<string, mixed> */
function raw_request(array $m, string $line): array
{
    $sock = stream_socket_client('unix://' . $m['sock'], $errno, $err, 2.0);
    fwrite($sock, $line . "\n");
    stream_set_timeout($sock, 5);
    $reply = json_decode((string)fgets($sock), true);
    fclose($sock);

    return is_array($reply) ? $reply : [];
}
