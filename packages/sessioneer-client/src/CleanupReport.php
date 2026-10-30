<?php

declare(strict_types=1);

namespace Loki495\SessioneerClient;

/**
 * What happened to the session this call created when it was cleaned up.
 *
 * A cleanup that could not finish is reported here rather than swallowed: a
 * leftover session counts against the live-session cap and blocks later work.
 */
final readonly class CleanupReport
{
    /**
     * @param  list<string>  $failures  One entry per failed kill attempt, in order.
     */
    public function __construct(
        public string $session,
        public bool $killed,
        public bool $forced,
        public array $failures,
    ) {}

    public function ok(): bool
    {
        return $this->killed && $this->failures === [];
    }

    public function describe(): string
    {
        if ($this->ok()) {
            return $this->forced
                ? "session {$this->session} stopped (forced)"
                : "session {$this->session} stopped";
        }

        return "session {$this->session} cleanup failed: " . implode('; ', $this->failures);
    }
}
