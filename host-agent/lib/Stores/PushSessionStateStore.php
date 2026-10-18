<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * sessionName => last-known push state ('blocked'|'working'|'idle') plus
 * the timestamp it's been in that state continuously since - the "since"
 * half is what lets PushDeliveryService::check_and_send_pushes() tell a
 * session that just finished a genuinely long task apart from one that
 * only worked for a couple of seconds. Lives in the `push_session_state`
 * table of Config::push_sqlite_path() (see PushSubscriptionStore's own
 * docblock for the migration off a plain JSON file, and its
 * later removal of the lazy legacy-file importer).
 */
class PushSessionStateStore
{
    private static function db(): \PDO
    {
        return SqliteDb::connect(Config::push_sqlite_path(), SqliteDb::push_schema());
    }

    /**
     * @return array<string, array{state:string, since:int}>
     */
    public static function read_push_session_state(): array
    {
        $rows = self::db()->query('SELECT session_name, state, since FROM push_session_state')->fetchAll(\PDO::FETCH_ASSOC);
        $result = [];

        foreach ($rows as $row) {
            $result[$row['session_name']] = ['state' => $row['state'], 'since' => (int)$row['since']];
        }

        return $result;
    }

    /**
     * @param array<string, array{state:string, since:int}> $state
     */
    public static function write_push_session_state(array $state): void
    {
        $db = self::db();
        $db->beginTransaction();
        $db->exec('DELETE FROM push_session_state');

        $stmt = $db->prepare('INSERT INTO push_session_state (session_name, state, since) VALUES (?, ?, ?)');

        foreach ($state as $sessionName => $entry) {
            $stmt->execute([$sessionName, $entry['state'], $entry['since']]);
        }

        $db->commit();
    }

    /**
     * Test-only equivalent of the old @unlink(push-session-state.json)
     * reset between independent test scenarios in the same file - clears just
     * this table, not the whole push.sqlite DB (push_subscriptions/
     * push_quota_state live there too).
     */
    public static function clear_all(): void
    {
        self::db()->exec('DELETE FROM push_session_state');
    }
}
