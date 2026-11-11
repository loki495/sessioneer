<?php

declare(strict_types=1);

namespace HostAgent\SessionFlow;

final class FlowDecider
{
    private const INTENTS = ['step-end', 'wait', 'done'];
    private const CLOSE_CALL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $facts
     * @param array<string, array{session_pct: ?int, week_pct: ?int}> $quotaMap
     * @return array<string, mixed>
     */
    public static function decide(array $facts, array $quotaMap, Thresholds $t, ?string $intent, int $now): array
    {
        $warnings = [];
        if ($intent !== null && !in_array($intent, self::INTENTS, true)) {
            $warnings[] = "unknown intent '$intent' ignored";
            $intent = null;
        }

        $ctx = $facts['context_tokens'] ?? null;
        $idle = $facts['idle_seconds'] ?? null;
        $session = $facts['quota']['session_pct'] ?? null;
        $week = $facts['quota']['week_pct'] ?? null;
        $resetAt = $facts['quota']['resets_at'] ?? null;
        $readings = array_filter([$session, $week], static fn ($v) => $v !== null);
        $quota = $readings === [] ? null : max($readings);
        $cold = $idle !== null && $idle >= $t->get('IDLE_MIN') * 60;

        $result = static fn (string $state, string $why, string $trigger = 'none', bool $close = false, ?array $receiver = null, ?int $reset = null, bool $approval = false): array => [
            'state' => $state,
            'why' => $why,
            'trigger' => $trigger,
            'close_call' => $close,
            'receiver' => $receiver,
            'reset_at' => $reset,
            'needs_approval' => $approval,
            'warnings' => $warnings,
        ];

        if ($intent === 'done') {
            return $result('DONE', 'work is finished');
        }
        if ($ctx === null && $idle === null && $quota === null) {
            return $result('UNKNOWN', 'no readable facts');
        }

        $quotaHigh = $quota !== null && $quota >= $t->get('QUOTA_PARK');
        $receiver = $quotaHigh ? self::receiver($quotaMap, $t->get('QUOTA_GO_MAX')) : null;
        $closeCall = $quotaHigh && $resetAt !== null && $resetAt - $now < self::CLOSE_CALL_SECONDS;

        if ($quotaHigh && $receiver === null) {
            return $result('PARK', "quota at $quota% and no receiver has room", 'quota', $closeCall, null, $resetAt);
        }
        if ($intent === 'wait' || ($facts['source']['status'] ?? null) === 'blocked') {
            return $result('WAIT', 'waiting on an answer or an external event');
        }
        if ($ctx !== null && $ctx >= $t->get('CTX_ROTATE')) {
            return $result('ROTATE', "context at $ctx tokens", 'context');
        }
        if ($quotaHigh) {
            return $result('ROTATE', "quota at $quota%; carry the work to {$receiver['key']}", 'quota', $closeCall, $receiver, $resetAt, true);
        }
        if ($cold && $ctx !== null && $ctx >= $t->get('IDLE_FLOOR')) {
            return $result('ROTATE', "cache is cold and context is $ctx tokens", 'cold_cache', true);
        }

        $tightCtx = $ctx !== null && $ctx >= $t->get('CTX_GO_MAX');
        $tightQuota = $quota !== null && $quota >= $t->get('QUOTA_GO_MAX');
        if ($tightCtx || $tightQuota) {
            $trigger = $tightCtx ? 'context' : 'quota';
            if ($intent === 'step-end') {
                return $result('CHECKPOINT', 'checkpoint at the step end', $trigger);
            }

            return $result('WARM_TIGHT', 'running tight on ' . $trigger, $trigger);
        }

        return $result('GO', 'plenty of room');
    }

    /**
     * @param array<string, array{session_pct: ?int, week_pct: ?int}> $quotaMap
     * @return array{key: string, agent: string, profile: string}|null
     */
    private static function receiver(array $quotaMap, int $max): ?array
    {
        foreach ($quotaMap as $key => $pct) {
            $s = $pct['session_pct'] ?? null;
            $w = $pct['week_pct'] ?? null;
            if ($s !== null && $w !== null && $s < $max && $w < $max) {
                [$agent, $profile] = explode('/', (string) $key, 2) + [1 => ''];

                return ['key' => (string) $key, 'agent' => $agent, 'profile' => $profile];
            }
        }

        return null;
    }
}
