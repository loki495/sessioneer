<?php

declare(strict_types=1);

namespace HostAgent\Services;

/** Current context snapshots, separate from cumulative billing/token counters. */
final class ContextUsage
{
    /**
     * @param array<string,mixed> $event
     * @param array<string,mixed>|null $previous
     * @return array<string,mixed>|null
     */
    public static function claude_assistant(array $event, ?array $previous): ?array
    {
        if (($event['parent_tool_use_id'] ?? null) !== null) {
            return $previous;
        }

        $model = $event['message']['model'] ?? null;
        $usage = $event['message']['usage'] ?? null;
        if (!is_string($model) || $model === '' || !is_array($usage)) {
            return null;
        }

        $input = 0;
        foreach (['input_tokens', 'cache_creation_input_tokens', 'cache_read_input_tokens'] as $key) {
            $count = $usage[$key] ?? null;
            if (!is_int($count) || $count < 0) {
                return null;
            }
            $input += $count;
        }

        return [
            'model' => $model,
            'input_tokens' => $input,
            'context_window' => ($previous['model'] ?? null) === $model ? ($previous['context_window'] ?? null) : null,
        ];
    }

    /**
     * @param array<string,mixed>|null $snapshot
     * @param array<string,mixed> $event
     * @return array<string,mixed>|null
     */
    public static function claude_result(?array $snapshot, array $event): ?array
    {
        if ($snapshot === null || !is_string($snapshot['model'] ?? null)) {
            return null;
        }

        // modelUsage can include subagents and earlier models. Only the model
        // that produced the latest main-thread input snapshot supplies its window.
        $snapshot['context_window'] = $event['modelUsage'][$snapshot['model']]['contextWindow'] ?? null;
        return $snapshot;
    }

    /** @param array<string,mixed>|null $snapshot */
    public static function claude_percentage(?array $snapshot): ?float
    {
        return self::percentage($snapshot['input_tokens'] ?? null, $snapshot['context_window'] ?? null);
    }

    /** @param array<string,mixed>|null $usage */
    public static function codex_percentage(?array $usage): ?float
    {
        // `total` accumulates across requests; `last` is replaced after each
        // response (and by Codex's retained-history estimate after compaction).
        return self::percentage($usage['last']['totalTokens'] ?? null, $usage['modelContextWindow'] ?? null);
    }

    private static function percentage(mixed $tokens, mixed $window): ?float
    {
        if (!is_int($tokens) || $tokens < 0 || !is_int($window) || $window <= 0) {
            return null;
        }

        return min(100.0, $tokens / $window * 100.0);
    }
}
