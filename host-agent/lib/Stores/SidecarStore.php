<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * Per-session metadata (workdir, spawned_at, agent_session_id) that
 * doesn't live anywhere tmux itself tracks - one row per app-spawned
 * session in the `sidecars` table of Config::sessions_sqlite_path()
 * (tmpfs, wiped on reboot - see that method's own docblock). Only ever
 * set for sessions this app created (see SessionService::create_agent_session()) -
 * a bare/manually-attached session has no sidecar.
 *
 * Backed by plain JSON files (one per session, plus separate .status.json/
 * .pending-tool.json siblings for SessionStatusStore/PendingToolStore)
 * until 2026-08-24 - migrated to SQLite to fix a real read-modify-write
 * race (SessionStatusStore::update_status() specifically, found live
 * 2026-08-23) with a proper atomic UPDATE instead. The migration shipped
 * with a lazy legacy-JSON-file importer for a live, no-downtime cutover;
 * removed once every real session on this machine had migrated (confirmed
 * live 2026-08-24, see CONTRIBUTING.md) - this store is SQLite-only now,
 * no file fallback.
 */
class SidecarStore
{
    private static function db(): \PDO
    {
        $pdo = SqliteDb::connect(Config::sessions_sqlite_path(), SqliteDb::sessions_schema());
        SqliteDb::add_column_if_missing($pdo, 'sidecars', 'agent', 'TEXT');
        SqliteDb::add_column_if_missing($pdo, 'sidecars', 'runtime', 'TEXT');
        SqliteDb::add_column_if_missing($pdo, 'sidecars', 'title', 'TEXT');
        // Added for multi-account support (Dibs plan #230) - the Claude
        // Code profile name (see Config::claude_profile_config()) this
        // session was spawned under, or NULL for a pre-profile row/the
        // default account. A profile name, not a resolved config_dir, so
        // this stays correct if agents.php's own entry for it changes later.
        SqliteDb::add_column_if_missing($pdo, 'sidecars', 'profile', 'TEXT');
        // Transitional: renamed from claude_session_id/spawned_by_csm (Sessioneer
        // rename, 2026-09-01) - CREATE TABLE IF NOT EXISTS alone never retroactively
        // renames a column on a table already created under the old schema. Same
        // add-alongside-not-migrate-in-place approach as the agent/runtime/title
        // columns above; the old columns are left in place, unused, on the live
        // (tmpfs, reboot-wiped) table rather than dropped.
        SqliteDb::add_column_if_missing($pdo, 'sidecars', 'agent_session_id', 'TEXT');
        SqliteDb::add_column_if_missing($pdo, 'sidecars', 'spawned_by_app', 'INTEGER');

        return $pdo;
    }

    /**
     * @return array{workdir:?string, spawned_at:?int, agent_session_id?:?string, spawned_by_app?:bool, agent?:?string, runtime?:?string, title?:?string, profile?:?string}|null
     */
    public static function read_sidecar(string $sessionName): ?array
    {
        $stmt = self::db()->prepare('SELECT workdir, spawned_at, agent_session_id, spawned_by_app, agent, runtime, title, profile FROM sidecars WHERE session_name = ?');
        $stmt->execute([$sessionName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            return [
                'workdir' => $row['workdir'],
                'spawned_at' => $row['spawned_at'] !== null ? (int)$row['spawned_at'] : null,
                'agent_session_id' => $row['agent_session_id'],
                // Genuinely null (key absent), not coerced to false, when
                // never written - callers like session_start.php's hook
                // rely on `$existingSidecar['spawned_by_app'] ?? <default>`
                // falling through when a sidecar was written without this
                // key at all (found live 2026-08-24: coercing to false here
                // made that ?? see an already-"set" value and never fall
                // through to the hook's own SESSIONEER_SESSION_NAME-based default).
                'spawned_by_app' => $row['spawned_by_app'] !== null ? (bool)$row['spawned_by_app'] : null,
                // Added 2026-08-24 (docs/antigravity-adapter-plan.md Phase
                // 0) for multi-agent support - a row written before this
                // column existed reads back null here (add_column_if_missing()
                // never backfills existing rows), which every real caller
                // treats as "claude" (the only agent that existed before
                // this column did), same convention as write_sidecar()'s
                // own default below.
                'agent' => $row['agent'],
                // Added 2026-08-25 (headless-runtime plan Phase 2.5) - a
                // row written before this column existed reads back null,
                // which callers treating a missing runtime as "tmux" rely
                // on (every sidecar predating headless support is a tmux
                // session).
                'runtime' => $row['runtime'],
                // The agent-visible title, populated by the headless sync
                // (sessioneer_headless_sync()) from the serve session's own title;
                // null for pre-headless rows, so callers fall back to a
                // workdir basename.
                'title' => $row['title'],
                // Added for multi-account support (Dibs plan #230). NULL
                // for any row written before this column existed, or for a
                // session spawned under the default account - callers
                // treat a null profile as "use Config's own defaults",
                // same convention runtime/title already use.
                'profile' => $row['profile'],
            ];
        }

        return null;
    }

    /**
     * $data['agent'] defaults to 'claude' when omitted entirely (not
     * array_key_exists-preserved the way spawned_by_app is) - every real
     * caller as of this column's introduction either knows its own agent
     * explicitly (SessionLifecycleService) or reads-and-re-passes the
     * existing sidecar's own agent field to preserve it across a partial
     * update (session_start.php, self_heal_agent_session_id() - same
     * established pattern those already use for workdir/spawned_at). This
     * default only matters for a caller that genuinely never mentions
     * agent at all, which today means "it's a Claude Code sidecar" - the
     * only kind that existed before AgentAdapter did.
     */
    public static function write_sidecar(string $sessionName, array $data): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO sidecars (session_name, workdir, spawned_at, agent_session_id, spawned_by_app, agent, runtime, title, profile)
             VALUES (:session_name, :workdir, :spawned_at, :agent_session_id, :spawned_by_app, :agent, :runtime, :title, :profile)
             ON CONFLICT(session_name) DO UPDATE SET
                workdir = excluded.workdir,
                spawned_at = excluded.spawned_at,
                agent_session_id = excluded.agent_session_id,
                spawned_by_app = excluded.spawned_by_app,
                agent = excluded.agent,
                runtime = excluded.runtime,
                title = excluded.title,
                profile = excluded.profile'
        );

        $stmt->execute([
            ':session_name' => $sessionName,
            ':workdir' => $data['workdir'] ?? null,
            ':spawned_at' => $data['spawned_at'] ?? null,
            ':agent_session_id' => $data['agent_session_id'] ?? null,
            // NULL, not 0, when the key is genuinely absent - see
            // read_sidecar()'s own comment on why this three-state
            // (true/false/absent) distinction matters to callers.
            ':spawned_by_app' => array_key_exists('spawned_by_app', $data) ? (!empty($data['spawned_by_app']) ? 1 : 0) : null,
            ':agent' => $data['agent'] ?? 'claude',
            // Bare NULL when the key is absent - callers reading it back as
            // null (see read_sidecar()) treat that as "tmux", which is the
            // only runtime every pre-headless sidecar belongs to.
            ':runtime' => $data['runtime'] ?? null,
            ':title' => $data['title'] ?? null,
            ':profile' => $data['profile'] ?? null,
        ]);
    }

    public static function delete_sidecar(string $sessionName): void
    {
        $stmt = self::db()->prepare('DELETE FROM sidecars WHERE session_name = ?');
        $stmt->execute([$sessionName]);
    }

    /**
     * A session can die on its own (crash, host reboot, bad cwd) without ever
     * going through kill_agent_session(), leaving its sidecar/status/pending-tool
     * rows behind. Since this runs on every listing anyway, prune anything
     * whose session no longer exists rather than letting them accumulate.
     */
    public static function prune_orphaned_sidecars(array $liveSessionNames): void
    {
        $db = self::db();
        $placeholders = implode(',', array_fill(0, count($liveSessionNames), '?'));

        foreach (['sidecars', 'session_status', 'pending_tools'] as $table) {
            // Runtime metadata, not an agent-specific id prefix, is the
            // authority. OpenCode ids happen to be ses_*, while Codex thread
            // ids are UUIDs and would otherwise be mistaken for dead tmux
            // rows and pruned on every dashboard poll.
            $guard = $table === 'sidecars'
                ? " AND COALESCE(runtime, 'tmux') != 'headless'"
                : " AND session_name NOT IN (SELECT session_name FROM sidecars WHERE runtime = 'headless')";

            if ($liveSessionNames === []) {
                $sql = "DELETE FROM {$table} WHERE 1=1{$guard}";
            } else {
                $sql = "DELETE FROM {$table} WHERE session_name NOT IN ({$placeholders}){$guard}";
            }

            $stmt = $db->prepare($sql);
            $stmt->execute($liveSessionNames);
        }
    }

    /**
     * Every sidecar row with a given runtime - the headless listing / prune
     * reads from here rather than re-hitting `opencode serve` on each poll.
     *
     * @return array<int, array{session_name:string, workdir:?string, spawned_at:?int, agent_session_id:?string, agent:?string, runtime:?string, title:?string}>
     */
    public static function list_runtime_sidecars(string $runtime): array
    {
        $stmt = self::db()->prepare('SELECT session_name, workdir, spawned_at, agent_session_id, agent, runtime, title FROM sidecars WHERE runtime = ?');
        $stmt->execute([$runtime]);
        $rows = [];

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $rows[] = [
                'session_name' => (string)$row['session_name'],
                'workdir' => $row['workdir'],
                'spawned_at' => $row['spawned_at'] !== null ? (int)$row['spawned_at'] : null,
                'agent_session_id' => $row['agent_session_id'],
                'agent' => $row['agent'],
                'runtime' => $row['runtime'],
                'title' => $row['title'],
            ];
        }

        return $rows;
    }

    /**
     * Finds the tmux session NAME bound to a given agent_session_id (the
     * agent-generated ses_* id). OpenCode's plugin reports permissions keyed
     * by ses_*; Sessioneer tracks them under oc-* tmux names, so this reverses that
     * join. Returns null for an id no sidecar is bound to (not a Sessioneer-tracked
     * session, or the id is the harness's own claude id).
     */
    public static function find_by_agent_session_id(string $agentSessionId): ?string
    {
        if ($agentSessionId === '') {
            return null;
        }

        $stmt = self::db()->prepare('SELECT session_name FROM sidecars WHERE agent_session_id = ? LIMIT 1');
        $stmt->execute([$agentSessionId]);
        $row = $stmt->fetch(\PDO::FETCH_NUM);

        return $row !== false ? (string)$row[0] : null;
    }
}
