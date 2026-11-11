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

echo "hasRoom()\n";
assert_equal(false, ReceiverPicker::hasRoom(null), 'no quota reading at all is not room');
assert_equal(true, ReceiverPicker::hasRoom($ok), 'all windows below the park threshold is room');
assert_equal(true, ReceiverPicker::hasRoom([]), 'a reading with no windows is room');
assert_equal(true, ReceiverPicker::hasRoom(['session' => ['pct' => 89.9]]), '89.9% is room');
assert_equal(false, ReceiverPicker::hasRoom(['session' => ['pct' => 90]]), '90% is the park threshold: no room');
assert_equal(false, ReceiverPicker::hasRoom(['session' => ['pct' => 100]]), '100% is no room');
assert_equal(false, ReceiverPicker::hasRoom($ok + ['gemini-weekly' => ['pct' => 95]]), 'every metered window counts, one full window is enough');
assert_equal(false, ReceiverPicker::hasRoom(['session' => ['pct' => 'unknown']]), 'a non-numeric percentage is not room');
assert_equal(false, ReceiverPicker::hasRoom(['session' => ['pct' => null]]), 'a null percentage is not room');
assert_equal(true, ReceiverPicker::hasRoom(['cost' => 16.3, 'tokens_input' => 40000000, 'captured_at' => '2026-11-07T19:00:00+00:00']), 'usage with no percentage windows (OpenCode) is room');
assert_equal(true, ReceiverPicker::hasRoom(['session' => ['resets_at' => 5], 'note' => 'x']), 'windows without pct and non-array entries are skipped');
assert_equal(true, ReceiverPicker::hasRoom(['numeric-string' => ['pct' => '50']]), 'a numeric string percentage is compared as a number');

echo "hasRoom() with the file's park line\n";
$const = Thresholds::class . '::DEFAULT_QUOTA_PARK';
assert_true(defined($const), 'Thresholds exposes the built-in park line as DEFAULT_QUOTA_PARK');
assert_equal(90, defined($const) ? constant($const) : null, 'the built-in park default stays 90');
assert_equal(95, $t95->get('QUOTA_PARK'), 'the thresholds file sets the park line to 95');
assert_equal(true, ReceiverPicker::hasRoom(['session' => ['pct' => 94]], $t95), 'QUOTA_PARK=95: a 94% account is room');
assert_equal(false, ReceiverPicker::hasRoom(['session' => ['pct' => 95]], $t95), 'QUOTA_PARK=95: a 95% account is parked');
assert_equal(false, ReceiverPicker::hasRoom(['session' => ['pct' => 94]]), 'without a Thresholds the built-in 90% line still parks a 94% account');

echo "manualReceivers()\n";
assert_equal(['Antigravity'], ReceiverPicker::manualReceivers(['antigravity' => ['profiles' => ['default' => ['quota' => $ok]]]]), 'Antigravity default profile with room');
assert_equal(['Antigravity'], ReceiverPicker::manualReceivers(['antigravity' => ['quota' => ['gemini-weekly' => ['pct' => 0]]]]), 'Antigravity top-level quota with room');
assert_equal(['Antigravity'], ReceiverPicker::manualReceivers(['antigravity' => ['profiles' => ['default' => ['quota' => $ok]], 'quota' => $limited]]), 'the default profile quota is read before the top-level one');
assert_equal([], ReceiverPicker::manualReceivers(['antigravity' => ['profiles' => ['default' => ['quota' => $limited]]]]), 'a limited Antigravity is not offered');
assert_equal([], ReceiverPicker::manualReceivers(['antigravity' => ['quota' => ['session' => ['pct' => 'unknown']]]]), 'an unreadable Antigravity quota is not offered');
assert_equal([], ReceiverPicker::manualReceivers(['antigravity' => []]), 'Antigravity without a quota reading is not offered');
assert_equal([], ReceiverPicker::manualReceivers([]), 'no usage at all: no manual receiver');
assert_equal([], ReceiverPicker::manualReceivers(['codex' => ['quota' => $ok]]), 'other agents are launchable, never manual');
assert_equal([], ReceiverPicker::manualReceivers(['antigravity' => ['quota' => ['session' => ['pct' => 94]]]]), 'default park line: a 94% Antigravity is not offered');
assert_equal(['Antigravity'], ReceiverPicker::manualReceivers(['antigravity' => ['quota' => ['session' => ['pct' => 94]]]], $t95), 'QUOTA_PARK=95: a 94% Antigravity is offered');

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

echo "pick()\n";
$n = 0;
/** Register a source session sidecar and return the $session argument pick() takes. */
$source = static function (string $agent, string $profile = 'work', array $extra = []) use (&$n): array {
    $name = 'src-' . (++$n);
    $id = sprintf('%08d-0000-4000-8000-000000000000', $n);
    SidecarStore::write_sidecar($name, ['workdir' => '/w/' . $name, 'agent_session_id' => $id, 'agent' => $agent, 'runtime' => 'headless', 'profile' => $profile] + $extra);
    return ['name' => $name, 'agent' => $agent, 'agent_session_id' => $id, 'workdir' => '/w/' . $name, 'profile' => $profile];
};
$usage = static fn (array $over = []): array => $over + [
    'claude' => ['profiles' => ['work' => ['quota' => $limited], 'personal' => ['quota' => $ok]]],
    'codex' => ['quota' => $ok],
    'opencode' => ['profiles' => ['default' => ['quota' => $ok]]],
    'antigravity' => ['profiles' => ['default' => ['quota' => $limited]]],
];
$claude = static fn (string $work, string $personal): array => ['profiles' => ['work' => ['quota' => $work === 'ok' ? $ok : $GLOBALS['limited']], 'personal' => ['quota' => $personal === 'ok' ? $ok : $GLOBALS['limited']]]];

// Not blocked: the source keeps its own agent and profile, quota is not consulted.
assert_equal(['agent' => 'claude', 'profile' => 'work'], ReceiverPicker::pick($source('claude', 'work'), $usage(), false, []), 'unblocked claude source keeps its own profile');
assert_equal(['agent' => 'claude', 'profile' => 'personal'], ReceiverPicker::pick($source('claude', 'personal'), $usage(), false, []), 'unblocked claude source: profile personal');
assert_equal(['agent' => 'codex', 'profile' => null], ReceiverPicker::pick($source('codex', 'personal'), $usage(), false, []), 'unblocked codex source carries no profile');

// Blocked Claude source: Codex, then OpenCode, then a manual Antigravity, the other Claude profile last.
assert_equal(['agent' => 'codex', 'profile' => null], ReceiverPicker::pick($source('claude'), $usage(), true, []), 'codex with room comes before the other Claude profile');
assert_equal(['agent' => 'opencode', 'profile' => null], ReceiverPicker::pick($source('claude'), $usage(['codex' => ['quota' => $limited]]), true, []), 'opencode comes next when codex is limited');
assert_equal(['agent' => 'opencode', 'profile' => null], ReceiverPicker::pick($source('claude'), $usage(['codex' => ['quota' => $limited], 'opencode' => ['quota' => ['cost' => 16.3, 'captured_at' => 'x']]]), true, []), 'opencode with usage but no windows is a receiver');
assert_equal(['agent' => 'opencode', 'profile' => null], ReceiverPicker::pick($source('claude'), $usage(['codex' => ['quota' => $limited], 'opencode' => ['quota' => $ok]]), true, []), 'opencode top-level quota is read when there is no default profile');
$manualFirst = $usage(['codex' => ['quota' => $limited], 'opencode' => ['profiles' => ['default' => ['quota' => $limited]]], 'antigravity' => ['profiles' => ['default' => ['quota' => $ok]]]]);
assert_equal(null, ReceiverPicker::pick($source('claude'), $manualFirst, true, []), 'a manual Antigravity takeover is preferred over spending the other Claude profile: no automatic receiver');
foreach ([$limited, ['session' => ['pct' => 'unknown']], null] as $agy) {
    $u = $manualFirst;
    $u['antigravity']['profiles']['default']['quota'] = $agy;
    assert_equal(['agent' => 'claude', 'profile' => 'personal'], ReceiverPicker::pick($source('claude'), $u, true, []), 'the other Claude profile is last resort when alternatives are limited or unknown');
}
$allLimited = $usage(['codex' => ['quota' => $limited], 'opencode' => ['profiles' => ['default' => ['quota' => $limited]]], 'claude' => $claude('limited', 'limited')]);
assert_equal(null, ReceiverPicker::pick($source('claude'), $allLimited, true, []), 'everything limited: no receiver');
assert_equal(null, ReceiverPicker::pick($source('claude'), ['claude' => ['profiles' => ['work' => ['quota' => $limited], 'personal' => []]]], true, []), 'a profile with no quota reading is not room: no receiver');
assert_equal(null, ReceiverPicker::pick($source('claude'), [], true, []), 'empty usage: no receiver');
assert_equal(null, ReceiverPicker::pick($source('claude'), ['claude' => ['profiles' => ['work' => ['quota' => $limited]]]], true, []), 'the source profile is skipped even when it is the only one');
assert_equal(null, ReceiverPicker::pick($source('claude', 'work'), $usage(['claude' => $claude('ok', 'limited'), 'codex' => ['quota' => $limited], 'opencode' => ['quota' => $limited]]), true, []), 'a blocked source never picks its own profile even if the reading shows room');
$three = $usage(['codex' => ['quota' => $limited], 'opencode' => ['quota' => $limited], 'claude' => ['profiles' => ['zeta' => ['quota' => $ok], 'alpha' => ['quota' => $ok], 'work' => ['quota' => $limited]]]]);
assert_equal(['agent' => 'claude', 'profile' => 'alpha'], ReceiverPicker::pick($source('claude'), $three, true, []), 'other Claude profiles are tried in name order');

// Blocked Codex source: never itself, a Claude profile (no own profile to skip), then OpenCode.
$codexBlocked = $usage(['codex' => ['quota' => $limited], 'claude' => ['profiles' => ['personal' => ['quota' => $ok]]]]);
assert_equal(['agent' => 'claude', 'profile' => 'personal'], ReceiverPicker::pick($source('codex', 'personal'), $codexBlocked, true, []), 'a blocked codex source uses the personal Claude account');
assert_equal(['agent' => 'claude', 'profile' => 'work'], ReceiverPicker::pick($source('codex', 'work'), $usage(['codex' => ['quota' => $limited], 'claude' => ['profiles' => ['work' => ['quota' => $ok]]]]), true, []), 'a codex source has no Claude profile to skip, even one named like its sidecar profile');
assert_equal(['agent' => 'opencode', 'profile' => null], ReceiverPicker::pick($source('codex'), $usage(['codex' => ['quota' => $ok], 'claude' => $claude('limited', 'limited')]), true, []), 'a blocked codex source never picks codex itself: opencode');

// Binaries: an agent that cannot be launched is not a receiver.
putenv('CODEX_BIN=');
assert_equal(['agent' => 'opencode', 'profile' => null], ReceiverPicker::pick($source('claude'), $usage(), true, []), 'no codex binary configured: skip codex');
putenv('OPENCODE_BIN=');
assert_equal(['agent' => 'claude', 'profile' => 'personal'], ReceiverPicker::pick($source('claude'), $usage(), true, []), 'no codex or opencode binary: the other Claude profile');
putenv('CLAUDE_BIN=');
assert_equal(null, ReceiverPicker::pick($source('claude'), $usage(), true, []), 'no binary for any agent: no receiver');
putenv('CLAUDE_BIN=/fixture/claude');
putenv('CODEX_BIN=/fixture/codex');
putenv('OPENCODE_BIN=/fixture/opencode');

// Tool restrictions and permission modes.
$toolLess = $source('claude');
SidecarStore::set_no_tools($toolLess['name']);
assert_equal(null, ReceiverPicker::pick($toolLess, $usage(), true, []), 'no_tools set through the store: no receiver');
assert_equal(['agent' => 'claude', 'profile' => 'work'], ReceiverPicker::pick($toolLess, $usage(), false, []), 'an unblocked tool-less source keeps itself');
$ruled = $source('claude');
SidecarStore::set_tool_rules($ruled['name'], null, 'Bash');
assert_equal(['agent' => 'claude', 'profile' => 'personal'], ReceiverPicker::pick($ruled, $usage(), true, ['mode' => 'plan']), 'tool rules cannot cross agents: only the other Claude profile remains');
assert_equal(['agent' => 'claude', 'profile' => 'personal'], ReceiverPicker::pick($source('claude'), $usage(), true, ['mode' => 'dontAsk']), 'dontAsk cannot cross agents: only the other Claude profile remains');
foreach (['', 'manual', 'accept edits', 'auto'] as $mode) {
    assert_equal(['agent' => 'codex', 'profile' => null], ReceiverPicker::pick($source('claude'), $usage(), true, ['mode' => $mode]), "mode '$mode' reaches codex");
    assert_equal(['agent' => 'opencode', 'profile' => null], ReceiverPicker::pick($source('claude'), $usage(['codex' => ['quota' => $limited]]), true, ['mode' => $mode]), "mode '$mode' reaches OpenCode");
}
assert_equal(['agent' => 'codex', 'profile' => null], ReceiverPicker::pick($source('claude'), $usage(), true, ['mode' => 'plan']), 'a read-only source still reaches codex, which can start read-only');
$noOpenCode = $usage(['codex' => ['quota' => $limited]]);
assert_equal(['agent' => 'claude', 'profile' => 'personal'], ReceiverPicker::pick($source('claude'), $noOpenCode, true, ['mode' => 'plan']), 'a read-only source is not offered OpenCode, which has no permission modes');
assert_equal(null, ReceiverPicker::pick($source('claude'), $usage(['codex' => ['quota' => $limited], 'claude' => $claude('limited', 'limited')]), true, ['mode' => 'plan']), 'read-only source with nothing else reproducible: no receiver');
assert_equal(['agent' => 'opencode', 'profile' => null], ReceiverPicker::pick($source('codex'), $usage(['codex' => ['quota' => $limited], 'claude' => $claude('limited', 'limited')]), true, ['mode' => 'workspace-write']), 'a codex workspace-write source reaches OpenCode');
assert_equal(null, ReceiverPicker::pick($source('codex'), $usage(['codex' => ['quota' => $limited], 'claude' => $claude('limited', 'limited')]), true, ['mode' => 'plan']), 'a read-only codex source is not offered OpenCode');

// pick() reads the park line from the passed Thresholds too.
$codex94 = ['claude' => ['profiles' => ['work' => ['quota' => $limited], 'personal' => ['quota' => $limited]]],
    'codex' => ['quota' => ['session' => ['pct' => 94], 'week_all' => ['pct' => 40]]],
    'opencode' => ['profiles' => ['default' => ['quota' => $limited]]],
    'antigravity' => ['profiles' => ['default' => ['quota' => $limited]]]];
assert_equal(null, ReceiverPicker::pick($source('claude', 'work'), $codex94, true, []), 'default park line: a 94% Codex is not a receiver');
assert_equal(['agent' => 'codex', 'profile' => null], ReceiverPicker::pick($source('claude', 'work'), $codex94, true, [], $t95), 'QUOTA_PARK=95: a 94% Codex is a receiver');

echo "ok\n";
test_exit();
