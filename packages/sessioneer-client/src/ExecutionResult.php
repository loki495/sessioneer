<?php

declare(strict_types=1);

namespace Loki495\SessioneerClient;

/**
 * A completed turn. `answer` is the final assistant text with the CLI's single
 * trailing newline removed; `session` is the session this call created, which
 * has already been stopped by the time the result is returned.
 */
final readonly class ExecutionResult
{
    public function __construct(
        public string $answer,
        public ?string $session,
        public int $exitCode,
        public ?CleanupReport $cleanup,
    ) {}

    public function cleanupFailed(): bool
    {
        return $this->cleanup !== null && !$this->cleanup->ok();
    }
}
