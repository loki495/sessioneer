<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Services\OpenCodeQuestionService;
use HostAgent\Services\OpenCodeTranscriptService;

/**
 * The headless runtime for OpenCode: sessions live in the `opencode serve`
 * process and are driven/observed over its HTTP API - no tmux pane.
 *
 * Lifecycle, status, and send go through OpenCodeServeClient; question
 * detection/answering reuses OpenCodeQuestionService (the proven live
 * `GET /question` surface); transcript reading stays with
 * OpenCodeTranscriptService (opencode.db) because it's the same DB
 * regardless of how the session was spawned.
 *
 * Phase 1 scope: contract + lifecycle/status/send + question prompts.
 * Permission-prompt detection/answering (the phase that needs the
 * permission.ask/`GET /permission` wiring) and a live-status event feed
 * are Phase 3/4 - methods that aren't wired yet return a handled
 * "not wired" failure, never a throw.
 */
class HeadlessRuntime implements RuntimeProvider
{
    private OpenCodeServeClient $client;

    public function __construct(?OpenCodeServeClient $client = null)
    {
        $this->client = $client ?? new OpenCodeServeClient();
    }

    public function id(): string
    {
        return RuntimeType::HEADLESS;
    }

    public function isHeadless(): bool
    {
        return true;
    }

    public function isTmux(): bool
    {
        return false;
    }

    public function create(array $options): array
    {
        $workdir = is_string($options['workdir'] ?? null) ? $options['workdir'] : '';

        if ($workdir === '') {
            return ['ok' => false, 'message' => 'create() requires a workdir'];
        }

        $title = is_string($options['title'] ?? null) ? $options['title'] : null;

        $result = $this->client->create_session($workdir, $title);

        if ($result['ok'] !== true) {
            return ['ok' => false, 'message' => $result['message'] ?? 'Create failed'];
        }

        return [
            'ok' => true,
            'id' => $result['id'] ?? null,
            'session' => $result['session'] ?? null,
        ];
    }

    public function list(): array
    {
        return $this->client->list_sessions();
    }

    public function detail(string $sessionRef): array
    {
        return $this->client->get_session($sessionRef);
    }

    public function kill(string $sessionRef): array
    {
        return $this->client->delete_session($sessionRef);
    }

    public function status(string $sessionRef): array
    {
        return $this->client->get_status($sessionRef);
    }

    public function send_message(string $sessionRef, string $text, array $attachmentPaths = []): array
    {
        // Attachment relay for a headless opencode session isn't wired yet
        // (Phase 3) - OpenCodeServeClient refuses it; the model it sends with
        // is resolved there (session's own model, else the serve default).
        return $this->client->send_message($sessionRef, $text, $attachmentPaths);
    }

    public function pending_prompt(string $sessionRef): ?array
    {
        return $this->client->pending_blocked($sessionRef);
    }

    public function answer_prompt(string $sessionRef, array $answers): array
    {
        $pending = $this->pending_prompt($sessionRef);

        if ($pending === null) {
            return ['ok' => false, 'message' => 'Rejected: no prompt is currently pending on this session'];
        }

        // Permission prompt: POST the v2 permission reply endpoint.
        // Note: on opencode 1.18.21, GET /permission returns empty and the
        // permission.ask hook is dormant, so this endpoint may also fail
        // (PermissionNotFoundError). The Sessioneer plugin's intent mechanism
        // requires the hook to fire, which it doesn't on this version.
        // Permission detection works (via PermissionStore fed by the
        // permission.asked event), but answering from Sessioneer is not yet
        // possible — the user must answer in the opencode webui/TUI.
        if ($pending['tool_name'] === 'permission') {
            $reply = match ($answers['option'] ?? 0) {
                1 => 'once',
                2 => 'always',
                3 => 'reject',
                default => null,
            };

            if ($reply === null) {
                return ['ok' => false, 'message' => 'Rejected: option number does not match this permission prompt'];
            }

            $requestId = is_string($pending['request_id'] ?? null) ? $pending['request_id'] : null;

            if ($requestId === null) {
                return ['ok' => false, 'message' => 'Rejected: permission request id unknown'];
            }

            return $this->client->answer_permission($sessionRef, $requestId, $reply);
        }

        // Multi-question: labels passed straight through (['answers'=>[[label],…]]).
        $labels = $answers['answers'] ?? null;
        $requestId = is_string($pending['request_id'] ?? null) ? $pending['request_id'] : null;

        if (is_array($labels)) {
            $normalized = $this->normalize_question_answers($pending, $labels);
            if ($normalized === null) {
                return ['ok' => false, 'message' => 'Rejected: answers do not match this question'];
            }
            return $this->client->answer_question($sessionRef, $normalized, $requestId);
        }

        // Free-text answer ("type your own answer" option) - the custom text
        // becomes the answer label for the opencode question reply.
        $text = $answers['text'] ?? null;

        if (is_string($text) && trim($text) !== '') {
            return $this->client->answer_question($sessionRef, [[trim($text)]], $requestId);
        }

        // Single question answered by option number (the Sessioneer canonical shape):
        // resolve that number to the pending prompt's label, then answer by
        // label.
        $option = $answers['option'] ?? null;

        if ($option !== null && (is_int($option) || ctype_digit((string)$option))) {
            foreach ($pending['options'] as $o) {
                if ($o['number'] === (int)$option) {
                    if ($o['label'] === 'Type something') {
                        return ['ok' => false, 'message' => 'Rejected: a custom answer requires text'];
                    }
                    return $this->client->answer_question($sessionRef, [$o['label']], $requestId);
                }
            }

            return ['ok' => false, 'message' => 'Rejected: option number does not match this prompt\'s options'];
        }

        return ['ok' => false, 'message' => 'Rejected: no valid answer supplied'];
    }

    /**
     * The shared multi-question card predates OpenCode and submits option
     * positions (or {text: ...}); OpenCode requires labels. A label-shaped
     * caller remains supported for the runtime API.
     *
     * @param array<string,mixed> $pending
     * @param array<int,mixed> $answers
     * @return array<int, array<int,string>|string>|null
     */
    private function normalize_question_answers(array $pending, array $answers): ?array
    {
        $questions = is_array($pending['tool_input']['questions'] ?? null) ? $pending['tool_input']['questions'] : [];
        if (count($questions) !== count($answers)) return null;

        $normalized = [];
        foreach ($questions as $index => $question) {
            if (!is_array($question) || !array_key_exists($index, $answers)) return null;
            $answer = $answers[$index];
            if (is_array($answer) && array_key_exists('text', $answer)) {
                $text = $answer['text'];
                if (!is_string($text) || trim($text) === '') return null;
                $normalized[] = trim($text);
                continue;
            }

            $selected = is_array($answer) ? $answer : [$answer];
            if ($selected === []) return null;
            $labels = [];
            foreach ($selected as $value) {
                if (is_string($value)) {
                    $labels[] = $value;
                    continue;
                }
                if (!is_int($value)) return null;
                $option = $question['options'][(int) $value - 1] ?? null;
                if (!is_array($option) || !is_string($option['label'] ?? null)) return null;
                $labels[] = $option['label'];
            }
            $normalized[] = $labels;
        }
        return $normalized;
    }
}
