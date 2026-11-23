<?php

declare(strict_types=1);

/**
 * Host action `flow_schedule` (quota reset scheduling), through the agent's own dispatch_action(), on the
 * `at` stub of tests/lib/wake_fixture.php. Written from the Contract only. Intent notes:
 *  - approval gate: no `at -t` call and no job without approved === true; a refusal never reaches `at`;
 *  - wake vs fresh start: `session` makes a job that resumes that conversation; handoff_id/plan_id make a job that
 *    starts a fresh session of the named agent/profile and does not resume anything;
 *  - timing: the job fires one minute after the stored reset (reset aligned to a minute, so `at` == reset + 60);
 *  - idempotency: same agent, profile, context and reset while the job is pending returns that job with
 *    existing:true and queues nothing; a different context or reset, or a cancelled job, queues a new one;
 *  - clean environment: the job runs under `env -i` and names no session variable (rule 1225), even when the
 *    caller's own environment carries one;
 *  - refusals: each has its own message and creates no job; atq failing refuses before scheduling;
 *  - a failure of `at` itself is reported (ok:false, no job id) and not swallowed;
 *  - listing and cancelling go through the existing launch_wake_list / launch_wake_cancel; nothing else cancels it,
 *    in particular dismissing an offer queues nothing and removes nothing.
 * Seeding: the Claude buckets live in GlobalStateStore (the data --usage reads), default account under key
 * quota_live_state, work account under quota_live_state:work. A profile's reset is the latest resets_at among its
 * buckets at 100% or more, else the soonest future one; unknown or past is refused. atd_health() is {ok, message},
 * healthy only when `at -l` succeeds and the command in SESSIONEER_ATD_CHECK_CMD exits 0 (tests set true/false).
 *  - flow_resets answers {ok, agents:{agent:{label, profiles:{profile:{reset_at}}}}} and is read-only;
 *  - `at` fires at the next whole minute at or after reset + 60 s; `approved` must be exactly boolean true; callers
 *    inside a tracked session are allowed; workdir is optional on a fresh start; a fired or cancelled job is not pending.
 */

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/wake_fixture.php';

$rig = new WakeRig('flow-schedule');
putenv('TMUX_SOCKET=' . $rig->root . '/tmux/socket');
putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
putenv('CLAUDE_HEADLESS_SOCKET=' . $rig->root . '/no-claude-manager.sock');
putenv('CODEX_BRIDGE_SOCKET=' . $rig->root . '/no-codex-bridge.sock');
putenv('SOCKET_CLIENT_FAIL_FAST_IF_ABSENT=1');
putenv('PUSH_SQLITE_FILE=' . $rig->root . '/push.sqlite');
putenv('SESSIONEER_SELF=cc-caller-from-env');
putenv('SESSIONEER_SESSION_NAME=cc-caller-from-env');
putenv('SESSIONEER_ATD_CHECK_CMD=true');

// `at -t` can be made to fail without touching the shared stub.
$wrapper = $rig->root . '/at-wrapper';
file_put_contents($wrapper, "#!/bin/sh\nif [ \"\$1\" = -t ] && [ -f '" . $rig->root . "/slow-t' ]; then sleep 0.5; fi\nif [ \"\$1\" = -t ] && [ -f '" . $rig->root . "/fail-t' ]; then echo 'at: cannot create job' >&2; exit 1; fi\nexec '" . $rig->dir . "/at' \"\$@\"\n");
chmod($wrapper, 0700);
putenv('AT_BIN=' . $wrapper);

require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\SessionWakeService;
use HostAgent\Stores\GlobalStateStore;
use HostAgent\Stores\SidecarStore;

SidecarStore::write_sidecar('claude-headless-w1', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'conv-w1', 'agent' => 'claude', 'profile' => 'work']);
SidecarStore::write_sidecar('claude-headless-p1', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'conv-p1', 'agent' => 'claude', 'profile' => 'personal']);
SidecarStore::write_sidecar('oc-w3', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent_session_id' => 'ses_abc', 'agent' => 'opencode']);
SidecarStore::write_sidecar('claude-headless-w2', ['workdir' => '/tmp/proj', 'spawned_at' => 1, 'agent' => 'claude']);

$phpNotices = [];
set_error_handler(static function (int $no, string $str) use (&$phpNotices): bool {
    // The dead Codex bridge socket of the fixture is probed by unrelated quota code and warns on every connect.
    if (!str_starts_with($str, 'stream_socket_client()')) {
        $phpNotices[] = $str;
    }

    return true;
});
$act = static function (array $request): array {
    try {
        return dispatch_action($request);
    } catch (\Throwable $e) {
        assert_true(false, 'dispatch_action ' . ($request['action'] ?? '?') . ' did not throw (' . get_class($e) . ': ' . $e->getMessage() . ')');

        return ['ok' => null, 'message' => 'threw'];
    }
};
$seed = static function (?string $profile, ?int $reset): void {
    $state = ['captured_at' => time()];
    if ($reset !== null) {
        $state['session'] = ['pct' => 95, 'resets_at' => $reset];
    }
    GlobalStateStore::write(Config::quota_live_state_key($profile), $state);
};
$seedBuckets = static function (?string $profile, array $buckets): void {
    GlobalStateStore::write(Config::quota_live_state_key($profile), ['captured_at' => time()] + $buckets);
};
$atCreates = fn (): int => count(array_filter($rig->calls(), static fn (string $l): bool => str_starts_with($l, '-t ')));
$msg = static fn (array $r): string => strtolower((string)($r['message'] ?? ''));

$resetPersonal = intdiv(time(), 60) * 60 + 3 * 3600;
$resetWork = $resetPersonal + 1800;
$seed(null, $resetPersonal);
$seed('work', $resetWork);

$base = ['action' => 'flow_schedule', 'agent' => 'claude', 'profile' => 'personal', 'approved' => true, 'expected_reset_at' => $resetPersonal];

try {
    echo "No job before approval\n";
    foreach ([
        'approved missing' => array_diff_key($base, ['approved' => 1]) + ['session' => 'claude-headless-w1'],
        'approved false' => ['approved' => false, 'session' => 'claude-headless-w1'] + $base,
        'approved the string true' => ['approved' => 'true', 'session' => 'claude-headless-w1'] + $base,
        'approved 1' => ['approved' => 1, 'session' => 'claude-headless-w1'] + $base,
    ] as $label => $req) {
        $r = $act($req);
        assert_equal(false, $r['ok'] ?? null, "{$label}: refused");
        assert_true(str_contains($msg($r), 'approv'), "{$label}: the message says approval is missing");
        assert_true(!array_key_exists('job', $r), "{$label}: no job id");
    }
    assert_equal(0, $atCreates(), 'no refusal reached at -t');
    assert_equal('', trim((string)file_get_contents($rig->dir . '/queue.txt')), 'the queue is still empty');

    echo "Refusals each have their own message and queue nothing\n";
    $ctx = ['session' => 'claude-headless-p1'];
    $refusals = [
        'unknown agent' => [['agent' => 'nosuchagent'] + $ctx + $base, '/agent/'],
        'unknown profile' => [['profile' => 'nosuchprofile'] + $ctx + $base, '/profile/'],
        'stale expected_reset_at' => [['expected_reset_at' => $resetPersonal - 600] + $ctx + $base, '/stale|changed|differ/'],
        'expected_reset_at missing' => [$ctx + array_diff_key($base, ['expected_reset_at' => 1]), '/reset/'],
        'no context' => [$base, '/one of|context|exactly/'],
        'two contexts' => [['session' => 'claude-headless-w1', 'handoff_id' => '5'] + $base, '/one of|context|exactly|only one/'],
        'three contexts' => [['session' => 'claude-headless-w1', 'handoff_id' => '5', 'plan_id' => '6'] + $base, '/one of|context|exactly|only one/'],
        'handoff not digits' => [['handoff_id' => '5x'] + $base, '/digit|handoff/'],
        'plan not digits' => [['plan_id' => '1.5'] + $base, '/digit|plan/'],
        'handoff empty' => [['handoff_id' => ''] + $base, '/digit|handoff|context|one of/'],
        'wake target not found' => [['session' => 'nope'] + $base, '/not found|no session|no such session|could not find/'],
        'wake target without conversation' => [['session' => 'claude-headless-w2'] + $base, '/conversation/'],
    ];
    $messages = [];
    foreach ($refusals as $label => [$req, $pattern]) {
        $r = $act($req);
        assert_equal(false, $r['ok'] ?? null, "{$label}: refused");
        assert_true(preg_match($pattern, $msg($r)) === 1, "{$label}: message is specific ({$pattern}), got '" . ($r['message'] ?? '') . "'");
        $messages[$label] = $msg($r);
    }
    assert_equal(count($messages), count(array_unique($messages)), 'every refusal reason reads differently');
    assert_equal(0, $atCreates(), 'no refusal reached at -t');

    echo "A wake context must match the requested agent and profile\n";
    $r = $act(['session' => 'claude-headless-w1'] + $base);
    assert_equal(false, $r['ok'] ?? null, 'a work-profile session with a claude:personal request: refused');
    assert_true(preg_match('/mismatch|does not match|belongs to|differs/', $msg($r)) === 1 && str_contains($msg($r), 'work') && str_contains($msg($r), 'personal'), "the message names the mismatch and both profiles, got '" . ($r['message'] ?? '') . "'");
    $r2 = $act(['session' => 'conv-w1'] + $base);
    assert_equal(false, $r2['ok'] ?? null, 'the same by conversation id: refused');
    $r3 = $act(['session' => 'oc-w3'] + $base);
    assert_equal(false, $r3['ok'] ?? null, 'an opencode session with a claude request: refused');
    assert_true(preg_match('/mismatch|does not match|belongs to|differs/', $msg($r3)) === 1 && str_contains($msg($r3), 'opencode'), "the agent mismatch names opencode, got '" . ($r3['message'] ?? '') . "'");
    assert_equal(0, $atCreates(), 'no mismatch reached at -t');
    $ok = $act(['session' => 'claude-headless-w1', 'profile' => 'work', 'expected_reset_at' => $resetWork] + $base);
    assert_equal(true, $ok['ok'] ?? null, 'the matching claude:work session still schedules');
    assert_equal(1, $atCreates(), 'one job for the match');
    $rig->reset();

    echo "An empty workdir is refused cleanly\n";
    $phpNotices = [];
    $r = $act(['handoff_id' => '61', 'workdir' => ''] + $base);
    assert_equal(false, $r['ok'] ?? null, 'workdir empty: refused');
    assert_contains('absolute path', $msg($r), 'with the absolute path message');
    $r = $act(['handoff_id' => '61', 'workdir' => 'relative/dir'] + $base);
    assert_equal(false, $r['ok'] ?? null, 'a relative workdir: refused');
    assert_contains('absolute path', $msg($r), 'with the same message');
    assert_equal([], $phpNotices, 'no PHP warning or notice');
    assert_equal(0, $atCreates(), 'no job queued');

    echo "Unknown or past reset time\n";
    $seed(null, null);
    $r = $act(['expected_reset_at' => $resetPersonal] + $ctx + $base);
    assert_equal(false, $r['ok'] ?? null, 'no stored reset: refused');
    assert_true(str_contains($msg($r), 'reset'), 'no stored reset: message names the reset time');
    $seed(null, intdiv(time(), 60) * 60 - 600);
    $r = $act(['expected_reset_at' => intdiv(time(), 60) * 60 - 600] + $ctx + $base);
    assert_equal(false, $r['ok'] ?? null, 'a past reset: refused');
    assert_true(preg_match('/past|already|passed|elapsed/', $msg($r)) === 1, "a past reset: message says so, got '" . ($r['message'] ?? '') . "'");
    $seed(null, $resetPersonal);
    assert_equal(0, $atCreates(), 'neither reached at -t');

    echo "atd and atq health refuse before scheduling\n";
    assert_true(method_exists(SessionWakeService::class, 'atd_health'), 'SessionWakeService::atd_health() exists');
    $health = static fn (): array => method_exists(SessionWakeService::class, 'atd_health') ? SessionWakeService::atd_health() : [];
    $h = $health();
    assert_equal(true, $h['ok'] ?? null, 'healthy: at -l works and the check command exits 0');
    assert_true(is_string($h['message'] ?? null), 'healthy: carries a message');
    touch($rig->dir . '/fail-l');
    $h = $health();
    assert_equal(false, $h['ok'] ?? null, 'atq failing: not ok');
    assert_true(is_string($h['message'] ?? null) && $h['message'] !== '', 'atq failing: a message');
    $r = $act($ctx + $base);
    assert_equal(false, $r['ok'] ?? null, 'atq failing: schedule refused');
    assert_true(preg_match('/atd|atq/', $msg($r)) === 1, "atq failing: message names atd or atq, got '" . ($r['message'] ?? '') . "'");
    assert_equal(0, $atCreates(), 'atq failing: no at -t call');
    @unlink($rig->dir . '/fail-l');
    putenv('SESSIONEER_ATD_CHECK_CMD=false');
    $h = $health();
    assert_equal(false, $h['ok'] ?? null, 'atd not running (check command exits 1): not ok even though at -l works');
    $r = $act($ctx + $base);
    assert_equal(false, $r['ok'] ?? null, 'atd not running: schedule refused');
    assert_contains('atd', $msg($r), 'atd not running: message names atd');
    assert_equal(0, $atCreates(), 'atd not running: no at -t call');
    putenv('SESSIONEER_ATD_CHECK_CMD=true');
    assert_equal(true, ($health())['ok'] ?? null, 'healthy again once the check passes');

    echo "Which reset time is stored\n";
    $soon = intdiv(time(), 60) * 60 + 3600;
    $mid = $soon + 3600;
    $late = $soon + 7200;
    $seedBuckets(null, ['session' => ['pct' => 100, 'resets_at' => $soon], 'week_all' => ['pct' => 100, 'resets_at' => $late]]);
    $res = $act(['action' => 'flow_resets']);
    assert_equal($late, $res['agents']['claude']['profiles']['personal']['reset_at'] ?? null, 'two exhausted buckets: the latest reset');
    $seedBuckets(null, ['session' => ['pct' => 100, 'resets_at' => $soon], 'week_all' => ['pct' => 40, 'resets_at' => $late]]);
    $res = $act(['action' => 'flow_resets']);
    assert_equal($soon, $res['agents']['claude']['profiles']['personal']['reset_at'] ?? null, 'one exhausted bucket: its reset, not the later healthy one');
    $seedBuckets(null, ['session' => ['pct' => 60, 'resets_at' => $mid], 'week_all' => ['pct' => 70, 'resets_at' => $soon]]);
    $res = $act(['action' => 'flow_resets']);
    assert_equal($soon, $res['agents']['claude']['profiles']['personal']['reset_at'] ?? null, 'none exhausted: the soonest future reset');
    $seedBuckets(null, ['session' => ['pct' => 60, 'resets_at' => $soon - 7200], 'week_all' => ['pct' => 70, 'resets_at' => $mid]]);
    $res = $act(['action' => 'flow_resets']);
    assert_equal($mid, $res['agents']['claude']['profiles']['personal']['reset_at'] ?? null, 'a past bucket is skipped for the soonest future');
    $seedBuckets(null, ['session' => ['pct' => 100, 'resets_at' => $soon], 'week_all' => ['pct' => 100, 'resets_at' => $late]]);
    $r = $act(['expected_reset_at' => $soon] + $ctx + $base);
    assert_equal(false, $r['ok'] ?? null, 'expected_reset_at of the earlier exhausted bucket is stale');
    $r = $act(['expected_reset_at' => $late] + ['handoff_id' => '9'] + $base);
    assert_equal([true, $late + 60], [$r['ok'] ?? null, $r['at'] ?? null], 'the latest exhausted reset schedules, at reset + 60');
    $rig->reset();
    $seed(null, $resetPersonal);

    echo "flow_resets\n";
    $res = $act(['action' => 'flow_resets']);
    assert_equal(true, $res['ok'] ?? null, 'ok');
    assert_true(is_string($res['agents']['claude']['label'] ?? null), 'each agent has a label');
    assert_equal($resetPersonal, $res['agents']['claude']['profiles']['personal']['reset_at'] ?? null, 'the default account reset');
    assert_equal($resetWork, $res['agents']['claude']['profiles']['work']['reset_at'] ?? null, 'the work account reset');
    assert_true(array_key_exists('reset_at', $res['agents']['codex']['profiles']['default'] ?? []) && $res['agents']['codex']['profiles']['default']['reset_at'] === null, 'a profile with no stored reset has reset_at null');
    assert_equal(0, $atCreates(), 'flow_resets is read-only');
    assert_equal([], $rig->calls(), 'flow_resets never calls at');

    echo "The next whole minute at or after reset + 60 s\n";
    $off = $resetPersonal + 30;
    $seed(null, $off);
    $r = $act(['expected_reset_at' => $off, 'handoff_id' => '31'] + $base);
    assert_equal([true, $resetPersonal + 120], [$r['ok'] ?? null, $r['at'] ?? null], 'a reset 30 s into a minute fires 90 s later, at the next whole minute');
    assert_equal('-t ' . (new DateTimeImmutable('@' . ($resetPersonal + 120)))->setTimezone(new DateTimeZone(SessionWakeService::system_timezone()))->format('YmdHi'), trim((string)file_get_contents($rig->dir . '/at-args')), 'and at gets that minute');
    $seed(null, $resetPersonal);
    $rig->reset();

    echo "A wake of an existing conversation\n";
    $r = $act($ctx + $base);
    assert_equal(true, $r['ok'] ?? null, 'scheduled');
    assert_equal(false, $r['existing'] ?? null, 'a new job is not existing');
    assert_equal($resetPersonal + 60, $r['at'] ?? null, 'it fires one minute after the stored reset');
    assert_true(is_int($r['job'] ?? null) && $r['job'] > 0, 'a visible job id is returned');
    $job = $r['job'];
    $expectedStamp = (new DateTimeImmutable('@' . ($resetPersonal + 60)))->setTimezone(new DateTimeZone(SessionWakeService::system_timezone()))->format('YmdHi');
    assert_equal('-t ' . $expectedStamp, trim((string)file_get_contents($rig->dir . '/at-args')), 'at gets reset + 60 s in the machine zone');
    $script = (string)file_get_contents($rig->dir . '/at-script');
    assert_true(str_starts_with($script, "env -i 'HOME="), 'the job runs under env -i');
    assert_contains("'--resume' 'conv-p1'", $script, 'a wake resumes that conversation');
    assert_true(!str_contains($script, 'SESSIONEER_SELF') && !str_contains($script, 'SESSIONEER_SESSION_NAME') && !str_contains($script, 'cc-caller-from-env'), 'no session variable and nothing of the caller\'s environment reaches the job');
    assert_equal(1, $atCreates(), 'one at job was created');

    echo "Idempotent repeat\n";
    $again = $act($ctx + $base);
    assert_equal(true, $again['ok'] ?? null, 'a repeat is ok');
    assert_equal(true, $again['existing'] ?? null, 'and says existing:true');
    assert_equal($job, $again['job'] ?? null, 'with the same job id');
    assert_equal($r['at'] ?? null, $again['at'] ?? null, 'and the same time');
    assert_equal(1, $atCreates(), 'nothing new was queued');
    assert_equal(1, count(array_filter(explode("\n", (string)file_get_contents($rig->dir . '/queue.txt')))), 'one row in the queue');
    $byId = $act(['session' => 'conv-p1'] + $base);
    assert_equal([true, $job, true], [$byId['ok'] ?? null, $byId['job'] ?? null, $byId['existing'] ?? null], 'the same conversation named by its id is the same context');

    echo "Listing and cancelling\n";
    $list = $act(['action' => 'launch_wake_list']);
    assert_true(in_array($job, array_map(static fn (array $w): int => $w['job'], $list['wakes'] ?? []), true), 'the job appears in the wake list');
    $row = array_values(array_filter($list['wakes'] ?? [], static fn (array $w): bool => $w['job'] === $job))[0] ?? [];
    assert_equal('conv-p1', $row['agent_session_id'] ?? null, 'it lists the conversation it wakes');
    assert_equal($resetPersonal + 60, $row['at'] ?? null, 'and the time it fires');
    $cancel = $act(['action' => 'launch_wake_cancel', 'job' => $job]);
    assert_equal(true, $cancel['ok'] ?? null, 'it cancels through launch_wake_cancel');
    assert_equal("{$job}\n", $rig->removed(), 'exactly that job was removed');
    $fresh = $act($ctx + $base);
    assert_equal([true, false], [$fresh['ok'] ?? null, $fresh['existing'] ?? null], 'once cancelled the same request queues a new job');
    assert_true(($fresh['job'] ?? $job) !== $job, 'with a new job id');
    $rig->reset();
    $rig->clear_cache();

    echo "A different reset, profile or context is a different job\n";
    $a = $act($ctx + $base);
    $b = $act(['handoff_id' => '123'] + $base);
    $c = $act(['session' => 'claude-headless-w1', 'profile' => 'work', 'expected_reset_at' => $resetWork] + $base);
    foreach (['wake' => $a, 'handoff' => $b, 'work profile wake' => $c] as $label => $x) {
        assert_equal([true, false], [$x['ok'] ?? null, $x['existing'] ?? null], "{$label}: queued as new");
    }
    assert_equal(3, count(array_unique([$a['job'] ?? 0, $b['job'] ?? 0, $c['job'] ?? 0])), 'three distinct jobs');
    assert_equal($resetWork + 60, $c['at'] ?? null, 'the work profile uses its own stored reset');
    $seed(null, $resetPersonal + 3600);
    $d = $act(['expected_reset_at' => $resetPersonal + 3600] + $ctx + $base);
    assert_equal([true, false], [$d['ok'] ?? null, $d['existing'] ?? null], 'a moved reset time queues a new job');
    assert_equal($resetPersonal + 3600 + 60, $d['at'] ?? null, 'at the new time');
    $seed(null, $resetPersonal);

    echo "A fresh start from a handoff or plan\n";
    $rig->reset();
    $h = $act(['handoff_id' => '123'] + $base);
    assert_equal(true, $h['ok'] ?? null, 'a handoff schedules');
    $script = (string)file_get_contents($rig->dir . '/at-script');
    assert_true(str_starts_with($script, "env -i 'HOME="), 'handoff: runs under env -i');
    assert_true(!str_contains($script, '--resume'), 'handoff: resumes nothing, it starts a fresh session');
    assert_contains("'--agent' 'claude'", $script, 'handoff: names the agent');
    assert_contains('123', $script, 'handoff: carries the handoff continuation prompt for that id');
    assert_true(!str_contains($script, 'SESSIONEER_SELF') && !str_contains($script, 'SESSIONEER_SESSION_NAME'), 'handoff: no session variable');
    $handoffAgain = $act(['handoff_id' => '123'] + $base);
    assert_equal([true, $h['job'] ?? null], [$handoffAgain['existing'] ?? null, $handoffAgain['job'] ?? null], 'handoff: a repeat is the existing job');
    $other = $act(['handoff_id' => '124'] + $base);
    assert_equal(false, $other['existing'] ?? null, 'handoff: another id is a new job');

    $rig->reset();
    $p = $act(['plan_id' => '1765', 'profile' => 'work', 'expected_reset_at' => $resetWork] + $base);
    assert_equal(true, $p['ok'] ?? null, 'a plan on the work profile schedules');
    $script = (string)file_get_contents($rig->dir . '/at-script');
    assert_true(!str_contains($script, '--resume'), 'plan: no --resume');
    assert_contains("'--profile' 'work'", $script, 'plan: the requested profile is used');
    assert_contains('1765', $script, 'plan: carries the plan id');
    $sameIdOther = $act(['handoff_id' => '1765', 'profile' => 'work', 'expected_reset_at' => $resetWork] + $base);
    assert_equal(false, $sameIdOther['existing'] ?? null, 'a handoff and a plan with the same digits are different contexts');

    echo "expected_reset_at may differ from the stored reset by up to 120 s (rolling windows)\n";
    // The Contract tolerates +-120 s (inclusive) because codex and opencode report a reset that moves one second per
    // second; the job time always comes from the stored value, never from the sent one. The older stale case
    // (-600 s, and the 7200 s one) is still stale, so no earlier assertion changed.
    foreach ([1, 60, 120, -1, -60, -120] as $i => $off) {
        $rig->reset();
        $r = $act(['expected_reset_at' => $resetPersonal + $off, 'handoff_id' => (string)(80 + $i)] + $base);
        assert_equal(true, $r['ok'] ?? null, "offset {$off} s: accepted (" . ($r['message'] ?? '') . ')');
        assert_equal($resetPersonal + 60, $r['at'] ?? null, "offset {$off} s: the job time comes from the stored reset + 60, not the sent value");
        $expectedStamp = (new DateTimeImmutable('@' . ($resetPersonal + 60)))->setTimezone(new DateTimeZone(SessionWakeService::system_timezone()))->format('YmdHi');
        assert_equal('-t ' . $expectedStamp, trim((string)@file_get_contents($rig->dir . '/at-args')), "offset {$off} s: at gets the stored reset + 60");
    }
    foreach ([121, -121, 600] as $i => $off) {
        $rig->reset();
        $r = $act(['expected_reset_at' => $resetPersonal + $off, 'handoff_id' => (string)(90 + $i)] + $base);
        assert_equal(false, $r['ok'] ?? null, "offset {$off} s: stale");
        assert_true(preg_match('/stale|changed|differ/', $msg($r)) === 1, "offset {$off} s: the message says stale, got '" . ($r['message'] ?? '') . "'");
        assert_equal(0, $atCreates(), "offset {$off} s: no job");
    }

    echo "A drifting store: the sent value is a few seconds old\n";
    $rig->reset();
    $seed(null, $resetPersonal + 3);
    $r = $act(['expected_reset_at' => $resetPersonal, 'handoff_id' => '95'] + $base);
    assert_equal([true, $resetPersonal + 120], [$r['ok'] ?? null, $r['at'] ?? null], 'stored moved +3 s since the read: accepted, at the next whole minute at or after stored + 60');
    $seed(null, $resetPersonal);

    echo "Idempotency holds when the sent value differs within tolerance\n";
    $rig->reset();
    $first = $act(['handoff_id' => '99'] + $base);
    assert_equal([true, false], [$first['ok'] ?? null, $first['existing'] ?? null], 'first request queues');
    foreach ([45, -100, 120] as $off) {
        $again = $act(['expected_reset_at' => $resetPersonal + $off, 'handoff_id' => '99'] + $base);
        assert_equal([true, true, $first['job'] ?? null], [$again['ok'] ?? null, $again['existing'] ?? null, $again['job'] ?? null], "a repeat sent {$off} s off is the existing job");
    }
    assert_equal(1, $atCreates(), 'nothing more was queued');
    $stale = $act(['expected_reset_at' => $resetPersonal + 121, 'handoff_id' => '99'] + $base);
    assert_equal(false, $stale['ok'] ?? null, 'a repeat beyond tolerance is stale, not silently the existing job');
    assert_equal(1, $atCreates(), 'still one job');
    $rig->reset();

    echo "In-session callers, workdir, and a job that already fired\n";
    $rig->reset();
    $r = $act(['from' => 'claude-headless-w2'] + $ctx + $base);
    assert_equal(true, $r['ok'] ?? null, 'a request from inside a tracked session is allowed');
    $rig->reset();
    $r = $act(['handoff_id' => '55', 'workdir' => '/tmp/proj'] + $base);
    assert_equal(true, $r['ok'] ?? null, 'a handoff with a workdir schedules');
    assert_contains("'--workdir' '/tmp/proj'", (string)file_get_contents($rig->dir . '/at-script'), 'the fresh session starts in that workdir');
    $rig->reset();
    $act(['handoff_id' => '56'] + $base);
    assert_true(!str_contains((string)file_get_contents($rig->dir . '/at-script'), '--workdir'), 'no workdir given, none passed');
    $rig->reset();
    $first = $act(['handoff_id' => '57'] + $base);
    file_put_contents($rig->dir . '/queue.txt', '');
    @unlink($rig->dir . '/jobs/' . ($first['job'] ?? 0));
    $rig->clear_cache();
    $second = $act(['handoff_id' => '57'] + $base);
    assert_equal([true, false], [$second['ok'] ?? null, $second['existing'] ?? null], 'a job that has fired (gone from the queue) is no longer pending: a new one is queued');

    echo "Concurrent duplicate requests queue one job\n";
    $rig->reset();
    file_put_contents($rig->root . '/child.php', "<?php\nrequire " . var_export(dirname(__DIR__) . '/host-agent/lib/Sessions.php', true) . ";\necho json_encode(dispatch_action(json_decode(\$argv[1], true)));\n");
    touch($rig->root . '/slow-t');
    $procs = [];
    for ($n = 0; $n < 5; $n++) {
        $procs[] = proc_open(['php', $rig->root . '/child.php', json_encode(['handoff_id' => '71'] + $base)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$n]);
    }
    $replies = [];
    foreach ($procs as $n => $proc) {
        $replies[] = json_decode((string)stream_get_contents($pipes[$n][1]), true) ?: ['ok' => null, 'raw' => 'unparsable'];
        fclose($pipes[$n][1]);
        fclose($pipes[$n][2]);
        proc_close($proc);
    }
    @unlink($rig->root . '/slow-t');
    assert_equal(5, count(array_filter($replies, static fn (array $x): bool => ($x['ok'] ?? null) === true)), 'all five requests answer ok');
    assert_equal(1, count(array_filter($replies, static fn (array $x): bool => ($x['existing'] ?? null) === false)), 'exactly one of them created the job');
    assert_equal(1, count(array_unique(array_map(static fn (array $x): mixed => $x['job'] ?? null, $replies))), 'all name the same job');
    assert_equal(1, $atCreates(), 'at -t ran once');
    assert_equal(1, count(array_filter(explode("\n", (string)file_get_contents($rig->dir . '/queue.txt')))), 'one row in the queue');

    echo "A failure from at is reported\n";
    $rig->reset();
    touch($rig->root . '/fail-t');
    $r = $act($ctx + $base);
    assert_equal(false, $r['ok'] ?? null, 'at failing: not ok');
    assert_true(str_contains($msg($r), 'cannot create job') || str_contains($msg($r), 'at'), 'at failing: a message is given');
    assert_true(!array_key_exists('job', $r) || $r['job'] === null, 'at failing: no job id is claimed');
    assert_equal('', trim((string)file_get_contents($rig->dir . '/queue.txt')), 'at failing: nothing is queued');
    @unlink($rig->root . '/fail-t');
    $retry = $act($ctx + $base);
    assert_equal([true, false], [$retry['ok'] ?? null, $retry['existing'] ?? null], 'after the failure a retry schedules normally, nothing was remembered');

    echo "Dismissing an offer queues nothing and cancels nothing\n";
    $rig->reset();
    $q = $act($ctx + $base);
    $before = $rig->calls();
    $dismiss = $act(['action' => 'flow_offer_dismiss', 'self' => 'claude-headless-w2']);
    assert_true(is_array($dismiss), 'flow_offer_dismiss answers');
    assert_equal('', $rig->removed(), 'no job was removed');
    assert_equal($before, $rig->calls(), 'the dismissal ran no at command at all');
    assert_true(isset($q['job']), 'the earlier job is untouched');
    assert_equal(1, $atCreates(), 'still exactly one created job');
} finally {
    $rig->cleanup();
}

test_exit();
