<?php
declare(strict_types=1);

require __DIR__ . '/lib/cli_flow.php';

$rig = new CliFlowRig('cli-spawn-cleanup-' . getmypid());
try {
    foreach ([
        ['SESSIONEER_SELF' => 'tracked-parent'],
        ['SESSIONEER_SELF' => false, 'CODEX_THREAD_ID' => 'thread-parent'],
        ['SESSIONEER_SELF' => 'tracked-parent', 'CODEX_THREAD_ID' => 'thread-parent'],
    ] as $env) {
        $rig->reset();
        $rig->reply(['ok' => true, 'name' => 'new-child']);
        [$code] = $rig->run(['--workdir', '/tmp', '--json', 'fixture ordinary launch'], $env);
        assert_equal(0, $code, 'ordinary CLI creation succeeds');
        $request = $rig->requests()[0] ?? [];
        assert_equal('launch', $request['action'] ?? null, 'ordinary creation still uses launch');
        assert_equal($env['SESSIONEER_SELF'] ?: $env['CODEX_THREAD_ID'], $request['from'] ?? null, 'ordinary creation forwards the same caller source and env precedence');
    }
    foreach (['--spawned' => 'launch_spawned', '--reap' => 'launch_reap'] as $flag => $action) {
        $reply = $flag === '--spawned'
            ? ['ok' => true, 'sessions' => [['name' => 'child-1', 'agent_session_id' => 'conversation-1', 'status' => 'idle']]]
            : ['ok' => true, 'results' => [['name' => 'child-1', 'archived' => false, 'reason' => 'holds a claim']]];
        foreach ([
            ['SESSIONEER_SELF' => 'tracked-parent'],
            ['SESSIONEER_SELF' => false, 'CODEX_THREAD_ID' => 'thread-parent'],
            ['SESSIONEER_SELF' => 'tracked-parent', 'CODEX_THREAD_ID' => 'thread-parent'],
        ] as $env) {
            $rig->reset();
            $rig->reply($reply);
            [$code, $out, $err] = $rig->run([$flag, '--json'], $env);
            assert_equal(0, $code, "$flag --json succeeds");
            assert_equal($reply, json_decode($out, true), "$flag prints the host JSON envelope");
            assert_equal('', $err, "$flag JSON has no diagnostic noise");
            $request = $rig->requests()[0] ?? [];
            assert_equal($action, $request['action'] ?? null, "$flag calls its host action");
            assert_equal($env['SESSIONEER_SELF'] ?: $env['CODEX_THREAD_ID'], $request['from'] ?? null, "$flag uses normal caller env identity and precedence");
            assert_true(empty($request['force']), "$flag never requests forced archive");
        }
        $rig->reply(['ok' => false, 'message' => 'Unknown calling session']);
        [$code, $out] = $rig->run([$flag, '--json']);
        assert_equal(1, $code, "$flag host refusal exits 1");
        assert_equal(false, json_decode($out, true)['ok'] ?? null, "$flag JSON refusal remains a handled envelope");
    }
    foreach ([
        ['--spawned', '--reap'], ['--reap', '--kill', 'somebody'], ['--spawned', '--list'],
        ['--reap', '--next'], ['--reap', '--rotate'], ['--spawned', '--send', 'somebody', 'hello'],
        ['--reap', '--force'], ['--spawned', '--resume', 'conversation'],
    ] as $args) {
        $rig->reset();
        [$code, $out, $err] = $rig->run($args);
        assert_equal(1, $code, implode(' ', $args) . ': conflicting actions or unsafe modifier refused');
        assert_true(trim($err) !== '', 'refusal explains the invalid combination');
        assert_equal([], $rig->requests(), 'invalid combination never reaches host or mutates sessions');
    }
    foreach (['--spawned', '--reap'] as $flag) {
        foreach ([
            ['--agent', 'codex'], ['--model', 'x'], ['--profile', 'p'],
            ['--workdir', '/tmp'], ['--task-tools'], ['--timeout', '5'],
        ] as $modifier) {
            $rig->reset();
            [$code, $out, $err] = $rig->run([$flag, ...$modifier]);
            assert_equal(1, $code, "$flag {$modifier[0]}: modifier refused");
            assert_true(trim($err) !== '' && str_contains($err, $modifier[0]), "$flag {$modifier[0]}: stderr names the modifier");
            assert_equal([], $rig->requests(), "$flag {$modifier[0]}: refusal sends no host request");
        }
    }
    foreach (['--spawned', '--reap'] as $flag) {
        $rig->reset();
        $rig->reply(['ok' => false, 'message' => 'Missing calling session']);
        [$code, $out, $err] = $rig->run([$flag, '--json'], ['SESSIONEER_SELF' => false, 'CODEX_THREAD_ID' => false]);
        assert_equal(1, $code, "$flag refuses missing caller");
        assert_true(trim($err) !== '' || (json_decode($out, true)['ok'] ?? null) === false, 'missing caller has a handled refusal');
        foreach ($rig->requests() as $request) {
            assert_equal($flag === '--spawned' ? 'launch_spawned' : 'launch_reap', $request['action'] ?? null, 'missing identity never falls through to launch');
        }
    }
} finally {
    $rig->stop();
}
test_exit();
