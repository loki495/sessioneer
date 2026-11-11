<?php
declare(strict_types=1);

/**
 * `bin/sessioneer --rotate` and `--offer`: the CLI over the host actions flow_rotate, flow_offer and
 * flow_offer_dismiss, against a fake host agent that records requests. Written from the Contract only.
 * Intent notes:
 *  - rotate request shape: action flow_rotate, session (target or the calling session), exactly one of handoff_id,
 *    plan_id or prompt, optional agent, model, effort, profile, workdir, set_name, dry_run; absent keys omitted;
 *  - rotate output: the receiver name alone on stdout, exit 1 on refusal, never archives or kills the source
 *    (no other host action is called);
 *  - offer: flow_offer with self (the calling session) and one of handoff_id, plan_id or prompt; --clear calls
 *    flow_offer_dismiss with self;
 *  - limits: ids are digits only, prompts at most 8000 characters (8000 passes, 8001 fails), both checked before
 *    any host call.
 */

require __DIR__ . '/lib/cli_flow.php';

$rig = new CliFlowRig('cli-flow-rotate');

try {
    // --rotate
    $rig->reply(['ok' => true, 'session' => 'cc-receiver-1', 'source' => 'cc-self', 'warnings' => [], 'prompt' => 'p']);
    [$code, $out, $err] = $rig->run(['--rotate', '--handoff', '123']);
    $reqs = $rig->requests();
    $req = $reqs[0] ?? [];
    assert_equal(0, $code, '--rotate --handoff: exit 0');
    assert_equal("cc-receiver-1\n", $out, '--rotate: prints the receiver name and nothing else on stdout');
    assert_equal(1, count($reqs), '--rotate: exactly one host call, so the source is never archived or killed');
    assert_equal(['flow_rotate', 'cc-self', '123'], [$req['action'] ?? null, $req['session'] ?? null, $req['handoff_id'] ?? null], '--rotate: flow_rotate for the calling session with handoff_id');
    foreach (['plan_id', 'prompt', 'agent', 'model', 'effort', 'profile', 'workdir', 'set_name', 'dry_run'] as $key) {
        assert_true(!array_key_exists($key, $req), "--rotate: $key omitted when not given");
    }

    $rig->reset();
    $rig->run(['--rotate', 'cc-target', '--plan', '1762']);
    $req = $rig->requests()[0] ?? [];
    assert_equal(['cc-target', '1762'], [$req['session'] ?? null, $req['plan_id'] ?? null], '--rotate TARGET --plan: target session and plan_id');

    $rig->reset();
    $rig->run(['--rotate', '--prompt', 'Review the repo and continue the migration.']);
    assert_equal('Review the repo and continue the migration.', $rig->requests()[0]['prompt'] ?? null, '--rotate --prompt: prompt text sent verbatim');

    $rig->reset();
    [$code] = $rig->run(['--rotate', 'cc-target', '--handoff', '9', '--agent', 'codex', '--model', 'gpt-5', '--effort', 'high', '--profile', 'work', '--workdir', '/tmp', '--set-name', 'receiver-x']);
    $req = $rig->requests()[0] ?? [];
    assert_equal(0, $code, '--rotate with launch options: exit 0');
    assert_equal(['codex', 'gpt-5', 'high', 'work', '/tmp', 'receiver-x'], [$req['agent'] ?? null, $req['model'] ?? null, $req['effort'] ?? null, $req['profile'] ?? null, $req['workdir'] ?? null, $req['set_name'] ?? null], '--rotate: agent, model, effort, profile, workdir and set_name forwarded');
    assert_true(!array_key_exists('name', $req), '--rotate: the name key is set_name, not name');

    $rig->reset();
    $rig->reply(['ok' => true, 'dry_run' => true, 'session' => null, 'source' => 'cc-self', 'warnings' => [], 'prompt' => 'Take over from handoff 5 and continue.', 'launch' => ['agent' => 'codex', 'profile' => 'default']]);
    [$code, $out] = $rig->run(['--rotate', '--handoff', '5', '--dry-run']);
    assert_equal([0, true], [$code, $rig->requests()[0]['dry_run'] ?? null], '--rotate --dry-run: forwarded as dry_run true, exit 0');
    assert_contains('Take over from handoff 5 and continue.', $out, '--rotate --dry-run: prints the prompt from the host reply');
    assert_contains('codex', $out, '--rotate --dry-run: prints the receiver preview');

    $rig->reply(['ok' => true, 'session' => 'cc-receiver-2', 'source' => 'cc-self', 'warnings' => ['SESSIONEER_CLAIMS_CMD is not set'], 'prompt' => 'p']);
    [$code, $out, $err] = $rig->run(['--rotate', '--handoff', '5', '--json']);
    $json = json_decode($out, true);
    assert_equal([0, 'cc-receiver-2'], [$code, $json['session'] ?? null], '--rotate --json: the host reply as JSON');
    [, $out, $err] = $rig->run(['--rotate', '--handoff', '5']);
    assert_contains('SESSIONEER_CLAIMS_CMD is not set', $err, '--rotate: host warnings go to stderr');
    assert_equal("cc-receiver-2\n", $out, '--rotate: warnings never pollute stdout');

    $rig->reply(['ok' => false, 'message' => 'Session cc-self holds Dibs claims: 1764']);
    [$code, $out, $err] = $rig->run(['--rotate', '--handoff', '5']);
    assert_equal(1, $code, '--rotate: a refusal exits 1');
    assert_contains('holds Dibs claims', $err, '--rotate: refusal message on stderr');
    assert_equal('', $out, '--rotate: nothing on stdout for a refusal');

    $rig->reset();
    [$code, $out, $err] = $rig->run(['--rotate', '--handoff', '5'], ['SESSIONEER_SELF' => false]);
    assert_equal(1, $code, '--rotate with no TARGET and no calling session: exit 1');
    assert_equal([], $rig->requests(), '--rotate with no calling session: no host call');

    // --prompt limit
    $rig->reply(['ok' => true, 'session' => 'cc-r', 'warnings' => []]);
    $rig->reset();
    [$code] = $rig->run(['--rotate', '--prompt', str_repeat('a', 8000)]);
    assert_equal(0, $code, '--rotate --prompt of 8000 characters: accepted');
    assert_equal(8000, strlen((string)($rig->requests()[0]['prompt'] ?? '')), '--rotate: the full 8000 characters are sent');
    $rig->reset();
    [$code, , $err] = $rig->run(['--rotate', '--prompt', str_repeat('a', 8001)]);
    assert_equal(1, $code, '--rotate --prompt of 8001 characters: refused');
    assert_contains('8000', $err, '--rotate --prompt too long: the limit is named');
    assert_equal([], $rig->requests(), '--rotate --prompt too long: no host call');

    // --offer
    $rig->reply(['ok' => true, 'offer' => ['source' => 'agent']]);
    $rig->reset();
    [$code, $out] = $rig->run(['--offer', '--handoff', '77']);
    $req = $rig->requests()[0] ?? [];
    assert_equal(0, $code, '--offer --handoff: exit 0');
    assert_equal(['flow_offer', 'cc-self', '77'], [$req['action'] ?? null, $req['self'] ?? null, $req['handoff_id'] ?? null], '--offer: flow_offer with self (calling session) and handoff_id');
    assert_true(!array_key_exists('prompt', $req) && !array_key_exists('plan_id', $req), '--offer --handoff: no other prompt source');

    $rig->reset();
    $rig->run(['--offer', '--plan', '1762']);
    assert_equal('1762', $rig->requests()[0]['plan_id'] ?? null, '--offer --plan: plan_id sent');

    $rig->reset();
    [$code] = $rig->run(['--offer', 'Start fresh and read the migration notes.']);
    $req = $rig->requests()[0] ?? [];
    assert_equal([0, 'Start fresh and read the migration notes.'], [$code, $req['prompt'] ?? null], '--offer PROMPT: positional text is the prompt');

    $rig->reset();
    [$code, $out] = $rig->run(['--offer', '--handoff', '77', '--json']);
    assert_equal([0, true], [$code, (json_decode($out, true) ?? [])['ok'] ?? null], '--offer --json: the host reply as JSON');

    $rig->reply(['ok' => true]);
    $rig->reset();
    [$code] = $rig->run(['--offer', '--clear']);
    $req = $rig->requests()[0] ?? [];
    assert_equal([0, 'flow_offer_dismiss', 'cc-self'], [$code, $req['action'] ?? null, $req['self'] ?? null], '--offer --clear: flow_offer_dismiss with self');
    assert_equal(1, count($rig->requests()), '--offer --clear: one host call');

    $rig->reply(['ok' => false, 'message' => 'No live offer to dismiss']);
    [$code, , $err] = $rig->run(['--offer', '--clear']);
    assert_equal(1, $code, '--offer --clear: refusal exits 1');
    assert_contains('No live offer', $err, '--offer --clear: refusal message');

    $rig->reply(['ok' => false, 'message' => 'Unknown session cc-self']);
    [$code, , $err] = $rig->run(['--offer', '--handoff', '1']);
    assert_equal(1, $code, '--offer: host refusal exits 1');
    assert_contains('Unknown session', $err, '--offer: refusal message');

    $rig->reset();
    [$code] = $rig->run(['--offer', '--clear'], ['SESSIONEER_SELF' => false]);
    assert_equal(1, $code, '--offer with no calling session: exit 1');
    assert_equal([], $rig->requests(), '--offer with no calling session: no host call');

    $rig->reset();
    [$code, , $err] = $rig->run(['--offer', str_repeat('a', 8001)]);
    assert_equal(1, $code, '--offer PROMPT of 8001 characters: refused');
    assert_contains('8000', $err, '--offer PROMPT too long: the limit is named');
    assert_equal([], $rig->requests(), '--offer PROMPT too long: no host call');
    $rig->reply(['ok' => true, 'offer' => []]);
    [$code] = $rig->run(['--offer', str_repeat('a', 8000)]);
    assert_equal(0, $code, '--offer PROMPT of 8000 characters: accepted');

    // Validation: nothing reaches the host
    $bad = [
        'rotate without source' => [['--rotate'], 'one of'],
        'rotate handoff and plan' => [['--rotate', '--handoff', '1', '--plan', '2'], 'one of'],
        'rotate handoff and prompt' => [['--rotate', '--handoff', '1', '--prompt', 'x'], 'one of'],
        'rotate empty prompt' => [['--rotate', '--prompt', ''], '--prompt'],
        'rotate handoff not digits' => [['--rotate', '--handoff', 'abc'], '--handoff'],
        'rotate handoff with space' => [['--rotate', '--handoff', '1 2'], '--handoff'],
        'rotate plan not digits' => [['--rotate', '--plan', '1.5'], '--plan'],
        'rotate two targets' => [['--rotate', 'cc-a', 'cc-b', '--handoff', '1'], 'one'],
        'rotate with list' => [['--rotate', '--handoff', '1', '--list'], 'cannot be combined'],
        'rotate with send' => [['--rotate', '--handoff', '1', '--send', 'cc-a'], 'cannot be combined'],
        'rotate with offer' => [['--rotate', '--handoff', '1', '--offer'], 'cannot be combined'],
        'rotate with resume' => [['--rotate', '--handoff', '1', '--resume', 'abc'], '--resume'],
        'dry-run without rotate' => [['--list', '--dry-run'], '--dry-run'],
        'prompt option without rotate' => [['--list', '--prompt', 'x'], '--prompt'],
        'clear without offer' => [['--list', '--clear'], '--clear'],
        'offer without anything' => [['--offer'], '--offer'],
        'offer handoff and plan' => [['--offer', '--handoff', '1', '--plan', '2'], 'one of'],
        'offer handoff and prompt' => [['--offer', '--handoff', '1', 'text'], 'one of'],
        'offer handoff not digits' => [['--offer', '--handoff', 'x1'], '--handoff'],
        'offer plan not digits' => [['--offer', '--plan', '-1'], '--plan'],
        'offer clear with handoff' => [['--offer', '--clear', '--handoff', '1'], '--clear'],
        'offer clear with prompt' => [['--offer', '--clear', 'text'], '--clear'],
        'offer with next' => [['--offer', '--handoff', '1', '--next'], 'cannot be combined'],
        'offer with kill' => [['--offer', '--handoff', '1', '--kill', 'cc-a'], 'cannot be combined'],
        'offer with intent' => [['--offer', '--handoff', '1', '--intent', 'wait'], '--intent'],
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
