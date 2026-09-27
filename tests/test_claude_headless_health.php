<?php

declare(strict_types=1);

/**
 * The "Claude Code headless" health-box section (ClaudeHeadlessHealthService).
 * build_checks() is driven with fake inputs so every green and red branch is
 * asserted as the exact check outcome; the real-input path is only smoke-tested
 * (it must report the manager as unavailable, never crash, with no manager).
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\ClaudeHeadlessHealthService as H;
use HostAgent\Services\Config;
use HostAgent\Services\PushHealthService;
use HostAgent\Stores\GlobalStateStore;

$realPushSqliteFile = Config::push_sqlite_path();
$fixtureDir = sys_get_temp_dir() . '/sessioneer-test-claude-health-' . bin2hex(random_bytes(4));
mkdir($fixtureDir, 0700, true);
putenv("PUSH_SQLITE_FILE={$fixtureDir}/push.sqlite");

if (Config::push_sqlite_path() === $realPushSqliteFile) {
    fwrite(STDERR, "REFUSING TO RUN: PUSH_SQLITE_FILE resolves to the real host state file.\n");
    exit(1);
}

register_shutdown_function(static function () use ($fixtureDir): void {
    exec('rm -rf ' . escapeshellarg($fixtureDir));
});

$now = 2_000_000_000;
$unit = 'sessioneer-claude-headless-manager.service';

/** @param array<string, mixed> $over */
function reply(array $over = []): array
{
    global $now;

    return array_merge([
        'ok' => true, 'pid' => 4242, 'children' => 2, 'max_children' => 8, 'rss_mb' => 1300,
        'spawn_blocked' => null, 'last_api_key_source' => 'none', 'claude_version' => H::TESTED_CLAUDE_VERSION,
        'rate_limit' => ['seen_at' => $now - 30],
    ], $over);
}

/** @return array<string, array{ok:bool, detail:?string, label:string}> checks by key */
function by_key(array $checks): array
{
    $out = [];

    foreach ($checks as $c) {
        $out[$c['key']] = $c;
        assert_equal(H::SECTION, $c['section'], "{$c['key']}: is in the Claude Code headless section");
    }

    return $out;
}

/** @param array<string, mixed>|null $reply */
function run(?array $reply, string $bin = '/usr/bin/claude', bool $exec = true, string $state = 'active', bool $enabled = true, ?int $captured = null): array
{
    global $now, $unit;

    return by_key(H::build_checks($bin, $exec, $unit, $state, $enabled, $reply, $captured ?? $now - 10, $now));
}

echo "Healthy\n";
$c = run(reply());
assert_equal(['claude_headless_cli', 'claude_headless_manager', 'claude_headless_spawn', 'claude_headless_credential', 'claude_headless_version', 'claude_headless_capacity', 'claude_headless_quota'], array_keys($c), 'every proposed check is present');
assert_true(array_reduce($c, static fn (bool $carry, array $x): bool => $carry && $x['ok'], true), 'and all are green');
assert_contains('pid 4242', (string)$c['claude_headless_manager']['detail'], 'the manager check names its pid');
assert_contains('2/8 processes, 1300 MB', (string)$c['claude_headless_capacity']['detail'], 'capacity shows live processes and memory');
assert_contains('the tested version', (string)$c['claude_headless_version']['detail'], 'the tested version is called out');
assert_equal('claude.ai login (no API key)', $c['claude_headless_credential']['detail'], 'the credential is the subscription login');

echo "CLI\n";
$c = run(reply(), '', false);
assert_equal(false, $c['claude_headless_cli']['ok'], 'an unset CLAUDE_BIN is red');
assert_contains('CLAUDE_BIN is not set', (string)$c['claude_headless_cli']['detail'], 'and says how to fix it');
$c = run(reply(), '/nope/claude', false);
assert_equal(false, $c['claude_headless_cli']['ok'], 'a CLAUDE_BIN that is not an executable file is red');
assert_contains('/nope/claude is not an executable file', (string)$c['claude_headless_cli']['detail'], 'naming the path');

echo "Manager service\n";
$c = run(null, state: 'inactive');
assert_equal(['claude_headless_cli', 'claude_headless_manager'], array_keys($c), 'with the service down only the checks that can be answered are reported');
assert_equal(false, $c['claude_headless_manager']['ok'], 'an inactive unit is red');
assert_contains("{$unit}: inactive", (string)$c['claude_headless_manager']['detail'], 'naming the unit and its state');
assert_contains('install.sh', (string)$c['claude_headless_manager']['detail'], 'and pointing at the installer');
$c = run(null, state: '');
assert_contains('not installed', (string)$c['claude_headless_manager']['detail'], 'an unknown unit says it is not installed');
$c = run(null, state: 'failed');
assert_contains('failed', (string)$c['claude_headless_manager']['detail'], 'a failed unit says so');
$c = run(reply(), enabled: false);
assert_equal(false, $c['claude_headless_manager']['ok'], 'an active but not enabled unit is red (it would not survive a reboot)');
assert_contains('not enabled', (string)$c['claude_headless_manager']['detail'], 'saying it is not enabled');
$c = run(['ok' => false, 'message' => 'Cannot reach Claude headless manager: connection refused']);
assert_equal(false, $c['claude_headless_manager']['ok'], 'an active unit whose socket does not answer is red');
assert_contains('active but Cannot reach Claude headless manager', (string)$c['claude_headless_manager']['detail'], 'with the real failure message');
assert_equal(['claude_headless_cli', 'claude_headless_manager'], array_keys($c), 'and no manager-derived check is reported from a failed reply');
$c = run(['ok' => false]);
assert_contains('the manager did not answer', (string)$c['claude_headless_manager']['detail'], 'a reply with no message still gets a handled detail');

echo "Spawn guard and credential\n";
$c = run(reply(['spawn_blocked' => "Claude reported apiKeySource='ANTHROPIC_API_KEY'"]));
assert_equal(false, $c['claude_headless_spawn']['ok'], 'a tripped billing guardrail is red');
assert_contains('apiKeySource', (string)$c['claude_headless_spawn']['detail'], 'with the guardrail\'s own reason');
$c = run(reply(['last_api_key_source' => 'ANTHROPIC_API_KEY']));
assert_equal(false, $c['claude_headless_credential']['ok'], 'an API-key credential source is red');
assert_contains("apiKeySource='ANTHROPIC_API_KEY'", (string)$c['claude_headless_credential']['detail'], 'naming the source');
$c = run(reply(['last_api_key_source' => '(not reported)']));
assert_equal(false, $c['claude_headless_credential']['ok'], 'a CLI that does not report its credential cannot be verified: red');
$c = run(reply(['last_api_key_source' => null]));
assert_equal(true, $c['claude_headless_credential']['ok'], 'no session started yet is not a problem');
$c = run(reply(['last_api_key_source' => 12]));
assert_contains("apiKeySource='unknown'", (string)$c['claude_headless_credential']['detail'], 'a garbage credential value is reported, not crashed on');

echo "Version\n";
$c = run(reply(['claude_version' => '2.1.100']));
assert_equal(false, $c['claude_headless_version']['ok'], 'a version older than the tested one is red');
assert_contains('older than ' . H::TESTED_CLAUDE_VERSION, (string)$c['claude_headless_version']['detail'], 'saying so');
$c = run(reply(['claude_version' => '2.2.0']));
assert_equal(true, $c['claude_headless_version']['ok'], 'a newer version is noted, not failed');
assert_contains('tests/run.sh --live', (string)$c['claude_headless_version']['detail'], 'with the instruction to re-verify');
$c = run(reply(['claude_version' => null]));
assert_equal(true, $c['claude_headless_version']['ok'], 'no version seen yet is fine');
$c = run(reply(['claude_version' => '']));
assert_contains('not seen yet', (string)$c['claude_headless_version']['detail'], 'an empty version counts as not seen');

echo "Capacity\n";
$c = run(reply(['children' => 8, 'max_children' => 8]));
assert_equal(false, $c['claude_headless_capacity']['ok'], 'a full manager is red');
assert_contains('new sessions are refused', (string)$c['claude_headless_capacity']['detail'], 'saying what that means');
$c = run(reply(['children' => 3, 'max_children' => 0]));
assert_equal(true, $c['claude_headless_capacity']['ok'], 'an unreported maximum does not read as full');

echo "Quota footer\n";
$c = run(reply(['rate_limit' => null]));
assert_equal(true, $c['claude_headless_quota']['ok'], 'no reading from the manager yet is fine');
$c = run(reply(), captured: $now - 100);
assert_equal(true, $c['claude_headless_quota']['ok'], 'a stored capture close to the manager\'s reading is current');
assert_contains('stored 1m ago', (string)$c['claude_headless_quota']['detail'], 'showing its age');
$c = run(reply(['rate_limit' => ['seen_at' => $now - 30]]), captured: $now - 163_000);
assert_equal(false, $c['claude_headless_quota']['ok'], 'a stored capture days behind the manager\'s reading is red (the regression this catches)');
assert_contains('the manager saw a reading 30s ago but last stored 45h ago', (string)$c['claude_headless_quota']['detail'], 'saying how far behind');
$c = by_key(H::build_checks('/usr/bin/claude', true, $unit, 'active', true, reply(), null, $now));
assert_equal(false, $c['claude_headless_quota']['ok'], 'no stored quota at all while the manager has a reading is red');
assert_contains('nothing stored', (string)$c['claude_headless_quota']['detail'], 'saying nothing is stored');

echo "Real inputs\n";
$real = by_key(H::checks());
assert_true(isset($real['claude_headless_cli'], $real['claude_headless_manager']), 'the real path reports the CLI and manager checks');
assert_equal(false, $real['claude_headless_manager']['ok'], 'with no manager (test unit and socket do not exist) the manager check is red, handled');
assert_contains('sessioneer-test-fake-claude-headless.service', (string)$real['claude_headless_manager']['detail'], 'naming the configured unit, not the real one');

GlobalStateStore::write(Config::quota_live_state_key(), ['session' => ['pct' => 1, 'resets_at' => 1], 'captured_at' => 1234]);
$health = PushHealthService::health_check()['checks'];
$keys = array_column($health, 'key');
assert_true(in_array('claude_headless_manager', $keys, true), 'the dashboard health check includes the headless section');
$sections = array_values(array_unique(array_column($health, 'section')));
$claudeAt = array_keys(array_filter($sections, static fn (string $s): bool => str_starts_with($s, 'Claude Code')));
assert_true(count($claudeAt) >= 2 && $claudeAt === range($claudeAt[0], $claudeAt[0] + count($claudeAt) - 1), 'all Claude sections are adjacent in the health box');
assert_equal(H::SECTION, $sections[$claudeAt[0]], 'and the headless section comes first among them');
$hooks = array_values(array_filter($health, static fn (array $x): bool => str_starts_with((string)$x['key'], 'hook_default_')));
assert_true($hooks !== [] && array_reduce($hooks, static fn (bool $carry, array $x): bool => $carry && (str_contains((string)$x['detail'], 'tmux and hand-started') || $x['ok'] === false), true), 'hook checks say they are for tmux and hand-started sessions');

test_exit();
