<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/** Short atomic reservations, never a transaction held across agent I/O or waiting. */
final class QuickPromptStore
{
    private static function db(): \PDO
    {
        return SqliteDb::connect(Config::sidecar_dir() . '/quick-prompt.sqlite', '
            CREATE TABLE IF NOT EXISTS requests (id TEXT PRIMARY KEY, fingerprint TEXT NOT NULL, response TEXT);
            CREATE TABLE IF NOT EXISTS sessions (id TEXT PRIMARY KEY, settings TEXT NOT NULL);
        ');
    }

    /** @return array<string, mixed>|null */
    public static function reserve(string $id, string $fingerprint): ?array
    {
        $db = self::db();
        $stmt = $db->prepare('INSERT OR IGNORE INTO requests (id, fingerprint) VALUES (?, ?)');
        $stmt->execute([$id, $fingerprint]);
        if ($stmt->rowCount() === 1) return null;
        $stmt = $db->prepare('SELECT fingerprint, response FROM requests WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return ['fingerprint' => $row['fingerprint'], 'response' => $row['response'] !== null ? json_decode($row['response'], true, 512, JSON_THROW_ON_ERROR) : null];
    }

    /** @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public static function finish(string $id, array $response): array
    {
        $stmt = self::db()->prepare('UPDATE requests SET response = ? WHERE id = ?');
        $stmt->execute([json_encode($response, JSON_THROW_ON_ERROR), $id]);
        return $response;
    }

    /** @param array<string, mixed> $settings */
    public static function track(string $id, array $settings): void
    {
        $stmt = self::db()->prepare('INSERT INTO sessions (id, settings) VALUES (?, ?)');
        $stmt->execute([$id, json_encode($settings, JSON_THROW_ON_ERROR)]);
    }

    /** @return array<string, mixed>|null */
    public static function settings(string $id): ?array
    {
        $stmt = self::db()->prepare('SELECT settings FROM sessions WHERE id = ?');
        $stmt->execute([$id]);
        $raw = $stmt->fetchColumn();
        return is_string($raw) ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;
    }
}
