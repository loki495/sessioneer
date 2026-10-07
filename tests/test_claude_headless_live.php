<?php

declare(strict_types=1);

/**
 * LIVE smoke test for the Claude headless runtime: a real ClaudeHeadlessManager
 * drives the REAL `claude` binary (haiku, tiny prompts) over stream-json on the
 * user's own logged-in account, to prove the manager still matches whatever the
 * currently installed CLI actually emits, not only the frozen captures
 * tests/test_claude_headless_replay.php replays.
 *
 * Deliberately NOT run by tests/run.sh's default suite - only `bash tests/run.sh
 * --live`. It needs the real binary and a logged-in account, is slower, and
 * spends a few cents of subscription usage (two short haiku turns). Skips
 * itself (exit 0, not a failure) when the binary or the real HOME is missing.
 *
 * What it proves against the real CLI:
 *  - the credential is the subscription login: init reports apiKeySource
 *    'none' even though the manager is started with a bogus ANTHROPIC_API_KEY
 *    in its environment (the manager must strip it), and no guardrail trips;
 *  - a plain turn completes, records model/usage, and writes the transcript
 *    file the transcript reader depends on;
 *  - a Write tool call arrives as a can_use_tool prompt with its exact input,
 *    a deny reaches Claude, the tool never runs, and the turn ends cleanly;
 *  - stop returns only after the process has exited.
 *
 * Run: `bash tests/run.sh --live`, or standalone:
 * `set -a && source tests/.env.testing; set +a; SESSIONEER_LIVE_HOME=$HOME php tests/test_claude_headless_live.php`
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/claude_headless.php';

use HostAgent\Services\Config;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

// Never Config::claude_bin(): in this test environment it resolves to the fake.
$realClaudeBin = (string)(getenv('SESSIONEER_LIVE_CLAUDE_BIN') ?: 'claude');
$foundPath = trim((string)shell_exec('command -v ' . escapeshellarg($realClaudeBin) . ' 2>/dev/null'));

if ($foundPath === '') {
    echo "SKIP: real '{$realClaudeBin}' binary not found on PATH - nothing to smoke-test here.\n";
    exit(0);
}

$home = (string)getenv('SESSIONEER_LIVE_HOME');

if ($home === '' || !is_dir($home . '/.claude')) {
    echo "SKIP: SESSIONEER_LIVE_HOME is not set or has no .claude directory - no real login to drive.\n";
    exit(0);
}

$root = sys_get_temp_dir() . '/sessioneer-test-claude-live-' . getmypid();
$workdir = $root . '/work';
@mkdir($workdir, 0700, true);

// Unconditional, not "only if unset": a session running AS a Sessioneer
// headless session (this file's own tests can run from inside one) inherits
// the REAL manager's environment - including SIDECAR_DIR - by construction
// (the manager passes its own env to every child it spawns), so an "if
// unset" guard would silently do nothing and this test would run against
// the real host's sessions.sqlite. Found live 2026-09-27: a direct `php tests/test_X.php` run from inside such
// a session locked and corrupted real session rows this way.
$realSidecarDir = Config::sidecar_dir();
putenv('SIDECAR_DIR=' . $root . '/sidecars');
@mkdir($root . '/sidecars', 0700, true);

if (Config::sidecar_dir() === $realSidecarDir) {
    fwrite(STDERR, "REFUSING TO RUN: SIDECAR_DIR resolves to the real host sidecar dir.\n");
    exit(1);
}

/** @var array<int, array{proc: resource, sock: string, log: string, fake: string}> $managers */
$managers = [];

/** @var array<int, string> $madeSessions */
$madeSessions = [];

// Claude names its project directory after the (real) working directory, so this
// is exactly the one directory the run created under the real ~/.claude.
$projectDir = $home . '/.claude/projects/' . preg_replace('/[^A-Za-z0-9]/', '-', (string)realpath($workdir));

register_shutdown_function(static function () use (&$managers, &$madeSessions, $root, $projectDir): void {
    foreach ($managers as $m) {
        if (is_resource($m['proc'])) {
            @proc_terminate($m['proc'], SIGTERM);
            usleep(500000);
            @proc_terminate($m['proc'], SIGKILL);
            @proc_close($m['proc']);
        }
    }

    foreach ($madeSessions as $name) {
        SidecarStore::delete_sidecar($name);
        SessionStatusStore::delete_status($name);
    }

    if (str_contains($projectDir, 'sessioneer-test-claude-live-') && is_dir($projectDir)) {
        exec('rm -rf ' . escapeshellarg($projectDir));
    }

    exec('rm -rf ' . escapeshellarg($root));
});

$name = 'claude-headless-live-' . getmypid();

echo "Live: headless Claude session on the real CLI\n";
$m = start_manager($root, 'live', [
    'CLAUDE_BIN' => $foundPath,
    'HOME' => $home,
    'HOME_ROOT' => $home,
    'CLAUDE_HEADLESS_STOP_GRACE_SECONDS' => '10',
]);
make_session($root, $name, ['workdir' => $workdir]);

$spawned = call($m, 'sessioneer/spawn', ['session' => $name, 'model' => 'haiku']);
assert_true(($spawned['ok'] ?? false) === true, 'live: the session is configured for haiku');

$sent = call($m, 'sessioneer/sendInput', ['session' => $name, 'content' => 'Reply with exactly the single word: pong']);
assert_true(($sent['ok'] ?? false) === true, 'live: the first message starts the real claude process');
$done = wait_until(static fn (): bool => status_is($name, 'idle') && (SessionStatusStore::read_status($name)['token_usage'] ?? null) !== null, 120.0);
$status = SessionStatusStore::read_status($name);
assert_true((bool)$done, 'live: the first turn completes (last error: ' . var_export($status['last_turn_error'] ?? null, true) . ')');
assert_equal(null, $status['last_turn_error'], 'live: a plain turn leaves no error (an expired login shows up here)');
assert_true(is_string($status['model']) && $status['model'] !== '', 'live: the model reported by init is recorded');
assert_true(is_array($status['token_usage']), 'live: the result usage is recorded');

$health = call($m, 'sessioneer/health');
assert_equal('none', $health['last_api_key_source'] ?? null, 'live: init reports apiKeySource none - the subscription login, not an API key');
assert_true(array_key_exists('spawn_blocked', $health) && $health['spawn_blocked'] === null, 'live: no billing guardrail tripped');
assert_true(is_string($health['claude_version']) && $health['claude_version'] !== '', 'live: the CLI version is reported (' . ($health['claude_version'] ?? '?') . ')');
assert_true(is_numeric($health['rate_limit']['windows']['five_hour']['utilization'] ?? null), 'live: a real five_hour rate-limit window was parsed');

$agentSessionId = (string)(SidecarStore::read_sidecar($name)['agent_session_id'] ?? '');
assert_true($agentSessionId !== '', 'live: the Claude session id is written to the sidecar');
assert_true(is_file("{$projectDir}/{$agentSessionId}.jsonl"), 'live: the transcript file the reader depends on exists where expected');

echo "Live: permission prompt over the real protocol\n";
call($m, 'sessioneer/sendInput', ['session' => $name, 'content' => 'Use the Write tool to create the file live-smoke.txt containing 1. Do nothing else.']);
$blocked = wait_until(static fn (): bool => status_is($name, 'blocked'), 120.0);
assert_true((bool)$blocked, 'live: a Write call arrives as a blocked prompt');
$pending = call($m, 'sessioneer/pendingPrompt', ['session' => $name])['prompt'] ?? [];
assert_equal('Write', $pending['tool_name'] ?? null, 'live: the prompt names the tool');
assert_true(is_string($pending['tool_input']['file_path'] ?? null) && str_ends_with($pending['tool_input']['file_path'], 'live-smoke.txt'), 'live: and carries the exact untruncated input');
assert_true(is_string($pending['request_id'] ?? null) && $pending['request_id'] !== '', 'live: with a request id to answer under');

$denied = call($m, 'sessioneer/answerPrompt', ['session' => $name, 'request_id' => (string)($pending['request_id'] ?? ''), 'response' => ['behavior' => 'deny', 'message' => 'Sessioneer live smoke test: not allowed']]);
assert_true(($denied['ok'] ?? false) === true, 'live: the deny is accepted');
assert_true((bool)wait_until(static fn (): bool => status_is($name, 'idle'), 120.0), 'live: the turn ends after the deny');
assert_true(!file_exists($workdir . '/live-smoke.txt'), 'live: the denied tool never ran');
assert_equal(null, SessionStatusStore::read_status($name)['blocked'], 'live: the blocked state is cleared');
assert_equal(null, SessionStatusStore::read_status($name)['last_turn_error'], 'live: a denied tool is a normal turn, not an error');

echo "Live: stop\n";
$pid = (int)(call($m, 'sessioneer/status', ['session' => $name])['pid'] ?? 0);
assert_true($pid > 1 && pid_alive($pid), 'live: the real process is running before stop');
$stopped = call($m, 'sessioneer/stop', ['session' => $name]);
assert_true(($stopped['ok'] ?? false) === true, 'live: stop is acknowledged');
assert_true(!pid_alive($pid), 'live: the process has exited by the time the reply arrives');
assert_equal('dormant', process_of($m, $name), 'live: the session is dormant, ready to resume');

if ($GLOBALS['__sessioneer_test_failures'] > 0) {
    $tail = @file_get_contents($m['log']);
    fwrite(STDOUT, "\n--- manager log ---\n" . substr((string)$tail, -2000) . "\n");
}

test_exit();
