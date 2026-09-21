<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\GlobalStateStore;

/**
 * The one place Claude's account-wide rate-limit readings are merged into
 * GlobalStateStore (Config::quota_live_state_key()), which
 * QuotaService::quota_from_statusline_state() and the push quota checks read.
 *
 * Two independent writers feed it: the statusLine script of a tmux/TUI
 * session (host-agent/quota_live_state_write.php) and the headless manager
 * (ClaudeHeadlessManager, from `rate_limit_event`s) - a headless `claude -p`
 * process never renders a status line, so without the second writer the
 * footer freezes at whatever the last TUI session saw.
 */
class QuotaLiveStateWriter
{
    /**
     * Merges a fresh reading into the stored state of one account and stamps
     * it captured now. Each bucket is `{used_percentage: 0..100, resets_at:
     * epoch}` or null when that window was not part of the reading (the
     * previous value is kept).
     *
     * @param array{used_percentage?:mixed, resets_at?:mixed}|null $fiveHour
     * @param array{used_percentage?:mixed, resets_at?:mixed}|null $sevenDay
     */
    public static function record(?string $profile, ?array $fiveHour, ?array $sevenDay): void
    {
        $key = Config::quota_live_state_key($profile);
        $prev = GlobalStateStore::read($key) ?? [];
        $merged = [];

        $session = self::merge_bucket($fiveHour, $prev['session'] ?? null);
        if ($session !== null) {
            $merged['session'] = $session;
        }

        $weekAll = self::merge_bucket($sevenDay, $prev['week_all'] ?? null);
        if ($weekAll !== null) {
            $merged['week_all'] = $weekAll;
        }

        $merged['captured_at'] = time();

        GlobalStateStore::write($key, $merged);
    }

    /**
     * One bucket's merge rule, shared by session (five_hour) and week_all
     * (seven_day) - a genuine window rollover (resets_at moved) always takes
     * the new reading; otherwise only a HIGHER percentage within the same
     * window is trusted, since usage only climbs within one window and a
     * lower reading from a DIFFERENT session's stale render would otherwise
     * make the number visibly jump backward.
     *
     * @param array{used_percentage?:mixed, resets_at?:mixed}|null $newBucket
     * @param array{pct?:mixed, resets_at?:mixed}|null $prevBucket
     * @return array{pct:int, resets_at:int}|null
     */
    public static function merge_bucket(?array $newBucket, ?array $prevBucket): ?array
    {
        if ($newBucket === null || !is_numeric($newBucket['used_percentage'] ?? null) || !is_int($newBucket['resets_at'] ?? null)) {
            return $prevBucket !== null && is_int($prevBucket['pct'] ?? null) && is_int($prevBucket['resets_at'] ?? null)
                ? ['pct' => $prevBucket['pct'], 'resets_at' => $prevBucket['resets_at']]
                : null;
        }

        $newPct = (int)round((float)$newBucket['used_percentage']);
        $newResetsAt = $newBucket['resets_at'];

        if ($prevBucket === null || !is_int($prevBucket['pct'] ?? null) || !is_int($prevBucket['resets_at'] ?? null)
            || $newPct >= $prevBucket['pct'] || $newResetsAt !== $prevBucket['resets_at']) {
            return ['pct' => $newPct, 'resets_at' => $newResetsAt];
        }

        return ['pct' => $prevBucket['pct'], 'resets_at' => $prevBucket['resets_at']];
    }
}
