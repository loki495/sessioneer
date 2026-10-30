<?php

declare(strict_types=1);

namespace Loki495\SessioneerClient;

/** Raw outcome of one child process: both streams, its status, and whether it was killed. */
final readonly class ProcessOutcome
{
    public function __construct(
        public string $stdout,
        public string $stderr,
        public int $exitCode,
        public bool $timedOut,
    ) {}
}
