<?php

declare(strict_types=1);

/**
 * Tests HostAgent\Runtimes\ClaudeHeadlessManager end to end: real manager
 * processes (host-agent/claude_headless_manager.php) driving the scripted
 * stand-in tests/fixtures/fake_claude_stream over its real stream-json
 * protocol, talked to through the real ClaudeHeadlessManagerClient socket
 * client. Never spawns the real `claude` binary, never billable.
 *
 * Covers the accepted design (Dibs decision #282, protocol research #280):
 * lazy spawn/resume, status + blocked-prompt feed, answering prompts,
 * interrupt and queued messages, mode/model control, session-id rotation,
 * graceful and forced stop, idle reaping, max children, the no-API-key and
 * no-overage guardrails, crash handling, stale-state cleanup on restart,
 * and the adapter's headless argv - happy AND sad paths, each asserted as a
 * specific handled outcome (never just "didn't crash").
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/claude_headless.php';

use HostAgent\Agents\ClaudeCodeAdapter;
use HostAgent\Runtimes\ClaudeHeadlessManagerClient;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$root = sys_get_temp_dir() . '/sessioneer-test-claude-headless-' . getmypid();
@mkdir($root . '/home/.claude/projects', 0700, true);

if ((string)getenv('SIDECAR_DIR') === '') {
    putenv('SIDECAR_DIR=' . $root . '/sidecars');
    @mkdir($root . '/sidecars', 0700, true);
}

/** @var array<int, array{proc: resource, sock: string, log: string, fake: string}> $managers */
$managers = [];

/** @var array<int, string> sessions this run wrote into the (possibly shared, possibly persistent) sidecar/status DB */
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

// Unique per run: the sidecar/status DB can be a fixture path shared between
// runs, and the manager deliberately re-applies a session's last known mode,
// so reused names would leak state from one run into the next.
$runTag = (string)getmypid();
$sn = static fn (int $i): string => sprintf('claude-headless-t%s-%06d', $runTag, $i);
$tmuxName = 'cc-tmux-t' . $runTag;
$codexName = 'codex-headless-t' . $runTag;
$missingName = 'claude-headless-t' . $runTag . '-missing';
$restartMessage = 'Claude headless manager restarted while this session was busy; retry the interrupted turn.';

// ============================================================ adapter argv

echo "Adapter: headless argv\n";
$adapter = new ClaudeCodeAdapter();
$fresh = $adapter->build_headless_argv(['starting_mode' => 'plan', 'model' => 'haiku', 'enable_task_tools' => true]);
$argv = $fresh['argv'];
assert_true(in_array('-p', $argv, true), 'headless argv is a print-mode (-p) process');
assert_equal(['--input-format', 'stream-json'], array_slice($argv, array_search('--input-format', $argv, true), 2), 'input is stream-json');
assert_equal(['--output-format', 'stream-json'], array_slice($argv, array_search('--output-format', $argv, true), 2), 'output is stream-json');
assert_equal(['--permission-prompt-tool', 'stdio'], array_slice($argv, array_search('--permission-prompt-tool', $argv, true), 2), 'prompts are routed to the client over stdio');
assert_true(!in_array('--bare', $argv, true), 'never --bare (it ignores the subscription login)');
assert_true(in_array('--session-id', $argv, true) && $fresh['assigned_id'] !== null && !in_array('--resume', $argv, true), 'a fresh session pre-assigns an id');
assert_true(in_array('--permission-mode', $argv, true) && in_array('plan', $argv, true), 'starting mode is passed in Claude vocabulary');
assert_true(in_array('--model', $argv, true) && in_array('haiku', $argv, true), 'model alias passes through');
assert_true(in_array('--allowedTools', $argv, true), 'task tools opt-in passes through');
assert_true(!isset($fresh['env']), 'no CLAUDE_CONFIG_DIR override without a profile');

$resume = $adapter->build_headless_argv(['resume' => 'abc-123']);
assert_equal(['--resume', 'abc-123'], array_slice($resume['argv'], array_search('--resume', $resume['argv'], true), 2), 'resume continues the given id');
assert_equal('abc-123', $resume['assigned_id'], 'resume reports the same id');
assert_true(!in_array('--session-id', $resume['argv'], true), 'resume does not also assign an id');

$pinned = $adapter->build_headless_argv(['session_id' => 'pinned-1']);
assert_equal('pinned-1', $pinned['assigned_id'], 'a caller can pin the id of a session with no transcript yet');
assert_equal(['--session-id', 'pinned-1'], array_slice($pinned['argv'], array_search('--session-id', $pinned['argv'], true), 2), 'pinned id is passed as --session-id');

$junk = $adapter->build_headless_argv(['model' => 'not-a-real-model', 'starting_mode' => 'nonsense']);
assert_true(!in_array('--model', $junk['argv'], true) && !in_array('--permission-mode', $junk['argv'], true), 'unknown model/mode values are dropped, not passed to the CLI');

// ================================================== stale state on startup

echo "Startup: stale state cleanup\n";
make_session($root, $sn(90));
SessionStatusStore::update_status($sn(90), ['status' => 'blocked', 'blocked' => ['source' => 'claude_headless', 'request_id' => 'old']]);
make_session($root, $sn(91));
SessionStatusStore::update_status($sn(91), ['status' => 'working']);
make_session($root, $tmuxName, ['runtime' => null]);
SessionStatusStore::update_status($tmuxName, ['status' => 'blocked', 'blocked' => ['tool_name' => 'Bash']]);

$m1 = start_manager($root, 'm1');
assert_true(file_exists($m1['sock']), 'manager binds its socket');
assert_true(status_is($sn(90), 'idle') && SessionStatusStore::read_status($sn(90))['blocked'] === null, 'a headless session left blocked by a dead manager is reset to idle');
assert_equal($restartMessage, SessionStatusStore::read_status($sn(90))['last_turn_error'], 'the reset explains why, in the documented wording');
assert_true(status_is($sn(91), 'idle'), 'a headless session left working is reset to idle');
assert_true(SessionStatusStore::read_status($tmuxName)['status'] === 'blocked', 'a same-agent TMUX session is left alone');

// A brand-new install starts the service before anything has ever touched the
// session database. Its stale-state reset must not depend on the schema having
// been migrated already (regression: "no such column: runtime" crash loop).
$freshDir = $root . '/fresh-db';
@mkdir($freshDir, 0700, true);
$fresh = start_manager($root, 'fresh', ['SIDECAR_DIR' => $freshDir]);
$freshHealth = wait_until(static function () use ($fresh): array|false {
    $reply = @stream_socket_client('unix://' . $fresh['sock'], $errno, $err, 0.5) ? call($fresh, 'sessioneer/health') : [];

    return ($reply['ok'] ?? false) === true ? $reply : false;
}, 5.0);
assert_true(is_array($freshHealth), 'a manager starts and answers on a completely fresh session database');
proc_terminate($fresh['proc'], SIGTERM);

$second = start_manager($root, 'dup', ['CLAUDE_HEADLESS_SOCKET' => $m1['sock']]);
$exitCode = null;
wait_until(static function () use ($second, &$exitCode): bool {
    $s = proc_get_status($second['proc']);
    if (!$s['running']) {
        $exitCode = $s['exitcode'];
    }

    return !$s['running'];
}, 5.0);
assert_equal(1, $exitCode, 'a second manager on a live socket refuses to start');
assert_contains('already listening', (string)file_get_contents($second['log']), 'and says why');
assert_true(call($m1, 'sessioneer/health')['ok'] === true, 'the first manager is unaffected');

// ====================================================== request validation

echo "Validation: sad paths\n";
$health = call($m1, 'sessioneer/health');
assert_equal(0, $health['children'], 'health starts with no children');
assert_equal(6, $health['max_children'], 'health reports the configured cap');
assert_equal(null, $health['spawn_blocked'], 'spawning is not blocked at start');

assert_equal('Unknown session', call($m1, 'sessioneer/status', ['session' => $missingName])['message'] ?? '', 'unknown session is rejected');
assert_equal('Unknown session', call($m1, 'sessioneer/sendInput', ['session' => $missingName, 'content' => 'x'])['message'] ?? '', 'sendInput to an unknown session is rejected');
assert_equal('Unknown session', call($m1, 'sessioneer/stop', ['session' => $missingName])['message'] ?? '', 'stop of an unknown session is rejected');
assert_equal('Not a headless Claude session', call($m1, 'sessioneer/sendInput', ['session' => $tmuxName, 'content' => 'x'])['message'] ?? '', 'a tmux Claude session is refused');
make_session($root, $codexName, ['agent' => 'codex']);
assert_equal('Not a headless Claude session', call($m1, 'sessioneer/status', ['session' => $codexName])['message'] ?? '', 'another agent\'s headless session is refused');
assert_equal('Invalid session name', call($m1, 'sessioneer/status', ['session' => '../etc/passwd'])['message'] ?? '', 'path-like session names are rejected');
assert_contains('Unknown method', (string)(call($m1, 'sessioneer/nonsense')['message'] ?? ''), 'unknown method is a handled error');
assert_equal('Invalid request', raw_request($m1, 'this is not json')['message'] ?? '', 'malformed JSON is a handled error');
assert_equal('Invalid request', raw_request($m1, '{"params":{}}')['message'] ?? '', 'a request without a method is a handled error');
make_session($root, $sn(1));
assert_contains('empty message', (string)(call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => '   '])['message'] ?? ''), 'a blank message is rejected');
make_session($root, $sn(2), ['workdir' => $root . '/does-not-exist']);
assert_contains('working directory', (string)(call($m1, 'sessioneer/sendInput', ['session' => $sn(2), 'content' => 'hi'])['message'] ?? ''), 'a missing working directory is a handled error');
assert_equal(0, call($m1, 'sessioneer/health')['children'], 'no process was started by any rejected request');

// ================================================== lazy spawn + status feed

echo "Lifecycle: lazy spawn, status, credentials\n";
assert_equal('dormant', process_of($m1, $sn(1)), 'a tracked session with no process is dormant');
$sent = call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'hello there']);
assert_true(($sent['ok'] ?? false) === true, 'first message spawns the process and is delivered');
assert_true((bool)wait_until(static fn (): bool => status_is($sn(1), 'idle') && has_result($m1, 'echo: hello there')), 'the turn completes and status returns to idle');
$status = SessionStatusStore::read_status($sn(1));
assert_equal('manual', $status['mode'], 'Claude\'s default permission mode is recorded in Sessioneer vocabulary');
assert_equal('claude-fake-1', $status['model'], 'the model from init is recorded');
assert_equal(null, $status['last_turn_error'], 'a successful turn leaves no error');
assert_true(is_array($status['token_usage']), 'result usage is recorded');
assert_equal('running', process_of($m1, $sn(1)), 'the process is now running');

$sidecar = SidecarStore::read_sidecar($sn(1));
assert_true(is_string($sidecar['agent_session_id']) && $sidecar['agent_session_id'] !== '', 'the assigned Claude session id is written to the sidecar');
$firstId = (string)$sidecar['agent_session_id'];

$launch = fake_log($m1)[0];
assert_true(in_array('-p', $launch['argv'], true) && !in_array('--bare', $launch['argv'], true), 'launched as -p, never --bare');
assert_equal(false, $launch['env']['ANTHROPIC_API_KEY'], 'ANTHROPIC_API_KEY is stripped from the child environment');
assert_equal(false, $launch['env']['ANTHROPIC_AUTH_TOKEN'], 'ANTHROPIC_AUTH_TOKEN is stripped from the child environment');
assert_equal(false, $launch['env']['SESSIONEER_SESSION_NAME'], 'SESSIONEER_SESSION_NAME is stripped so the hooks stay silent (manager is the sole writer)');
assert_equal(SidecarStore::read_sidecar($sn(1))['workdir'], $launch['cwd'], 'the child runs in the session\'s working directory');

// ================================================== prompts (permission)

echo "Prompts: permission request\n";
assert_equal(null, call($m1, 'sessioneer/pendingPrompt', ['session' => $sn(1)])['prompt'], 'no prompt is pending when idle');
assert_contains('no prompt is currently pending', (string)(call($m1, 'sessioneer/answerPrompt', ['session' => $sn(1), 'request_id' => 'x', 'response' => ['behavior' => 'allow']])['message'] ?? ''), 'answering with nothing pending is rejected');
call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'PERMISSION please']);
assert_true((bool)wait_until(static fn (): bool => status_is($sn(1), 'blocked')), 'a permission request marks the session blocked');
$pending = call($m1, 'sessioneer/pendingPrompt', ['session' => $sn(1)])['prompt'];
assert_equal('Write', $pending['tool_name'], 'the pending prompt carries the tool name');
assert_equal('/tmp/fake.txt', $pending['tool_input']['file_path'], 'and the exact untruncated tool input');
assert_equal('acceptEdits', $pending['permission_suggestions'][0]['mode'], 'and the suggested "allow and remember" permission update');
assert_equal(false, $pending['requires_user_interaction'], 'a plain tool approval does not require human interaction');
assert_equal($pending['request_id'], SessionStatusStore::read_status($sn(1))['blocked']['request_id'], 'the dashboard-visible blocked state names the same request');
assert_equal('blocked', call($m1, 'sessioneer/status', ['session' => $sn(1)])['state'], 'status reports the blocked state');

assert_contains('no longer the pending one', (string)(call($m1, 'sessioneer/answerPrompt', ['session' => $sn(1), 'request_id' => 'stale-id', 'response' => ['behavior' => 'allow']])['message'] ?? ''), 'a stale request id is rejected');
assert_contains('behavior', (string)(call($m1, 'sessioneer/answerPrompt', ['session' => $sn(1), 'request_id' => $pending['request_id'], 'response' => ['behavior' => 'maybe']])['message'] ?? ''), 'an invalid behavior is rejected');
assert_contains('behavior', (string)(call($m1, 'sessioneer/answerPrompt', ['session' => $sn(1), 'request_id' => $pending['request_id'], 'response' => ['behavior' => 'deny']])['message'] ?? ''), 'a deny without a message is rejected');
assert_equal('blocked', call($m1, 'sessioneer/status', ['session' => $sn(1)])['state'], 'rejected answers leave the prompt pending');

$answered = call($m1, 'sessioneer/answerPrompt', ['session' => $sn(1), 'request_id' => $pending['request_id'], 'response' => ['behavior' => 'allow', 'updatedInput' => $pending['tool_input']]]);
assert_true(($answered['ok'] ?? false) === true, 'a valid answer is accepted');
assert_true((bool)wait_until(static fn (): bool => status_is($sn(1), 'idle') && has_result($m1, 'tool Write answered: allow')), 'Claude received the allow and the turn finished');
assert_equal(null, SessionStatusStore::read_status($sn(1))['blocked'], 'the blocked state is cleared');

call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'PERMISSION again']);
wait_until(static fn (): bool => status_is($sn(1), 'blocked'));
$again = call($m1, 'sessioneer/pendingPrompt', ['session' => $sn(1)])['prompt'];
call($m1, 'sessioneer/answerPrompt', ['session' => $sn(1), 'request_id' => $again['request_id'], 'response' => ['behavior' => 'deny', 'message' => 'not now']]);
assert_true((bool)wait_until(static fn (): bool => has_result($m1, 'tool Write answered: deny')), 'a deny reaches Claude');
wait_until(static fn (): bool => status_is($sn(1), 'idle'));

echo "Prompts: question + unsupported control request\n";
call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'QUESTION now']);
wait_until(static fn (): bool => status_is($sn(1), 'blocked'));
$question = call($m1, 'sessioneer/pendingPrompt', ['session' => $sn(1)])['prompt'];
assert_equal('AskUserQuestion', $question['tool_name'], 'a question prompt is surfaced');
assert_equal(true, $question['requires_user_interaction'], 'and flagged as needing a human');
assert_equal('Which color?', $question['tool_input']['questions'][0]['question'], 'with its full questions array');
$viaError = call($m1, 'sessioneer/answerPrompt', ['session' => $sn(1), 'request_id' => $question['request_id'], 'error' => 'cannot answer here']);
assert_true(($viaError['ok'] ?? false) === true, 'a prompt can be refused with an error reply');
assert_true((bool)wait_until(static fn (): bool => has_result($m1, 'tool AskUserQuestion answered: error')), 'Claude receives the error reply');
wait_until(static fn (): bool => status_is($sn(1), 'idle'));

call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'UNSUPPORTED request']);
assert_true((bool)wait_until(static fn (): bool => has_result($m1, 'unsupported request answered: error')), 'a control request the manager does not implement is answered with an error, so the turn cannot stall');
assert_true((bool)wait_until(static fn (): bool => status_is($sn(1), 'idle')), 'and the session returns to idle');

// ======================================== interrupt, queueing, mode, model

echo "Turns: interrupt and queued messages\n";
call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'SLOW work']);
assert_true((bool)wait_until(static fn (): bool => status_is($sn(1), 'working')), 'a slow turn shows as working');
$queued = call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'follow-up while busy']);
assert_true(($queued['ok'] ?? false) === true && ($queued['queued'] ?? false) === true, 'a message sent mid-turn is accepted and reported as queued');
assert_equal(2, call($m1, 'sessioneer/status', ['session' => $sn(1)])['open_turns'], 'two turns are open');
$interrupted = call($m1, 'sessioneer/interrupt', ['session' => $sn(1)]);
assert_true(($interrupted['ok'] ?? false) === true, 'interrupt is acknowledged by Claude');
assert_true((bool)wait_until(static fn (): bool => has_result($m1, 'echo: follow-up while busy') && status_is($sn(1), 'idle')), 'the queued message still runs after the interrupt, then the session is idle');
assert_true((bool)wait_until(static fn (): bool => SessionStatusStore::read_status($sn(1))['last_turn_error'] === null), 'and the follow-up\'s success clears the interrupted turn\'s error');

echo "Control: mode, model, rotation\n";
$mode = call($m1, 'sessioneer/setMode', ['session' => $sn(1), 'mode' => 'accept edits']);
assert_true(($mode['ok'] ?? false) === true, 'a permission mode change is acknowledged');
assert_true((bool)wait_until(static fn (): bool => SessionStatusStore::read_status($sn(1))['mode'] === 'accept edits'), 'and the new mode is recorded (via the status event)');
assert_contains('unknown permission mode', (string)(call($m1, 'sessioneer/setMode', ['session' => $sn(1), 'mode' => 'yolo'])['message'] ?? ''), 'an unknown mode is rejected');
assert_true((call($m1, 'sessioneer/setModel', ['session' => $sn(1), 'model' => 'sonnet'])['ok'] ?? false) === true, 'a model change is acknowledged');
assert_contains('invalid model', (string)(call($m1, 'sessioneer/setModel', ['session' => $sn(1), 'model' => 'x; rm -rf /'])['message'] ?? ''), 'a model with shell metacharacters is rejected');
call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'after mode change']);
wait_until(static fn (): bool => has_result($m1, 'echo: after mode change'));
assert_true(has_result($m1, 'mode=acceptEdits'), 'Claude really runs in the new mode');
assert_true((bool)wait_until(static fn (): bool => SessionStatusStore::read_status($sn(1))['model'] === 'sonnet'), 'the new model shows up on the next turn');

call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'CLEAR everything']);
assert_true((bool)wait_until(static fn (): bool => SidecarStore::read_sidecar($sn(1))['agent_session_id'] !== $firstId), 'a /clear-style rotation re-points the sidecar at the new Claude session id');
assert_true(str_starts_with((string)SidecarStore::read_sidecar($sn(1))['agent_session_id'], 'fake-'), 'to the id Claude reported');
wait_until(static fn (): bool => status_is($sn(1), 'idle'));
$rotatedId = (string)SidecarStore::read_sidecar($sn(1))['agent_session_id'];

// ============================================ stop, dormancy, lazy resume

echo "Lifecycle: stop, dormant, resume\n";
$pid = (int)call($m1, 'sessioneer/status', ['session' => $sn(1)])['pid'];
assert_true(pid_alive($pid), 'the child is alive before stop');
$stopped = call($m1, 'sessioneer/stop', ['session' => $sn(1)]);
assert_true(($stopped['ok'] ?? false) === true, 'stop is acknowledged');
assert_true(!pid_alive($pid), 'and the process has already exited when the reply arrives (race-free take-over)');
assert_equal('dormant', process_of($m1, $sn(1)), 'the session is dormant, not gone');
assert_equal('Already stopped', call($m1, 'sessioneer/stop', ['session' => $sn(1)])['message'] ?? '', 'stopping a dormant session is a no-op');
assert_contains('no running Claude process', (string)(call($m1, 'sessioneer/interrupt', ['session' => $sn(1)])['message'] ?? ''), 'interrupting a dormant session is a handled error');
assert_contains('no running Claude process', (string)(call($m1, 'sessioneer/setMode', ['session' => $sn(1), 'mode' => 'plan'])['message'] ?? ''), 'so is changing its mode');

$transcriptDir = $root . '/home/.claude/projects/' . preg_replace('/[^A-Za-z0-9]/', '-', (string)SidecarStore::read_sidecar($sn(1))['workdir']);
@mkdir($transcriptDir, 0700, true);
file_put_contents("{$transcriptDir}/{$rotatedId}.jsonl", "{}\n");
call($m1, 'sessioneer/sendInput', ['session' => $sn(1), 'content' => 'welcome back']);
assert_true((bool)wait_until(static fn (): bool => has_result($m1, "echo: welcome back [spawn=resume id={$rotatedId} mode=acceptEdits]")), 'a message to a dormant session resumes the SAME conversation and re-applies the last permission mode');

make_session($root, $sn(3), ['agent_session_id' => 'pre-assigned-id-3']);
call($m1, 'sessioneer/sendInput', ['session' => $sn(3), 'content' => 'first words']);
assert_true((bool)wait_until(static fn (): bool => has_result($m1, 'echo: first words [spawn=fresh id=pre-assigned-id-3')), 'a session with an id but no transcript yet starts fresh under that same id (resume would fail)');

// ========================================================= crash handling

echo "Failure: crash mid-turn\n";
make_session($root, $sn(4));
call($m1, 'sessioneer/sendInput', ['session' => $sn(4), 'content' => 'hello first']);
wait_until(static fn (): bool => status_is($sn(4), 'idle'));
call($m1, 'sessioneer/sendInput', ['session' => $sn(4), 'content' => 'CRASH now']);
assert_true((bool)wait_until(static fn (): bool => process_of($m1, $sn(4)) === 'dormant'), 'a crashed child is forgotten');
$crashed = SessionStatusStore::read_status($sn(4));
assert_equal('idle', $crashed['status'], 'a crashed session is idle, not stuck working');
assert_contains('exited unexpectedly', (string)$crashed['last_turn_error'], 'the error says the process died');
assert_contains('code 3', (string)$crashed['last_turn_error'], 'with its exit code');
assert_contains('simulated crash', (string)$crashed['last_turn_error'], 'and the tail of its stderr');
call($m1, 'sessioneer/sendInput', ['session' => $sn(4), 'content' => 'recovered']);
assert_true((bool)wait_until(static fn (): bool => has_result($m1, 'echo: recovered') && status_is($sn(4), 'idle')), 'the next message respawns it and the session recovers');
assert_equal(null, SessionStatusStore::read_status($sn(4))['last_turn_error'], 'clearing the crash error');

echo "Failure: requested mode not applied\n";
make_session($root, $sn(5));
call($m1, 'sessioneer/spawn', ['session' => $sn(5), 'starting_mode' => 'auto']);
call($m1, 'sessioneer/sendInput', ['session' => $sn(5), 'content' => 'hi auto']);
assert_true((bool)wait_until(static fn (): bool => has_result($m1, 'echo: hi auto') && status_is($sn(5), 'idle')), 'the turn runs');
assert_contains("Requested permission mode 'auto' was not applied", (string)(SessionStatusStore::read_status($sn(5))['last_turn_error'] ?? ''), 'a silently ignored --permission-mode is surfaced instead of trusted, and survives the successful turn');
call($m1, 'sessioneer/setMode', ['session' => $sn(5), 'mode' => 'plan']);
wait_until(static fn (): bool => SessionStatusStore::read_status($sn(5))['mode'] === 'plan');
call($m1, 'sessioneer/sendInput', ['session' => $sn(5), 'content' => 'hi again']);
wait_until(static fn (): bool => has_result($m1, 'echo: hi again') && status_is($sn(5), 'idle'));
assert_equal(null, SessionStatusStore::read_status($sn(5))['last_turn_error'], 'once the mode is changed on purpose the warning is gone');

echo "Failure: stdout noise, auth failure, death with a prompt open\n";
make_session($root, $sn(6));
call($m1, 'sessioneer/sendInput', ['session' => $sn(6), 'content' => 'GARBAGE please']);
assert_true((bool)wait_until(static fn (): bool => has_result($m1, 'garbage survived') && status_is($sn(6), 'idle')), 'a turn with non-JSON, unknown-type and split-across-writes stdout lines still completes');
$managerLog = (string)file_get_contents($m1['log']);
assert_contains("{$sn(6)}: ignored 1 non-JSON stdout line(s)", $managerLog, 'the one non-JSON line is logged and skipped');
assert_true(!str_contains($managerLog, 'ignored 2 non-JSON'), 'a JSON line split across two writes is reassembled, not counted as garbage');
assert_true(!str_contains($managerLog, 'unexpected error'), 'and the unknown event type is ignored without an exception');
assert_equal(null, SessionStatusStore::read_status($sn(6))['last_turn_error'], 'the session shows no error');
assert_equal('running', process_of($m1, $sn(6)), 'and the process was not killed over it');
call($m1, 'sessioneer/sendInput', ['session' => $sn(6), 'content' => 'still fine']);
assert_true((bool)wait_until(static fn (): bool => has_result($m1, 'echo: still fine')), 'the session keeps working afterwards');
call($m1, 'sessioneer/stop', ['session' => $sn(6)]);

make_session($root, $sn(7));
call($m1, 'sessioneer/sendInput', ['session' => $sn(7), 'content' => 'AUTHFAIL now']);
assert_true((bool)wait_until(static fn (): bool => status_is($sn(7), 'idle') && SessionStatusStore::read_status($sn(7))['last_turn_error'] !== null), 'an is_error result ends the turn as idle');
assert_contains('OAuth token has expired', (string)SessionStatusStore::read_status($sn(7))['last_turn_error'], 'and the login problem reaches the session as its error');
usleep(700000);
assert_equal(1, count(array_filter(fake_results($m1), static fn (string $r): bool => str_contains($r, 'OAuth token has expired'))), 'nothing retried the failed turn');
assert_equal('running', process_of($m1, $sn(7)), 'the process is left alone so the user can log in and retry');
call($m1, 'sessioneer/sendInput', ['session' => $sn(7), 'content' => 'after login']);
assert_true((bool)wait_until(static fn (): bool => has_result($m1, 'echo: after login') && SessionStatusStore::read_status($sn(7))['last_turn_error'] === null), 'the next successful turn clears the error');
call($m1, 'sessioneer/stop', ['session' => $sn(7)]);

make_session($root, $sn(8));
call($m1, 'sessioneer/sendInput', ['session' => $sn(8), 'content' => 'PERMISSION then die']);
assert_true((bool)wait_until(static fn (): bool => status_is($sn(8), 'blocked')), 'a prompt is open');
$deadPrompt = call($m1, 'sessioneer/pendingPrompt', ['session' => $sn(8)])['prompt'];
kill_hard((int)call($m1, 'sessioneer/status', ['session' => $sn(8)])['pid']);
assert_true((bool)wait_until(static fn (): bool => process_of($m1, $sn(8)) === 'dormant'), 'a child killed while a prompt is open is forgotten');
$died = SessionStatusStore::read_status($sn(8));
assert_equal('idle', $died['status'], 'the session is idle, not stuck blocked');
assert_equal(null, $died['blocked'], 'the dead prompt is cleared from the dashboard state');
assert_contains('exited unexpectedly', (string)$died['last_turn_error'], 'the session says the process died');
assert_equal(null, call($m1, 'sessioneer/pendingPrompt', ['session' => $sn(8)])['prompt'], 'no prompt is offered any more');
assert_contains('no prompt is currently pending', (string)(call($m1, 'sessioneer/answerPrompt', ['session' => $sn(8), 'request_id' => $deadPrompt['request_id'], 'response' => ['behavior' => 'allow']])['message'] ?? ''), 'answering the dead prompt is a handled rejection');
call($m1, 'sessioneer/sendInput', ['session' => $sn(8), 'content' => 'back again']);
assert_true((bool)wait_until(static fn (): bool => has_result($m1, 'echo: back again') && status_is($sn(8), 'idle')), 'the next message respawns the session');

// ============================================================ health/list

$health = call($m1, 'sessioneer/health');
assert_true($health['children'] >= 3, 'health counts live children');
assert_equal('0.0.0-fake', $health['claude_version'], 'health reports the CLI version seen in init');
assert_equal('none', $health['last_api_key_source'], 'health reports the credential source Claude reported');
assert_equal(0.25, $health['rate_limit']['windows']['five_hour']['utilization'], 'health carries the latest rate-limit window');
assert_true(is_int($health['rss_mb']), 'health reports resident memory');
$names = array_column(call($m1, 'sessioneer/list')['children'] ?? [], 'session');
assert_true(in_array($sn(1), $names, true) && in_array($sn(4), $names, true), 'list names the live sessions');

// ================================================== graceful shutdown (M1)

echo "Shutdown: SIGTERM stops children and removes the socket\n";
$childPids = array_map(static fn (array $c): int => (int)$c['pid'], call($m1, 'sessioneer/list')['children'] ?? []);
proc_terminate($m1['proc'], SIGTERM);
$exited = wait_until(static function () use ($m1): bool {
    return !proc_get_status($m1['proc'])['running'];
}, 12.0);
assert_true((bool)$exited, 'the manager exits on SIGTERM');
assert_true(!file_exists($m1['sock']), 'and removes its socket');
assert_true(array_reduce($childPids, static fn (bool $carry, int $pid): bool => $carry && !pid_alive($pid), true), 'and no child outlives it');
assert_true(status_is($sn(1), 'idle'), 'sessions are left idle');

echo "Failure: manager down\n";
$down = call($m1, 'sessioneer/health');
assert_true(($down['ok'] ?? true) === false, 'a client call to a dead manager fails');
assert_contains('Cannot reach Claude headless manager', (string)$down['message'], 'with a handled, named error');

// ============================================================ max children

echo "Limits: max children\n";
$m2 = start_manager($root, 'm2', ['CLAUDE_HEADLESS_MAX_CHILDREN' => '1']);
make_session($root, $sn(10));
make_session($root, $sn(11));
assert_true((call($m2, 'sessioneer/sendInput', ['session' => $sn(10), 'content' => 'one'])['ok'] ?? false) === true, 'the first session fits');
$over = call($m2, 'sessioneer/sendInput', ['session' => $sn(11), 'content' => 'two']);
assert_true(($over['ok'] ?? true) === false, 'a session over the cap is refused, never silently queued');
assert_contains('Too many live Claude sessions', (string)$over['message'], 'with a clear message');
assert_equal('dormant', process_of($m2, $sn(11)), 'and no process was started for it');
call($m2, 'sessioneer/stop', ['session' => $sn(10)]);
assert_true((call($m2, 'sessioneer/sendInput', ['session' => $sn(11), 'content' => 'two'])['ok'] ?? false) === true, 'stopping one frees a slot');

// ============================================================ idle reaping

echo "Limits: idle reaping\n";
$m3 = start_manager($root, 'm3', ['CLAUDE_HEADLESS_IDLE_SECONDS' => '2']);
make_session($root, $sn(20));
make_session($root, $sn(21));
call($m3, 'sessioneer/sendInput', ['session' => $sn(20), 'content' => 'idle soon']);
call($m3, 'sessioneer/sendInput', ['session' => $sn(21), 'content' => 'PERMISSION hold']);
wait_until(static fn (): bool => status_is($sn(21), 'blocked'));
assert_true((bool)wait_until(static fn (): bool => process_of($m3, $sn(20)) === 'dormant', 10.0), 'an idle session\'s process is stopped after the idle limit');
assert_true(status_is($sn(20), 'idle') && SessionStatusStore::read_status($sn(20))['last_turn_error'] === null, 'without an error - reaping is not a failure');
assert_equal('running', process_of($m3, $sn(21)), 'a session waiting on a prompt is NOT reaped');
assert_equal('blocked', call($m3, 'sessioneer/status', ['session' => $sn(21)])['state'], 'and still shows its prompt');

// ===================================================== guardrails (M4, M5)

echo "Guardrail: API-key credential source\n";
$m4 = start_manager($root, 'm4');
make_session($root, $sn(30));
make_session($root, $sn(31));
call($m4, 'sessioneer/sendInput', ['session' => $sn(30), 'content' => 'APIKEY please']);
assert_true((bool)wait_until(static fn (): bool => process_of($m4, $sn(30)) === 'dormant'), 'a child reporting an API-key source is killed');
$guarded = call($m4, 'sessioneer/health');
assert_contains('apiKeySource', (string)$guarded['spawn_blocked'], 'health says spawning is blocked and why');
assert_equal('ANTHROPIC_API_KEY', $guarded['last_api_key_source'], 'and records the offending source');
assert_contains('API key', (string)SessionStatusStore::read_status($sn(30))['last_turn_error'], 'the session shows the reason');
$refused = call($m4, 'sessioneer/sendInput', ['session' => $sn(31), 'content' => 'hello']);
assert_true(($refused['ok'] ?? true) === false, 'no further session can be started');
assert_contains('Spawning is disabled', (string)$refused['message'], 'with a clear message');

echo "Guardrail: overage billing\n";
$m5 = start_manager($root, 'm5');
make_session($root, $sn(40));
make_session($root, $sn(41));
call($m5, 'sessioneer/sendInput', ['session' => $sn(40), 'content' => 'OVERAGE hit']);
assert_true((bool)wait_until(static fn (): bool => call($m5, 'sessioneer/health')['spawn_blocked'] !== null), 'an overage-in-use rate-limit event blocks new spawns');
assert_contains('overage', (string)call($m5, 'sessioneer/health')['spawn_blocked'], 'and says why');
assert_contains('Spawning is disabled', (string)(call($m5, 'sessioneer/sendInput', ['session' => $sn(41), 'content' => 'hi'])['message'] ?? ''), 'a new session is refused');

// ============================================ stop escalation, real restart

echo "Stop: a child that ignores stdin EOF and SIGTERM is SIGKILLed\n";
$m6 = start_manager($root, 'm6', ['CLAUDE_HEADLESS_STOP_GRACE_SECONDS' => '1']);
make_session($root, $sn(50));
call($m6, 'sessioneer/sendInput', ['session' => $sn(50), 'content' => 'STUBBORN child']);
assert_true((bool)wait_until(static fn (): bool => status_is($sn(50), 'working')), 'the stubborn child is mid-turn');
$stubbornPid = (int)call($m6, 'sessioneer/status', ['session' => $sn(50)])['pid'];
$started = microtime(true);
$stoppedHard = call($m6, 'sessioneer/stop', ['session' => $sn(50)]);
$elapsed = microtime(true) - $started;
assert_true(($stoppedHard['ok'] ?? false) === true, 'stop still succeeds');
assert_true(!pid_alive($stubbornPid), 'the process is gone when the reply arrives');
assert_true($elapsed >= 2.5, 'it waited out the grace period and the SIGTERM window before escalating (took ' . round($elapsed, 1) . 's)');
assert_true(in_array(['signal' => 'SIGTERM'], fake_log($m6), true), 'SIGTERM was tried first (the child saw it and ignored it)');
assert_equal('dormant', process_of($m6, $sn(50)), 'the session is dormant');
assert_true(status_is($sn(50), 'idle') && SessionStatusStore::read_status($sn(50))['last_turn_error'] === null, 'a requested stop is not reported as a crash');

echo "Restart: manager killed with a prompt open\n";
$m7 = start_manager($root, 'm7');
make_session($root, $sn(60));
call($m7, 'sessioneer/sendInput', ['session' => $sn(60), 'content' => 'PERMISSION across restart']);
assert_true((bool)wait_until(static fn (): bool => status_is($sn(60), 'blocked')), 'a prompt is open');
$oldPrompt = call($m7, 'sessioneer/pendingPrompt', ['session' => $sn(60)])['prompt'];
$orphanPid = (int)call($m7, 'sessioneer/status', ['session' => $sn(60)])['pid'];
kill_hard((int)proc_get_status($m7['proc'])['pid']);
assert_true((bool)wait_until(static fn (): bool => !proc_get_status($m7['proc'])['running']), 'the manager dies without any cleanup');
assert_true((bool)wait_until(static fn (): bool => !pid_alive($orphanPid)), 'its child exits when the pipe closes');
assert_true(status_is($sn(60), 'blocked') && file_exists($m7['sock']), 'the dead manager left the prompt and its socket file behind');

$m7b = start_manager($root, 'm7b', ['CLAUDE_HEADLESS_SOCKET' => $m7['sock']]);
$m7b['sock'] = $m7['sock'];
assert_true((bool)wait_until(static fn (): bool => (call($m7b, 'sessioneer/health')['ok'] ?? false) === true), 'a new manager replaces the stale socket file and answers');
$reset = SessionStatusStore::read_status($sn(60));
assert_equal('idle', $reset['status'], 'the abandoned prompt no longer shows the session as blocked');
assert_equal(null, $reset['blocked'], 'the blocked state is cleared');
assert_equal($restartMessage, $reset['last_turn_error'], 'the session says the prompt was lost and to retry the turn');
assert_equal(null, call($m7b, 'sessioneer/pendingPrompt', ['session' => $sn(60)])['prompt'], 'no prompt is offered');
assert_contains('no prompt is currently pending', (string)(call($m7b, 'sessioneer/answerPrompt', ['session' => $sn(60), 'request_id' => $oldPrompt['request_id'], 'response' => ['behavior' => 'allow']])['message'] ?? ''), 'answering the lost prompt is a handled rejection');
call($m7b, 'sessioneer/sendInput', ['session' => $sn(60), 'content' => 'retry the turn']);
assert_true((bool)wait_until(static fn (): bool => has_result($m7b, 'echo: retry the turn') && status_is($sn(60), 'idle')), 'the retried turn runs and the session recovers');

// ============================================================== done

foreach ($managers as $m) {
    if (is_resource($m['proc']) && proc_get_status($m['proc'])['running']) {
        proc_terminate($m['proc'], SIGTERM);
    }
}

if ($GLOBALS['__sessioneer_test_failures'] > 0) {
    foreach ($managers as $m) {
        $tail = @file_get_contents($m['log']);

        if ($tail !== false && trim($tail) !== '') {
            fwrite(STDOUT, "\n--- manager log " . basename($m['log']) . " ---\n" . substr($tail, -1500) . "\n");
        }
    }
}

test_exit();
