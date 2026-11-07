<?php

declare(strict_types=1);

namespace HostAgent\Services;

use App\AdvisePolicy;

/**
 * Resume-or-fresh verdict for an idle session, computed from files and numbers
 * only: nothing here talks to the session being judged, because waking a cold
 * session is the very cost the verdict is meant to avoid.
 */
final class AdviseService
{
    public const RESUME_MAX_TOKENS = 60000;
    public const FRESH_MIN_TOKENS = 150000;
    // The prompt cache outlives this only on the longer-TTL plans; a miss costs a full re-write either way.
    public const CACHE_TTL_SECONDS = AdvisePolicy::CACHE_TTL_SECONDS;
    // Typical size of a written handoff plus the context it makes the new session re-read.
    public const HANDOFF_TOKENS = 6000;
    public const QUOTA_PARK_PCT = AdvisePolicy::QUOTA_PARK_PCT;

    private const TAIL_BYTES = 262144;

    /**
     * Context size and last activity of a Claude transcript, from the last
     * main-thread assistant usage (same sum as ContextUsage::claude_assistant()).
     *
     * @return array{agent:string,context_tokens:int,model:string,last_activity:int}|null
     */
    public static function claude_facts(string $transcriptPath): ?array
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
            if (is_array($event) && ($event['type'] ?? null) === 'assistant' && empty($event['isSidechain'])) {
                $snapshot = ContextUsage::claude_assistant($event, $snapshot) ?? $snapshot;
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
    public static function codex_facts(?array $tokenUsage, string $model, int $lastActivity): ?array
    {
        $tokens = $tokenUsage['last']['totalTokens'] ?? null;
        return is_int($tokens) && $tokens >= 0
            ? ['agent' => 'codex', 'context_tokens' => $tokens, 'model' => $model, 'last_activity' => $lastActivity]
            : null;
    }

    /**
     * @param array{agent:string,context_tokens:int,model:string,last_activity:int}|null $facts null: no readable context
     * @param array{session?:array{pct?:int|float,resets_at?:int},week_all?:array{pct?:int|float,resets_at?:int}}|null $quota the chosen profile's quota
     * @return array<string,mixed>
     */
    public static function advise(?array $facts, ?array $quota, string $conversationId, ?int $now = null): array
    {
        if ($facts === null) {
            return ['verdict' => 'unsupported', 'reason' => 'No readable context size for this session (agent not supported yet, or no transcript).'];
        }

        $now ??= time();
        $context = $facts['context_tokens'];
        $cacheAge = max(0, $now - $facts['last_activity']);
        $cold = $cacheAge >= self::CACHE_TTL_SECONDS;

        if ($context < self::RESUME_MAX_TOKENS) {
            $verdict = 'resume';
            $reason = 'Small context: resuming costs less than writing and re-reading a handoff.';
        } elseif ($context >= self::FRESH_MIN_TOKENS) {
            $verdict = 'fresh';
            $reason = 'Large context: every later turn re-reads it, a handoff is far smaller.';
        } elseif (!$cold) {
            $verdict = 'resume';
            $reason = 'Cache is still warm, so resuming does not re-write the context.';
        } else {
            $verdict = 'judgment';
            $reason = 'Middle band with a cold cache: depends on state a handoff cannot carry.';
        }

        $blocked = false;
        $restartAfter = 0;
        $resetUnknown = false;
        foreach (['session', 'week_all'] as $window) {
            if (($quota[$window]['pct'] ?? 0) >= self::QUOTA_PARK_PCT) {
                $blocked = true;
                $reset = $quota[$window]['resets_at'] ?? null;
                if (!is_int($reset) || $reset <= $now) {
                    $resetUnknown = true;
                } else {
                    $restartAfter = max($restartAfter, $reset);
                }
            }
        }
        $restartAfter = $blocked && !$resetUnknown ? $restartAfter : 0;
        $resetSeconds = $restartAfter > 0 ? $restartAfter - $now : null;
        $shortWait = $resetSeconds !== null && $resetSeconds < AdvisePolicy::QUOTA_SHORT_WAIT_SECONDS;
        if ($blocked) {
            $verdict = 'fresh';
            $reason = $resetSeconds === null
                ? 'This account is near or at quota and its reset time is unknown; take over in another session with quota.'
                : 'This account is near or at quota for another ' . (int) ceil($resetSeconds / 60) . ' minutes; take over in another session with quota.';
        }

        return [
            'verdict' => $verdict,
            'reason' => $reason,
            'agent' => $facts['agent'],
            'model' => $facts['model'],
            'context_tokens' => $context,
            'cache_age_seconds' => $cacheAge,
            'cache_cold' => $cold,
            'cost' => [
                'resume_rewrite_tokens' => $cold ? $context : 0,
                'resume_reread_per_turn' => $context,
                'fresh_handoff_tokens' => self::HANDOFF_TOKENS,
                'fresh_reread_per_turn' => self::HANDOFF_TOKENS,
            ],
            'quota_known' => $quota !== null,
            'quota_blocked' => $blocked,
            'restart_after' => $restartAfter ?: null,
            'quota_reset_seconds' => $resetSeconds,
            'quota_short_wait' => $shortWait,
            'prompts' => [
                'resume' => 'Continue where you stopped: read your last Dibs checkpoint, then carry on with the next step.',
                'fresh' => 'Take over conversation ' . $conversationId . ': read its complete thread and Dibs state, transfer the work safely, and archive it only after the source is idle and claim-free.',
            ],
        ];
    }
}
