<?php

declare(strict_types=1);

/** Persistent, directory-scoped OpenCode v1 /event consumer. */

require_once __DIR__ . '/../vendor/autoload.php';

use HostAgent\Runtimes\OpenCodeServeClient;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\Config;
use HostAgent\Services\OpenCodeSseConsumer;
use HostAgent\Services\OpenCodeSseFrameParser;
use HostAgent\Services\ProcessRunner;
use HostAgent\Stores\SidecarStore;

/** @var array<string,array{process:resource,stdout:resource,stderr:resource,parser:OpenCodeSseFrameParser,tracked:array<string,string>,connected:bool}> $connections */
$connections = [];
$running = true;
$lastReconcile = 0.0;

if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, static function () use (&$running): void { $running = false; });
    pcntl_signal(SIGINT, static function () use (&$running): void { $running = false; });
}

/** @param array<string,string> $tracked */
function opencode_sse_recover(string $directory, array $tracked): void
{
    // Empty/error is intentionally non-authoritative: it must never erase a
    // prompt learned from SSE. This is only a reconnect gap recovery attempt.
    $result = ProcessRunner::run_process([
        'curl', '--silent', '--show-error', '--fail-with-body', '--max-time', '3',
        '--header', 'x-opencode-directory: ' . rawurlencode($directory),
        Config::opencode_server_url() . '/question',
    ]);
    if ($result['exit'] !== 0) return;
    $pending = json_decode($result['stdout'], true);
    if (!is_array($pending)) return;
    foreach ($pending as $question) {
        if (is_array($question)) OpenCodeSseConsumer::handle(['type' => 'question.asked', 'properties' => $question], $directory, $tracked);
    }
}

/** @param array<string,string> $tracked */
function opencode_sse_start(string $directory, array $tracked): ?array
{
    $process = proc_open([
        'curl', '--silent', '--show-error', '--fail-with-body', '--no-buffer',
        '--connect-timeout', '3', '--speed-limit', '1', '--speed-time', '90',
        '--header', 'Accept: text/event-stream',
        '--header', 'x-opencode-directory: ' . rawurlencode($directory),
        Config::opencode_server_url() . '/event',
    ], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) {
        fwrite(STDERR, "OpenCode SSE: could not subscribe {$directory}\n");
        return null;
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    fwrite(STDERR, "OpenCode SSE: subscribing {$directory}\n");
    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2], 'parser' => new OpenCodeSseFrameParser(), 'tracked' => $tracked, 'connected' => false];
}

function opencode_sse_stop(array $connection): void
{
    foreach (['stdout', 'stderr'] as $pipe) if (is_resource($connection[$pipe])) fclose($connection[$pipe]);
    if (is_resource($connection['process'])) {
        $status = proc_get_status($connection['process']);
        if (($status['running'] ?? false) === true) proc_terminate($connection['process']);
        proc_close($connection['process']);
    }
}

try {
    while ($running) {
        if (microtime(true) - $lastReconcile >= 5.0) {
            $lastReconcile = microtime(true);
            $list = (new OpenCodeServeClient())->list_sessions();
            if ($list['ok'] === true) {
                $wanted = OpenCodeSseConsumer::subscriptions(SidecarStore::list_runtime_sidecars(RuntimeType::HEADLESS), $list['sessions'] ?? []);
                foreach ($connections as $directory => $connection) {
                    if (!isset($wanted[$directory]) || $wanted[$directory] !== $connection['tracked']) {
                        opencode_sse_stop($connection);
                        unset($connections[$directory]);
                    }
                }
                foreach ($wanted as $directory => $tracked) {
                    if (!isset($connections[$directory])) {
                        $connection = opencode_sse_start($directory, $tracked);
                        if ($connection !== null) $connections[$directory] = $connection;
                    }
                }
            }
        }

        $read = [];
        $byStream = [];
        foreach ($connections as $directory => $connection) {
            foreach (['stdout', 'stderr'] as $pipe) {
                if (!is_resource($connection[$pipe])) continue;
                $read[] = $connection[$pipe];
                $byStream[(int)$connection[$pipe]] = [$directory, $pipe];
            }
        }
        if ($read === []) { usleep(200000); continue; }
        $write = $except = [];
        if (@stream_select($read, $write, $except, 1) === false) continue;
        foreach ($read as $stream) {
            [$directory, $pipe] = $byStream[(int)$stream];
            if (!isset($connections[$directory]) || !is_resource($stream)) continue;
            $chunk = fread($stream, 65536);
            if ($chunk === '' && feof($stream)) {
                opencode_sse_stop($connections[$directory]);
                unset($connections[$directory]);
                continue;
            }
            if ($chunk === false || $chunk === '') continue;
            if ($pipe === 'stderr') { fwrite(STDERR, $chunk); continue; }
            foreach ($connections[$directory]['parser']->push($chunk) as $event) {
                $envelopeType = is_string($event['type'] ?? null) ? $event['type'] : '';
                if ($envelopeType === 'server.connected' && !$connections[$directory]['connected']) {
                    // This occurs in stream order: recovery is applied before
                    // later buffered asked/replied events can be processed.
                    $connections[$directory]['connected'] = true;
                    fwrite(STDERR, "OpenCode SSE: connected {$directory}\n");
                    opencode_sse_recover($directory, $connections[$directory]['tracked']);
                }
                OpenCodeSseConsumer::handle($event, $directory, $connections[$directory]['tracked']);
            }
        }
    }
} finally {
    foreach ($connections as $connection) opencode_sse_stop($connection);
}
