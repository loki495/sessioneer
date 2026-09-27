<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Services\PromptParser;

/**
 * Translates between the RAW pending prompt a ClaudeHeadlessManager holds (a
 * `can_use_tool` request) and Sessioneer's canonical prompt shape the dashboard
 * already renders and answers for tmux sessions.
 *
 * Nothing here invents a menu: a permission prompt's options come from the
 * SAME builder the hook-fed tmux path uses (PromptParser::
 * build_options_from_permission_suggestions), and a chosen option is mapped
 * back to an allow/deny through that path's own label classifier, so both
 * runtimes always agree on what "option 2" means.
 */
final class ClaudeHeadlessPromptProtocol
{
    private const DENY_MESSAGE = 'The user declined this action.';

    private const PLAN_DENY_MESSAGE = 'The user did not approve this plan yet. Keep planning.';

    /**
     * @param array<string, mixed> $raw the manager's pending prompt
     * @return array<string, mixed>|null canonical prompt (plus request_id), null when unusable
     */
    public static function canonical_prompt(array $raw): ?array
    {
        $tool = is_string($raw['tool_name'] ?? null) ? $raw['tool_name'] : '';
        $input = is_array($raw['tool_input'] ?? null) ? $raw['tool_input'] : [];
        $requestId = is_string($raw['request_id'] ?? null) ? $raw['request_id'] : null;

        if ($tool === '') {
            return null;
        }

        if ($tool === 'AskUserQuestion') {
            $questions = is_array($input['questions'] ?? null) ? array_values($input['questions']) : [];
            $first = is_array($questions[0] ?? null) ? $questions[0] : [];

            return [
                'question' => is_string($first['question'] ?? null) ? $first['question'] : 'Waiting on input',
                'context' => '',
                'options' => [],
                // No pane to fall back on, so the structured form is the only rendering.
                'multi_question' => true,
                'is_folder_trust' => false,
                'tool_name' => $tool,
                'tool_input' => $input,
                'request_id' => $requestId,
            ];
        }

        if ($tool === 'ExitPlanMode') {
            return [
                'question' => 'Ready to proceed with this plan?',
                'context' => is_string($input['plan'] ?? null) ? $input['plan'] : '',
                'options' => [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
                'multi_question' => false,
                'is_folder_trust' => false,
                'tool_name' => $tool,
                'tool_input' => $input,
                'request_id' => $requestId,
            ];
        }

        $prompt = PromptParser::build_prompt_from_hook_status([
            'tool_name' => $tool,
            'tool_input' => $input,
            'permission_suggestions' => $raw['permission_suggestions'] ?? [],
        ]);

        return $prompt === null ? null : $prompt + ['request_id' => $requestId];
    }

    /**
     * Turns the user's canonical answer into the control-response payload the
     * manager forwards to Claude ({behavior: allow|deny, ...}).
     *
     * @param array<string, mixed> $raw the manager's pending prompt
     * @param array<string, mixed> $answers ['option' => int, 'text' => ?string] or ['answers' => [...]]
     * @return array<string, mixed>|null null when the answer does not fit this prompt
     */
    public static function response(array $raw, array $answers): ?array
    {
        $tool = is_string($raw['tool_name'] ?? null) ? $raw['tool_name'] : '';
        $input = is_array($raw['tool_input'] ?? null) ? $raw['tool_input'] : [];
        $text = is_string($answers['text'] ?? null) ? trim($answers['text']) : '';

        if ($tool === 'AskUserQuestion') {
            return self::question_response($input, $answers, $text);
        }

        $option = $answers['option'] ?? null;

        if (!is_int($option) && !(is_string($option) && ctype_digit($option))) {
            return null;
        }

        $option = (int)$option;

        if ($tool === 'ExitPlanMode') {
            return match ($option) {
                1 => ['behavior' => 'allow', 'updatedInput' => $input],
                2 => ['behavior' => 'deny', 'message' => $text !== '' ? $text : self::PLAN_DENY_MESSAGE],
                default => null,
            };
        }

        $suggestions = is_array($raw['permission_suggestions'] ?? null) ? $raw['permission_suggestions'] : [];
        $label = null;

        foreach (PromptParser::build_options_from_permission_suggestions($suggestions) as $candidate) {
            if ($candidate['number'] === $option) {
                $label = $candidate['label'];
                break;
            }
        }

        if ($label === null) {
            return null;
        }

        return match (PromptParser::classify_permission_option_intent($label)) {
            'yes_once' => ['behavior' => 'allow', 'updatedInput' => $input],
            'yes_always' => self::remember_response($input, $suggestions),
            'no' => ['behavior' => 'deny', 'message' => $text !== '' ? $text : self::DENY_MESSAGE],
            default => null,
        };
    }

    /**
     * "Yes, and don't ask again": allow, plus the same suggestion the menu
     * offered (the builder's own pick - most specific, non-setMode first).
     *
     * @param array<string, mixed> $input
     * @param array<int, mixed> $suggestions
     * @return array<string, mixed>
     */
    private static function remember_response(array $input, array $suggestions): array
    {
        $chosen = null;

        foreach ([false, true] as $wantSetMode) {
            foreach ($suggestions as $suggestion) {
                if (is_array($suggestion) && (($suggestion['type'] ?? null) === 'setMode') === $wantSetMode) {
                    $chosen = $suggestion;
                    break 2;
                }
            }
        }

        $response = ['behavior' => 'allow', 'updatedInput' => $input];

        if ($chosen !== null) {
            $response['updatedPermissions'] = [$chosen];
        }

        return $response;
    }

    /**
     * Answers keyed by QUESTION TEXT with the chosen label(s), or free text.
     * Accepts the shapes the dashboard's shared question card submits: option
     * positions (1-based), labels, {text: ...}, or a list of either for a
     * multi-select question.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $answers
     * @return array<string, mixed>|null
     */
    private static function question_response(array $input, array $answers, string $text): ?array
    {
        $questions = is_array($input['questions'] ?? null) ? array_values($input['questions']) : [];

        if ($questions === []) {
            return null;
        }

        $given = $answers['answers'] ?? null;

        if (!is_array($given)) {
            // A lone question answered by option number and/or free text.
            $option = $answers['option'] ?? null;
            $given = [$text !== '' ? ['text' => $text] : $option];
        }

        $given = array_values($given);
        $map = [];

        foreach ($questions as $index => $question) {
            if (!is_array($question) || !is_string($question['question'] ?? null) || !array_key_exists($index, $given)) {
                return null;
            }

            $value = self::answer_value($question, $given[$index]);

            if ($value === null) {
                return null;
            }

            $map[$question['question']] = $value;
        }

        if (count($given) !== count($questions)) {
            return null;
        }

        return ['behavior' => 'allow', 'updatedInput' => ['questions' => $input['questions'], 'answers' => $map]];
    }

    /**
     * @param array<string, mixed> $question
     * @return string|array<int, string>|null
     */
    private static function answer_value(array $question, mixed $answer): string|array|null
    {
        if (is_array($answer) && array_key_exists('text', $answer)) {
            $free = is_string($answer['text']) ? trim($answer['text']) : '';

            return $free !== '' ? $free : null;
        }

        $options = is_array($question['options'] ?? null) ? array_values($question['options']) : [];
        $multi = ($question['multiSelect'] ?? false) === true;
        $picked = is_array($answer) ? array_values($answer) : [$answer];
        $labels = [];

        foreach ($picked as $one) {
            if (is_string($one) && !ctype_digit($one)) {
                $labels[] = $one;
                continue;
            }

            if (!is_int($one) && !(is_string($one) && ctype_digit($one))) {
                return null;
            }

            $option = $options[(int)$one - 1] ?? null;

            if (!is_array($option) || !is_string($option['label'] ?? null)) {
                return null;
            }

            $labels[] = $option['label'];
        }

        if ($labels === [] || (!$multi && count($labels) !== 1)) {
            return null;
        }

        return $multi ? $labels : $labels[0];
    }
}
