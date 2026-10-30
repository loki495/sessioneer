<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\SidecarStore;

/**
 * Fallback store for OpenCode pending permissions.
 *
 * The primary source is the serve's own list, GET /api/permission/request,
 * which v2 scopes by `location.directory` (an unscoped read reports only the
 * server's cwd) - OpenCodeServeClient::pending_permissions() queries it once
 * per live session directory. This store covers a permission that list does
 * not expose, and it is the only path that can answer in-process.
 *
 * A pane footer can be STALE (a resolved dialog still rendered while the
 * session works), so the pane never decides whether a permission is pending.
 *
 * Design (mirrors Sessioneer's Claude-Code hook -> SessionStatusStore pattern):
 *   - The Sessioneer plugin (~/.config/opencode/plugins/sessioneer-permissions.js)
 *     registers `ctx.permission.hook("evaluate")` and
 *     `ctx.event.subscribe(...)`, and writes a JSON record per ses_* id into
 *     Config::opencode_permission_dir() on permission.asked/updated, clearing
 *     it on permission.replied.
 *   - The host-agent reads those records here; to answer, it writes an intent
 *     the plugin applies on its next `evaluate` fire with effect "ask" (so the
 *     answer takes effect in the process that owns the permission).
 *
 * Store shape (one file per ses_* id, e.g. <dir>/ses_abc.json):
 *   { "permission": {pending Permission},  "intent": "allow"|"deny"|null }
 * where the plugin writes `permission` and the host-agent writes/clears `intent`.
 */
class PermissionStore
{
    private static function dir(): string
    {
        return Config::opencode_permission_dir();
    }

    private static function file_for(string $sessionId): string
    {
        if (!preg_match('/^ses_[A-Za-z0-9]+$/', $sessionId)) {
            throw new \InvalidArgumentException('Not a valid OpenCode session id: ' . $sessionId);
        }

        return self::dir() . '/' . $sessionId . '.json';
    }

    /**
     * Records a pending permission (called by the host-agent when it wants to
     * stage one for a session, or refreshes existing state). The plugin owns
     * the authoritative `permission` object; this is only used to seed state
     * if the plugin hasn't yet (defensive).
     *
     * @param array<string, mixed> $permission
     */
    public static function write_pending_permission(string $sessionId, array $permission): void
    {
        $path = self::file_for($sessionId);
        $current = self::read_file($path);
        $current['permission'] = $permission;

        self::write_file($path, $current);
    }

    /**
     * Reads the currently-pending permission for a ses_* id, or null. Only
     * trusts a record the plugin marked as pending - a record with no pending
     * permission (or one whose `permission` is null) is not a block.
     *
     * @return array<string, mixed>|null
     */
    public static function read_pending_permission(string $sessionId): ?array
    {
        if (!preg_match('/^ses_[A-Za-z0-9]+$/', $sessionId)) {
            return null;
        }

        $data = self::read_file(self::file_for($sessionId));

        return is_array($data['permission'] ?? null) ? $data['permission'] : null;
    }

    /**
     * Pending permission resolved back to a Sessioneer tmux session NAME, via the
     * sidecar join (plugin reports ses_*; Sessioneer tracks oc-* names). Returns
     * null when the id isn't bound to a Sessioneer-tracked session.
     */
    public static function find_by_session_id(string $sessionId): ?string
    {
        return SidecarStore::find_by_agent_session_id($sessionId);
    }

    /** Writes the answer intent for the plugin to consume on its next fire. */
    public static function write_answer_intent(string $sessionId, string $status): void
    {
        $path = self::file_for($sessionId);
        $current = self::read_file($path);
        $current['intent'] = in_array($status, ['allow', 'deny'], true) ? $status : null;

        self::write_file($path, $current);
    }

    /**
     * Reads and clears the pending answer intent (called by the plugin, not
     * the host-agent - provided here for tests and for the host-agent to
     * inspect). Returns the intent, or null.
     */
    public static function consume_answer_intent(string $sessionId): ?string
    {
        $path = self::file_for($sessionId);
        $data = self::read_file($path);
        $intent = is_string($data['intent'] ?? null) ? $data['intent'] : null;
        $data['intent'] = null;

        self::write_file($path, $data);

        return $intent;
    }

    /** Removes a session's record entirely (permission resolved / session gone). */
    public static function delete_permission(string $sessionId): void
    {
        if (!preg_match('/^ses_[A-Za-z0-9]+$/', $sessionId)) {
            return;
        }

        @unlink(self::file_for($sessionId));
    }

    /**
     * @return array{permission:?array, intent:?string}
     */
    private static function read_file(string $path): array
    {
        if (!is_file($path)) {
            return ['permission' => null, 'intent' => null];
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return ['permission' => null, 'intent' => null];
        }

        $data = json_decode($raw, true);

        if (!is_array($data)) {
            return ['permission' => null, 'intent' => null];
        }

        return [
            'permission' => is_array($data['permission'] ?? null) ? $data['permission'] : null,
            'intent' => is_string($data['intent'] ?? null) ? $data['intent'] : null,
        ];
    }

    private static function write_file(string $path, array $data): void
    {
        $dir = dirname($path);

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        // JSON_PRETTY_PRINT for human-inspectability; the plugin writes the
        // same shape. Atomic write (tmp + rename) so the plugin's concurrent
        // read never sees a half-written file.
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));

        if (file_put_contents($tmp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) === false) {
            @unlink($tmp);

            return;
        }

        chmod($tmp, 0600);
        @rename($tmp, $path);
    }
}
