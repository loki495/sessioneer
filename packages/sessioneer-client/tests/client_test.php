<?php

declare(strict_types=1);

use Loki495\SessioneerClient\Agent;
use Loki495\SessioneerClient\ExecutionFailed;
use Loki495\SessioneerClient\ExecutionRequest;
use Loki495\SessioneerClient\FailureReason;
use Loki495\SessioneerClient\SessioneerClient;

function failed_call(SessioneerClient $client, ExecutionRequest $request, string $prompt = 'label this'): ExecutionFailed
{
    try {
        $client->execute($request, $prompt);
    } catch (ExecutionFailed $e) {
        return $e;
    }

    throw new \RuntimeException('expected the call to fail');
}

/** The request shape most tests use. */
function request(array $overrides = []): ExecutionRequest
{
    return new ExecutionRequest(
        workdir: $overrides['workdir'] ?? temp_workdir(),
        agent: $overrides['agent'] ?? Agent::Claude,
        model: $overrides['model'] ?? null,
        effort: $overrides['effort'] ?? null,
        noTools: $overrides['noTools'] ?? true,
        timeoutSeconds: $overrides['timeoutSeconds'] ?? 60,
        graceSeconds: $overrides['graceSeconds'] ?? 5,
    );
}

test('a successful turn returns the answer and stops its own session', function (): void {
    [$binary, $log] = fake_turn('claude-headless-abc123', ['conversation_id' => '0f0e1d2c-aaaa-bbbb-cccc-1234567890ab']);

    $result = (new SessioneerClient($binary))->execute(request(), 'label this');

    same('keeper', $result->answer, 'answer is the envelope answer, verbatim');
    same('claude-headless-abc123', $result->session, 'the session comes from the envelope');
    same('0f0e1d2c-aaaa-bbbb-cccc-1234567890ab', $result->conversationId, 'the conversation id is carried');
    same(0, $result->exitCode, 'exit code is kept');
    check($result->cleanup !== null && $result->cleanup->ok(), 'the owned session is stopped');
    check($result->cleanup !== null && !$result->cleanup->forced, 'a normal kill needs no --force');
    check(!$result->cleanupFailed(), 'a clean cleanup is not reported as a failure');

    $calls = invocations($log);
    same(2, count($calls), 'one launch and one kill');
    contains('--agent claude --kill claude-headless-abc123', $calls[1] ?? '', 'the kill names the agent and exactly that session');
    check(!str_contains($calls[1] ?? '', '--force'), 'no --force on a normal kill');
});

test('the prompt goes to the CLI on stdin, not argv', function (): void {
    [$binary, $log] = fake_binary(<<<'PHP'
        if (in_array('--kill', $argv, true)) {
            exit(0);
        }
        fwrite(STDERR, "claude-headless-stdin\n");
        echo json_encode([
            'ok' => true,
            'answer' => trim((string) stream_get_contents(STDIN)),
            'session' => 'claude-headless-stdin',
            'conversation_id' => null,
            'agent' => 'claude',
            'model' => null,
            'workdir' => null,
            'exit_code' => 0,
            'error' => null,
        ]), "\n";
        exit(0);
        PHP);

    $result = (new SessioneerClient($binary))->execute(request(), '{"state":"a b; rm -rf /"}');

    same('{"state":"a b; rm -rf /"}', $result->answer, 'the prompt arrives intact on stdin');
    check(!str_contains(invocations($log)[0] ?? '', 'rm -rf'), 'the prompt is not in argv');
});

test('the argv carries the whole one-attempt contract for Claude', function (): void {
    [$binary, $log] = fake_turn('claude-headless-argv');
    $workdir = temp_workdir();

    (new SessioneerClient($binary))->execute(request([
        'workdir' => $workdir,
        'model' => 'sonnet',
        'effort' => 'high',
    ]), 'label this');

    same(
        "--wait --json --timeout 60 --workdir {$workdir} --agent claude --no-tools --model sonnet --effort high -",
        invocations($log)[0] ?? '',
        'argv is exactly the documented shape',
    );
});

test('Codex is asked for tools explicitly, never sent --no-tools', function (): void {
    [$binary, $log] = fake_turn('0199d0f4-6f5b-7c2e-9a1d-3c4b5a697887', ['agent' => 'codex']);

    $result = (new SessioneerClient($binary))->execute(request([
        'agent' => Agent::Codex,
        'noTools' => false,
        'model' => 'gpt-6.1-sol',
    ]), 'label this');

    same('0199d0f4-6f5b-7c2e-9a1d-3c4b5a697887', $result->session, 'a Codex thread id is a session name');
    check(!str_contains(invocations($log)[0] ?? '', '--no-tools'), 'Codex is not sent --no-tools');
    same(
        '--agent codex --kill 0199d0f4-6f5b-7c2e-9a1d-3c4b5a697887',
        invocations($log)[1] ?? '',
        'the kill names the Codex launcher, not the Claude default',
    );
});

test('Ollama needs a model and is never sent --no-tools', function (): void {
    [$binary, $log] = fake_turn('ollama-llama3-8b-headless-7f3a', ['agent' => 'ollama', 'answer' => 'ok']);

    $client = new SessioneerClient($binary);

    try {
        $client->execute(request(['agent' => Agent::Ollama, 'noTools' => false]), 'label this');
        check(false, 'a model is required for Ollama');
    } catch (\InvalidArgumentException $e) {
        contains('requires a model', $e->getMessage(), 'a model is required for Ollama');
    }

    $result = $client->execute(request([
        'agent' => Agent::Ollama,
        'model' => 'llama3:8b-instruct-q4_K_M',
        'noTools' => false,
    ]), 'label this');
    same('ok', $result->answer, 'Ollama runs with its model');
    same(
        '--agent ollama --kill ollama-llama3-8b-headless-7f3a',
        invocations($log)[1] ?? '',
        'the kill names the Ollama launcher',
    );
});

test('an option token that could be another flag or a shell fragment is refused', function (): void {
    foreach (['-x', 'a b', "a\nb", '', 'a;b', '$(id)'] as $model) {
        try {
            request(['model' => $model]);
            check(false, 'model [' . addcslashes($model, "\n") . '] is refused');
        } catch (\InvalidArgumentException $e) {
            contains('safe option token', $e->getMessage(), 'model [' . addcslashes($model, "\n") . '] is refused');
        }
    }
});

test('Claude effort levels are checked here, Codex levels by the CLI', function (): void {
    try {
        request(['effort' => 'turbo']);
        check(false, 'an unknown Claude level is refused before the call');
    } catch (\InvalidArgumentException $e) {
        contains('not a claude level', $e->getMessage(), 'an unknown Claude level is refused before the call');
    }

    check(request(['effort' => 'xhigh'])->effort === 'xhigh', 'a known Claude level passes');
    check(
        request(['agent' => Agent::Codex, 'noTools' => false, 'effort' => 'medium'])->effort === 'medium',
        'Codex levels are left to the CLI',
    );
});

test('an agent Sessioneer cannot launch is refused with the reason', function (): void {
    try {
        Agent::fromName('agy');
        check(false, 'agy is refused: Sessioneer has no launcher for it');
    } catch (\InvalidArgumentException $e) {
        contains('not launchable through Sessioneer yet', $e->getMessage(), 'agy is refused: Sessioneer has no launcher for it');
        contains('claude, codex, ollama, opencode', $e->getMessage(), 'the refusal names what is launchable');
    }
});

test('OpenCode is asked for provider/model and never sent --no-tools or --effort', function (): void {
    [$binary, $log] = fake_turn('ses_71e3daa3ffeWX46yL0WKKGf5j', ['agent' => 'opencode', 'answer' => 'ok']);

    $request = request([
        'agent' => Agent::OpenCode,
        'noTools' => false,
        'model' => 'opencode-go/gpt-6.1',
    ]);
    $result = (new SessioneerClient($binary))->execute($request, 'label this');

    same('ok', $result->answer, 'an OpenCode turn returns its answer');
    same(
        "--wait --json --timeout 60 --workdir {$request->workdir} --agent opencode --model opencode-go/gpt-6.1 -",
        invocations($log)[0] ?? '',
        'the provider/model goes through and no Claude-only option is added',
    );
    same(
        '--agent opencode --kill ses_71e3daa3ffeWX46yL0WKKGf5j',
        invocations($log)[1] ?? '',
        'the kill names the OpenCode launcher',
    );

    try {
        request(['agent' => Agent::OpenCode, 'noTools' => false, 'effort' => 'high']);
        check(false, 'OpenCode does not accept --effort');
    } catch (\InvalidArgumentException $e) {
        contains('does not accept --effort', $e->getMessage(), 'OpenCode does not accept --effort');
    }
});

test('a launch refusal fails without a session to clean up', function (): void {
    [$binary, $log] = fake_failure(null, 'launch_refused', 'launch refused: no quota left');

    $failure = failed_call(new SessioneerClient($binary), request());

    same(FailureReason::LaunchRefused, $failure->reason, 'the reason is a launch refusal');
    same(null, $failure->session, 'no session was named');
    same(null, $failure->cleanup, 'nothing was killed');
    contains('no quota left', $failure->getMessage(), 'the CLI message is kept');
    same(1, count(invocations($log)), 'only the launch ran');
});

test('a turn error still stops the session it created', function (): void {
    [$binary, $log] = fake_failure('claude-headless-err', 'turn_error', 'session claude-headless-err errored: boom');

    $failure = failed_call(new SessioneerClient($binary), request());

    same(FailureReason::TurnError, $failure->reason, 'the reason is a turn error');
    same('claude-headless-err', $failure->session, 'the owned session is known');
    check($failure->cleanup !== null && $failure->cleanup->ok(), 'the session is stopped');
    contains('errored: boom', $failure->getMessage(), 'the turn error is the message');
    contains('--agent claude --kill claude-headless-err', invocations($log)[1] ?? '', 'the kill targets that session');
});

test('the CLI own timeout is treated as a timeout and cleaned up', function (): void {
    [$binary] = fake_failure('claude-headless-slow', 'timeout', 'timed out after 1s waiting for claude-headless-slow');

    $failure = failed_call(new SessioneerClient($binary), request(['timeoutSeconds' => 1]));

    same(FailureReason::Timeout, $failure->reason, 'the CLI timeout is a timeout');
    check($failure->cleanup !== null && $failure->cleanup->killed, 'the session is stopped');
});

test('the outer deadline cannot bypass cleanup', function (): void {
    // The CLI is killed before it can write an envelope, so its first stderr
    // line is the only record of the session it created.
    [$binary, $log] = fake_binary(<<<'PHP'
        if (in_array('--kill', $argv, true)) {
            exit(0);
        }
        fwrite(STDERR, "claude-headless-hang\n");
        fflush(STDERR);
        sleep(30);
        exit(0);
        PHP);
    $started = microtime(true);

    $failure = failed_call(new SessioneerClient($binary), request(['timeoutSeconds' => 1, 'graceSeconds' => 1]));

    check(microtime(true) - $started < 10, 'the call gives up instead of waiting out the sleep');
    same(FailureReason::Timeout, $failure->reason, 'the outer deadline is a timeout');
    same('claude-headless-hang', $failure->session, 'the session was captured from stderr before the deadline');
    check($failure->cleanup !== null && $failure->cleanup->killed, 'the hung session is killed anyway');
    contains('--agent claude --kill claude-headless-hang', invocations($log)[1] ?? '', 'the kill names that session');
});

test('a refused kill is retried with --force and reported', function (): void {
    [$binary, $log] = fake_turn('claude-headless-busy', [], <<<'PHP'
        if (in_array('--force', $argv, true)) {
            exit(0);
        }
        fwrite(STDERR, "sessioneer: session claude-headless-busy is working\n");
        exit(1);
        PHP);

    $result = (new SessioneerClient($binary))->execute(request(), 'label this');

    same('keeper', $result->answer, 'the answer is still returned');
    check($result->cleanup !== null && $result->cleanup->killed, 'the forced kill stopped the session');
    check($result->cleanup !== null && $result->cleanup->forced, 'the report says --force was needed');
    check($result->cleanup !== null && count($result->cleanup->failures) === 1, 'the refusal is kept as evidence');
    contains('is working', $result->cleanup?->failures[0] ?? '', 'the refusal message is kept');
    contains('--agent claude --kill claude-headless-busy --force', invocations($log)[2] ?? '', 'the retry uses --force');
});

test('a cleanup that never succeeds is reported, never swallowed', function (): void {
    [$binary, $log] = fake_turn('claude-headless-stuck', [], <<<'PHP'
        fwrite(STDERR, "sessioneer: cannot reach host agent\n");
        exit(1);
        PHP);

    $result = (new SessioneerClient($binary))->execute(request(), 'label this');

    same('keeper', $result->answer, 'a cleanup failure does not throw the answer away');
    check($result->cleanupFailed(), 'the failure is visible on the result');
    check($result->cleanup !== null && !$result->cleanup->killed, 'the report says the session survived');
    check($result->cleanup !== null && count($result->cleanup->failures) === 2, 'both attempts are reported');
    contains('cannot reach host agent', $result->cleanup?->describe() ?? '', 'the cause is in the report');
    same(3, count(invocations($log)), 'launch, kill, kill --force');
});

test('a failing turn also reports a failed cleanup', function (): void {
    [$binary] = fake_binary(
        "if (in_array('--kill', \$argv, true)) {\n    exit(1);\n}\n"
        . failure_body('claude-headless-err', 'turn_error', 'session claude-headless-err errored: boom')
    );

    $failure = failed_call(new SessioneerClient($binary), request());

    check($failure->cleanup !== null && !$failure->cleanup->ok(), 'the exception carries the cleanup failure');
    contains('cleanup failed', $failure->describe(), 'describe() shows both the error and the leak');
});

test('a missing binary is reported before anything runs', function (): void {
    $missing = sys_get_temp_dir() . '/no-such-sessioneer-' . bin2hex(random_bytes(4));

    try {
        (new SessioneerClient($missing))->execute(request(), 'label this');
        check(false, 'a missing binary fails');
    } catch (ExecutionFailed $e) {
        same(FailureReason::Unavailable, $e->reason, 'the reason is unavailable');
        contains('not executable', $e->getMessage(), 'the message names the binary');
    }
});

test('invalid requests are refused before a process starts', function (): void {
    try {
        (new SessioneerClient('/nonexistent'))->execute(request(), '   ');
        check(false, 'an empty prompt is refused');
    } catch (\InvalidArgumentException $e) {
        contains('prompt is empty', $e->getMessage(), 'an empty prompt is refused');
    }

    try {
        new ExecutionRequest(workdir: '/no/such/dir');
        check(false, 'a missing workdir is refused');
    } catch (\InvalidArgumentException $e) {
        contains('not a directory', $e->getMessage(), 'a missing workdir is refused');
    }

    try {
        new ExecutionRequest(workdir: temp_workdir(), agent: Agent::Codex);
        check(false, 'noTools with Codex is refused instead of silently dropped');
    } catch (\InvalidArgumentException $e) {
        contains('cannot run with --no-tools', $e->getMessage(), 'noTools with Codex is refused instead of silently dropped');
    }

    try {
        new ExecutionRequest(workdir: temp_workdir(), timeoutSeconds: 0);
        check(false, 'a zero timeout is refused');
    } catch (\InvalidArgumentException $e) {
        contains('between 1 and 86400', $e->getMessage(), 'a zero timeout is refused');
    }
});

test('only the first stderr line can name a session', function (): void {
    same('claude-headless-abc', SessioneerClient::parseSessionName("claude-headless-abc\nmore\n"), 'a name line is a session');
    same(null, SessioneerClient::parseSessionName("sessioneer: launch refused\n"), 'an error line is not a session');
    same(null, SessioneerClient::parseSessionName("timed out after 1s waiting for x\n"), 'a timeout line is not a session');
    same(null, SessioneerClient::parseSessionName(''), 'empty stderr has no session');
});

test('an error kind this client does not know is a process failure, never a guess', function (): void {
    [$binary] = fake_failure('claude-headless-new', 'a_kind_from_the_future', 'something new went wrong');

    $failure = failed_call(new SessioneerClient($binary), request());

    same(FailureReason::ProcessFailure, $failure->reason, 'an unknown kind is a process failure');
    same('something new went wrong', $failure->getMessage(), 'its message is still reported');
    same('claude-headless-new', $failure->session, 'the session is still cleaned up');
});

test('a CLI that prints no envelope is a process failure, never an empty answer', function (): void {
    // An older CLI, or one whose stdout a wrapper swallowed: the answer is
    // unknown, so this is a failure even on exit 0.
    [$binary] = fake_binary(<<<'PHP'
        if (in_array('--kill', $argv, true)) {
            exit(0);
        }
        fwrite(STDERR, "claude-headless-old\n");
        echo "keeper\n";
        exit(0);
        PHP);

    $failure = failed_call(new SessioneerClient($binary), request());

    same(FailureReason::ProcessFailure, $failure->reason, 'no envelope is a process failure');
    contains('without a --json envelope', $failure->getMessage(), 'the message names what was missing');
    same('claude-headless-old', $failure->session, 'the stderr fallback still names the session to stop');
    check($failure->cleanup !== null && $failure->cleanup->ok(), 'and it is stopped');
});

test('the envelope names the session to stop, even when stderr disagrees', function (): void {
    [$binary, $log] = fake_binary(<<<'PHP'
        if (in_array('--kill', $argv, true)) {
            exit(0);
        }
        fwrite(STDERR, "claude-headless-stale\n");
        echo json_encode([
            'ok' => true,
            'answer' => 'keeper',
            'session' => 'claude-headless-real',
            'conversation_id' => null,
            'agent' => 'claude',
            'model' => null,
            'workdir' => null,
            'exit_code' => 0,
            'error' => null,
        ]), "\n";
        exit(0);
        PHP);

    $result = (new SessioneerClient($binary))->execute(request(), 'label this');

    same('claude-headless-real', $result->session, 'the envelope is authoritative');
    contains('--kill claude-headless-real', invocations($log)[1] ?? '', 'the envelope session is the one killed');
    check(!str_contains(invocations($log)[1] ?? '', 'stale'), 'the stderr name is not touched');
});

test('a session name that is not one argv-safe word is never killed', function (): void {
    // The name becomes a --kill argument, so anything that is not a plain
    // session word is refused rather than passed through.
    [$binary, $log] = fake_binary(envelope_body('--force', ['session' => '--force']));

    $result = (new SessioneerClient($binary))->execute(request(), 'label this');

    same(null, $result->session, 'an unsafe name is not treated as a session');
    same(1, count(invocations($log)), 'nothing was killed');
    check($result->cleanup === null, 'and no cleanup is reported');
});
