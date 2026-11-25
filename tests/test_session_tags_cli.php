<?php
declare(strict_types=1);

/**
 * Session tags in `bin/sessioneer`: --tag (launch, rotate, filter), --tag-add/--tag-remove/--tag-set, the tags column of
 * --list and --peers, --whoami --json and the usage text, against a fake host agent that records requests. Written from
 * the Contract only. Intent notes:
 *  - editing: one `tags_set` request {session, mode, tags}; the target is --session or SESSIONEER_SELF; `none` clears;
 *    the printed line (`tags: a, b` or `tags: none`) comes from the host reply; --json prints the reply;
 *  - editing sad paths: no target, a prompt or another action, two edit options, a host refusal and an unreachable host
 *    exit 1 with a message on stderr; local refusals send nothing;
 *  - normalization: lowercase, `-` and `_` to a space, whitespace collapsed and trimmed, ^[a-z0-9]+( [a-z0-9]+)*$ up to
 *    32 chars, duplicates collapse keeping first order; read here from the `tag_filter` request key;
 *  - filter: --tag (repeatable, comma lists) with --list, --peers or --spawned sends `tag_filter`; any other action is
 *    refused; an invalid filter tag is refused locally; no match is empty output with exit 0;
 *  - launch and rotate: normalized `tags` array on the request, the prompt untouched;
 *  - display: --list and --peers text rows gain one trailing TSV column (tags joined by `, `, empty when none), the
 *    earlier columns unchanged; --whoami text stays the bare name, --json comes from the `launch_whoami` action.
 * Where the Contract does not say whether the CLI or the host refuses (too many tags, an invalid tag on launch or edit),
 * the fake host replies with a refusal too, so only the exit code and the message are pinned.
 */

// Nothing in this file may ever reach the real host agent: the CLI under test could turn an unknown flag into a launch.
putenv('SESSIONEER_AGENT_SOCKET=' . sys_get_temp_dir() . '/sessioneer-test-cli-session-tags-never.sock');

require __DIR__ . '/lib/cli_flow.php';

set_time_limit(180);

$ok = static fn (array $tags): array => ['ok' => true, 'tags' => $tags];
$rig = new CliFlowRig('cli-session-tags-' . getmypid());

/** @return array<string, mixed> */
$first = static fn (): array => $rig->requests()[0] ?? [];

/** The value of the first `tags` key anywhere in a request. */
function tc_find_tags(mixed $node): mixed
{
    if (!is_array($node)) {
        return null;
    }
    if (array_key_exists('tags', $node)) {
        return $node['tags'];
    }
    foreach ($node as $child) {
        $found = tc_find_tags($child);
        if ($found !== null) {
            return $found;
        }
    }

    return null;
}

echo "Unreachable host agent\n";
[$code, $out, $err] = $rig->run(['--tag-add', 'alpha'], ['SESSIONEER_SELF' => 'cc-self', 'SESSIONEER_AGENT_SOCKET' => sys_get_temp_dir() . '/sessioneer-test-cli-session-tags-missing.sock']);
assert_equal(1, $code, '--tag-add: exit 1 when the host agent is unreachable');
assert_contains('Cannot reach host agent', $err, '--tag-add: an unreachable agent is a handled message');
assert_equal('', $out, '--tag-add unreachable: nothing on stdout');

try {
    echo "--tag-add / --tag-remove / --tag-set: request and output\n";
    foreach (['--tag-add' => 'add', '--tag-remove' => 'remove', '--tag-set' => 'set'] as $flag => $mode) {
        $rig->reset();
        $rig->reply($ok(['alpha', 'beta']));
        [$code, $out, $err] = $rig->run([$flag, 'alpha,beta']);
        $req = $first();
        assert_equal(0, $code, "{$flag}: exit 0");
        assert_equal('', $err, "{$flag}: nothing on stderr");
        assert_equal(1, count($rig->requests()), "{$flag}: exactly one host call");
        assert_equal(
            ['tags_set', 'cc-self', $mode, ['alpha', 'beta']],
            [$req['action'] ?? null, $req['session'] ?? null, $req['mode'] ?? null, $req['tags'] ?? null],
            "{$flag}: tags_set for the calling session with mode {$mode} and the list"
        );
        assert_equal("tags: alpha, beta\n", $out, "{$flag}: prints `tags: a, b`");
    }

    $rig->reply($ok(['zeta']));
    [, $out] = $rig->run(['--tag-add', 'alpha']);
    assert_equal("tags: zeta\n", $out, '--tag-add: the printed tags come from the host reply, not from what was typed');

    $rig->reset();
    $rig->reply($ok([]));
    [$code, $out] = $rig->run(['--tag-set', 'none']);
    $req = $first();
    assert_equal(0, $code, '--tag-set none: exit 0');
    assert_equal(['tags_set', 'set', []], [$req['action'] ?? null, $req['mode'] ?? null, $req['tags'] ?? 'missing'], '--tag-set none: mode set with an empty list');
    assert_equal("tags: none\n", $out, '--tag-set none: prints `tags: none`');

    $rig->reset();
    $rig->reply($ok([]));
    [$code, $out] = $rig->run(['--tag-remove', 'not there']);
    assert_equal([0, "tags: none\n"], [$code, $out], '--tag-remove of an absent tag: not an error, shows the reply');

    echo "Edit options normalize their list\n";
    $rig->reset();
    $rig->reply($ok(['test builder']));
    $rig->run(['--tag-add', 'Test_Builder, Project-Sessioneer ,test builder']);
    assert_equal(['test builder', 'project sessioneer'], $first()['tags'] ?? null, '--tag-add: normalized, trimmed after commas, duplicates collapsed in first order');
    $rig->reset();
    $rig->run(['--tag-remove', 'HANDOFF__RECEIVER']);
    assert_equal(['handoff receiver'], $first()['tags'] ?? null, '--tag-remove: normalized the same way');
    $eight = ['t1', 't2', 't3', 't4', 't5', 't6', 't7', 't8'];
    $rig->reset();
    $rig->reply($ok($eight));
    [$code, $out] = $rig->run(['--tag-set', implode(',', $eight)]);
    assert_equal([0, $eight], [$code, $first()['tags'] ?? null], '--tag-set: eight tags (the maximum) are sent');
    assert_equal('tags: ' . implode(', ', $eight) . "\n", $out, '--tag-set: all eight printed');

    $rig->reset();
    [$code] = $rig->run(['--tag-set', implode(',', $eight) . ',T1,,T2']);
    assert_equal([0, $eight], [$code, $first()['tags'] ?? null], 'limit counts normalized unique tags, not raw elements');
    foreach (['--tag-add', '--tag-remove', '--tag-set'] as $flag) {
        $rig->reset();
        [$code, $out, $err] = $rig->run([$flag, ', ,']);
        assert_true($code === 1 && $out === '' && trim($err) !== '', "{$flag}: empty edit refused");
        assert_equal([], $rig->requests(), "{$flag}: empty edit never reaches host");
        $rig->reset();
        $rig->run([$flag, ',alpha,, beta,']);
        assert_equal(['alpha', 'beta'], $first()['tags'] ?? null, "{$flag}: empty elements ignored");
    }
    foreach ([['--tag-add', 'none'], ['--tag-remove', 'none'], ['--tag-set', 'none,alpha'], ['--tag', 'none', '--list'], ['--tag', 'none', '--workdir', '/tmp', 'go']] as $args) {
        $rig->reset();
        $rig->reply(['ok' => false, 'error' => 'invalid tag: none', 'message' => 'invalid tag: none']);
        [$code, , $err] = $rig->run($args);
        assert_equal(1, $code, 'reserved none rejected: ' . implode(' ', $args));
        assert_contains('invalid tag: none', $err, 'reserved none identifies the invalid tag');
    }
    $rig->reply($ok(['a']));
    foreach (['--tag-add', '--tag-remove', '--tag-set'] as $flag) {
        foreach ([[], ['--session', 'cc-other']] as $target) {
            $rig->reset();
            [$code, , $err] = $rig->run([$flag, 'a', '--set-name', 'title', ...$target]);
            assert_true($code === 1 && trim($err) !== '', "{$flag} with --set-name is refused");
            assert_equal([], $rig->requests(), "{$flag} with --set-name sends nothing");
        }
    }
    foreach ([['SESSIONEER_SELF' => false, 'CODEX_THREAD_ID' => 'codex-conv'], ['SESSIONEER_SELF' => 'cc-preferred', 'CODEX_THREAD_ID' => 'codex-conv']] as $env) {
        $expected = $env['SESSIONEER_SELF'] ?: 'codex-conv';
        $rig->reset();
        [$code] = $rig->run(['--tag-add', 'a'], $env);
        assert_equal([0, $expected], [$code, $first()['session'] ?? null], 'edit resolves caller identity with SESSIONEER_SELF precedence');
        $rig->reset();
        $rig->reply(['ok' => true, 'name' => 'cc-resolved', 'conversation_id' => 'codex-conv', 'tags' => ['a']]);
        [$code, $out] = $rig->run(['--whoami', '--json'], $env);
        assert_equal([0, $expected, 'cc-resolved', 'codex-conv', ['a']], [$code, $first()['session'] ?? null, json_decode($out, true)['name'] ?? null, json_decode($out, true)['conversation_id'] ?? null, json_decode($out, true)['tags'] ?? null], 'whoami resolves caller identity');
        $rig->reply($ok(['a']));
    }

    echo "--session target\n";
    $rig->reset();
    $rig->reply($ok(['a']));
    [$code, $out] = $rig->run(['--tag-add', 'a', '--session', 'cc-other']);
    assert_equal([0, 'cc-other'], [$code, $first()['session'] ?? null], '--session TARGET: edits TARGET, not the caller');
    assert_equal("tags: a\n", $out, '--session: same output');
    $rig->reset();
    [$code] = $rig->run(['--tag-set', 'a', '--session', 'conv-1234-abcd'], ['SESSIONEER_SELF' => false]);
    assert_equal([0, 'conv-1234-abcd'], [$code, $first()['session'] ?? null], '--session CONVERSATION_ID: works outside a session, the id is passed through');

    echo "--json\n";
    $rig->reset();
    $rig->reply($ok(['a', 'b c']) + ['extra' => 'kept']);
    [$code, $out] = $rig->run(['--tag-add', 'a', '--json']);
    $json = json_decode($out, true);
    assert_equal(0, $code, '--tag-add --json: exit 0');
    assert_equal([true, ['a', 'b c'], 'kept'], [$json['ok'] ?? null, $json['tags'] ?? null, $json['extra'] ?? null], '--json: the host reply');
    assert_true(!str_contains($out, 'tags: '), '--json: no plain line beside the JSON');

    foreach (['Unknown session cc-nope', 'At most 8 tags per session'] as $message) {
        $reply = ['ok' => false, 'error' => $message, 'detail' => 'host detail'];
        $rig->reply($reply);
        [$code, $out, $err] = $rig->run(['--tag-add', 'a', '--session', 'cc-nope', '--json']);
        assert_equal(1, $code, 'JSON host refusal exits 1');
        assert_equal($reply, json_decode($out, true), 'JSON host refusal prints the complete host reply');
        assert_contains($message, $err, 'JSON host refusal reports the error on stderr');
    }

    echo "Host refusals\n";
    foreach (['Unknown session cc-nope', 'At most 8 tags per session'] as $message) {
        $rig->reset();
        $rig->reply(['ok' => false, 'error' => $message]);
        [$code, $out, $err] = $rig->run(['--tag-add', 'a', '--session', 'cc-nope']);
        assert_equal(1, $code, "refusal `{$message}`: exit 1");
        assert_contains($message, $err, "refusal `{$message}`: the host error goes to stderr");
        assert_equal('', $out, "refusal `{$message}`: nothing on stdout");
    }
    [$code] = $rig->run(['--tag-add', 'a', '--json']);
    assert_equal(1, $code, 'refusal with --json: still exit 1');

    echo "Too many and invalid tags on edit\n";
    $nine = 't1,t2,t3,t4,t5,t6,t7,t8,t9';
    foreach (['--tag-add', '--tag-set'] as $flag) {
        $rig->reset();
        $rig->reply(['ok' => false, 'error' => 'At most 8 tags per session']);
        [$code, $out, $err] = $rig->run([$flag, $nine]);
        assert_equal(1, $code, "{$flag} with nine tags: exit 1, never silently cut to eight");
        assert_true(trim($err) !== '' && $out === '', "{$flag} with nine tags: a message on stderr, nothing on stdout");
        foreach ($rig->requests() as $req) {
            assert_equal(9, count($req['tags'] ?? []), "{$flag} with nine tags: if sent at all, sent whole");
        }
    }
    foreach (['--tag-add', '--tag-remove', '--tag-set'] as $flag) {
        foreach (['bad!', 'a,bad!'] as $list) {
            $rig->reset();
            $rig->reply(['ok' => false, 'error' => 'invalid tag: bad!']);
            [$code, $out, $err] = $rig->run([$flag, $list]);
            assert_equal(1, $code, "{$flag} {$list}: exit 1");
            assert_contains('invalid tag: bad!', $err, "{$flag} {$list}: names the invalid tag");
            assert_equal('', $out, "{$flag} {$list}: nothing on stdout");
        }
    }

    echo "Edit options: local refusals\n";
    $outside = ['SESSIONEER_SELF' => false];
    foreach (['--tag-add', '--tag-remove', '--tag-set'] as $flag) {
        $rig->reset();
        $rig->reply($ok(['a']));
        [$code, $out, $err] = $rig->run([$flag, 'a'], $outside);
        assert_equal(1, $code, "{$flag} outside a session with no --session: exit 1");
        assert_true(trim($err) !== '' && $out === '', "{$flag} outside a session: message on stderr only");
        assert_equal([], $rig->requests(), "{$flag} outside a session: nothing sent");

        $rig->reset();
        [$code, , $err] = $rig->run([$flag]);
        assert_equal(1, $code, "{$flag} with no value: exit 1");
        assert_true(trim($err) !== '', "{$flag} with no value: a message");
        assert_equal([], $rig->requests(), "{$flag} with no value: nothing sent");
    }
    foreach ([
        'a launch prompt' => ['--tag-add', 'a', 'do the thing'],
        'a new session' => ['--tag-add', 'a', '--workdir', '/tmp', 'do the thing'],
        'a resume' => ['--tag-set', 'a', '--resume', 'conv-1'],
        'add and remove' => ['--tag-add', 'a', '--tag-remove', 'b'],
        'add and set' => ['--tag-add', 'a', '--tag-set', 'b'],
        'remove and set none' => ['--tag-remove', 'a', '--tag-set', 'none'],
        'with --tag' => ['--tag-add', 'a', '--tag', 'b'],
        'with --list' => ['--tag-add', 'a', '--list'],
        'with --peers' => ['--tag-set', 'a', '--peers'],
        'with --spawned' => ['--tag-add', 'a', '--spawned'],
        'with --kill' => ['--tag-add', 'a', '--kill', 'x'],
        'with --show' => ['--tag-remove', 'a', '--show', 'x'],
        'with --send' => ['--tag-set', 'a', '--send', 'x', 'hi'],
        'with --usage' => ['--tag-add', 'a', '--usage'],
        'with --whoami' => ['--tag-remove', 'a', '--whoami'],
        'with --next' => ['--tag-add', 'a', '--next'],
        'with --rotate' => ['--tag-add', 'a', '--rotate', '--handoff', '5'],
        'with --waiting-on' => ['--tag-add', 'a', '--waiting-on', 'x'],
    ] as $label => $args) {
        $rig->reset();
        $rig->reply($ok(['a']));
        [$code, $out, $err] = $rig->run($args);
        assert_equal(1, $code, "tag edit, {$label}: exit 1");
        assert_true(trim($err) !== '', "tag edit, {$label}: says why");
        assert_equal('', $out, "tag edit, {$label}: nothing on stdout");
        assert_equal([], $rig->requests(), "tag edit, {$label}: nothing reached the host");
    }

    echo "--tag filter: request\n";
    foreach (['--list' => 'launch_list', '--peers' => 'launch_peers', '--spawned' => 'launch_spawned'] as $flag => $action) {
        $rig->reset();
        $rig->reply(['ok' => true, 'sessions' => [], 'peers' => []]);
        [$code, $out] = $rig->run([$flag, '--tag', 'worker']);
        $req = $first();
        assert_equal(0, $code, "{$flag} --tag with no match: exit 0");
        assert_equal('', trim($out), "{$flag} --tag with no match: empty output");
        assert_equal([$action, ['worker']], [$req['action'] ?? null, $req['tag_filter'] ?? null], "{$flag} --tag: {$action} with tag_filter");

        $rig->reset();
        $rig->run([$flag, '--tag', 'b,a', '--tag', 'B', '--tag', 'Test_Builder']);
        assert_equal(['b', 'a', 'test builder'], $first()['tag_filter'] ?? null, "{$flag}: comma lists and repeated --tag combine, normalized, duplicates collapsed in first order");

        $rig->reset();
        $rig->run([$flag]);
        assert_equal($action, $first()['action'] ?? null, "{$flag} without --tag still calls {$action}");
        assert_true(empty($first()['tag_filter']), "{$flag} without --tag: no filter");
    }
    foreach (['--list', '--peers'] as $flag) {
        $rig->reset();
        $rig->reply(['ok' => true, 'sessions' => [], 'peers' => []]);
        [$code, $out] = $rig->run([$flag, '--tag', 'worker']);
        assert_equal([0, ''], [$code, $out], "{$flag} --tag with no match: not even a blank line");
    }
    $rig->reset();
    $rig->run(['--tag=alpha', '--list']);
    assert_equal(['alpha'], $first()['tag_filter'] ?? null, '--tag=VALUE before the action works too');

    $rig->reset();
    $rig->run(['--list', '--tag', ', ,']);
    assert_true(empty($first()['tag_filter']), 'empty filter elements impose no filter');
    $rig->reset();
    [$code] = $rig->run(['--list', '--tag', 't1,t2,t3,t4,t5,t6,t7,t8,t9']);
    assert_equal([0, explode(',', 't1,t2,t3,t4,t5,t6,t7,t8,t9')], [$code, $first()['tag_filter'] ?? null], 'filters have no eight tag limit');

    echo "Normalization table (through tag_filter)\n";
    $long = str_repeat('a', 32);
    foreach ([
        'uppercase' => ['Worker', 'worker'],
        'dash' => ['Test-Builder', 'test builder'],
        'underscore' => ['test_builder', 'test builder'],
        'outer and repeated spaces' => ['  Test   Builder  ', 'test builder'],
        'tab' => ["handoff\treceiver", 'handoff receiver'],
        'mixed separators' => ['a--__ b', 'a b'],
        'leading and trailing separators' => ['-edge_', 'edge'],
        'project tag' => ['PROJECT Sessioneer', 'project sessioneer'],
        'one digit' => ['0', '0'],
        '32 characters' => [$long, $long],
        'long only before collapsing' => ['a' . str_repeat(' ', 40) . 'b', 'a b'],
        'free form' => ['sprint 42 ui', 'sprint 42 ui'],
    ] as $label => [$input, $expected]) {
        $rig->reset();
        [$code] = $rig->run(['--list', '--tag', $input]);
        assert_equal([0, [$expected]], [$code, $first()['tag_filter'] ?? null], "normalize {$label}");
    }
    foreach ([
        ['worker', 'orchestrator', 'designer', 'test builder', 'implementer', 'runner'],
        ['interpreter', 'reviewer', 'closer', 'curator', 'handoff receiver'],
    ] as $roles) {
        $rig->reset();
        $rig->run(['--list', '--tag', implode(',', $roles)]);
        assert_equal($roles, $first()['tag_filter'] ?? null, 'core roles pass unchanged: ' . implode(', ', $roles));
    }

    echo "Invalid filter tags are refused locally\n";
    foreach ([
        'punctuation' => ['bad!', 'bad!'],
        'dot' => ['a.b', 'a.b'],
        'slash' => ['a/b', 'a/b'],
        'shell metacharacters' => ['a;rm', 'a;rm'],
        'non ascii' => ['café', 'café'],
        '33 characters' => [str_repeat('a', 33), str_repeat('a', 33)],
        'only separators' => ['___', '___'],
        'bad one among good' => ['good,bad!', 'bad!'],
    ] as $label => [$input, $named]) {
        foreach (['--list', '--peers', '--spawned'] as $flag) {
            $rig->reset();
            $rig->reply(['ok' => true, 'sessions' => [], 'peers' => []]);
            [$code, , $err] = $rig->run([$flag, '--tag', $input]);
            assert_equal(1, $code, "{$flag} --tag {$label}: exit 1");
            assert_contains("invalid tag: {$named}", $err, "{$flag} --tag {$label}: error `invalid tag: <input>`");
            assert_equal([], $rig->requests(), "{$flag} --tag {$label}: nothing sent to the host");
        }
    }

    echo "--tag with any other action is refused\n";
    foreach ([
        '--show' => ['--show', 'x'],
        '--kill' => ['--kill', 'x'],
        '--send' => ['--send', 'x', 'hi'],
        '--usage' => ['--usage'],
        '--whoami' => ['--whoami'],
        '--reap' => ['--reap'],
        '--interrupt' => ['--interrupt', 'x'],
        '--answer' => ['--answer', 'x', '--option', '1'],
        '--wake-list' => ['--wake-list'],
        '--bare' => ['--bare'],
        '--waiting-on' => ['--waiting-on', 'x'],
        '--next' => ['--next'],
    ] as $label => $args) {
        $rig->reset();
        $rig->reply(['ok' => true, 'sessions' => []]);
        [$code, , $err] = $rig->run(['--tag', 'a', ...$args]);
        assert_equal(1, $code, "--tag with {$label}: exit 1");
        assert_true(trim($err) !== '', "--tag with {$label}: says why");
        assert_equal([], $rig->requests(), "--tag with {$label}: nothing reached the host");
    }

    echo "Launch\n";
    $launched = ['ok' => true, 'name' => 'new-child', 'action' => 'created', 'tags' => ['alpha']];
    $rig->reset();
    $rig->reply($launched);
    [$code] = $rig->run(['--workdir', '/tmp', '--tag', 'alpha', 'do it']);
    $req = $first();
    assert_equal(0, $code, 'launch --tag: exit 0');
    assert_equal(['launch', ['alpha'], 'do it'], [$req['action'] ?? null, $req['tags'] ?? null, $req['prompt'] ?? null], 'launch --tag: tags on the launch request, the prompt untouched');
    $rig->reset();
    $rig->run(['--tag', 'Test_Builder,project-x', '--tag', 'test builder', '--tag', 'zed', '--workdir', '/tmp', 'do it']);
    assert_equal(['test builder', 'project x', 'zed'], $first()['tags'] ?? null, 'launch: repeatable and comma separated, normalized, duplicates collapsed in first order');
    $rig->reset();
    $rig->run(['--resume', 'conv-1', '--tag', 'a,b', 'next step']);
    $req = $first();
    assert_equal(['launch', 'conv-1', ['a', 'b'], 'next step'], [$req['action'] ?? null, $req['agent_session_id'] ?? null, $req['tags'] ?? null, $req['prompt'] ?? null], 'resume --tag: tags travel with the resume');
    $rig->reset();
    $rig->run(['--resume', 'conv-1', '--tag', 'a']);
    assert_equal(['launch', ['a']], [$first()['action'] ?? null, $first()['tags'] ?? null], 'resume without a prompt carries tags too');
    $rig->reset();
    $rig->run(['--workdir', '/tmp', '--set-name', 'Receiver', '--tag', 'worker', 'do it']);
    assert_equal(['Receiver', ['worker']], [$first()['set_name'] ?? null, $first()['tags'] ?? null], 'launch: --tag combines with --set-name');
    $rig->reset();
    $rig->run(['--workdir', '/tmp', '--tag', 't1,t2,t3,t4', '--tag', 't5,t6,t7,t8', 'do it']);
    assert_equal(['t1', 't2', 't3', 't4', 't5', 't6', 't7', 't8'], $first()['tags'] ?? null, 'launch: eight tags are sent');
    $rig->reset();
    $rig->run(['--workdir', '/tmp', 'do it']);
    assert_true($first() !== [] && empty($first()['tags']), 'launch without --tag adds no tags');

    $rig->reset();
    $rig->reply(['ok' => false, 'message' => 'At most 8 tags per session', 'error' => 'At most 8 tags per session']);
    [$code, , $err] = $rig->run(['--workdir', '/tmp', '--tag', 't1,t2,t3,t4,t5', '--tag', 't6,t7,t8,t9', 'do it']);
    assert_equal(1, $code, 'launch with nine tags: exit 1');
    assert_true(trim($err) !== '', 'launch with nine tags: a message');
    foreach ($rig->requests() as $req) {
        assert_equal(9, count($req['tags'] ?? []), 'launch with nine tags: if sent at all, not silently cut');
    }
    $rig->reset();
    $rig->reply(['ok' => false, 'message' => 'invalid tag: bad!', 'error' => 'invalid tag: bad!']);
    [$code, , $err] = $rig->run(['--workdir', '/tmp', '--tag', 'bad!', 'do it']);
    assert_equal(1, $code, 'launch with an invalid tag: exit 1');
    assert_contains('invalid tag: bad!', $err, 'launch with an invalid tag: names it');

    $rig->reset();
    $rig->reply(['ok' => true, 'name' => 'existing', 'action' => 'reused', 'tags' => $eight, 'tags_error' => 'At most 8 tags per session']);
    [$code, , $err] = $rig->run(['--resume', 'existing', '--tag', 't9']);
    assert_equal(0, $code, 'merge overflow leaves launch successful');
    assert_contains('At most 8 tags per session', $err, 'merge overflow warning reaches stderr');

    echo "--rotate forwards --tag\n";
    $rig->reset();
    $rig->reply(['ok' => true, 'session' => 'cc-receiver-1', 'source' => 'cc-self', 'warnings' => [], 'prompt' => 'p']);
    [$code, $out] = $rig->run(['--rotate', '--handoff', '123', '--tag', 'Handoff_Receiver', '--tag', 'project-x']);
    $req = $first();
    assert_equal(0, $code, '--rotate --tag: exit 0');
    assert_equal("cc-receiver-1\n", $out, '--rotate --tag: still prints the receiver name only');
    assert_equal('flow_rotate', $req['action'] ?? null, '--rotate --tag: flow_rotate');
    assert_equal(['handoff receiver', 'project x'], tc_find_tags($req), '--rotate --tag: the normalized tags are forwarded under `tags`');
    $rig->reset();
    $rig->run(['--rotate', '--handoff', '123']);
    assert_true($first() !== [] && empty(tc_find_tags($first())), '--rotate without --tag forwards none');

    echo "Display: --list and --peers rows\n";
    $rows = [
        ['name' => 's1', 'status' => 'idle', 'agent_session_id' => 'c1', 'workdir' => '/tmp/p', 'branch' => 'main', 'tags' => ['worker', 'project x']],
        ['name' => 's2', 'status' => 'working', 'agent_session_id' => 'c2', 'workdir' => '/tmp/q', 'branch' => 'dev', 'tags' => []],
        ['name' => 's3', 'status' => 'idle', 'agent_session_id' => 'c3', 'workdir' => '/tmp/r', 'branch' => 'x', 'tags' => ['solo']],
    ];
    $rig->reply(['ok' => true, 'sessions' => $rows, 'peers' => $rows]);
    [$code, $out] = $rig->run(['--list']);
    assert_equal(0, $code, '--list: exit 0');
    assert_equal("s1\tidle\tc1\t/tmp/p\tworker, project x\ns2\tworking\tc2\t/tmp/q\t\ns3\tidle\tc3\t/tmp/r\tsolo\n", $out, '--list: earlier columns unchanged, one trailing tags column, empty when none');
    [$code, $out] = $rig->run(['--peers']);
    assert_equal(0, $code, '--peers: exit 0');
    assert_equal("s1\tidle\tmain\t/tmp/p\tworker, project x\ns2\tworking\tdev\t/tmp/q\t\ns3\tidle\tx\t/tmp/r\tsolo\n", $out, '--peers: earlier columns unchanged, one trailing tags column, empty when none');
    [, $out] = $rig->run(['--list', '--tag', 'worker']);
    assert_contains("s1\tidle\tc1\t/tmp/p\tworker, project x\n", $out, '--list --tag: filtered output has the same row format');
    [, $out] = $rig->run(['--list', '--json']);
    assert_equal(['worker', 'project x'], json_decode($out, true)['sessions'][0]['tags'] ?? null, '--list --json: rows keep tags');

    $rig->reply(['ok' => true, 'name' => 's1', 'status' => 'idle', 'tags' => ['worker', 'project x']]);
    [$code, $out] = $rig->run(['--show', 's1']);
    assert_equal(0, $code, 'show tagged session succeeds');
    assert_contains("tags: worker, project x\n", $out, 'show includes the tags line');
    $rig->reply(['ok' => true, 'name' => 's1', 'status' => 'idle', 'tags' => []]);
    [, $out] = $rig->run(['--show', 's1']);
    assert_true(!str_contains(strtolower($out), 'tags:'), 'show omits empty tags line');
    $rig->reply(['ok' => true, 'sessions' => $rows]);
    [$code, $taggedOutput] = $rig->run(['--spawned']);
    $untaggedRows = array_map(static function (array $row): array { $row['tags'] = []; return $row; }, $rows);
    $rig->reply(['ok' => true, 'sessions' => $untaggedRows]);
    [, $plainOutput] = $rig->run(['--spawned']);
    assert_equal(0, $code, 'spawned with tagged rows succeeds');
    assert_equal($plainOutput, $taggedOutput, 'spawned text format is unchanged by tags');

    echo "--whoami\n";
    $rig->reset();
    $rig->reply(['ok' => true, 'name' => 'cc-self', 'conversation_id' => 'conv-self', 'tags' => ['worker', 'test builder']]);
    [$code, $out] = $rig->run(['--whoami']);
    assert_equal([0, "cc-self\n"], [$code, $out], '--whoami: the text form stays the bare name');
    $rig->reset();
    [$code, $out] = $rig->run(['--whoami', '--json']);
    $json = json_decode($out, true);
    assert_equal(0, $code, '--whoami --json: exit 0');
    assert_equal(1, count($rig->requests()), '--whoami --json: one host call');
    assert_equal(['launch_whoami', 'cc-self'], [$first()['action'] ?? null, $first()['session'] ?? null], '--whoami --json: launch_whoami for the calling session');
    assert_equal(['cc-self', 'conv-self', ['worker', 'test builder']], [$json['name'] ?? null, $json['conversation_id'] ?? null, $json['tags'] ?? null], '--whoami --json: name, conversation_id and tags');
    $rig->reset();
    $rig->reply(['ok' => true, 'name' => 'cc-self', 'conversation_id' => 'conv-self', 'tags' => []]);
    [, $out] = $rig->run(['--whoami', '--json']);
    assert_equal([], json_decode($out, true)['tags'] ?? 'missing', '--whoami --json: no tags is an empty array');
    $rig->reset();
    [$code, , $err] = $rig->run(['--whoami', '--json'], ['SESSIONEER_SELF' => false]);
    assert_equal(1, $code, '--whoami --json outside a session: exit 1');
    assert_true(trim($err) !== '', '--whoami --json outside a session: a message');
    assert_equal([], $rig->requests(), '--whoami --json outside a session: nothing sent');

    echo "Usage text\n";
    $rig->reset();
    [$code, $out, $err] = $rig->run(['--help']);
    $help = $out . $err;
    assert_equal(0, $code, '--help: exit 0');
    foreach (['--tag', '--tag-add', '--tag-remove', '--tag-set'] as $flag) {
        assert_true(preg_match('/' . preg_quote($flag, '/') . '(?![-\w])/', $help) === 1, "--help lists {$flag}");
    }
} finally {
    $rig->stop();
}

test_exit();
