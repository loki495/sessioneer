<?php

declare(strict_types=1);

namespace HostAgent\SessionFlow;

use HostAgent\Services\ContextUsage;

/**
 * Context size and last activity of a session, from files and stored numbers
 * only: nothing here talks to the session.
 */
final class FlowFacts
{
    private const TAIL_BYTES = 262144;

    /**
     * From the newest main-thread assistant usage or compact_boundary in the
     * transcript tail. A compaction after the last assistant entry has already
     * shrunk the context, so it counts at its postTokens (as the context-budget
     * hook does); it carries no model, so the preceding assistant entry supplies it.
     *
     * @return array{agent:string,context_tokens:int,model:string,last_activity:int}|null
     */
    public static function claude(string $transcriptPath): ?array
    {
        $mtime = @filemtime($transcriptPath);
        $size = @filesize($transcriptPath);
        $fh = @fopen($transcriptPath, 'rb');
        if ($mtime === false || $size === false || $fh === false) {
            return null;
        }
        fseek($fh, max(0, $size - self::TAIL_BYTES));
        $tail = (string) stream_get_contents($fh);
        fclose($fh);

        $snapshot = null;
        foreach (explode("\n", $tail) as $line) {
            $event = json_decode($line, true);
            if (!is_array($event)) {
                continue;
            }
            if (($event['type'] ?? null) === 'assistant' && empty($event['isSidechain'])) {
                $snapshot = ContextUsage::claude_assistant($event, $snapshot) ?? $snapshot;
            } elseif (($event['subtype'] ?? null) === 'compact_boundary' && is_int($event['compactMetadata']['postTokens'] ?? null)) {
                $snapshot = ['model' => $snapshot['model'] ?? '', 'input_tokens' => $event['compactMetadata']['postTokens']];
            }
        }

        return $snapshot === null ? null : [
            'agent' => 'claude',
            'context_tokens' => (int) $snapshot['input_tokens'],
            'model' => (string) $snapshot['model'],
            'last_activity' => $mtime,
        ];
    }

    /**
     * @param array<string,mixed>|null $tokenUsage the bridge's stored tokenUsage
     * @return array{agent:string,context_tokens:int,model:string,last_activity:int}|null
     */
    public static function codex(?array $tokenUsage, string $model, int $lastActivity): ?array
    {
        $tokens = $tokenUsage['last']['totalTokens'] ?? null;
        return is_int($tokens) && $tokens >= 0
            ? ['agent' => 'codex', 'context_tokens' => $tokens, 'model' => $model, 'last_activity' => $lastActivity]
            : null;
    }

    /**
     * The stored reading wins; a Claude session with none falls back to its transcript.
     *
     * @param array<string,mixed>|null $contextUsage stored Claude context_usage
     * @param array<string,mixed>|null $tokenUsage stored Codex token_usage
     * @return array{agent:string,context_tokens:int,model:string,last_activity:int}|null
     */
    public static function stored(?array $contextUsage, ?array $tokenUsage, string $agent, string $model, int $lastActivity, ?string $transcriptPath): ?array
    {
        if ($agent === 'codex') {
            return self::codex($tokenUsage, $model, $lastActivity);
        }
        if ($agent !== 'claude') {
            return null;
        }
        $tokens = $contextUsage['input_tokens'] ?? null;
        if (is_int($tokens) && $tokens >= 0) {
            return ['agent' => 'claude', 'context_tokens' => $tokens, 'model' => (string) ($contextUsage['model'] ?? $model), 'last_activity' => $lastActivity];
        }

        return $transcriptPath === null ? null : self::claude($transcriptPath);
    }
}
