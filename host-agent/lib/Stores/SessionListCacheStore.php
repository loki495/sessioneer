<?php

declare(strict_types=1);

namespace HostAgent\Stores;

use HostAgent\Services\Config;

/**
 * Very-short-TTL cache for SessionService::list_all_sessions() - that scan
 * spawns a tmux subprocess (list-panes + capture-pane) per tracked session
 * plus a full /proc walk, and is the single most expensive thing this
 * agent does. Found live 2026-08-11: every open session.php tab was
 * independently re-running the whole scan on its own poll timer, and with
 * several sessions/tabs open the redundant, concurrent scans were visibly
 * hanging the page for a second or two.
 *
 * This does not reduce how often any one poller asks - it only means two
 * requests landing within Config::session_list_cache_ttl_seconds() of each
 * other (multiple tabs, multiple sessions' own poll timers drifting into
 * alignment) share one scan's result instead of each paying for their own.
 * The TTL is kept deliberately below the fastest selectable poll interval
 * (see POLL_INTERVAL_ALLOWED_MS in common.js) so a single poller still gets
 * a fresh scan on every one of its own ticks - this only ever coalesces
 * concurrent callers, it never stales a lone poller past what it already
 * asked for.
 */
class SessionListCacheStore
{
    public static function cache_path(): string
    {
        return Config::cache_dir() . '/session_list.json';
    }

    /**
     * @return array{sessions:array, bare:array}|null null on a cold/expired/
     *   corrupt cache - callers always treat that the same as "compute fresh"
     */
    public static function read(): ?array
    {
        $raw = @file_get_contents(self::cache_path());

        if ($raw === false) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded) || !isset($decoded['fetched_at'], $decoded['data'])) {
            return null;
        }

        if (microtime(true) - (float)$decoded['fetched_at'] >= Config::session_list_cache_ttl_seconds()) {
            return null;
        }

        return is_array($decoded['data']) ? $decoded['data'] : null;
    }

    /** Drops the cached listing, for callers that just changed what is running and must not see the old picture. */
    public static function clear(): void
    {
        @unlink(self::cache_path());
    }

    /**
     * @param array{sessions:array, bare:array} $data
     */
    public static function write(array $data): void
    {
        $dir = Config::cache_dir();

        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        @file_put_contents(self::cache_path(), json_encode([
            'fetched_at' => microtime(true),
            'data' => $data,
        ]));
    }
}
