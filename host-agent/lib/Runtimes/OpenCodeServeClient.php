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
 * Endpoint-generation note (verified live against opencode 1.18.21's
 * /doc, Phase 0 of the headless-runtime plan): create uses the richer
 * `/api/session` (its `location.directory` is what makes arbitrary-workdir
 * spawn work); reads/status/todo use the reliable `/session` and global
 * `/question` surface; the v2 `/api/session/:id/{history,question,
 * permission}` GETs 500 on a freshly-created session in a not-yet-registered
 * directory (it lands in the `global` project until realized), so they are
 * not used for reads here. See docs/headless-runtime-plan.md.
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
     * session; verified live 2026-08-26 that the returned id equals the one
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
        [$status, $data, $error] = $this->request('GET', '/session/' . rawurlencode($sessionId));

        if ($status < 200 || $status >= 300 || $data === null) {
            return ['ok' => false, 'message' => "Get session failed (HTTP $status): $error"];
        }

        return ['ok' => true, 'session' => $data];
    }

    /**
     * @return array{ok:bool, message?:string}
     */
    public function delete_session(string $sessionId): array
    {
        [$status, $data, $error] = $this->request('DELETE', '/session/' . rawurlencode($sessionId));

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
     * The serve's default model, from GET /config/providers' `default` map
     * (per provider). A freshly created session has model=null and a v2
     * /session/{id}/prompt only ADMITS the prompt without running a turn,
     * so sending needs an explicit model - this is the fallback when the
     * session doesn't carry one. Returns {providerID, modelID} (empty when no
     * provider default is configured).
     *
     * @return array{providerID:string, modelID:string}
     */
    public function default_model(): array
    {
        [$status, $data, $error] = $this->request('GET', '/config/providers');

        if ($data === null) {
            return ['providerID' => '', 'modelID' => ''];
        }

        $default = is_array($data['default'] ?? null) ? $data['default'] : [];

        foreach ($default as $providerID => $modelID) {
            if (is_string($providerID) && is_string($modelID) && $modelID !== '') {
                return ['providerID' => $providerID, 'modelID' => $modelID];
            }
        }

        foreach (($data['providers'] ?? []) as $p) {
            if (is_array($p) && is_string($p['id'] ?? null) && is_array($p['models'] ?? null)) {
                foreach ($p['models'] as $mid => $m) {
                    if (is_string($mid)) {
                        return ['providerID' => $p['id'], 'modelID' => $mid];
                    }
                }
            }
        }

        return ['providerID' => '', 'modelID' => ''];
    }

    /**
     * The serve's available models, flattened from GET /config/providers -
     * one entry per model: {providerID, id, name, family}. The sync caches
     * this (GlobalStateStore) so the session page's model dropdown can be
     * populated without a serve round-trip on every render.
     *
     * @return array<int, array{providerID:string, id:string, name:string, family:?string}>
     */
    public function available_models(): array
    {
        [$status, $data, $error] = $this->request('GET', '/config/providers');

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

        foreach (($data['providers'] ?? []) as $provider) {
            if (!is_array($provider) || !is_string($provider['id'] ?? null)) {
                continue;
            }

            $pid = $provider['id'];
            $prio = $priority[$pid] ?? 99;

            foreach (is_array($provider['models'] ?? null) ? $provider['models'] : [] as $mid => $m) {
                if (!is_string($mid)) {
                    continue;
                }

                if (isset($byId[$mid]) && $byId[$mid]['_priority'] <= $prio) {
                    continue;
                }

                $byId[$mid] = [
                    'providerID' => $pid,
                    'id' => $mid,
                    'name' => is_string($m['name'] ?? null) ? $m['name'] : $mid,
                    'family' => is_string($m['family'] ?? null) ? $m['family'] : null,
                    '_priority' => $prio,
                ];
            }
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
     * The model a session runs under, from its own record (GET
     * /session/{id}), falling back to the serve's default model when the
     * session was created bare (model=null) - which is the common case for a
     * Sessioneer-created headless session. The v2 /api/session/{id}/prompt endpoint
     * only admits a prompt without running a turn, so sending always needs an
     * explicit model to hand the v1 /session/{id}/message call.
     *
     * @return array{providerID:string, modelID:string}
     */
    private function resolve_model(string $sessionId): array
    {
        $detail = $this->get_session($sessionId);

        if (($detail['ok'] === true) && is_array($detail['session']['model'] ?? null)) {
            $m = $detail['session']['model'];
            $pid = is_string($m['providerID'] ?? null) ? $m['providerID'] : null;
            $mid = is_string($m['id'] ?? null) ? $m['id'] : (is_string($m['modelID'] ?? null) ? $m['modelID'] : null);

            if ($pid !== null && $mid !== null && $mid !== '') {
                return ['providerID' => $pid, 'modelID' => $mid];
            }
        }

        return $this->default_model();
    }

    /**
     * Sends a free-text message asynchronously via POST /session/{id}/prompt_async
     * - the app-style `parts` + `model` body that actually runs the agent loop,
     * but returns 204 immediately (no waiting on the full turn), so Sessioneer's send
     * doesn't time out on a long turn ("curl 28"). The reply arrives in the
     * transcript via the session page's poll. The model is the session's own
     * if it has one, else the serve default.
     *
     * @param array<int, string> $attachmentPaths
     * @param array{providerID:string, modelID:string}|null $model
     * @return array{ok:bool, message?:string}
     */
    public function send_message(string $sessionId, string $text, array $attachmentPaths = [], ?array $model = null): array
    {
        $attachmentLines = array_map(static fn(string $path): string => '[Attached: ' . $path . ']', $attachmentPaths);
        $text = $attachmentLines === [] ? $text : trim(rtrim($text) . "\n" . implode("\n", $attachmentLines));

        $model = $model ?? $this->resolve_model($sessionId);

        if ($model['providerID'] === '' || $model['modelID'] === '') {
            return ['ok' => false, 'message' => 'No opencode model is available to send with'];
        }

        [$status, $data, $error] = $this->request('POST', '/session/' . rawurlencode($sessionId) . '/prompt_async', [
            'parts' => [['type' => 'text', 'text' => $text]],
            'model' => ['providerID' => $model['providerID'], 'modelID' => $model['modelID']],
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
     * Live status for one session, from the session record's own
     * permission/token/status summary where available. This is a best-effort
     * read; the authoritative working/idle/blocked signal for headless
     * opencode currently comes from GET /question (questions) and the serve
     * /permission surface (permission) - see HeadlessRuntime::status().
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
     * The pending prompt (question or permission) for a session, normalized
     * to the shape OpenCodeQuestionService::to_prompt() produces for
     * questions, or a permission-shaped prompt for a pending permission.
     *
     * @return array<int, array<string, mixed>> the raw pending permission requests
     */
    public function pending_permissions(): array
    {
        [$status, $data, $error] = $this->request('GET', '/permission');

        if ($data === null) {
            return [];
        }

        $items = is_array($data['data'] ?? null) ? $data['data'] : $data;

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * Every pending question request across all sessions, from the global
     * GET /question (the batched source the blocked-prompt sync uses).
     *
     * @return array<int, array<string, mixed>> the raw pending question requests
     */
    public function pending_questions(): array
    {
        [$status, $data, $error] = $this->request('GET', '/question');

        if ($data === null) {
            return [];
        }

        $items = is_array($data['data'] ?? null) ? $data['data'] : $data;

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
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
     * Answers a pending question by label, via the global /question reply
     * endpoint OpenCodeQuestionService already uses (proven live).
     *
     * @param array<int, array<int, string>|string> $labels OpenCode option labels or custom text
     * @return array{ok:bool, message?:string}
     */
    public function answer_question(string $sessionId, array $labels, ?string $requestId = null): array
    {
        return \HostAgent\Services\OpenCodeQuestionService::answer($sessionId, $labels, $requestId);
    }

    /**
     * Answers a pending permission request via the v1 reply endpoint -
     * POST /session/{id}/permissions/{requestID} with
     * `{ response: once | always | reject }`.
     *
     * The v2 equivalent (POST /api/session/{id}/permission/{requestID}/reply)
     * returns PermissionNotFoundError on 1.18.21; the v1 path works.
     *
     * @param string $reply one of 'once' | 'always' | 'reject'
     * @return array{ok:bool, message?:string}
     */
    public function answer_permission(string $sessionId, string $requestId, string $reply): array
    {
        [$status, $data, $error] = $this->request(
            'POST',
            '/session/' . rawurlencode($sessionId) . '/permissions/' . rawurlencode($requestId),
            ['response' => $reply]
        );

        if ($status >= 200 && $status < 300) {
            return ['ok' => true, 'message' => 'Permission answered'];
        }

        return ['ok' => false, 'message' => "Answer permission failed (HTTP $status): " . trim($error)];
    }

    /**
     * Live status for EVERY session on the serve, in one call (GET
     * /session/status). This is the batched source the headless status sync
     * uses - one HTTP request per tick updates all headless sessions, so the
     * dashboard's per-poll list read never has to hit serve.
     *
     * Maps opencode's SessionStatus tagged union (idle / busy / retry) to
     * Sessioneer's own 'idle' | 'working' (retry is "trying to do work", so treat it
     * as working). Blocked-prompt state is a separate surface (GET /question
     * / GET /permission) and is not included here.
     *
     * @return array{ok:bool, statuses?:array<string, string>, message?:string}
     */
    public function status_map(): array
    {
        [$status, $data, $error] = $this->request('GET', '/session/status');

        if ($data === null) {
            return ['ok' => false, 'message' => "Session status failed (HTTP $status): $error"];
        }

        $statuses = [];

        foreach (is_array($data) ? $data : [] as $id => $state) {
            if (!is_string($id) || !is_array($state)) {
                continue;
            }

            $type = is_string($state['type'] ?? null) ? $state['type'] : null;
            $statuses[$id] = $type === 'idle' ? 'idle' : 'working';
        }

        return ['ok' => true, 'statuses' => $statuses];
    }

    /**
     * The todo list for a session (GET /session/:id/todo - the proven v1
     * surface).
     *
     * @return array{ok:bool, todos?:array<string,mixed>, message?:string}
     */
    public function get_todo(string $sessionId): array
    {
        [$status, $data, $error] = $this->request('GET', '/session/' . rawurlencode($sessionId) . '/todo');

        if ($data === null) {
            return ['ok' => false, 'message' => "Todo read failed (HTTP $status): $error"];
        }

        return ['ok' => true, 'todos' => $data];
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
