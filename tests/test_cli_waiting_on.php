<?php
declare(strict_types=1);

/**
 * `bin/sessioneer --waiting-on SESSION[,SESSION] [--session TARGET]`: the CLI over the host action waiting_on_set,
 * against a fake host agent that records requests. Written from the Contract only. Intent notes:
 *  - request shape: action waiting_on_set with session (the caller, or --session) and targets (a list of names);
 *    `none` sends an empty list;
 *  - caller: SESSIONEER_SELF, or CODEX_THREAD_ID in a Codex session; --session overrides it;
 *  - output: one line `waiting on: a, b` or `waiting on: none`, built from the host reply; --json prints the reply;
 *  - sad paths: unknown or self target, more than 8, bad names, clashes with other action options, no caller, host
 *    refusal and an unreachable host all exit 1 with a message on stderr and nothing on stdout; local validation
 *    errors never reach the host.
 * Assumption (not stated by the Contract): a refused unknown target is the host's reply ({ok:false, message}); a self
 * target may be refused by the CLI or by the host, so only the exit code and message are pinned for it.
 */

require __DIR__ . '/lib/cli_flow.php';

$ok = static fn (array $names): array => ['ok' => true, 'waiting_on' => $names];

$rig = new CliFlowRig('cli-waiting-on');

[$code, , $err] = $rig->run(['--waiting-on', 'cc-a'], ['SESSIONEER_SELF' => 'cc-self', 'SESSIONEER_AGENT_SOCKET' => sys_get_temp_dir() . '/sessioneer-test-cli-waiting-on-missing.sock']);
assert_equal(1, $code, '--waiting-on: exit 1 when the host agent is unreachable');
assert_contains('Cannot reach host agent', $err, '--waiting-on: unreachable agent is a handled message');

try {
    // Request shape and output
    $rig->reply($ok(['cc-a', 'cc-b']));
    [$code, $out, $err] = $rig->run(['--waiting-on', 'cc-a,cc-b']);
    $req = $rig->requests()[0] ?? [];
    assert_equal(0, $code, '--waiting-on a,b: exit 0');
    assert_equal('', $err, '--waiting-on a,b: nothing on stderr');
    assert_equal(1, count($rig->requests()), '--waiting-on: exactly one host call');
    assert_equal('waiting_on_set', $req['action'] ?? null, '--waiting-on: calls the waiting_on_set action');
    assert_equal('cc-self', $req['session'] ?? null, '--waiting-on: the session is the caller (SESSIONEER_SELF)');
    assert_equal(['cc-a', 'cc-b'], $req['targets'] ?? null, '--waiting-on a,b: targets is a list of the two names, in order');
    assert_equal("waiting on: cc-a, cc-b\n", $out, '--waiting-on: prints one line `waiting on: a, b`');

    $rig->reset();
    $rig->reply($ok(['cc-a']));
    [$code, $out] = $rig->run(['--waiting-on', 'cc-a']);
    assert_equal(['cc-a'], $rig->requests()[0]['targets'] ?? null, '--waiting-on a: one target is still a list');
    assert_equal("waiting on: cc-a\n", $out, '--waiting-on a: one name, no trailing separator');

    // The line shows what the host replied, not what was typed
    $rig->reply($ok(['cc-z']));
    [, $out] = $rig->run(['--waiting-on', 'cc-a']);
    assert_equal("waiting on: cc-z\n", $out, '--waiting-on: the printed names come from the host reply');

    // none clears
    $rig->reset();
    $rig->reply($ok([]));
    [$code, $out] = $rig->run(['--waiting-on', 'none']);
    $req = $rig->requests()[0] ?? [];
    assert_equal(0, $code, '--waiting-on none: exit 0');
    assert_equal(['waiting_on_set', 'cc-self', []], [$req['action'] ?? null, $req['session'] ?? null, $req['targets'] ?? 'missing'], '--waiting-on none: an empty targets list for the caller');
    assert_equal("waiting on: none\n", $out, '--waiting-on none: prints `waiting on: none`');

    // Spaces after commas are tolerated, repeated flag is not needed
    $rig->reset();
    $rig->reply($ok(['cc-a', 'cc-b']));
    $rig->run(['--waiting-on', 'cc-a, cc-b']);
    assert_equal(['cc-a', 'cc-b'], $rig->requests()[0]['targets'] ?? null, '--waiting-on "a, b": names are trimmed');

    // Eight targets are accepted
    $eight = ['cc-1', 'cc-2', 'cc-3', 'cc-4', 'cc-5', 'cc-6', 'cc-7', 'cc-8'];
    $rig->reset();
    $rig->reply($ok($eight));
    [$code] = $rig->run(['--waiting-on', implode(',', $eight)]);
    assert_equal([0, $eight], [$code, $rig->requests()[0]['targets'] ?? null], '--waiting-on: eight targets (the maximum) are sent');

    // Names with the allowed punctuation
    $rig->reset();
    $rig->reply($ok(['Cc.odd_name-1']));
    [$code] = $rig->run(['--waiting-on', 'Cc.odd_name-1']);
    assert_equal([0, ['Cc.odd_name-1']], [$code, $rig->requests()[0]['targets'] ?? null], '--waiting-on: dots, dashes and underscores are valid in names');

    // --session overrides the caller
    $rig->reset();
    $rig->reply($ok(['cc-a']));
    [$code, $out] = $rig->run(['--waiting-on', 'cc-a', '--session', 'cc-other']);
    $req = $rig->requests()[0] ?? [];
    assert_equal(0, $code, '--waiting-on --session: exit 0');
    assert_equal('cc-other', $req['session'] ?? null, '--waiting-on --session TARGET: the list is set on TARGET, not on the caller');
    assert_equal("waiting on: cc-a\n", $out, '--waiting-on --session: same one-line output');

    $rig->reset();
    $rig->reply($ok(['cc-a']));
    [$code] = $rig->run(['--waiting-on', 'cc-a', '--session', 'cc-other'], ['SESSIONEER_SELF' => false]);
    assert_equal([0, 'cc-other'], [$code, $rig->requests()[0]['session'] ?? null], '--waiting-on --session: works without a calling session');

    // Codex caller
    $rig->reset();
    $rig->reply($ok(['cc-a']));
    [$code] = $rig->run(['--waiting-on', 'cc-a'], ['SESSIONEER_SELF' => false, 'CODEX_THREAD_ID' => 'thread-9']);
    assert_equal([0, 'thread-9'], [$code, $rig->requests()[0]['session'] ?? null], '--waiting-on: a Codex session is identified by CODEX_THREAD_ID');

    // --json prints the reply
    $rig->reset();
    $rig->reply($ok(['cc-a', 'cc-b']) + ['extra' => 'kept']);
    [$code, $out] = $rig->run(['--waiting-on', 'cc-a,cc-b', '--json']);
    $json = json_decode($out, true);
    assert_equal(0, $code, '--waiting-on --json: exit 0');
    assert_equal([true, ['cc-a', 'cc-b'], 'kept'], [$json['ok'] ?? null, $json['waiting_on'] ?? null, $json['extra'] ?? null], '--waiting-on --json: the host reply as JSON');
    assert_true(!str_contains($out, 'waiting on:'), '--waiting-on --json: no plain line besides the JSON');

    $rig->reply($ok([]));
    [, $out] = $rig->run(['--waiting-on', 'none', '--json']);
    assert_equal([true, []], [json_decode($out, true)['ok'] ?? null, json_decode($out, true)['waiting_on'] ?? 'missing'], '--waiting-on none --json: an empty list');

    // Host refusals
    foreach ([
        'unknown target' => 'Unknown session cc-nope',
        'unknown caller' => 'Unknown session cc-self',
        'too many' => 'At most 8 sessions',
        'duplicate' => 'Duplicate target cc-a',
    ] as $label => $message) {
        $rig->reset();
        $rig->reply(['ok' => false, 'message' => $message]);
        [$code, $out, $err] = $rig->run(['--waiting-on', 'cc-nope']);
        assert_equal(1, $code, "host refusal ({$label}): exit 1");
        assert_contains($message, $err, "host refusal ({$label}): the message goes to stderr");
        assert_equal('', $out, "host refusal ({$label}): nothing on stdout");
    }
    $rig->reply(['ok' => false, 'message' => 'Unknown session cc-nope']);
    [$code, $out] = $rig->run(['--waiting-on', 'cc-nope', '--json']);
    assert_equal(1, $code, 'host refusal with --json: still exit 1');
    assert_equal(false, json_decode($out, true)['ok'] ?? null, 'host refusal with --json: the refusal is printed as JSON');

    // Self target is refused with a message
    $rig->reset();
    $rig->reply($ok(['cc-self']));
    [$code, $out, $err] = $rig->run(['--waiting-on', 'cc-self']);
    assert_equal(1, $code, 'self target: exit 1');
    assert_true($err !== '' && $out === '', 'self target: a message on stderr and nothing on stdout');
    $rig->reset();
    [$code, , $err] = $rig->run(['--waiting-on', 'cc-a,cc-self,cc-b']);
    assert_equal(1, $code, 'self target among others: exit 1');
    assert_true($err !== '', 'self target among others: a message');
    $rig->reset();
    [$code] = $rig->run(['--waiting-on', 'cc-other', '--session', 'cc-other']);
    assert_equal(1, $code, 'self target with --session: the named session cannot wait on itself');

    // Validation: nothing reaches the host
    $nine = implode(',', ['cc-1', 'cc-2', 'cc-3', 'cc-4', 'cc-5', 'cc-6', 'cc-7', 'cc-8', 'cc-9']);
    $bad = [
        'more than eight' => [['--waiting-on', $nine], '8'],
        'duplicate' => [['--waiting-on', 'cc-a,cc-a'], 'cc-a'],
        'space inside a name' => [['--waiting-on', 'cc a'], '--waiting-on'],
        'slash in name' => [['--waiting-on', 'a/b'], '--waiting-on'],
        'path traversal' => [['--waiting-on', '../x'], '--waiting-on'],
        'shell metacharacters' => [['--waiting-on', 'cc-a;rm -rf /'], '--waiting-on'],
        'non ascii name' => [['--waiting-on', 'cc-é'], '--waiting-on'],
        'empty value' => [['--waiting-on', ''], '--waiting-on'],
        'empty element' => [['--waiting-on', 'cc-a,,cc-b'], '--waiting-on'],
        'trailing comma' => [['--waiting-on', 'cc-a,'], '--waiting-on'],
        'none among names' => [['--waiting-on', 'none,cc-a'], '--waiting-on'],
        'missing value' => [['--waiting-on'], '--waiting-on'],
        'with list' => [['--waiting-on', 'cc-a', '--list'], 'cannot be combined'],
        'with kill' => [['--waiting-on', 'cc-a', '--kill', 'cc-b'], 'cannot be combined'],
        'with usage' => [['--waiting-on', 'cc-a', '--usage'], 'cannot be combined'],
        'with next' => [['--waiting-on', 'cc-a', '--next'], 'cannot be combined'],
        'with rotate' => [['--waiting-on', 'cc-a', '--rotate', '--handoff', '1'], 'cannot be combined'],
        'with set name' => [['--waiting-on', 'cc-a', '--set-name', 'x'], 'cannot be combined'],
        'with resume' => [['--waiting-on', 'cc-a', '--resume', 'abc'], '--resume'],
        'with wait' => [['--waiting-on', 'cc-a', '--wait'], '--wait'],
        'with a prompt' => [['--waiting-on', 'cc-a', 'do the thing'], '--waiting-on'],
        'with intent' => [['--waiting-on', 'cc-a', '--intent', 'wait'], '--intent'],
    ];
    foreach ($bad as $label => [$args, $needle]) {
        $rig->reset();
        $rig->reply($ok(['cc-a']));
        [$code, $out, $err] = $rig->run($args);
        assert_equal(1, $code, "$label: exit 1");
        assert_contains($needle, $err, "$label: message mentions $needle");
        assert_equal('', $out, "$label: nothing on stdout");
        assert_equal([], $rig->requests(), "$label: no request reached the host");
    }

    // No caller and no --session
    $rig->reset();
    [$code, $out, $err] = $rig->run(['--waiting-on', 'cc-a'], ['SESSIONEER_SELF' => false]);
    assert_equal(1, $code, 'no calling session and no --session: exit 1');
    assert_contains('SESSIONEER_SELF', $err, 'no calling session: names what is missing');
    assert_equal('', $out, 'no calling session: nothing on stdout');
    assert_equal([], $rig->requests(), 'no calling session: no host call');

    // --session is not a standalone option
    $rig->reset();
    [$code, , $err] = $rig->run(['--session', 'cc-x']);
    assert_equal(1, $code, '--session alone: exit 1');
    assert_true($err !== '', '--session alone: a message');
    assert_equal([], $rig->requests(), '--session alone: no host call');
} finally {
    $rig->stop();
}

echo "OK\n";
test_exit();
