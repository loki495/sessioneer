<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SessionStatusStore;

/** Reads and answers the in-memory questions exposed by opencode serve. */
class OpenCodeQuestionService
{
    /** @return array{requestID:string, questions:array<int, array<string,mixed>>}|null */
    public static function pending_question(string $sessionId): ?array
    {
        [$status, $data] = self::request('GET', '/question');
        if ($status < 200 || $status >= 300 || !is_array($data)) return null;

        foreach ($data as $request) {
            if (!is_array($request) || ($request['sessionID'] ?? null) !== $sessionId) continue;
            $requestID = is_string($request['id'] ?? null) ? $request['id'] : null;
            $questions = is_array($request['questions'] ?? null) ? $request['questions'] : [];
            if ($requestID !== null && $questions !== []) return ['requestID' => $requestID, 'questions' => $questions];
        }
        return null;
    }

    /**
     * @return array{requestID:string, questions:array<int, array<string,mixed>>, directory:?string}|null
     */
    public static function stored_pending_question(string $sessionId): ?array
    {
        $status = SessionStatusStore::read_status($sessionId);
        $blocked = is_array($status['blocked'] ?? null) ? $status['blocked'] : null;
        if (($blocked['source'] ?? null) !== 'opencode_sse') return null;

        $requestID = is_string($blocked['request_id'] ?? null) && $blocked['request_id'] !== '' ? $blocked['request_id'] : null;
        $questions = is_array($blocked['tool_input']['questions'] ?? null) ? $blocked['tool_input']['questions'] : [];
        if ($requestID === null || $questions === []) return null;

        return [
            'requestID' => $requestID,
            'questions' => $questions,
            'directory' => is_string($blocked['directory'] ?? null) && $blocked['directory'] !== '' ? $blocked['directory'] : null,
        ];
    }

    /**
     * @param array<int, array<int, string>|string> $labels
     * @return array{ok:bool, message:string}
     */
    public static function answer(string $sessionId, array $labels, ?string $expectedRequestId = null): array
    {
        $stored = self::stored_pending_question($sessionId);
        $live = self::pending_question($sessionId);
        $pending = null;

        if ($expectedRequestId !== null && $expectedRequestId !== '') {
            foreach ([$stored, $live] as $candidate) {
                if ($candidate !== null && $candidate['requestID'] === $expectedRequestId) {
                    $pending = $candidate;
                    break;
                }
            }
            if ($pending === null) return ['ok' => false, 'message' => 'Rejected: this question is no longer the pending request'];
        } else {
            $pending = $stored ?? $live;
        }
        if ($pending === null) return ['ok' => false, 'message' => 'Rejected: this session is not currently waiting on a question'];

        $answers = self::validated_answers($pending['questions'], $labels);
        if ($answers === null) return ['ok' => false, 'message' => 'Rejected: answers do not match this question'];

        $requestId = $pending['requestID'];
        $directory = $pending['directory'] ?? ($stored['directory'] ?? null);
        [$status, $data, $error] = self::request(
            'POST',
            '/api/session/' . rawurlencode($sessionId) . '/question/' . rawurlencode($requestId) . '/reply',
            ['answers' => $answers],
            $directory,
        );

        // v2 declares NoContent. Only definitive unsupported/not-found routes
        // may fall through to v1; unknown transport state must not be replayed.
        if ($status === 204 && trim($error) === '') {
            SessionStatusStore::resolve_opencode_question($sessionId, $requestId);
            return ['ok' => true, 'message' => 'Question answered'];
        }
        if (!in_array($status, [404, 405, 501], true)) return ['ok' => false, 'message' => self::reply_error('v2', $status, $error)];
        if ($directory === null) return ['ok' => false, 'message' => 'Question reply failed: v2 is unavailable and the question directory is unknown'];

        [$legacyStatus, $legacyData, $legacyError] = self::request(
            'POST',
            '/question/' . rawurlencode($requestId) . '/reply',
            ['answers' => $answers],
            $directory,
        );
        // v1 explicitly returns JSON boolean true. A 2xx no-content v1 reply
        // is ambiguous and is intentionally not treated as a successful answer.
        if ($legacyStatus >= 200 && $legacyStatus < 300 && $legacyData === true) {
            SessionStatusStore::resolve_opencode_question($sessionId, $requestId);
            return ['ok' => true, 'message' => 'Question answered'];
        }
        if (self::is_question_not_found($legacyStatus, $legacyData)) {
            SessionStatusStore::resolve_opencode_question($sessionId, $requestId);
            return ['ok' => false, 'message' => 'Rejected: this question was already resolved'];
        }
        return ['ok' => false, 'message' => self::reply_error('v1', $legacyStatus, $legacyError)];
    }

    /**
     * @param array<int, mixed> $questions
     * @param array<int, mixed> $labels
     * @return array<int, array<int, string>>|null
     */
    public static function validated_answers(array $questions, array $labels): ?array
    {
        if ($questions === [] || count($labels) !== count($questions)) return null;
        $answers = [];
        foreach ($questions as $index => $question) {
            if (!is_array($question) || !array_key_exists($index, $labels)) return null;
            $selected = is_array($labels[$index]) ? $labels[$index] : [$labels[$index]];
            if ($selected === []) return null;

            $optionLabels = [];
            foreach (is_array($question['options'] ?? null) ? $question['options'] : [] as $option) {
                if (is_array($option) && is_string($option['label'] ?? null)) $optionLabels[] = $option['label'];
            }
            $multiple = ($question['multiple'] ?? $question['multiSelect'] ?? false) === true;
            $custom = ($question['custom'] ?? true) === true;
            if (!$multiple && count($selected) !== 1) return null;

            $normalized = [];
            foreach ($selected as $label) {
                if (!is_string($label) || trim($label) === '' || in_array($label, $normalized, true)) return null;
                if (!in_array($label, $optionLabels, true) && !$custom) return null;
                $normalized[] = $label;
            }
            $answers[] = $normalized;
        }
        return $answers;
    }

    /** @return array{question:string, context:string, options:array<int, array{number:int, label:string}>, multi_question:bool, tool_name:string} */
    public static function to_prompt(array $pending): array
    {
        $questions = is_array($pending['questions'] ?? null) ? $pending['questions'] : [];
        $first = is_array($questions[0] ?? null) ? $questions[0] : [];
        $options = [];
        foreach (is_array($first['options'] ?? null) ? $first['options'] : [] as $idx => $option) {
            if (is_array($option) && is_string($option['label'] ?? null)) $options[] = ['number' => $idx + 1, 'label' => $option['label']];
        }
        if (($first['custom'] ?? true) === true && !self::options_already_include_freetext($options)) {
            $options[] = ['number' => count($options) + 1, 'label' => 'Type something'];
        }
        $context = is_string($first['header'] ?? null) ? $first['header'] : '';
        if (count($questions) > 1) $context = trim($context . "\n(+ " . (count($questions) - 1) . ' more question(s))');

        return [
            'question' => is_string($first['question'] ?? null) ? $first['question'] : 'Waiting on input',
            'context' => $context,
            'options' => $options,
            'multi_question' => count($questions) > 1,
            'tool_name' => 'question',
        ];
    }

    private static function reply_error(string $protocol, int $status, string $error): string
    {
        $detail = trim($error);
        return "Question reply failed via {$protocol} (HTTP {$status})" . ($detail === '' ? '' : ": {$detail}");
    }

    private static function is_question_not_found(int $status, mixed $data): bool
    {
        if ($status !== 404 || !is_array($data)) return false;
        if (($data['name'] ?? null) === 'QuestionNotFoundError') return true;
        return ($data['error']['name'] ?? null) === 'QuestionNotFoundError';
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array{0: int, 1: mixed, 2: string}
     */
    private static function request(string $method, string $path, ?array $body = null, ?string $directory = null): array
    {
        $cmd = ['curl', '--silent', '--show-error', '--max-time', '5', '--request', $method, '--write-out', "\n%{http_code}"];
        $bodyFile = null;
        if ($body !== null) {
            $payload = json_encode($body);
            if (!is_string($payload)) return [0, null, 'could not encode request'];
            $bodyFile = tempnam(sys_get_temp_dir(), 'sessioneer-ocq-');
            if ($bodyFile === false) return [0, null, 'could not create request body'];
            file_put_contents($bodyFile, $payload);
            $cmd[] = '--header'; $cmd[] = 'Content-Type: application/json';
            $cmd[] = '--data'; $cmd[] = '@' . $bodyFile;
        }
        if ($directory !== null) {
            $cmd[] = '--header';
            $cmd[] = 'x-opencode-directory: ' . rawurlencode($directory);
        }
        $cmd[] = Config::opencode_server_url() . $path;
        $result = ProcessRunner::run_process($cmd);
        if ($bodyFile !== null) @unlink($bodyFile);
        if ($result['exit'] !== 0) return [0, null, trim($result['stderr'])];

        $parts = explode("\n", trim($result['stdout']));
        $status = (int) array_pop($parts);
        $raw = implode("\n", $parts);
        $data = $raw === '' ? null : json_decode($raw, true);
        return [$status, $data, $raw];
    }

    /** @param array<int, array{number:int,label:string}> $options */
    private static function options_already_include_freetext(array $options): bool
    {
        foreach ($options as $option) if (stripos($option['label'], 'type something') !== false) return true;
        return false;
    }
}
