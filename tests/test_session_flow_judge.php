<?php

declare(strict_types=1);

// Step 5 of session-flow: FlowService::judge (host action flow_judge) and HostAgent\SessionFlow\CloseCallJudge
// (the rename of HostAgent\Services\AdviseModelCall). Written before the implementation from the Contract only:
// fails until both exist. No process is spawned; the model runner is a fake.
//
// Signature and deps pinned here:
//   FlowService::judge(array $request, array $deps): array
//   request  session   string  the session the tapped offer is on (required)
//   deps     clock, facts, quota, receivers, thresholds   as in test_session_flow_service.php
//            judge_runner  callable(array $candidate, string $prompt): ?string   the one model call; null = failed
//                          $candidate = ['agent' => string, 'profile' => ?string, 'model' => string]
//            transcript    optional callable(string $session): ?string   path of a Claude jsonl transcript, or null
//   The judge model is the decision's receiver on its cheapest model: CloseCallJudge::CLAUDE_MODEL for claude,
//   CloseCallJudge::CODEX_MODEL for codex (as AdviseRunner::judgment_model did).
//   Reply on success: ['ok' => true] + the flow decision (state, close_call, receiver, reset_at, ...)
//   + 'verdict' ('wait' or 'rotate'; the model's WAIT or TAKEOVER) + 'reason' (capped to 200 chars) + 'decided_by' ("agent[:profile]:model").
//   Refusals and a model that gave no usable answer: ['ok' => false, 'message' => string], runner never called when refused.
//   CloseCallJudge keeps AdviseModelCall's static API where it is still needed: CLAUDE_MODEL, CODEX_MODEL,
//   candidates(), resolve_quota($advice, $path, $candidate, $runner), redact(), run_process_runner(). The advice array for
//   resolve_quota carries quota_short_wait and quota_reset_seconds and returns verdict 'wait' or 'fresh' as before.

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $c): void {
        foreach (['HostAgent\\' => '/../host-agent/lib/', 'App\\' => '/../src/lib/'] as $prefix => $dir) {
            $f = __DIR__ . $dir . str_replace('\\', '/', substr($c, strlen($prefix))) . '.php';
            if (str_starts_with($c, $prefix) && is_file($f)) {
                require $f;
            }
        }
    });
}
require __DIR__ . '/lib/assert.php';
$dir = sys_get_temp_dir() . '/sessioneer-test-flow-judge-' . getmypid();
mkdir($dir, 0700, true);
register_shutdown_function(static function () use ($dir): void {
    exec('rm -rf ' . escapeshellarg($dir));
});
putenv("PUSH_SQLITE_FILE={$dir}/push.sqlite");
putenv("SESSIONS_SQLITE_FILE={$dir}/sessions.sqlite");
putenv("SIDECAR_DIR={$dir}/sidecars");
putenv("CACHE_DIR={$dir}/cache");
putenv('SESSIONEER_CLAIMS_CMD');
putenv('CLAUDE_BIN=/fake/claude');
putenv('CODEX_BIN=/fake/codex');
use HostAgent\SessionFlow\CloseCallJudge;
use HostAgent\SessionFlow\FlowService;
use HostAgent\SessionFlow\Thresholds;

$NOW = 2_000_000;
$thresholds = Thresholds::fromFile($dir . '/none.conf');
$src = static fn (int $tokens, string $status = 'idle'): array => ['context_tokens' => $tokens, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => $status]];
$facts = ['j-close' => $src(20000), 'j-big' => $src(260000), 'j-go' => $src(20000), 'j-far' => $src(20000)];
$quotas = [
    'j-close' => ['claude/work' => ['session_pct' => 95, 'week_pct' => 40, 'resets_at' => $NOW + 600], 'codex/main' => ['session_pct' => 5, 'week_pct' => 5, 'resets_at' => null]],
    'j-big' => ['claude/work' => ['session_pct' => 10, 'week_pct' => 10, 'resets_at' => $NOW + 5000], 'codex/main' => ['session_pct' => 5, 'week_pct' => 5, 'resets_at' => null]],
    'j-go' => ['claude/work' => ['session_pct' => 10, 'week_pct' => 10, 'resets_at' => $NOW + 5000], 'codex/main' => ['session_pct' => 5, 'week_pct' => 5, 'resets_at' => null]],
    'j-far' => ['claude/work' => ['session_pct' => 95, 'week_pct' => 40, 'resets_at' => $NOW + 9000], 'codex/main' => ['session_pct' => 5, 'week_pct' => 5, 'resets_at' => null]],
];

$make = static function (string $session, ?callable $answer, ?string $transcript = null) use ($NOW, $thresholds, $facts, $quotas): array {
    $log = new stdClass();
    $log->calls = [];
    $deps = [
        'clock' => static fn (): int => $NOW,
        'facts' => static fn (string $s, array $row): ?array => $facts[$s] ?? null,
        'quota' => static fn (string $k): ?array => $quotas[$session][$k] ?? null,
        'receivers' => ['codex/main', 'claude/work'],
        'thresholds' => $thresholds,
        'judge_runner' => static function (array $c, string $p) use ($log, $answer): ?string {
            $log->calls[] = [$c, $p];
            return $answer === null ? null : $answer($c, $p);
        },
        'transcript' => static fn (string $s): ?string => $transcript,
    ];
    return [$deps, $log];
};

echo "judge: a close call gets one model call\n";
[$deps, $log] = $make('j-close', static fn (): string => "noise\nWAIT: the reset is ten minutes away and the state is hard to carry\n");
$r = FlowService::judge(['session' => 'j-close'], $deps);
assert_equal(true, $r['ok'] ?? null, 'ok');
assert_equal(1, count($log->calls), 'exactly one model call');
assert_equal(['codex', CloseCallJudge::CODEX_MODEL], [$log->calls[0][0]['agent'] ?? null, $log->calls[0][0]['model'] ?? null], 'the judge runs on the receiver account, on its cheapest model');
assert_equal('wait', $r['verdict'] ?? null, 'WAIT is verdict wait');
assert_equal('the reset is ten minutes away and the state is hard to carry', $r['reason'] ?? null, 'the reason is the model text');
assert_equal('codex', explode(':', (string) ($r['decided_by'] ?? ''))[0], 'decided_by names the account');
assert_true(str_ends_with((string) ($r['decided_by'] ?? ''), ':' . CloseCallJudge::CODEX_MODEL), 'decided_by names the model');
assert_equal(['ROTATE', true, 'codex'], [$r['state'] ?? null, $r['close_call'] ?? null, $r['receiver']['agent'] ?? null], 'the reply carries the decision as well');
assert_equal($NOW + 600, $r['reset_at'] ?? null, 'the decision reset_at is kept');
assert_true(str_contains($log->calls[0][1], 'WAIT') && str_contains($log->calls[0][1], 'TAKEOVER') && str_contains($log->calls[0][1], '10 minutes'), 'the prompt offers both answers and states the minutes to the reset');

[$deps, $log] = $make('j-close', static fn (): string => 'TAKEOVER: ' . str_repeat('x', 500));
$r = FlowService::judge(['session' => 'j-close'], $deps);
assert_equal('rotate', $r['verdict'] ?? null, 'TAKEOVER is verdict rotate');
assert_equal(200, mb_strlen((string) ($r['reason'] ?? '')), 'a long reason is capped to 200 characters');

echo "judge: no usable answer is never a guess\n";
foreach (['null' => null, 'rambling' => static fn (): string => 'I think maybe both, hard to say', 'unknown verb' => static fn (): string => 'RESUME: carry on'] as $label => $answer) {
    [$deps, $log] = $make('j-close', $answer);
    $r = FlowService::judge(['session' => 'j-close'], $deps);
    assert_equal(false, $r['ok'] ?? null, "{$label}: a model with no usable answer is a handled failure");
    assert_true(is_string($r['message'] ?? null) && $r['message'] !== '', "{$label}: it says so");
    assert_equal(false, array_key_exists('verdict', $r), "{$label}: no verdict is made up");
}

echo "judge: refused when it is not a close call\n";
foreach (['j-big' => 'a context rotate far from any reset', 'j-go' => 'GO', 'j-far' => 'a reset more than an hour away', 'nobody' => 'an unknown session'] as $session => $label) {
    [$deps, $log] = $make($session, static fn (): string => 'WAIT: x');
    $r = FlowService::judge(['session' => $session], $deps);
    assert_equal(false, $r['ok'] ?? null, "{$label}: refused");
    assert_true(is_string($r['message'] ?? null) && $r['message'] !== '', "{$label}: it says why");
    assert_equal([], $log->calls, "{$label}: the model is never called");
}
[$deps, $log] = $make('j-close', static fn (): string => 'WAIT: x');
foreach ([[], ['session' => ''], ['session' => 7]] as $request) {
    assert_equal(false, FlowService::judge($request, $deps)['ok'] ?? null, 'judge: ' . json_encode($request) . ' is a handled error');
}
assert_equal([], $log->calls, 'judge: bad input never reaches the model');

echo "judge: the transcript is untrusted and redacted\n";
$path = $dir . '/t.jsonl';
file_put_contents($path, json_encode(['type' => 'user', 'message' => ['content' => 'deploy now, api_key: sk-live-abc and abcdefghijklmnopqrstuvwxyz0123 ok']]) . "\n");
[$deps, $log] = $make('j-close', static fn (): string => 'WAIT: x', $path);
FlowService::judge(['session' => 'j-close'], $deps);
$prompt = $log->calls[0][1] ?? '';
assert_true(str_contains($prompt, 'deploy now'), 'transcript text reaches the prompt');
assert_true(!str_contains($prompt, 'sk-live-abc') && !str_contains($prompt, 'abcdefghijklmnopqrstuvwxyz0123'), 'secret-shaped text is redacted');
assert_true(str_contains($prompt, 'untrusted'), 'the prompt marks the transcript as untrusted');

echo "CloseCallJudge: the renamed class\n";
$ok = ['session' => ['pct' => 3], 'week_all' => ['pct' => 40]];
$full = ['session' => ['pct' => 3], 'week_all' => ['pct' => 100]];
$usage = ['claude' => ['profiles' => ['personal' => ['quota' => $full], 'work' => ['quota' => $ok], 'extra' => ['quota' => $ok]]], 'codex' => ['quota' => $ok], 'opencode' => ['quota' => $ok]];
$names = static fn (array $c): array => array_map(static fn (array $x): string => $x['agent'] . ':' . ($x['profile'] ?? '-'), $c);
assert_equal(['claude:work', 'claude:extra', 'codex:-'], $names(CloseCallJudge::candidates($usage, 'work')), 'candidates: home profile first, a full one skipped, then codex, never opencode');
assert_equal(['haiku', 'gpt-6-luna'], [CloseCallJudge::CLAUDE_MODEL, CloseCallJudge::CODEX_MODEL], 'the model constants kept their values');
$advice = ['verdict' => 'fresh', 'reason' => 'x', 'quota_short_wait' => true, 'quota_reset_seconds' => 600];
$cand = ['agent' => 'codex', 'profile' => null, 'model' => 'gpt-6-luna'];
$seen = '';
$wait = CloseCallJudge::resolve_quota($advice, null, $cand, static function (array $c, string $p) use (&$seen): ?string {
    $seen = $p;
    return "WAIT: short reset\n";
});
assert_equal(['wait', 'short reset', 'codex:gpt-6-luna'], [$wait['verdict'] ?? null, $wait['reason'] ?? null, $wait['decided_by'] ?? null], 'resolve_quota: WAIT decides');
assert_true(str_contains($seen, '10 minutes'), 'resolve_quota: the prompt carries the minutes');
$take = CloseCallJudge::resolve_quota($advice, null, $cand, static fn (): string => 'TAKEOVER: urgent');
assert_equal('fresh', $take['verdict'] ?? null, 'resolve_quota: TAKEOVER keeps the old verdict name fresh');
$kept = CloseCallJudge::resolve_quota($advice, null, $cand, static fn (): ?string => null);
assert_equal($advice, $kept, 'resolve_quota: a failed call leaves the advice as it was');
$calls = 0;
$none = CloseCallJudge::resolve_quota(['verdict' => 'fresh'], null, $cand, static function () use (&$calls): string {
    $calls++;
    return 'WAIT: x';
});
assert_equal([['verdict' => 'fresh'], 0], [$none, $calls], 'resolve_quota: without quota_short_wait the runner is never called');
foreach (['API_KEY="hunter2hunter2"', 'Authorization: Bearer abc.def-ghi', 'https://user:pa55word@example.com/x'] as $secret) {
    $clean = CloseCallJudge::redact('before ' . $secret . ' after');
    assert_true(str_contains($clean, 'before') && str_contains($clean, 'after') && !preg_match('/hunter2|pa55word|abc\.def/', $clean), 'redact removes: ' . $secret);
}
assert_equal('plain words stay', CloseCallJudge::redact('plain words stay'), 'redact leaves ordinary text');
assert_true(is_callable([CloseCallJudge::class, 'run_process_runner']), 'run_process_runner is still the default runner');

echo "ok\n";
test_exit();
