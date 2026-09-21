<?php

declare(strict_types=1);

/**
 * End-to-end test of headless Claude sessions through the host agent's own
 * entry point, dispatch_action(): the exact requests the web app sends
 * (create with runtime=headless, list, session_detail, session_history,
 * send_message, the answer_* actions, send_escape, set_mode, set_model,
 * list_archived, kill), served by a REAL ClaudeHeadlessManager process
 * driving the scripted stand-in tests/fixtures/fake_claude_stream. Never
 * spawns the real `claude`, never billable.
 *
 * Happy and sad paths: rejected requests are asserted as their specific
 * handled messages, and a dead manager is asserted to be a named, handled
 * error rather than a crash.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/claude_headless.php';

$root = sys_get_temp_dir() . '/sessioneer-test-claude-headless-dispatch-' . getmypid();
@mkdir($root . '/home/.claude/projects', 0700, true);
@mkdir($root . '/work', 0700, true);

// Isolate everything the host agent reaches for (see tests/.env.testing); a
// direct `php tests/...` run gets safe defaults, a tests/run.sh run keeps its own.
foreach ([
    'TMUX_SOCKET' => $root . '/tmux/socket',
    'CLAUDE_BIN' => __DIR__ . '/fixtures/fake_claude',
    'CACHE_DIR' => $root . '/cache',
    'SIDECAR_DIR' => $root . '/sidecars',
    'SESSION_LIST_CACHE_TTL_SECONDS' => '0',
    'OPENCODE_SERVE_URL' => 'http://127.0.0.1:1',
    'OPENCODE_DB_PATH' => $root . '/no-opencode.db',
    'CODEX_BRIDGE_SOCKET' => $root . '/no-codex-bridge.sock',
] as $key => $value) {
    if ((string)getenv($key) === '') {
        putenv("{$key}={$value}");
    }
}

@mkdir((string)getenv('SIDECAR_DIR'), 0700, true);
@mkdir((string)getenv('CACHE_DIR'), 0700, true);
@mkdir(dirname((string)getenv('TMUX_SOCKET')), 0700, true);
putenv('HOME_ROOT=' . $root . '/home');
putenv('CLAUDE_HEADLESS_SOCKET=' . $root . '/mgr.sock');

require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/** @var array<int, array{proc: resource, sock: string, log: string, fake: string}> $managers */
$managers = [];

/** @var array<int, string> $made */
$made = [];

register_shutdown_function(static function () use (&$managers, &$made, $root): void {
    foreach ($managers as $m) {
        if (is_resource($m['proc'])) {
            @proc_terminate($m['proc'], SIGTERM);
            usleep(300000);
            @proc_terminate($m['proc'], SIGKILL);
            @proc_close($m['proc']);
        }
    }

    foreach ($made as $name) {
        SidecarStore::delete_sidecar($name);
        SessionStatusStore::delete_status($name);
    }

    // Only ever stop a tmux server this test started itself (its own socket);
    // a run.sh run cleans up its own isolated server.
    $socket = (string)getenv('TMUX_SOCKET');

    if (str_starts_with($socket, $root . '/')) {
        exec('tmux -S ' . escapeshellarg($socket) . ' kill-server 2>/dev/null');
    }

    exec('rm -rf ' . escapeshellarg($root));
});

/** @param array<string, mixed> $request @return array<string, mixed> */
function act(array $request): array
{
    return dispatch_action($request);
}

/** @return array<string, mixed> */
function detail(string $name): array
{
    return act(['action' => 'session_detail', 'session' => $name]);
}

function status_of(string $name): string
{
    return (string)(detail($name)['status'] ?? '?');
}

/** The manager's own view of a session (process state, pid). @return array<string, mixed> */
function call_manager(array $m, string $session): array
{
    return (new HostAgent\Runtimes\ClaudeHeadlessManagerClient($m['sock'], 30))->request('sessioneer/status', ['session' => $session]);
}

$m = start_manager($root, 'mgr');
assert_true(file_exists($m['sock']), 'the manager is up');

// ===================================================================== create

echo "Create\n";
$workdir = $root . '/work/project';
@mkdir($workdir, 0700, true);
$created = act(['action' => 'create', 'agent' => 'claude', 'runtime' => 'headless', 'workdir' => $workdir, 'model' => 'haiku', 'starting_mode' => 'plan']);
assert_true(($created['ok'] ?? false) === true, 'create with runtime=headless succeeds' . (($created['ok'] ?? false) === true ? '' : ' - got ' . json_encode($created)));
$name = (string)($created['name'] ?? '');
$made[] = $name;

if (($created['ok'] ?? false) !== true) {
    // Nothing after this can mean anything; show why the manager is not answering.
    foreach ($managers as $started) {
        fwrite(STDOUT, "\n--- manager log ---\n" . substr((string)@file_get_contents($started['log']), -2000) . "\n");
    }
    test_exit();
}
assert_true(preg_match('/^claude-headless-\d{8}-\d{6}(-\d+)?$/', $name) === 1, 'the session is named claude-headless-<timestamp>');
assert_equal($name, $created['session'], 'the create reply carries the name the redirect uses');
$sidecar = SidecarStore::read_sidecar($name);
assert_equal('headless', $sidecar['runtime'], 'the sidecar says headless');
assert_equal($workdir, $sidecar['workdir'], 'with its working directory');
$launch = fake_log($m)[0];
assert_true(in_array('haiku', $launch['argv'], true) && in_array('plan', $launch['argv'], true), 'the requested model and starting mode reach Claude');

$bad = act(['action' => 'create', 'agent' => 'claude', 'runtime' => 'headless', 'workdir' => 'relative/path']);
assert_true(($bad['ok'] ?? true) === false, 'a relative workdir is rejected');
assert_contains('existing absolute workdir', (string)$bad['message'], 'with a clear message');
$missing = act(['action' => 'create', 'agent' => 'claude', 'runtime' => 'headless', 'workdir' => $root . '/does-not-exist']);
assert_true(($missing['ok'] ?? true) === false, 'a missing workdir is rejected');

// ============================================================ list and detail

echo "List and detail\n";
$list = act(['action' => 'list']);
$rows = array_values(array_filter($list['sessions'], static fn (array $s): bool => $s['name'] === $name));
assert_equal(1, count($rows), 'the dashboard list contains the headless session');
assert_equal('headless', $rows[0]['runtime'], 'as a headless row');
assert_equal('claude', $rows[0]['agent'], 'for Claude');
assert_equal('Claude Code', $rows[0]['agent_label'], 'labelled Claude Code');

$d = detail($name);
assert_true(($d['ok'] ?? false) === true, 'session_detail resolves it');
assert_equal($name, $d['name'], 'by its ref');
assert_equal('headless', $d['runtime'], 'as headless');
assert_equal('idle', $d['status'], 'idle before any message');
assert_equal(false, $d['has_transcript'], 'with no transcript yet, handled rather than an error');
assert_equal('Session not found', act(['action' => 'session_detail', 'session' => 'claude-headless-nope'])['message'] ?? '', 'an unknown session is a handled error');

// ============================================================ send + history

echo "Send and history\n";
assert_true((act(['action' => 'send_message', 'session' => $name, 'text' => 'hello there'])['ok'] ?? false) === true, 'send_message reaches the session');
assert_true((bool)wait_until(static fn (): bool => status_of($name) === 'idle' && has_result($m, 'echo: hello there')), 'the turn completes');
$d = detail($name);
assert_equal('plan', $d['current_mode'], 'the mode Claude reported is shown in Sessioneer vocabulary');
$agentSessionId = (string)$d['agent_session_id'];
assert_true($agentSessionId !== '', 'the current Claude conversation id is exposed');

assert_contains('Transcript file not found', (string)(act(['action' => 'session_history', 'session' => $name, 'limit' => 10])['message'] ?? ''), 'history before any transcript exists is a handled message, resolved through the sidecar');
$transcriptDir = $root . '/home/.claude/projects/' . preg_replace('/[^A-Za-z0-9]/', '-', $workdir);
@mkdir($transcriptDir, 0700, true);
copy(__DIR__ . '/fixtures/transcript_sample.jsonl', "{$transcriptDir}/{$agentSessionId}.jsonl");
$history = act(['action' => 'session_history', 'session' => $name, 'limit' => 10]);
assert_true(($history['ok'] ?? false) === true && count($history['entries'] ?? []) > 0, 'history is read from the transcript through the sidecar\'s conversation id');
assert_true(detail($name)['has_transcript'] === true, 'and the detail knows a transcript exists');

echo "Archived list\n";
$archivedIds = array_column(act(['action' => 'list_archived'])['archived'] ?? [], 'agent_session_id');
assert_true(!in_array($agentSessionId, $archivedIds, true), 'a live headless conversation is NOT listed as archived');

// ============================================================= permissions

echo "Permission prompt\n";
act(['action' => 'send_message', 'session' => $name, 'text' => 'PERMISSION please']);
assert_true((bool)wait_until(static fn (): bool => status_of($name) === 'blocked'), 'a permission request blocks the session');
$d = detail($name);
assert_equal('Do you want to proceed?', $d['blocked_reason'], 'the dashboard gets the same question a tmux session shows');
assert_equal(3, count($d['prompt_options']), 'and the numbered options');
assert_equal('Write', $d['prompt_tool_name'], 'and the tool');
assert_equal('/tmp/fake.txt', $d['prompt_tool_input']['file_path'], 'with its exact input');
assert_equal(null, $d['prompt_questions'], 'and no question form');
assert_contains('does not match', (string)(act(['action' => 'answer_prompt', 'session' => $name, 'option' => 9])['message'] ?? ''), 'an option that is not on the menu is rejected');
assert_equal('blocked', status_of($name), 'and leaves the prompt pending');
assert_true((act(['action' => 'answer_prompt', 'session' => $name, 'option' => 1])['ok'] ?? false) === true, 'answering option 1 succeeds');
assert_true((bool)wait_until(static fn (): bool => has_result($m, 'tool Write answered: allow') && status_of($name) === 'idle'), 'Claude received an allow and the turn finished');
assert_contains('no prompt is currently pending', (string)(act(['action' => 'answer_prompt', 'session' => $name, 'option' => 1])['message'] ?? ''), 'answering again is rejected');

act(['action' => 'send_message', 'session' => $name, 'text' => 'PERMISSION again']);
wait_until(static fn (): bool => status_of($name) === 'blocked');
act(['action' => 'answer_prompt_with_text', 'session' => $name, 'option' => 3, 'text' => 'not that file']);
assert_true((bool)wait_until(static fn (): bool => has_result($m, 'tool Write answered: deny')), 'a "No" with typed feedback denies');
wait_until(static fn (): bool => status_of($name) === 'idle');

echo "Question prompt\n";
act(['action' => 'send_message', 'session' => $name, 'text' => 'QUESTION now']);
wait_until(static fn (): bool => status_of($name) === 'blocked');
$d = detail($name);
assert_equal(1, count($d['prompt_questions']), 'a question exposes its questions for the structured form');
assert_equal('AskUserQuestion', $d['prompt_tool_name'], 'and the tool');
assert_contains('does not match', (string)(act(['action' => 'answer_multi_question', 'session' => $name, 'answers' => [1, 1]])['message'] ?? ''), 'the wrong number of answers is rejected');
assert_true((act(['action' => 'answer_multi_question', 'session' => $name, 'answers' => [2]])['ok'] ?? false) === true, 'a fitting answer is delivered');
assert_true((bool)wait_until(static fn (): bool => has_result($m, 'tool AskUserQuestion answered: allow') && status_of($name) === 'idle'), 'and the turn finishes');

// ============================================== interrupt, mode, model, kill

echo "Interrupt, mode, model\n";
act(['action' => 'send_message', 'session' => $name, 'text' => 'SLOW work']);
assert_true((bool)wait_until(static fn (): bool => status_of($name) === 'working'), 'a slow turn shows as working');
assert_true((act(['action' => 'send_escape', 'session' => $name])['ok'] ?? false) === true, 'send_escape interrupts it');
assert_true((bool)wait_until(static fn (): bool => status_of($name) === 'idle'), 'and the session is idle again');

assert_true((act(['action' => 'set_mode', 'session' => $name, 'mode' => 'accept edits'])['ok'] ?? false) === true, 'set_mode works on a headless Claude session');
assert_true((bool)wait_until(static fn (): bool => detail($name)['current_mode'] === 'accept edits'), 'and the new mode shows');
assert_contains('unknown permission mode', (string)(act(['action' => 'set_mode', 'session' => $name, 'mode' => 'yolo'])['message'] ?? ''), 'an unknown mode is rejected');
assert_true((act(['action' => 'set_model', 'session' => $name, 'model' => 'opus'])['ok'] ?? false) === true, 'set_model works');
assert_equal('opus', detail($name)['current_model'], 'and the model shows at once');
assert_contains('choose one of the model families', (string)(act(['action' => 'set_model', 'session' => $name, 'model' => 'default'])['message'] ?? ''), '"default" is not a selectable model here');

echo "Kill\n";
$pid = (int)(call_manager($m, $name)['pid'] ?? 0);
assert_true($pid > 0 && pid_alive($pid), 'the session has a live process');
assert_true((act(['action' => 'kill', 'session' => $name])['ok'] ?? false) === true, 'kill succeeds');
assert_true(!pid_alive($pid), 'the process is gone when kill returns');
assert_equal(null, SidecarStore::read_sidecar($name), 'the sidecar is removed');
assert_equal(null, SessionStatusStore::read_status($name), 'and its status');
assert_equal([], array_values(array_filter(act(['action' => 'list'])['sessions'], static fn (array $s): bool => $s['name'] === $name)), 'the session leaves the list at once');
$archivedIds = array_column(act(['action' => 'list_archived'])['archived'] ?? [], 'agent_session_id');
assert_true(in_array($agentSessionId, $archivedIds, true), 'its conversation is now archived and can be resumed');

// ================================================ resume as headless, and switch

echo "Resume as headless\n";
$noTranscript = act(['action' => 'resume', 'runtime' => 'headless', 'workdir' => $workdir, 'agent_session_id' => 'no-such-conversation']);
assert_true(($noTranscript['ok'] ?? true) === false, 'resuming a conversation with no transcript fails');
assert_contains('No transcript was found', (string)$noTranscript['message'], 'with a clear message');
assert_contains('existing absolute path', (string)(act(['action' => 'resume', 'runtime' => 'headless', 'workdir' => 'relative', 'agent_session_id' => $agentSessionId])['message'] ?? ''), 'a relative workdir is rejected');
assert_contains('Missing agent_session_id', (string)(act(['action' => 'resume', 'runtime' => 'headless', 'workdir' => $workdir, 'agent_session_id' => ''])['message'] ?? ''), 'so is a missing conversation id');

$resumed = act(['action' => 'resume', 'runtime' => 'headless', 'workdir' => $workdir, 'agent_session_id' => $agentSessionId]);
assert_true(($resumed['ok'] ?? false) === true, 'an archived conversation can be resumed headless' . (($resumed['ok'] ?? false) === true ? '' : ' - got ' . json_encode($resumed)));
$resumedName = (string)($resumed['name'] ?? '');
$made[] = $resumedName;
assert_true(preg_match('/^claude-headless-\d{8}-\d{6}(-\d+)?$/', $resumedName) === 1, 'as a claude-headless-<timestamp> session');
assert_equal($agentSessionId, SidecarStore::read_sidecar($resumedName)['agent_session_id'], 'bound to that same conversation');
act(['action' => 'send_message', 'session' => $resumedName, 'text' => 'back again']);
assert_true((bool)wait_until(static fn (): bool => has_result($m, "echo: back again [spawn=resume id={$agentSessionId}")), 'the process continues the SAME conversation (--resume)');
wait_until(static fn (): bool => status_of($resumedName) === 'idle');

$again = act(['action' => 'resume', 'runtime' => 'headless', 'workdir' => $workdir, 'agent_session_id' => $agentSessionId]);
assert_true(($again['ok'] ?? true) === false, 'a conversation that is already live cannot be resumed a second time');
assert_contains('already has a live process', (string)$again['message'], 'with a clear message');
$asPane = act(['action' => 'resume', 'workdir' => $workdir, 'agent_session_id' => $agentSessionId]);
assert_true(($asPane['ok'] ?? true) === false, 'nor can it be resumed in a tmux pane (two writers on one transcript)');
assert_contains('already has a live pane', (string)$asPane['message'], 'the existing guard now sees headless sessions too');

echo "Switch runtime\n";
assert_contains('Unknown runtime', (string)(act(['action' => 'switch_runtime', 'session' => $resumedName, 'runtime' => 'bogus'])['message'] ?? ''), 'an unknown target runtime is rejected');
assert_contains('already running that way', (string)(act(['action' => 'switch_runtime', 'session' => $resumedName, 'runtime' => 'headless'])['message'] ?? ''), 'switching to the runtime it already has is rejected');
assert_equal('Session not found', act(['action' => 'switch_runtime', 'session' => 'claude-headless-nope', 'runtime' => 'tmux'])['message'] ?? '', 'an unknown session is rejected');
$codexName = 'codex-headless-t' . getmypid();
$made[] = $codexName;
SidecarStore::write_sidecar($codexName, ['workdir' => $workdir, 'spawned_at' => time(), 'agent_session_id' => 'codex-thread', 'agent' => 'codex', 'runtime' => 'headless']);
assert_contains('Only Claude Code', (string)(act(['action' => 'switch_runtime', 'session' => $codexName, 'runtime' => 'tmux'])['message'] ?? ''), 'another agent\'s session is rejected');

$fresh = act(['action' => 'create', 'agent' => 'claude', 'runtime' => 'headless', 'workdir' => $workdir]);
$freshName = (string)($fresh['name'] ?? '');
$made[] = $freshName;
assert_contains('nothing to carry over', (string)(act(['action' => 'switch_runtime', 'session' => $freshName, 'runtime' => 'tmux'])['message'] ?? ''), 'a session with no messages yet has no conversation to carry over');
act(['action' => 'kill', 'session' => $freshName]);

act(['action' => 'send_message', 'session' => $resumedName, 'text' => 'SLOW work']);
wait_until(static fn (): bool => status_of($resumedName) === 'working');
assert_contains('busy', (string)(act(['action' => 'switch_runtime', 'session' => $resumedName, 'runtime' => 'tmux'])['message'] ?? ''), 'a session that is mid-turn is not switched');
assert_true(SidecarStore::read_sidecar($resumedName) !== null, 'and nothing was stopped');
act(['action' => 'send_escape', 'session' => $resumedName]);
wait_until(static fn (): bool => status_of($resumedName) === 'idle');

$pid = (int)(call_manager($m, $resumedName)['pid'] ?? 0);
$toTmux = act(['action' => 'switch_runtime', 'session' => $resumedName, 'runtime' => 'tmux']);
assert_true(($toTmux['ok'] ?? false) === true, 'a headless session can move into a tmux pane' . (($toTmux['ok'] ?? false) === true ? '' : ' - got ' . json_encode($toTmux)));
$tmuxName = (string)($toTmux['name'] ?? '');
$made[] = $tmuxName;
assert_true(str_starts_with($tmuxName, 'cc-'), 'as an ordinary cc-* tmux session');
assert_true(!pid_alive($pid), 'the headless process is gone');
assert_equal(null, SidecarStore::read_sidecar($resumedName), 'and its session removed');
assert_equal($agentSessionId, SidecarStore::read_sidecar($tmuxName)['agent_session_id'], 'while the conversation carries over to the pane');
assert_true(($tmuxRow = array_values(array_filter(act(['action' => 'list'])['sessions'], static fn (array $s): bool => $s['name'] === $tmuxName))) !== [] && $tmuxRow[0]['runtime'] === 'tmux', 'and it lists as a tmux session');

$toHeadless = act(['action' => 'switch_runtime', 'session' => $tmuxName, 'runtime' => 'headless']);
assert_true(($toHeadless['ok'] ?? false) === true, 'and back out of the pane' . (($toHeadless['ok'] ?? false) === true ? '' : ' - got ' . json_encode($toHeadless)));
$backName = (string)($toHeadless['name'] ?? '');
$made[] = $backName;
assert_true(str_starts_with($backName, 'claude-headless-'), 'into a headless session');
assert_equal(null, SidecarStore::read_sidecar($tmuxName), 'with the pane session gone');
assert_equal($agentSessionId, SidecarStore::read_sidecar($backName)['agent_session_id'], 'and the same conversation');
act(['action' => 'kill', 'session' => $backName]);

// ============================================================ manager down

echo "Failure: manager down\n";
$second = act(['action' => 'create', 'agent' => 'claude', 'runtime' => 'headless', 'workdir' => $workdir]);
$name2 = (string)$second['name'];
$made[] = $name2;
proc_terminate($m['proc'], SIGTERM);
wait_until(static fn (): bool => !proc_get_status($m['proc'])['running'], 12.0);
$sent = act(['action' => 'send_message', 'session' => $name2, 'text' => 'hello?']);
assert_true(($sent['ok'] ?? true) === false, 'sending with the manager down fails');
assert_contains('Cannot reach Claude headless manager', (string)$sent['message'], 'with a named, handled error');
$killed = act(['action' => 'kill', 'session' => $name2]);
assert_true(($killed['ok'] ?? false) === true, 'kill still succeeds - no process can outlive the manager');
assert_equal(null, SidecarStore::read_sidecar($name2), 'and removes the session');
$createDown = act(['action' => 'create', 'agent' => 'claude', 'runtime' => 'headless', 'workdir' => $workdir]);
assert_true(($createDown['ok'] ?? true) === false, 'creating with the manager down fails');
assert_contains('Cannot reach Claude headless manager', (string)$createDown['message'], 'with the same named error');
assert_equal(0, count(array_filter(SidecarStore::list_runtime_sidecars('headless'), static fn (array $r): bool => ($r['agent'] ?? null) === 'claude' && ($r['workdir'] ?? null) === $workdir)), 'and leaves no half-created Claude session behind');

test_exit();
