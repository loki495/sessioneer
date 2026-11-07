<?php

declare(strict_types=1);

namespace App;

/** Shared thresholds must be available without loading host-only services in the web container. */
final class AdvisePolicy
{
    public const CACHE_TTL_SECONDS = 3600;
    public const QUOTA_PARK_PCT = 90;
}
