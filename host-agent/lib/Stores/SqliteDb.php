<?php

declare(strict_types=1);

namespace HostAgent\Stores;

/**
 * Shared PDO/SQLite connection helper for every Store class that moved off
 * hand-rolled JSON files (2026-08-24) - SidecarStore/SessionStatusStore/
 * PendingToolStore (sessions_sqlite_path(), tmpfs) and
 * PushSubscriptionStore/PushSessionStateStore/PushQuotaStateStore/
 * GlobalStateStore (push_sqlite_path(), persistent). See
 * Config::sessions_sqlite_path()/push_sqlite_path() for why these are two
 * separate DB files, not one.
 *
 * global_state (GlobalStateStore) is the generic key -> JSON-value catch-
 * all for the handful of single-blob global concerns that don't warrant
 * their own dedicated table/columns the way the others above do (each was
 * its own plain JSON file before this) - PushDeliveryService's two
 * check-status heartbeats, and quota_live_state (written by
 * host-agent/quota_live_state_write.php, invoked from the shell
 * statusLine script instead of that script writing JSON directly - see
 * that file's own docblock for why). Everything this app's own PHP/hooks
 * write now goes through SQLite, full stop - no plain JSON state files
 * left anywhere under this app's control (Andres's own call, 2026-08-24:
 * simpler to reason about than a permanent legacy-file fallback, and this
 * app has no other real installs yet for such a fallback to actually
 * protect).
 *
 * Connections are cached per absolute path for the lifetime of this PHP
 * process - host-agent spawns one fresh process per connection (see
 * agent.php's own docblock), so this only ever avoids re-opening/re-
 * PRAGMA-ing the SAME file twice within a single request when more than
 * one Store backed by it is touched (e.g. SessionService::build_session_entry()
 * reading both the sidecar and the status row).
 */
class SqliteDb
{
    /** @var array<string, \PDO> */
    private static array $connections = [];

    /**
     * WAL (Write-Ahead Logging) journal mode instead of SQLite's default
     * rollback-journal - lets readers and a writer proceed concurrently
     * instead of a writer blocking every reader for its whole transaction,
     * important here since host-agent's one-process-per-connection model
     * means many independent short-lived PHP processes (a hook firing, a
     * dashboard poll, another hook firing) can genuinely overlap in time.
     * busy_timeout makes a writer that DOES have to wait for another
     * writer retry for up to 5s instead of immediately throwing "database
     * is locked" - real contention between two hooks firing a few
     * milliseconds apart is exactly the case this whole migration exists
     * to handle correctly rather than racily.
     */
    public static function connect(string $path, string $schemaSql): \PDO
    {
        if (isset(self::$connections[$path])) {
            return self::$connections[$path];
        }

        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        $pdo = new \PDO('sqlite:' . $path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA journal_mode=WAL');
        $pdo->exec('PRAGMA busy_timeout=5000');
        $pdo->exec($schemaSql);

        self::$connections[$path] = $pdo;

        return $pdo;
    }

    /**
     * One-off transitional migration for a column added to an existing
     * table after real rows already exist (sidecars.agent, added
     * 2026-08-24 for multi-agent support - see
     * docs/history/antigravity-adapter-plan.md Phase 0) - CREATE TABLE IF NOT
     * EXISTS alone never retroactively adds a column to a table that was
     * already created under the old schema. sidecars is tmpfs (wiped on
     * reboot, see Config::sessions_sqlite_path()), so this self-resolves
     * on the next reboot regardless, but a running instance shouldn't
     * start failing every sidecar write until then. Cheap: ADD COLUMN
     * fails harmlessly (caught, ignored) once the column already exists,
     * same "duplicate column name" every SQLite version reports - no need
     * to PRAGMA table_info() first just to avoid it.
     */
    public static function add_column_if_missing(\PDO $pdo, string $table, string $column, string $definition): void
    {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (\PDOException $e) {
            // Already has the column - the expected steady-state case for
            // every connection after the first one following a deploy.
        }
    }

    /**
     * Test-only: drops every cached connection so a test that points these
     * paths at a fresh fixture location (putenv() + a new tmp file) doesn't
     * keep reading/writing through a PDO handle opened against the PREVIOUS
     * test's path - real production code never needs this (one process per
     * request, per agent.php's own model, so the cache never outlives a
     * single request there anyway).
     */
    public static function reset_connections_for_tests(): void
    {
        self::$connections = [];
    }

    public static function sessions_schema(): string
    {
        return <<<'SQL'
            CREATE TABLE IF NOT EXISTS sidecars (
                session_name TEXT PRIMARY KEY,
                workdir TEXT,
                spawned_at INTEGER,
                agent_session_id TEXT,
                spawned_by_app INTEGER,
                agent TEXT
            );
            CREATE TABLE IF NOT EXISTS session_status (
                session_name TEXT PRIMARY KEY,
                status TEXT,
                blocked_json TEXT,
                mode TEXT,
                last_message TEXT,
                last_turn_error TEXT,
                updated_at INTEGER
            );
            CREATE TABLE IF NOT EXISTS pending_tools (
                session_name TEXT PRIMARY KEY,
                tool_name TEXT,
                tool_input_json TEXT,
                written_at INTEGER
            );
            SQL;
    }

    public static function push_schema(): string
    {
        return <<<'SQL'
            CREATE TABLE IF NOT EXISTS push_subscriptions (
                endpoint TEXT PRIMARY KEY,
                p256dh TEXT NOT NULL,
                auth TEXT NOT NULL
            );
            CREATE TABLE IF NOT EXISTS push_session_state (
                session_name TEXT PRIMARY KEY,
                state TEXT,
                since INTEGER
            );
            CREATE TABLE IF NOT EXISTS push_quota_state (
                bucket_key TEXT PRIMARY KEY,
                pct INTEGER,
                resets_at INTEGER,
                notified_near INTEGER,
                notified_over INTEGER,
                notified_reset INTEGER
            );
            CREATE TABLE IF NOT EXISTS global_state (
                key TEXT PRIMARY KEY,
                value_json TEXT,
                updated_at INTEGER
            );
            SQL;
    }
}
