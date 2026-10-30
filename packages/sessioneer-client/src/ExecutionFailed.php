<?php

declare(strict_types=1);

namespace Loki495\SessioneerClient;

/**
 * A turn that produced no answer.
 *
 * The session this call created (when the launch got far enough to name one)
 * has already been cancelled, and `cleanup` says whether that worked. Nothing
 * outside this call's own session is ever touched.
 */
final class ExecutionFailed extends \RuntimeException
{
    public function __construct(
        public readonly FailureReason $reason,
        string $message,
        public readonly ?string $session = null,
        public readonly ?CleanupReport $cleanup = null,
    ) {
        parent::__construct($message);
    }

    /** The failure plus what became of the session, for a log line. */
    public function describe(): string
    {
        return $this->cleanup === null
            ? $this->getMessage()
            : $this->getMessage() . ' (' . $this->cleanup->describe() . ')';
    }
}
