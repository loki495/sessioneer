<?php

declare(strict_types=1);

/**
 * The host-agent actions `launch_wake_list`, `launch_wake_show` and `launch_wake_cancel`, through the
 * agent's own dispatch_action(): request keys, reply shapes, target resolution, bad input, and the
 * rule that a cancel is not refused when it names a `from` session (the dashboard sends none; a session
 * asks Andres first by instruction, not by code). `at` is the stub of tests/lib/wake_fixture.php.
 */

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/wake_fixture.php';

$rig = new WakeRig('wake-actions');
putenv('TMUX_SOCKET=' . $rig->root . '/tmux/socket');
putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
putenv('CLAUDE_HEADLESS_SOCKET=' . $rig->root . '/no-claude-manager.sock');
putenv('CODEX_BRIDGE_SOCKET=' . $rig->root . '/no-codex-bridge.sock');
putenv('SOCKET_CLIENT_FAIL_FAST_IF_ABSENT=1');
putenv('PUSH_SQLITE_FILE=' . $rig->root . '/push.sqlite');

require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\SessionWakeService;
use HostAgent\Stores\SidecarStore;

SidecarStore::write_sidecar('claude-headless-w1', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'conv-w1', 'agent' => 'claude', 'profile' => 'work']);
SidecarStore::write_sidecar('claude-headless-w2', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent' => 'claude']);
SidecarStore::write_sidecar('oc-w3', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'ses_abc', 'agent' => 'opencode']);

$act = static function (array $request): array {
    try {
        return dispatch_action($request);
    } catch (\Throwable $e) {
        assert_true(false, 'dispatch_action ' . ($request['action'] ?? '?') . ' did not throw (' . get_class($e) . ': ' . $e->getMessage() . ')');

        return ['ok' => null, 'message' => 'threw'];
    }
};
$noLeak = static fn (array $reply): bool => !str_contains(json_encode($reply), 'hunter2') && !str_contains(json_encode($reply), 'atrun') && !str_contains(json_encode($reply), 'Execution directory');
$epoch = static fn (string $local): int => wake_epoch($local);
$wakeKeys = ['agent', 'agent_session_id', 'at', 'at_local', 'job', 'profile', 'prompt_length', 'prompt_preview', 'session', 'state', 'title', 'workdir'];
$withPrompt = array_merge($wakeKeys, ['prompt']);
sort($withPrompt);
$hostile = "it's \"quoted\" \$HOME `date` \\ back\n--workdir";
$future = static fn (int $days): int => intdiv(time(), 60) * 60 + $days * 86400;

try {
    $rig->add(11, $future(1), WakeRig::body(SessionWakeService::script('claude', 'conv-w1', '/tmp/proj', 'work', $hostile)));
    $rig->add(12, $future(2), WakeRig::body('echo hi'));
    $rig->add(16, intdiv(time(), 60) * 60 - 120, WakeRig::body(SessionWakeService::script('claude', 'conv-w1', '', '', 'running')), '=');
    $rig->add(19, $future(3), WakeRig::body(SessionWakeService::script('claude', 'conv-gone', '', '', 'orphan')));
    $rig->add(20, $future(1), WakeRig::body(SessionWakeService::script('opencode', 'ses_abc', '', '', 'oc wake')));
    $rig->add(18, $future(4), null);

    echo "launch_wake_list\n";
    $r = $act(['action' => 'launch_wake_list']);
    assert_equal(true, $r['ok'] ?? null, 'ok');
    assert_equal(SessionWakeService::system_timezone(), $r['time_zone'] ?? null, 'time_zone is the machine zone (here America/New_York)');
    assert_equal('America/New_York', $r['time_zone'] ?? null, 'and it follows TZ');
    assert_equal([16, 11, 20, 19], array_map(static fn (array $w): int => $w['job'], $r['wakes'] ?? []), 'all four Sessioneer wake-ups, by time then job; unrelated and vanished jobs left out');
    assert_true(!isset($r['truncated']), 'not truncated');
    $keys = array_keys($r['wakes'][1] ?? []);
    sort($keys);
    assert_equal($wakeKeys, $keys, 'a wake has the contract keys and no prompt');
    assert_true($noLeak($r), 'no raw at -c output in the reply');
    assert_equal(['running', 'scheduled'], [$r['wakes'][0]['state'] ?? null, $r['wakes'][1]['state'] ?? null], 'states are carried');

    $r = $act(['action' => 'launch_wake_list', 'target' => 'claude-headless-w1']);
    assert_equal([16, 11], array_map(static fn (array $w): int => $w['job'], $r['wakes'] ?? []), 'a session name narrows the list to that conversation');
    $r = $act(['action' => 'launch_wake_list', 'target' => 'conv-w1']);
    assert_equal([16, 11], array_map(static fn (array $w): int => $w['job'], $r['wakes'] ?? []), 'a conversation id narrows it the same way');
    $r = $act(['action' => 'launch_wake_list', 'target' => 'oc-w3']);
    assert_equal([20], array_map(static fn (array $w): int => $w['job'], $r['wakes'] ?? []), 'an opencode session resolves through its conversation id');
    $r = $act(['action' => 'launch_wake_list', 'target' => 'claude-headless-w2']);
    assert_equal(true, $r['ok'] ?? null, 'a session with no conversation yet: ok');
    assert_equal([], $r['wakes'] ?? null, 'and no wakes');
    assert_true(!array_key_exists('truncated', $r), 'and no truncated key');
    $r = $act(['action' => 'launch_wake_list', 'target' => 'nope']);
    assert_equal(false, $r['ok'] ?? null, 'an unknown target is refused');
    assert_contains("No tracked session found for 'nope'", (string)($r['message'] ?? ''), 'with the usual not-found message');
    assert_true(!isset($r['wakes']), 'and no wakes');

    $r = $act(['action' => 'launch_wake_list', 'include_prompt' => true]);
    foreach ($r['wakes'] ?? [] as $w) {
        $keys = array_keys($w);
        sort($keys);
        assert_equal($withPrompt, $keys, "include_prompt: job {$w['job']} carries its prompt");
    }
    $byJob = array_column($r['wakes'] ?? [], null, 'job');
    assert_equal($hostile, $byJob[11]['prompt'] ?? null, 'include_prompt: the prompt is byte-exact');
    $r = $act(['action' => 'launch_wake_list', 'include_prompt' => false]);
    assert_true(!array_key_exists('prompt', $r['wakes'][0] ?? ['prompt' => 1]), 'include_prompt false: no prompt');

    $r = $act(['action' => 'launch_wake_list', 'from' => 'claude-headless-w2']);
    assert_equal([16, 11, 20, 19], array_map(static fn (array $w): int => $w['job'], $r['wakes'] ?? []), 'from is ignored: a session may list');
    $r = $act(['action' => 'launch_wake_list', 'from' => 'claude-headless-w1', 'target' => 'conv-w1']);
    assert_equal(true, $r['ok'] ?? null, 'from naming the target itself is ignored too');

    echo "launch_wake_list when at is unhappy\n";
    $rig->clear_cache();
    touch($rig->dir . '/fail-l');
    $r = $act(['action' => 'launch_wake_list']);
    assert_equal([false, 'Could not read the at queue: at: cannot read the queue'], [$r['ok'] ?? null, $r['message'] ?? null], 'at -l failing: the message with the first stderr line');
    @unlink($rig->dir . '/fail-l');
    putenv('AT_BIN=/nonexistent/at');
    $r = $act(['action' => 'launch_wake_list']);
    assert_equal([false, 'The at command is not available (AT_BIN=/nonexistent/at)'], [$r['ok'] ?? null, $r['message'] ?? null], 'no at binary: the not-available message');
    foreach (['launch_wake_show', 'launch_wake_cancel'] as $action) {
        $r = $act(['action' => $action, 'job' => 11]);
        assert_equal([false, 'The at command is not available (AT_BIN=/nonexistent/at)'], [$r['ok'] ?? null, $r['message'] ?? null], "{$action}: the same message");
    }
    putenv('AT_BIN=' . $rig->dir . '/at');
    assert_equal('', $rig->removed(), 'nothing removed so far');

    echo "launch_wake_show\n";
    $r = $act(['action' => 'launch_wake_show', 'job' => 11]);
    assert_equal(true, $r['ok'] ?? null, 'ok');
    $keys = array_keys($r['wake'] ?? []);
    sort($keys);
    assert_equal($withPrompt, $keys, 'the wake carries the contract keys and its prompt');
    assert_equal($hostile, $r['wake']['prompt'] ?? null, 'the prompt is byte-exact');
    assert_equal('claude-headless-w1', $r['wake']['session'] ?? null, 'session resolved');
    assert_true($noLeak($r), 'no raw at -c output');
    assert_equal('running', $act(['action' => 'launch_wake_show', 'job' => 16])['wake']['state'] ?? null, 'a running job can be shown');
    assert_equal([false, 'Job 12 is not a Sessioneer wake-up'], [$act(['action' => 'launch_wake_show', 'job' => 12])['ok'] ?? null, $act(['action' => 'launch_wake_show', 'job' => 12])['message'] ?? null], 'an unrelated job is not shown');
    $r = $act(['action' => 'launch_wake_show', 'job' => 999]);
    assert_equal([false, true, 'Wake-up 999 no longer exists: it already ran or was cancelled'], [$r['ok'] ?? null, $r['gone'] ?? null, $r['message'] ?? null], 'a job that is gone');
    $r = $act(['action' => 'launch_wake_show', 'job' => 18]);
    assert_equal([false, true], [$r['ok'] ?? null, $r['gone'] ?? null], 'a queue row whose job vanished is gone');
    foreach (['missing' => null, 'abc' => 'abc', 'zero' => 0, 'negative' => -3, 'null' => 'NULL', 'an array' => [1], 'empty string' => '', 'a numeric string' => '11', 'a float' => 1.5, 'a bool' => true, 'a float with a whole value' => 11.0] as $label => $bad) {
        $request = ['action' => 'launch_wake_show'];
        if ($label !== 'missing') {
            $request['job'] = $bad === 'NULL' ? null : $bad;
        }
        $r = $act($request);
        assert_equal([false, 'Needs an at job number'], [$r['ok'] ?? null, $r['message'] ?? null], "show with a {$label} job: needs a job number");
    }

    $rig->clear_calls();
    foreach (['launch_wake_show', 'launch_wake_cancel'] as $action) {
        foreach ([null, 'abc', 0, -3, '11', 1.5, true, [1]] as $bad) {
            $act(['action' => $action, 'job' => $bad]);
        }
    }
    assert_equal([], $rig->calls(), 'a job that is not an integer above 0 is refused before any at call');
    assert_equal(true, ($act(['action' => 'launch_wake_show', 'job' => 11, 'from' => 'claude-headless-w2'])['ok'] ?? null), 'show ignores from too');
    $rig->clear_calls();

    echo "launch_wake_cancel refusals remove nothing\n";
    foreach (['missing' => null, 'abc' => 'abc', 'zero' => 0, 'negative' => -3, 'null' => 'NULL', 'an array' => [1], 'empty string' => '', 'a numeric string' => '11', 'a float' => 1.5, 'a bool' => true, 'a float with a whole value' => 11.0] as $label => $bad) {
        $request = ['action' => 'launch_wake_cancel'];
        if ($label !== 'missing') {
            $request['job'] = $bad === 'NULL' ? null : $bad;
        }
        $r = $act($request);
        assert_equal([false, 'Needs an at job number'], [$r['ok'] ?? null, $r['message'] ?? null], "cancel with a {$label} job: needs a job number");
    }
    $r = $act(['action' => 'launch_wake_cancel', 'job' => 12]);
    assert_equal([false, 'Job 12 is not a Sessioneer wake-up'], [$r['ok'] ?? null, $r['message'] ?? null], 'an unrelated job');
    $r = $act(['action' => 'launch_wake_cancel', 'job' => 16]);
    assert_equal([false, 'Wake-up 16 is already running'], [$r['ok'] ?? null, $r['message'] ?? null], 'a running job');
    $r = $act(['action' => 'launch_wake_cancel', 'job' => 18]);
    assert_equal([false, true, 'Wake-up 18 no longer exists: it already ran or was cancelled'], [$r['ok'] ?? null, $r['gone'] ?? null, $r['message'] ?? null], 'a vanished job');
    touch($rig->dir . '/fail-r');
    $r = $act(['action' => 'launch_wake_cancel', 'job' => 11]);
    assert_equal([false, 'Could not cancel wake-up 11: Permission denied removing 11'], [$r['ok'] ?? null, $r['message'] ?? null], 'at -r failing');
    @unlink($rig->dir . '/fail-r');
    assert_equal('', $rig->removed(), 'removed.log is still empty after every refusal');

    echo "launch_wake_cancel\n";
    $r = $act(['action' => 'launch_wake_cancel', 'job' => 11]);
    assert_equal(true, $r['ok'] ?? null, 'a Sessioneer wake-up is cancelled');
    assert_equal(11, $r['job'] ?? null, 'job');
    assert_equal('claude-headless-w1', $r['name'] ?? null, 'name is the session');
    assert_equal('Cancelled wake-up 11 (' . (new DateTimeImmutable('@' . $future(1)))->setTimezone(new DateTimeZone('America/New_York'))->format('Y-m-d H:i T') . ')', $r['message'] ?? null, 'the message names the job and its local time');
    assert_equal("11\n", $rig->removed(), 'at -r 11 ran');
    assert_true($noLeak($r), 'no raw at -c output');
    $r = $act(['action' => 'launch_wake_cancel', 'job' => 19, 'from' => 'claude-headless-w2']);
    assert_equal(true, $r['ok'] ?? null, 'a cancel naming a tracked session as from is NOT refused');
    assert_equal('', $r['name'] ?? 'x', 'name is empty when no session tracks the conversation');
    assert_equal("11\n19\n", $rig->removed(), 'and the job was removed');
    $r = $act(['action' => 'launch_wake_cancel', 'job' => 20, 'from' => 'oc-w3']);
    assert_equal([true, 'oc-w3'], [$r['ok'] ?? null, $r['name'] ?? null], 'a session may even cancel its own wake-up: the rule is an instruction, not code');
    $r = $act(['action' => 'launch_wake_cancel', 'job' => 20, 'from' => 'no-such-session']);
    assert_equal([false, true], [$r['ok'] ?? null, $r['gone'] ?? null], 'an untracked from changes nothing: the second cancel is gone');

    echo "the existing launch_wake keeps its refusal inside a session\n";
    $r = $act(['action' => 'launch_wake', 'target' => 'conv-w1', 'at' => time() + 7200, 'message' => 'continue', 'from' => 'claude-headless-w2']);
    assert_equal(false, $r['ok'] ?? null, 'scheduling from a tracked session is still refused');
    assert_contains('outside any session', (string)($r['message'] ?? ''), 'with the old reason');

    echo "only flags reach at\n";
    foreach ($rig->calls() as $line) {
        assert_true(preg_match('/^(-l|-c \d+|-r \d+|-t \d+)$/', $line) === 1, "at was called with flags only (saw: {$line})");
    }
    assert_true(!is_file($rig->root . '/atq-atrm.log'), 'atq and atrm were never called by name');
} finally {
    $rig->cleanup();
}

test_exit();
