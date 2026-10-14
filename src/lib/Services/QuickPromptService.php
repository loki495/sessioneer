<?php

declare(strict_types=1);

namespace App\Services;

use App\AgentClient;

/** Token-only API: no browser session/cookie lock, CSRF exemption is limited to this route. */
final class QuickPromptService
{
    public const MAX_BODY_BYTES = 24576;

    /** @var \Closure(array<string, mixed>, float):array<string, mixed> */
    private \Closure $call;
    /** @var \Closure():float */
    private \Closure $now;
    /** @var \Closure(float):void */
    private \Closure $sleep;

    /** @param callable(array<string, mixed>, float):array<string, mixed>|null $call
     * @param callable():float|null $now
     * @param callable(float):void|null $sleep
     */
    public function __construct(?callable $call = null, ?callable $now = null, ?callable $sleep = null)
    {
        $this->call = \Closure::fromCallable($call ?? [AgentClient::class, 'agent_call_bounded']);
        $this->now = \Closure::fromCallable($now ?? static fn(): float => microtime(true));
        $this->sleep = \Closure::fromCallable($sleep ?? static function (float $seconds): void { usleep((int)($seconds * 1000000)); });
    }

    /** Authenticate before reading the body or contacting the host.
     * @param array<string, mixed> $server
     * @return array<string, mixed>|null
     */
    public static function guard(array $server): ?array
    {
        if (($server['REQUEST_METHOD'] ?? '') !== 'POST') return self::error('method_not_allowed', 'POST required', 405);
        $token = getenv('QUICK_PROMPT_API_TOKEN');
        if (!is_string($token) || strlen($token) < 32 || preg_match('/\s/', $token) === 1) return self::error('not_configured', 'Quick Prompt is not configured', 503);
        $authorization = $server['HTTP_AUTHORIZATION'] ?? '';
        $provided = is_string($authorization) && preg_match('/^Bearer ([^\s]+)$/iD', $authorization, $match) === 1 ? $match[1] : '';
        if (!hash_equals(hash('sha256', $token), hash('sha256', $provided))) return self::error('unauthorized', 'Unauthorized', 401);
        if (isset($server['HTTP_ORIGIN']) || ($server['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') return self::error('origin_not_allowed', 'Browser submissions are not supported', 403);
        if (strtolower(trim(explode(';', (string)($server['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') return self::error('unsupported_media_type', 'JSON body required', 415);
        if ((int)($server['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) return self::error('input_too_large', 'Request body is too large', 413);
        return null;
    }

    /** Atomic shared-token limiter across PHP workers; no forwarded-IP trust.
     * @return array<string, mixed>|null
     */
    public static function rate_limit(): ?array
    {
        $dir = getenv('QUICK_PROMPT_STATE_DIR') ?: sys_get_temp_dir() . '/sessioneer-quick-prompt';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) return self::error('state_unavailable', 'Quick Prompt is temporarily unavailable', 503);
        $db = new \PDO('sqlite:' . $dir . '/rate.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=1000; CREATE TABLE IF NOT EXISTS requests (created REAL NOT NULL)');
        $db->exec('BEGIN IMMEDIATE');
        try {
            $now = microtime(true);
            $stmt = $db->prepare('DELETE FROM requests WHERE created < ?'); $stmt->execute([$now - 60]);
            if ((int)$db->query('SELECT COUNT(*) FROM requests')->fetchColumn() >= self::integer('QUICK_PROMPT_RATE_LIMIT', 10, 1, 60)) {
                $db->exec('COMMIT');
                return self::error('rate_limited', 'Too many Quick Prompt requests; retry later', 429);
            }
            $stmt = $db->prepare('INSERT INTO requests (created) VALUES (?)'); $stmt->execute([$now]);
            $db->exec('COMMIT');
            return null;
        } catch (\Throwable $e) { $db->exec('ROLLBACK'); throw $e; }
    }

    /** @param array<string, mixed> $server
     * @return array<string, mixed>
     */
    public function submit(string $raw, array $server): array
    {
        if (strlen($raw) > self::MAX_BODY_BYTES) return self::error('input_too_large', 'Request body is too large', 413);
        try { $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return self::error('invalid_json', 'Malformed JSON body', 400); }
        if (!is_array($input) || !is_string($input['prompt'] ?? null)) return self::error('invalid_prompt', 'A nonempty text prompt is required', 422);
        if (str_starts_with($input['prompt'], '@sessioneer ')) {
            $lines = explode("\n", $input['prompt'], 2);
            foreach (explode(' ', substr($lines[0], 12)) as $hint) {
                if (preg_match('/^(agent|project|mode|model|profile)=([a-zA-Z0-9_.-]+)$/D', $hint, $parts) !== 1 || array_key_exists($parts[1], $input)) return self::error('invalid_hint', 'Invalid or conflicting Sessioneer hint', 422);
                $input[$parts[1]] = $parts[2];
            }
            $input['prompt'] = $lines[1] ?? '';
        }
        if (str_contains($input['prompt'], "\0")) return self::error('invalid_prompt', 'Prompt must not contain NUL bytes', 422);
        $input['prompt'] = trim($input['prompt']);
        if ($input['prompt'] === '' || strlen($input['prompt']) > 16384 || str_contains($input['prompt'], "\0")) return self::error('invalid_prompt', 'Prompt must contain 1–16384 UTF-8 bytes', 422);
        $base = getenv('QUICK_PROMPT_BASE_URL') ?: '';
        $url = parse_url($base);
        if (!is_array($url) || ($url['scheme'] ?? '') !== 'https' || empty($url['host']) || isset($url['user'], $url['pass']) || isset($url['user']) || isset($url['query']) || isset($url['fragment'])) return self::error('not_configured', 'Quick Prompt is not configured', 503);
        $key = $server['HTTP_IDEMPOTENCY_KEY'] ?? null;
        if ($key !== null && (!is_string($key) || preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D', $key) !== 1)) return self::error('invalid_idempotency_key', 'Invalid idempotency key', 422);
        $result = ($this->call)(['action' => 'quick_prompt_start', 'input' => $input, 'key' => hash('sha256', $key ?? bin2hex(random_bytes(32)))], 10.0);
        if (!isset($result['type'])) return self::error('agent_unavailable', 'Unable to reach the host agent; retry with the same idempotency key', 503);
        $id = $result['session_id'] ?? null;
        if ($result['type'] === 'error' || !is_string($id)) return $this->public_result($result, $base);
        $requestId = $result['request_id'] ?? null;
        $turnId = $result['turn_id'] ?? null;
        $disposable = !empty($result['created']) && ($result['intent'] ?? null) === 'answer';
        $deadline = ($this->now)() + self::integer('QUICK_PROMPT_WAIT_SECONDS', 6, 0, 15);
        while (($remaining = $deadline - ($this->now)()) > 0) {
            $probe = ($this->call)(['action' => 'quick_prompt_status', 'session_id' => $id, 'request_id' => $requestId, 'turn_id' => $turnId], min(2.0, $remaining));
            if (isset($probe['type'])) $result = $probe;
            else { $result = ['type' => 'session', 'status' => 'unknown', 'session_id' => $id]; break; }
            if ($result['type'] === 'error' || !empty($result['completed']) || in_array($result['status'] ?? '', ['blocked', 'unknown', 'interrupted'], true)) break;
            $remaining = $deadline - ($this->now)();
            if ($remaining > 0) ($this->sleep)(min(1.0, $remaining));
        }
        $public = $this->public_result($result, $base);
        if ($disposable && ($public['type'] ?? null) === 'answer' && is_string($requestId)) {
            // A short answer the router judged a one-off: archive its session, keeping the transcript.
            $answer = ['type' => 'answer', 'text' => $public['text'], 'archived' => true];
            $done = ($this->call)(['action' => 'quick_prompt_dispose', 'session_id' => $id, 'request_id' => $requestId, 'response' => $answer], 5.0);
            if (($done['archived'] ?? false) === true) return $answer;
        }
        return $public;
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function public_result(array $result, string $base): array
    {
        $id = $result['session_id'] ?? null;
        $reference = is_string($id) && preg_match('/^[a-zA-Z0-9_-]{1,128}$/D', $id) === 1
            ? ['session_id' => $id, 'session_url' => rtrim($base, '/') . '/session.php?session=' . rawurlencode($id)] : [];
        if (($result['type'] ?? null) === 'error') {
            // Only accept the fixed public error contract from our host action.
            return ['type' => 'error', 'code' => $result['code'] ?? 'agent_unavailable', 'message' => $result['message'] ?? 'Unable to start the agent', '_http' => $result['_http'] ?? 503] + $reference;
        }
        if ($reference === []) return self::error('agent_unavailable', 'Unable to start the agent', 503);
        $text = $result['text'] ?? null;
        if (!empty($result['completed']) && empty($result['has_tools']) && is_string($text) && trim($text) !== '' && preg_match('//u', $text) === 1 && strlen($text) <= self::integer('QUICK_PROMPT_MAX_ANSWER_BYTES', 2000, 1, 8192)) return ['type' => 'answer', 'text' => $text] + $reference;
        return ['type' => 'session', 'status' => $result['status'] ?? 'unknown'] + $reference;
    }

    public static function integer(string $key, int $default, int $min, int $max): int
    {
        $value = getenv($key);
        return is_string($value) && ctype_digit($value) ? max($min, min($max, (int)$value)) : $default;
    }

    /** @return array<string, mixed> */
    public static function error(string $code, string $message, int $http): array
    {
        return ['type' => 'error', 'code' => $code, 'message' => $message, '_http' => $http];
    }
}
