<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * Persistent, unlike SidecarStore/SessionStatusStore/PendingToolStore's
 * shared DB - a phone's subscription shouldn't need to be redone just
 * because the host rebooted, so this lives in the `push_subscriptions`
 * table of Config::push_sqlite_path() (host-agent/state/, gitignored,
 * not tmpfs). Backed by a plain JSON file until it was migrated to
 * SQLite alongside PushSessionStateStore/PushQuotaStateStore for the same
 * atomicity reasons as SidecarStore's own migration; its lazy legacy-file
 * importer was later removed once every real subscription had cut over.
 */
class PushSubscriptionStore
{
    private static function db(): \PDO
    {
        return SqliteDb::connect(Config::push_sqlite_path(), SqliteDb::push_schema());
    }

    /**
     * @return array<int, array{endpoint:string, keys:array{p256dh:string, auth:string}}>
     */
    public static function read_push_subscriptions(): array
    {
        $rows = self::db()->query('SELECT endpoint, p256dh, auth FROM push_subscriptions')->fetchAll(\PDO::FETCH_ASSOC);

        return array_map(
            static fn(array $row): array => ['endpoint' => $row['endpoint'], 'keys' => ['p256dh' => $row['p256dh'], 'auth' => $row['auth']]],
            $rows
        );
    }

    /**
     * @param array<int, array{endpoint:string, keys:array{p256dh:string, auth:string}}> $subscriptions
     */
    public static function write_push_subscriptions(array $subscriptions): void
    {
        $db = self::db();
        $db->beginTransaction();
        $db->exec('DELETE FROM push_subscriptions');

        $stmt = $db->prepare('INSERT INTO push_subscriptions (endpoint, p256dh, auth) VALUES (?, ?, ?)');

        foreach ($subscriptions as $s) {
            $stmt->execute([$s['endpoint'], $s['keys']['p256dh'], $s['keys']['auth']]);
        }

        $db->commit();
    }

    /**
     * Adds a subscription, or replaces an existing one with the same
     * endpoint (a browser can resubscribe with new keys under the same
     * endpoint - the frontend does this on every page load to self-heal iOS's
     * flaky subscription lifecycle) - endpoint is the table's own primary
     * key, so this is a single atomic upsert, no read-modify-write at all.
     *
     * @param array{endpoint?:mixed, keys?:mixed} $subscription
     */
    public static function add_push_subscription(array $subscription): bool
    {
        $endpoint = (string)($subscription['endpoint'] ?? '');
        $keys = $subscription['keys'] ?? null;

        if ($endpoint === '' || !is_array($keys) || !is_string($keys['p256dh'] ?? null) || !is_string($keys['auth'] ?? null)) {
            return false;
        }

        $stmt = self::db()->prepare(
            'INSERT INTO push_subscriptions (endpoint, p256dh, auth) VALUES (:endpoint, :p256dh, :auth)
             ON CONFLICT(endpoint) DO UPDATE SET p256dh = excluded.p256dh, auth = excluded.auth'
        );
        $stmt->execute([':endpoint' => $endpoint, ':p256dh' => $keys['p256dh'], ':auth' => $keys['auth']]);

        return true;
    }

    public static function remove_push_subscription(string $endpoint): void
    {
        $stmt = self::db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
        $stmt->execute([$endpoint]);
    }
}
