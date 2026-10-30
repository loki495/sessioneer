<?php

declare(strict_types=1);

namespace Loki495\SessioneerClient;

enum FailureReason: string
{
    /** The `sessioneer` binary is not there or not executable. */
    case Unavailable = 'unavailable';

    /** The CLI refused the launch (quota, unknown agent, missing binary, ...). */
    case LaunchRefused = 'launch_refused';

    /** The turn started and reported an error. */
    case TurnError = 'turn_error';

    /** The turn did not finish inside the deadline; the client cancelled its session. */
    case Timeout = 'timeout';

    /** The CLI process could not be started or died without an answer. */
    case ProcessFailure = 'process_failure';

    /**
     * The `error.kind` from the CLI's `--json` envelope. An unknown kind is a
     * process failure, never a guess: a kind a newer CLI adds must not look like
     * a launch refusal to this client.
     */
    public static function fromCli(string $kind): self
    {
        return match ($kind) {
            'launch_refused' => self::LaunchRefused,
            'turn_error' => self::TurnError,
            'timeout' => self::Timeout,
            default => self::ProcessFailure,
        };
    }
}
