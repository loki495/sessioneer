<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * The most recent PreToolUse hook payload recorded for a session (see
 * host-agent/hooks/pre_tool_use.php) - one row per session in the
 * `pending_tools` table of Config::sessions_sqlite_path() (see
 * SidecarStore's own docblock for why this migrated off a plain JSON file,
 * and why its lazy legacy-file importer was later removed),
 * always overwritten by the latest tool call, never appended. Only
 * meaningful alongside a pane-detected blocking prompt (see PromptParser::
 * augment_prompt_with_pending_tool()); by itself this says nothing about
 * whether that tool call actually ended up needing approval.
 */
class PendingToolStore
{
    private static function db(): \PDO
    {
        return SqliteDb::connect(Config::sessions_sqlite_path(), SqliteDb::sessions_schema());
    }

    /**
     * @return array{tool_name:?string, tool_input:?array, written_at:?int}|null
     */
    public static function read_pending_tool(string $sessionName): ?array
    {
        $stmt = self::db()->prepare('SELECT tool_name, tool_input_json, written_at FROM pending_tools WHERE session_name = ?');
        $stmt->execute([$sessionName]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row !== false) {
            return [
                'tool_name' => $row['tool_name'],
                'tool_input' => $row['tool_input_json'] !== null ? json_decode($row['tool_input_json'], true) : null,
                'written_at' => $row['written_at'] !== null ? (int)$row['written_at'] : null,
            ];
        }

        return null;
    }

    public static function write_pending_tool(string $sessionName, array $data): void
    {
        $stmt = self::db()->prepare(
            'INSERT INTO pending_tools (session_name, tool_name, tool_input_json, written_at)
             VALUES (:session_name, :tool_name, :tool_input_json, :written_at)
             ON CONFLICT(session_name) DO UPDATE SET
                tool_name = excluded.tool_name,
                tool_input_json = excluded.tool_input_json,
                written_at = excluded.written_at'
        );

        $stmt->execute([
            ':session_name' => $sessionName,
            ':tool_name' => $data['tool_name'] ?? null,
            ':tool_input_json' => isset($data['tool_input']) ? json_encode($data['tool_input']) : null,
            ':written_at' => $data['written_at'] ?? time(),
        ]);
    }

    public static function delete_pending_tool(string $sessionName): void
    {
        $stmt = self::db()->prepare('DELETE FROM pending_tools WHERE session_name = ?');
        $stmt->execute([$sessionName]);
    }
}
