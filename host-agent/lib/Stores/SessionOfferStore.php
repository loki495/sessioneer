<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * One offer per session: what to do next and the prompt that does it. A hook-made offer never
 * replaces a live agent-made one. Every state change is one SQL statement, so two processes
 * cannot both claim the same launch.
 */
final class SessionOfferStore
{
    private const TTL_SECONDS = 86400;
    private const MAX_PROMPT = 8000;
    private const ORIGINS = ['hook', 'agent'];
    private const LIVE = 'dismissed_at IS NULL AND launched_at IS NULL AND expires_at > :now';

    private static function db(): \PDO
    {
        return SqliteDb::connect(Config::sessions_sqlite_path(), SqliteDb::sessions_schema());
    }

    /**
     * @param array<string, mixed> $decision
     * @return bool false when a live agent offer kept its place
     */
    public static function post(string $session, string $origin, array $decision, ?string $handoffId, ?string $planId, ?string $prompt, int $now): bool
    {
        if ($session === '' || !in_array($origin, self::ORIGINS, true)) {
            throw new \InvalidArgumentException('session name and origin (hook or agent) are required');
        }
        foreach (['handoff id' => $handoffId, 'plan id' => $planId] as $label => $id) {
            if ($id !== null && preg_match('/^[0-9]+$/D', $id) !== 1) {
                throw new \InvalidArgumentException("{$label} must be digits only");
            }
        }
        if ($prompt !== null && strlen($prompt) > self::MAX_PROMPT) {
            throw new \InvalidArgumentException('prompt is longer than ' . self::MAX_PROMPT . ' characters');
        }

        $stmt = self::db()->prepare(
            'INSERT INTO session_offers (session_name, origin, decision_json, handoff_id, plan_id, prompt, created_at, expires_at)
             VALUES (:s, :o, :d, :h, :p, :t, :now, :exp)
             ON CONFLICT(session_name) DO UPDATE SET
                 origin = excluded.origin, decision_json = excluded.decision_json, handoff_id = excluded.handoff_id,
                 plan_id = excluded.plan_id, prompt = excluded.prompt, created_at = excluded.created_at,
                 expires_at = excluded.expires_at, dismissed_at = NULL, launched_at = NULL
             WHERE excluded.origin = \'agent\'
                OR NOT (session_offers.origin = \'agent\' AND session_offers.dismissed_at IS NULL
                        AND session_offers.launched_at IS NULL AND session_offers.expires_at > :now)'
        );
        $stmt->execute([
            ':s' => $session, ':o' => $origin, ':d' => json_encode($decision, JSON_THROW_ON_ERROR), ':h' => $handoffId,
            ':p' => $planId, ':t' => $prompt, ':now' => $now, ':exp' => $now + self::TTL_SECONDS,
        ]);

        return $stmt->rowCount() === 1;
    }

    /** @return array<string, mixed>|null the live offer, or null */
    public static function get(string $session, int $now): ?array
    {
        $stmt = self::db()->prepare('SELECT * FROM session_offers WHERE session_name = :s AND ' . self::LIVE);
        $stmt->execute([':s' => $session, ':now' => $now]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['decision'] = json_decode((string) $row['decision_json'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['decision_json']);

        return $row;
    }

    public static function dismiss(string $session, int $now): bool
    {
        return self::markLive('dismissed_at', $session, $now);
    }

    /** True for exactly one caller per live offer. */
    public static function claimLaunch(string $session, int $now): bool
    {
        return self::markLive('launched_at', $session, $now);
    }

    public static function clear(string $session, ?string $origin = null): void
    {
        $stmt = self::db()->prepare('DELETE FROM session_offers WHERE session_name = :s AND (:o IS NULL OR origin = :o)');
        $stmt->execute([':s' => $session, ':o' => $origin]);
    }

    private static function markLive(string $column, string $session, int $now): bool
    {
        $stmt = self::db()->prepare("UPDATE session_offers SET {$column} = :now WHERE session_name = :s AND " . self::LIVE);
        $stmt->execute([':s' => $session, ':now' => $now]);

        return $stmt->rowCount() === 1;
    }
}
