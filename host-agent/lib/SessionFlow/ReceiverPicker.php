<?php

declare(strict_types=1);

namespace HostAgent\SessionFlow;


/**
 * Which account takes over a session whose own account is blocked, and which
 * permission mode the receiver starts in. Reads quota readings and sidecars
 * only; starts nothing.
 */
final class ReceiverPicker
{
    private const CROSS_AGENT_MODES = ['', 'manual', 'accept edits', 'plan', 'auto', 'workspace-write', 'full'];
    private const CLAUDE_MODES = ['manual', 'accept edits', 'plan', 'auto', 'dontAsk'];
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
