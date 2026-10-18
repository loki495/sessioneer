<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Pure, dependency-free translation between codex app-server's server-request
 * protocol (item/tool/requestUserInput, item/*RequestApproval) and Sessioneer's own
 * blocked-prompt shape (BlockedPromptView/session.js's `question`/`options`/
 * `tool_input`/`prompt_questions` fields). Extracted out of
 * host-agent/codex_bridge.php - that script spawns a real
 * `codex app-server --stdio` process and binds a UNIX socket the instant it's
 * required, so these two functions had no way to be unit-tested while they
 * lived there as bare functions. No behavior change from the original
 * normalize_prompt() side; prompt_response()'s item/tool/requestUserInput
 * branch is a real bug fix - see resolve_answer_texts()'s own docblock.
 */
final class CodexPromptProtocol
{
    /**
     * Normalizes one codex app-server server-request into Sessioneer's blocked-
     * prompt shape. item/tool/requestUserInput (Codex's AskUserQuestion
     * equivalent) becomes tool_name 'question', carrying every question
     * (not just the first) in tool_input.questions for the multi-question
     * form to read; the three *RequestApproval methods become tool_name
     * 'permission' with the fixed four-option accept/acceptForSession/
     * decline/cancel menu.
     *
     * @param array<string,mixed> $params
     * @return array<string,mixed>
     */
    public static function normalize_prompt(string $method, array $params): array
    {
        if ($method === 'item/tool/requestUserInput') {
            $questions = is_array($params['questions'] ?? null) ? $params['questions'] : [];
            $first = is_array($questions[0] ?? null) ? $questions[0] : [];
            $options = [];
            foreach (($first['options'] ?? []) as $index => $option) {
                if (is_array($option) && is_string($option['label'] ?? null)) {
                    $options[] = ['number' => $index + 1, 'label' => $option['label']];
                }
            }
            return [
                'tool_name' => 'question',
                'question' => (string)($first['question'] ?? 'Codex needs input'),
                'context' => '',
                'options' => $options,
                'multi_question' => count($questions) > 1,
                'is_folder_trust' => false,
                'tool_input' => ['questions' => $questions],
                'request_id' => (string)($params['itemId'] ?? ''),
            ];
        }

        $command = is_string($params['command'] ?? null) ? $params['command'] : '';
        $reason = is_string($params['reason'] ?? null) ? $params['reason'] : '';
        $question = $method === 'item/fileChange/requestApproval'
            ? 'Allow Codex to change files?'
            : ($method === 'item/permissions/requestApproval' ? 'Grant additional permissions?' : 'Allow Codex to run this command?');

        return [
            'tool_name' => 'permission',
            'question' => $question,
            'context' => $command !== '' ? $command : $reason,
            'options' => [
                ['number' => 1, 'label' => 'Allow once'],
                ['number' => 2, 'label' => 'Allow for session'],
                ['number' => 3, 'label' => 'Deny'],
                ['number' => 4, 'label' => 'Deny and stop'],
            ],
            'multi_question' => false,
            'is_folder_trust' => false,
            'tool_input' => $params,
            'request_id' => (string)($params['itemId'] ?? ''),
        ];
    }

    /**
     * Builds the JSON-RPC result codex_bridge.php sends back to app-server
     * for a pending server request, from the answers Sessioneer's frontend
     * collected. `$pending` is the bridge's own stored
     * {request_id, method, params, prompt} entry (see codex_bridge.php's
     * $pendingPrompts); `$answers` is whatever the client posted -
     * `['option' => int]` for a permission-type prompt, or
     * `['answers' => [...]]` for a question-type one (the shape
     * collectMultiQuestionAnswers() in public/js/common.js builds: one entry
     * per question, each an int ordinal, an array of int ordinals
     * (multi-select), or `{'text': '...'}`).
     *
     * @param array{request_id:int|string,method:string,params:array<string,mixed>,prompt:array<string,mixed>} $pending
     * @param array<string,mixed> $answers
     * @return array<string,mixed>|null
     */
    public static function prompt_response(array $pending, array $answers): ?array
    {
        $method = $pending['method'];
        if ($method === 'item/tool/requestUserInput') {
            $questions = is_array($pending['params']['questions'] ?? null) ? $pending['params']['questions'] : [];
            $supplied = is_array($answers['answers'] ?? null) ? $answers['answers'] : [];
            $mapped = [];
            foreach ($questions as $index => $question) {
                if (!is_array($question) || !is_string($question['id'] ?? null)) continue;
                $mapped[$question['id']] = ['answers' => self::resolve_answer_texts($question, $supplied[$index] ?? null)];
            }
            return ['answers' => (object)$mapped];
        }

        $decision = match ((int)($answers['option'] ?? 0)) {
            1 => 'accept',
            2 => 'acceptForSession',
            3 => 'decline',
            4 => 'cancel',
            default => null,
        };
        if ($decision === null) return null;

        if ($method === 'item/permissions/requestApproval') {
            if ($decision !== 'accept' && $decision !== 'acceptForSession') return null;
            return [
                'permissions' => $pending['params']['permissions'] ?? (object)[],
                'scope' => $decision === 'acceptForSession' ? 'session' : 'turn',
            ];
        }
        return ['decision' => $decision];
    }

    /**
     * Resolves one question's submitted answer to the real text app-server
     * expects, instead of the bug this replaces: the old bridge code did
     * `array_map('strval', $value)` over whatever the frontend sent, so a
     * selected option's 1-based ordinal (what collectMultiQuestionAnswers()
     * actually submits - see its own docblock in public/js/common.js) became
     * the literal string "1"/"2" instead of the option's real label text.
     * Free text happened to survive only by accident (strval() over
     * {text: "foo"} yields ["foo"]); that shape is now read explicitly
     * instead of relying on the coincidence.
     *
     * @param array<string,mixed> $question one of $pending['params']['questions']
     * @param mixed $value the submitted value for this question: an int
     *   ordinal (single-select), an array of int ordinals (multi-select -
     *   not reachable from Codex's own questions today since they never set
     *   multiSelect, but the frontend form supports it generically), a
     *   {'text': string} free-text shape, or null (no answer supplied)
     * @return array<int,string>
     */
    private static function resolve_answer_texts(array $question, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        $options = is_array($question['options'] ?? null) ? $question['options'] : [];

        if (is_array($value) && is_string($value['text'] ?? null)) {
            return [$value['text']];
        }

        if (is_array($value)) {
            $texts = [];
            foreach ($value as $ordinal) {
                $texts[] = self::resolve_option_label($options, $ordinal);
            }
            return $texts;
        }

        return [self::resolve_option_label($options, $value)];
    }

    /**
     * Looks up a 1-based option ordinal's label text. Never crashes on an
     * out-of-range index or a malformed/missing options array - falls back
     * defensively to the raw stringified ordinal so a shape mismatch still
     * produces SOME answer rather than throwing mid-turn.
     *
     * @param array<int,mixed> $options
     */
    private static function resolve_option_label(array $options, mixed $ordinal): string
    {
        if (is_numeric($ordinal)) {
            $index = (int)$ordinal - 1;
            $label = is_array($options[$index] ?? null) ? ($options[$index]['label'] ?? null) : null;
            if (is_string($label)) {
                return $label;
            }
        }

        return is_scalar($ordinal) ? (string)$ordinal : '';
    }
}
