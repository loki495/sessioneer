<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Stores\SidecarStore;

/**
 * The one place a headless session's reference (its "name") is minted:
 * `<agentId>-headless-<YYYYMMDD-HHMMSS>`, so every agent that gains a
 * headless runtime is recognizable at a glance and named the same way.
 *
 * The reference is Sessioneer's own and stays stable for the session's whole
 * life - it is deliberately NOT the agent's conversation id, which can change
 * (Claude Code rotates it on /clear).
 */
final class HeadlessSessionName
{
    public static function generate(string $agentId): string
    {
        $base = $agentId . '-headless-' . date('Ymd-His');
        $name = $base;

        // Two sessions created within the same second must not collide.
        for ($suffix = 2; SidecarStore::read_sidecar($name) !== null; $suffix++) {
            $name = $base . '-' . $suffix;
        }

        return $name;
    }
}
