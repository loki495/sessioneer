<?php

declare(strict_types=1);

/**
 * The host-agent half of the CLI's session control (`launch_show`,
 * `launch_answer`, `launch_interrupt`, `launch_send` with as_user,
 * `launch_kill` on a terminal session, `launch_bare`, `launch_take_over`) and
 * the dashboard actions that now share its code (`kill`, plain `resume`).
 * A REAL ClaudeHeadlessManager drives the scripted tests/fixtures/
 * fake_claude_stream; terminal Claudes are a stand-in process and a tmux pane
 * on the test's own tmux server. Never spawns the real `claude`, never billable.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/claude_headless.php';

$root = sys_get_temp_dir() . '/sessioneer-test-launch-control-' . getmypid();
@mkdir($root . '/home/.claude/projects', 0700, true);
@mkdir($root . '/work/project', 0700, true);

$realTmuxSocket = \HostAgent\Services\Config::tmux_socket();
$realSidecarDir = \HostAgent\Services\Config::sidecar_dir();

foreach ([
    'TMUX_SOCKET' => $root . '/tmux/socket',
    'CACHE_DIR' => $root . '/cache',
    'SIDECAR_DIR' => $root . '/sidecars',
    'SESSION_LIST_CACHE_TTL_SECONDS' => '0',
    'OPENCODE_SERVE_URL' => 'http://127.0.0.1:1',
    'OPENCODE_DB_PATH' => $root . '/no-opencode.db',
    'CODEX_BRIDGE_SOCKET' => $root . '/no-codex-bridge.sock',
    'PUSH_SQLITE_FILE' => $root . '/push.sqlite',
    'SESSIONS_SQLITE_FILE' => $root . '/sessions.sqlite',
    'TAKE_OVER_EXIT_WAIT_SECONDS' => '10',
] as $key => $value) {
    putenv("{$key}={$value}");
}

if (\HostAgent\Services\Config::tmux_socket() === $realTmuxSocket || \HostAgent\Services\Config::sidecar_dir() === $realSidecarDir) {
    fwrite(STDERR, "REFUSING TO RUN: configuration resolves to the real host.\n");
    exit(1);
}

@mkdir((string)getenv('SIDECAR_DIR'), 0700, true);
@mkdir((string)getenv('CACHE_DIR'), 0700, true);
@mkdir(dirname((string)getenv('TMUX_SOCKET')), 0700, true);
putenv('HOME_ROOT=' . $root . '/home');
putenv('CLAUDE_HEADLESS_SOCKET=' . $root . '/mgr.sock');

require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\ProcessInspector;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/** @var array<int, array{proc: resource, sock: string, log: string, fake: string}> $managers */
$managers = [];

/** @var array<int, string> $made */
$made = [];

/** @var array<int, resource> $procs */
$procs = [];

register_shutdown_function(static function () use (&$managers, &$made, &$procs, $root): void {
    foreach ($procs as $p) {
        @proc_terminate($p, SIGKILL);
        @proc_close($p);
    }

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

$m = start_manager($root, 'mgr');
$workdir = $root . '/work/project';

// The manager keeps its own fake binary; from here the host agent's CLAUDE_BIN
// is the stand-in that terminal ("bare") Claude processes are started from.
$wrapper = $root . '/terminalclaude';
file_put_contents($wrapper, "#!/bin/bash\nexec -a \"\$0\" perl -e 'sleep 300;' -- \"\$@\"\n");
chmod($wrapper, 0755);
putenv('CLAUDE_BIN=' . $wrapper);

echo "A blocked headless session\n";
$new = act(['action' => 'launch', 'prompt' => 'PERMISSION please', 'workdir' => $workdir]);
assert_true(($new['ok'] ?? false) === true, 'a session starts' . (($new['ok'] ?? false) === true ? '' : ' - got ' . json_encode($new)));
$name = (string)$new['name'];
$made[] = $name;
assert_true((bool)wait_until(static fn (): bool => status_is($name, 'blocked')), 'and blocks on a permission request');
$conversationId = (string)SidecarStore::read_sidecar($name)['agent_session_id'];

echo "Show\n";
$shown = act(['action' => 'launch_show', 'target' => $name]);
assert_equal(true, $shown['ok'] ?? null, 'a session is shown by name');
assert_equal('blocked', $shown['status'] ?? null, 'with its status');
assert_equal('Do you want to proceed?', $shown['blocked_reason'] ?? null, 'the question it is blocked on');
assert_equal(3, count($shown['prompt_options'] ?? []), 'and the numbered options');
assert_equal('Write', $shown['prompt_tool_name'] ?? null, 'and the tool');
assert_true(!array_key_exists('model_labels', $shown) && !array_key_exists('todos', $shown), 'without the dashboard-only fields');
assert_contains('No tracked session found', (string)act(['action' => 'launch_show', 'target' => 'no-such-session'])['message'], 'an unknown session is a handled error');
assert_contains('Needs a session name', (string)act(['action' => 'launch_show', 'target' => ' '])['message'], 'so is a blank target');

echo "Answer: refusals\n";
$inSession = act(['action' => 'launch_answer', 'target' => $name, 'option' => 1, 'from' => $name]);
assert_equal(false, $inSession['ok'] ?? null, 'a session cannot answer a prompt, its own included');
assert_contains('outside any session', (string)$inSession['message'], 'and is told why');
assert_true(status_is($name, 'blocked'), 'the prompt is still pending');
foreach ([
    'neither an option nor answers' => [[], 'exactly one of an option number'],
    'both' => [['option' => 1, 'answers' => [1]], 'exactly one of an option number'],
    'a zero option' => [['option' => 0], 'exactly one of an option number'],
    'empty answers' => [['answers' => []], 'non-empty list'],
    'answers with text' => [['answers' => [1], 'text' => 'x'], 'non-empty list'],
] as $label => [$extra, $needle]) {
    $refused = act(['action' => 'launch_answer', 'target' => $name] + $extra);
    assert_equal(false, $refused['ok'] ?? null, "{$label}: refused");
    assert_contains($needle, (string)$refused['message'], "{$label}: says why");
}

assert_contains('No tracked session found', (string)act(['action' => 'launch_answer', 'target' => 'nope', 'option' => 1])['message'], 'an unknown session is refused');
assert_true(status_is($name, 'blocked'), 'none of the refusals answered the prompt');

echo "Answer: bound to the prompt that was read\n";
$requestId = (string)($shown['prompt_request_id'] ?? '');
assert_true($requestId !== '', 'a shown prompt carries its request id');
$stale = act(['action' => 'answer_prompt', 'session' => $name, 'option' => 1, 'expected_request_id' => 'not-the-pending-request']);
assert_equal(false, $stale['ok'] ?? null, 'an answer naming another request id is refused');
assert_contains('changed', (string)$stale['message'], 'and says the prompt changed');
assert_true(status_is($name, 'blocked'), 'the pending prompt was not answered');
$notSeen = act(['action' => 'launch_answer', 'target' => $name, 'option' => 1, 'expected_request_id' => 'not-the-prompt-you-read']);
assert_equal(false, $notSeen['ok'] ?? null, 'launch_answer refuses a request id other than the pending one');
assert_contains('not the one you read', (string)$notSeen['message'], 'and says so');
assert_true(status_is($name, 'blocked'), 'without answering');

echo "Answer: happy paths\n";
$label = (string)$shown['prompt_options'][0]['label'];
$answered = act(['action' => 'launch_answer', 'target' => $name, 'option' => 1, 'expected_label' => strtoupper($label)]);
assert_equal(true, $answered['ok'] ?? null, 'option 1 is answered when its label matches (case aside)' . (($answered['ok'] ?? false) === true ? '' : ' - got ' . json_encode($answered)));
assert_equal($name, $answered['name'] ?? null, 'naming the session');
assert_true((bool)wait_until(static fn (): bool => has_result($m, 'tool Write answered: allow') && status_is($name, 'idle')), 'Claude receives an allow and the turn finishes');
assert_contains('has no prompt waiting', (string)act(['action' => 'launch_answer', 'target' => $name, 'option' => 1])['message'], 'answering again is refused up front');
echo "Show: by conversation id and with history\n";
assert_equal($name, act(['action' => 'launch_show', 'target' => $conversationId])['name'] ?? null, 'a session is shown by conversation id too');
assert_true(!array_key_exists('history', act(['action' => 'launch_show', 'target' => $name])), 'with no history unless asked for');
$transcriptDir = $root . '/home/.claude/projects/' . preg_replace('/[^A-Za-z0-9]/', '-', $workdir);
@mkdir($transcriptDir, 0700, true);
copy(__DIR__ . '/fixtures/transcript_sample.jsonl', "{$transcriptDir}/{$conversationId}.jsonl");
$withHistory = act(['action' => 'launch_show', 'target' => $name, 'history' => 2]);
assert_true(count($withHistory['history'] ?? []) > 0 && count($withHistory['history']) <= 2, 'history N returns at most N transcript entries');

echo "Answer: a prompt that is not the one expected\n";
act(['action' => 'send_message', 'session' => $name, 'text' => 'PERMISSION again']);
wait_until(static fn (): bool => status_is($name, 'blocked'));
$wrongLabel = act(['action' => 'launch_answer', 'target' => $name, 'option' => 1, 'expected_label' => 'Delete everything']);
assert_equal(false, $wrongLabel['ok'] ?? null, 'an option whose label is not the one expected is refused');
assert_contains("not 'Delete everything'", (string)$wrongLabel['message'], 'naming what it is');
assert_contains('not offered', (string)act(['action' => 'launch_answer', 'target' => $name, 'option' => 9, 'expected_label' => 'Yes'])['message'], 'and an option off the menu is not offered');
assert_true(status_is($name, 'blocked'), 'the prompt is still pending');
assert_equal(true, act(['action' => 'launch_answer', 'target' => $conversationId, 'option' => 3, 'text' => 'not that file'])['ok'] ?? null, 'a "No" with feedback text is delivered, addressed by conversation id');
assert_true((bool)wait_until(static fn (): bool => has_result($m, 'tool Write answered: deny')), 'and denies');
wait_until(static fn (): bool => status_is($name, 'idle'));

act(['action' => 'send_message', 'session' => $name, 'text' => 'QUESTION now']);
wait_until(static fn (): bool => status_is($name, 'blocked'));
assert_equal(1, count(act(['action' => 'launch_show', 'target' => $name])['prompt_questions'] ?? []), 'a question prompt shows its questions');
assert_equal(false, act(['action' => 'launch_answer', 'target' => $name, 'answers' => [1, 1]])['ok'] ?? null, 'the wrong number of answers is refused');
assert_equal(true, act(['action' => 'launch_answer', 'target' => $name, 'answers' => [2]])['ok'] ?? null, 'a multi-question answer is delivered');
assert_true((bool)wait_until(static fn (): bool => has_result($m, 'tool AskUserQuestion answered: allow') && status_is($name, 'idle')), 'and the turn finishes');

echo "Interrupt and send as the user\n";
$tooEarly = act(['action' => 'launch_send', 'target' => $name, 'message' => 'x', 'as_user' => true, 'from' => $name]);
assert_equal(false, $tooEarly['ok'] ?? null, 'a session cannot pass as the user');
assert_contains('outside any session', (string)$tooEarly['message'], 'and is told why');
assert_contains('needs a target', (string)act(['action' => 'launch_send', 'target' => $name, 'message' => '  ', 'as_user' => true])['message'], 'a blank message is refused');
assert_contains('No tracked session found', (string)act(['action' => 'launch_send', 'target' => 'nope', 'message' => 'hi', 'as_user' => true])['message'], 'so is an unknown target');
$sent = act(['action' => 'launch_send', 'target' => $conversationId, 'message' => 'SLOW work', 'as_user' => true]);
assert_equal(true, $sent['ok'] ?? null, 'a shell outside any session can message a session by conversation id');
assert_equal('sent', $sent['action'] ?? null, 'reported as sent');
assert_true((bool)wait_until(static fn (): bool => status_is($name, 'working')), 'the session starts the turn');
assert_true(!str_contains(json_encode(fake_results($m)), 'sessioneer peer'), 'and the text carries no peer stamp');
assert_equal(true, act(['action' => 'launch_interrupt', 'target' => $name])['ok'] ?? null, 'it can be interrupted');
assert_true((bool)wait_until(static fn (): bool => status_is($name, 'idle')), 'and is idle again');
assert_contains('No tracked session found', (string)act(['action' => 'launch_interrupt', 'target' => 'nope'])['message'], 'interrupting an unknown session is refused');

echo "Kill\n";
$busy = act(['action' => 'launch', 'prompt' => 'SLOW work', 'workdir' => $workdir]);
$busyName = (string)$busy['name'];
$made[] = $busyName;
wait_until(static fn (): bool => status_is($busyName, 'working'));
$refusedKill = act(['action' => 'launch_kill', 'target' => $busyName]);
assert_equal(false, $refusedKill['ok'] ?? null, 'a working session is not killed without --force');
assert_contains('use --force', (string)$refusedKill['message'], 'and the message says how');
assert_equal(true, act(['action' => 'kill', 'session' => $busyName])['ok'] ?? null, 'the dashboard\'s Kill is the forced CLI kill');
assert_equal(null, SidecarStore::read_sidecar($busyName), 'and removes the session at once');
assert_contains('No headless Claude session found', (string)act(['action' => 'launch_kill', 'target' => 'nope'])['message'], 'an unknown target is a handled error');

$tmuxBefore = array_column(TmuxService::list_tracked_tmux_sessions(), 'name');
$pane = act(['action' => 'create', 'agent' => 'claude', 'runtime' => 'tmux', 'workdir' => $workdir]);
$paneName = (string)(array_values(array_diff(array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), $tmuxBefore))[0] ?? '');
$made[] = $paneName;
assert_true(str_starts_with($paneName, 'cc-'), 'a tmux session exists' . (($pane['ok'] ?? false) === true ? '' : ' - got ' . json_encode($pane)));
$paneKill = act(['action' => 'launch_kill', 'target' => $paneName, 'force' => true]);
assert_equal(true, $paneKill['ok'] ?? null, 'the CLI kill reaches a tmux session too' . (($paneKill['ok'] ?? false) === true ? '' : ' - got ' . json_encode($paneKill)));
assert_true(!in_array($paneName, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), true), 'and the pane is gone');
assert_equal(null, SidecarStore::read_sidecar($paneName), 'with its sidecar');

echo "Kill: an unreadable terminal session\n";
SidecarStore::write_sidecar('cc-ghost-kill', ['workdir' => $workdir, 'spawned_at' => time(), 'agent_session_id' => null, 'spawned_by_app' => true, 'agent' => 'claude', 'runtime' => 'tmux', 'title' => null, 'profile' => null]);
$ghost = act(['action' => 'launch_kill', 'target' => 'cc-ghost-kill']);
assert_equal(false, $ghost['ok'] ?? null, 'a terminal session whose status cannot be read is not killed without --force');
assert_contains('--force', (string)$ghost['message'], 'and the message says how');
assert_true(SidecarStore::read_sidecar('cc-ghost-kill') !== null, 'its row stays');
$ghostForced = act(['action' => 'launch_kill', 'target' => 'cc-ghost-kill', 'force' => true]);
assert_true(!str_contains((string)($ghostForced['message'] ?? ''), 'Could not read the status'), 'with --force the status is not even read');
SidecarStore::delete_sidecar('cc-ghost-kill');

echo "Terminal Claude: list\n";

/** @return array{pid:int, id:string} */
function start_bare(string $wrapper, string $workdir, string $id): array
{
    global $procs;

    $p = proc_open([$wrapper, '--resume', $id], [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $workdir, ['PATH' => (string)getenv('PATH')]);
    $procs[] = $p;
    $pid = (int)proc_get_status($p)['pid'];
    wait_until(static fn (): bool => in_array($pid, array_column(ProcessInspector::find_claude_processes(), 'pid'), true), 5.0);

    return ['pid' => $pid, 'id' => $id];
}

$bareId = 'bbbbbbbb-1111-4111-8111-bbbbbbbbbbbb';
copy(__DIR__ . '/fixtures/transcript_sample.jsonl', "{$transcriptDir}/{$bareId}.jsonl");
$bare = start_bare($wrapper, $workdir, $bareId);
$listed = act(['action' => 'launch_bare']);
assert_equal(true, $listed['ok'] ?? null, 'terminal Claude processes are listed');
$row = array_values(array_filter($listed['bare'] ?? [], static fn (array $b): bool => (int)$b['pid'] === $bare['pid']))[0] ?? [];
assert_equal($bareId, $row['resolved_agent_session_id'] ?? null, 'with the conversation each runs');
assert_equal(null, act(['action' => 'launch', 'agent_session_id' => $bareId])['name'] ?? null, 'a launch on that conversation is refused');
assert_contains('--take-over', (string)act(['action' => 'launch', 'agent_session_id' => $bareId])['message'], 'pointing at --take-over');

echo "Terminal Claude: take over\n";
assert_contains('needs a process id', (string)act(['action' => 'launch_take_over', 'target' => ''])['message'], 'a blank target is refused');
assert_contains('not a currently running claude process', (string)act(['action' => 'launch_take_over', 'target' => '999999'])['message'], 'a pid that is not a Claude process is refused');
assert_contains('No terminal Claude process', (string)act(['action' => 'launch_take_over', 'target' => 'cccccccc-1111-4111-8111-cccccccccccc'])['message'], 'so is a conversation nothing runs');
assert_true(pid_alive($bare['pid']), 'and nothing was stopped');
$wrong = act(['action' => 'launch_take_over', 'target' => (string)$bare['pid'], 'agent_session_id' => 'cccccccc-1111-4111-8111-cccccccccccc']);
assert_equal(false, $wrong['ok'] ?? null, 'a process whose conversation is known is not taken over for another one');
assert_contains('is running conversation', (string)$wrong['message'], 'and the message names the conversation it runs');
assert_true(pid_alive($bare['pid']), 'and nothing was stopped');
$taken = act(['action' => 'launch_take_over', 'target' => $bareId]);
assert_equal(true, $taken['ok'] ?? null, 'a terminal Claude is taken over by its conversation id' . (($taken['ok'] ?? false) === true ? '' : ' - got ' . json_encode($taken)));
$takenName = (string)($taken['name'] ?? '');
$made[] = $takenName;
assert_true(str_starts_with($takenName, 'claude-headless-'), 'into a headless session, not a pane');
assert_equal($bareId, SidecarStore::read_sidecar($takenName)['agent_session_id'] ?? null, 'on the same conversation');
assert_true((bool)wait_until(static fn (): bool => !pid_alive($bare['pid'])), 'and the terminal process is gone');

$pidId = 'dddddddd-1111-4111-8111-dddddddddddd';
copy(__DIR__ . '/fixtures/transcript_sample.jsonl', "{$transcriptDir}/{$pidId}.jsonl");
$unknown = start_bare($wrapper, $workdir, $pidId);
$byPid = act(['action' => 'launch_take_over', 'target' => (string)$unknown['pid']]);
assert_equal(true, $byPid['ok'] ?? null, 'a pid works as well' . (($byPid['ok'] ?? false) === true ? '' : ' - got ' . json_encode($byPid)));
$made[] = (string)($byPid['name'] ?? '');
assert_equal($pidId, SidecarStore::read_sidecar((string)$byPid['name'])['agent_session_id'] ?? null, 'on the conversation named in its arguments');

$ambiguous = start_bare($wrapper, $workdir, '');
$candidateId = 'eeeeeeee-1111-4111-8111-eeeeeeeeeeee';
// A candidate is offered only for the working directory the process runs in.
file_put_contents("{$transcriptDir}/{$candidateId}.jsonl", json_encode(['type' => 'user', 'uuid' => 'u0', 'cwd' => $workdir, 'timestamp' => '2026-01-01T00:00:00Z', 'message' => ['role' => 'user', 'content' => 'Fix the bug']]) . "\n" . file_get_contents(__DIR__ . '/fixtures/transcript_sample.jsonl'));
$choice = act(['action' => 'launch_take_over', 'target' => (string)$ambiguous['pid']]);
assert_equal(true, $choice['needs_choice'] ?? null, 'a process whose conversation cannot be told asks for a choice');
assert_true(pid_alive($ambiguous['pid']), 'and stops nothing');
$notOffered = act(['action' => 'launch_take_over', 'target' => (string)$ambiguous['pid'], 'agent_session_id' => 'cccccccc-1111-4111-8111-cccccccccccc']);
assert_equal(false, $notOffered['ok'] ?? null, 'an unresolved process is only taken over for one of its candidates');
assert_true(pid_alive($ambiguous['pid']), 'and nothing was stopped');
$confirmed = act(['action' => 'launch_take_over', 'target' => (string)$ambiguous['pid'], 'agent_session_id' => $candidateId]);
assert_equal(true, $confirmed['ok'] ?? null, 'naming the conversation completes the take over' . (($confirmed['ok'] ?? false) === true ? '' : ' - got ' . json_encode($confirmed)));
$made[] = (string)($confirmed['name'] ?? '');
assert_equal($candidateId, SidecarStore::read_sidecar((string)$confirmed['name'])['agent_session_id'] ?? null, 'on the chosen conversation');
assert_true((bool)wait_until(static fn (): bool => !pid_alive($ambiguous['pid'])), 'and the terminal process is gone');

echo "Resume\n";
$resumeId = 'ffffffff-1111-4111-8111-ffffffffffff';
copy(__DIR__ . '/fixtures/transcript_sample.jsonl', "{$transcriptDir}/{$resumeId}.jsonl");
$resumed = act(['action' => 'resume', 'workdir' => $workdir, 'agent_session_id' => $resumeId]);
assert_equal(true, $resumed['ok'] ?? null, 'a plain dashboard resume succeeds' . (($resumed['ok'] ?? false) === true ? '' : ' - got ' . json_encode($resumed)));
$made[] = (string)($resumed['name'] ?? '');
assert_equal('headless', SidecarStore::read_sidecar((string)$resumed['name'])['runtime'] ?? null, 'and is headless without asking');
assert_equal('Missing agent_session_id', act(['action' => 'resume', 'workdir' => $workdir, 'agent_session_id' => ''])['message'] ?? null, 'a resume with no conversation id is refused');
assert_true(!array_key_exists('name', act(['action' => 'take_over_bare', 'pid' => 1])), 'the dashboard\'s old take-over actions are gone: the CLI action is the only one');

test_exit();
