<?php

declare(strict_types=1);

namespace App\Services;

use App\AgentClient;

/**
 * Bearer-token JSON API shaped for Apple Shortcuts (docs/ios-shortcuts.md).
 *
 * It owns no business logic: every read and write goes through the same host-agent actions
 * the web UI posts (`list`, `session_detail`, `answer_prompt`, `answer_prompt_with_text`,
 * `answer_multi_question`, `send_message`), so a reply sent from a Shortcut behaves exactly
 * like one sent from the dashboard. Each write re-reads the session first and refuses a
 * prompt that is no longer the one the caller saw (HTTP 409).
 */
final class ShortcutsService
{
    public const MAX_BODY_BYTES = 16384;
    public const MAX_TEXT_BYTES = 8000;

    private const ID_PATTERN = '/^[a-zA-Z0-9_.:-]{1,128}$/D';

    /** @var \Closure(array<string, mixed>):array<string, mixed> */
    private \Closure $call;

    /** @param callable(array<string, mixed>):array<string, mixed>|null $call */
    public function __construct(?callable $call = null)
    {
        $this->call = \Closure::fromCallable($call ?? [AgentClient::class, 'agent_call']);
    }

    /** Authenticate before touching the body or the host agent.
     * @param array<string, mixed> $server
     * @return array<string, mixed>|null an error envelope, or null when the request may proceed
     */
    public static function guard(array $server, string $expectedMethod): ?array
    {
        $token = getenv('SHORTCUTS_API_TOKEN');
        if (!is_string($token) || strlen($token) < 32 || preg_match('/\s/', $token) === 1) return self::error('not_configured', 'The Shortcuts API is not configured', 503);
        $authorization = $server['HTTP_AUTHORIZATION'] ?? '';
        $provided = is_string($authorization) && preg_match('/^Bearer ([^\s]+)$/iD', $authorization, $match) === 1 ? $match[1] : '';
        if (!hash_equals(hash('sha256', $token), hash('sha256', $provided))) return self::error('unauthorized', 'Unauthorized', 401);
        if (($server['REQUEST_METHOD'] ?? '') !== $expectedMethod) return self::error('method_not_allowed', $expectedMethod . ' required', 405);
        if (isset($server['HTTP_ORIGIN']) || ($server['HTTP_SEC_FETCH_SITE'] ?? '') === 'cross-site') return self::error('origin_not_allowed', 'Browser requests are not supported', 403);
        if ($expectedMethod === 'POST') {
            if (strtolower(trim(explode(';', (string)($server['CONTENT_TYPE'] ?? ''))[0])) !== 'application/json') return self::error('unsupported_media_type', 'JSON body required', 415);
            if ((int)($server['CONTENT_LENGTH'] ?? 0) > self::MAX_BODY_BYTES) return self::error('input_too_large', 'Request body is too large', 413);
        }
        return null;
    }

    /** Shared per-minute limit across PHP workers; no forwarded-IP trust.
     * @return array<string, mixed>|null
     */
    public static function rate_limit(): ?array
    {
        $dir = getenv('SHORTCUTS_STATE_DIR') ?: sys_get_temp_dir() . '/sessioneer-shortcuts';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) return self::error('state_unavailable', 'The Shortcuts API is temporarily unavailable', 503);
        $limit = (int)(getenv('SHORTCUTS_RATE_LIMIT') ?: 60);
        $limit = max(1, min(600, $limit > 0 ? $limit : 60));
        $db = new \PDO('sqlite:' . $dir . '/rate.sqlite', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $db->exec('PRAGMA busy_timeout=1000; CREATE TABLE IF NOT EXISTS requests (created REAL NOT NULL)');
        $db->exec('BEGIN IMMEDIATE');
        try {
            $now = microtime(true);
            $stmt = $db->prepare('DELETE FROM requests WHERE created < ?');
            $stmt->execute([$now - 60]);
            if ((int)$db->query('SELECT COUNT(*) FROM requests')->fetchColumn() >= $limit) {
                $db->exec('COMMIT');
                return self::error('rate_limited', 'Too many requests; retry in a minute', 429);
            }
            $stmt = $db->prepare('INSERT INTO requests (created) VALUES (?)');
            $stmt->execute([$now]);
            $db->exec('COMMIT');
            return null;
        } catch (\Throwable $e) {
            $db->exec('ROLLBACK');
            throw $e;
        }
    }

    /** @return array<string, mixed> */
    public function sessions(): array
    {
        $list = ($this->call)(['action' => 'list']);
        if (($list['ok'] ?? false) !== true || !is_array($list['sessions'] ?? null)) return self::error('agent_unavailable', 'Unable to reach the host agent', 503);

        $rows = [];
        foreach ($list['sessions'] as $entry) {
            if (is_array($entry) && is_string($entry['name'] ?? null) && preg_match(self::ID_PATTERN, $entry['name']) === 1) $rows[] = $entry;
        }
        $rank = static fn(array $e): int => self::status($e) === 'needs_reply' ? 0 : (self::status($e) === 'working' ? 1 : 2);
        usort($rows, static fn(array $a, array $b): int => [$rank($a), -(int)($a['activity'] ?? 0), (string)$a['name']] <=> [$rank($b), -(int)($b['activity'] ?? 0), (string)$b['name']]);

        $sessions = [];
        $menu = [];
        $ids = [];
        foreach ($rows as $i => $entry) {
            $status = self::status($entry);
            $display = ($i + 1) . '. ' . self::title($entry) . ' — ' . self::STATUS_LABELS[$status];
            $sessions[] = [
                'id' => $entry['name'],
                'display_name' => $display,
                'status' => $status,
                'can_message' => $status !== 'needs_reply',
                'pending_prompt_count' => $status === 'needs_reply' ? 1 : 0,
            ];
            $menu[] = $display;
            $ids[] = $entry['name'];
        }
        return ['ok' => true, 'count' => count($sessions), 'menu' => $menu, 'ids' => $ids, 'sessions' => $sessions];
    }

    /** @return array<string, mixed> */
    public function session(string $id): array
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) return self::error('invalid_session', 'A valid session id is required', 422);
        $detail = $this->detail($id);
        if ($detail === null) return self::error('not_found', 'Session not found', 404);

        $status = self::status($detail);
        $prompt = self::prompt($detail);
        $out = [
            'ok' => true,
            'id' => $id,
            'display_name' => self::title($detail) . ' — ' . self::STATUS_LABELS[$status],
            'status' => $status,
            'can_message' => $status !== 'needs_reply',
            'has_prompt' => $prompt !== null,
            'prompt_id' => $prompt['prompt_id'] ?? '',
            'prompt_kind' => $prompt === null ? 'none' : (count($prompt['questions']) === 1 ? 'single' : 'multi'),
            'question_count' => $prompt !== null ? count($prompt['questions']) : 0,
            'questions' => [],
        ];
        if ($prompt !== null) {
            foreach ($prompt['questions'] as $q) {
                // "0" is the typed-answer entry: Shortcuts can then branch on the number it split off
                // the chosen label instead of comparing booleans. It is never a valid `choice`.
                $choices = $q['choices'];
                $ids = $q['choice_ids'];
                if ($q['allows_text']) {
                    $choices[] = self::TEXT_CHOICE_LABEL;
                    $ids[] = '0';
                }
                $out['questions'][] = [
                    'index' => $q['index'],
                    'question' => $q['question'],
                    'select_mode' => $q['multi_select'] ? 'many' : 'one',
                    'multi_select' => $q['multi_select'],
                    'allows_text' => $q['allows_text'],
                    'choices' => $choices,
                    'choice_ids' => $ids,
                ];
            }
        }
        return $out;
    }

    /** @return array<string, mixed> */
    public function message(string $raw): array
    {
        $input = self::decode($raw);
        if (isset($input['_http'])) return $input;
        $id = $input['session'] ?? null;
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) return self::error('invalid_session', 'A valid session id is required', 422);
        $text = self::text($input['text'] ?? null);
        if ($text === null) return self::error('invalid_text', 'Text must contain 1–' . self::MAX_TEXT_BYTES . ' UTF-8 bytes', 422);

        $detail = $this->detail($id);
        if ($detail === null) return self::error('not_found', 'Session not found', 404);
        if (self::status($detail) === 'needs_reply') return self::error('prompt_pending', 'This session is waiting on a prompt; answer it instead', 409);

        return $this->outcome(($this->call)(['action' => 'send_message', 'session' => $id, 'text' => $text, 'attachment_paths' => []]), 'Message sent');
    }

    /** @return array<string, mixed> */
    public function answer(string $raw): array
    {
        $input = self::decode($raw);
        if (isset($input['_http'])) return $input;
        $id = $input['session'] ?? null;
        $promptId = $input['prompt_id'] ?? null;
        if (!is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) return self::error('invalid_session', 'A valid session id is required', 422);
        if (!is_string($promptId) || preg_match('/^[a-f0-9]{12}$/D', $promptId) !== 1) return self::error('invalid_prompt', 'A valid prompt_id is required', 422);
        $given = array_filter(['choice', 'text', 'answers'], static fn(string $k): bool => array_key_exists($k, $input));
        if (count($given) !== 1) return self::error('invalid_answer', 'Send exactly one of choice, text or answers', 422);

        $detail = $this->detail($id);
        if ($detail === null) return self::error('not_found', 'Session not found', 404);
        $prompt = self::prompt($detail);
        if ($prompt === null) return self::error('no_pending_prompt', 'This session has no pending prompt (already answered?)', 409);
        if (!hash_equals($prompt['prompt_id'], $promptId)) return self::error('prompt_changed', 'The prompt changed since you read it', 409);

        $isMulti = $prompt['kind'] === 'multi';
        $request = null;
        if ($isMulti && count($prompt['questions']) === 1 && !isset($input['answers'])) {
            // A one-question form prompt is presented as `single`, so it takes choice/text like any other.
            $one = isset($input['text']) ? (is_string($input['text']) && self::text($input['text']) !== null ? 'text:' . $input['text'] : null) : ($input['choice'] ?? null);
            if (!is_string($one)) return self::error('invalid_answer', 'Send a valid choice or text', 422);
            $input = ['answers' => [$one]] + $input;
        }
        if (!$isMulti) {
            $q = $prompt['questions'][0];
            if (isset($input['choice'])) {
                $choice = $input['choice'];
                if (!is_string($choice) || !in_array($choice, $q['choice_ids'], true)) return self::error('invalid_choice', 'That choice is not offered by this prompt', 422);
                $label = $prompt['labels'][(int)$choice] ?? '';
                $request = ['action' => 'answer_prompt', 'session' => $id, 'option' => (int)$choice, 'expected_label' => $label];
                if ($prompt['request_id'] !== null) $request['expected_request_id'] = $prompt['request_id'];
            } elseif (isset($input['text'])) {
                $text = self::text($input['text']);
                if ($text === null) return self::error('invalid_text', 'Text must contain 1–' . self::MAX_TEXT_BYTES . ' UTF-8 bytes', 422);
                if ($prompt['text_option'] === null) return self::error('text_not_supported', 'This prompt does not accept a typed answer', 422);
                $request = ['action' => 'answer_prompt_with_text', 'session' => $id, 'option' => $prompt['text_option'], 'text' => $text];
                if ($prompt['request_id'] !== null) $request['expected_request_id'] = $prompt['request_id'];
            } else {
                return self::error('invalid_answer', 'This prompt takes a choice or text, not answers', 422);
            }
        } else {
            $answers = $input['answers'] ?? null;
            if (!is_array($answers) || !array_is_list($answers) || count($answers) !== count($prompt['questions'])) return self::error('invalid_answer', 'answers must hold one entry per question', 422);
            $built = [];
            foreach ($prompt['questions'] as $i => $q) {
                $entry = $answers[$i];
                if (!is_string($entry)) return self::error('invalid_answer', 'Each answer must be a string', 422);
                $parsed = self::multi_answer($entry, $q);
                if ($parsed === null) return self::error('invalid_answer', 'Answer ' . ($i + 1) . ' does not match its question', 422);
                $built[] = $parsed;
            }
            $request = ['action' => 'answer_multi_question', 'session' => $id, 'answers' => $built];
            if ($prompt['request_id'] !== null) $request['expected_request_id'] = $prompt['request_id'];
        }
        return $this->outcome(($this->call)($request), 'Answer sent');
    }

    private const TEXT_CHOICE_LABEL = '0. Type my own answer';
    private const STATUS_LABELS = ['needs_reply' => 'NEEDS REPLY', 'working' => 'WORKING', 'idle' => 'IDLE'];

    /** @param array<string, mixed> $entry */
    private static function status(array $entry): string
    {
        $blocked = !empty($entry['prompt_options']) || !empty($entry['prompt_questions']) || is_string($entry['blocked_reason'] ?? null);
        return $blocked ? 'needs_reply' : (!empty($entry['working']) ? 'working' : 'idle');
    }

    /** @param array<string, mixed> $entry */
    private static function title(array $entry): string
    {
        $title = is_string($entry['title'] ?? null) ? trim($entry['title']) : '';
        $title = preg_replace('/[\x00-\x1f]+/u', ' ', $title) ?? '';
        if ($title === '') $title = (string)($entry['name'] ?? 'Session');
        return mb_strlen($title) > 60 ? mb_substr($title, 0, 59) . '…' : $title;
    }

    /** @return array<string, mixed>|null null when the host agent does not know this session */
    private function detail(string $id): ?array
    {
        $detail = ($this->call)(['action' => 'session_detail', 'session' => $id]);
        return ($detail['ok'] ?? false) === true ? $detail : null;
    }

    /**
     * The one pending prompt as flat questions. A plain prompt is one question; an
     * AskUserQuestion with several is `multi` and must be answered in one request.
     *
     * @param array<string, mixed> $detail
     * @return array{prompt_id:string, kind:string, request_id:?string, text_option:?int, labels:array<int,string>, questions:array<int, array{index:int, question:string, multi_select:bool, allows_text:bool, choices:array<int,string>, choice_ids:array<int,string>}>}|null
     */
    private static function prompt(array $detail): ?array
    {
        $requestId = is_string($detail['prompt_request_id'] ?? null) && $detail['prompt_request_id'] !== '' ? $detail['prompt_request_id'] : null;
        $questions = [];
        $labels = [];
        $textOption = null;
        $kind = 'single';

        if (is_array($detail['prompt_questions'] ?? null) && $detail['prompt_questions'] !== []) {
            $kind = 'multi';
            foreach (array_values($detail['prompt_questions']) as $i => $q) {
                if (!is_array($q)) continue;
                $choices = [];
                $ids = [];
                foreach (array_values(is_array($q['options'] ?? null) ? $q['options'] : []) as $n => $option) {
                    $choices[] = ($n + 1) . '. ' . self::label(is_array($option) ? ($option['label'] ?? '') : $option);
                    $ids[] = (string)($n + 1);
                }
                $multi = ($q['multiSelect'] ?? false) === true || ($q['multiple'] ?? false) === true;
                $questions[] = ['index' => $i, 'question' => self::label($q['question'] ?? ''), 'multi_select' => $multi, 'allows_text' => !$multi, 'choices' => $choices, 'choice_ids' => $ids];
            }
        } elseif (is_array($detail['prompt_options'] ?? null) && $detail['prompt_options'] !== []) {
            $choices = [];
            $ids = [];
            foreach ($detail['prompt_options'] as $option) {
                if (!is_array($option) || !isset($option['number'])) continue;
                $number = (int)$option['number'];
                $label = self::label($option['label'] ?? '');
                $labels[$number] = $label;
                if (stripos($label, 'type something') !== false) {
                    $textOption = $number;
                    continue;
                }
                $choices[] = $number . '. ' . $label;
                $ids[] = (string)$number;
            }
            $questions[] = ['index' => 0, 'question' => self::label($detail['blocked_reason'] ?? 'Reply needed'), 'multi_select' => false, 'allows_text' => $textOption !== null, 'choices' => $choices, 'choice_ids' => $ids];
        }
        if ($questions === []) return null;

        return [
            'prompt_id' => substr(sha1(json_encode([$requestId, $questions]) ?: ''), 0, 12),
            'kind' => $kind,
            'request_id' => $requestId,
            'text_option' => $textOption,
            'labels' => $labels,
            'questions' => $questions,
        ];
    }

    /** One multi-question answer string: "2", "1,3" (multi-select), or "text:free answer".
     * @param array{multi_select:bool, allows_text:bool, choice_ids:array<int,string>} $q
     * @return int|array<int, int>|array{text:string}|null
     */
    private static function multi_answer(string $entry, array $q): int|array|null
    {
        if (str_starts_with($entry, 'text:')) {
            $text = self::text(substr($entry, 5));
            return $q['allows_text'] && $text !== null ? ['text' => $text] : null;
        }
        $parts = array_map('trim', explode(',', $entry));
        foreach ($parts as $part) if (!in_array($part, $q['choice_ids'], true)) return null;
        if ($q['multi_select']) return count(array_unique($parts)) === count($parts) ? array_map('intval', $parts) : null;
        return count($parts) === 1 ? (int)$parts[0] : null;
    }

    /** @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function outcome(array $result, string $okMessage): array
    {
        if (($result['ok'] ?? false) === true) return ['ok' => true, 'message' => $okMessage];
        $message = is_string($result['message'] ?? null) ? $result['message'] : '';
        if (preg_match('/no prompt is currently pending|changed since|already moved on|not currently waiting|not currently offered|not currently showing/i', $message) === 1) return self::error('prompt_changed', 'That prompt is no longer waiting (already answered?)', 409);
        if (stripos($message, 'not a currently active') !== false) return self::error('not_found', 'Session not found', 404);
        // No host-agent text past this point: it may carry paths or transcript content.
        return self::error('agent_rejected', 'The session did not accept that', 502);
    }

    /** @return array<string, mixed> */
    private static function decode(string $raw): array
    {
        if (strlen($raw) > self::MAX_BODY_BYTES) return self::error('input_too_large', 'Request body is too large', 413);
        try {
            $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return self::error('invalid_json', 'Malformed JSON body', 400);
        }
        return is_array($input) && !array_is_list($input) ? $input : self::error('invalid_json', 'A JSON object is required', 400);
    }

    private static function text(mixed $value): ?string
    {
        if (!is_string($value) || str_contains($value, "\0")) return null;
        $value = trim($value);
        return $value !== '' && strlen($value) <= self::MAX_TEXT_BYTES && mb_check_encoding($value, 'UTF-8') ? $value : null;
    }

    private static function label(mixed $value): string
    {
        $text = is_scalar($value) ? trim((string)$value) : '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return mb_strlen($text) > 300 ? mb_substr($text, 0, 299) . '…' : $text;
    }

    /** @return array<string, mixed> */
    public static function error(string $code, string $message, int $http): array
    {
        return ['ok' => false, 'code' => $code, 'message' => $message, '_http' => $http];
    }
}
