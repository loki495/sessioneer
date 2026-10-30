<?php

declare(strict_types=1);

/**
 * Runs every *_test.php file in this directory. Usage: php tests/run.php
 */

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    fwrite(STDOUT, '== ' . basename($file) . " ==\n");
    require $file;
}

fwrite(STDOUT, sprintf(
    "\n%d checks, %d failed\n",
    $GLOBALS['client_tests'],
    $GLOBALS['client_failures'],
));

exit($GLOBALS['client_failures'] === 0 ? 0 : 1);
