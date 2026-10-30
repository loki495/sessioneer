<?php

declare(strict_types=1);

namespace Loki495\SessioneerClient;

/**
 * The agents `sessioneer --agent` can launch.
 *
 * OpenCode needs a Sessioneer with its launcher; an older CLI refuses it and the
 * client reports that as a launch refusal.
 */
enum Agent: string
{
    case Claude = 'claude';
    case Codex = 'codex';
    case Ollama = 'ollama';
    case OpenCode = 'opencode';

    /** Agents Sessioneer knows about but cannot launch from the CLI. */
    private const array KNOWN_UNLAUNCHABLE = ['agy', 'antigravity'];

    public static function fromName(string $name): self
    {
        $agent = self::tryFrom($name);

        if ($agent !== null) {
            return $agent;
        }

        $reason = in_array($name, self::KNOWN_UNLAUNCHABLE, true)
            ? 'is not launchable through Sessioneer yet'
            : 'is not an agent Sessioneer launches';

        throw new \InvalidArgumentException(
            'agent [' . self::printable($name) . "] {$reason}; launchable: " . self::launchableNames()
        );
    }

    public static function launchableNames(): string
    {
        return implode(', ', array_map(static fn (self $agent): string => $agent->value, self::cases()));
    }

    /** `--no-tools` is a Claude-only option; Codex, Ollama and OpenCode refuse it. */
    public function supportsNoTools(): bool
    {
        return $this === self::Claude;
    }

    /** Ollama has no default model, so `--model` is mandatory for it. */
    public function requiresModel(): bool
    {
        return $this === self::Ollama;
    }

    /**
     * The reasoning levels this agent accepts, or null when the CLI is the
     * authority (Codex validates the level against the chosen model).
     *
     * @return list<string>|null
     */
    public function effortLevels(): ?array
    {
        return match ($this) {
            self::Claude => ['low', 'medium', 'high', 'xhigh', 'max'],
            self::OpenCode => [],
            default => null,
        };
    }

    private static function printable(string $value): string
    {
        return (string) preg_replace('/[^\x20-\x7E]/', '?', $value);
    }
}
