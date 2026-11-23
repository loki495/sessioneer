<?php
declare(strict_types=1);

/**
 * `bin/sessioneer --next ... --plan ID --post --no-gates`: the CLI side of auto-continue (Dibs 1766), against a fake host
 * agent that records requests. Written from the Contract only. Intent notes:
 *  - request shape: --no-gates sends no_gates true and --plan sends plan_id (string of digits); both are omitted when not given;
 *  - output: when the reply says auto_continued true, plain output has one extra line `auto-continued: <receiver name>`
 *    (the reply's `session`); no such line otherwise, and --json carries the reply fields;
 *  - sad paths: --no-gates combines only with --next, so every other flow command refuses it before reaching the host.
 */

require __DIR__ . '/lib/cli_flow.php';

$decision = [
    'ok' => true, 'state' => 'ROTATE', 'why' => 'session quota is 95% used', 'trigger' => 'quota',
    'close_call' => false, 'source' => ['name' => 'cc-self', 'agent' => 'claude', 'profile' => 'personal', 'status' => 'idle'],
    'facts' => ['context_tokens' => 20000, 'idle_seconds' => 60], 'receiver' => ['key' => 'claude/work', 'agent' => 'claude', 'profile' => 'work'],
    'prompt' => 'Take over from plan 1766.', 'prompt_kind' => 'plan', 'needs_approval' => false,
    'reset_at' => null, 'thresholds' => [], 'warnings' => [], 'offer' => null,
];

$rig = new CliFlowRig('cli-flow-auto-continue');

try {
    $rig->reply($decision + ['auto_continue' => true, 'auto_continued' => true, 'session' => 'cc-receiver-7']);

    [$code, $out] = $rig->run(['--next', 'cc-target', '--plan', '1766', '--post', '--no-gates']);
    $req = $rig->requests()[0] ?? [];
    assert_equal(0, $code, '--next --no-gates: exit 0');
    assert_equal(['flow_next', 'cc-target', '1766', true, true], [$req['action'] ?? null, $req['session'] ?? null, $req['plan_id'] ?? null, $req['post'] ?? null, $req['no_gates'] ?? null], '--next --plan --post --no-gates: the request keys');
    assert_contains('auto-continued: cc-receiver-7', $out, 'started: the plain output names the receiver');
    assert_equal(1, substr_count($out, 'auto-continued:'), 'started: exactly one auto-continued line');
    assert_contains('ROTATE', $out, 'started: the decision is still printed');

    $rig->reset();
    $rig->run(['--next', '--plan', '1766', '--post', '--no-gates']);
    $req = $rig->requests()[0] ?? [];
    assert_equal('cc-self', $req['session'] ?? null, '--next --no-gates without TARGET: the calling session');
    assert_equal(true, $req['no_gates'] ?? null, '--next --no-gates without TARGET: no_gates sent');

    $rig->reset();
    $rig->run(['--next', 'cc-target', '--plan', '1766', '--post']);
    assert_true(!array_key_exists('no_gates', $rig->requests()[0] ?? []), '--next without --no-gates: no_gates omitted');

    $rig->reset();
    [$code, $out] = $rig->run(['--next', 'cc-target', '--plan', '1766', '--post', '--no-gates', '--json']);
    $json = json_decode($out, true);
    assert_equal(0, $code, '--json: exit 0');
    assert_equal([true, true, 'cc-receiver-7'], [$json['auto_continue'] ?? null, $json['auto_continued'] ?? null, $json['session'] ?? null], '--json: the auto-continue fields pass through');
    assert_true(!str_contains($out, 'auto-continued: cc-receiver-7'), '--json: no plain line in the JSON output');

    // Not started: no extra line
    $notStarted = [
        'auto_continued false' => ['auto_continue' => true, 'auto_continued' => false, 'auto_continue_note' => 'The source still holds Dibs claims'],
        'auto_continue off' => ['auto_continue' => false, 'auto_continued' => false],
        'auto_continue null' => ['auto_continue' => null, 'auto_continued' => false, 'auto_continue_note' => 'SESSIONEER_PLAN_CMD is not set'],
        'keys absent' => [],
    ];
    foreach ($notStarted as $label => $extra) {
        $rig->reply($decision + $extra);
        [$code, $out] = $rig->run(['--next', 'cc-target', '--plan', '1766', '--post', '--no-gates']);
        assert_equal(0, $code, "$label: exit 0");
        assert_true(!str_contains($out, 'auto-continued:'), "$label: no auto-continued line");
        assert_contains('ROTATE', $out, "$label: the decision is printed");
    }

    // A plain next without the new options prints no extra line even if a reply carried the keys
    $rig->reply($decision);
    [$code, $out] = $rig->run(['--next', 'cc-target']);
    assert_true($code === 0 && !str_contains($out, 'auto-continued:'), '--next plain: no auto-continued line');

    // Validation: --no-gates combines only with --next; nothing reaches the host
    $bad = [
        'no-gates without next' => [['--no-gates'], '--no-gates'],
        'no-gates with list' => [['--list', '--no-gates'], '--no-gates'],
        'no-gates with rotate' => [['--rotate', '--handoff', '1', '--no-gates'], '--no-gates'],
        'no-gates with rotate plan' => [['--rotate', '--plan', '1', '--no-gates'], '--no-gates'],
        'no-gates with offer' => [['--offer', '--plan', '1', '--no-gates'], '--no-gates'],
        'no-gates with offer clear' => [['--offer', '--clear', '--no-gates'], '--no-gates'],
        'no-gates with kill' => [['--kill', 'cc-a', '--no-gates'], '--no-gates'],
        'no-gates with usage' => [['--usage', '--no-gates'], '--no-gates'],
        'no-gates with wait' => [['--wait', '--no-gates'], '--no-gates'],
        'no-gates with a prompt launch' => [['--no-gates', 'hello'], '--no-gates'],
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
