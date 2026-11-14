<?php

declare(strict_types=1);

/**
 * clients/ios/SessioneerReply.js under Node, against stand-ins for Scriptable's objects and a
 * scripted API (tests/scriptable_harness.js). Proves the script's flows and request bodies; it cannot
 * prove how Scriptable draws them on a device. Skips when Node is not installed.
 */
require __DIR__ . '/lib/assert.php';

$node = trim((string)shell_exec('command -v node 2>/dev/null'));
if ($node === '') {
    echo "  SKIP: node is not installed\n";
    exit(0);
}

$output = [];
exec(escapeshellarg($node) . ' ' . escapeshellarg(__DIR__ . '/scriptable_harness.js') . ' 2>&1', $output, $exit);
echo implode("\n", $output) . "\n";
assert_equal(0, $exit, 'the Scriptable harness passes');
exit($GLOBALS['__sessioneer_test_failures'] > 0 ? 1 : 0);
