<?php

declare(strict_types=1);

namespace HostAgent\Runtimes;

use HostAgent\Agents\AgentRegistry;

/**
 * The one seam that knows which runtimes an agent supports and resolves a
 * RuntimeProvider for a given (agent, runtime) pair - the runtime analogue
 * of AgentRegistry. Callers ask this for a runtime by agent id + type
 * rather than instantiating a provider directly, so a new runtime (or a new
 * agent's supported set) only ever needs one place to change.
 *
 * Which runtimes an agent supports is a property of the agent, so it lives
 * on AgentAdapter::supported_runtimes(); this registry just consults the
 * adapter and turns the answer into concrete providers. An agent that does
 * not support a requested runtime gets null (and callers should fall back
 * to the agent's default), not an error.
 */
class RuntimeRegistry
{
    /** @var array<string, RuntimeProvider> */
    private static array $instances = [];

    /**
     * The RuntimeProvider for $agentId under $runtimeType, or null when the
     * agent doesn't support that runtime. Providers are cached per
     * (agent, runtime).
     */
    public static function runtime_for(string $agentId, string $runtimeType): ?RuntimeProvider
    {
        $key = $agentId . '::' . $runtimeType;

        return self::$instances[$key] ??= self::build($agentId, $runtimeType);
    }

    /**
     * The agent's runtime preference order - its first entry is the default.
     *
     * @return array<int, string>
     */
    public static function supported(string $agentId): array
    {
        return AgentRegistry::get($agentId)->supported_runtimes();
    }

    /**
     * The agent's default runtime (its first supported one), or null if it
     * declares none (shouldn't happen - every adapter returns at least
     * tmux).
     */
    public static function default_for(string $agentId): ?RuntimeProvider
    {
        $supported = self::supported($agentId);

        return $supported === [] ? null : self::runtime_for($agentId, $supported[0]);
    }

    /**
     * Builds a provider without caching, so the public runtime_for() can use
     * `??=` without a separately-cached sentinel.
     */
    private static function build(string $agentId, string $runtimeType): ?RuntimeProvider
    {
        if (!in_array($runtimeType, self::supported($agentId), true)) {
            return null;
        }

        return match ($runtimeType) {
            RuntimeType::TMUX => new TmuxRuntime($agentId),
            RuntimeType::HEADLESS => match ($agentId) {
                'codex' => new CodexHeadlessRuntime(),
                'claude' => new ClaudeHeadlessRuntime(),
                default => new HeadlessRuntime(),
            },
            default => null,
        };
    }
}
