<?php

declare(strict_types=1);

namespace HostAgent\Agents;

/**
 * The one seam that knows which AgentAdapter implementations exist -
 * everything else (SessionLifecycleService, agent.php's dispatch_action(),
 * the dashboard) asks this for an adapter by id rather than instantiating
 * one directly, so adding a new agent (Antigravity - see
 * docs/history/antigravity-adapter-plan.md) only ever needs one line added to
 * ADAPTERS below.
 */
class AgentRegistry
{
    /** @var array<string, class-string<AgentAdapter>> */
    private const ADAPTERS = [
        'claude' => ClaudeCodeAdapter::class,
        'antigravity' => AntigravityAdapter::class,
        'opencode' => OpenCodeAdapter::class,
        'codex' => CodexAdapter::class,
    ];

    /** @var array<string, AgentAdapter> */
    private static array $instances = [];

    public static function get(string $agentId): AgentAdapter
    {
        if (!isset(self::ADAPTERS[$agentId])) {
            throw new \InvalidArgumentException("Unknown agent id: {$agentId}");
        }

        return self::$instances[$agentId] ??= new (self::ADAPTERS[$agentId])();
    }

    /**
     * The agent a session/spawn request should use when nothing else says
     * otherwise - Claude Code, unchanged from every existing caller's
     * behavior before this class existed.
     */
    public static function default_agent_id(): string
    {
        return 'claude';
    }

    /** @return string[] */
    public static function known_agent_ids(): array
    {
        return array_keys(self::ADAPTERS);
    }
}
