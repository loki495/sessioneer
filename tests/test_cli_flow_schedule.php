<?php

declare(strict_types=1);

/**
 * `bin/sessioneer --resets` and `--schedule-reset`: the CLI over quota reset scheduling. Written from the Contract
 * only. Intent notes:
 *  - validation (fake host agent that records requests): every malformed command exits 1 with a specific stderr
 *    message, prints nothing on stdout and sends no flow_schedule request, so no job can exist; the --approve
 *    gate is the first of these;
 *  - request shapes (fake host): --resets sends flow_resets; --schedule-reset sends flow_resets first, then flow_schedule
 *    with agent, profile, approved === true, expected_reset_at equal to that profile's reset_at from the flow_resets
 *    reply, and exactly one of session (--wake TARGET), handoff_id or plan_id (strings), plus workdir when --workdir is
 *    given; it works from inside a tracked session; an unknown reset (null) stops before any flow_schedule request;
 *  - end to end (the real agent.php on fixtures, `at` stubbed by tests/lib/wake_fixture.php, quota state seeded in
 *    the sessions DB): --resets is read-only and lists the stored reset per agent and profile; an approved
 *    --schedule-reset queues exactly one job one minute after the reset, prints its id and time (--json prints the
 *    host reply), repeats idempotently, shows up in --wake-list and goes away with --wake-cancel; refusals exit 1
 *    with the host's message and queue nothing; the job carries no session variable from the caller's environment.
 */

require __DIR__ . '/lib/cli_flow.php';
require __DIR__ . '/lib/wake_fixture.php';

$wake = new WakeRig('cli-flow-schedule');
putenv('TMUX_SOCKET=' . $wake->root . '/tmux/socket');
putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
putenv('CLAUDE_HEADLESS_SOCKET=' . $wake->root . '/no-claude-manager.sock');
putenv('CODEX_BRIDGE_SOCKET=' . $wake->root . '/no-codex-bridge.sock');
putenv('SOCKET_CLIENT_FAIL_FAST_IF_ABSENT=1');
putenv('PUSH_SQLITE_FILE=' . $wake->root . '/push.sqlite');
putenv('OPENCODE_DB_PATH=' . $wake->root . '/no-opencode.db');
putenv('SESSIONEER_ATD_CHECK_CMD=true');
$bumpFlag = $wake->root . '/bump-flag';

require_once dirname(__DIR__) . '/vendor/autoload.php';

use HostAgent\Services\Config;
use HostAgent\Stores\GlobalStateStore;
use HostAgent\Stores\SidecarStore;

SidecarStore::write_sidecar('claude-headless-p1', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'conv-p1', 'agent' => 'claude', 'profile' => 'personal']);
SidecarStore::write_sidecar('claude-headless-w1', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'conv-w1', 'agent' => 'claude', 'profile' => 'work']);
$resetPersonal = intdiv(time(), 60) * 60 + 3 * 3600;
$resetWork = $resetPersonal + 1800;
GlobalStateStore::write(Config::quota_live_state_key(), ['captured_at' => time(), 'session' => ['pct' => 95, 'resets_at' => $resetPersonal]]);
GlobalStateStore::write(Config::quota_live_state_key('work'), ['captured_at' => time(), 'session' => ['pct' => 95, 'resets_at' => $resetWork]]);

$fake = new CliFlowRig('cli-flow-schedule-fake');
$realSocket = sys_get_temp_dir() . '/sessioneer-test-cli-flow-schedule-agent.sock';
$agent = start_harness(['php', dirname(__DIR__) . '/host-agent/agent.php'], $realSocket);
$driftSocket = sys_get_temp_dir() . '/sessioneer-test-cli-flow-schedule-drift.sock';
putenv('DRIFT_TARGET=' . $realSocket);
putenv('DRIFT_FLAG=' . $bumpFlag);
$drift = start_harness(['php', __DIR__ . '/fixtures/drift_proxy.php'], $driftSocket);
$noSession = ['SESSIONEER_SELF' => false, 'SESSIONEER_AGENT_SOCKET' => $realSocket, 'SESSIONEER_SESSION_NAME' => 'cc-leak-check'];
$cli = static fn (array $args): array => $fake->run($args, $noSession);
$validate = static fn (array $args): array => $fake->run($args, ['SESSIONEER_SELF' => false, 'SESSIONEER_AGENT_SOCKET' => $fake->socket]);
$creates = static fn (): int => count(array_filter($wake->calls(), static fn (string $l): bool => str_starts_with($l, '-t ')));
$scheduleRequests = static fn (): array => array_values(array_filter($fake->requests(), static fn (array $r): bool => ($r['action'] ?? '') === 'flow_schedule'));

try {
    echo "Validation: nothing reaches the host as a flow_schedule request\n";
    $bad = [
        'no --approve' => [['--schedule-reset', 'claude:personal', '--handoff', '5'], '--approve'],
        'no --approve, wake' => [['--schedule-reset', 'claude:personal', '--wake', 'conv-w1'], '--approve'],
        'no context option' => [['--schedule-reset', 'claude:personal', '--approve'], 'one of'],
        'wake and handoff' => [['--schedule-reset', 'claude:personal', '--approve', '--wake', 'conv-w1', '--handoff', '5'], 'one of'],
        'handoff and plan' => [['--schedule-reset', 'claude:personal', '--approve', '--handoff', '5', '--plan', '6'], 'one of'],
        'all three' => [['--schedule-reset', 'claude:personal', '--approve', '--wake', 'conv-w1', '--handoff', '5', '--plan', '6'], 'one of'],
        'handoff not digits' => [['--schedule-reset', 'claude:personal', '--approve', '--handoff', '5x'], '--handoff'],
        'plan not digits' => [['--schedule-reset', 'claude:personal', '--approve', '--plan', '1.5'], '--plan'],
        'plan negative' => [['--schedule-reset', 'claude:personal', '--approve', '--plan', '-1'], '--plan'],
        'empty agent' => [['--schedule-reset', '', '--approve', '--handoff', '5'], '--schedule-reset'],
        'empty profile' => [['--schedule-reset', 'claude:', '--approve', '--handoff', '5'], '--schedule-reset'],
        'empty agent with profile' => [['--schedule-reset', ':work', '--approve', '--handoff', '5'], '--schedule-reset'],
        'too many colons' => [['--schedule-reset', 'claude:a:b', '--approve', '--handoff', '5'], '--schedule-reset'],
        'with --list' => [['--schedule-reset', 'claude:personal', '--approve', '--handoff', '5', '--list'], 'cannot be combined'],
        'with --wake-list' => [['--schedule-reset', 'claude:personal', '--approve', '--handoff', '5', '--wake-list'], 'cannot be combined'],
        'with --rotate' => [['--schedule-reset', 'claude:personal', '--approve', '--handoff', '5', '--rotate'], 'cannot be combined'],
        'with --offer' => [['--schedule-reset', 'claude:personal', '--approve', '--handoff', '5', '--offer'], 'cannot be combined'],
        'with --resets' => [['--schedule-reset', 'claude:personal', '--approve', '--handoff', '5', '--resets'], 'cannot be combined'],
        'with --usage' => [['--schedule-reset', 'claude:personal', '--approve', '--handoff', '5', '--usage'], 'cannot be combined'],
        '--approve alone' => [['--approve', '--list'], '--approve'],
        '--approve with --rotate' => [['--rotate', '--handoff', '5', '--approve'], '--approve'],
        '--resets with --list' => [['--resets', '--list'], 'cannot be combined'],
        '--resets with --usage' => [['--resets', '--usage'], 'cannot be combined'],
        '--resets with a prompt' => [['--resets', 'hello'], '--resets'],
        '--resets with --approve' => [['--resets', '--approve'], '--approve'],
    ];
    foreach ($bad as $label => [$args, $needle]) {
        $fake->reset();
        [$code, $out, $err] = $validate($args);
        assert_equal(1, $code, "{$label}: exit 1");
        assert_contains($needle, $err, "{$label}: stderr names {$needle}");
        assert_equal('', $out, "{$label}: nothing on stdout");
        assert_equal([], $scheduleRequests(), "{$label}: no flow_schedule request");
    }
    assert_equal(0, $creates(), 'no validation failure created a job');

    echo "Request shapes against the fake host\n";
    $R = 1_900_000_000;
    $fake->reply(['ok' => true, 'agents' => ['claude' => ['label' => 'Claude', 'profiles' => ['personal' => ['reset_at' => $R], 'work' => ['reset_at' => null]]]], 'job' => 7, 'at' => $R + 60, 'existing' => false]);
    $inSession = ['SESSIONEER_SELF' => 'cc-self', 'SESSIONEER_AGENT_SOCKET' => $fake->socket];
    $fake->reset();
    [$code, $out, $err] = $fake->run(['--resets', '--json'], $inSession);
    assert_equal([0, 'flow_resets'], [$code, $fake->requests()[0]['action'] ?? null], '--resets --json: sends flow_resets (stderr: ' . trim($err) . ')');
    assert_equal(1, count($fake->requests()), '--resets: one request, read-only');
    assert_equal($R, json_decode($out, true)['agents']['claude']['profiles']['personal']['reset_at'] ?? null, '--resets --json: prints the host reply');
    [$code, $out] = $fake->run(['--resets'], $inSession);
    $lines = array_values(array_filter(explode("\n", $out), static fn (string $l): bool => str_contains($l, 'personal')));
    assert_equal([0, 1], [$code, count($lines)], '--resets: one line for the profile with a reset');
    assert_contains('claude', strtolower($lines[0] ?? ''), '--resets: the line names the agent');
    assert_true(str_contains(implode("\n", array_filter(explode("\n", $out), static fn (string $l): bool => str_contains($l, 'work'))), 'claude'), '--resets: a profile with no reset still gets a line');

    $shapes = [
        'wake' => [['--wake', 'conv-w1'], ['session' => 'conv-w1']],
        'handoff' => [['--handoff', '123'], ['handoff_id' => '123']],
        'plan' => [['--plan', '1765'], ['plan_id' => '1765']],
        'handoff with workdir' => [['--handoff', '123', '--workdir', '/tmp/proj'], ['handoff_id' => '123', 'workdir' => '/tmp/proj']],
    ];
    foreach ($shapes as $label => [$extra, $want]) {
        $fake->reset();
        [$code, $out, $err] = $fake->run(['--schedule-reset', 'claude:personal', '--approve', ...$extra], $inSession);
        $reqs = $fake->requests();
        assert_equal(0, $code, "{$label}: exit 0 from inside a session (stderr: " . trim($err) . ')');
        assert_equal(['flow_resets', 'flow_schedule'], array_map(static fn (array $r): mixed => $r['action'] ?? null, $reqs), "{$label}: flow_resets first, then flow_schedule");
        $req = $reqs[1] ?? [];
        assert_equal([true, 'claude', 'personal', $R], [$req['approved'] ?? null, $req['agent'] ?? null, $req['profile'] ?? null, $req['expected_reset_at'] ?? null], "{$label}: approved true, agent, profile and the looked-up reset");
        foreach ($want as $k => $v) {
            assert_equal($v, $req[$k] ?? null, "{$label}: {$k} sent");
        }
        foreach (array_diff(['session', 'handoff_id', 'plan_id'], array_keys($want)) as $k) {
            assert_true(!array_key_exists($k, $req), "{$label}: {$k} omitted");
        }
        if (!isset($want['workdir'])) {
            assert_true(!array_key_exists('workdir', $req), "{$label}: workdir omitted when not given");
        }
        assert_contains('7', $out, "{$label}: prints the job id");
    }
    $fake->reset();
    [$code, $out] = $fake->run(['--schedule-reset', 'claude:personal', '--approve', '--handoff', '1', '--json'], $inSession);
    assert_equal([0, 7, false], [$code, json_decode($out, true)['job'] ?? null, json_decode($out, true)['existing'] ?? null], '--json prints the flow_schedule reply');
    $fake->reset();
    [$code, $out, $err] = $fake->run(['--schedule-reset', 'claude:work', '--approve', '--handoff', '1'], $inSession);
    assert_equal([1, ''], [$code, $out], 'a profile with no known reset: exit 1, nothing on stdout');
    assert_true($err !== '' && str_contains(strtolower($err), 'reset'), 'a profile with no known reset: stderr says so');
    assert_equal([], $scheduleRequests(), 'a profile with no known reset: no flow_schedule request');
    $fake->reply(['ok' => true, 'agents' => ['claude' => ['label' => 'Claude', 'profiles' => ['personal' => ['reset_at' => $R]]]]]);
    $fake->reply(['ok' => false, 'message' => 'The reset time changed (stale expected_reset_at)']);
    [$code, $out, $err] = $fake->run(['--schedule-reset', 'claude:personal', '--approve', '--handoff', '1'], $inSession);
    assert_equal([1, ''], [$code, $out], 'a host refusal: exit 1, nothing on stdout');

    echo "--resets is read-only\n";
    [$code, $out, $err] = $cli(['--resets', '--json']);
    $json = json_decode($out, true);
    assert_equal(0, $code, '--resets --json: exit 0 (stderr: ' . trim($err) . ')');
    assert_true(is_array($json), '--resets --json: output is JSON');
    assert_contains((string)$resetPersonal, $out, '--resets --json: the stored default-account reset is listed');
    assert_equal($resetPersonal, $json['agents']['claude']['profiles']['personal']['reset_at'] ?? null, '--resets --json: the stored default-account reset');
    assert_equal($resetWork, $json['agents']['claude']['profiles']['work']['reset_at'] ?? null, '--resets --json: the work account reset');
    assert_true(is_string($json['agents']['claude']['label'] ?? null), '--resets --json: the agent has a label');
    assert_true(array_key_exists('reset_at', $json['agents']['codex']['profiles']['default'] ?? []) && $json['agents']['codex']['profiles']['default']['reset_at'] === null, '--resets --json: an unknown reset is null');
    [$code, $out] = $cli(['--resets']);
    assert_equal(0, $code, '--resets: exit 0');
    assert_contains('claude', strtolower($out), '--resets: names the agent');
    assert_contains('work', $out, '--resets: names the profile');
    assert_equal(0, $creates(), '--resets queued nothing');
    assert_equal([], $wake->calls(), '--resets never called at');

    echo "No job without --approve, even with a valid reset\n";
    [$code, $out, $err] = $cli(['--schedule-reset', 'claude:personal', '--wake', 'conv-p1']);
    assert_equal([1, ''], [$code, $out], 'refused with nothing on stdout');
    assert_contains('--approve', $err, 'the refusal names --approve');
    assert_equal(0, $creates(), 'no at job was created');
    assert_equal('', trim((string)file_get_contents($wake->dir . '/queue.txt')), 'the queue is empty');

    echo "An approved wake\n";
    [$code, $out, $err] = $cli(['--schedule-reset', 'claude:personal', '--approve', '--wake', 'conv-p1', '--json']);
    $reply = json_decode($out, true);
    assert_equal(0, $code, 'exit 0 (stderr: ' . trim($err) . ')');
    assert_equal(true, $reply['ok'] ?? null, '--json prints the host reply: ok');
    assert_equal($resetPersonal + 60, $reply['at'] ?? null, 'at is one minute after the stored reset');
    assert_equal(false, $reply['existing'] ?? null, 'a new job is not existing');
    assert_true(is_int($reply['job'] ?? null), 'the job id is an integer');
    $job = (int)($reply['job'] ?? 0);
    assert_equal(1, $creates(), 'exactly one at job was created');
    $script = (string)file_get_contents($wake->dir . '/at-script');
    assert_true(str_starts_with($script, "env -i 'HOME="), 'the job runs under env -i');
    assert_contains("'--resume' 'conv-p1'", $script, 'it resumes the conversation');
    assert_true(!str_contains($script, 'SESSIONEER_SESSION_NAME') && !str_contains($script, 'cc-leak-check'), 'the caller\'s session variable does not reach the job');

    [$code, $out] = $cli(['--schedule-reset', 'claude:personal', '--approve', '--wake', 'conv-p1']);
    assert_equal(0, $code, 'plain output: exit 0');
    assert_contains((string)$job, $out, 'plain output: prints the job id');
    assert_equal(1, $creates(), 'a repeat queues nothing');
    [, $out] = $cli(['--schedule-reset', 'claude:personal', '--approve', '--wake', 'conv-p1', '--json']);
    $again = json_decode($out, true);
    assert_equal([true, true, $job], [$again['ok'] ?? null, $again['existing'] ?? null, $again['job'] ?? null], 'a repeat answers existing:true with the same job');

    [$code, $out] = $cli(['--wake-list', '--json']);
    assert_contains('"job":' . $job, str_replace(' ', '', $out), 'the job appears in --wake-list');
    [$code, , $err] = $cli(['--wake-cancel', (string)$job]);
    assert_equal(0, $code, '--wake-cancel removes it (stderr: ' . trim($err) . ')');
    assert_equal("{$job}\n", $wake->removed(), 'exactly that job was removed');

    echo "A fresh start from a handoff or plan\n";
    $wake->reset();
    [$code, $out, $err] = $cli(['--schedule-reset', 'claude:work', '--approve', '--handoff', '123', '--json']);
    $reply = json_decode($out, true);
    assert_equal([0, true], [$code, $reply['ok'] ?? null], 'handoff: scheduled (stderr: ' . trim($err) . ')');
    assert_equal($resetWork + 60, $reply['at'] ?? null, 'handoff: uses the work profile\'s own reset');
    $script = (string)file_get_contents($wake->dir . '/at-script');
    assert_true(!str_contains($script, '--resume'), 'handoff: resumes nothing');
    assert_contains("'--profile' 'work'", $script, 'handoff: starts under the named profile');
    assert_contains('123', $script, 'handoff: the continuation prompt names the id');
    [, $out] = $cli(['--schedule-reset', 'claude:work', '--approve', '--plan', '1765', '--json']);
    assert_equal([true, false], [json_decode($out, true)['ok'] ?? null, json_decode($out, true)['existing'] ?? null], 'plan: a different context is a new job');
    assert_equal(2, $creates(), 'two jobs in total');

    echo "A reset that drifts between the CLI's read and the schedule\n";
    $wake->reset();
    file_put_contents($bumpFlag, (string)($resetPersonal + 3));
    [$code, $out, $err] = $fake->run(['--schedule-reset', 'claude:personal', '--approve', '--handoff', '321', '--json'], array_merge($noSession, ['SESSIONEER_AGENT_SOCKET' => $driftSocket]));
    $reply = json_decode($out, true);
    assert_equal([0, true], [$code, $reply['ok'] ?? null], 'stored reset moved +3 s after the read: still scheduled, not refused as stale (stderr: ' . trim($err) . ')');
    assert_equal($resetPersonal + 120, $reply['at'] ?? null, 'the job time follows the stored (bumped) reset: next whole minute at or after stored + 60');
    assert_true(!file_exists($bumpFlag), 'the drift really happened between the read and the schedule (proxy applied it)');
    assert_equal(1, $creates(), 'one job');
    GlobalStateStore::write(Config::quota_live_state_key(), ['captured_at' => time(), 'session' => ['pct' => 95, 'resets_at' => $resetPersonal]]);

    echo "A wake must match the agent and profile of its session\n";
    $wake->reset();
    [$code, $out, $err] = $cli(['--schedule-reset', 'claude:personal', '--approve', '--wake', 'conv-w1']);
    assert_equal([1, ''], [$code, $out], 'a work session with claude:personal: exit 1, nothing on stdout');
    assert_true(str_contains(strtolower($err), 'work') && str_contains(strtolower($err), 'personal'), 'the host\'s message names both profiles');
    assert_equal(0, $creates(), 'no job queued');
    [$code, $out, $err] = $cli(['--schedule-reset', 'claude:work', '--approve', '--wake', 'conv-w1', '--json']);
    assert_equal([0, true], [$code, json_decode($out, true)['ok'] ?? null], 'the matching claude:work wake schedules (stderr: ' . trim($err) . ')');
    assert_equal(1, $creates(), 'one job');

    echo "Refusals from the host\n";
    $wake->reset();
    foreach ([
        'unknown agent' => [['--schedule-reset', 'nosuchagent:x', '--approve', '--handoff', '5'], 'agent'],
        'unknown profile' => [['--schedule-reset', 'claude:nosuchprofile', '--approve', '--handoff', '5'], 'profile'],
        'wake target not found' => [['--schedule-reset', 'claude:personal', '--approve', '--wake', 'nope'], 'not found'],
    ] as $label => [$args, $needle]) {
        [$code, $out, $err] = $cli($args);
        assert_equal([1, ''], [$code, $out], "{$label}: exit 1, nothing on stdout");
        assert_contains($needle, strtolower($err), "{$label}: the host's message is on stderr");
    }
    touch($wake->dir . '/fail-l');
    [$code, $out, $err] = $cli(['--schedule-reset', 'claude:personal', '--approve', '--handoff', '5']);
    assert_equal([1, ''], [$code, $out], 'atq failing: exit 1, nothing on stdout');
    assert_true(preg_match('/atd|atq/', $err) === 1, 'atq failing: stderr names atd or atq');
    @unlink($wake->dir . '/fail-l');
    assert_equal(0, $creates(), 'no refusal created a job');
    $unreachable = $fake->run(['--schedule-reset', 'claude:personal', '--approve', '--handoff', '5'], array_merge($noSession, ['SESSIONEER_AGENT_SOCKET' => sys_get_temp_dir() . '/sessioneer-test-cli-flow-schedule-missing.sock']));
    assert_equal(1, $unreachable[0], 'an unreachable host agent: exit 1');
    assert_contains('Cannot reach host agent', $unreachable[2], 'with the handled message');
} finally {
    stop_harness($drift, $driftSocket);
    stop_harness($agent, $realSocket);
    $fake->stop();
    $wake->cleanup();
}

echo "OK\n";
test_exit();
