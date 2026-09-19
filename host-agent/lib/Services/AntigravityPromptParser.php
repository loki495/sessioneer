<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Antigravity equivalent of PromptParser::parse_blocking_prompt() - unlike
 * Claude Code, Antigravity has no PermissionRequest-style hook at all (see
 * docs/antigravity-adapter-plan.md's Phase 3 research: confirmed live no
 * such second hook exists), so a blocked prompt can ONLY ever be detected
 * by reading the live pane, for every prompt shape, not just the two
 * carve-outs SessionService::build_session_entry() needs for Claude Code.
 *
 * Returns the SAME canonical shape PromptParser::parse_blocking_prompt()
 * does ({question, context, options, multi_question, is_folder_trust}) -
 * BlockedPromptView/session.js's rendering and PromptInteractionService::
 * answer_prompt()'s answering logic are both already generic over that
 * shape (numbered options, answered by sending the chosen digit), so
 * reusing it here needed no new UI or new answer-side wiring at all, just
 * this parser plus the small agent-branch in build_session_entry() and
 * answer_prompt()/send_escape() that calls it instead of Claude's own for
 * an antigravity session.
 *
 * Supports both:
 * - Legacy Antigravity 1.1.x: "Do you want to proceed?" with 4 numbered options
 * - Modern Antigravity 1.2.x: Action-specific tool approval prompts ("Run this command?",
 *   "Allow creation of this file?", "Accept this file edit?", "Allow access to this file?",
 *   "Allow access to this URL?", "Allow calling this tool?", etc.) typically with 2 numbered options
 *   ("1. Yes, run command", "2. No, cancel" / "1. Yes, allow creation", "2. No, deny creation", etc.).
 */
class AntigravityPromptParser
{
    /**
     * Known tool confirmation question strings from Antigravity CLI.
     */
    public const KNOWN_QUESTIONS = [
        'Do you want to proceed?',
        'Run this command?',
        'Allow creation of this file?',
        'Accept this file edit?',
        'Allow access to this file?',
        'Allow access to this URL?',
        'Allow calling this tool?',
        'Allow administrator elevation?',
        'Allow remote debugging?',
        'Allow sandbox bypass for command execution?',
        'Send input to this task?',
        'Overwrite settings.json?',
    ];

    /**
     * Checks if a line matches a recognized Antigravity tool confirmation question.
     */
    public static function is_prompt_question(string $line): bool
    {
        $trimmed = trim($line);
        if (!str_ends_with($trimmed, '?')) {
            return false;
        }

        if (in_array($trimmed, self::KNOWN_QUESTIONS, true)) {
            return true;
        }

        // Future action prompts may vary in their object text, but an
        // arbitrary pane question is not evidence of a permission request.
        return (bool)preg_match('/^(?:Run|Allow|Accept|Send|Overwrite|Execute|Delete|Create|Edit|Install|Enable|Disable|Approve)\b.+\?$/u', $trimmed);
    }

    /**
     * @return array{question:string, context:string, options: array<int, array{number:int, label:string}>, multi_question:bool, is_folder_trust:bool}|null
     */
    public static function parse_blocking_prompt(string $paneContent): ?array
    {
        $lines = explode("\n", $paneContent);
        $questionIndex = null;
        $options = [];

        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (self::is_prompt_question($lines[$i])) {
                $parsedOptions = self::parse_options($lines, $i);
                if ($parsedOptions !== []) {
                    $questionIndex = $i;
                    $options = $parsedOptions;
                    break;
                }
            }
        }

        if ($questionIndex === null) {
            return null;
        }

        // "Requesting permission for:" or file/command context above the question,
        // up to the blank line separating it from previous output, bounded by
        // BLOCKING_PROMPT_CONTEXT_WINDOW.
        $contextLines = [];

        for ($i = $questionIndex - 1; $i >= 0 && count($contextLines) < PromptParser::BLOCKING_PROMPT_CONTEXT_WINDOW; $i--) {
            $trimmed = trim($lines[$i]);

            if ($trimmed === '' && $contextLines !== []) {
                break;
            }

            if ($trimmed === '') {
                continue;
            }

            array_unshift($contextLines, $trimmed);
        }

        return [
            'question' => trim($lines[$questionIndex]),
            'context' => implode("\n", $contextLines),
            'options' => $options,
            'multi_question' => false,
            'is_folder_trust' => false,
        ];
    }

    /**
     * @param string[] $lines
     * @return array<int, array{number:int, label:string}>
     */
    private static function parse_options(array $lines, int $questionIndex): array
    {
        $options = [];
        $number = 1;

        for ($i = $questionIndex + 1; $i < count($lines); $i++) {
            $rawLine = $lines[$i];
            $trimmed = trim(ltrim($rawLine, "> \t"));

            // Skip leading blank lines between question and options if any
            if ($options === [] && $trimmed === '') {
                continue;
            }

            // Stop if we hit terminal footer navigation/escape lines or decorative rules
            if (
                str_starts_with($trimmed, '↑')
                || str_starts_with($trimmed, 'esc to cancel')
                || str_starts_with($trimmed, '─')
            ) {
                break;
            }

            if (preg_match('/^' . $number . '\.\s*(.+)$/u', $trimmed, $matches)) {
                $options[] = ['number' => $number, 'label' => trim($matches[1])];
                $number++;
            } elseif ($options !== [] && $trimmed !== '') {
                $lastIndex = count($options) - 1;
                $options[$lastIndex]['label'] = trim($options[$lastIndex]['label'] . ' ' . $trimmed);
            } else {
                break;
            }
        }

        return $options;
    }
}
