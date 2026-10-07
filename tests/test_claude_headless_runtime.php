<?php

declare(strict_types=1);

/**
 * Tests HostAgent\Runtimes\ClaudeHeadlessRuntime and
 * ClaudeHeadlessPromptProtocol: the RuntimeProvider face of headless Claude
 * sessions, driven through a scripted fake of the manager client. The manager
 * itself (real processes, real socket) is covered by
 * test_claude_headless_manager.php.
 *
 * Happy and sad paths: every rejection is asserted as its specific handled
 * message, and rejected requests are asserted NOT to have reached the manager.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';

use HostAgent\Runtimes\ClaudeHeadlessManagerClient;
use HostAgent\Runtimes\ClaudeHeadlessPromptProtocol;
use HostAgent\Runtimes\ClaudeHeadlessRuntime;
use HostAgent\Runtimes\RuntimeRegistry;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\Config;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$root = sys_get_temp_dir() . '/sessioneer-test-claude-headless-runtime-' . getmypid();
@mkdir($root . '/work', 0700, true);

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

/** @var array<int, string> $made */
$made = [];

register_shutdown_function(static function () use (&$made, $root): void {
    foreach ($made as $name) {
        SidecarStore::delete_sidecar($name);
        SessionStatusStore::delete_status($name);
    }
    exec('rm -rf ' . escapeshellarg($root));
});

class FakeManagerClient extends ClaudeHeadlessManagerClient
{
    /** @var array<int, array{method: string, params: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, array<string, mixed>> */
    public array $responses = [];

    public function __construct()
    {
    }

    public function request(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];

        return $this->responses[$method] ?? ['ok' => true];
    }

    /** @return array<int, string> */
    public function methods(): array
    {
        return array_column($this->calls, 'method');
    }
}

$tag = (string)getmypid();

function track(string $name): string
{
    global $made;
    $made[] = $name;

    return $name;
}

/** @param array<string, mixed> $over */
function session(string $root, string $name, array $over = []): string
{
    track($name);
    @mkdir($root . '/work/' . $name, 0700, true);
    SidecarStore::write_sidecar($name, array_merge([
        'workdir' => $root . '/work/' . $name, 'spawned_at' => time(), 'agent_session_id' => null,
        'spawned_by_app' => true, 'agent' => 'claude', 'runtime' => RuntimeType::HEADLESS, 'title' => null, 'profile' => null,
    ], $over));

    return $name;
}

$write = ['request_id' => 'req-1', 'tool_name' => 'Write', 'tool_input' => ['file_path' => '/tmp/a.txt', 'content' => 'x'],
    'permission_suggestions' => [['type' => 'setMode', 'mode' => 'acceptEdits', 'destination' => 'session']]];
$ask = ['request_id' => 'req-2', 'tool_name' => 'AskUserQuestion', 'requires_user_interaction' => true, 'tool_input' => ['questions' => [
    ['question' => 'Which color?', 'header' => 'Color', 'options' => [['label' => 'Red'], ['label' => 'Blue']], 'multiSelect' => false],
    ['question' => 'Toppings?', 'header' => 'Top', 'options' => [['label' => 'cheese'], ['label' => 'ham']], 'multiSelect' => true],
]]];
$plan = ['request_id' => 'req-3', 'tool_name' => 'ExitPlanMode', 'tool_input' => ['plan' => '# Plan', 'planFilePath' => '/tmp/p.md']];

// ==================================================== prompt protocol (pure)

echo "Protocol: canonical prompts\n";
$p = ClaudeHeadlessPromptProtocol::canonical_prompt($write);
assert_equal('Do you want to proceed?', $p['question'], 'a permission prompt reuses the tmux path\'s question');
assert_equal(['Yes', 'No'], array_slice(array_column($p['options'], 'label'), 0, 1) + [1 => end($p['options'])['label']], 'a permission prompt offers Yes ... No');
assert_equal(3, count($p['options']), 'a suggestion adds a middle "remember" option');
assert_equal('req-1', $p['request_id'], 'the canonical prompt carries the manager\'s request id');
assert_equal('Write', $p['tool_name'], 'and the tool name');
assert_equal(2, count(ClaudeHeadlessPromptProtocol::canonical_prompt(['tool_name' => 'Bash', 'tool_input' => ['command' => 'ls'], 'request_id' => 'r'])['options']), 'no suggestions means just Yes / No');

$q = ClaudeHeadlessPromptProtocol::canonical_prompt($ask);
assert_equal('Which color?', $q['question'], 'a question prompt shows the first question');
assert_equal([], $q['options'], 'and has no flat options (the structured form is used)');
assert_equal(true, $q['multi_question'], 'so it is rendered as the structured question form');

$pl = ClaudeHeadlessPromptProtocol::canonical_prompt($plan);
assert_equal('# Plan', $pl['context'], 'a plan prompt shows the plan text');
assert_equal(['Yes', 'No'], array_column($pl['options'], 'label'), 'and offers approve / keep planning');
assert_equal(null, ClaudeHeadlessPromptProtocol::canonical_prompt(['tool_name' => '', 'tool_input' => []]), 'a prompt with no tool name is unusable');

echo "Protocol: permission answers\n";
assert_equal(['behavior' => 'allow', 'updatedInput' => $write['tool_input']], ClaudeHeadlessPromptProtocol::response($write, ['option' => 1]), 'option 1 allows once with the original input');
$always = ClaudeHeadlessPromptProtocol::response($write, ['option' => 2]);
assert_equal('allow', $always['behavior'], 'the middle option allows');
assert_equal([$write['permission_suggestions'][0]], $always['updatedPermissions'], 'and remembers the offered permission update');
$deny = ClaudeHeadlessPromptProtocol::response($write, ['option' => 3]);
assert_equal('deny', $deny['behavior'], 'the last option denies');
assert_true(is_string($deny['message']) && $deny['message'] !== '', 'with a message Claude can read');
assert_equal('use the other file', ClaudeHeadlessPromptProtocol::response($write, ['option' => 3, 'text' => ' use the other file '])['message'], 'typed feedback becomes the deny message');
assert_equal(null, ClaudeHeadlessPromptProtocol::response($write, ['option' => 4]), 'an option past the menu is rejected');
assert_equal(null, ClaudeHeadlessPromptProtocol::response($write, ['option' => 0]), 'option 0 is rejected');
assert_equal(null, ClaudeHeadlessPromptProtocol::response($write, ['option' => 'abc']), 'a non-numeric option is rejected');
assert_equal(null, ClaudeHeadlessPromptProtocol::response($write, []), 'no answer is rejected');
$mixed = $write;
$mixed['permission_suggestions'] = [['type' => 'setMode', 'mode' => 'acceptEdits', 'destination' => 'session'], ['type' => 'addRules', 'rules' => [['toolName' => 'Write', 'ruleContent' => '/tmp/*']], 'behavior' => 'allow', 'destination' => 'session']];
assert_equal('addRules', ClaudeHeadlessPromptProtocol::response($mixed, ['option' => 2])['updatedPermissions'][0]['type'], 'the most specific suggestion wins, as in the menu the user saw');
assert_equal(['behavior' => 'allow', 'updatedInput' => $write['tool_input']], ClaudeHeadlessPromptProtocol::response($write, ['option' => '1']), 'a numeric string option works too');

echo "Protocol: question answers\n";
$one = ['request_id' => 'r', 'tool_name' => 'AskUserQuestion', 'tool_input' => ['questions' => [$ask['tool_input']['questions'][0]]]];
assert_equal(['Which color?' => 'Blue'], ClaudeHeadlessPromptProtocol::response($one, ['option' => 2])['updatedInput']['answers'], 'a lone question answered by option number becomes its label');
assert_equal(['Which color?' => 'teal'], ClaudeHeadlessPromptProtocol::response($one, ['option' => 3, 'text' => 'teal'])['updatedInput']['answers'], 'free text is passed through as the answer');
assert_equal($one['tool_input']['questions'], ClaudeHeadlessPromptProtocol::response($one, ['option' => 1])['updatedInput']['questions'], 'the original questions travel with the answers');
$multi = ClaudeHeadlessPromptProtocol::response($ask, ['answers' => [1, [1, 2]]]);
assert_equal(['Which color?' => 'Red', 'Toppings?' => ['cheese', 'ham']], $multi['updatedInput']['answers'], 'several questions: positions become labels, multi-select becomes a list');
assert_equal(['Which color?' => 'XXL', 'Toppings?' => ['ham']], ClaudeHeadlessPromptProtocol::response($ask, ['answers' => [['text' => 'XXL'], ['ham']]])['updatedInput']['answers'], 'free text and literal labels are accepted');
assert_equal(null, ClaudeHeadlessPromptProtocol::response($ask, ['answers' => [1]]), 'too few answers are rejected');
assert_equal(null, ClaudeHeadlessPromptProtocol::response($ask, ['answers' => [1, 2, 1]]), 'too many answers are rejected');
assert_equal(null, ClaudeHeadlessPromptProtocol::response($ask, ['answers' => [9, [1]]]), 'an option that does not exist is rejected');
assert_equal(null, ClaudeHeadlessPromptProtocol::response($ask, ['answers' => [[1, 2], [1]]]), 'two picks for a single-select question are rejected');
assert_equal(null, ClaudeHeadlessPromptProtocol::response($ask, ['answers' => [['text' => '  '], [1]]]), 'blank free text is rejected');
assert_equal(null, ClaudeHeadlessPromptProtocol::response(['tool_name' => 'AskUserQuestion', 'tool_input' => []], ['option' => 1]), 'a question prompt with no questions is rejected');

echo "Protocol: plan answers\n";
assert_equal('allow', ClaudeHeadlessPromptProtocol::response($plan, ['option' => 1])['behavior'], 'approving a plan allows it');
assert_equal('deny', ClaudeHeadlessPromptProtocol::response($plan, ['option' => 2])['behavior'], 'declining denies it');
assert_equal('add tests first', ClaudeHeadlessPromptProtocol::response($plan, ['option' => 2, 'text' => 'add tests first'])['message'], 'with the user\'s feedback when given');
assert_equal(null, ClaudeHeadlessPromptProtocol::response($plan, ['option' => 3]), 'a third plan option does not exist');

// ================================================================== runtime

$fake = new FakeManagerClient();
$rt = new ClaudeHeadlessRuntime($fake);

echo "Runtime: identity and registry\n";
assert_equal(RuntimeType::HEADLESS, $rt->id(), 'the runtime is headless');
assert_true($rt->isHeadless() && !$rt->isTmux(), 'and says so');
assert_true(RuntimeRegistry::runtime_for('claude', RuntimeType::HEADLESS) instanceof ClaudeHeadlessRuntime, 'the registry resolves claude+headless to it');

echo "Runtime: create\n";
$created = $rt->create(['workdir' => $root . '/work', 'model' => 'haiku', 'starting_mode' => 'plan', 'enable_task_tools' => true, 'profile' => 'work']);
track((string)($created['name'] ?? ''));
assert_true(($created['ok'] ?? false) === true, 'create succeeds when the manager starts the session');
assert_true(preg_match('/^claude-headless-\d{8}-\d{6}(-\d+)?$/', (string)$created['name']) === 1, 'the session is named claude-headless-<timestamp>');
assert_equal($created['name'], $created['id'], 'id and name are the same reference');
$sc = SidecarStore::read_sidecar($created['name']);
assert_equal('claude', $sc['agent'], 'the sidecar records the agent');
assert_equal(RuntimeType::HEADLESS, $sc['runtime'], 'and the headless runtime');
assert_equal('work', $sc['profile'], 'and the account profile');
assert_equal($root . '/work', $sc['workdir'], 'and the working directory');
assert_equal(true, $sc['spawned_by_app'], 'and that the app spawned it');
$spawn = $fake->calls[0];
assert_equal('sessioneer/spawn', $spawn['method'], 'the manager is asked to spawn');
assert_equal(['session' => $created['name'], 'fresh' => true, 'model' => 'haiku', 'starting_mode' => 'plan', 'enable_task_tools' => true], $spawn['params'], 'with the requested options');

$second = $rt->create(['workdir' => $root . '/work']);
track((string)$second['name']);
assert_true($second['name'] !== $created['name'], 'two sessions created in the same second get different names');

$before = count($fake->calls);
foreach ([['workdir' => ''], ['workdir' => 'relative/dir'], ['workdir' => $root . '/nope']] as $bad) {
    $r = $rt->create($bad);
    assert_true(($r['ok'] ?? true) === false, 'create rejects workdir ' . var_export($bad['workdir'], true));
    assert_contains('existing absolute workdir', (string)$r['message'], 'with a clear message');
}
assert_equal($before, count($fake->calls), 'and never asks the manager to start anything for them');

$fake->responses['sessioneer/spawn'] = ['ok' => false, 'message' => 'Too many live Claude sessions (8); stop one first'];
$failed = $rt->create(['workdir' => $root . '/work']);
assert_true(($failed['ok'] ?? true) === false, 'create fails when the manager refuses');
assert_equal('Too many live Claude sessions (8); stop one first', $failed['message'], 'with the manager\'s own message');
$listed = array_column(SidecarStore::list_runtime_sidecars(RuntimeType::HEADLESS), 'session_name');
$leaked = array_filter($listed, static fn (string $n): bool => !in_array($n, [$created['name'], $second['name']], true) && str_starts_with($n, 'claude-headless-') && (SidecarStore::read_sidecar($n)['workdir'] ?? '') === $root . '/work');
assert_equal([], array_values($leaked), 'a failed create leaves no sidecar behind');
$fake->responses['sessioneer/spawn'] = ['ok' => false, 'message' => 'Cannot reach Claude headless manager: No such file or directory'];
assert_contains('Cannot reach', (string)$rt->create(['workdir' => $root . '/work'])['message'], 'a manager that is down is a named, handled error');
unset($fake->responses['sessioneer/spawn']);

echo "Runtime: entries, list, detail\n";
$s1 = session($root, 'claude-headless-t' . $tag . '-1', ['agent_session_id' => 'sess-uuid-1', 'title' => null]);
SessionStatusStore::update_status($s1, ['status' => 'idle', 'mode' => 'accept edits', 'model' => 'claude-haiku-4-5-20251001', 'last_turn_error' => 'boom']);
$e = $rt->session_entry($s1);
assert_equal('claude', $e['agent'], 'the entry names the agent');
assert_equal('Claude Code', $e['agent_label'], 'with its label');
assert_equal(RuntimeType::HEADLESS, $e['runtime'], 'and the runtime');
assert_equal('idle', $e['status'], 'status comes from the manager-written store');
assert_equal(false, $e['working'], 'idle is not working');
assert_equal('accept edits', $e['current_mode'], 'the mode is Sessioneer vocabulary');
assert_equal('haiku', $e['current_model'], 'a raw model id maps to its family');
assert_equal('boom', $e['last_turn_error'], 'the last turn error is passed through');
assert_equal('sess-uuid-1', $e['agent_session_id'], 'the current Claude session id is exposed');
assert_equal(null, $e['blocked_reason'], 'nothing is blocked');
assert_equal(null, $e['pid'], 'there is no pane process');
SessionStatusStore::update_status($s1, ['model' => 'sonnet']);
assert_equal('sonnet', $rt->session_entry($s1)['current_model'], 'a picker alias is shown as is');
SessionStatusStore::update_status($s1, ['model' => 'some-unknown-model']);
assert_equal(null, $rt->session_entry($s1)['current_model'], 'an unrecognized model is unknown, not guessed');

SessionStatusStore::update_status($s1, ['status' => 'blocked', 'blocked' => $write]);
$b = $rt->session_entry($s1);
assert_equal('blocked', $b['status'], 'a blocked session says so');
assert_equal('Do you want to proceed?', $b['blocked_reason'], 'and shows the canonical question');
assert_equal(3, count($b['prompt_options']), 'and the canonical options');
assert_equal('Write', $b['prompt_tool_name'], 'and the tool');
assert_equal(null, $b['prompt_questions'], 'a plain permission has no question form');
SessionStatusStore::update_status($s1, ['status' => 'blocked', 'blocked' => $ask]);
assert_equal(2, count($rt->session_entry($s1)['prompt_questions']), 'an AskUserQuestion exposes every question for the structured form');

$s2 = session($root, 'claude-headless-t' . $tag . '-2');
session($root, 'cc-tmux-t' . $tag, ['runtime' => null]);
session($root, 'codex-headless-t' . $tag, ['agent' => 'codex']);
assert_equal(null, $rt->session_entry('cc-tmux-t' . $tag), 'a tmux session is not a headless Claude session');
assert_equal(null, $rt->session_entry('codex-headless-t' . $tag), 'nor is another agent\'s headless session');
assert_equal(null, $rt->session_entry('claude-headless-t' . $tag . '-missing'), 'nor is an unknown name');
$names = array_column($rt->list()['sessions'], 'name');
assert_true(in_array($s1, $names, true) && in_array($s2, $names, true), 'list contains this runtime\'s sessions');
assert_true(!in_array('cc-tmux-t' . $tag, $names, true) && !in_array('codex-headless-t' . $tag, $names, true), 'and only those');
$d = $rt->detail($s2);
assert_true($d['ok'] === true && $d['session']['has_transcript'] === false && $d['session']['todos'] === null, 'detail of a session with no transcript yet is handled, not an error');
assert_equal('Session not found', $rt->detail('claude-headless-t' . $tag . '-missing')['message'], 'detail of an unknown session is a handled error');

echo "Runtime: status, kill\n";
SessionStatusStore::update_status($s1, ['status' => 'blocked', 'blocked' => $write]);
$st = $rt->status($s1);
assert_true($st['ok'] && $st['status'] === 'blocked' && $st['blocked']['request_id'] === 'req-1', 'status carries the canonical blocked prompt');
SessionStatusStore::update_status($s1, ['status' => 'idle', 'blocked' => null]);
assert_equal(null, $rt->status($s1)['blocked'], 'an idle session has no blocked prompt');
assert_true($rt->status('claude-headless-t' . $tag . '-missing')['ok'] === false, 'status of an unknown session is a handled failure');

$fake->calls = [];
assert_equal('Session not found', $rt->kill('claude-headless-t' . $tag . '-missing')['message'], 'kill of an unknown session is rejected');
assert_equal([], $fake->calls, 'without asking the manager');
assert_true($rt->kill($s2)['ok'], 'kill stops the session through the manager');
assert_equal(['session' => $s2], $fake->calls[0]['params'], 'naming exactly that session');
$fake->responses['sessioneer/stop'] = ['ok' => false, 'message' => 'Something odd'];
assert_equal('Something odd', $rt->kill($s2)['message'], 'a manager failure is reported');
$fake->responses['sessioneer/stop'] = ['ok' => false, 'message' => 'Cannot reach Claude headless manager: gone'];
$down = $rt->kill($s2);
assert_true($down['ok'], 'with the manager down there is no process left, so kill still succeeds');
assert_contains('manager was not running', $down['message'], 'and says why');
unset($fake->responses['sessioneer/stop']);

echo "Runtime: send_message\n";
$fake->calls = [];
assert_equal('Session not found', $rt->send_message('claude-headless-t' . $tag . '-missing', 'hi')['message'], 'sending to an unknown session is rejected');
assert_equal('Rejected: empty message', $rt->send_message($s1, '  ')['message'], 'a blank message is rejected');
assert_equal([], $fake->calls, 'neither reaches the manager');
assert_true($rt->send_message($s1, 'hello')['ok'], 'a message is sent');
assert_equal(['session' => $s1, 'content' => 'hello'], $fake->calls[0]['params'], 'as plain text content');
$fake->responses['sessioneer/sendInput'] = ['ok' => false, 'message' => 'Spawning is disabled: nope'];
assert_equal('Spawning is disabled: nope', $rt->send_message($s1, 'hello')['message'], 'a manager refusal is reported');
unset($fake->responses['sessioneer/sendInput']);

$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
$workdir = (string)SidecarStore::read_sidecar($s1)['workdir'];
file_put_contents($workdir . '/pic.png', $png);
file_put_contents($workdir . '/notes.txt', 'n');
file_put_contents($workdir . '/huge.png', str_repeat('x', 5242881));
$fake->calls = [];
$rt->send_message($s1, 'look', ['pic.png', 'notes.txt', 'huge.png', 'missing.png', $workdir . '/pic.png']);
$content = $fake->calls[0]['params']['content'];
assert_equal('text', $content[0]['type'], 'attachments turn the content into blocks, text first');
assert_contains('look', $content[0]['text'], 'keeping the message');
foreach (['[Attached: notes.txt]', '[Attached: huge.png]', '[Attached: missing.png]'] as $mention) {
    assert_contains($mention, $content[0]['text'], "{$mention} is named by path (not an inlinable image)");
}
$images = array_values(array_filter($content, static fn (array $b): bool => $b['type'] === 'image'));
assert_equal(2, count($images), 'the readable images (relative and absolute path) are inlined');
assert_equal('image/png', $images[0]['source']['media_type'], 'with their media type');
assert_equal(base64_encode($png), $images[0]['source']['data'], 'and their bytes');
$fake->calls = [];
$rt->send_message($s1, '', ['pic.png']);
assert_equal('See the attached image.', $fake->calls[0]['params']['content'][0]['text'], 'an image with no text gets a neutral caption');

echo "Runtime: prompts\n";
$fake->calls = [];
assert_equal(null, $rt->pending_prompt('claude-headless-t' . $tag . '-missing'), 'no pending prompt for an unknown session');
assert_equal([], $fake->calls, 'and the manager is not asked');
$fake->responses['sessioneer/pendingPrompt'] = ['ok' => true, 'prompt' => null];
assert_equal(null, $rt->pending_prompt($s1), 'nothing pending is null');
assert_contains('no prompt is currently pending', $rt->answer_prompt($s1, ['option' => 1])['message'], 'answering with nothing pending is rejected');
$fake->responses['sessioneer/pendingPrompt'] = ['ok' => true, 'prompt' => $write];
assert_equal('req-1', $rt->pending_prompt($s1)['request_id'], 'a pending prompt is returned in canonical form');
$fake->calls = [];
assert_contains('does not match', $rt->answer_prompt($s1, ['option' => 9])['message'], 'an answer that does not fit is rejected');
assert_equal(['sessioneer/pendingPrompt'], $fake->methods(), 'without answering the manager');
$fake->calls = [];
assert_true($rt->answer_prompt($s1, ['option' => 1])['ok'], 'a fitting answer is delivered');
assert_equal('sessioneer/answerPrompt', $fake->calls[1]['method'], 'through answerPrompt');
assert_equal(['session' => $s1, 'request_id' => 'req-1', 'response' => ['behavior' => 'allow', 'updatedInput' => $write['tool_input']]], $fake->calls[1]['params'], 'naming the request it answers');
$fake->responses['sessioneer/answerPrompt'] = ['ok' => false, 'message' => 'Rejected: that prompt is no longer the pending one'];
assert_contains('no longer the pending one', $rt->answer_prompt($s1, ['option' => 1])['message'], 'a stale answer is reported as the manager rejects it');
unset($fake->responses['sessioneer/pendingPrompt'], $fake->responses['sessioneer/answerPrompt']);

echo "Runtime: interrupt, model, mode\n";
$fake->calls = [];
assert_true($rt->interrupt($s1)['ok'], 'interrupt is forwarded');
assert_equal('sessioneer/interrupt', $fake->calls[0]['method'], 'as an interrupt request');
$fake->responses['sessioneer/interrupt'] = ['ok' => false, 'message' => 'Session has no running Claude process'];
assert_equal('Session has no running Claude process', $rt->interrupt($s1)['message'], 'a session with nothing to interrupt is a handled error');
unset($fake->responses['sessioneer/interrupt']);

$fake->calls = [];
SessionStatusStore::update_status($s1, ['model' => 'haiku']);
assert_true($rt->update_settings($s1, 'opus')['ok'], 'a model family can be selected');
assert_equal(['session' => $s1, 'model' => 'opus'], $fake->calls[0]['params'], 'through setModel');
assert_equal('opus', SessionStatusStore::read_status($s1)['model'], 'and shows immediately');
$fake->calls = [];
foreach ([null, '', 'default', 'gpt-5'] as $badModel) {
    assert_contains('choose one of the model families', (string)$rt->update_settings($s1, $badModel)['message'], 'model ' . var_export($badModel, true) . ' is rejected');
}
assert_equal([], $fake->calls, 'and never reaches the manager');
$fake->responses['sessioneer/setModel'] = ['ok' => false, 'message' => 'Timed out waiting for Claude to answer'];
SessionStatusStore::update_status($s1, ['model' => 'haiku']);
assert_equal('Timed out waiting for Claude to answer', $rt->update_settings($s1, 'opus')['message'], 'a manager failure is reported');
assert_equal('haiku', SessionStatusStore::read_status($s1)['model'], 'and the shown model is not changed');
unset($fake->responses['sessioneer/setModel']);

$fake->calls = [];
assert_true($rt->set_mode($s1, 'plan')['ok'], 'a permission mode can be set');
assert_equal(['session' => $s1, 'mode' => 'plan'], $fake->calls[0]['params'], 'through setMode');
assert_contains('unknown permission mode', $rt->set_mode($s1, 'yolo')['message'], 'an unknown mode is rejected');
assert_equal(1, count($fake->calls), 'without reaching the manager');
$fake->responses['sessioneer/setMode'] = ['ok' => false, 'message' => 'Session has no running Claude process'];
assert_equal('Session has no running Claude process', $rt->set_mode($s1, 'plan')['message'], 'a session with no process is a handled error');

test_exit();
