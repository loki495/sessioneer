#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Entry point of the sessioneer-flow-reap timer: archives rotated sources whose receiver holds
 * a claim and that are idle and claim-free (FlowService::archive_replaced()). Prints one stderr
 * line per result that is not "waiting"; a pass that throws is logged and still exits 0.
 */

require __DIR__ . '/lib/Push.php';
require __DIR__ . '/lib/Sessions.php';

use HostAgent\SessionFlow\FlowService;

try {
    $reply = FlowService::archive_replaced(FlowService::live());
    foreach ($reply['results'] ?? [] as $row) {
        if (($row['outcome'] ?? '') !== 'waiting') {
            error_log("sessioneer-flow-reap: {$row['source']} -> {$row['receiver']}: {$row['outcome']} ({$row['detail']})");
        }
    }
} catch (\Throwable $e) {
    error_log('sessioneer-flow-reap: pass crashed - ' . $e->getMessage());
}
