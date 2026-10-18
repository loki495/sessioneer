<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * bucketKey (session/week_all/week_<plan>) => last-known pct/resets_at plus
 * whether a near/over/reset notification has already fired for the CURRENT
 * window - lets PushDeliveryService::check_and_send_quota_pushes() notify
 * once per crossing rather than on every tick, and re-arm each flag once
 * its window actually rolls over (resets_at passing, for reset; a real
 * rollover or a plan change, for near/over) rather than staying
 * permanently silenced from one earlier crossing. Lives in the
 * `push_quota_state` table of Config::push_sqlite_path() (see
 * PushSubscriptionStore's own docblock for the migration off a
 * plain JSON file, and its later removal of the lazy legacy-file importer
 * - this avoids double notifications via a
 * single atomic write instead of a read-modify-write that a concurrent
 * tick could race).
 *
 * Deliberately its own table, separate from GlobalStateStore's
 * `quota_live_state` key (QuotaService::quota_from_statusline_state()'s
 * own source, written by host-agent/quota_live_state_write.php on every
 * statusLine render) - different write frequency and shape (one row per
 * bucket here, with its own notified-flag columns, vs. one flat JSON blob
 * there), not worth merging into one table just because both are
 * quota-flavored.
 */
class PushQuotaStateStore
{
    private static function db(): \PDO
    {
        return SqliteDb::connect(Config::push_sqlite_path(), SqliteDb::push_schema());
    }

    /**
     * @return array<string, array{pct:int, resets_at:?int, notified_near:bool, notified_over:bool, notified_reset:bool}>
     */
    public static function read_push_quota_state(): array
    {
        $rows = self::db()->query('SELECT bucket_key, pct, resets_at, notified_near, notified_over, notified_reset FROM push_quota_state')->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];

        foreach ($rows as $row) {
            $result[$row['bucket_key']] = [
                'pct' => (int)$row['pct'],
                'resets_at' => $row['resets_at'] !== null ? (int)$row['resets_at'] : null,
                'notified_near' => (bool)$row['notified_near'],
                'notified_over' => (bool)$row['notified_over'],
                'notified_reset' => (bool)$row['notified_reset'],
            ];
        }

        return $result;
    }

    /**
     * @param array<string, array{pct:int, resets_at:?int, notified_near:bool, notified_over:bool, notified_reset:bool}> $state
     */
    public static function write_push_quota_state(array $state): void
    {
        $db = self::db();
        $db->beginTransaction();
        $db->exec('DELETE FROM push_quota_state');

        $stmt = $db->prepare(
            'INSERT INTO push_quota_state (bucket_key, pct, resets_at, notified_near, notified_over, notified_reset)
             VALUES (?, ?, ?, ?, ?, ?)'
        );

        foreach ($state as $bucketKey => $entry) {
            $stmt->execute([
                $bucketKey,
                $entry['pct'],
                $entry['resets_at'],
                !empty($entry['notified_near']) ? 1 : 0,
                !empty($entry['notified_over']) ? 1 : 0,
                !empty($entry['notified_reset']) ? 1 : 0,
            ]);
        }

        $db->commit();
    }

    /**
     * Test-only equivalent of the old @unlink(push-quota-state.json) reset
     * between independent test scenarios in the same file - clears
     * just this table, not the whole push.sqlite DB.
     */
    public static function clear_all(): void
    {
        self::db()->exec('DELETE FROM push_quota_state');
    }
}
