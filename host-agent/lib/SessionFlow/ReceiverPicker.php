<?php

declare(strict_types=1);

namespace HostAgent\SessionFlow;

use App\AdvisePolicy;
use HostAgent\Services\Config;
use HostAgent\Stores\SidecarStore;

/**
 * Which account takes over a session whose own account is blocked, and which
 * permission mode the receiver starts in. Reads quota readings and sidecars
 * only; starts nothing.
 */
final class ReceiverPicker
{
    /**
     * Antigravity has no headless launcher, so its takeover stays manual.
     * A blocked Claude source reserves other Claude profiles for last resort.
     */
    private const RECEIVER_ORDER = ['claude', 'codex', 'opencode'];
    private const CLAUDE_RECEIVER_ORDER = ['codex', 'opencode', 'claude'];

    /**
     * Permission modes in this app's own vocabulary (see PermissionMode), plus
     * the two Codex sandboxes a session can record. dontAsk is left out: a
     * fresh session that denied every unapproved tool call could not read the
     * source conversation.
     */
    private const CROSS_AGENT_MODES = ['', 'manual', 'accept edits', 'plan', 'auto', 'workspace-write', 'full'];

    /** OpenCode has no permission modes at all, so only a read-only source cannot travel to it. */
    private const OPENCODE_MODES = ['', 'manual', 'accept edits', 'auto', 'workspace-write', 'full'];

    /** The modes a Claude session records, in this app's vocabulary (not Codex's sandbox names). */
    private const CLAUDE_MODES = ['manual', 'accept edits', 'plan', 'auto', 'dontAsk'];

    /**
     * @param array{name:string,agent:string,agent_session_id:string,workdir:?string,profile:string} $session
     * @param array<string,mixed> $usage
     * @param array<string,mixed> $status
     * @return array{agent:string,profile:?string}|null
     */
    public static function pick(array $session, array $usage, bool $blocked, array $status): ?array
    {
        if (!$blocked) {
            return ['agent' => $session['agent'], 'profile' => $session['agent'] === 'claude' ? $session['profile'] : null];
        }

        $old = SidecarStore::read_sidecar($session['name']) ?? [];
        // A tool-less source cannot read itself, so no replacement could transfer its work.
        if (!empty($old['no_tools'])) {
            return null;
        }

        $mode = (string) ($status['mode'] ?? '');
        $order = $session['agent'] === 'claude' ? self::CLAUDE_RECEIVER_ORDER : self::RECEIVER_ORDER;
        foreach ($order as $agent) {
            if ($agent === $session['agent'] && $agent !== 'claude') {
                continue;
            }
            if ($agent !== $session['agent'] && !self::crossAgentSafe($old, $status)) {
                continue;
            }
            // OpenCode has no permission modes, so a read-only source restriction cannot travel with it.
            if ($agent === 'opencode' && !in_array($mode, self::OPENCODE_MODES, true)) {
                continue;
            }
            if ($agent === 'claude') {
                // Prefer a manual agy takeover over spending another Claude profile.
                if ($session['agent'] === 'claude' && self::crossAgentSafe($old, $status) && self::manualReceivers($usage) !== []) {
                    return null;
                }
                $profiles = $usage['claude']['profiles'] ?? [];
                $names = array_keys($profiles);
                sort($names);
                foreach ($names as $name) {
                    // Only a Claude source has a profile of its own to skip; Codex carries none.
                    if (($session['agent'] === 'claude' && $name === $session['profile'])
                        || !self::hasRoom($profiles[$name]['quota'] ?? null)) {
                        continue;
                    }
                    if (Config::claude_bin($name) !== '') {
                        return ['agent' => 'claude', 'profile' => $name];
                    }
                }
                continue;
            }
            if ($agent === 'codex' && Config::codex_bin() !== '' && self::hasRoom($usage['codex']['quota'] ?? null)) {
                return ['agent' => 'codex', 'profile' => null];
            }
            if ($agent === 'opencode' && Config::opencode_bin() !== ''
                && self::hasRoom($usage['opencode']['profiles']['default']['quota'] ?? ($usage['opencode']['quota'] ?? null))) {
                return ['agent' => 'opencode', 'profile' => null];
            }
        }

        return null;
    }

    /**
     * Agents with room that Sessioneer cannot start headless, so the takeover
     * prompt has to be pasted into a session there by hand.
     *
     * @param array<string,mixed> $usage
     * @return list<string>
     */
    public static function manualReceivers(array $usage): array
    {
        $quota = $usage['antigravity']['profiles']['default']['quota'] ?? ($usage['antigravity']['quota'] ?? null);
        return self::hasRoom($quota) ? ['Antigravity'] : [];
    }

    /**
     * Every metered window an account reports must be below the park threshold.
     * An account that reports usage but no percentage windows has no limit to
     * hit (OpenCode bills per token against no plan), so it counts as room.
     * No reading at all is not room.
     *
     * @param array<string,mixed>|null $quota
     */
    public static function hasRoom(?array $quota): bool
    {
        if ($quota === null) {
            return false;
        }
        foreach ($quota as $window) {
            if (!is_array($window) || !array_key_exists('pct', $window)) {
                continue;
            }
            if (!is_numeric($window['pct']) || $window['pct'] >= AdvisePolicy::QUOTA_PARK_PCT) {
                return false;
            }
        }
        return true;
    }

    /**
     * Tool rules cannot be translated across agents at all, and a mode outside
     * this app's own vocabulary (or the launch-only dontAsk) is not offered to
     * a different agent. Which mode the receiver starts in is startingMode().
     *
     * @param array<string,mixed> $old the source session's sidecar
     * @param array<string,mixed> $status
     */
    public static function crossAgentSafe(array $old, array $status): bool
    {
        if (!empty($old['no_tools']) || !empty($old['allowed_tools']) || !empty($old['disallowed_tools'])) {
            return false;
        }
        return in_array((string) ($status['mode'] ?? ''), self::CROSS_AGENT_MODES, true);
    }

    /**
     * The mode a fresh receiver starts in: its own when it can reproduce the
     * source's, nothing when it cannot. Claude takes this app's own modes,
     * Codex only its read-only sandbox ('plan'), OpenCode has no modes.
     */
    public static function startingMode(string $receiverAgent, string $mode): ?string
    {
        if ($receiverAgent === 'claude') {
            return in_array($mode, self::CLAUDE_MODES, true) ? $mode : null;
        }
        if ($receiverAgent === 'codex') {
            return in_array($mode, ['plan', 'full'], true) ? $mode : null;
        }
        return null;
    }
}
