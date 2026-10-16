<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Runtimes\ClaudeHeadlessManagerClient;
use HostAgent\Runtimes\ClaudeHeadlessRuntime;
use HostAgent\Runtimes\CodexBridgeClient;
use HostAgent\Runtimes\CodexHeadlessRuntime;
use HostAgent\Runtimes\RuntimeProvider;
use HostAgent\Stores\QuickPromptStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SessionStatusStore;

/** Restricted facade over existing managed runtimes; no subprocess or shell execution here. */
final class QuickPromptService
{
    /** @var \Closure(string):RuntimeProvider */
    private \Closure $runtime;
    private ClaudeHeadlessManagerClient $manager;
    private QuickPromptRouter $router;

    /** @param callable(string):RuntimeProvider|null $runtime */
    public function __construct(?callable $runtime = null, ?ClaudeHeadlessManagerClient $manager = null, ?QuickPromptRouter $router = null)
    {
        $this->router = $router ?? new QuickPromptRouter();
        $this->manager = $manager ?? new ClaudeHeadlessManagerClient(null, 2, false);
        $this->runtime = $runtime !== null ? \Closure::fromCallable($runtime) : function (string $agent): RuntimeProvider {
            return $agent === 'claude'
                ? new ClaudeHeadlessRuntime($this->manager, $this->manager)
                : new CodexHeadlessRuntime(new CodexBridgeClient(null, 2));
        };
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function start(array $request): array
    {
        $data = $request['input'] ?? null;
        $key = $request['key'] ?? null;
        if (!is_array($data) || !is_string($key) || preg_match('/^[a-f0-9]{64}$/D', $key) !== 1) return self::error('invalid_request', 422);
        $settings = $this->validate($data);
        if (isset($settings['type'])) return $settings;
        $fingerprint = hash('sha256', json_encode([$settings, $data['prompt'], $data['session_id'] ?? null], JSON_THROW_ON_ERROR));
        $previous = QuickPromptStore::reserve($key, $fingerprint);
        if ($previous !== null) {
            if ($previous['fingerprint'] !== $fingerprint) return self::error('idempotency_conflict', 409);
            return is_array($previous['response']) ? $previous['response'] : self::error('dispatch_pending', 409);
        }
        $finish = static fn(array $result): array => QuickPromptStore::finish($key, $result);

        $id = $data['session_id'] ?? null;
        if ($id === null) {
            // After the reservation, so a retry never asks the router again.
            $settings = $this->route($data, $settings);
        }
        $lock = null;
        $isNew = false;
        try {
            $runtime = ($this->runtime)($settings['agent']);
            if ($id !== null) {
                if (!is_string($id) || preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id) !== 1) return $finish(self::error('invalid_session', 422));
                // Continuation is deliberately limited to tracked, idle Claude
                // sessions still in their original permission mode. Codex's queue may override sandbox settings;
                // its continuation is refused until that boundary is verifiable.
                $stored = QuickPromptStore::settings($id);
                $session = SidecarStore::read_sidecar($id);
                $state = SessionStatusStore::read_status($id);
                if ($stored !== $settings || ($session['agent'] ?? null) !== 'claude' || ($session['runtime'] ?? null) !== 'headless'
                    || ($session['workdir'] ?? null) !== $settings['workdir']
                    || ($session['profile'] ?? null) !== $settings['profile']
                    || ($state['mode'] ?? null) !== $settings['mode']
                    || ($state['status'] ?? null) !== 'idle'
                    || ($settings['model'] !== null && ($state['model'] ?? null) !== $settings['model']
                        && SelectableModel::family_from_raw_model(is_string($state['model'] ?? null) ? $state['model'] : '') !== $settings['model'])) return $finish(self::error('invalid_session', 422));
                // Only this short dispatch owns the lock, never completion wait.
                $lock = fopen(Config::sidecar_dir() . '/quick-prompt-' . hash('sha256', $id) . '.lock', 'c');
                if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) return $finish(self::error('session_busy', 409));
                $live = $this->manager->request('sessioneer/status', ['session' => $id]);
                if (($live['ok'] ?? false) !== true || ($live['state'] ?? null) !== 'idle' || ($live['process'] ?? null) !== 'running') return $finish(self::error('invalid_session', 422));
            } else {
                $created = $runtime->create([
                    'workdir' => $settings['workdir'], 'model' => $settings['model'],
                    'profile' => $settings['profile'], 'starting_mode' => $settings['mode'] === 'plan' || $settings['agent'] === 'codex' ? 'plan' : null, 'read_only' => true, 'include_session' => false, 'unique_name' => true,
                ]);
                $id = $created['ok'] === true ? ($created['id'] ?? $created['name'] ?? null) : null;
                if (!is_string($id) || preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id) !== 1) return $finish(self::error('agent_unavailable', 503, is_string($created['message'] ?? null) ? $created['message'] : null, $this->account($settings)));
                // `claude -p --permission-mode auto` is silently ignored (the manager reports
                // that as a mode warning), so auto is applied after spawn like the dashboard does.
                if ($settings['agent'] === 'claude' && $settings['mode'] === 'auto') {
                    $applied = $runtime->set_mode($id, 'auto');
                    if ($applied['ok'] !== true) {
                        $this->discard('claude', $id);
                        return $finish(self::error('agent_unavailable', 503, is_string($applied['message'] ?? null) ? $applied['message'] : null, $this->account($settings)));
                    }
                }
                if ($settings['agent'] === 'codex') {
                    SidecarStore::write_sidecar($id, [
                        'workdir' => $settings['workdir'], 'spawned_at' => time(), 'agent_session_id' => $id,
                        'spawned_by_app' => true, 'agent' => 'codex', 'runtime' => 'headless',
                    ]);
                }
                QuickPromptStore::track($id, $settings);
            }
            // Save the reference before sending: an ambiguous socket failure
            // must never cause a retry to create or deliver a duplicate turn.
            $isNew = ($data['session_id'] ?? null) === null;
            $reference = ['type' => 'session', 'status' => 'unknown', 'session_id' => $id, 'request_id' => $key];
            if ($isNew) $reference += ['created' => true];
            $finish($reference);
            $sent = $settings['agent'] === 'claude'
                ? $this->manager->request('sessioneer/sendInput', ['session' => $id, 'content' => trim($data['prompt']), 'quick_prompt_id' => $key, 'require_mode' => $settings['mode']])
                : $runtime->send_message($id, trim($data['prompt']));
            if (($sent['ok'] ?? false) !== true) {
                // A session this request created and could not feed is only clutter.
                return $finish(self::error('delivery_failed', 503, is_string($sent['message'] ?? null) ? $sent['message'] : null, $this->account($settings)) + ($isNew ? [] : ['session_id' => $id]) + ($isNew && !$this->discard($settings['agent'], $id) ? ['session_id' => $id] : []));
            }
            return $finish(['type' => 'session', 'status' => 'running', 'session_id' => $id, 'request_id' => $key, 'turn_id' => $sent['turn_id'] ?? null] + ($isNew ? ['created' => true] : []));
        } catch (\Throwable $e) {
            error_log('Quick Prompt host dispatch failed: ' . $e::class);
            $kept = is_string($id) && !($isNew && $this->discard($settings['agent'], $id));
            return $finish(self::error('agent_unavailable', 503) + ($kept ? ['session_id' => $id] : []));
        } finally {
            if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); }
        }
    }

    /** Fills the fields the client left out from the local router; explicit fields always win.
     * @param array<string, mixed> $data
     * @param array<string, mixed> $settings
     * @return array<string, mixed> the settings to use
     */
    private function route(array $data, array $settings): array
    {
        if (!QuickPromptRouter::enabled()) return $settings;
        $models = json_decode(Config::sessioneer_config('QUICK_PROMPT_MODELS', '{"claude":["sonnet","haiku","opus","fable"],"codex":[]}'), true);
        $projects = json_decode(Config::sessioneer_config('QUICK_PROMPT_PROJECTS', '{}'), true);
        $picked = $this->router->route(trim($data['prompt']), [
            'agent' => array_values(array_intersect(['claude', 'codex'], array_map('trim', explode(',', Config::sessioneer_config('QUICK_PROMPT_AGENTS', 'claude'))))),
            'profile' => Config::claude_profile_names(),
            'project' => is_array($projects) ? array_map('strval', array_keys($projects)) : [],
            'models' => is_array($models) ? $models : [],
        ]);
        $picked = array_diff_key($picked, $data);
        // A model or account the chosen agent cannot use is dropped, never an error.
        foreach ([$picked, array_diff_key($picked, ['model' => 1, 'profile' => 1])] as $candidate) {
            if ($candidate === []) break;
            $routed = $this->validate($candidate + $data);
            if (!isset($routed['type'])) return $routed;
        }
        return $settings;
    }

    /** @param array<string, mixed> $settings */
    private function account(array $settings): ?string
    {
        return $settings['agent'] === 'claude' ? (is_string($settings['profile']) ? $settings['profile'] : 'default') : null;
    }

    /** Stops and archives a session this feature created; its conversation stays resumable. */
    private function discard(string $agent, string $id): bool
    {
        try {
            $stopped = ($this->runtime)($agent)->kill($id);
        } catch (\Throwable $e) {
            error_log('Quick Prompt cleanup failed: ' . $e::class);
            return false;
        }
        SidecarStore::delete_sidecar($id);
        SessionStatusStore::delete_status($id);
        return $stopped['ok'] === true;
    }

    /** @return array<string, mixed> */
    private function failed(string $id, string $agent, mixed $requestId, mixed $raw = null): array
    {
        $profile = QuickPromptStore::settings($id)['profile'] ?? null;
        $error = self::error('agent_failed', 502, is_string($raw) ? $raw : null, $agent === 'claude' ? (is_string($profile) ? $profile : 'default') : null);
        if (!$this->discard($agent, $id)) $error += ['session_id' => $id];
        if (is_string($requestId) && preg_match('/^[a-f0-9]{64}$/D', $requestId) === 1) QuickPromptStore::finish($requestId, $error);
        return $error;
    }

    /** Archives the session of a short answer already delivered, only while it is idle.
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function dispose(array $request): array
    {
        $id = $request['session_id'] ?? null;
        $requestId = $request['request_id'] ?? null;
        $settings = is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id) === 1 ? QuickPromptStore::settings($id) : null;
        if ($settings === null || !is_string($requestId) || preg_match('/^[a-f0-9]{64}$/D', $requestId) !== 1) return ['ok' => false, 'archived' => false];
        if (Config::sessioneer_config('QUICK_PROMPT_ARCHIVE_ANSWERS', '1') !== '1') return ['ok' => true, 'archived' => false];
        if ($settings['agent'] === 'claude') {
            $live = $this->manager->request('sessioneer/status', ['session' => $id]);
            $idle = ($live['ok'] ?? false) === true && ($live['state'] ?? null) === 'idle' && ($live['open_turns'] ?? 0) === 0;
        } else {
            $idle = ($this->runtime)('codex')->pending_prompt($id) === null && (($this->runtime)('codex')->status($id)['status']) === 'idle';
        }
        if (!$idle) return ['ok' => true, 'archived' => false];
        $archived = $this->discard($settings['agent'], $id);
        if ($archived && is_array($request['response'] ?? null)) QuickPromptStore::finish($requestId, $request['response']);
        return ['ok' => true, 'archived' => $archived];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        foreach (array_keys($input) as $field) {
            if (!in_array($field, ['prompt', 'agent', 'project', 'mode', 'model', 'profile', 'session_id'], true)) return self::error('invalid_request', 422);
        }
        if (!is_string($input['prompt'] ?? null) || trim($input['prompt']) === '' || strlen($input['prompt']) > 16384 || preg_match('//u', $input['prompt']) !== 1 || str_contains($input['prompt'], "\0")) return self::error('invalid_prompt', 422);
        foreach (['agent', 'project', 'mode', 'model', 'profile'] as $field) {
            if (isset($input[$field]) && (!is_string($input[$field]) || $input[$field] === '')) return self::error('invalid_request', 422);
        }
        $agent = $input['agent'] ?? Config::sessioneer_config('QUICK_PROMPT_DEFAULT_AGENT', 'claude');
        if (!in_array($agent, ['claude', 'codex'], true) || !in_array($agent, explode(',', Config::sessioneer_config('QUICK_PROMPT_AGENTS', 'claude')), true)) return self::error('invalid_agent', 422);
        $project = $input['project'] ?? Config::sessioneer_config('QUICK_PROMPT_DEFAULT_PROJECT', '');
        $projects = json_decode(Config::sessioneer_config('QUICK_PROMPT_PROJECTS', '{}'), true);
        $path = is_array($projects) && is_string($project) ? ($projects[$project] ?? null) : null;
        if (!is_string($path) || $path === '' || $path[0] !== '/' || !is_dir($path) || realpath($path) === false) return self::error('invalid_project', 422);
        $modes = $agent === 'claude' ? ['plan', 'auto'] : ['read-only'];
        $defaultMode = $agent === 'claude' ? Config::sessioneer_config('QUICK_PROMPT_DEFAULT_MODE', 'auto') : 'read-only';
        $mode = $input['mode'] ?? (in_array($defaultMode, $modes, true) ? $defaultMode : $modes[0]);
        if (!in_array($mode, $modes, true)) return self::error('invalid_mode', 422);
        $profile = $input['profile'] ?? ($agent === 'claude' ? (Config::sessioneer_config('QUICK_PROMPT_DEFAULT_PROFILE', '') ?: null) : null);
        if ($profile !== null && ($agent !== 'claude' || !in_array($profile, Config::claude_profile_names(), true))) return self::error('invalid_profile', 422);
        $models = json_decode(Config::sessioneer_config('QUICK_PROMPT_MODELS', '{"claude":["sonnet","haiku","opus","fable"],"codex":[]}'), true);
        $allowed = is_array($models) && is_array($models[$agent] ?? null) ? $models[$agent] : [];
        $defaultModel = Config::sessioneer_config('QUICK_PROMPT_DEFAULT_MODEL', '');
        $model = $input['model'] ?? (in_array($defaultModel, $allowed, true) ? $defaultModel : null);
        if ($model !== null && !in_array($model, $allowed, true)) return self::error('invalid_model', 422);
        return ['agent' => $agent, 'project' => $project, 'workdir' => realpath($path), 'mode' => $mode, 'model' => $model, 'profile' => $profile];
    }

    /** @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function status(array $request): array
    {
        $id = $request['session_id'] ?? null;
        if (!is_string($id) || preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id) !== 1) return self::error('invalid_session', 422);
        $settings = QuickPromptStore::settings($id);
        if ($settings === null) return self::error('invalid_session', 422);
        $reference = ['type' => 'session', 'status' => 'unknown', 'session_id' => $id];
        if ($settings['agent'] === 'claude') {
            $reply = $this->manager->request('sessioneer/status', ['session' => $id]);
            if (($reply['ok'] ?? false) !== true) return $reference;
            if (($reply['state'] ?? null) === 'blocked') return array_replace($reference, ['status' => 'blocked']);
            if (($reply['state'] ?? null) === 'working' || ($reply['open_turns'] ?? 0) > 0) return array_replace($reference, ['status' => 'running']);
            $result = $reply['last_result'] ?? null;
            if (!is_array($result)) {
                if (!empty($reply['last_turn_error'])) return $this->failed($id, 'claude', $request['request_id'] ?? null, $reply['last_turn_error']);
                return $reference;
            }
            if (($result['request_id'] ?? null) !== ($request['request_id'] ?? null)) return $reference;
            if (!empty($result['failed'])) return $this->failed($id, 'claude', $request['request_id'] ?? null, $reply['last_turn_error'] ?? null);
            return array_replace($reference, [
                'status' => 'completed', 'completed' => true,
                'text' => is_string($result['text'] ?? null) ? $result['text'] : '', 'has_tools' => !empty($result['has_tools']),
            ]);
        }
        $runtime = ($this->runtime)('codex');
        if ($runtime->pending_prompt($id) !== null) return array_replace($reference, ['status' => 'blocked']);
        $reply = $runtime->detail($id);
        if ($reply['ok'] !== true) return $reference;
        $turns = $reply['session']['turns'] ?? [];
        $turn = is_array($turns) && $turns !== [] ? $turns[array_key_last($turns)] : null;
        if (is_array($turns) && count($turns) > 1) return $reference;
        if (!is_array($turn)) return array_replace($reference, ['status' => 'running']);
        // Without the submission's native turn id, a dashboard turn could
        // be mistaken for our answer. Queued/uncorrelated delivery stays a link.
        if (!is_string($request['turn_id'] ?? null) || ($turn['id'] ?? null) !== $request['turn_id']) return $reference;
        if (($turn['status'] ?? null) === 'failed') return $this->failed($id, 'codex', $request['request_id'] ?? null, CodexTranscriptService::turn_error_message($turn['error'] ?? null));
        if (($turn['status'] ?? null) !== 'completed') return array_replace($reference, ['status' => ($turn['status'] ?? null) === 'interrupted' ? 'interrupted' : 'running']);
        $text = ''; $hasTools = false;
        foreach (is_array($turn['items'] ?? null) ? $turn['items'] : [] as $item) {
            if (!is_array($item)) { $hasTools = true; continue; }
            if (($item['type'] ?? null) === 'agentMessage' && is_string($item['text'] ?? null)) $text = $item['text'];
            elseif (($item['type'] ?? null) !== 'userMessage' && ($item['type'] ?? null) !== 'reasoning') $hasTools = true;
        }
        return array_replace($reference, ['status' => 'completed', 'completed' => true, 'text' => strlen($text) <= 8192 ? $text : '', 'has_tools' => $hasTools]);
    }

    /** Public errors are codes and fixed messages, never raw runtime diagnostics.
     * @return array<string, mixed>
     */
    public static function error(string $code, int $http, ?string $raw = null, ?string $account = null): array
    {
        $message = match ($code) {
            'agent_unavailable' => 'Unable to start the agent',
            'agent_failed' => 'The agent could not complete the prompt',
            'delivery_failed' => 'Unable to deliver the prompt; check the session before retrying',
            'dispatch_pending' => 'Dispatch is pending or uncertain; retry with the same idempotency key',
            'idempotency_conflict' => 'Idempotency key was already used for another request',
            default => 'Invalid or incompatible Quick Prompt request',
        };
        $detail = $raw !== null ? self::scrub($raw) : '';
        $who = $account !== null ? ['account' => $account] : [];
        if ($detail === '') return ['type' => 'error', 'code' => $code, 'message' => $message, '_http' => $http] + $who;
        $reason = self::classify($detail);
        $headline = [
            'quota_exhausted' => 'Usage limit reached',
            'auth_failed' => 'Authentication failed',
            'service_unavailable' => 'The model service is unavailable',
            'model_unavailable' => 'The model is unavailable',
            'mode_not_applied' => 'The permission mode could not be applied',
            'agent_crashed' => 'The agent process crashed',
            'host_unavailable' => 'The agent host is unreachable',
        ][$reason] ?? $message;
        if ($account !== null && in_array($reason, ['quota_exhausted', 'auth_failed'], true)) $headline .= " ({$account} account)";
        return ['type' => 'error', 'code' => $code, 'reason' => $reason, 'message' => $headline . ': ' . $detail, 'detail' => $detail, '_http' => $http] + $who;
    }

    /** What went wrong, as a stable machine-readable word.
     * @return string
     */
    private static function classify(string $detail): string
    {
        foreach ([
            'mode_not_applied' => '/permission mode/i',
            'quota_exhausted' => '/(?:usage|session|weekly|daily) limit|rate.?limit|quota|limit (?:reached|exceeded)|too many requests|\b429\b/i',
            'auth_failed' => '/not logged in|log ?in|unauthori[sz]ed|authenticat|credential|api key|\b40[13]\b/i',
            'model_unavailable' => '/model[^.]{0,60}(?:not found|unavailable|not available|access|does not exist)/i',
            'service_unavailable' => '/overloaded|\b(?:500|502|503|529)\b|timed? ?out|connection|network|unavailable/i',
            'agent_crashed' => '/exited unexpectedly|crash|terminated|killed/i',
            'host_unavailable' => '/cannot reach|unreachable/i',
        ] as $reason => $pattern) {
            if (preg_match($pattern, $detail) === 1) return $reason;
        }
        return 'agent_error';
    }

    /** An agent's error text made safe to show: no paths, secrets, tokens or addresses, bounded.
     */
    private static function scrub(string $raw): string
    {
        $text = preg_replace('/\x1b\[[0-9;]*[A-Za-z]|[\x00-\x08\x0b-\x1f\x7f]/', ' ', $raw) ?? '';
        $text = preg_replace('/\b(?:token|secret|password|passwd|api[_-]?key|authorization|bearer)\b\s*[=:]?\s*\S+/i', '[redacted]', $text) ?? '';
        $text = preg_replace('#~?(?:[A-Za-z]:)?(?:/[^\s/:"\'<>]+){2,}#', '[path]', $text) ?? '';
        $text = preg_replace('/[\w.+-]+@[\w-]+\.[\w.-]+/', '[email]', $text) ?? '';
        $text = preg_replace('/\b[A-Za-z0-9_\-]{24,}\b/', '[redacted]', $text) ?? '';
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        return preg_match('//u', $text) === 1 ? mb_strimwidth($text, 0, 240, '…', 'UTF-8') : '';
    }
}
