#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Standalone entry point invoked by the statusLine script this app
 * installs (see StatuslineMarkerService::quota_capture_block()) once per
 * Claude Code status-line render, instead of that script writing
 * QuotaService::quota_from_statusline_state()'s state directly via jq -
 * moved off a plain JSON file to GlobalStateStore (Config::
 * quota_live_state_key(), Config::push_sqlite_path()) 2026-08-24, same as
 * every other piece of this app's own state (see SqliteDb's own
 * docblock). The merge logic (only move a bucket's pct DOWN when its
 * resets_at also moved forward - a genuine window rollover - rather than
 * whichever session's script happened to fire most recently) is the exact
 * same rule the old jq filter used, just in PHP now, guarded by SQLite's
 * own transaction instead of a shell tmp-file-then-mv.
 *
 * Reads the new rate_limits reading as JSON on stdin (the bash side still
 * does the cheap jq extraction/shape-narrowing before invoking this, no
 * point re-deriving that in PHP too):
 *   {"five_hour": {...}|null, "seven_day": {...}|null}
 * where each bucket, if present, has "used_percentage" (float) and
 * "resets_at" (int, real epoch - Claude Code's own statusLine JSON field).
 *
 * Never writes anything to stdout (would pollute the actual rendered
 * status line) and always exits 0, same "never disrupt the statusline"
 * convention the jq version it replaces already followed (its own `2>/dev/null`
 * on every step).
 */

require __DIR__ . '/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Stores\GlobalStateStore;

$input = stream_get_contents(STDIN);
$new = json_decode((string)$input, true);

if (!is_array($new)) {
    exit(0);
}

// $CLAUDE_CONFIG_DIR is inherited from whichever Claude Code process invoked
// the statusLine script that shells out to this file - set per-profile by
// ClaudeCodeAdapter::build_spawn_argv() at session spawn time, so this is
// the same live signal that tells us which account's statusline just
// rendered, with no extra plumbing needed to pass it explicitly.
$profile = Config::claude_profile_for_config_dir((string)(getenv('CLAUDE_CONFIG_DIR') ?: ''));
$key = Config::quota_live_state_key($profile);
$prev = GlobalStateStore::read($key) ?? [];

/**
 * One bucket's own merge rule, shared by session (five_hour) and week_all
 * (seven_day) below - a genuine window rollover (resets_at moved) always
 * takes the new reading; otherwise only a HIGHER percentage within the
 * same window is trusted, since usage only climbs within one window and a
 * lower reading from a DIFFERENT session's stale statusline render would
 * otherwise make the number visibly jump backward.
 *
 * @param array{used_percentage?:mixed, resets_at?:mixed}|null $newBucket
 * @param array{pct?:mixed, resets_at?:mixed}|null $prevBucket
 * @return array{pct:int, resets_at:int}|null
 */
function merge_quota_bucket(?array $newBucket, ?array $prevBucket): ?array
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

$merged = [];

$session = merge_quota_bucket($new['five_hour'] ?? null, $prev['session'] ?? null);
if ($session !== null) {
    $merged['session'] = $session;
}

$weekAll = merge_quota_bucket($new['seven_day'] ?? null, $prev['week_all'] ?? null);
if ($weekAll !== null) {
    $merged['week_all'] = $weekAll;
}

$merged['captured_at'] = time();

GlobalStateStore::write($key, $merged);
