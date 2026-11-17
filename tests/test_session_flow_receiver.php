<?php

declare(strict_types=1);

// Step 2 of session-flow: HostAgent\SessionFlow\ReceiverPicker (pick, manualReceivers,
// hasRoom, crossAgentSafe, startingMode). Characterization tests ported from
// test_advise_runner.php: they pass against the private AdviseRunner methods today and
// must pass unchanged once the logic lives in the new class. Isolated sidecar and
// status stores in /tmp; fixture binaries are never executed. No agent, no model call.
// Written before the implementation: fails until the class exists.

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $c): void {
        foreach (['HostAgent\\' => '/../host-agent/lib/', 'App\\' => '/../src/lib/'] as $prefix => $dir) {
            $f = __DIR__ . $dir . str_replace('\\', '/', substr($c, strlen($prefix))) . '.php';
            if (str_starts_with($c, $prefix) && is_file($f)) {
                require $f;
            }
        }
    });
}
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/../host-agent/lib/Sessions.php';

use HostAgent\SessionFlow\ReceiverPicker;
use HostAgent\SessionFlow\Thresholds;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SqliteDb;

$root = sys_get_temp_dir() . '/sessioneer-test-flow-receiver-' . getmypid();
mkdir($root . '/home', 0700, true);
foreach (['SIDECAR_DIR' => '/sidecars', 'SESSIONS_SQLITE_FILE' => '/sessions.sqlite', 'PUSH_SQLITE_FILE' => '/push.sqlite', 'HOME_ROOT' => '/home'] as $key => $path) {
    putenv($key . '=' . $root . $path);
}
putenv('CLAUDE_BIN=/fixture/claude');
putenv('CODEX_BIN=/fixture/codex');
putenv('OPENCODE_BIN=/fixture/opencode');
register_shutdown_function(static function () use ($root): void {
    SqliteDb::reset_connections_for_tests();
    exec('rm -rf ' . escapeshellarg($root));
});

$ok = ['session' => ['pct' => 3], 'week_all' => ['pct' => 40]];
$limited = ['session' => ['pct' => 90, 'resets_at' => 2_000_000_000], 'week_all' => ['pct' => 40]];
// A second park line from a thresholds file: the file value is authoritative wherever the check runs.
// The file lives inside $root, which the shutdown function above removes.
$parkFile = $root . '/thresholds95.conf';
file_put_contents($parkFile, "QUOTA_PARK=95\n");
$t95 = Thresholds::fromFile($parkFile);

echo "crossAgentSafe()\n";
foreach (['', 'manual', 'accept edits', 'plan', 'auto', 'workspace-write', 'full'] as $mode) {
    assert_equal(true, ReceiverPicker::crossAgentSafe([], ['mode' => $mode]), "mode '$mode' travels across agents");
}
assert_equal(true, ReceiverPicker::crossAgentSafe([], []), 'no recorded mode is safe');
assert_equal(true, ReceiverPicker::crossAgentSafe([], ['mode' => null]), 'a null mode is safe');
assert_equal(false, ReceiverPicker::crossAgentSafe([], ['mode' => 'dontAsk']), 'the launch-only dontAsk is not safe');
assert_equal(false, ReceiverPicker::crossAgentSafe([], ['mode' => 'bogus']), 'a mode outside the vocabulary is not safe');
assert_equal(false, ReceiverPicker::crossAgentSafe(['no_tools' => true], ['mode' => 'auto']), 'a tool-less source is not safe');
assert_equal(false, ReceiverPicker::crossAgentSafe(['allowed_tools' => 'Bash'], ['mode' => 'auto']), 'allowed tool rules cannot be translated');
assert_equal(false, ReceiverPicker::crossAgentSafe(['disallowed_tools' => 'Bash'], ['mode' => 'auto']), 'disallowed tool rules cannot be translated');
assert_equal(true, ReceiverPicker::crossAgentSafe(['allowed_tools' => '', 'disallowed_tools' => null, 'no_tools' => false], ['mode' => 'auto']), 'empty rules are no restriction');

echo "startingMode()\n";
foreach (['manual', 'accept edits', 'plan', 'auto', 'dontAsk'] as $mode) {
    assert_equal($mode, ReceiverPicker::startingMode('claude', $mode), "claude starts in '$mode'");
}
assert_equal(null, ReceiverPicker::startingMode('claude', ''), 'claude: no mode, none sent');
assert_equal(null, ReceiverPicker::startingMode('claude', 'workspace-write'), 'claude: a Codex sandbox name is not reproduced');
assert_equal(null, ReceiverPicker::startingMode('claude', 'full'), 'claude: full is not a Claude mode');
assert_equal('plan', ReceiverPicker::startingMode('codex', 'plan'), 'codex starts read-only for a read-only source');
assert_equal('full', ReceiverPicker::startingMode('codex', 'full'), 'codex starts in full');
foreach (['', 'manual', 'accept edits', 'auto', 'dontAsk', 'workspace-write'] as $mode) {
    assert_equal(null, ReceiverPicker::startingMode('codex', $mode), "codex: '$mode' is not handed over");
}
foreach (['plan', 'auto', 'full', ''] as $mode) {
    assert_equal(null, ReceiverPicker::startingMode('opencode', $mode), "opencode has no modes ('$mode')");
}
assert_equal(null, ReceiverPicker::startingMode('antigravity', 'plan'), 'an unknown agent gets no mode');

// Receiver selection is tested through FlowService; these historical selectors
// are no longer public acceptance surfaces. Mode compatibility remains above.
test_exit();
