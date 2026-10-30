<?php

declare(strict_types=1);

/**
 * The shared PHP client lives in packages/sessioneer-client as a standalone
 * Composer package with its own dependency-free runner, so a consumer can copy
 * the package and keep its tests. This file only makes that runner part of the
 * repo suite: same checks, same exit code.
 */

require __DIR__ . '/../packages/sessioneer-client/tests/run.php';
