<?php

declare(strict_types=1);

namespace Loki495\SessioneerClient;

/**
 * How one `sessioneer --wait` turn should run: where, on which agent, and how
 * long to wait before the client cancels it.
 *
 * Every option that becomes an argv element is validated here, so a caller
 * cannot smuggle a second option or a shell fragment through config. The prompt
 * is not part of this object: it never touches argv, it goes to the CLI on
 * stdin, and it is passed to the call itself.
 */
final readonly class ExecutionRequest
{
    /** One argv-safe token: starts alphanumeric, so it can never look like an option. */
    private const string TOKEN = '/^[A-Za-z0-9][A-Za-z0-9._:@+\/-]{0,199}$/';

    private const string EFFORT_TOKEN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,31}$/';

    public function __construct(
        public string $workdir,
        public Agent $agent = Agent::Claude,
        public ?string $model = null,
        public ?string $effort = null,
        public bool $noTools = true,
        public int $timeoutSeconds = 60,
        public int $graceSeconds = 30,
    ) {
        if (!is_dir($workdir)) {
            throw new \InvalidArgumentException("workdir [{$workdir}] is not a directory");
        }

        if ($model !== null) {
            self::assertToken($model, 'model', self::TOKEN);
        }

        if ($effort !== null) {
            self::assertToken($effort, 'effort', self::EFFORT_TOKEN);
            $levels = $agent->effortLevels();

            if ($levels === []) {
                throw new \InvalidArgumentException("agent [{$agent->value}] does not accept --effort");
            }

            if ($levels !== null && !in_array($effort, $levels, true)) {
                throw new \InvalidArgumentException(
                    "effort [{$effort}] is not a {$agent->value} level: " . implode(', ', $levels)
                );
            }
        }

        if ($agent->requiresModel() && $model === null) {
            throw new \InvalidArgumentException("agent [{$agent->value}] requires a model");
        }

        if ($noTools && !$agent->supportsNoTools()) {
            throw new \InvalidArgumentException(
                "agent [{$agent->value}] cannot run with --no-tools; pass noTools: false to accept its tools"
            );
        }

        if ($timeoutSeconds < 1 || $timeoutSeconds > 86400) {
            throw new \InvalidArgumentException('timeoutSeconds must be between 1 and 86400');
        }

        if ($graceSeconds < 0 || $graceSeconds > 3600) {
            throw new \InvalidArgumentException('graceSeconds must be between 0 and 3600');
        }
    }

    /**
     * The argv for one attempt. The prompt is not in it; it is written to stdin.
     *
     * @return list<string>
     */
    public function command(string $binary): array
    {
        $command = [
            $binary,
            '--wait',
            '--json',
            '--timeout',
            (string) $this->timeoutSeconds,
            '--workdir',
            $this->workdir,
            '--agent',
            $this->agent->value,
        ];

        if ($this->noTools) {
            $command[] = '--no-tools';
        }

        if ($this->model !== null) {
            $command[] = '--model';
            $command[] = $this->model;
        }

        if ($this->effort !== null) {
            $command[] = '--effort';
            $command[] = $this->effort;
        }

        $command[] = '-';

        return $command;
    }

    /** The outer process limit: the CLI's own timeout plus time to report and exit. */
    public function processTimeoutSeconds(): int
    {
        return $this->timeoutSeconds + $this->graceSeconds;
    }

    private static function assertToken(string $value, string $name, string $pattern): void
    {
        if (preg_match($pattern, $value) === 1) {
            return;
        }

        $printable = (string) preg_replace('/[^\x20-\x7E]/', '?', $value);

        throw new \InvalidArgumentException("{$name} [{$printable}] is not a safe option token");
    }
}
