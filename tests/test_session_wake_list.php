<?php

declare(strict_types=1);

/**
 * Listing, showing and cancelling scheduled wake-ups (`SessionWakeService::parse_queue`,
 * `parse_job_script`, `bin_path`, `list`, `show`, `cancel`). `at` is a stub over a fixture directory
 * (tests/lib/wake_fixture.php): no real queue is read or changed, and `removed.log` proves what a
 * cancel did or refused to do. Written from the Contract before any implementation exists.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/wake_fixture.php';

// Reading a field of a reply that failed to arrive is the failure already reported, not a second one.
set_error_handler(static fn (int $no, string $msg): bool => (bool)preg_match('/Undefined array key|offset on null|Undefined variable/', $msg));

use HostAgent\Services\SessionWakeService;
use HostAgent\Stores\SidecarStore;

/**
 * Calls a SessionWakeService method; a method that does not exist yet is one failed assertion and a
 * neutral value, not a fatal error that hides every later check.
 */
function wk(string $method, mixed ...$args): mixed
{
    try {
        return SessionWakeService::$method(...$args);
    } catch (\Error $e) {
        assert_true(false, "SessionWakeService::{$method}() works ({$e->getMessage()})");

        return match ($method) {
            'parse_queue' => [],
            'parse_job_script' => null,
            'bin_path' => '',
            default => ['ok' => false, 'message' => 'not implemented'],
        };
    }
}

$rig = new WakeRig('wake-list');
$bin = wk('bin_path');

SidecarStore::write_sidecar('claude-headless-w1', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'conv-w1', 'agent' => 'claude', 'profile' => 'work']);
SidecarStore::write_sidecar('oc-w3', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'ses_abc', 'agent' => 'opencode']);

/** The job command exactly as SessionWakeService::script() emits it. */
$script = static fn (string $agent, string $id, string $workdir, string $profile, string $prompt): string => SessionWakeService::script($agent, $id, $workdir, $profile, $prompt);
$wakeBody = static fn (string $command): string => WakeRig::body($command);
$tzNy = 'America/New_York';
$stampOf = static fn (string $local): int => wake_epoch($local, $tzNy);

try {
    // ------------------------------------------------------------------ bin_path
    echo "bin_path is the path script() embeds\n";
    assert_equal(dirname(__DIR__) . '/bin/sessioneer', $bin, 'this checkout\'s bin/sessioneer, no realpath');
    assert_contains(escapeshellarg($bin), $script('claude', 'c1', '', '', 'go'), 'script() embeds exactly bin_path(), shell-quoted');

    // ------------------------------------------------------------------ parse_queue
    echo "parse_queue\n";
    $text = implode("\n", [
        "26\tSun Nov 15 10:30:00 2026 a waketest",
        "7\tMon Nov 16 09:05:00 2026 = waketest",
        "123\tTue Nov 17 23:59:00 2026 b waketest",
        '',
    ]);
    $rows = wk('parse_queue', $text, $tzNy);
    assert_equal(3, count($rows), 'three rows parsed');
    $byJob = [];
    foreach ($rows as $row) {
        $byJob[$row['job']] = $row;
    }
    assert_equal(['job', 'at', 'queue'], array_keys($rows[0] ?? []), 'each row has exactly job, at, queue');
    assert_equal(26, $byJob[26]['job'], 'job is an int');
    assert_equal(1794756600, $byJob[26]['at'], 'at is the unix time of the date in the given zone (10:30 EST = 15:30 UTC)');
    assert_equal('a', $byJob[26]['queue'], 'queue letter a');
    assert_equal('=', $byJob[7]['queue'], 'queue letter = marks a running job');
    assert_equal('b', $byJob[123]['queue'], 'queue letter b');
    assert_equal($stampOf('2026-11-16 09:05:00'), $byJob[7]['at'], 'a one-digit job number parses');

    assert_equal([], wk('parse_queue', '', $tzNy), 'an empty listing is an empty list');
    assert_equal([], wk('parse_queue', "\n\n", $tzNy), 'blank lines only is an empty list');

    $utc = wk('parse_queue', "26\tSun Nov 15 10:30:00 2026 a waketest", 'UTC');
    assert_equal(wake_epoch('2026-11-15 10:30:00', 'UTC'), $utc[0]['at'], 'the zone argument decides the instant');
    assert_true($utc[0]['at'] !== $byJob[26]['at'], 'the same text in another zone is another instant');

    $padded = wk('parse_queue', "5\tSun Nov  1 12:00:00 2026 a waketest", $tzNy);
    assert_equal($stampOf('2026-11-01 12:00:00'), $padded[0]['at'] ?? null, 'a single-digit day padded with two spaces parses');
    $spaces = wk('parse_queue', "5   Sun Nov 15 10:30:00 2026   a   waketest", $tzNy);
    assert_equal(1794756600, $spaces[0]['at'] ?? null, 'runs of spaces between columns are accepted');

    $repeated = wk('parse_queue', "9\tSun Nov  1 01:30:00 2026 a waketest", $tzNy);
    assert_equal(wake_epoch('2026-11-01 05:30:00', 'UTC'), $repeated[0]['at'] ?? null, 'a time in the repeated DST hour is the earlier instant (EDT)');

    $mixed = wk('parse_queue', implode("\n", [
        'no at jobs',
        "x1\tSun Nov 15 10:30:00 2026 a waketest",
        "30\tgarbage in the date column a waketest",
        "31\tSun Xyz 15 10:30:00 2026 a waketest",
        "32\tSun Nov 15 10:30:00 2026 a waketest",
        '    ',
        "33\tMon Nov 16 10:30:00 2026 a waketest extra words",
    ]), $tzNy);
    assert_equal([32], array_map(static fn (array $r): int => $r['job'], $mixed), 'unparseable lines are skipped, valid ones kept');

    // ------------------------------------------------------------------ parse_job_script: round trip
    echo "parse_job_script round-trips what script() emits\n";
    $prompts = [
        'plain' => 'continue the work',
        'single and double quotes' => "it's \"quoted\" and 'single'",
        'dollar and backticks' => 'costs $HOME and ${PATH} and $(id) and `date` and $$',
        'backslashes' => 'back\\slash \\n literal \\\\ end \\',
        'newlines, CRLF and tabs' => "line1\nline2\r\n\ttabbed\n\n",
        'a leading --' => '--workdir /etc',
        'exactly --workdir' => '--workdir',
        'exactly --profile' => '--profile',
        'exactly --agent' => '--agent',
        'exactly --resume' => '--resume',
        'exactly --' => '--',
        'the shell-quote escape itself' => "a'\\''b",
        'only a quote' => "'",
        'two quotes' => "''",
        'unicode' => 'héllo 🌍 日本語',
        'whitespace only' => ' ',
        'the redirect text' => "; rm -rf ~ ' >> /tmp/x 2>&1",
        'env -i text' => "env -i 'HOME=x' y",
        'a fake command line' => "\n' >> 'x' 2>&1\nenv -i '",
        'at 30000 characters' => str_repeat("ab'\"\$`\\\n", 3750),
    ];
    $shapes = [
        'claude with workdir and profile' => ['claude', 'conv-a', '/tmp/proj', 'work', ['agent' => 'claude', 'agent_session_id' => 'conv-a', 'workdir' => '/tmp/proj', 'profile' => 'work']],
        'claude with workdir only' => ['claude', 'conv-b', '/tmp/my dir/it\'s', '', ['agent' => 'claude', 'agent_session_id' => 'conv-b', 'workdir' => '/tmp/my dir/it\'s', 'profile' => null]],
        'claude with profile only' => ['claude', 'conv-c', '', 'work', ['agent' => 'claude', 'agent_session_id' => 'conv-c', 'workdir' => null, 'profile' => 'work']],
        'opencode, nothing optional' => ['opencode', 'ses_abc', '', '', ['agent' => 'opencode', 'agent_session_id' => 'ses_abc', 'workdir' => null, 'profile' => null]],
        'opencode with a profile that script() drops' => ['opencode', 'ses_abc', '/tmp/p', 'work', ['agent' => 'opencode', 'agent_session_id' => 'ses_abc', 'workdir' => '/tmp/p', 'profile' => null]],
    ];

    foreach ([false, true] as $withSocket) {
        putenv('SESSIONEER_AGENT_SOCKET=' . ($withSocket ? '/run/user/1000/test agent.sock' : ''));

        foreach ($shapes as $shapeLabel => [$agent, $id, $workdir, $profile, $expected]) {
            foreach ($prompts as $promptLabel => $prompt) {
                $parsed = wk('parse_job_script', $wakeBody($script($agent, $id, $workdir, $profile, $prompt)), $bin);
                $label = "{$shapeLabel}" . ($withSocket ? ' + socket' : '') . ", prompt: {$promptLabel}";

                if (!is_array($parsed)) {
                    assert_true(false, "{$label}: recognised as a Sessioneer wake job");
                    continue;
                }

                $got = $parsed;
                assert_equal($prompt, $got['prompt'] ?? null, "{$label}: prompt byte-exact");
                unset($got['prompt']);
                ksort($got);
                ksort($expected);
                assert_equal($expected, $got, "{$label}: agent, conversation id, workdir and profile");
            }
        }
    }

    putenv('SESSIONEER_AGENT_SOCKET=');
    $keys = array_keys(wk('parse_job_script', $wakeBody($script('claude', 'c', '', '', 'x')), $bin) ?? []);
    sort($keys);
    assert_equal(['agent', 'agent_session_id', 'profile', 'prompt', 'workdir'], $keys, 'the parsed array has exactly agent, agent_session_id, workdir, profile, prompt');

    echo "parse_job_script accepts an older job and any log path\n";
    $good = $script('claude', 'conv-old', '', '', 'old job');
    $oldLog = (string)preg_replace('# >> \'[^\']*\' 2>&1$#', " >> '/tmp/sessioneer-wake.log' 2>&1", $good);
    assert_true($oldLog !== $good, 'fixture: the log path was rewritten to a pre-0278da6 style /tmp path');
    assert_equal('old job', wk('parse_job_script', $wakeBody($oldLog), $bin)['prompt'] ?? null, 'a job with a different quoted log path still counts');
    assert_equal('old job', wk('parse_job_script', $wakeBody($good) . "\n\n\n", $bin)['prompt'] ?? null, 'trailing newlines after the command are ignored');
    assert_equal('old job', wk('parse_job_script', $good, $bin)['prompt'] ?? null, 'a body with no preamble at all but the command line parses');

    // ------------------------------------------------------------------ parse_job_script: rejections
    echo "parse_job_script rejects everything that is not exactly a Sessioneer wake job\n";
    $env = "'HOME=/home/waketest' 'PATH=/home/waketest/.local/bin:/usr/local/bin:/usr/bin'";
    $q = static fn (string $s): string => escapeshellarg($s);
    $cmd = static fn (string $envPart, string $binPart, string $args, string $tail = " >> '/tmp/w.log' 2>&1"): string => "env -i {$envPart} {$binPart} {$args}{$tail}";
    $okArgs = "'--agent' 'claude' '--resume' 'conv-x' 'go'";
    assert_true(is_array(wk('parse_job_script', $wakeBody($cmd($env, $q($bin), $okArgs)), $bin)), 'fixture sanity: the hand-built command parses');

    $reject = [
        'an unrelated job' => 'echo hi',
        'an empty body' => '',
        'only a preamble' => '',
        'another checkout' => $cmd($env, $q('/other/checkout/bin/sessioneer'), $okArgs),
        'this checkout under a different spelling' => $cmd($env, $q($bin . '/../bin/sessioneer'), $okArgs),
        'this checkout\'s path, unquoted' => $cmd($env, $bin, $okArgs),
        'a command injected before the redirect' => $cmd($env, $q($bin), $okArgs, "; rm -rf ~ >> '/tmp/w.log' 2>&1"),
        'a command injected after the redirect' => $cmd($env, $q($bin), $okArgs, " >> '/tmp/w.log' 2>&1; rm -rf ~"),
        'a command injected between the env tokens' => "env -i 'HOME=/home/waketest' ; rm -rf ~ 'PATH=/x' " . $q($bin) . " {$okArgs} >> '/tmp/w.log' 2>&1",
        'a command substitution outside quotes' => $cmd($env, $q($bin), $okArgs . ' $(id)'),
        'a second command after the first' => $cmd($env, $q($bin), $okArgs) . "\necho pwned",
        'a second command on the same line' => $cmd($env, $q($bin), $okArgs) . ' && echo pwned',
        'an unquoted prompt word' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' go"),
        'a double-quoted prompt' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' \"go\""),
        'adjacent quoted tokens' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' 'go''more'"),
        'two spaces between tokens' => $cmd($env, $q($bin), "'--agent' 'claude'  '--resume' 'conv-x' 'go'"),
        'an unknown flag' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' '--model' 'opus' 'go'"),
        'an unknown flag before the prompt' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' '--force' 'go'"),
        'no prompt' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x'"),
        'two prompts' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' 'one' 'two'"),
        'an empty resume id' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' '' 'go'"),
        'a missing --agent' => $cmd($env, $q($bin), "'--resume' 'conv-x' 'go'"),
        'a missing --resume' => $cmd($env, $q($bin), "'--agent' 'claude' 'go'"),
        '--resume before --agent' => $cmd($env, $q($bin), "'--resume' 'conv-x' '--agent' 'claude' 'go'"),
        'an agent with capitals' => $cmd($env, $q($bin), "'--agent' 'Claude' '--resume' 'conv-x' 'go'"),
        'an agent starting with a digit' => $cmd($env, $q($bin), "'--agent' '1claude' '--resume' 'conv-x' 'go'"),
        'an agent with a space' => $cmd($env, $q($bin), "'--agent' 'cla ude' '--resume' 'conv-x' 'go'"),
        'an agent with shell characters' => $cmd($env, $q($bin), "'--agent' 'claude;id' '--resume' 'conv-x' 'go'"),
        '--profile before --workdir' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' '--profile' 'work' '--workdir' '/tmp' 'go'"),
        'a repeated --workdir' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' '--workdir' '/a' '--workdir' '/b' 'go'"),
        'env tokens in the wrong order' => "env -i 'PATH=/x' 'HOME=/home/waketest' " . $q($bin) . " {$okArgs} >> '/tmp/w.log' 2>&1",
        'an extra env token' => "env -i 'HOME=/h' 'PATH=/x' 'LD_PRELOAD=/evil.so' " . $q($bin) . " {$okArgs} >> '/tmp/w.log' 2>&1",
        'a socket token before PATH' => "env -i 'HOME=/h' 'SESSIONEER_AGENT_SOCKET=/s' 'PATH=/x' " . $q($bin) . " {$okArgs} >> '/tmp/w.log' 2>&1",
        'a missing PATH' => "env -i 'HOME=/h' " . $q($bin) . " {$okArgs} >> '/tmp/w.log' 2>&1",
        'a missing HOME' => "env -i 'PATH=/x' " . $q($bin) . " {$okArgs} >> '/tmp/w.log' 2>&1",
        'no env -i' => $q($bin) . " {$okArgs} >> '/tmp/w.log' 2>&1",
        'env without -i' => "env 'HOME=/h' 'PATH=/x' " . $q($bin) . " {$okArgs} >> '/tmp/w.log' 2>&1",
        'a missing redirect' => "env -i {$env} " . $q($bin) . " {$okArgs}",
        'a missing 2>&1' => $cmd($env, $q($bin), $okArgs, " >> '/tmp/w.log'"),
        'an unquoted log path' => $cmd($env, $q($bin), $okArgs, ' >> /tmp/w.log 2>&1'),
        'a truncating redirect' => $cmd($env, $q($bin), $okArgs, " > '/tmp/w.log' 2>&1"),
        'two log paths' => $cmd($env, $q($bin), $okArgs, " >> '/tmp/a.log' '/tmp/b.log' 2>&1"),
        'an earlier env -i line that does not parse, then a valid command' => "env -i 'X' junk\n" . $cmd($env, $q($bin), $okArgs),
        'an earlier env -i line that is a different valid-looking job, then ours' => $cmd($env, $q('/other/checkout/bin/sessioneer'), $okArgs) . "\n" . $cmd($env, $q($bin), $okArgs),
        'an invalid UTF-8 byte in the prompt' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' 'bad \xff bytes'"),
        'an invalid UTF-8 byte in the conversation id' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-\xc3' 'go'"),
        'an invalid UTF-8 byte in the workdir' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' '--workdir' '/tmp/\xfe' 'go'"),
        'a single-quote left open' => $cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' 'go"),
    ];

    foreach ($reject as $label => $command) {
        assert_equal(null, wk('parse_job_script', $label === 'an empty body' ? '' : $wakeBody($command), $bin), "{$label}: not a wake job");
    }

    assert_equal(null, wk('parse_job_script', $wakeBody($script('claude', 'c', '', '', 'x')), '/other/checkout/bin/sessioneer'), 'the same job is not ours when the checkout path differs');
    assert_equal(null, wk('parse_job_script', $wakeBody($cmd($env, $q($bin), $okArgs)) . "\n", ''), 'an empty bin path matches nothing');

    assert_equal(null, wk('parse_job_script', $wakeBody('echo hi', ["PAYLOAD=env\\ -i\\ \\'HOME\\=/h\\'\\ x; export PAYLOAD"]), $bin), 'a preamble holding an escaped env -i text does not make a job a wake job');

    // ------------------------------------------------------------------ list / show / cancel fixture
    $rig->reset();
    $now = $stampOf('2026-11-14 12:00:00');
    $hostile = "it's \"quoted\" \$HOME `date` \\ back\n--workdir";
    $realA = $script('claude', 'conv-w1', '/tmp/proj', 'work', $hostile);
    $realRunning = $script('claude', 'conv-w1', '/tmp/proj', 'work', 'running now');
    $realNoSidecar = $script('claude', 'conv-gone', '', '', 'orphan');
    $other = $script('opencode', 'ses_abc', '/tmp/proj', '', 'opencode wake');
    $okNoPrompt = $cmd($env, $q($bin), $okArgs);
    $rig->add(11, $stampOf('2026-11-15 10:30:00'), $wakeBody($realA));
    $rig->add(12, $stampOf('2026-11-15 11:00:00'), $wakeBody('echo hi'));
    $rig->add(13, $stampOf('2026-11-15 11:30:00'), $wakeBody($cmd($env, $q('/other/checkout/bin/sessioneer'), $okArgs)));
    $rig->add(14, $stampOf('2026-11-15 12:00:00'), $wakeBody($cmd($env, $q($bin), $okArgs, "; rm -rf ~ >> '/tmp/w.log' 2>&1")));
    $rig->add(15, $stampOf('2026-11-15 12:30:00'), $wakeBody($cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' '--model' 'opus' 'go'")));
    $rig->add(16, $stampOf('2026-11-14 11:59:30'), $wakeBody($realRunning), '=');
    $rig->add(17, $stampOf('2026-11-15 13:00:00'), $wakeBody('echo hi', ["PAYLOAD=env\\ -i\\ \\'HOME\\=/h\\'\\ x; export PAYLOAD"]));
    $rig->add(18, $stampOf('2026-11-15 13:30:00'), null);
    $rig->add(19, $stampOf('2026-11-15 09:00:00'), $wakeBody($realNoSidecar));
    $rig->add(20, $stampOf('2026-11-15 10:30:00'), $wakeBody($other));
    $wakeKeys = ['agent', 'agent_session_id', 'at', 'at_local', 'job', 'profile', 'prompt_length', 'prompt_preview', 'session', 'state', 'title', 'workdir'];

    echo "list returns only Sessioneer wake jobs, sorted by time then job\n";
    $r = wk('list', null, false, $now);
    assert_equal(true, $r['ok'] ?? null, 'ok');
    assert_equal([16, 19, 11, 20], array_map(static fn (array $w): int => $w['job'], $r['wakes'] ?? []), 'jobs 16, 19, 11, 20 only, ordered by at then job (11 and 20 share a minute)');
    assert_true(empty($r['truncated']), 'not truncated');
    $wakes = [];
    foreach ($r['wakes'] ?? [] as $w) {
        $wakes[$w['job']] = $w;
    }
    $w11 = $wakes[11] ?? null;
    $keys = array_keys($w11 ?? []);
    sort($keys);
    assert_equal($wakeKeys, $keys, 'a wake object has exactly the contract keys (no prompt unless asked)');
    assert_equal(1794756600, $w11['at'], 'at is the unix time');
    assert_equal('2026-11-15 10:30 EST', $w11['at_local'], 'at_local is Y-m-d H:i T in the machine zone');
    assert_equal('scheduled', $w11['state'], 'a future job is scheduled');
    assert_equal('claude', $w11['agent'], 'agent');
    assert_equal('conv-w1', $w11['agent_session_id'], 'conversation id');
    assert_equal('/tmp/proj', $w11['workdir'], 'workdir');
    assert_equal('work', $w11['profile'], 'profile');
    assert_equal('claude-headless-w1', $w11['session'], 'the session that tracks the conversation');
    assert_true(array_key_exists('title', $w11) && ($w11['title'] === null || is_string($w11['title'])), 'the title is a string or null');
    assert_equal(mb_strlen($hostile), $w11['prompt_length'], 'prompt_length counts characters of the full prompt');
    assert_equal("it's \"quoted\" \$HOME `date` \\ back --workdir", $w11['prompt_preview'], 'the preview collapses whitespace runs to one space and keeps quotes and symbols');
    assert_equal(null, $wakes[19]['workdir'], 'no --workdir gives a null workdir');
    assert_equal(null, $wakes[19]['profile'], 'no --profile gives a null profile');
    assert_equal(null, $wakes[19]['session'], 'a conversation no session tracks has a null session');
    assert_equal('oc-w3', $wakes[20]['session'], 'an opencode conversation resolves to its own session');

    echo "list states\n";
    assert_equal('running', $wakes[16]['state'], 'queue letter = is running, even though its time has passed');
    assert_equal('scheduled', $wakes[11]['state'], 'the future is scheduled');
    $rig->reset();
    foreach ([
        'exactly 60 s ago' => [$now - 60, 'scheduled'],
        '61 s ago' => [$now - 61, 'overdue'],
        'two hours ago' => [$now - 7200, 'overdue'],
        'this very second' => [$now, 'scheduled'],
        'a minute ahead' => [$now + 60, 'scheduled'],
    ] as $label => [$at, $state]) {
        $rig->clear_cache();
        $rig->reset();
        $rig->add(40, $at, $wakeBody($script('claude', 'conv-w1', '', '', 'x')));
        assert_equal($state, wk('list', null, false, $now)['wakes'][0]['state'] ?? null, "{$label}: {$state}");
    }
    $rig->reset();
    $rig->add(41, $now - 7200, $wakeBody($script('claude', 'conv-w1', '', '', 'x')), '=');
    assert_equal('running', wk('list', null, false, $now)['wakes'][0]['state'] ?? null, 'running wins over overdue');

    echo "list: filter, prompt, session resolution\n";
    $rig->reset();
    $rig->add(11, $stampOf('2026-11-15 10:30:00'), $wakeBody($realA));
    $rig->add(19, $stampOf('2026-11-15 09:00:00'), $wakeBody($realNoSidecar));
    $rig->add(20, $stampOf('2026-11-15 10:30:00'), $wakeBody($other));
    assert_equal([11], array_map(static fn (array $w): int => $w['job'], wk('list', 'conv-w1', false, $now)['wakes'] ?? []), 'filtering by conversation id keeps only that conversation');
    assert_equal([], wk('list', 'conv-none', false, $now)['wakes'] ?? null, 'a conversation with no wake-ups: ok and empty');
    assert_equal(true, wk('list', 'conv-none', false, $now)['ok'] ?? null, 'and ok');
    $plain = wk('list', 'conv-w1', false, $now)['wakes'][0] ?? [];
    assert_true(!array_key_exists('prompt', $plain), 'no prompt key unless asked');
    $full = wk('list', 'conv-w1', true, $now)['wakes'][0] ?? null;
    assert_equal($hostile, $full['prompt'] ?? null, 'include_prompt adds the exact prompt bytes');
    $keys = array_keys($full ?? []);
    sort($keys);
    $withPrompt = array_merge($wakeKeys, ['prompt']);
    sort($withPrompt);
    assert_equal($withPrompt, $keys, 'with the prompt: the contract keys plus prompt');

    SidecarStore::delete_sidecar('claude-headless-w1');
    $afterRemoval = wk('list', 'conv-w1', false, $now)['wakes'][0] ?? [];
    assert_true(array_key_exists('session', $afterRemoval) && $afterRemoval['session'] === null, 'once its sidecar is removed the session is null');
    SidecarStore::write_sidecar('claude-headless-w1', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'conv-w1', 'agent' => 'claude', 'profile' => 'work']);

    echo "list: preview and length rules\n";
    $previewCases = [
        'short' => ['short prompt', 'short prompt', 12],
        'whitespace runs' => ["a\n\n  b\t\tc", 'a b c', 9],
        'exactly 160 chars' => [str_repeat('x', 160), str_repeat('x', 160), 160],
        '161 chars' => [str_repeat('x', 161), str_repeat('x', 160) . '…', 161],
        'multibyte over the limit' => [str_repeat('é', 200), str_repeat('é', 160) . '…', 200],
        'emoji over the limit' => [str_repeat('🌍', 170), str_repeat('🌍', 160) . '…', 170],
        'trimmed at both ends' => ["  leading and trailing  ", 'leading and trailing', 24],
        'trim happens before the cut' => [' ' . str_repeat('x', 160), str_repeat('x', 160), 161],
        'whitespace only' => [' ', '', 1],
        'the 30000-character maximum' => [str_repeat('z', 30000), str_repeat('z', 160) . '…', 30000],
    ];
    foreach ($previewCases as $label => [$prompt, $preview, $length]) {
        $rig->reset();
        $rig->add(50, $stampOf('2026-11-15 10:30:00'), $wakeBody($script('claude', 'conv-w1', '', '', $prompt)));
        $w = wk('list', null, true, $now)['wakes'][0] ?? [];
        assert_equal($preview, $w['prompt_preview'] ?? null, "preview: {$label}");
        assert_equal($length, $w['prompt_length'] ?? null, "length: {$label}");
        assert_equal($prompt, $w['prompt'] ?? null, "full prompt: {$label}");
    }

    echo "list: empty queue, vanishing job, only flags, no raw output\n";
    $rig->reset();
    $empty = wk('list', null, false, $now);
    assert_equal(true, $empty['ok'] ?? null, 'an empty queue is ok');
    assert_equal([], $empty['wakes'] ?? null, 'with no wakes');
    assert_true(!array_key_exists('truncated', $empty), 'and no truncated key');
    $rig->clear_calls();
    $rig->add(18, $stampOf('2026-11-15 13:30:00'), null);
    $rig->add(11, $stampOf('2026-11-15 10:30:00'), $wakeBody($realA));
    $r = wk('list', null, false, $now);
    assert_equal([11], array_map(static fn (array $w): int => $w['job'], $r['wakes'] ?? []), 'a queue row whose job vanished is skipped');
    assert_equal(true, $r['ok'] ?? null, 'and it is no error');
    assert_true(!str_contains(json_encode($r), 'hunter2') && !str_contains(json_encode($r), 'atrun') && !str_contains(json_encode($r), 'Execution directory'), 'no raw at -c preamble in the reply');
    foreach ($rig->calls() as $line) {
        assert_true(preg_match('/^(-l|-c \d+)$/', $line) === 1, "list only runs at -l and at -c N (saw: {$line})");
    }
    assert_equal(1, $rig->count_calls('-l'), 'the queue is read once');
    assert_true(!is_file($rig->root . '/atq-atrm.log'), 'atq and atrm are never called by name');

    echo "list: failures of at itself\n";
    $rig->reset();
    putenv('AT_BIN=' . $rig->root . '/no-such-dir/at');
    foreach (['list' => wk('list', null, false, $now), 'show' => wk('show', 11), 'cancel' => wk('cancel', 11)] as $label => $r) {
        assert_equal(false, $r['ok'] ?? null, "{$label}: missing at binary is not ok");
        assert_equal('The at command is not available (AT_BIN=' . $rig->root . '/no-such-dir/at)', $r['message'] ?? null, "{$label}: says so and names AT_BIN");
    }
    putenv('AT_BIN=' . $rig->dir . '/at');
    touch($rig->dir . '/fail-l');
    $r = wk('list', null, false, $now);
    assert_equal(false, $r['ok'] ?? null, 'at -l failing is not ok');
    assert_equal('Could not read the at queue: at: cannot read the queue', $r['message'] ?? null, 'with the first stderr line only');
    assert_true(!str_contains((string)($r['message'] ?? ''), 'second stderr line'), 'not the second line');
    assert_true(!isset($r['wakes']), 'and no wakes');
    @unlink($rig->dir . '/fail-l');
    touch($rig->dir . '/fail-l-silent');
    assert_equal('Could not read the at queue: at failed', wk('list', null, false, $now)['message'] ?? null, 'an empty stderr gives "at failed"');
    @unlink($rig->dir . '/fail-l-silent');
    $rig->clear_calls();
    foreach ([0, -4] as $badJob) {
        wk('show', $badJob);
        wk('cancel', $badJob);
    }
    assert_equal([], $rig->calls(), 'a job number that is not positive is refused before any at call');

    echo "list: the 200-row cap\n";
    foreach ([200 => false, 201 => true, 250 => true] as $rows => $truncated) {
        $rig->reset();
        $base = $stampOf('2026-11-16 00:00:00');

        for ($i = 1; $i <= $rows; $i++) {
            $rig->add($i, $base + $i * 60, $wakeBody($script('claude', 'conv-w1', '', '', "p{$i}")));
        }

        $r = wk('list', null, false, $now);
        assert_equal(200, count($r['wakes'] ?? []), "{$rows} rows: 200 wakes returned");
        assert_equal(true, $truncated ? ($r['truncated'] ?? null) === true : !array_key_exists('truncated', $r), "{$rows} rows: truncated is " . ($truncated ? 'true' : 'absent'));
        assert_true($rig->count_calls('-l') === 1 && count($rig->calls()) <= 201, "{$rows} rows: at -c ran at most 200 times");
    }

    echo "list: over the cap the 200 earliest by time are kept\n";
    $rig->reset();
    $base = $stampOf('2026-11-16 00:00:00');

    for ($i = 1; $i <= 201; $i++) {
        $rig->add($i, $base + (202 - $i) * 60, $wakeBody($script('claude', 'conv-w1', '', '', "p{$i}")));
    }

    $r = wk('list', null, false, $now);
    $jobs = array_map(static fn (array $w): int => $w['job'], $r['wakes'] ?? []);
    assert_equal(200, count($jobs), 'reverse order, 201 rows: 200 wakes');
    assert_equal(true, $r['truncated'] ?? null, 'reverse order: truncated');
    assert_true(!in_array(1, $jobs, true), 'the latest job (job 1) is the one left out');
    assert_equal([201, 200, 199], array_slice($jobs, 0, 3), 'and the rest are sorted by time, earliest first');
    assert_equal(0, $rig->count_calls('-c 1'), 'at -c was never run for the job left out');

    echo "list: an invalid UTF-8 job is skipped and the reply stays encodable\n";
    $rig->reset();
    $rig->add(11, $stampOf('2026-11-15 10:30:00'), $wakeBody($realA));
    $rig->add(61, $stampOf('2026-11-15 10:45:00'), $wakeBody($cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-w1' 'bad \xff bytes'")));
    $r = wk('list', null, true, $now);
    assert_equal([11], array_map(static fn (array $w): int => $w['job'], $r['wakes'] ?? []), 'only the good job is listed');
    assert_true(json_encode($r) !== false, 'the reply can be encoded as JSON');
    $r = wk('show', 61);
    assert_equal([false, 'Job 61 is not a Sessioneer wake-up'], [$r['ok'] ?? null, $r['message'] ?? null], 'show refuses it as not a wake-up');

    // ------------------------------------------------------------------ show
    echo "show\n";
    $rig->reset();
    $rig->add(11, $stampOf('2026-11-15 10:30:00'), $wakeBody($realA));
    $rig->add(12, $stampOf('2026-11-15 11:00:00'), $wakeBody('echo hi'));
    $rig->add(16, $now - 30, $wakeBody($realRunning), '=');
    $rig->add(18, $stampOf('2026-11-15 13:30:00'), null);
    $r = wk('show', 11);
    assert_equal(true, $r['ok'] ?? null, 'show: ok');
    $keys = array_keys($r['wake'] ?? []);
    sort($keys);
    assert_equal($withPrompt, $keys, 'show: the wake has the contract keys and the prompt');
    assert_equal($hostile, $r['wake']['prompt'] ?? null, 'show: the prompt is byte-exact');
    assert_equal('claude-headless-w1', $r['wake']['session'] ?? null, 'show: the session resolves');
    assert_equal('running', wk('show', 16)['wake']['state'] ?? null, 'show: a running job can be shown');
    $r = wk('show', 12);
    assert_equal([false, 'Job 12 is not a Sessioneer wake-up'], [$r['ok'] ?? null, $r['message'] ?? null], 'show: an unrelated job is refused with the not-a-wake message');
    foreach ([18, 999] as $gone) {
        $r = wk('show', $gone);
        assert_equal([false, true, "Wake-up {$gone} no longer exists: it already ran or was cancelled"], [$r['ok'] ?? null, $r['gone'] ?? null, $r['message'] ?? null], "show {$gone}: gone");
    }
    foreach ([0, -4] as $bad) {
        $r = wk('show', $bad);
        assert_equal([false, 'Needs an at job number'], [$r['ok'] ?? null, $r['message'] ?? null], "show {$bad}: needs a job number");
    }
    foreach ([wk('show', 12), wk('show', 999), wk('show', 11)] as $reply) {
        assert_true(!str_contains(json_encode($reply), 'hunter2') && !str_contains(json_encode($reply), 'atrun'), 'show: no raw at -c output in any reply');
    }
    assert_equal('', $rig->removed(), 'show never removes anything');

    // ------------------------------------------------------------------ cancel
    echo "cancel refuses what it must and removes nothing\n";
    $rig->reset();
    $rig->add(12, $stampOf('2026-11-15 11:00:00'), $wakeBody('echo hi'));
    $rig->add(13, $stampOf('2026-11-15 11:30:00'), $wakeBody($cmd($env, $q('/other/checkout/bin/sessioneer'), $okArgs)));
    $rig->add(14, $stampOf('2026-11-15 12:00:00'), $wakeBody($cmd($env, $q($bin), $okArgs, "; rm -rf ~ >> '/tmp/w.log' 2>&1")));
    $rig->add(15, $stampOf('2026-11-15 12:30:00'), $wakeBody($cmd($env, $q($bin), "'--agent' 'claude' '--resume' 'conv-x' '--model' 'opus' 'go'")));
    $rig->add(16, $now - 30, $wakeBody($realRunning), '=');
    $rig->add(17, $stampOf('2026-11-15 13:00:00'), $wakeBody('echo hi', ["PAYLOAD=env\\ -i\\ \\'HOME\\=/h\\'\\ x; export PAYLOAD"]));
    $rig->add(18, $stampOf('2026-11-15 13:30:00'), null);
    $rig->add(21, $now - 30, $wakeBody('echo hi'), '=');

    foreach ([12, 13, 14, 15, 17, 21] as $job) {
        $r = wk('cancel', $job);
        assert_equal(false, $r['ok'] ?? null, "cancel {$job}: refused");
        assert_equal("Job {$job} is not a Sessioneer wake-up", $r['message'] ?? null, "cancel {$job}: not a Sessioneer wake-up");
        assert_true(!str_contains(json_encode($r), 'hunter2') && !str_contains(json_encode($r), 'atrun'), "cancel {$job}: no raw at -c output in the reply");
    }

    $r = wk('cancel', 16);
    assert_equal([false, 'Wake-up 16 is already running'], [$r['ok'] ?? null, $r['message'] ?? null], 'cancel of a running wake-up is refused');
    foreach ([18, 999] as $job) {
        $r = wk('cancel', $job);
        assert_equal([false, true, "Wake-up {$job} no longer exists: it already ran or was cancelled"], [$r['ok'] ?? null, $r['gone'] ?? null, $r['message'] ?? null], "cancel {$job}: gone");
    }
    foreach ([0, -1, -999] as $job) {
        $r = wk('cancel', $job);
        assert_equal([false, 'Needs an at job number'], [$r['ok'] ?? null, $r['message'] ?? null], "cancel {$job}: needs a job number");
    }

    assert_equal('', $rig->removed(), 'removed.log stayed empty through every refusal');
    foreach ($rig->calls() as $line) {
        assert_true(preg_match('/^(-l|-c \d+)$/', $line) === 1, "a refused cancel only reads (saw: {$line})");
    }

    echo "cancel removes a Sessioneer wake-up\n";
    $rig->reset();
    $rig->add(11, $stampOf('2026-11-15 10:30:00'), $wakeBody($realA));
    $rig->add(12, $stampOf('2026-11-15 11:00:00'), $wakeBody('echo hi'));
    $rig->add(19, $stampOf('2026-11-15 09:00:00'), $wakeBody($realNoSidecar));
    $r = wk('cancel', 11);
    assert_equal(true, $r['ok'] ?? null, 'ok');
    assert_equal('Cancelled wake-up 11 (2026-11-15 10:30 EST)', $r['message'] ?? null, 'the message names the job and its local time');
    assert_equal(11, $r['job'] ?? null, 'job');
    assert_equal('claude-headless-w1', $r['name'] ?? null, 'name is the session that tracks the conversation');
    assert_equal("11\n", $rig->removed(), 'at -r 11 ran, once, for that job only');
    assert_equal([19], array_map(static fn (array $w): int => $w['job'], wk('list', null, false, $now)['wakes'] ?? []), 'the job is gone from the queue');
    $r = wk('cancel', 11);
    assert_equal([false, true], [$r['ok'] ?? null, $r['gone'] ?? null], 'cancelling it again says it is gone');
    assert_equal("11\n", $rig->removed(), 'and removes nothing more');
    $r = wk('cancel', 19);
    assert_equal(true, $r['ok'] ?? null, 'a wake-up whose session is gone can still be cancelled');
    assert_equal('', $r['name'] ?? '', 'with no session name');
    assert_equal("11\n19\n", $rig->removed(), 'only the two cancelled jobs were removed');
    assert_equal('', trim((string)(is_file($rig->root . '/atq-atrm.log') ? file_get_contents($rig->root . '/atq-atrm.log') : '')), 'atrm was never called by name');
    assert_true(count(array_filter($rig->calls(), static fn (string $l): bool => !preg_match('/^(-l|-c \d+|-r \d+)$/', $l))) === 0, 'only at -l, -c N and -r N were run');

    echo "cancel: a job at -c can read but at -l does not list, and at -l failing\n";
    $rig->reset();
    file_put_contents($rig->dir . '/jobs/60', $wakeBody($realA));
    $r = wk('cancel', 60);
    assert_equal([false, true, 'Wake-up 60 no longer exists: it already ran or was cancelled'], [$r['ok'] ?? null, $r['gone'] ?? null, $r['message'] ?? null], 'not in the queue: gone');
    $r = wk('show', 60);
    assert_equal([false, true], [$r['ok'] ?? null, $r['gone'] ?? null], 'show says the same');
    $rig->add(11, $stampOf('2026-11-15 10:30:00'), $wakeBody($realA));
    touch($rig->dir . '/fail-l');
    $r = wk('cancel', 11);
    assert_equal([false, 'Could not read the at queue: at: cannot read the queue'], [$r['ok'] ?? null, $r['message'] ?? null], 'at -l failing during a cancel is reported');
    $r = wk('show', 11);
    assert_equal([false, 'Could not read the at queue: at: cannot read the queue'], [$r['ok'] ?? null, $r['message'] ?? null], 'and during a show');
    @unlink($rig->dir . '/fail-l');
    assert_equal('', $rig->removed(), 'nothing was removed in either case');

    echo "cancel when at -r fails\n";
    $rig->reset();
    $rig->add(11, $stampOf('2026-11-15 10:30:00'), $wakeBody($realA));
    touch($rig->dir . '/fail-r');
    $r = wk('cancel', 11);
    assert_equal([false, 'Could not cancel wake-up 11: Permission denied removing 11'], [$r['ok'] ?? null, $r['message'] ?? null], 'the first stderr line is in the message');
    assert_true(!str_contains((string)($r['message'] ?? ''), 'second stderr line'), 'not the second line');
    assert_equal('', $rig->removed(), 'nothing was removed');
} finally {
    $rig->cleanup();
}

test_exit();
