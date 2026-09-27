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
 * whichever session's script happened to fire most recently) lives in
 * QuotaLiveStateWriter, shared with the headless manager's writer.
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
use HostAgent\Services\QuotaLiveStateWriter;

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

QuotaLiveStateWriter::record($profile, $new['five_hour'] ?? null, $new['seven_day'] ?? null);
