<?php

declare(strict_types=1);

/**
 * QuotaLiveStateWriter: the merge rule and per-account keying shared by the
 * statusLine script (host-agent/quota_live_state_write.php, exercised end to
 * end in test_statusline_marker.php) and the headless manager (end to end in
 * test_claude_headless_manager.php). Pure store-level checks here: happy and
 * sad inputs, each asserted as the exact stored outcome.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\QuotaLiveStateWriter;
use HostAgent\Stores\GlobalStateStore;

$realPushSqliteFile = Config::push_sqlite_path();
$fixtureDir = sys_get_temp_dir() . '/sessioneer-test-quota-writer-' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0700, true);
putenv("PUSH_SQLITE_FILE={$fixtureDir}/push.sqlite");

if (Config::push_sqlite_path() === $realPushSqliteFile) {
    fwrite(STDERR, "REFUSING TO RUN: PUSH_SQLITE_FILE resolves to the real host state file.\n");
    exit(1);
}

register_shutdown_function(static function () use ($fixtureDir): void {
    exec('rm -rf ' . escapeshellarg($fixtureDir));
});

echo "merge_bucket\n";
$prev = ['pct' => 40, 'resets_at' => 1000];
assert_equal(['pct' => 55, 'resets_at' => 1000], QuotaLiveStateWriter::merge_bucket(['used_percentage' => 55.4, 'resets_at' => 1000], $prev), 'a higher reading in the same window is taken (rounded)');
assert_equal($prev, QuotaLiveStateWriter::merge_bucket(['used_percentage' => 30.0, 'resets_at' => 1000], $prev), 'a lower reading in the same window is ignored');
assert_equal(['pct' => 3, 'resets_at' => 2000], QuotaLiveStateWriter::merge_bucket(['used_percentage' => 3.0, 'resets_at' => 2000], $prev), 'a moved resets_at is a rollover: the lower reading is taken');
assert_equal(['pct' => 12, 'resets_at' => 500], QuotaLiveStateWriter::merge_bucket(['used_percentage' => 12.0, 'resets_at' => 500], null), 'a first reading is taken as is');
assert_equal($prev, QuotaLiveStateWriter::merge_bucket(null, $prev), 'no reading for a window keeps the previous bucket');
assert_equal(null, QuotaLiveStateWriter::merge_bucket(null, null), 'no reading and no previous bucket stays empty');
assert_equal($prev, QuotaLiveStateWriter::merge_bucket(['used_percentage' => 'lots', 'resets_at' => 1000], $prev), 'a non-numeric percentage is treated as no reading');
assert_equal($prev, QuotaLiveStateWriter::merge_bucket(['used_percentage' => 60.0, 'resets_at' => '1000'], $prev), 'a non-integer resets_at is treated as no reading');
assert_equal(null, QuotaLiveStateWriter::merge_bucket(['used_percentage' => 60.0], null), 'a bucket without resets_at is never stored');
assert_equal(['pct' => 9, 'resets_at' => 77], QuotaLiveStateWriter::merge_bucket(['used_percentage' => 9.0, 'resets_at' => 77], ['pct' => 'x', 'resets_at' => null]), 'a corrupt previous bucket is replaced by a good reading');

echo "record\n";
$before = time();
QuotaLiveStateWriter::record(null, ['used_percentage' => 20.0, 'resets_at' => 100], ['used_percentage' => 5.0, 'resets_at' => 900]);
$state = GlobalStateStore::read(Config::quota_live_state_key());
assert_equal(['pct' => 20, 'resets_at' => 100], $state['session'] ?? null, 'record stores the session bucket under the default key');
assert_equal(['pct' => 5, 'resets_at' => 900], $state['week_all'] ?? null, 'and the week_all bucket');
assert_true(($state['captured_at'] ?? 0) >= $before, 'stamped with the capture time');

QuotaLiveStateWriter::record(null, null, ['used_percentage' => 8.0, 'resets_at' => 900]);
$state = GlobalStateStore::read(Config::quota_live_state_key());
assert_equal(['pct' => 20, 'resets_at' => 100], $state['session'] ?? null, 'a reading with no five_hour window keeps the stored session bucket');
assert_equal(['pct' => 8, 'resets_at' => 900], $state['week_all'] ?? null, 'while updating the week_all one');

QuotaLiveStateWriter::record('work', ['used_percentage' => 61.0, 'resets_at' => 300], null);
$work = GlobalStateStore::read(Config::quota_live_state_key('work'));
assert_equal(['pct' => 61, 'resets_at' => 300], $work['session'] ?? null, 'another account has its own key');
assert_true(!isset($work['week_all']), 'with no week_all bucket when none was ever read');
assert_equal(20, GlobalStateStore::read(Config::quota_live_state_key())['session']['pct'] ?? null, 'and the default account is untouched by it');

test_exit();
