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
use HostAgent\Services\CodexWebSocketFrames;
use HostAgent\Services\Config;
use HostAgent\Stores\SessionStatusStore;

$codexBin = Config::codex_bin();
if ($codexBin === '') {
    fwrite(STDERR, "CODEX_BIN is not configured\n");
    exit(1);
}

/**
 * Prefer the managed daemon Codex Remote (the phone app) uses: a thread that
 * lives there is visible to the phone, and the daemon sends a server request
 * (approval, request_user_input) to every connection that has the thread loaded,
 * so either side can answer. A private `app-server --stdio` child is the
 * fallback when the daemon is not running; its threads belong to this bridge alone.
 *
 * @return array{0:resource,1:resource,2:resource|null,3:resource|null,4:CodexWebSocketFrames|null}
 */
function codex_bridge_connect(string $codexBin): array
{
    $daemonSocket = Config::codex_daemon_socket();
    if ($daemonSocket !== '' && file_exists($daemonSocket)) {
        $socket = @stream_socket_client('unix://' . $daemonSocket, $errno, $error, 3);
        if ($socket !== false) {
            fwrite($socket, CodexWebSocketFrames::handshake_request());
            $response = '';
            $deadline = microtime(true) + 3;
            while (!str_contains($response, "\r\n\r\n") && microtime(true) < $deadline) {
                $chunk = fread($socket, 1);
                if ($chunk === false || $chunk === '') break;
                $response .= $chunk;
            }
            if (str_starts_with($response, 'HTTP/1.1 101')) {
                return [null, $socket, null, null, new CodexWebSocketFrames()];
            }
            fclose($socket);
        }
        fwrite(STDERR, "Codex daemon socket unusable, falling back to a private app-server\n");
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
    [$in, $out, $err] = $pipes;
    stream_set_blocking($out, false);
    stream_set_blocking($err, false);
    return [$in, $out, $err, $process, null];
}

[$appIn, $appOut, $appErr, $process, $wsFrames] = codex_bridge_connect($codexBin);
// The daemon connection is one duplex socket; the read side is $appOut either way.
if ($wsFrames !== null) $appIn = $appOut;
$appOutBuffer = '';

/**
 * @param resource $stream
 * @param array<string,mixed> $message
 */
function codex_bridge_write($stream, array $message): void
{
    global $wsFrames;
    $json = json_encode($message, JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Codex app-server pipe closed');
    }
    $payload = $wsFrames !== null ? CodexWebSocketFrames::encode($json) : $json . "\n";
    $offset = 0;
    while ($offset < strlen($payload)) {
        $written = @fwrite($stream, substr($payload, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('Codex app-server pipe closed');
        $offset += $written;
    }
    fflush($stream);
}

/** Turns raw bytes from the app-server into newline-delimited JSON lines in $appOutBuffer. */
function codex_bridge_ingest(string $chunk): void
{
    global $wsFrames, $appIn, $appOutBuffer;
    if ($wsFrames === null) {
        $appOutBuffer .= $chunk;
        return;
    }
    $decoded = $wsFrames->feed($chunk);
    if ($decoded['reply'] !== '') @fwrite($appIn, $decoded['reply']);
    if ($decoded['closed']) throw new RuntimeException('Codex daemon closed the connection');
    foreach ($decoded['messages'] as $message) $appOutBuffer .= $message . "\n";
}

codex_bridge_write($appIn, [
    'method' => 'initialize',
    'id' => 1,
    'params' => ['clientInfo' => ['name' => 'sessioneer', 'title' => 'Sessioneer', 'version' => '0.1'], 'capabilities' => ['experimentalApi' => true]],
]);

$initialized = false;
$deadline = microtime(true) + 10;
while (!$initialized && microtime(true) < $deadline) {
    $read = [$appOut];
    $write = $except = [];
    if (stream_select($read, $write, $except, 1) > 0) {
        $chunk = fread($appOut, 65536);
        if ($chunk !== false && $chunk !== '') codex_bridge_ingest($chunk);
        // Take only the initialize reply out of the buffer; anything the
        // server sent alongside it stays for the main loop.
        $lines = explode("\n", $appOutBuffer);
        $appOutBuffer = array_pop($lines);
        $kept = '';
        foreach ($lines as $line) {
            $message = json_decode($line, true);
            if (!$initialized && is_array($message) && ($message['id'] ?? null) === 1 && isset($message['result'])) {
                $initialized = true;
                continue;
            }
            $kept .= $line . "\n";
        }
        $appOutBuffer = $kept . $appOutBuffer;
    }
}

if (!$initialized) {
    fwrite(STDERR, "Codex app-server initialize timed out\n");
    if ($process !== null) proc_terminate($process);
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
    if ($process !== null) proc_terminate($process);
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
/** @var array<int,string> $rpcMethods method behind each in-flight client request, to learn which ones attach a thread */
$rpcMethods = [];

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

/**
 * Threads this connection has loaded: the daemon only sends a thread's server
 * requests (approvals, questions) to connections that have it loaded, and a
 * thread another client (the phone app) started is not loaded here until we
 * resume it. A brand-new thread has no rollout to resume until its first turn
 * has run, so a refused resume is retried a few times.
 * @var array<string,true> $attached
 */
$attached = [];
/** @var array<string,array{at:float,tries:int}> $resumeDue */
$resumeDue = [];
/** @var array<int,array{kind:string,thread?:string,tries?:int}> $internalRpc replies to requests the bridge made for itself */
$internalRpc = [];
$nextInternalId = 1000000;
const CODEX_BRIDGE_RESUME_TRIES = 10;

/** @param array<string,mixed> $params @param array{kind:string,thread?:string,tries?:int} $meta */
function codex_bridge_internal(string $method, array $params, array $meta): void
{
    global $appIn, $internalRpc, $nextInternalId;
    $id = $nextInternalId++;
    $internalRpc[$id] = $meta;
    codex_bridge_write($appIn, ['method' => $method, 'id' => $id, 'params' => (object)$params]);
}

function codex_bridge_schedule_resume(string $threadId, int $tries = 0, float $delay = 0.0): void
{
    global $attached, $resumeDue;
    if ($threadId === '' || isset($attached[$threadId]) || isset($resumeDue[$threadId])) return;
    $resumeDue[$threadId] = ['at' => microtime(true) + $delay, 'tries' => $tries];
}

/** Status-store fields for a thread that is waiting on a human but whose request this connection never received. */
function codex_bridge_external_block(string $question): array
{
    return ['tool_name' => 'external_input', 'question' => $question, 'context' => '', 'options' => [], 'multi_question' => false, 'is_folder_trust' => false, 'tool_input' => [], 'external' => true];
}

// Threads already running in the daemon when the bridge starts.
if ($wsFrames !== null) codex_bridge_internal('thread/loaded/list', [], ['kind' => 'loaded']);

try {
    while (true) {
        $read = array_merge([$server, $appOut], $appErr !== null ? [$appErr] : [], array_values($clients));
        $write = $except = [];
        if (stream_select($read, $write, $except, 1) === false) continue;

        $now = microtime(true);
        foreach ($resumeDue as $dueThread => $due) {
            if ($due['at'] > $now) continue;
            unset($resumeDue[$dueThread]);
            codex_bridge_internal('thread/resume', ['threadId' => $dueThread, 'excludeTurns' => true], ['kind' => 'resume', 'thread' => $dueThread, 'tries' => $due['tries']]);
        }

        foreach ($read as $stream) {
            if ($stream === $server) {
                $client = @stream_socket_accept($server, 0);
                if ($client !== false) {
                    stream_set_blocking($client, false);
                    $clients[(int)$client] = $client;
                }
                continue;
            }

            if ($appErr !== null && $stream === $appErr) {
                while (($line = fgets($appErr)) !== false) fwrite(STDERR, $line);
                continue;
            }

            if ($stream === $appOut) {
                $chunk = fread($appOut, 65536);
                if ($chunk !== false && $chunk !== '') codex_bridge_ingest($chunk);

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
                        if (isset($internalRpc[$id])) {
                            $meta = $internalRpc[$id];
                            unset($internalRpc[$id]);
                            if ($meta['kind'] === 'loaded' && is_array($message['result']['data'] ?? null)) {
                                foreach ($message['result']['data'] as $loadedId) {
                                    if (is_string($loadedId)) codex_bridge_schedule_resume($loadedId);
                                }
                            } elseif ($meta['kind'] === 'resume') {
                                $resumed = $meta['thread'] ?? '';
                                if (isset($message['error'])) {
                                    $tries = ($meta['tries'] ?? 0) + 1;
                                    if ($tries < CODEX_BRIDGE_RESUME_TRIES && str_contains((string)($message['error']['message'] ?? ''), 'no rollout')) {
                                        codex_bridge_schedule_resume($resumed, $tries, 1.0);
                                    }
                                } elseif ($resumed !== '') {
                                    $attached[$resumed] = true;
                                }
                            }
                            continue;
                        }
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
                                    if (($rpcMethods[$id] ?? '') === 'thread/start') $attached[$resultThreadId] = true;
                                    unset($rpcMethods[$id]);
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
                    // thread/started nests the thread rather than carrying a threadId.
                    if ($threadId === '' && $method === 'thread/started' && is_string($params['thread']['id'] ?? null)) {
                        $threadId = $params['thread']['id'];
                    }

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

                    if ($threadId !== '' && $method === 'thread/started') {
                        codex_bridge_schedule_resume($threadId);
                    } elseif ($threadId !== '' && $method === 'serverRequest/resolved') {
                        // Answered by us, by the phone app, or withdrawn: only the request
                        // this notice names goes away, never a newer prompt.
                        if (isset($pendingPrompts[$threadId]) && (string)$pendingPrompts[$threadId]['request_id'] === (string)($params['requestId'] ?? '')) {
                            unset($pendingPrompts[$threadId]);
                            SessionStatusStore::update_status($threadId, ['status' => 'working', 'blocked' => null]);
                        }
                    } elseif ($threadId !== '' && $method === 'item/autoApprovalReview/completed') {
                        // The reviewer settled this approval on its own, so a human never
                        // needed to; drop the observe-only notice the hook recorded for it.
                        $current = SessionStatusStore::read_status($threadId);
                        if (!isset($pendingPrompts[$threadId]) && ($current['blocked']['external'] ?? false) === true) {
                            SessionStatusStore::update_status($threadId, ['status' => 'working', 'blocked' => null]);
                        }
                    } elseif ($threadId !== '' && $method === 'thread/status/changed') {
                        $type = $params['status']['type'] ?? 'idle';
                        $flags = is_array($params['status']['activeFlags'] ?? null) ? $params['status']['activeFlags'] : [];
                        if ($type === 'active') codex_bridge_schedule_resume($threadId);
                        if ($type === 'notLoaded') unset($attached[$threadId]);
                        if ($type !== 'active') {
                            unset($pendingPrompts[$threadId]);
                            SessionStatusStore::update_status($threadId, ['status' => 'idle', 'blocked' => null]);
                        } elseif (!isset($pendingPrompts[$threadId])) {
                            // A real prompt we hold must outlive unrelated status events; with none held,
                            // a waiting flag means a request we cannot answer (not attached yet).
                            if (in_array('waitingOnApproval', $flags, true) || in_array('waitingOnUserInput', $flags, true)) {
                                $current = SessionStatusStore::read_status($threadId);
                                SessionStatusStore::update_status($threadId, ['status' => 'blocked'] + (($current['blocked'] ?? null) === null
                                    ? ['blocked' => codex_bridge_external_block(in_array('waitingOnUserInput', $flags, true) ? 'Input required in Codex Remote.' : 'Approval required in Codex Remote.')]
                                    : []));
                            } else {
                                SessionStatusStore::update_status($threadId, ['status' => 'working', 'blocked' => null]);
                            }
                        }
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
            $rpcMethods[$rpcId] = $method;
            codex_bridge_write($appIn, ['method' => $method, 'id' => $rpcId, 'params' => (object)$params]);
        }
    }
} finally {
    foreach ($clients as $client) @fclose($client);
    @fclose($server);
    @unlink($socketPath);
    if ($process !== null) @proc_terminate($process);
    @fclose($appOut);
}
