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
}
