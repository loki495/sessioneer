#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Standalone entry point run periodically by the (opt-in, not
 * auto-enabled - see install.sh) sessioneer-antigravity-quota-check systemd
 * timer. Runs `agy -p "/usage" --output-format json` - a real, confirmed-
 * free headless call (live-verified 2026-08-24: duration_seconds=0, all-
 * zero token usage, no real model turn or transcript entry - see
 * docs/history/antigravity-adapter-plan.md's quota research) - and writes the
 * parsed result to GlobalStateStore under Config::
 * antigravity_quota_live_state_key().
 *
 * Unlike Claude Code's quota_live_state_write.php, no merge-against-
 * previous logic is needed here: that file protects against several
 * independent Claude Code sessions' statuslines racing each other with
 * partial/stale readings, but this script is the ONLY writer (one timer,
 * one process at a time), and every successful poll is already the full,
 * current, account-wide truth from Antigravity's own quota system - a
 * plain overwrite is correct.
 *
 * A no-op (exits 0 immediately) if ANTIGRAVITY_BIN isn't configured yet,
 * same "installing the timer before configuring the feature is harmless"
 * convention push_trigger.php already uses for VAPID keys.
 */

require __DIR__ . '/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\ProcessRunner;
use HostAgent\Stores\GlobalStateStore;

if (Config::antigravity_bin() === '') {
    exit(0);
}

// --print-timeout is Antigravity's own CLI flag (default 5m) - a short
// value here is a safety net against a hung/unreachable call blocking
// this timer tick indefinitely, not a real expectation of ever needing
// it (a slash command like /usage is local and instant in practice).
$result = ProcessRunner::run_process([
    Config::antigravity_bin(), '-p', '/usage', '--output-format', 'json', '--print-timeout', '30s',
]);

if ($result['exit'] !== 0) {
    exit(0);
}

$decoded = json_decode($result['stdout'], true);

if (!is_array($decoded) || ($decoded['status'] ?? null) !== 'SUCCESS') {
    exit(0);
}

$groups = $decoded['command']['data']['groups'] ?? null;

if (!is_array($groups)) {
    exit(0);
}

$state = ['captured_at' => time()];

foreach ($groups as $group) {
    if (!is_array($group)) {
        continue;
    }

    $groupName = is_string($group['name'] ?? null) ? $group['name'] : null;
    $buckets = is_array($group['buckets'] ?? null) ? $group['buckets'] : [];

    foreach ($buckets as $bucket) {
        if (!is_array($bucket)) {
            continue;
        }

        $id = is_string($bucket['id'] ?? null) ? $bucket['id'] : null;
        $remainingFraction = $bucket['remaining_fraction'] ?? null;
        $resetTime = is_string($bucket['reset_time'] ?? null) ? $bucket['reset_time'] : null;

        if ($id === null || !is_numeric($remainingFraction) || $resetTime === null) {
            continue;
        }

        $resetsAt = strtotime($resetTime);

        if ($resetsAt === false) {
            continue;
        }

        // Stored as USED percentage (climbing toward 100 = closer to the
        // limit), matching Claude Code's own quota_live_state convention
        // (QuotaService, the push-notification near/over thresholds) -
        // Antigravity's own API reports the opposite orientation
        // (remaining_fraction, how much is LEFT), converted here so any
        // future shared display code only ever has one convention to
        // handle rather than two inverted ones.
        $state[$id] = [
            'pct' => (int)round((1 - (float)$remainingFraction) * 100),
            'resets_at' => $resetsAt,
            'group_name' => $groupName,
        ];
    }
}

GlobalStateStore::write(Config::antigravity_quota_live_state_key(), $state);
