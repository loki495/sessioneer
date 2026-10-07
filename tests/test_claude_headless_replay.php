<?php

declare(strict_types=1);

/**
 * Replays every captured Claude Code stream-json stdout
 * (tests/fixtures/claude_stream_json_*_v2_1_278.ndjson) through a REAL
 * ClaudeHeadlessManager via tests/fixtures/fake_claude_replay, and asserts the
 * manager digests what the real CLI actually emitted: every prompt is surfaced
 * with its exact request id and untruncated input, answers go back under that
 * id, the sidecar follows the session id the last `init` reported, the
 * credential/overage guardrails do not misfire, nothing is logged as an
 * unexpected error, and the session ends in the state the capture's last event
 * implies. Never spawns the real `claude`, never billable.
 *
 * This catches drift between the manager and a frozen capture; a NEW CLI
 * version changing shape is what test_claude_headless_live.php is for
 * (re-capture into a new _v<version> set after an upgrade and
 * this test picks it up by glob).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/claude_headless.php';

use HostAgent\Services\Config;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$root = sys_get_temp_dir() . '/sessioneer-test-claude-replay-' . getmypid();
@mkdir($root . '/home/.claude/projects', 0700, true);

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

register_shutdown_function(static function () use (&$managers, &$madeSessions, $root): void {
    foreach ($managers as $m) {
        if (is_resource($m['proc'])) {
            @proc_terminate($m['proc'], SIGTERM);
            usleep(300000);
            @proc_terminate($m['proc'], SIGKILL);
            @proc_close($m['proc']);
        }
    }

    foreach ($madeSessions as $name) {
        SidecarStore::delete_sidecar($name);
        SessionStatusStore::delete_status($name);
    }

    exec('rm -rf ' . escapeshellarg($root));
});

$captures = glob(__DIR__ . '/fixtures/claude_stream_json_*.ndjson') ?: [];
sort($captures);
assert_true(count($captures) >= 14, 'the recorded captures are present (' . count($captures) . ' found)');

$runTag = (string)getmypid();

foreach ($captures as $i => $file) {
    $label = preg_replace('/^claude_stream_json_|\.ndjson$/', '', basename($file));
    echo "Replay: {$label}\n";

    $events = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $decoded = json_decode($line, true);
        assert_true(is_array($decoded), 'every capture line is JSON');
        $events[] = $decoded;
    }

    $requests = [];
    $lastInit = null;
    $lastResult = null;
    $sawRateLimit = false;

    foreach ($events as $e) {
        $type = $e['type'] ?? '';

        if ($type === 'control_request' && ($e['request']['subtype'] ?? '') === 'can_use_tool') {
            $requests[] = $e;
        } elseif ($type === 'system' && ($e['subtype'] ?? '') === 'init') {
            $lastInit = $e;
        } elseif ($type === 'result') {
            $lastResult = $e;
        } elseif ($type === 'rate_limit_event') {
            $sawRateLimit = true;
        }
    }

    // A capture "ends on an open prompt" when the last prompt is never followed by a result
    // (the process was killed there); trailing hook events do not count.
    $lastRequestAt = -1;
    $lastResultAt = -1;

    foreach ($events as $at => $e) {
        if (($e['type'] ?? '') === 'control_request' && ($e['request']['subtype'] ?? '') === 'can_use_tool') {
            $lastRequestAt = $at;
        } elseif (($e['type'] ?? '') === 'result') {
            $lastResultAt = $at;
        }
    }

    $endsOnRequest = $lastRequestAt > $lastResultAt;
    $name = sprintf('claude-headless-t%s-replay%02d', $runTag, $i);
    $m = start_manager($root, "r{$i}", [
        'CLAUDE_BIN' => __DIR__ . '/fixtures/fake_claude_replay',
        'FAKE_CLAUDE_REPLAY' => $file,
    ]);
    make_session($root, $name);

    $sent = call($m, 'sessioneer/sendInput', ['session' => $name, 'content' => 'replay']);
    assert_true(($sent['ok'] ?? false) === true, "{$label}: the replayed session starts");

    foreach ($requests as $k => $request) {
        $expectedId = (string)$request['request_id'];
        $isLast = $k === array_key_last($requests);
        $surfaced = wait_until(static fn (): bool => status_is($name, 'blocked')
            && (SessionStatusStore::read_status($name)['blocked']['request_id'] ?? null) === $expectedId);
        assert_true((bool)$surfaced, "{$label}: prompt " . ($k + 1) . " of " . count($requests) . " is surfaced under its own request id");

        $pending = call($m, 'sessioneer/pendingPrompt', ['session' => $name])['prompt'] ?? [];
        assert_equal($request['request']['tool_name'], $pending['tool_name'] ?? null, "{$label}: prompt " . ($k + 1) . " carries the tool name");
        assert_equal($request['request']['input'], $pending['tool_input'] ?? null, "{$label}: prompt " . ($k + 1) . " carries the exact, untruncated tool input");
        assert_equal(!empty($request['request']['requires_user_interaction']), $pending['requires_user_interaction'] ?? null, "{$label}: prompt " . ($k + 1) . " keeps requires_user_interaction");

        if ($isLast && $endsOnRequest) {
            assert_equal('blocked', call($m, 'sessioneer/status', ['session' => $name])['state'] ?? null, "{$label}: a capture that ends on an open prompt leaves the session blocked");
            continue;
        }

        $answered = call($m, 'sessioneer/answerPrompt', ['session' => $name, 'request_id' => $expectedId, 'response' => ['behavior' => 'allow', 'updatedInput' => $request['request']['input']]]);
        assert_true(($answered['ok'] ?? false) === true, "{$label}: prompt " . ($k + 1) . " is answered");
    }

    $sentAnswers = array_values(array_filter(fake_log($m), static fn (array $r): bool => isset($r['replay_answer'])));
    $answeredIds = array_map(static fn (array $r): mixed => $r['replay_answer']['request_id'] ?? null, $sentAnswers);
    $expectedIds = array_map(static fn (array $r): string => (string)$r['request_id'], $endsOnRequest ? array_slice($requests, 0, -1) : $requests);
    assert_equal($expectedIds, $answeredIds, "{$label}: every answer reached Claude under the request id it was for, in order");

    $lastInitId = (string)$lastInit['session_id'];
    assert_true((bool)wait_until(static fn (): bool => (SidecarStore::read_sidecar($name)['agent_session_id'] ?? null) === $lastInitId), "{$label}: the sidecar follows the session id of the last init");

    if (!$endsOnRequest) {
        assert_true((bool)wait_until(static fn (): bool => in_array(['replay_done' => true], fake_log($m), true)), "{$label}: the whole capture was consumed");
        assert_true((bool)wait_until(static fn (): bool => status_is($name, 'idle') && SessionStatusStore::read_status($name)['blocked'] === null), "{$label}: the session ends idle with no prompt");

        // last_turn_error is written in the SAME update_status() call as the
        // status/blocked fields the wait_until above already confirmed, so it
        // should never lag behind them - but reading it with its own
        // wait_until, rather than trusting that atomicity across two
        // separate reads, is what actually asserts the value this block
        // cares about instead of a proxy for it (found live 2026-09-28: a
        // plain unguarded read here flaked on a loaded CI runner).
        if (!empty($lastResult['is_error'])) {
            assert_true(
                (bool)wait_until(static fn (): bool => is_string(SessionStatusStore::read_status($name)['last_turn_error'] ?? null) && SessionStatusStore::read_status($name)['last_turn_error'] !== ''),
                "{$label}: a final is_error result is recorded as the session's error"
            );
        } else {
            assert_true(
                (bool)wait_until(static fn (): bool => SessionStatusStore::read_status($name)['last_turn_error'] === null),
                "{$label}: a final successful result leaves no error"
            );
        }

        $final = SessionStatusStore::read_status($name);

        if (is_array($lastResult['usage'] ?? null)) {
            assert_true(is_array($final['token_usage']), "{$label}: the final result's usage is recorded");
        }
    }

    $health = call($m, 'sessioneer/health');
    assert_equal('none', $health['last_api_key_source'] ?? null, "{$label}: the captured login is accepted as the subscription");
    assert_true(array_key_exists('spawn_blocked', $health) && $health['spawn_blocked'] === null, "{$label}: the captured events do not trip a billing guardrail");

    if ($sawRateLimit) {
        assert_true(is_numeric($health['rate_limit']['windows']['five_hour']['utilization'] ?? null), "{$label}: the captured rate-limit window is read");
    }

    $managerLog = (string)file_get_contents($m['log']);
    assert_true(!str_contains($managerLog, 'unexpected error'), "{$label}: no event raised an unexpected error");
    assert_true(!str_contains($managerLog, 'non-JSON'), "{$label}: no captured line was rejected as non-JSON");
    assert_true(!str_contains($managerLog, 'unsupported control request'), "{$label}: no captured request was refused as unsupported");

    proc_terminate($m['proc'], SIGTERM);
}

test_exit();
