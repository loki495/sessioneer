<?php

declare(strict_types=1);

namespace Loki495\SessioneerClient;

/**
 * A completed turn. `answer` is the final assistant text, exactly as the CLI's
 * `--json` envelope carried it; `session` is the session this call created,
 * which has already been stopped by the time the result is returned, and
 * `conversationId` is that session's agent-side conversation id when the CLI
 * could report it.
 */
final readonly class ExecutionResult
{
    public function __construct(
        public string $answer,
        public ?string $session,
        public int $exitCode,
        public ?CleanupReport $cleanup,
        public ?string $conversationId = null,
    ) {}

    public function cleanupFailed(): bool
    {
        return $this->cleanup !== null && !$this->cleanup->ok();
    }
}
