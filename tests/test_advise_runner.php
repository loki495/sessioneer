<?php

declare(strict_types=1);

// AdviseRunner against isolated fixtures: sidecar rows, fixture transcripts, and injected quota/model/launch.
// No tmux, no host transcripts, no real agent process, no model call.
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/../host-agent/lib/Sessions.php';

use HostAgent\Services\AdviseRunner;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SqliteDb;

$root = sys_get_temp_dir() . '/sessioneer-test-advise-runner-' . getmypid();
mkdir($root . '/home/.claude-work/projects/p', 0700, true);
foreach (['SIDECAR_DIR' => '/sidecars', 'SESSIONS_SQLITE_FILE' => '/sessions.sqlite', 'PUSH_SQLITE_FILE' => '/push.sqlite', 'HOME_ROOT' => '/home'] as $key => $path) {
    putenv($key . '=' . $root . $path);
}
putenv('CLAUDE_BIN=');
putenv('CODEX_BIN=');
putenv('OPENCODE_BIN=');
register_shutdown_function(static function () use ($root): void {
    SqliteDb::reset_connections_for_tests();
    exec('rm -rf ' . escapeshellarg($root));
});

function fixture(string $root, string $name, string $id, int $tokens, int $ageSeconds, string $status = 'idle'): void
{
    $path = $root . '/home/.claude-work/projects/p/' . $id . '.jsonl';
    file_put_contents($path, json_encode(['type' => 'assistant', 'message' => ['model' => 'm1', 'usage' => ['input_tokens' => 1, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => $tokens - 1]]]) . "\n");
    touch($path, time() - $ageSeconds);
    SidecarStore::write_sidecar($name, ['workdir' => '/w/' . $name, 'agent_session_id' => $id, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work']);
    SessionStatusStore::write_status($name, ['status' => $status]);
}

fixture($root, 'small-s', '11111111-1111-4111-8111-111111111111', 20000, 7200);
fixture($root, 'big-s', '22222222-2222-4222-8222-222222222222', 200000, 7200);
fixture($root, 'busy-s', '33333333-3333-4333-8333-333333333333', 200000, 7200, 'working');
SidecarStore::write_sidecar('44444444-4444-4444-8444-444444444444', ['workdir' => '/w/codex-s', 'agent_session_id' => '44444444-4444-4444-8444-444444444444', 'agent' => 'codex', 'runtime' => 'headless']);
SessionStatusStore::write_status('44444444-4444-4444-8444-444444444444', ['status' => 'idle', 'mode' => 'workspace-write']);
SessionStatusStore::update_status('44444444-4444-4444-8444-444444444444', ['model' => 'gpt-6', 'token_usage' => ['last' => ['totalTokens' => 200000]]]);
SidecarStore::write_sidecar('big-s', ['workdir' => '/w/big-s', 'agent_session_id' => '22222222-2222-4222-8222-222222222222', 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work']);
SidecarStore::set_tool_rules('big-s', null, 'Bash');
SessionStatusStore::write_status('big-s', ['status' => 'idle', 'mode' => 'plan']);
$ok = ['session' => ['pct' => 3], 'week_all' => ['pct' => 40]];
$deps = [
    'quota' => static fn (): array => ['agents' => [], 'claude_profiles' => ['work' => ['quota' => $ok]]],
    'model_runner' => static function (): never {
        throw new RuntimeException('no model call expected');
    },
];

$table = AdviseRunner::run([], $deps);
$byName = array_column($table['sessions'], null, 'name');
assert_equal(4, count($table['sessions']), 'the table lists tracked headless Claude and Codex sessions');
assert_equal('resume', $byName['small-s']['advice']['verdict'], 'a small session resumes');
assert_equal('fresh', $byName['big-s']['advice']['verdict'], 'a big session goes fresh');
assert_equal(true, str_contains($byName['big-s']['advice']['prompts']['fresh'], 'claude work 22222222-2222-4222-8222-222222222222'), 'the takeover prompt identifies the source agent, profile and conversation');
assert_equal(true, str_contains($byName['big-s']['advice']['prompts']['fresh'], '22222222-2222-4222-8222-222222222222.jsonl'), 'the takeover prompt points at the source transcript');
assert_equal(true, str_contains($byName['big-s']['advice']['prompts']['fresh'], 'entire source conversation'), 'the takeover prompt requires a full-thread review');
assert_equal(true, str_contains($byName['44444444-4444-4444-8444-444444444444']['advice']['prompts']['fresh'], 'codex 44444444-4444-4444-8444-444444444444'), 'the Codex prompt names its agent and conversation without a Claude profile');

assert_equal(false, AdviseRunner::run(['target' => 'nope'], $deps)['ok'], 'an unknown target is refused');
assert_equal(false, AdviseRunner::run(['act' => true], $deps)['ok'], '--act without a target is refused');
assert_equal('big-s', AdviseRunner::run(['target' => '22222222-2222-4222-8222-222222222222'], $deps)['sessions'][0]['name'], 'a conversation id selects a session too');

$calls = [];
$deps['launch'] = static function (array $r) use (&$calls): array {
    $calls[] = ['launch', $r];
    return ['ok' => true, 'name' => 'new-s', 'message' => 'ok'];
};
$deps['kill'] = static function (array $r) use (&$calls): array {
    $calls[] = ['kill', $r];
    return ['ok' => true];
};

$resume = AdviseRunner::run(['target' => 'small-s', 'act' => true], $deps);
assert_equal('resume', $resume['acted'], 'act on resume verdict resumes');
assert_equal([['launch', ['agent' => 'claude', 'workdir' => '/w/small-s', 'profile' => 'work', 'agent_session_id' => '11111111-1111-4111-8111-111111111111', 'prompt' => $calls[0][1]['prompt']]]], $calls, 'resume reuses the conversation and never kills anything');

$calls = [];
$fresh = AdviseRunner::run(['target' => 'big-s', 'act' => true, 'model' => 'sonnet'], $deps);
assert_equal(['launch'], array_column($calls, 0), 'fresh launches a receiver and leaves archival to its takeover checks');
assert_equal(false, isset($calls[0][1]['agent_session_id']), 'the fresh launch is a new conversation');
assert_equal('sonnet', $calls[0][1]['model'], 'the requested model carries to the fresh session');
assert_equal('Bash', $calls[0][1]['disallowed_tools'] ?? null, 'the fresh session keeps the old one\'s denied tools');
assert_equal('plan', $calls[0][1]['starting_mode'] ?? null, 'and its permission mode');
assert_equal(false, isset($fresh['archived']), 'the response does not claim the old session was archived');
assert_equal(true, str_contains($fresh['message'], 'old session remains available'), 'the response says the source remains available');

$calls = [];
$codexFresh = AdviseRunner::run(['target' => '44444444-4444-4444-8444-444444444444', 'act' => true], $deps);
assert_equal(true, $codexFresh['ok'], 'Codex fresh takeover launches');
assert_equal(['launch'], array_column($calls, 0), 'Codex source remains available for the receiver checks');
assert_equal(false, isset($calls[0][1]['profile']), 'Codex launch has no Claude profile');
assert_equal(false, isset($calls[0][1]['starting_mode']), 'the default Codex workspace-write mode is omitted');

$calls = [];
$deps['launch'] = static function (array $r) use (&$calls): array {
    $calls[] = ['launch', $r];
    return ['ok' => false, 'name' => 'half', 'message' => 'prompt not delivered'];
};
$failed = AdviseRunner::run(['target' => 'big-s', 'act' => true], $deps);
assert_equal(false, $failed['ok'], 'a fresh session that did not take its prompt fails the act');
assert_equal(['launch'], array_column($calls, 0), 'and the old session is NOT archived');

$calls = [];
assert_equal(false, AdviseRunner::run(['target' => 'busy-s', 'act' => true], $deps)['ok'], 'a working session is refused');
$full = $deps;
$full['quota'] = static fn (): array => ['agents' => [], 'claude_profiles' => ['work' => ['quota' => ['week_all' => ['pct' => 95, 'resets_at' => 2000000000]]]]];
assert_equal(false, AdviseRunner::run(['target' => 'small-s', 'act' => true], $full)['ok'], 'a full quota refuses to act');
assert_equal([], $calls, 'refusals launch nothing');

// A person's explicit pick (the dashboard buttons) overrides the verdict; the guards still apply.
$calls = [];
$deps['launch'] = static function (array $r) use (&$calls): array {
    $calls[] = ['launch', $r];
    return ['ok' => true, 'name' => 'new-s', 'message' => 'ok'];
};
$picked = AdviseRunner::run(['target' => 'big-s', 'act' => true, 'choice' => 'resume'], $deps);
assert_equal('resume', $picked['acted'], 'choice=resume resumes even where the verdict is fresh');
assert_equal('22222222-2222-4222-8222-222222222222', $calls[0][1]['agent_session_id'], 'and it reuses the conversation');
$calls = [];
assert_equal('fresh', AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'fresh'], $deps)['acted'], 'choice=fresh starts a new one even where the verdict is resume');
assert_equal(false, AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'bogus'], $deps)['ok'], 'an unknown choice is refused');
$calls = [];
assert_equal(false, AdviseRunner::run(['target' => 'busy-s', 'act' => true, 'choice' => 'fresh'], $deps)['ok'], 'a choice on a working session is still refused');
assert_equal(false, AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'resume'], $full)['ok'], 'a choice on a full quota is still refused');
assert_equal([], $calls, 'refused choices launch nothing');

putenv('CLAUDE_BIN=/fixture/claude');
putenv('CODEX_BIN=/fixture/codex');
$blockedQuota = ['session' => ['pct' => 90, 'resets_at' => time() + 7200], 'week_all' => ['pct' => 40]];
$quotaFixture = ['agents' => ['codex' => ['quota' => $ok]], 'claude_profiles' => ['work' => ['quota' => $blockedQuota], 'personal' => ['quota' => $ok]]];
$modelCalls = [];
$modelAnswer = 'WAIT: Sensitive debugging state is costly to transfer and this work can wait.';
$quotaDeps = [
    'quota' => static function () use (&$quotaFixture): array { return $quotaFixture; },
    'model_runner' => static function (array $candidate, string $prompt) use (&$modelCalls, &$modelAnswer): ?string {
        $modelCalls[] = [$candidate, $prompt];
        return $modelAnswer;
    },
    'launch' => $deps['launch'],
    'kill' => $deps['kill'],
];
$quotaTable = AdviseRunner::run([], $quotaDeps);
$quotaRows = array_column($quotaTable['sessions'], null, 'name');
assert_equal('fresh', $quotaRows['small-s']['advice']['verdict'], 'table: blocked small source defaults to takeover');
assert_equal(['agent' => 'claude', 'profile' => 'personal'], $quotaRows['small-s']['advice']['receiver'] ?? null, 'table: another available Claude account is preferred over Codex');
assert_equal([], $modelCalls, 'table: quota recommendations make no model call');

$calls = [];
$takeover = AdviseRunner::run(['target' => 'small-s', 'act' => true], $quotaDeps);
assert_equal(true, $takeover['ok'], 'a blocked source can start a takeover on another account');
assert_equal('fresh', $takeover['acted'] ?? null, 'blocked default act starts fresh');
assert_equal(['launch'], array_column($calls, 0), 'a blocked takeover neither resumes nor archives its source');
assert_equal('personal', $calls[0][1]['profile'] ?? null, 'the fresh launch uses the available receiving account');
assert_equal(false, isset($calls[0][1]['agent_session_id']), 'the blocked source conversation is never resumed');
$takeoverPrompt = $calls[0][1]['prompt'] ?? '';
assert_contains('/takeover', $takeoverPrompt, 'blocked launch invokes the global takeover skill');
assert_contains('claude work 11111111-1111-4111-8111-111111111111', $takeoverPrompt, 'blocked launch identifies source agent, profile and conversation');
assert_contains('old session remains available', $takeover['message'], 'takeover leaves the source available for review');
$calls = [];
assert_equal(false, AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'resume'], $quotaDeps)['ok'], 'explicit Continue is refused even when a receiver exists');
assert_equal([], $calls, 'blocked Continue never sends to the source');

$quotaFixture['claude_profiles']['work']['quota']['session']['resets_at'] = time() + 1800;
$modelCalls = [];
$shortTable = array_column(AdviseRunner::run([], $quotaDeps)['sessions'], null, 'name');
assert_equal('fresh', $shortTable['small-s']['advice']['verdict'], 'a many-session short-reset table still defaults to takeover');
assert_equal([], $modelCalls, 'a many-session short-reset table never makes a model call');
$wait = AdviseRunner::run(['target' => 'small-s'], $quotaDeps)['sessions'][0]['advice'];
assert_equal('wait', $wait['verdict'], 'short reset WAIT is a distinct wait-then-continue recommendation');
assert_equal(1, count($modelCalls), 'one named short-reset source gets one small judgment');
$judgmentPrompt = strtolower($modelCalls[0][1] ?? '');
assert_true((bool) preg_match('/urgenc|severity|urgent/', $judgmentPrompt), 'short-reset judgment considers task urgency or severity');
assert_contains('sensitiv', $judgmentPrompt, 'short-reset judgment considers sensitive context');
assert_true((bool) preg_match('/transfer|recover|context|state/', $judgmentPrompt), 'short-reset judgment considers how much source state the receiver needs');
$calls = [];
assert_equal(false, AdviseRunner::run(['target' => 'small-s', 'act' => true], $quotaDeps)['ok'], 'WAIT does not launch an immediate continuation');
assert_equal([], $calls, 'WAIT leaves all sessions untouched');
assert_equal(true, AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'fresh'], $quotaDeps)['ok'], 'explicit fresh may override a WAIT recommendation');
assert_equal('personal', $calls[0][1]['profile'] ?? null, 'explicit takeover from WAIT still uses the receiver');

foreach (['TAKEOVER: Urgent incident, the Dibs checkpoint is enough.', 'RESUME: small context', 'unparseable answer', null] as $answer) {
    $modelAnswer = $answer;
    $modelCalls = [];
    $a = AdviseRunner::run(['target' => 'small-s'], $quotaDeps)['sessions'][0]['advice'];
    assert_equal('fresh', $a['verdict'], 'short-reset takeover or failed assessment keeps a safe takeover verdict: ' . ($answer ?? 'null'));
    assert_equal(1, count($modelCalls), 'short-reset assessment is bounded to one model call: ' . ($answer ?? 'null'));
}
$modelCalls = [];
assert_equal('fresh', AdviseRunner::run(['target' => 'small-s', 'no_model' => true], $quotaDeps)['sessions'][0]['advice']['verdict'], 'no_model retains the blocked takeover default');
assert_equal([], $modelCalls, 'no_model disables the short-reset assessment');

$quotaFixture['claude_profiles']['personal']['quota'] = ['week_all' => ['pct' => 90]];
$fallback = AdviseRunner::run(['target' => 'small-s', 'no_model' => true], $quotaDeps)['sessions'][0]['advice'];
assert_equal(['agent' => 'codex', 'profile' => null], $fallback['receiver'] ?? null, 'a receiving account at 90% is excluded and a configured alternate agent is offered');
$calls = [];
$crossAgent = AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'fresh', 'no_model' => true], $quotaDeps);
assert_equal(true, $crossAgent['ok'], 'compatible default permissions allow cross-agent takeover');
assert_equal('codex', $calls[0][1]['agent'] ?? null, 'cross-agent fresh action launches the chosen alternate agent');
assert_equal(false, isset($calls[0][1]['profile']), 'alternate agent launch does not carry a Claude profile');
$calls = [];
$incompatible = AdviseRunner::run(['target' => 'big-s', 'act' => true, 'choice' => 'fresh', 'no_model' => true], $quotaDeps);
assert_equal(false, $incompatible['ok'], 'Claude plan mode and denied tools cannot be dropped for an alternate agent');
assert_equal([], $calls, 'incompatible permissions launch no receiver');

// A Codex source has no Claude profile of its own, so the preferred account still qualifies.
$codexSource = [
    'agents' => ['codex' => ['quota' => ['session' => ['pct' => 95, 'resets_at' => time() + 7200], 'week_all' => ['pct' => 10]]]],
    'claude_profiles' => ['personal' => ['quota' => $ok]],
];
$codexDeps = $quotaDeps;
$codexDeps['quota'] = static function () use (&$codexSource): array { return $codexSource; };
$codexAdvice = AdviseRunner::run(['target' => '44444444-4444-4444-8444-444444444444', 'no_model' => true], $codexDeps)['sessions'][0]['advice'];
assert_equal(['agent' => 'claude', 'profile' => 'personal'], $codexAdvice['receiver'] ?? null, 'a blocked Codex source uses the personal Claude account although it carries no profile');

unset($quotaFixture['agents']['codex']['quota']);
$calls = [];
$modelCalls = [];
$manual = AdviseRunner::run(['target' => 'small-s'], $quotaDeps)['sessions'][0]['advice'];
assert_equal(null, $manual['receiver'] ?? null, 'unknown receiver quota offers no automatic launch');
assert_contains('/takeover', $manual['prompts']['fresh'], 'no automatic receiver retains a manual takeover prompt');
assert_equal([], $modelCalls, 'no safe receiver makes no short-reset model call');
assert_equal(false, AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'fresh'], $quotaDeps)['ok'], 'fresh is refused without a receiver with known quota');
assert_equal([], $calls, 'no receiver never launches or archives the blocked source');

$quotaFixture['claude_profiles']['personal']['quota'] = $ok;
$failedReceiverDeps = $quotaDeps;
$failedReceiverDeps['launch'] = static function (array $r) use (&$calls): array {
    $calls[] = ['launch', $r];
    return ['ok' => false, 'message' => 'fixture receiver prompt refused'];
};
$failedReceiver = AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'fresh', 'no_model' => true], $failedReceiverDeps);
assert_equal(false, $failedReceiver['ok'], 'a failed receiving-account launch reports failure');
assert_equal(['launch'], array_column($calls, 0), 'a failed receiving-account launch never archives the source');

// Every launchable agent is a candidate; a source blocked on both Claude profiles and Codex reaches OpenCode.
putenv('CLAUDE_BIN=/fixture/claude');
putenv('CODEX_BIN=/fixture/codex');
putenv('OPENCODE_BIN=/fixture/opencode');
$bothBlocked = ['session' => ['pct' => 100, 'resets_at' => time() + 1800], 'week_all' => ['pct' => 55]];
$quotaFixture = [
    'agents' => [
        'codex' => ['quota' => ['session' => ['pct' => 100, 'resets_at' => time() + 7200], 'week_all' => ['pct' => 60]]],
        'opencode' => ['quota' => ['cost' => 16.3, 'tokens_input' => 40000000, 'captured_at' => '2026-11-07T19:00:00+00:00']],
        'antigravity' => ['quota' => ['gemini-weekly' => ['pct' => 0, 'resets_at' => time() + 7200]]],
    ],
    'claude_profiles' => ['work' => ['quota' => $bothBlocked], 'personal' => ['quota' => $bothBlocked]],
];
$modelCalls = [];
$openAdvice = AdviseRunner::run(['target' => 'small-s'], $quotaDeps)['sessions'][0]['advice'];
assert_equal(['agent' => 'opencode', 'profile' => null], $openAdvice['receiver'] ?? null, 'OpenCode with usage but no metered windows is a receiver');
assert_equal([], $modelCalls, 'an OpenCode receiver gets no Claude or Codex judgment call');
$calls = [];
assert_equal(true, AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'fresh', 'no_model' => true], $quotaDeps)['ok'], 'a blocked source can take over in OpenCode');
assert_equal('opencode', $calls[0][1]['agent'] ?? null, 'the launch uses the OpenCode receiver');
assert_equal(false, isset($calls[0][1]['profile']), 'the OpenCode launch carries no Claude profile');

// An agent with room that Sessioneer has no launcher for is named in the manual prompt instead.
unset($quotaFixture['agents']['opencode']);
$calls = [];
$manualOnly = AdviseRunner::run(['target' => 'small-s', 'no_model' => true], $quotaDeps)['sessions'][0]['advice'];
assert_equal('manual', $manualOnly['verdict'], 'a non-launchable agent leaves a manual verdict');
assert_equal(['Antigravity'], $manualOnly['manual_agents'] ?? null, 'the manual advice names the agent with room');
assert_contains('Antigravity', $manualOnly['reason'], 'the reason says which agent has quota');
assert_equal(null, $manualOnly['receiver'] ?? null, 'a non-launchable agent is no automatic receiver');
assert_equal(false, AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'fresh', 'no_model' => true], $quotaDeps)['ok'], 'fresh is refused for a manual-only receiver');
assert_equal([], $calls, 'a manual-only receiver launches nothing');

// A permission mode the receiver cannot reproduce rules that receiver out: OpenCode has no modes.
$quotaFixture['agents']['opencode'] = ['quota' => ['cost' => 1.0, 'captured_at' => '2026-11-07T19:00:00+00:00']];
SessionStatusStore::write_status('small-s', ['status' => 'idle', 'mode' => 'plan']);
$calls = [];
$planAdvice = AdviseRunner::run(['target' => 'small-s', 'no_model' => true], $quotaDeps)['sessions'][0]['advice'];
assert_equal(null, $planAdvice['receiver'] ?? null, 'a plan-mode source is not sent to OpenCode, which has no permission modes');
assert_equal('manual', $planAdvice['verdict'], 'no reproducible receiver leaves the manual prompt');
assert_equal(false, AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'fresh', 'no_model' => true], $quotaDeps)['ok'], 'a plan-mode source launches no OpenCode receiver');
assert_equal([], $calls, 'the plan-mode refusal launches nothing');
SessionStatusStore::write_status('small-s', ['status' => 'idle']);

// A tool-less source cannot read itself, so no receiver can transfer its work.
SidecarStore::set_no_tools('small-s');
$quotaFixture['claude_profiles']['personal']['quota'] = $ok;
$calls = [];
$toolLessBlocked = AdviseRunner::run(['target' => 'small-s', 'no_model' => true], $quotaDeps)['sessions'][0]['advice'];
assert_equal(null, $toolLessBlocked['receiver'] ?? null, 'a blocked tool-less source has no automatic receiver');
assert_equal('manual', $toolLessBlocked['verdict'], 'a blocked tool-less source keeps the manual prompt');
assert_equal(false, AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'fresh', 'no_model' => true], $quotaDeps)['ok'], 'a blocked tool-less source cannot start a receiver');
assert_equal([], $calls, 'a blocked tool-less source launches nothing');

putenv('CLAUDE_BIN=');
putenv('CODEX_BIN=');
SidecarStore::set_no_tools('small-s');
$calls = [];
$toolLess = AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'fresh'], $deps);
assert_equal(false, $toolLess['ok'], 'a tool-less replacement cannot perform the takeover');
assert_equal([], $calls, 'the tool-less refusal starts no session');
$calls = [];
assert_equal(false, AdviseRunner::run(['target' => 'small-s', 'act' => true, 'choice' => 'fresh', 'no_model' => true], $quotaDeps)['ok'], 'a blocked tool-less source cannot be replaced with a tool-less takeover receiver');
assert_equal([], $calls, 'a blocked tool-less refusal starts no receiver');

echo "ok\n";
test_exit();
