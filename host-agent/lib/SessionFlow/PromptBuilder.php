<?php

declare(strict_types=1);

namespace HostAgent\SessionFlow;

/**
 * Prompts a session-flow step sends to another session. Pure and static: every
 * value that lands in a prompt is validated first, so a note, id or path cannot
 * smuggle a second line, a slash command or shell syntax into the receiver.
 */
final class PromptBuilder
{
    private const MAX_LENGTH = 8000;

    public static function plan(string $planId, string $note = ''): string
    {
        self::digits($planId, 'plan id');
        $text = "Continue Dibs plan {$planId}. Read the plan, claim it, then work from todo_next.";
        if ($note !== '') {
            $text .= ' ' . self::line($note, 'note');
        }

        return self::limit($text);
    }

    public static function handoff(string $taskId): string
    {
        self::digits($taskId, 'task id');

        return self::limit("Continue from the handoff in Dibs task {$taskId}. Read it and claim the task. "
            . 'Re-verify its State against git, the tests and Dibs before acting, and ask only what is not under Decisions made. '
            . 'Then continue its Next steps. Archive the source session once it holds no claim.');
    }

    /** `$agent` and `$profile` form the slash command's source argument: "<agent> [<profile>] <id>". */
    public static function takeover(string $source, ?string $transcriptPath = null, ?string $profile = null, string $agent = 'claude'): string
    {
        self::token($agent, 'agent');
        $isUuid = preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $source) === 1;
        $isHex = preg_match('/^[0-9a-f]+$/i', $source) === 1;
        $isSes = preg_match('/^ses_[A-Za-z0-9]+$/', $source) === 1;
        if (!$isUuid && !$isHex && !$isSes) {
            throw new \InvalidArgumentException('source must be a hex id, a UUID or a ses_ token');
        }

        $args = $agent . ($profile !== null ? ' ' . self::token($profile, 'profile') : '') . ' ' . $source;
        $text = 'Use the global /takeover skill for source ' . $args
            . '. Read the entire source conversation and Dibs state, transfer its work and claims safely, then archive the old session only when it is idle, claim-free, and a cold session can continue from Dibs. Continue its work.';
        if ($transcriptPath !== null) {
            $text .= ' Source transcript: ' . self::line($transcriptPath, 'transcript path') . '.';
        }

        return self::limit($text);
    }

    public static function rotateRequest(string $receiver, ?string $model, ?string $effort, ?string $profile): string
    {
        return self::limit('/rotate ' . self::receiverArgs($receiver, $model, $effort, $profile));
    }

    public static function textInstruction(string $receiver, ?string $model, ?string $effort, ?string $profile): string
    {
        return self::limit('Write a handoff and rotate this session to a fresh one: ' . self::receiverArgs($receiver, $model, $effort, $profile) . '.');
    }

    /** Free text for a receiver with no other context; the 8000-character limit applies to the text itself. */
    public static function selfContained(string $text): string
    {
        if (trim($text) === '') {
            throw new \InvalidArgumentException('text is empty');
        }
        if (strlen($text) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('text is longer than ' . self::MAX_LENGTH . ' characters');
        }

        return "Complete this task. It is self-contained; no earlier conversation is available.\n\n" . $text;
    }

    private static function receiverArgs(string $receiver, ?string $model, ?string $effort, ?string $profile): string
    {
        $parts = ['agent=' . self::token($receiver, 'receiver')];
        foreach (['model' => $model, 'effort' => $effort, 'profile' => $profile] as $name => $value) {
            if ($value !== null) {
                $parts[] = $name . '=' . self::token($value, $name);
            }
        }

        return implode(' ', $parts);
    }

    private static function digits(string $value, string $name): void
    {
        if (preg_match('/^[0-9]+$/', $value) !== 1) {
            throw new \InvalidArgumentException("{$name} must be digits only");
        }
    }

    private static function token(string $value, string $name): string
    {
        if (preg_match('/^[A-Za-z0-9._:-]+$/', $value) !== 1) {
            throw new \InvalidArgumentException("{$name} has characters outside A-Z a-z 0-9 . _ : -");
        }

        return $value;
    }

    /** A single-line value without quotes or backticks. */
    private static function line(string $value, string $name): string
    {
        if ($value === '' || preg_match('/[\r\n"\'`]/', $value) === 1) {
            throw new \InvalidArgumentException("{$name} must be non-empty, one line, without quotes or backticks");
        }

        return $value;
    }

    private static function limit(string $text): string
    {
        if (strlen($text) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException('prompt is longer than ' . self::MAX_LENGTH . ' characters');
        }

        return $text;
    }
}
