<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Agents\ClaudeCodeAdapter;
use HostAgent\Services\Config;
use HostAgent\Services\PermissionMode;
use HostAgent\Services\QuotaLiveStateWriter;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/**
 * The persistent host-native manager behind sessioneer-claude-headless-manager
 * .service: owns one long-lived `claude -p` stream-json child per ACTIVE
 * headless Claude session and exposes a narrow Sessioneer-shaped UNIX-socket
 * API (one request, one reply, newline-delimited JSON) to the socket-activated
 * host agent, exactly like the Codex bridge does for `codex app-server`.
 *
 * Why it must be a separate long-lived process: a `can_use_tool` request
 * arrives as JSON on the child's stdout and its answer must carry the
 * original request_id, so whoever holds the child's pipes must outlive any
 * single web request.
 *
 * The child process is a CACHE and the sidecar row is the truth: a session
 * with no live child is "dormant" and the next message respawns it with
 * `--resume`. Nothing needs re-adopting after a manager restart.
 *
 * This class is the transport/lifecycle layer only. It stores the RAW
 * pending prompt; mapping it to the dashboard's canonical prompt shape and
 * back is the runtime layer's job (ClaudeHeadlessRuntime / prompt protocol).
 *
 * The manager is the SOLE writer of session_status for headless sessions.
 * Its children deliberately do NOT get SESSIONEER_SESSION_NAME, which every
 * Claude hook script requires, so the hooks stay silent for them.
 *
 * Research/decision records: Dibs #280 (protocol), #282 (design).
 */
final class ClaudeHeadlessManager
{
    private const LOG_PREFIX = '[claude-headless] ';

    /** One request line may carry base64 images; cap it well above a sane payload. */
    private const REQUEST_MAX_BYTES = 33554432;

    private const CONTROL_TIMEOUT_SECONDS = 15.0;

    /** Extra seconds between SIGTERM and SIGKILL. */
    private const KILL_AFTER_TERM_SECONDS = 2.0;

    private const SELECT_TIMEOUT_SECONDS = 1;

    private const EXIT_REAP_WAIT_SECONDS = 0.5;

    private const RESTART_MESSAGE = 'Claude headless manager restarted while this session was busy; retry the interrupted turn.';

    /** Environment variables that would silently switch a child from the subscription login to an API key. */
    private const STRIPPED_ENV = ['ANTHROPIC_API_KEY', 'ANTHROPIC_AUTH_TOKEN', 'SESSIONEER_SESSION_NAME'];

    /** @var resource|null */
    private $server = null;

    /** @var array<int, array{stream: resource, buffer: string}> */
    private array $clients = [];

    /** @var array<string, ClaudeHeadlessChild> */
    private array $children = [];

    private bool $shutdown = false;

    private ?string $spawnBlockedReason = null;

    /**
     * Status writes that threw (Dibs 388: a can_use_tool control request left
     * a child correctly `blocked` in memory while SessionStatusStore's row
     * silently stayed on its previous value - a busy/locked SQLite write is
     * the suspected cause, never confirmed by a captured log line) - retried
     * every housekeeping tick until one succeeds, keyed by session name so a
     * retry survives the child itself being torn down. Only ever the LATEST
     * snapshot per session, same "last write wins" rule every other status
     * field already follows - an older failed write superseded by a newer
     * one is simply dropped, not queued twice.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $pendingStatusWrites = [];

    private ?string $lastApiKeySource = null;

    private ?string $lastClaudeVersion = null;

    /** @var array<string, mixed>|null */
    private ?array $lastRateLimit = null;

    private ClaudeCodeAdapter $adapter;

    public function __construct(
        private string $socketPath,
        private int $idleSeconds,
        private int $maxChildren,
        private int $stopGraceSeconds,
        ?ClaudeCodeAdapter $adapter = null,
    ) {
        $this->adapter = $adapter ?? new ClaudeCodeAdapter();
    }

    public static function from_config(): self
    {
        return new self(
            Config::claude_headless_socket(),
            Config::claude_headless_idle_seconds(),
            Config::claude_headless_max_children(),
            Config::claude_headless_stop_grace_seconds(),
        );
    }

    public function run(): void
    {
        $this->bind();

        // Every child from a previous manager died with it, so anything still
        // recorded as working/blocked is stale and its prompt can no longer be
        // answered. Same-agent tmux sessions (hook-owned) are untouched.
        SessionStatusStore::reset_stale_for_runtime('claude', RuntimeType::HEADLESS, self::RESTART_MESSAGE);

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function (): void {
                $this->shutdown = true;
            };
            pcntl_signal(SIGTERM, $stop);
            pcntl_signal(SIGINT, $stop);
        }

        $this->log('listening on ' . $this->socketPath . ' (max ' . $this->maxChildren . ' children, idle ' . $this->idleSeconds . 's)');

        try {
            while (!$this->shutdown) {
                try {
                    $this->poll_once();
                    $this->housekeeping();
                } catch (\Throwable $e) {
                    // Handled (logged) so one bad session or event cannot take
                    // down every other live child; re-thrown in debug mode.
                    $this->unexpected($e, 'main loop');
                    usleep(100000);
                }
            }
        } finally {
            $this->stop_all_children();
            if (is_resource($this->server)) {
                @fclose($this->server);
            }
            @unlink($this->socketPath);
        }
    }

    // ------------------------------------------------------------------ loop

    private function bind(): void
    {
        $dir = dirname($this->socketPath);

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        if (file_exists($this->socketPath)) {
            // A reused fixed path: refuse to start on top of a LIVE listener,
            // clear a stale leftover file otherwise.
            $probe = @stream_socket_client('unix://' . $this->socketPath, $errno, $error, 0.3);

            if ($probe !== false) {
                fclose($probe);
                throw new \RuntimeException('Another Claude headless manager is already listening on ' . $this->socketPath);
            }

            @unlink($this->socketPath);
        }

        $server = @stream_socket_server('unix://' . $this->socketPath, $errno, $error);

        if ($server === false) {
            throw new \RuntimeException("Cannot bind Claude headless manager socket {$this->socketPath}: {$error}");
        }

        @chmod($this->socketPath, 0660);
        stream_set_blocking($server, false);
        $this->server = $server;
    }

    private function poll_once(): void
    {
        $read = [];
        /** @var array<int, array{child: ClaudeHeadlessChild, kind: string}> $childStreams */
        $childStreams = [];

        if (is_resource($this->server)) {
            $read[] = $this->server;
        }

        foreach ($this->clients as $client) {
            $read[] = $client['stream'];
        }

        foreach ($this->children as $child) {
            $out = $child->stdout_stream();
            $err = $child->stderr_stream();
            $read[] = $out;
            $read[] = $err;
            $childStreams[(int)$out] = ['child' => $child, 'kind' => 'out'];
            $childStreams[(int)$err] = ['child' => $child, 'kind' => 'err'];
        }

        $write = null;
        $except = null;
        $ready = @stream_select($read, $write, $except, self::SELECT_TIMEOUT_SECONDS);

        if ($ready === false || $ready === 0) {
            return;
        }

        foreach ($read as $stream) {
            $id = (int)$stream;

            if ($stream === $this->server) {
                $this->accept_client();
            } elseif (isset($this->clients[$id])) {
                $this->read_client($id);
            } elseif (isset($childStreams[$id])) {
                $entry = $childStreams[$id];

                // An earlier stream in this same select round may already have
                // finalized (and closed) this child.
                if (($this->children[$entry['child']->name] ?? null) !== $entry['child']) {
                    continue;
                }

                if ($entry['kind'] === 'err') {
                    $entry['child']->drain_stderr();
                } else {
                    $this->pump_child($entry['child']);
                }
            }
        }
    }

    private function accept_client(): void
    {
        if (!is_resource($this->server)) {
            return;
        }

        $client = @stream_socket_accept($this->server, 0);

        if ($client === false) {
            return;
        }

        stream_set_blocking($client, false);
        $this->clients[(int)$client] = ['stream' => $client, 'buffer' => ''];
    }

    private function read_client(int $id): void
    {
        $stream = $this->clients[$id]['stream'];
        $chunk = @fread($stream, 65536);

        if ($chunk !== false && $chunk !== '') {
            $this->clients[$id]['buffer'] .= $chunk;
        }

        if (strlen($this->clients[$id]['buffer']) > self::REQUEST_MAX_BYTES) {
            $this->reply($stream, ['ok' => false, 'message' => 'Request too large']);

            return;
        }

        $newline = strpos($this->clients[$id]['buffer'], "\n");

        if ($newline === false) {
            if (feof($stream)) {
                $this->drop_client($id);
            }

            return;
        }

        $line = substr($this->clients[$id]['buffer'], 0, $newline);
        $this->clients[$id]['buffer'] = '';
        $this->handle_request($stream, $line);
    }

    private function drop_client(int $id): void
    {
        if (isset($this->clients[$id])) {
            @fclose($this->clients[$id]['stream']);
            unset($this->clients[$id]);
        }
    }

    /**
     * Replies (once) and closes the connection. Safe if the client already
     * went away.
     *
     * @param resource $stream
     * @param array<string, mixed> $response
     */
    private function reply($stream, array $response): void
    {
        $json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json !== false && is_resource($stream)) {
            $payload = $json . "\n";
            $offset = 0;
            $deadline = microtime(true) + 5.0;
            stream_set_blocking($stream, false);

            while ($offset < strlen($payload) && microtime(true) < $deadline) {
                $written = @fwrite($stream, substr($payload, $offset));

                if ($written === false) {
                    break;
                }

                if ($written === 0) {
                    usleep(2000);
                    continue;
                }

                $offset += $written;
            }
        }

        $this->drop_client((int)$stream);
    }

    // -------------------------------------------------------------- requests

    /** @param resource $stream */
    private function handle_request($stream, string $line): void
    {
        $request = json_decode(trim($line), true);

        if (!is_array($request) || !is_string($request['method'] ?? null)) {
            $this->reply($stream, ['ok' => false, 'message' => 'Invalid request']);

            return;
        }

        $params = is_array($request['params'] ?? null) ? $request['params'] : [];
        /** @var array<string, mixed> $params */

        try {
            $response = $this->dispatch($request['method'], $params, $stream);
        } catch (\Throwable $e) {
            $this->unexpected($e, 'request ' . $request['method']);
            $response = ['ok' => false, 'message' => 'Claude headless manager internal error'];
        }

        // null = the reply is deferred (stop / control requests answer later).
        if ($response !== null) {
            $this->reply($stream, $response);
        }
    }

    /**
     * @param array<string, mixed> $params
     * @param resource $stream
     * @return array<string, mixed>|null
     */
    private function dispatch(string $method, array $params, $stream): ?array
    {
        $session = is_string($params['session'] ?? null) ? $params['session'] : '';

        return match ($method) {
            'sessioneer/health' => $this->health(),
            'sessioneer/list' => $this->list_children(),
            'sessioneer/status' => $this->status($session),
            'sessioneer/spawn' => $this->spawn_request($session, $params),
            'sessioneer/sendInput' => $this->send_input($session, $params),
            'sessioneer/pendingPrompt' => $this->pending_prompt($session),
            'sessioneer/answerPrompt' => $this->answer_prompt($session, $params),
            'sessioneer/interrupt' => $this->control_request($session, ['subtype' => 'interrupt'], $stream),
            'sessioneer/setMode' => $this->set_mode($session, $params, $stream),
            'sessioneer/setModel' => $this->set_model($session, $params, $stream),
            'sessioneer/stop' => $this->stop_request($session, $stream),
            default => ['ok' => false, 'message' => "Unknown method: {$method}"],
        };
    }

    /**
     * The session must be a tracked HEADLESS CLAUDE session: re-validated on
     * every request, never trusted from the caller (repo convention).
     *
     * @return array{ok: true, sidecar: array<string, mixed>}|array{ok: false, message: string}
     */
    private function tracked(string $session): array
    {
        if (preg_match('/^[A-Za-z0-9._-]{1,128}$/', $session) !== 1) {
            return ['ok' => false, 'message' => 'Invalid session name'];
        }

        $sidecar = SidecarStore::read_sidecar($session);

        if ($sidecar === null) {
            return ['ok' => false, 'message' => 'Unknown session'];
        }

        if (($sidecar['agent'] ?? 'claude') !== 'claude' || ($sidecar['runtime'] ?? null) !== RuntimeType::HEADLESS) {
            return ['ok' => false, 'message' => 'Not a headless Claude session'];
        }

        return ['ok' => true, 'sidecar' => $sidecar];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function spawn_request(string $session, array $params): array
    {
        $tracked = $this->tracked($session);

        if (!$tracked['ok']) {
            return $tracked;
        }

        if (isset($this->children[$session])) {
            return ['ok' => true, 'message' => 'Already running', 'pid' => $this->children[$session]->pid];
        }

        $started = $this->start_child($session, $tracked['sidecar'], $params);

        return $started['ok']
            ? ['ok' => true, 'message' => 'Started', 'pid' => $this->children[$session]->pid, 'agent_session_id' => $this->children[$session]->agentSessionId]
            : $started;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function send_input(string $session, array $params): array
    {
        $tracked = $this->tracked($session);

        if (!$tracked['ok']) {
            return $tracked;
        }

        $content = $params['content'] ?? ($params['text'] ?? null);

        if (!(is_string($content) && trim($content) !== '') && !(is_array($content) && $content !== [])) {
            return ['ok' => false, 'message' => 'Rejected: empty message'];
        }

        $child = $this->children[$session] ?? null;

        if ($child === null) {
            // Dormant: bring the process back with --resume (or a fresh pinned
            // id when the conversation has no transcript yet), then deliver.
            $started = $this->start_child($session, $tracked['sidecar'], $params);

            if (!$started['ok']) {
                return $started;
            }

            $child = $this->children[$session];
        }

        if (!$child->write(['type' => 'user', 'message' => ['role' => 'user', 'content' => $content]])) {
            $this->finalize_child($child);

            return ['ok' => false, 'message' => 'Could not write to the Claude process; it has been stopped, send the message again to resume'];
        }

        $child->openTurns++;
        $child->lastActivity = time();

        if ($child->state !== 'blocked') {
            $child->state = 'working';
            $this->persist_status($session, ['status' => 'working', 'blocked' => null, 'last_turn_error' => null]);
        }

        return ['ok' => true, 'message' => 'Message sent', 'queued' => $child->openTurns > 1];
    }

    /** @return array<string, mixed> */
    private function pending_prompt(string $session): array
    {
        $tracked = $this->tracked($session);

        if (!$tracked['ok']) {
            return $tracked;
        }

        return ['ok' => true, 'prompt' => ($this->children[$session] ?? null)?->pending];
    }

    /**
     * Raw answer to the pending can_use_tool request: either `response`
     * ({behavior: allow, updatedInput?, updatedPermissions?} or {behavior:
     * deny, message}) or `error` (a string, replied as a control error).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function answer_prompt(string $session, array $params): array
    {
        $tracked = $this->tracked($session);

        if (!$tracked['ok']) {
            return $tracked;
        }

        $child = $this->children[$session] ?? null;

        if ($child === null || $child->pending === null) {
            return ['ok' => false, 'message' => 'Rejected: no prompt is currently pending on this session'];
        }

        $requestId = $params['request_id'] ?? null;

        if (!is_string($requestId) || $requestId !== $child->pending['request_id']) {
            return ['ok' => false, 'message' => 'Rejected: that prompt is no longer the pending one'];
        }

        if (is_string($params['error'] ?? null) && $params['error'] !== '') {
            $reply = ['type' => 'control_response', 'response' => ['subtype' => 'error', 'request_id' => $requestId, 'error' => $params['error']]];
        } else {
            $response = $params['response'] ?? null;
            $behavior = is_array($response) ? ($response['behavior'] ?? null) : null;

            if (!is_array($response) || !in_array($behavior, ['allow', 'deny'], true)
                || ($behavior === 'deny' && !is_string($response['message'] ?? null))) {
                return ['ok' => false, 'message' => 'Rejected: response must be {behavior: allow|deny} (deny needs a message)'];
            }

            $reply = ['type' => 'control_response', 'response' => ['subtype' => 'success', 'request_id' => $requestId, 'response' => $response]];
        }

        if (!$child->write($reply)) {
            $this->finalize_child($child);

            return ['ok' => false, 'message' => 'Could not write to the Claude process; the prompt is gone'];
        }

        $child->pending = null;
        $child->state = 'working';
        $child->lastActivity = time();
        $this->persist_status($session, ['status' => 'working', 'blocked' => null]);

        return ['ok' => true, 'message' => 'Prompt answered'];
    }

    /**
     * @param array<string, mixed> $params
     * @param resource $stream
     * @return array<string, mixed>|null
     */
    private function set_mode(string $session, array $params, $stream): ?array
    {
        $mode = $params['mode'] ?? null;
        // Accept Sessioneer's own vocabulary (manual/accept edits/plan/auto) or Claude's raw one.
        $real = is_string($mode)
            ? (array_flip(PermissionMode::HOOK_PERMISSION_MODE_MAP)[$mode] ?? (isset(PermissionMode::HOOK_PERMISSION_MODE_MAP[$mode]) ? $mode : null))
            : null;

        if ($real === null) {
            return ['ok' => false, 'message' => 'Rejected: unknown permission mode'];
        }

        return $this->control_request($session, ['subtype' => 'set_permission_mode', 'mode' => $real], $stream);
    }

    /**
     * @param array<string, mixed> $params
     * @param resource $stream
     * @return array<string, mixed>|null
     */
    private function set_model(string $session, array $params, $stream): ?array
    {
        $model = $params['model'] ?? null;

        if (!is_string($model) || preg_match('/^[A-Za-z0-9._\[\]-]{1,100}$/', $model) !== 1) {
            return ['ok' => false, 'message' => 'Rejected: invalid model'];
        }

        return $this->control_request($session, ['subtype' => 'set_model', 'model' => $model], $stream);
    }

    /**
     * Sends a control_request and defers the client's reply until the child's
     * matching control_response arrives (or the child exits / it times out).
     *
     * @param array<string, mixed> $request
     * @param resource $stream
     * @return array<string, mixed>|null
     */
    private function control_request(string $session, array $request, $stream): ?array
    {
        $tracked = $this->tracked($session);

        if (!$tracked['ok']) {
            return $tracked;
        }

        $child = $this->children[$session] ?? null;

        if ($child === null) {
            return ['ok' => false, 'message' => 'Session has no running Claude process'];
        }

        $requestId = self::uuid();

        if (!$child->write(['type' => 'control_request', 'request_id' => $requestId, 'request' => $request])) {
            $this->finalize_child($child);

            return ['ok' => false, 'message' => 'Could not write to the Claude process'];
        }

        $child->controlWaiters[$requestId] = ['client' => $stream, 'deadline' => microtime(true) + self::CONTROL_TIMEOUT_SECONDS];

        return null;
    }

    /**
     * @param resource $stream
     * @return array<string, mixed>|null
     */
    private function stop_request(string $session, $stream): ?array
    {
        $tracked = $this->tracked($session);

        if (!$tracked['ok']) {
            return $tracked;
        }

        $child = $this->children[$session] ?? null;

        if ($child === null) {
            return ['ok' => true, 'message' => 'Already stopped'];
        }

        $this->request_stop($child, $stream);

        return null;
    }

    /** @return array<string, mixed> */
    private function status(string $session): array
    {
        $tracked = $this->tracked($session);

        if (!$tracked['ok']) {
            return $tracked;
        }

        $child = $this->children[$session] ?? null;

        return [
            'ok' => true,
            'process' => $child !== null ? 'running' : 'dormant',
            'state' => $child->state ?? 'idle',
            'pid' => $child?->pid,
            'agent_session_id' => $child->agentSessionId ?? ($tracked['sidecar']['agent_session_id'] ?? null),
            'open_turns' => $child->openTurns ?? 0,
            'stopping' => $child->stopping ?? false,
        ];
    }

    /** @return array<string, mixed> */
    private function list_children(): array
    {
        $now = time();
        $rows = [];

        foreach ($this->children as $name => $child) {
            $rows[] = [
                'session' => $name,
                'pid' => $child->pid,
                'state' => $child->state,
                'agent_session_id' => $child->agentSessionId,
                'idle_seconds' => $now - $child->lastActivity,
                'has_pending_prompt' => $child->pending !== null,
                'stopping' => $child->stopping,
            ];
        }

        return ['ok' => true, 'children' => $rows];
    }

    /** @return array<string, mixed> */
    private function health(): array
    {
        $rss = 0;

        foreach ($this->children as $child) {
            $rss += self::rss_kb($child->pid);
        }

        return [
            'ok' => true,
            'pid' => getmypid(),
            'children' => count($this->children),
            'max_children' => $this->maxChildren,
            'idle_seconds' => $this->idleSeconds,
            'rss_mb' => intdiv($rss, 1024),
            'spawn_blocked' => $this->spawnBlockedReason,
            'last_api_key_source' => $this->lastApiKeySource,
            'claude_version' => $this->lastClaudeVersion,
            'rate_limit' => $this->lastRateLimit,
        ];
    }

    // ------------------------------------------------------------- lifecycle

    /**
     * @param array<string, mixed> $sidecar
     * @param array<string, mixed> $options model / starting_mode / enable_task_tools / fresh
     * @return array{ok: true}|array{ok: false, message: string}
     */
    private function start_child(string $session, array $sidecar, array $options): array
    {
        if ($this->spawnBlockedReason !== null) {
            return ['ok' => false, 'message' => 'Spawning is disabled: ' . $this->spawnBlockedReason];
        }

        if (count($this->children) >= $this->maxChildren) {
            return ['ok' => false, 'message' => "Too many live Claude sessions ({$this->maxChildren}); stop one first"];
        }

        $workdir = is_string($sidecar['workdir'] ?? null) ? $sidecar['workdir'] : '';

        if ($workdir === '' || $workdir[0] !== '/' || !is_dir($workdir)) {
            return ['ok' => false, 'message' => 'Session working directory is missing or not absolute'];
        }

        $profile = is_string($sidecar['profile'] ?? null) && $sidecar['profile'] !== '' ? $sidecar['profile'] : null;
        $existingId = is_string($sidecar['agent_session_id'] ?? null) && $sidecar['agent_session_id'] !== '' ? $sidecar['agent_session_id'] : null;
        $fresh = !empty($options['fresh']);

        // A `-p` resume does not restore the permission mode, so re-apply the
        // last known one (Sessioneer vocabulary, kept in session_status).
        $mode = $options['starting_mode'] ?? (SessionStatusStore::read_status($session)['mode'] ?? null);

        $adapterOptions = [
            'profile' => $profile,
            'model' => $options['model'] ?? null,
            'starting_mode' => is_string($mode) ? $mode : null,
            'enable_task_tools' => !empty($options['enable_task_tools']),
        ];

        if ($existingId !== null && !$fresh) {
            if (self::transcript_exists($existingId, $profile, $workdir)) {
                $adapterOptions['resume'] = $existingId;
            } else {
                $adapterOptions['session_id'] = $existingId;
            }
        }

        $spec = $this->adapter->build_headless_argv($adapterOptions);

        if (($spec['argv'][0] ?? '') === '') {
            return ['ok' => false, 'message' => 'CLAUDE_BIN is not configured'];
        }

        $child = ClaudeHeadlessChild::spawn($session, $spec['assigned_id'], $spec['argv'], $this->child_env($spec['env'] ?? []), $workdir);

        if ($child === null) {
            return ['ok' => false, 'message' => 'Could not start the Claude process'];
        }

        $realMode = is_string($mode) ? (array_flip(PermissionMode::HOOK_PERMISSION_MODE_MAP)[$mode] ?? null) : null;
        $child->requestedMode = $realMode;
        $child->profile = $profile;
        $this->children[$session] = $child;

        if ($spec['assigned_id'] !== null && $spec['assigned_id'] !== $existingId) {
            $this->rebind_session_id($session, $spec['assigned_id']);
        }

        return ['ok' => true];
    }

    /**
     * The child environment: everything inherited MINUS the variables that
     * would override the subscription login or re-enable Claude's hooks.
     *
     * @param array<string, string> $extra
     * @return array<string, string>
     */
    private function child_env(array $extra): array
    {
        $env = [];

        foreach (getenv() as $key => $value) {
            if (!in_array($key, self::STRIPPED_ENV, true)) {
                $env[$key] = $value;
            }
        }

        return $extra + $env;
    }

    /**
     * Begins a graceful stop (interrupt if mid-turn, then close stdin;
     * housekeeping escalates to SIGTERM/SIGKILL past the grace period).
     *
     * @param resource|null $waiter client socket to answer once the child has exited
     */
    private function request_stop(ClaudeHeadlessChild $child, $waiter = null): void
    {
        if ($waiter !== null) {
            $child->stopWaiters[(int)$waiter] = $waiter;
        }

        if ($child->stopping) {
            return;
        }

        $child->stopping = true;
        $child->stopDeadline = microtime(true) + $this->stopGraceSeconds;

        if ($child->state === 'working' || $child->state === 'blocked') {
            $child->write(['type' => 'control_request', 'request_id' => self::uuid(), 'request' => ['subtype' => 'interrupt']]);
        }

        // Closing stdin makes Claude cancel any open prompt and exit.
        $child->close_stdin();
    }

    private function stop_all_children(): void
    {
        foreach ($this->children as $child) {
            $this->request_stop($child);
        }

        $deadline = microtime(true) + $this->stopGraceSeconds + self::KILL_AFTER_TERM_SECONDS + 2.0;

        while ($this->children !== [] && microtime(true) < $deadline) {
            foreach ($this->children as $child) {
                $child->drain_stderr();
                $this->pump_child($child);
            }
            $this->housekeeping();
            usleep(50000);
        }
    }

    private function housekeeping(): void
    {
        $now = microtime(true);

        foreach ($this->pendingStatusWrites as $session => $fields) {
            $this->persist_status($session, $fields);
        }

        foreach ($this->children as $child) {
            if (!$child->is_running()) {
                $this->finalize_child($child);
                continue;
            }

            foreach ($child->controlWaiters as $requestId => $waiter) {
                if ($now > $waiter['deadline']) {
                    unset($child->controlWaiters[$requestId]);
                    $this->reply($waiter['client'], ['ok' => false, 'message' => 'Timed out waiting for Claude to answer']);
                }
            }

            if ($child->stopping) {
                $deadline = $child->stopDeadline ?? $now;

                if (!$child->termSent && $now > $deadline) {
                    $child->signal(SIGTERM);
                    $child->termSent = true;
                } elseif ($child->termSent && !$child->killSent && $now > $deadline + self::KILL_AFTER_TERM_SECONDS) {
                    $child->signal(SIGKILL);
                    $child->killSent = true;
                }

                continue;
            }

            if ($this->idleSeconds > 0 && $child->state === 'idle' && $child->pending === null
                && $child->openTurns === 0 && (time() - $child->lastActivity) > $this->idleSeconds) {
                $this->log("stopping idle session {$child->name}");
                $this->request_stop($child);
            }
        }
    }

    // ---------------------------------------------------------------- events

    private function pump_child(ClaudeHeadlessChild $child): void
    {
        $read = $child->read_events();

        if ($read['bad'] > 0) {
            $this->log("{$child->name}: ignored {$read['bad']} non-JSON stdout line(s)");
        }

        foreach ($read['events'] as $event) {
            try {
                $this->handle_event($child, $event);
            } catch (\Throwable $e) {
                $this->unexpected($e, "event handler for {$child->name}");
            }

            // A guard may have already torn this child down.
            if (!isset($this->children[$child->name]) || $this->children[$child->name] !== $child) {
                return;
            }
        }

        if ($read['eof']) {
            $this->finalize_child($child);
        }
    }

    /** @param array<string, mixed> $event */
    private function handle_event(ClaudeHeadlessChild $child, array $event): void
    {
        // A guardrail already condemned this child; its remaining buffered
        // events (a success `result`, say) must not overwrite the reason.
        if ($child->guarded) {
            return;
        }

        $type = $event['type'] ?? null;
        $child->lastActivity = time();

        if ($type === 'system') {
            match ($event['subtype'] ?? null) {
                'init' => $this->on_init($child, $event),
                'status' => $this->on_mode($child, $event['permissionMode'] ?? null),
                default => null,
            };
        } elseif ($type === 'control_request') {
            $this->on_control_request($child, $event);
        } elseif ($type === 'control_response') {
            $this->on_control_response($child, $event);
        } elseif ($type === 'result') {
            $this->on_result($child, $event);
        } elseif ($type === 'rate_limit_event') {
            $this->on_rate_limit($child, $event);
        }
        // assistant / user / conversation_reset / unknown types: nothing to do
        // (the transcript file is the history; the next `init` carries any new id).
    }

    /** @param array<string, mixed> $event */
    private function on_init(ClaudeHeadlessChild $child, array $event): void
    {
        $version = $event['claude_code_version'] ?? null;
        $this->lastClaudeVersion = is_string($version) ? $version : $this->lastClaudeVersion;

        // Enforce "the subscription login, never an API key" by construction.
        // A missing field (an older/newer CLI) is logged and allowed rather
        // than bricking every spawn.
        if (array_key_exists('apiKeySource', $event)) {
            $source = is_string($event['apiKeySource']) ? $event['apiKeySource'] : 'unknown';
            $this->lastApiKeySource = $source;

            if ($source !== 'none') {
                $reason = "Claude reported apiKeySource='{$source}' (an API key would be billed instead of the subscription); "
                    . 'remove the credential from the environment and restart the manager';
                $this->spawnBlockedReason = $reason;
                $child->guarded = true;
                $this->log("{$child->name}: {$reason}");
                $this->persist_status($child->name, ['status' => 'idle', 'blocked' => null, 'last_turn_error' => $reason]);
                $child->openTurns = 0;
                $this->request_stop($child);
                $child->signal(SIGKILL);

                return;
            }
        } else {
            $this->lastApiKeySource = '(not reported)';
            $this->log("{$child->name}: init did not report apiKeySource; cannot verify the login");
        }

        $sessionId = $event['session_id'] ?? null;

        if (is_string($sessionId) && $sessionId !== '' && $sessionId !== $child->agentSessionId) {
            $this->rebind_session_id($child->name, $sessionId);
            $child->agentSessionId = $sessionId;
        }

        $fields = [];
        $mode = PermissionMode::normalize_hook_permission_mode($event['permissionMode'] ?? null);

        if ($mode !== null) {
            $fields['mode'] = $mode;
        }

        if (is_string($event['model'] ?? null)) {
            $fields['model'] = $event['model'];
        }

        // `--permission-mode auto` was live-observed to be silently ignored
        // (init reported `default`); never trust the flag, compare.
        if (!$child->modeChecked) {
            $child->modeChecked = true;
            $reported = $event['permissionMode'] ?? null;

            if ($child->requestedMode !== null && is_string($reported) && $reported !== $child->requestedMode) {
                $child->modeWarning = "Requested permission mode '{$child->requestedMode}' was not applied (Claude reports '{$reported}')";
                $fields['last_turn_error'] = $child->modeWarning;
            }
        }

        if ($fields !== []) {
            $this->persist_status($child->name, $fields);
        }
    }

    private function on_mode(ClaudeHeadlessChild $child, mixed $rawMode): void
    {
        $mode = PermissionMode::normalize_hook_permission_mode($rawMode);

        if ($mode !== null) {
            // The mode was changed on purpose after spawn, so the spawn-time
            // "not applied" warning no longer describes reality.
            $child->modeWarning = null;
            $this->persist_status($child->name, ['mode' => $mode]);
        }
    }

    /** @param array<string, mixed> $event */
    private function on_control_request(ClaudeHeadlessChild $child, array $event): void
    {
        $requestId = is_string($event['request_id'] ?? null) ? $event['request_id'] : null;
        $request = is_array($event['request'] ?? null) ? $event['request'] : [];

        if ($requestId === null) {
            return;
        }

        if (($request['subtype'] ?? null) !== 'can_use_tool') {
            // An unanswered request stalls the turn (live-verified), so refuse
            // anything we do not implement explicitly.
            $subtype = is_string($request['subtype'] ?? null) ? $request['subtype'] : 'unknown';
            $this->log("{$child->name}: refusing unsupported control request '{$subtype}'");
            $child->write(['type' => 'control_response', 'response' => [
                'subtype' => 'error',
                'request_id' => $requestId,
                'error' => "Sessioneer does not support the '{$subtype}' request",
            ]]);

            return;
        }

        $prompt = [
            'source' => 'claude_headless',
            'request_id' => $requestId,
            'tool_name' => is_string($request['tool_name'] ?? null) ? $request['tool_name'] : '',
            'tool_input' => is_array($request['input'] ?? null) ? $request['input'] : [],
            'tool_use_id' => $request['tool_use_id'] ?? null,
            'description' => $request['description'] ?? null,
            'permission_suggestions' => is_array($request['permission_suggestions'] ?? null) ? $request['permission_suggestions'] : [],
            'requires_user_interaction' => !empty($request['requires_user_interaction']),
        ];

        $child->pending = $prompt;
        $child->state = 'blocked';
        $this->persist_status($child->name, ['status' => 'blocked', 'blocked' => $prompt]);
    }

    /** @param array<string, mixed> $event */
    private function on_control_response(ClaudeHeadlessChild $child, array $event): void
    {
        $response = is_array($event['response'] ?? null) ? $event['response'] : [];
        $requestId = is_string($response['request_id'] ?? null) ? $response['request_id'] : '';
        $waiter = $child->controlWaiters[$requestId] ?? null;

        if ($waiter === null) {
            return;
        }

        unset($child->controlWaiters[$requestId]);

        if (($response['subtype'] ?? null) === 'error') {
            $message = is_string($response['error'] ?? null) ? $response['error'] : 'Claude rejected the request';
            $this->reply($waiter['client'], ['ok' => false, 'message' => $message]);

            return;
        }

        $this->reply($waiter['client'], ['ok' => true, 'response' => $response['response'] ?? null]);
    }

    /** @param array<string, mixed> $event */
    private function on_result(ClaudeHeadlessChild $child, array $event): void
    {
        $child->openTurns = max(0, $child->openTurns - 1);
        $child->pending = null;
        $child->state = $child->openTurns > 0 ? 'working' : 'idle';

        $error = null;

        if (!empty($event['is_error'])) {
            $text = is_string($event['result'] ?? null) ? trim($event['result']) : '';
            $reason = is_string($event['terminal_reason'] ?? null) ? $event['terminal_reason'] : (is_string($event['subtype'] ?? null) ? $event['subtype'] : 'error');
            $error = $text !== '' ? $text : $reason;
        }

        // A successful turn clears the previous turn's error, but not a
        // still-true "your requested mode was ignored" warning.
        $error ??= $child->modeWarning;

        $fields = [
            'status' => $child->state,
            'blocked' => null,
            'last_turn_error' => $error,
        ];

        if (is_array($event['usage'] ?? null)) {
            $fields['token_usage'] = $event['usage'];
        }

        $this->persist_status($child->name, $fields);
    }

    /** @param array<string, mixed> $event */
    private function on_rate_limit(ClaudeHeadlessChild $child, array $event): void
    {
        $info = is_array($event['rate_limit_info'] ?? null) ? $event['rate_limit_info'] : [];
        $windows = is_array($info['unifiedWindows'] ?? null) ? $info['unifiedWindows'] : [];
        $this->lastRateLimit = [
            'status' => $info['status'] ?? null,
            'rate_limit_type' => $info['rateLimitType'] ?? null,
            'is_using_overage' => !empty($info['isUsingOverage']),
            'windows' => $windows,
            'seen_at' => time(),
        ];

        if (!empty($info['isUsingOverage'])) {
            $this->spawnBlockedReason = 'Claude reports pay-as-you-go overage is in use; disable overage or wait for the limit window, then restart the manager';
            $this->log($this->spawnBlockedReason);
        }

        // A headless child renders no status line, so the dashboard's quota
        // footer (fed by the statusLine script for TUI sessions) would freeze
        // without this. Same store, same merge rule, keyed by the child's own account.
        $fiveHour = self::quota_bucket($windows['five_hour'] ?? null);
        $sevenDay = self::quota_bucket($windows['seven_day'] ?? null);

        if ($fiveHour !== null || $sevenDay !== null) {
            QuotaLiveStateWriter::record($child->profile, $fiveHour, $sevenDay);
        }
    }

    /**
     * One `unifiedWindows` entry ({utilization: 0..1 fraction, resetsAt:
     * epoch}, captured v2.1.278) as the {used_percentage, resets_at} bucket
     * QuotaLiveStateWriter merges; null when either field is unusable.
     *
     * @return array{used_percentage: float, resets_at: int}|null
     */
    private static function quota_bucket(mixed $window): ?array
    {
        if (!is_array($window) || !is_numeric($window['utilization'] ?? null) || !is_int($window['resetsAt'] ?? null)) {
            return null;
        }

        return ['used_percentage' => (float)$window['utilization'] * 100, 'resets_at' => $window['resetsAt']];
    }

    /**
     * The child is gone (exited, was killed, or its pipe broke): settle its
     * status, answer everyone still waiting on it, and forget it.
     */
    private function finalize_child(ClaudeHeadlessChild $child): void
    {
        if (!isset($this->children[$child->name]) || $this->children[$child->name] !== $child) {
            return;
        }

        // Read whatever the child managed to say before it died.
        $child->drain_stderr();
        $tail = $child->read_events();

        foreach ($tail['events'] as $event) {
            try {
                $this->handle_event($child, $event);
            } catch (\Throwable $e) {
                $this->unexpected($e, "final events of {$child->name}");
            }
        }

        // The kernel closes a dying process's pipes before it can be reaped, so
        // an EOF is often seen a moment before the exit status is available;
        // killing it in that window would replace the real exit code.
        if ($child->is_running() && !$child->wait_for_exit(self::EXIT_REAP_WAIT_SECONDS)) {
            // Pipe broke but the process lingers (or a write failed): make sure it is gone.
            $child->close_stdin();
            $child->signal(SIGKILL);
        }

        $interrupted = $child->openTurns > 0 || $child->pending !== null;
        $fields = ['status' => 'idle', 'blocked' => null];

        if (!$child->stopping && $interrupted) {
            $code = $child->exit_code();
            $stderr = $child->stderr_tail();
            $fields['last_turn_error'] = 'Claude process exited unexpectedly'
                . ($code !== null ? " (code {$code})" : '')
                . ($stderr !== '' ? ': ' . $stderr : '');
        }

        try {
            $this->persist_status($child->name, $fields);
        } catch (\Throwable $e) {
            $this->unexpected($e, "status write for {$child->name}");
        }

        foreach ($child->stopWaiters as $client) {
            $this->reply($client, ['ok' => true, 'message' => 'Stopped']);
        }

        foreach ($child->controlWaiters as $waiter) {
            $this->reply($waiter['client'], ['ok' => false, 'message' => 'Claude process exited before answering']);
        }

        unset($this->children[$child->name]);
        $child->dispose();
    }

    // --------------------------------------------------------------- helpers

    /** Re-points the sidecar at Claude's CURRENT session id (it rotates on /clear, /fork, first spawn). */
    private function rebind_session_id(string $session, string $agentSessionId): void
    {
        $sidecar = SidecarStore::read_sidecar($session);

        if ($sidecar === null) {
            return;
        }

        $sidecar['agent_session_id'] = $agentSessionId;
        SidecarStore::write_sidecar($session, $sidecar);
    }

    /**
     * Whether Claude already has a transcript for this id, i.e. whether
     * `--resume` can work (a session that never received a message has none
     * and needs a pinned --session-id instead). Names longer than Claude's
     * 200-char project-dir limit are hashed, so assume "exists" there.
     */
    private static function transcript_exists(string $agentSessionId, ?string $profile, string $workdir): bool
    {
        $base = Config::claude_config_dir($profile) . '/projects/';

        foreach (array_unique([$workdir, (string)realpath($workdir)]) as $dir) {
            if ($dir === '') {
                continue;
            }

            $project = preg_replace('/[^A-Za-z0-9]/', '-', $dir) ?? '';

            if (strlen($project) > 200) {
                return true;
            }

            if (is_file($base . $project . '/' . $agentSessionId . '.jsonl')) {
                return true;
            }
        }

        return false;
    }

    /** Resident memory (KB) of a process and all its descendants. */
    private static function rss_kb(int $pid): int
    {
        $children = [];

        foreach (@scandir('/proc') ?: [] as $entry) {
            if (!ctype_digit($entry)) {
                continue;
            }

            $stat = @file_get_contents("/proc/{$entry}/stat");

            if ($stat === false) {
                continue;
            }

            $tail = strrchr($stat, ')');
            $fields = $tail !== false ? explode(' ', trim(substr($tail, 1))) : [];

            if (isset($fields[1])) {
                $children[(int)$fields[1]][] = (int)$entry;
            }
        }

        $total = 0;
        $stack = [$pid];

        while ($stack !== []) {
            $current = array_pop($stack);

            foreach (@file("/proc/{$current}/status") ?: [] as $line) {
                if (str_starts_with($line, 'VmRSS:')) {
                    $total += (int)trim(substr($line, 6));
                }
            }

            array_push($stack, ...($children[$current] ?? []));
        }

        return $total;
    }

    private static function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        $hex = bin2hex($data);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * Errors policy: an unexpected exception is always logged; in debug mode
     * it is re-thrown so it surfaces with the runtime's normal diagnostics,
     * otherwise it is handled so one bad session cannot take down every
     * other live child.
     */
    /**
     * SessionStatusStore::update_status(), made resilient: a write that
     * throws (SQLITE_BUSY under WAL contention is the leading suspect, see
     * $pendingStatusWrites' own docblock) is logged and queued for
     * housekeeping() to retry every tick, instead of the session's real state
     * silently never reaching the store that every reader (dashboard list,
     * push, session_detail) depends on.
     *
     * @param array<string, mixed> $fields
     */
    private function persist_status(string $session, array $fields): void
    {
        try {
            SessionStatusStore::update_status($session, $fields);
            unset($this->pendingStatusWrites[$session]);
        } catch (\Throwable $e) {
            $this->unexpected($e, "persisting status for {$session}");
            $this->pendingStatusWrites[$session] = $fields;
        }
    }

    private function unexpected(\Throwable $e, string $context): void
    {
        $this->log("unexpected error in {$context}: " . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());

        if (Config::debug()) {
            throw $e;
        }
    }

    private function log(string $message): void
    {
        fwrite(STDERR, self::LOG_PREFIX . $message . "\n");
    }
}
