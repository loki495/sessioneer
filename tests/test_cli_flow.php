<?php
declare(strict_types=1);

/**
 * `bin/sessioneer --next`: the CLI over the host action flow_next, against a fake host agent that records requests.
 * Written from the Contract only. Intent notes:
 *  - request shape: pins the keys sent to the host (action flow_next; session, intent, plan_id, handoff_id, post, all),
 *    keys the user did not give are omitted;
 *  - output: any decision exits 0 and prints state, why, receiver and prompt kind (plain or --json);
 *  - target: defaults to the calling session as --whoami reads it;
 *  - sad paths: bad ids, bad intent, wrong modifiers, option clashes, host refusal and an unreachable host all exit 1
 *    with a message, and validation errors never reach the host.
 */

require __DIR__ . '/lib/cli_flow.php';

$decision = [
    'ok' => true, 'state' => 'ROTATE', 'why' => 'context is 612k tokens and the cache is cold', 'trigger' => 'context',
    'close_call' => false, 'source' => ['name' => 'cc-self', 'agent' => 'claude', 'profile' => 'personal', 'status' => 'idle'],
    'facts' => ['context_tokens' => 612000, 'idle_seconds' => 7200], 'receiver' => ['key' => 'codex/default', 'agent' => 'codex', 'profile' => 'default'],
    'prompt' => 'Take over from handoff 123.', 'prompt_kind' => 'handoff', 'needs_approval' => false,
    'reset_at' => null, 'thresholds' => [], 'warnings' => [], 'offer' => null,
];

$rig = new CliFlowRig('cli-flow');

[$code, , $err] = $rig->run(['--next', 'cc-x'], ['SESSIONEER_AGENT_SOCKET' => sys_get_temp_dir() . '/sessioneer-test-cli-flow-missing.sock']);
assert_equal(1, $code, '--next: exit 1 when the host agent is unreachable');
assert_contains('Cannot reach host agent', $err, '--next: unreachable agent is a handled message');

try {
    $rig->reply($decision);

    // Request shape and target
    [$code, $out] = $rig->run(['--next', 'cc-target']);
    $req = $rig->requests()[0] ?? [];
    assert_equal(0, $code, '--next TARGET: exit 0');
    assert_equal('flow_next', $req['action'] ?? null, '--next: calls the flow_next action');
    assert_equal('cc-target', $req['session'] ?? null, '--next TARGET: session is the target');
    foreach (['intent', 'plan_id', 'handoff_id', 'post', 'all'] as $key) {
        assert_true(!array_key_exists($key, $req), "--next: $key omitted when not given");
    }
    foreach (['ROTATE', 'context is 612k tokens and the cache is cold', 'codex/default', 'handoff'] as $needle) {
        assert_contains($needle, $out, "--next: plain output shows $needle");
    }

    $rig->reset();
    [$code] = $rig->run(['--next']);
    assert_equal(0, $code, '--next without TARGET: exit 0');
    assert_equal('cc-self', $rig->requests()[0]['session'] ?? null, '--next without TARGET: the calling session (SESSIONEER_SELF)');

    $rig->reset();
    [$code] = $rig->run(['--next'], ['SESSIONEER_SELF' => false, 'CODEX_THREAD_ID' => 'thread-9']);
    assert_equal(0, $code, '--next: a Codex session is identified by CODEX_THREAD_ID');
    assert_equal('thread-9', $rig->requests()[0]['session'] ?? null, '--next: Codex thread id is the default target');

    $rig->reset();
    [$code, $out, $err] = $rig->run(['--next'], ['SESSIONEER_SELF' => false]);
    assert_equal(1, $code, '--next with no TARGET and no calling session: exit 1');
    assert_contains('SESSIONEER_SELF', $err, '--next with no calling session: names what is missing');
    assert_equal([], $rig->requests(), '--next with no calling session: no host call');

    // Modifiers
    $rig->reset();
    [$code] = $rig->run(['--next', 'cc-target', '--intent', 'step-end', '--handoff', '123', '--post']);
    $req = $rig->requests()[0] ?? [];
    assert_equal(0, $code, '--next with intent, handoff and post: exit 0');
    assert_equal(['flow_next', 'cc-target', 'step-end', '123', true], [$req['action'] ?? null, $req['session'] ?? null, $req['intent'] ?? null, $req['handoff_id'] ?? null, $req['post'] ?? null], '--next: intent, handoff_id (string of digits) and post true are sent');
    assert_true(!array_key_exists('plan_id', $req), '--next --handoff: no plan_id');

    foreach (['wait', 'done'] as $intent) {
        $rig->reset();
        [$code] = $rig->run(['--next', '--intent', $intent]);
        assert_equal([0, $intent], [$code, $rig->requests()[0]['intent'] ?? null], "--next --intent $intent: accepted and forwarded");
    }

    $rig->reset();
    $rig->run(['--next', '--plan', '1762']);
    $req = $rig->requests()[0] ?? [];
    assert_equal('1762', $req['plan_id'] ?? null, '--next --plan: plan_id sent as a string of digits');
    assert_true(!array_key_exists('handoff_id', $req), '--next --plan: no handoff_id');

    // --all
    $rig->reset();
    $rig->reply(['ok' => true, 'sessions' => [
        ['name' => 'cc-one', 'state' => 'GO', 'why' => 'plenty of room', 'receiver' => null, 'prompt_kind' => null],
        ['name' => 'cc-two', 'state' => 'PARK', 'why' => 'quota nearly used', 'receiver' => null, 'prompt_kind' => null],
    ]]);
    [$code, $out] = $rig->run(['--next', '--all']);
    $req = $rig->requests()[0] ?? [];
    assert_equal(0, $code, '--next --all: exit 0');
    assert_equal(['flow_next', true], [$req['action'] ?? null, $req['all'] ?? null], '--next --all: flow_next with all true');
    assert_true(!array_key_exists('session', $req), '--next --all: no session key');
    foreach (['cc-one', 'GO', 'cc-two', 'PARK'] as $needle) {
        assert_contains($needle, $out, "--next --all: lists $needle");
    }
    [$code, , $err] = $rig->run(['--next', 'cc-x', '--all']);
    assert_equal(1, $code, '--next TARGET --all: refused');
    assert_contains('--all', $err, '--next TARGET --all: message names --all');

    // --json
    $rig->reply($decision);
    [$code, $out] = $rig->run(['--next', 'cc-target', '--json']);
    $json = json_decode($out, true);
    assert_equal(0, $code, '--next --json: exit 0');
    assert_equal(['ROTATE', 'codex/default', 'handoff'], [$json['state'] ?? null, $json['receiver']['key'] ?? null, $json['prompt_kind'] ?? null], '--next --json: the decision as JSON');

    // Any decision exits 0
    foreach (['GO', 'WARM_TIGHT', 'CHECKPOINT', 'PARK', 'WAIT', 'DONE', 'UNKNOWN'] as $state) {
        $rig->reply(['ok' => true, 'state' => $state, 'why' => "because $state", 'receiver' => null, 'prompt_kind' => null]);
        [$code, $out] = $rig->run(['--next', 'cc-target']);
        assert_equal(0, $code, "--next: state $state exits 0");
        assert_contains($state, $out, "--next: state $state is printed");
    }

    // --post is forwarded and the offer is reported
    $rig->reply($decision + ['offer' => ['source' => 'hook']]);
    $rig->reset();
    [$code] = $rig->run(['--next', 'cc-target', '--post']);
    assert_equal([0, true], [$code, $rig->requests()[0]['post'] ?? null], '--next --post: forwarded');

    // Host refusal and malformed reply
    $rig->reply(['ok' => false, 'message' => 'Unknown session cc-nope']);
    [$code, $out, $err] = $rig->run(['--next', 'cc-nope']);
    assert_equal(1, $code, '--next: a host refusal exits 1');
    assert_contains('Unknown session cc-nope', $err, '--next: the refusal message goes to stderr');
    assert_equal('', $out, '--next: nothing on stdout for a refusal');

    // Validation: nothing reaches the host
    $bad = [
        'handoff not digits' => [['--next', '--handoff', '12a'], '--handoff'],
        'handoff empty' => [['--next', '--handoff', ''], '--handoff'],
        'handoff negative' => [['--next', '--handoff', '-5'], '--handoff'],
        'handoff injection' => [['--next', '--handoff', '1; rm -rf /'], '--handoff'],
        'plan not digits' => [['--next', '--plan', 'abc'], '--plan'],
        'plan and handoff' => [['--next', '--handoff', '1', '--plan', '2'], '--handoff'],
        'bad intent' => [['--next', '--intent', 'later'], '--intent'],
        'intent without next' => [['--list', '--intent', 'wait'], '--intent'],
        'post without next' => [['--list', '--post'], '--post'],
        'all without next' => [['--list', '--all'], '--all'],
        'plan without flow command' => [['--list', '--plan', '5'], '--plan'],
        'two targets' => [['--next', 'cc-a', 'cc-b'], 'one'],
        'next with list' => [['--next', '--list'], 'cannot be combined'],
        'next with kill' => [['--next', '--kill', 'cc-a'], 'cannot be combined'],
        'next with usage' => [['--next', '--usage'], 'cannot be combined'],
        'next with rotate' => [['--next', '--rotate', '--handoff', '1'], 'cannot be combined'],
        'next with offer' => [['--next', '--offer', '--clear'], 'cannot be combined'],
        'next with resume' => [['--next', '--resume', 'abc'], '--resume'],
        'next with wait' => [['--next', '--wait'], '--wait'],
    ];
    foreach ($bad as $label => [$args, $needle]) {
        $rig->reset();
        [$code, $out, $err] = $rig->run($args);
        assert_equal(1, $code, "$label: exit 1");
        assert_contains($needle, $err, "$label: message mentions $needle");
        assert_equal('', $out, "$label: nothing on stdout");
        assert_equal([], $rig->requests(), "$label: no request reached the host");
    }
} finally {
    $rig->stop();
}

echo "OK\n";
test_exit();
