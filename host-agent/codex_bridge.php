<?php

declare(strict_types=1);

/**
 * Persistent bidirectional bridge between Sessioneer's request-per-process host
 * agent and `codex app-server --stdio`. Codex server requests (approvals and
 * request_user_input) belong to this long-lived connection, never to tmux.
 */

require_once __DIR__ . '/../vendor/autoload.php';

use HostAgent\Services\CodexPromptProtocol;
use HostAgent\Services\CodexTranscriptService;
use HostAgent\Services\Config;
use HostAgent\Stores\SessionStatusStore;

$codexBin = Config::codex_bin();
if ($codexBin === '') {
    fwrite(STDERR, "CODEX_BIN is not configured\n");
    exit(1);
}

$process = proc_open([$codexBin, 'app-server', '--stdio'], [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
], $pipes);

if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start codex app-server\n");
    exit(1);
}

[$appIn, $appOut, $appErr] = $pipes;
stream_set_blocking($appOut, false);
stream_set_blocking($appErr, false);

/**
 * @param resource $stream
 * @param array<string,mixed> $message
 */
function codex_bridge_write($stream, array $message): void
{
    $json = json_encode($message, JSON_UNESCAPED_SLASHES);
    if ($json === false || fwrite($stream, $json . "\n") === false) {
        throw new RuntimeException('Codex app-server pipe closed');
    }
    fflush($stream);
}

codex_bridge_write($appIn, [
    'method' => 'initialize',
    'id' => 1,
    'params' => ['clientInfo' => ['name' => 'sessioneer', 'title' => 'Sessioneer', 'version' => '0.1']],
]);

$initialized = false;
$deadline = microtime(true) + 10;
while (!$initialized && microtime(true) < $deadline) {
    $read = [$appOut];
    $write = $except = [];
    if (stream_select($read, $write, $except, 1) > 0) {
        while (($line = fgets($appOut)) !== false) {
            $message = json_decode($line, true);
            if (is_array($message) && ($message['id'] ?? null) === 1 && isset($message['result'])) {
                $initialized = true;
                break;
            }
        }
    }
}

if (!$initialized) {
    fwrite(STDERR, "Codex app-server initialize timed out\n");
    proc_terminate($process);
    exit(1);
}

codex_bridge_write($appIn, ['method' => 'initialized']);

// A server request's JSON-RPC id belongs to the app-server process that
// issued it and cannot be replayed safely after a bridge/app-server restart.
// The normalized prompt is persisted for display while live; clear any
// leftover Codex prompt now so the UI cannot offer an answer that has no
// valid recipient. Fresh thread/read/status events reconcile the turn state.
SessionStatusStore::clear_stale_blocked_for_agent(
    'codex',
    'Codex app-server restarted while waiting for input; retry the interrupted turn.'
);

$socketPath = Config::codex_bridge_socket();
$socketDir = dirname($socketPath);
if (!is_dir($socketDir)) @mkdir($socketDir, 0700, true);
if (file_exists($socketPath)) @unlink($socketPath);
$server = @stream_socket_server('unix://' . $socketPath, $errno, $error);
if ($server === false) {
    fwrite(STDERR, "Cannot bind Codex bridge socket: {$error}\n");
    proc_terminate($process);
    exit(1);
}
@chmod($socketPath, 0660);
stream_set_blocking($server, false);

/** @var array<int,resource> $clients */
$clients = [];
/** @var array<int,resource> $rpcClients */
$rpcClients = [];
/** @var array<string,array{request_id:int|string,method:string,params:array<string,mixed>,prompt:array<string,mixed>}> $pendingPrompts */
$pendingPrompts = [];
/** @var array<string,string> $activeTurns */
$activeTurns = [];
$nextRpcId = 100;
$appOutBuffer = '';

/**
 * @param resource $client
 * @param array<string,mixed> $response
 */
function codex_bridge_reply($client, array $response): void
{
    $json = json_encode($response, JSON_UNESCAPED_SLASHES);
    if ($json !== false) {
        $payload = $json . "\n";
        $offset = 0;
        stream_set_blocking($client, true);
        while ($offset < strlen($payload)) {
            $written = @fwrite($client, substr($payload, $offset));
            if ($written === false || $written === 0) break;
            $offset += $written;
        }
    }
    @fclose($client);
}

try {
    while (true) {
        $read = array_merge([$server, $appOut, $appErr], array_values($clients));
        $write = $except = [];
        if (stream_select($read, $write, $except, null) === false) continue;

        foreach ($read as $stream) {
            if ($stream === $server) {
                $client = @stream_socket_accept($server, 0);
                if ($client !== false) {
                    stream_set_blocking($client, false);
                    $clients[(int)$client] = $client;
                }
                continue;
            }

            if ($stream === $appErr) {
                while (($line = fgets($appErr)) !== false) fwrite(STDERR, $line);
                continue;
            }

            if ($stream === $appOut) {
                $chunk = fread($appOut, 65536);
                if ($chunk !== false && $chunk !== '') $appOutBuffer .= $chunk;

                // app-server speaks newline-delimited JSON, but stdout is a
                // nonblocking pipe: a large thread/read response can arrive
                // across multiple fread/fgets calls. Never decode or discard
                // anything until its terminating newline has arrived.
                while (($newline = strpos($appOutBuffer, "\n")) !== false) {
                    $line = substr($appOutBuffer, 0, $newline);
                    $appOutBuffer = substr($appOutBuffer, $newline + 1);
                    $message = json_decode($line, true);
                    if (!is_array($message)) continue;

                    if (array_key_exists('id', $message) && !isset($message['method'])) {
                        $id = (int)$message['id'];
                        if (isset($rpcClients[$id])) {
                            $client = $rpcClients[$id];
                            unset($rpcClients[$id], $clients[(int)$client]);
                            if (isset($message['error'])) {
                                codex_bridge_reply($client, ['ok' => false, 'message' => (string)($message['error']['message'] ?? 'Codex request failed'), 'error' => $message['error']]);
                            } else {
                                $result = $message['result'] ?? null;

                                // A thread can already be running when this
                                // bridge starts (or when another Codex client
                                // starts its turn). In that case we never saw
                                // turn/started and would wrongly submit the
                                // compose message as a new turn. Learn the
                                // current turn from the thread/read response
                                // that session.php fetches before composing,
                                // so sessioneer/sendInput can steer it instead.
                                $thread = is_array($result) && is_array($result['thread'] ?? null)
                                    ? $result['thread']
                                    : null;
                                $resultThreadId = is_array($thread) && is_string($thread['id'] ?? null)
                                    ? $thread['id']
                                    : '';
                                if ($resultThreadId !== '') {
                                    $statusType = $thread['status']['type'] ?? 'idle';
                                    if ($statusType !== 'active') {
                                        unset($activeTurns[$resultThreadId]);
                                    } else {
                                        $turns = is_array($thread['turns'] ?? null) ? $thread['turns'] : [];
                                        for ($turnIndex = count($turns) - 1; $turnIndex >= 0; $turnIndex--) {
                                            $turn = $turns[$turnIndex];
                                            if (is_array($turn) && ($turn['status'] ?? null) === 'inProgress' && is_string($turn['id'] ?? null)) {
                                                $activeTurns[$resultThreadId] = $turn['id'];
                                                break;
                                            }
                                        }
                                    }
                                }

                                codex_bridge_reply($client, ['ok' => true, 'result' => $result]);
                            }
                        }
                        continue;
                    }

                    $method = is_string($message['method'] ?? null) ? $message['method'] : '';
                    $params = is_array($message['params'] ?? null) ? $message['params'] : [];
                    $threadId = is_string($params['threadId'] ?? null) ? $params['threadId'] : '';

                    if (array_key_exists('id', $message) && $threadId !== '' && in_array($method, [
                        'item/commandExecution/requestApproval',
                        'item/fileChange/requestApproval',
                        'item/permissions/requestApproval',
                        'item/tool/requestUserInput',
                    ], true)) {
                        $prompt = CodexPromptProtocol::normalize_prompt($method, $params);
                        $pendingPrompts[$threadId] = ['request_id' => $message['id'], 'method' => $method, 'params' => $params, 'prompt' => $prompt];
                        SessionStatusStore::update_status($threadId, ['status' => 'blocked', 'blocked' => $prompt]);
                        continue;
                    }

                    if ($threadId !== '' && $method === 'thread/status/changed') {
                        $type = $params['status']['type'] ?? 'idle';
                        SessionStatusStore::update_status($threadId, ['status' => $type === 'active' ? 'working' : 'idle', 'blocked' => null]);
                    } elseif ($threadId !== '' && $method === 'turn/started') {
                        $turnId = is_string($params['turn']['id'] ?? null) ? $params['turn']['id'] : '';
                        if ($turnId !== '') $activeTurns[$threadId] = $turnId;
                        SessionStatusStore::update_status($threadId, ['status' => 'working', 'blocked' => null, 'last_turn_error' => null]);
                    } elseif ($threadId !== '' && $method === 'turn/completed') {
                        unset($pendingPrompts[$threadId], $activeTurns[$threadId]);
                        $turn = is_array($params['turn'] ?? null) ? $params['turn'] : [];
                        SessionStatusStore::update_status($threadId, [
                            'status' => 'idle',
                            'blocked' => null,
                            'last_turn_error' => CodexTranscriptService::turn_error_message($turn['error'] ?? null),
                        ]);
                    } elseif ($threadId !== '' && $method === 'thread/tokenUsage/updated') {
                        $usage = is_array($params['tokenUsage'] ?? null) ? $params['tokenUsage'] : null;
                        if ($usage !== null) SessionStatusStore::update_status($threadId, ['token_usage' => $usage]);
                    }
                }
                if (feof($appOut)) {
                    throw new RuntimeException('Codex app-server exited');
                }
                continue;
            }

            $clientId = (int)$stream;
            $line = fgets($stream);
            if ($line === false) {
                if (feof($stream)) { unset($clients[$clientId]); fclose($stream); }
                continue;
            }
            $request = json_decode($line, true);
            if (!is_array($request) || !is_string($request['method'] ?? null)) {
                unset($clients[$clientId]);
                codex_bridge_reply($stream, ['ok' => false, 'message' => 'Invalid bridge request']);
                continue;
            }

            $method = $request['method'];
            $params = is_array($request['params'] ?? null) ? $request['params'] : [];
            $threadId = is_string($params['threadId'] ?? null) ? $params['threadId'] : '';

            if ($method === 'sessioneer/pendingPrompt') {
                unset($clients[$clientId]);
                codex_bridge_reply($stream, ['ok' => true, 'prompt' => $pendingPrompts[$threadId]['prompt'] ?? null]);
                continue;
            }

            if ($method === 'sessioneer/answerPrompt') {
                $pending = $pendingPrompts[$threadId] ?? null;
                $response = is_array($pending) ? CodexPromptProtocol::prompt_response($pending, is_array($params['answers'] ?? null) ? $params['answers'] : []) : null;
                unset($clients[$clientId]);
                if ($pending === null || $response === null) {
                    codex_bridge_reply($stream, ['ok' => false, 'message' => 'Rejected: no matching Codex prompt or invalid answer']);
                    continue;
                }
                codex_bridge_write($appIn, ['id' => $pending['request_id'], 'result' => $response]);
                unset($pendingPrompts[$threadId]);
                SessionStatusStore::update_status($threadId, ['status' => 'working', 'blocked' => null, 'last_turn_error' => null]);
                codex_bridge_reply($stream, ['ok' => true, 'message' => 'Prompt answered']);
                continue;
            }

            if ($method === 'sessioneer/sendInput') {
                $input = is_array($params['input'] ?? null) ? $params['input'] : [];
                $rpcMethod = isset($activeTurns[$threadId]) ? 'turn/steer' : 'turn/start';
                $rpcParams = ['threadId' => $threadId, 'input' => $input];
                if ($rpcMethod === 'turn/steer') $rpcParams['expectedTurnId'] = $activeTurns[$threadId];
                $rpcId = $nextRpcId++;
                $rpcClients[$rpcId] = $stream;
                codex_bridge_write($appIn, ['method' => $rpcMethod, 'id' => $rpcId, 'params' => $rpcParams]);
                continue;
            }

            if ($method === 'sessioneer/interrupt') {
                unset($clients[$clientId]);
                $turnId = $activeTurns[$threadId] ?? null;
                if (!is_string($turnId)) {
                    codex_bridge_reply($stream, ['ok' => false, 'message' => 'Codex has no active turn to interrupt']);
                    continue;
                }
                $rpcId = $nextRpcId++;
                $rpcClients[$rpcId] = $stream;
                codex_bridge_write($appIn, ['method' => 'turn/interrupt', 'id' => $rpcId, 'params' => ['threadId' => $threadId, 'turnId' => $turnId]]);
                continue;
            }

            $rpcId = $nextRpcId++;
            $rpcClients[$rpcId] = $stream;
            codex_bridge_write($appIn, ['method' => $method, 'id' => $rpcId, 'params' => (object)$params]);
        }
    }
} finally {
    foreach ($clients as $client) @fclose($client);
    @fclose($server);
    @unlink($socketPath);
    @proc_terminate($process);
}
