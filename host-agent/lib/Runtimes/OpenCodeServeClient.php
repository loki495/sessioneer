<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Services\Config;
use HostAgent\Services\ProcessRunner;
use HostAgent\Stores\SessionStatusStore;

/**
 * Thin HTTP client for the `opencode serve` API - the host-native
 * counterpart to OpenCodeQuestionService's curl calls, but wider: one
 * class owning the create/list/detail/kill/status/drive endpoints a
 * headless opencode session needs.
 *
 * Endpoint-generation note: create and list use the v2 `/api/session`
 * (`location.directory` is what makes arbitrary-workdir spawn work). The
 * server is v2-only now and requires HTTP Basic auth, so every call carries
 * `Config::opencode_curl_auth_args()`. Detail/status/todo/prompt/permission
 * replies still target v1 paths and are the remaining migration work.
 *
 * Every call goes through ProcessRunner (command as a string[] - no shell
 * string, this project's standard no-metacharacter-injection rule) and
 * returns a shape with an `ok` bool; transport/parse failures are handled,
 * not thrown.
 */
class OpenCodeServeClient
{
    /**
     * @return array{ok:bool, id?:string, session?:array<string,mixed>, message?:string, http?:int}
     */
    public function create_session(string $directory, ?string $title = null): array
    {
        $body = ['location' => ['directory' => $directory]];

        if ($title !== null && $title !== '') {
            $body['title'] = $title;
        }

        [$status, $data, $error] = $this->request('POST', '/api/session', $body);

        if ($status < 200 || $status >= 300 || $data === null) {
            return ['ok' => false, 'message' => "Create session failed (HTTP $status): $error", 'http' => $status];
        }

        $session = is_array($data['data'] ?? null) ? $data['data'] : $data;
        $id = is_string($session['id'] ?? null) ? $session['id'] : null;

        if ($id === null) {
            return ['ok' => false, 'message' => "Create session failed (HTTP $status): response contained no session id", 'http' => $status];
        }

        return [
            'ok' => true,
            'id' => $id,
            'session' => $session,
        ];
    }

    /**
     * Resumes an existing opencode session as a serve-hosted (headless)
     * session - POST /api/session with the session's own `id` (+ location),
     * so the SAME conversation id is continued rather than a fork created
     * (that v2 create body's `id` is what makes this a resume, not a new
     * session; the returned id equals the one
     * passed in). The reconcile then adopts it headless.
     *
     * @return array{ok:bool, id?:string, session?:array<string,mixed>, message?:string, http?:int}
     */
    public function resume_session(string $sessionId, string $directory): array
    {
        [$status, $data, $error] = $this->request('POST', '/api/session', [
            'id' => $sessionId,
            'location' => ['directory' => $directory],
        ]);

        if ($data === null) {
            return ['ok' => false, 'message' => "Resume session failed (HTTP $status): $error", 'http' => $status];
        }

        $session = is_array($data['data'] ?? null) ? $data['data'] : $data;

        return [
            'ok' => true,
            'id' => is_string($session['id'] ?? null) ? $session['id'] : $sessionId,
            'session' => $session,
        ];
    }

    /**
     * Lists every session the serve recognizes. Uses the v2 /api/session
     * endpoint, NOT v1 /session - v1 only returns the small "currently live"
     * set (observed returning [0] while /api/session had 50 sessions; a
     * recently-resumed session was absent from v1, which is what made the
     * headless sync prune the just-resumed session's sidecar). Callers are
     * responsible for the active-vs-dormant filter (see sessioneer_headless_sync).
     *
     * @return array{ok:bool, sessions?:array<int, array<string,mixed>>, message?:string}
     */
    public function list_sessions(): array
    {
        [$status, $data, $error] = $this->request('GET', '/api/session');

        if ($status < 200 || $status >= 300 || $data === null) {
            return ['ok' => false, 'message' => "List sessions failed (HTTP $status): $error"];
        }

        $items = is_array($data['data'] ?? null) ? $data['data'] : (is_array($data) ? $data : []);

        return ['ok' => true, 'sessions' => array_values($items)];
    }

    /**
     * @return array{ok:bool, session?:array<string,mixed>, message?:string}
     */
    public function get_session(string $sessionId): array
    {
        [$status, $data, $error] = $this->request('GET', '/api/session/' . rawurlencode($sessionId));

        if ($status < 200 || $status >= 300 || $data === null) {
            return ['ok' => false, 'message' => "Get session failed (HTTP $status): $error"];
        }

        return ['ok' => true, 'session' => is_array($data['data'] ?? null) ? $data['data'] : $data];
    }

    /**
     * @return array{ok:bool, message?:string}
     */
    public function delete_session(string $sessionId): array
    {
        [$status, $data, $error] = $this->request('DELETE', '/api/session/' . rawurlencode($sessionId));

        if ($data === null) {
            // Some DELETE endpoints return an empty 200 body; a 20x with no
            // body is still a success for the contract, only transport errors
            // (curl failure) are real failures here.
            return $status >= 200 && $status < 300
                ? ['ok' => true, 'message' => 'Session deleted']
                : ['ok' => false, 'message' => "Delete session failed (HTTP $status): $error"];
        }

        return $data === false
            ? ['ok' => false, 'message' => 'Delete session rejected by the server (session may already be gone)']
            : ['ok' => true, 'message' => 'Session deleted'];
    }

    /**
     * The serve's default model, from GET /api/model/default. Returns
     * {providerID, modelID} (empty when the server reports none).
     *
     * @return array{providerID:string, modelID:string}
     */
    public function default_model(): array
    {
        [$status, $data, $error] = $this->request('GET', '/api/model/default');

        $model = is_array($data['data'] ?? null) ? $data['data'] : null;
        if ($model === null) {
            return ['providerID' => '', 'modelID' => ''];
        }

        return [
            'providerID' => is_string($model['providerID'] ?? null) ? $model['providerID'] : '',
            'modelID' => is_string($model['modelID'] ?? null) ? $model['modelID'] : (is_string($model['id'] ?? null) ? $model['id'] : ''),
        ];
    }

    /**
     * The serve's available models, flattened from GET /api/model - one entry
     * per model: {providerID, id, name, family}. The sync caches this
     * (GlobalStateStore) so the session page's model dropdown can be
     * populated without a serve round-trip on every render.
     *
     * @return array<int, array{providerID:string, id:string, name:string, family:?string}>
     */
    public function available_models(): array
    {
        [$status, $data, $error] = $this->request('GET', '/api/model');

        if ($data === null) {
            return [];
        }

        // Provider priority: prefer opencode-go (always works via proxy)
        // over openai (requires user's own key, often broken) over
        // everything else.  A model ID that appears under a higher-priority
        // provider wins; the lower-priority copy is dropped so the dropdown
        // never offers a broken variant.
        $priority = ['opencode-go' => 0, 'opencode' => 1, 'openai' => 2];

        /** @var array<string, array{providerID:string, id:string, name:string, family:?string, _priority:int}> */
        $byId = [];

        foreach (is_array($data['data'] ?? null) ? $data['data'] : [] as $model) {
            if (!is_array($model) || !is_string($model['providerID'] ?? null)) {
                continue;
            }

            $pid = $model['providerID'];
            $mid = is_string($model['modelID'] ?? null) ? $model['modelID'] : (is_string($model['id'] ?? null) ? $model['id'] : null);
            if ($mid === null || $mid === '') {
                continue;
            }

            $prio = $priority[$pid] ?? 99;

            if (isset($byId[$mid]) && $byId[$mid]['_priority'] <= $prio) {
                continue;
            }

            $byId[$mid] = [
                'providerID' => $pid,
                'id' => $mid,
                'name' => is_string($model['name'] ?? null) ? $model['name'] : $mid,
                'family' => is_string($model['family'] ?? null) ? $model['family'] : null,
                '_priority' => $prio,
            ];
        }

        $out = [];
        foreach ($byId as $entry) {
            unset($entry['_priority']);
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Switches a session's model - POST /api/session/{id}/model with a
     * ModelRef. Session-scoped (unlike some pickers), per the v2 endpoint.
     *
     * @return array{ok:bool, message?:string}
     */
    public function set_model(string $sessionId, string $providerID, string $modelId): array
    {
        [$status, $data, $error] = $this->request('POST', '/api/session/' . rawurlencode($sessionId) . '/model', [
            'model' => ['id' => $modelId, 'providerID' => $providerID],
        ]);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'message' => 'Model updated'];
        }

        return ['ok' => false, 'message' => "Set model failed (HTTP $status): " . trim($error)];
    }

    /**
     * Sends a free-text message via POST /api/session/{id}/prompt, which
     * returns as soon as the prompt is admitted (a `Session.Inbox.User`
     * record) instead of waiting for the whole turn, so Sessioneer's send
     * doesn't time out on a long turn ("curl 28"). The reply arrives in the
     * transcript via the session page's poll. The model is the session's own
     * (set with set_model); the prompt body has no model field in v2.
     *
     * @param array<int, string> $attachmentPaths
     * @return array{ok:bool, message?:string}
     */
    public function send_message(string $sessionId, string $text, array $attachmentPaths = []): array
    {
        $attachmentLines = array_map(static fn(string $path): string => '[Attached: ' . $path . ']', $attachmentPaths);
        $text = $attachmentLines === [] ? $text : trim(rtrim($text) . "\n" . implode("\n", $attachmentLines));

        [$status, $data, $error] = $this->request('POST', '/api/session/' . rawurlencode($sessionId) . '/prompt', [
            'text' => $text,
        ]);

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'message' => 'Message sent'];
        }

        return ['ok' => false, 'message' => "Send message failed (HTTP $status): " . trim($error)];
    }

    /**
     * @return array{ok:bool, message?:string}
     */
    public function interrupt(string $sessionId): array
    {
        [$status, $data, $error] = $this->request('POST', '/api/session/' . rawurlencode($sessionId) . '/interrupt');

        if ($data === null) {
            return ['ok' => false, 'message' => "Interrupt failed (HTTP $status): $error"];
        }

        return ['ok' => true, 'message' => 'Interrupt sent'];
    }

    /**
     * Live status for one session. The authoritative working/idle signal comes
     * from GET /api/session/active (status_map); blocked state comes from the
     * per-session forms (questions) and /api/permission/request (permissions)
     * - see HeadlessRuntime::status().
     *
     * @return array{ok:bool, status?:string, blocked?:?array<string,mixed>, message?:string}
     */
    public function get_status(string $sessionId): array
    {
        $detail = $this->get_session($sessionId);

        if ($detail['ok'] !== true) {
            return ['ok' => false, 'message' => $detail['message'] ?? 'Status read failed'];
        }

        // No reliable "is it working right now" field on a session record;
        // presence of a pending question/permission is the blocked signal.
        $blocked = $this->pending_blocked($sessionId);

        return [
            'ok' => true,
            'status' => $blocked !== null ? 'blocked' : 'idle',
            'blocked' => $blocked,
        ];
    }

    /**
     * The pending permission requests for the given session directories, from
     * GET /api/permission/request (`{location, data: [Permission.Request]}`).
     * Each entry is `{id, sessionID, action, resources[], save[], metadata,
     * source, message}`.
     *
     * v2 scopes this list by `location.directory`: an unscoped read only
     * reports the server's own cwd, so a request raised in a project directory
     * is invisible. Query once per directory and merge by request id. With no
     * directories it falls back to the single unscoped read.
     *
     * @param array<int, string> $directories session workdirs to include
     * @return array<int, array<string, mixed>> the raw pending permission requests
     */
    public function pending_permissions(array $directories = []): array
    {
        $directories = array_values(array_unique(array_filter(
            $directories,
            static fn($directory): bool => $directory !== ''
        )));

        $paths = $directories === []
            ? ['/api/permission/request']
            : array_map(
                static fn(string $directory): string => '/api/permission/request?location%5Bdirectory%5D=' . rawurlencode($directory),
                $directories
            );

        $pending = [];

        foreach ($paths as $path) {
            [$status, $data, $error] = $this->request('GET', $path);

            if ($data === null) {
                continue;
            }

            $items = is_array($data['data'] ?? null) ? $data['data'] : $data;

            foreach (is_array($items) ? $items : [] as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $id = is_string($item['id'] ?? null) ? $item['id'] : null;

                if ($id !== null && isset($pending[$id])) {
                    continue;
                }

                $pending[$id ?? 'request_' . count($pending)] = $item;
            }
        }

        return array_values($pending);
    }

    /**
     * Every pending question form across the given sessions. v2 has no global
     * question list, so this asks each session for its own forms and keeps
     * only `metadata.kind == question` ones, normalized to the question shape
     * the status store speaks.
     *
     * @param array<int, string> $sessionIds the sessions to check
     * @return array<int, array<string, mixed>> one normalized pending question per session
     */
    public function pending_questions(array $sessionIds): array
    {
        $pending = [];

        foreach ($sessionIds as $sessionId) {
            $question = \HostAgent\Services\OpenCodeQuestionService::pending_question($sessionId);
            if ($question === null) {
                continue;
            }

            $pending[] = [
                'id' => $question['requestID'],
                'sessionID' => $sessionId,
                'questions' => $question['questions'],
            ];
        }

        return $pending;
    }

    /**
     * The current blocked prompt for a session, already normalized to Sessioneer's
     * canonical shape (tool_name 'permission'|'question' with a request_id
     * so answer_prompt can route to the right reply endpoint). Reads the
     * shape the sync wrote into SessionStatusStore; falls back to a live
     * question read if the sync hasn't caught it yet.
     *
     * @return array<string, mixed>|null
     */
    public function pending_blocked(string $sessionId): ?array
    {
        $status = SessionStatusStore::read_status($sessionId);

        if (is_array($status['blocked'] ?? null) && (is_string($status['blocked']['question'] ?? null) || is_string($status['blocked']['tool_name'] ?? null))) {
            return $status['blocked'];
        }

        // Live fallback for a question the sync hasn't reflected yet.
        $question = \HostAgent\Services\OpenCodeQuestionService::pending_question($sessionId);

        if ($question !== null) {
            $prompt = \HostAgent\Services\OpenCodeQuestionService::to_prompt($question);
            $prompt['request_id'] = $question['requestID'];
            $prompt['tool_input'] = ['questions' => $question['questions']];

            return $prompt;
        }

        return null;
    }

    /**
     * Answers a pending question by label through OpenCodeQuestionService,
     * which speaks the v2 form reply API.
     *
     * @param array<int, array<int, string>|string> $labels OpenCode option labels or custom text
     * @return array{ok:bool, message?:string}
     */
    public function answer_question(string $sessionId, array $labels, ?string $requestId = null): array
    {
        return \HostAgent\Services\OpenCodeQuestionService::answer($sessionId, $labels, $requestId);
    }

    /**
     * Answers a pending permission request - POST
     * /api/session/{id}/permission/{requestID}/reply with
     * `{decision: once | always | reject}`.
     *
     * @param string $reply one of 'once' | 'always' | 'reject'
     * @return array{ok:bool, message?:string}
     */
    public function answer_permission(string $sessionId, string $requestId, string $reply): array
    {
        [$status, $data, $error] = $this->request(
            'POST',
            '/api/session/' . rawurlencode($sessionId) . '/permission/' . rawurlencode($requestId) . '/reply',
            ['decision' => $reply]
        );

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'message' => 'Permission answered'];
        }

        return ['ok' => false, 'message' => "Answer permission failed (HTTP $status): " . trim($error)];
    }

    /**
     * Live status for EVERY session on the serve, in one call (GET
     * /api/session/active). This is the batched source the headless status
     * sync uses - one HTTP request per tick updates all headless sessions, so
     * the dashboard's per-poll list read never has to hit serve.
     *
     * v2 reports only sessions that are doing work, as `{ses_x: {type:
     * "running"}}`; anything absent is idle. Blocked-prompt state is a
     * separate surface (/api/permission/request and the per-session forms)
     * and is not included here.
     *
     * @return array{ok:bool, statuses?:array<string, string>, message?:string}
     */
    public function status_map(): array
    {
        [$status, $data, $error] = $this->request('GET', '/api/session/active');

        if ($data === null) {
            return ['ok' => false, 'message' => "Session status failed (HTTP $status): $error"];
        }

        $statuses = [];

        foreach (is_array($data['data'] ?? null) ? $data['data'] : [] as $id => $state) {
            if (!is_string($id)) {
                continue;
            }

            $type = is_array($state) && is_string($state['type'] ?? null) ? $state['type'] : null;
            $statuses[$id] = $type === 'idle' ? 'idle' : 'working';
        }

        return ['ok' => true, 'statuses' => $statuses];
    }

    /**
     * OpenCode v2 has no todo endpoint (v1's GET /session/:id/todo is gone and
     * nothing in the v2 schema replaces it), so there is no source for the
     * session page's todo list. Kept as an explicit unsupported result so the
     * caller renders no todos instead of failing.
     *
     * @return array{ok:bool, todos?:array<string,mixed>, message?:string}
     */
    public function get_todo(string $sessionId): array
    {
        return ['ok' => false, 'message' => 'OpenCode v2 does not expose a todo list'];
    }

    /**
     * One HTTP request via curl. Returns a decoded JSON body (or null for a
     * non-JSON/empty body), the HTTP status, and a trailing error string.
     *
     * @param array<string, mixed>|null $body
     * @return array{0:int, 1:mixed, 2:string} [status, decodedBody, error]
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $cmd = [
            'curl', '--silent', '--show-error', '--max-time', '8',
            '--request', $method,
            '--write-out', "\n%{http_code}",
        ];
        foreach (Config::opencode_curl_auth_args() as $arg) $cmd[] = $arg;

        $bodyFile = null;

        if ($body !== null) {
            $payload = json_encode($body);
            $tmp = tempnam(sys_get_temp_dir(), 'sessioneer-oes-');
            $bodyFile = $tmp !== false ? $tmp : '/dev/null';
            file_put_contents($bodyFile, (string)$payload);
            $cmd[] = '--header';
            $cmd[] = 'Content-Type: application/json';
            $cmd[] = '--data';
            $cmd[] = '@' . $bodyFile;
        }

        $cmd[] = Config::opencode_server_url() . $path;

        $result = ProcessRunner::run_process($cmd);

        if ($bodyFile !== null) {
            @unlink($bodyFile);
        }

        if ($result['exit'] !== 0) {
            return [0, null, trim($result['stderr'])];
        }

        // --write-out appends a newline + http_code after the body; split
        // reliably off the last line.
        $parts = explode("\n", trim($result['stdout']));
        $last = (string)array_pop($parts);
        $status = (int)$last;
        $raw = implode("\n", $parts);

        if ($raw === '') {
            return [$status, null, ''];
        }

        $decoded = json_decode($raw, true);

        return [$status, $decoded, ''];
    }
}
