<?php

declare(strict_types=1);

// Step 5 of session-flow: HostAgent\SessionFlow\FlowService::rotate (host action flow_rotate).
// Written before the implementation from the Contract only: fails until the method exists.
// Isolation: temp SQLite files and sidecar dir, a fake launch and a fake claims runner; no process,
// session or network is touched.
//
// Signature and deps pinned here (the Contract names the behaviour, not the shapes):
//   FlowService::rotate(array $request, array $deps): array
//   request  session          string  the source session name (required)
//            handoff_id       ?string digits only  \ exactly one of the three
//            plan_id          ?string digits only  |
//            prompt           ?string free text, 1..8000 chars -> PromptBuilder::selfContained()
//            agent, profile, model, effort, workdir, set_name   optional receiver overrides
//            dry_run          bool    build and check everything, launch nothing, consume nothing
//   deps     clock            callable(): int
//            facts            callable(string $session, array $row): ?array  as in test_session_flow_service.php;
//                               null = unknown session, else ['source' => ['agent','profile','status'], ...]
//            launch           callable(array $request): array  like AdviseRunner's deps['launch'];
//                               returns ['ok' => bool, 'name' => string, 'message' => string]
//            runner           callable(string $command, string $conversationId): array{exit:int, stdout:string}
//                               runs SESSIONEER_CLAIMS_CMD; stdout is JSON, a non-empty list/array = claims held
//   Launch request keys: agent, workdir, prompt, plus profile (claude only), effort, set_name,
//   starting_mode, allowed_tools, disallowed_tools, no_tools as AdviseRunner::act builds them.
//   Reply: ['ok' => true, 'session' => receiver name, 'source' => source name, 'warnings' => list<string>,
//   'prompt' => string] ; with dry_run also 'dry_run' => true. Handled failures: ['ok' => false, 'message' => string].
//   Sidecar fields: SidecarStore::read_sidecar() exposes 'rotated_from' (receiver) and 'rotated_to' (source).
//   A deps 'archive' or 'kill' callable must never be called (the receiver archives the source, never rotate).
//   Offer: when a live SessionOfferStore offer exists for the source it is claimed with claimLaunch();
//   no offer means a plain (CLI) rotate that proceeds.

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
$dir = sys_get_temp_dir() . '/sessioneer-test-flow-rotate-' . getmypid();
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
use HostAgent\SessionFlow\FlowService;
use HostAgent\SessionFlow\PromptBuilder;
use HostAgent\SessionFlow\Thresholds;
use HostAgent\Stores\SessionOfferStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SqliteDb;

$NOW = 2_000_000;
$thresholds = Thresholds::fromFile($dir . '/none.conf');
$UUID = '11111111-1111-4111-8111-111111111111';

$source = static function (string $name, array $extra = [], array $status = ['status' => 'idle', 'mode' => 'plan']) use ($UUID): void {
    SidecarStore::write_sidecar($name, ['workdir' => '/w/' . $name, 'agent_session_id' => $UUID, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work'] + $extra);
    SessionStatusStore::write_status($name, $status);
    if (isset($extra['effort'])) {
        SidecarStore::set_effort($name, $extra['effort']);
    }
    if (isset($extra['allowed_tools']) || isset($extra['disallowed_tools'])) {
        SidecarStore::set_tool_rules($name, $extra['allowed_tools'] ?? null, $extra['disallowed_tools'] ?? null);
    }
};
$factsFor = static fn (string $agent = 'claude', string $profile = 'work', string $status = 'idle'): array => [
    'context_tokens' => 260000, 'idle_seconds' => 60, 'source' => ['agent' => $agent, 'profile' => $profile, 'status' => $status],
];

/** @return array{0: array<string, mixed>, 1: object} deps plus a call log */
$make = static function (array $known, ?callable $runner = null, ?callable $launch = null) use ($NOW, $thresholds): array {
    $log = new stdClass();
    $log->launch = [];
    $log->runner = [];
    $log->forbidden = [];
    $n = 0;
    $deps = [
        'clock' => static fn (): int => $NOW,
        'facts' => static fn (string $s, array $row): ?array => $known[$s] ?? null,
        'quota' => static fn (string $k): ?array => null,
        'receivers' => ['codex/main', 'claude/work'],
        'thresholds' => $thresholds,
        'launch' => static function (array $req) use ($log, &$n, $launch): array {
            $log->launch[] = $req;
            if ($launch !== null) {
                return $launch($req, $n);
            }
            $n++;
            $name = 'recv-' . $n;
            SidecarStore::write_sidecar($name, ['workdir' => $req['workdir'] ?? null, 'agent' => $req['agent'] ?? 'claude', 'runtime' => 'headless']);
            return ['ok' => true, 'name' => $name, 'message' => ''];
        },
        'runner' => static function (string $command, string $conv) use ($log, $runner): array {
            $log->runner[] = [$command, $conv];
            return $runner !== null ? $runner($command, $conv) : ['exit' => 0, 'stdout' => '[]'];
        },
        'archive' => static function () use ($log): array {
            $log->forbidden[] = 'archive';
            return ['ok' => true];
        },
        'kill' => static function () use ($log): array {
            $log->forbidden[] = 'kill';
            return ['ok' => true];
        },
    ];
    return [$deps, $log];
};
$reset = static function () use ($dir): void {
    foreach (glob($dir . '/*.sqlite*') ?: [] as $f) {
        @unlink($f);
    }
    SqliteDb::reset_connections_for_tests();
};
$known = ['src-a' => $factsFor(), 'src-b' => $factsFor(), 'src-codex' => $factsFor('codex', 'default'), 'src-big' => $factsFor()];
$source('src-a', ['effort' => 'high']);
$source('src-b');
$source('src-big', ['allowed_tools' => 'Read,Grep', 'disallowed_tools' => 'Bash']);
$source('src-codex', ['agent' => 'codex', 'profile' => null]);

echo "rotate: happy path with a handoff\n";
[$deps, $log] = $make($known);
$r = FlowService::rotate(['session' => 'src-a', 'handoff_id' => '123'], $deps);
assert_equal(true, $r['ok'] ?? null, 'rotate: ok');
assert_equal('recv-1', $r['session'] ?? null, 'rotate: the reply names the receiver session');
assert_equal(1, count($log->launch), 'rotate: exactly one launch');
$req = $log->launch[0] ?? [];
assert_equal(PromptBuilder::handoff('123'), $req['prompt'] ?? null, 'rotate: the prompt is PromptBuilder::handoff');
assert_equal(['claude', 'work', '/w/src-a'], [$req['agent'] ?? null, $req['profile'] ?? null, $req['workdir'] ?? null], 'rotate: receiver defaults to the source agent, profile and workdir');
assert_equal('high', $req['effort'] ?? null, 'rotate: receiver defaults to the source effort');
assert_equal(false, array_key_exists('agent_session_id', $req), 'rotate: the receiver is a new session, never a resume of the source');
assert_equal('plan', $req['starting_mode'] ?? null, 'rotate: the receiver starts in the source mode');
assert_equal(PromptBuilder::handoff('123'), $r['prompt'] ?? null, 'rotate: the reply carries the prompt');
assert_true(is_array($r['warnings'] ?? null), 'rotate: the reply has a warnings list');
assert_true(count(array_filter((array) ($r['warnings'] ?? []), static fn ($w): bool => is_string($w) && str_contains($w, 'SESSIONEER_CLAIMS_CMD'))) === 1, 'rotate: SESSIONEER_CLAIMS_CMD unset gives one warning naming it');
assert_equal([], $log->runner, 'rotate: no claims command, no runner call');
assert_equal('src-a', SidecarStore::read_sidecar('recv-1')['rotated_from'] ?? null, 'rotate: rotated_from is written on the receiver');
assert_equal('recv-1', SidecarStore::read_sidecar('src-a')['rotated_to'] ?? null, 'rotate: rotated_to is written on the source');
assert_true(SidecarStore::read_sidecar('src-a') !== null && (SessionStatusStore::read_status('src-a')['status'] ?? null) === 'idle', 'rotate: the source is untouched (still tracked, still idle)');
assert_equal([], $log->forbidden, 'rotate: the source is never archived or killed');
SidecarStore::write_sidecar('src-a', ['workdir' => '/w/src-a', 'agent_session_id' => $UUID, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work', 'title' => 'renamed']);
assert_equal('recv-1', SidecarStore::read_sidecar('src-a')['rotated_to'] ?? null, 'rotate: a later sidecar write (headless sync) keeps rotated_to');

echo "rotate: plan, prompt and launch options\n";
[$deps, $log] = $make($known);
$r = FlowService::rotate(['session' => 'src-b', 'plan_id' => '456'], $deps);
assert_equal(PromptBuilder::plan('456'), $log->launch[0]['prompt'] ?? null, 'rotate: plan_id gives PromptBuilder::plan');
$r = FlowService::rotate(['session' => 'src-b', 'prompt' => 'Fix the parser, tests are in t/.'], $deps);
assert_equal(PromptBuilder::selfContained('Fix the parser, tests are in t/.'), $log->launch[1]['prompt'] ?? null, 'rotate: prompt gives PromptBuilder::selfContained');
$r = FlowService::rotate(['session' => 'src-b', 'handoff_id' => '5', 'set_name' => 'Next leg', 'workdir' => '/w/other'], $deps);
assert_equal(['Next leg', '/w/other'], [$log->launch[2]['set_name'] ?? null, $log->launch[2]['workdir'] ?? null], 'rotate: set_name and workdir overrides reach the launch');
$r = FlowService::rotate(['session' => 'src-b', 'handoff_id' => '5', 'profile' => 'personal', 'model' => 'sonnet', 'effort' => 'low'], $deps);
assert_equal(['personal', 'sonnet', 'low'], [$log->launch[3]['profile'] ?? null, $log->launch[3]['model'] ?? null, $log->launch[3]['effort'] ?? null], 'rotate: profile, model and effort overrides reach the launch');
assert_equal(4, count(array_filter(array_column($log->launch, 'prompt'))), 'rotate: every launch carried a prompt');

echo "rotate: permission limits match AdviseRunner\n";
[$deps, $log] = $make($known);
$r = FlowService::rotate(['session' => 'src-big', 'handoff_id' => '7'], $deps);
assert_equal(['Read,Grep', 'Bash'], [$log->launch[0]['allowed_tools'] ?? null, $log->launch[0]['disallowed_tools'] ?? null], 'rotate: same-agent receiver keeps the source tool rules');
$r = FlowService::rotate(['session' => 'src-big', 'handoff_id' => '7', 'agent' => 'codex'], $deps);
assert_equal(false, $r['ok'] ?? null, 'rotate: tool rules cannot cross agents, so a cross-agent rotate of a restricted source is refused');
assert_equal(1, count($log->launch), 'rotate: the refused cross-agent rotate launched nothing');
$r = FlowService::rotate(['session' => 'src-b', 'handoff_id' => '7', 'agent' => 'codex'], $deps);
assert_equal(true, $r['ok'] ?? null, 'rotate: an unrestricted source may rotate to another agent');
assert_equal(['codex', false, 'plan'], [$log->launch[1]['agent'] ?? null, array_key_exists('profile', $log->launch[1]), $log->launch[1]['starting_mode'] ?? null], 'rotate: cross-agent launch has no claude profile and the mapped starting mode');
$r = FlowService::rotate(['session' => 'src-b', 'handoff_id' => '7', 'agent' => 'codex', 'model' => 'gpt-6'], $deps);
assert_equal(false, $r['ok'] ?? null, 'rotate: a cross-agent rotate cannot reuse a model');
$r = FlowService::rotate(['session' => 'src-b', 'handoff_id' => '7', 'agent' => 'codex', 'effort' => 'low'], $deps);
assert_equal(false, $r['ok'] ?? null, 'rotate: a cross-agent rotate cannot reuse an effort');
assert_equal(2, count($log->launch), 'rotate: the refused ones launched nothing');
$source('src-notools', ['no_tools' => true]);
SidecarStore::set_no_tools('src-notools');
[$deps, $log] = $make($known + ['src-notools' => $factsFor()]);
$r = FlowService::rotate(['session' => 'src-notools', 'handoff_id' => '7'], $deps);
assert_equal([false, 0], [$r['ok'] ?? null, count($log->launch)], 'rotate: a tool-less source is refused (the receiver could not read the handoff or move claims)');
$source('src-manual', [], ['status' => 'idle', 'mode' => 'manual']);
[$deps, $log] = $make($known + ['src-manual' => $factsFor()]);
FlowService::rotate(['session' => 'src-manual', 'handoff_id' => '7', 'agent' => 'codex'], $deps);
assert_equal(false, array_key_exists('starting_mode', $log->launch[0] ?? []), 'rotate: a mode the receiver cannot reproduce is not passed');

echo "rotate: invalid input is refused\n";
[$deps, $log] = $make($known);
$bad = [
    'no session' => ['handoff_id' => '1'],
    'empty session' => ['session' => '', 'handoff_id' => '1'],
    'unknown session' => ['session' => 'nobody', 'handoff_id' => '1'],
    'no input' => ['session' => 'src-a'],
    'two inputs' => ['session' => 'src-a', 'handoff_id' => '1', 'plan_id' => '2'],
    'handoff 12a' => ['session' => 'src-a', 'handoff_id' => '12a'],
    'handoff empty' => ['session' => 'src-a', 'handoff_id' => ''],
    'handoff space' => ['session' => 'src-a', 'handoff_id' => '1 2'],
    'handoff newline' => ['session' => 'src-a', 'handoff_id' => "1\n2"],
    'handoff int' => ['session' => 'src-a', 'handoff_id' => 12],
    'plan abc' => ['session' => 'src-a', 'plan_id' => 'abc'],
    'prompt empty' => ['session' => 'src-a', 'prompt' => ''],
    'prompt 8001' => ['session' => 'src-a', 'prompt' => str_repeat('x', 8001)],
    'unknown agent' => ['session' => 'src-a', 'handoff_id' => '1', 'agent' => 'bogus'],
];
foreach ($bad as $label => $request) {
    $r = FlowService::rotate($request, $deps);
    assert_equal(false, $r['ok'] ?? null, "rotate: {$label} is a handled error");
    assert_true(is_string($r['message'] ?? null) && $r['message'] !== '', "rotate: {$label} has a message");
}
assert_equal([], $log->launch, 'rotate: nothing invalid launched anything');
assert_equal('recv-1', SidecarStore::read_sidecar('src-a')['rotated_to'] ?? null, 'rotate: nothing invalid changed rotated_to (still the first receiver)');
$r = FlowService::rotate(['session' => 'src-a', 'prompt' => str_repeat('x', 8000)], $deps);
assert_equal(true, $r['ok'] ?? null, 'rotate: a prompt of exactly 8000 characters is accepted');

echo "rotate: claims check\n";
putenv('SESSIONEER_CLAIMS_CMD=/usr/bin/claims-of');
$source('src-c');
[$deps, $log] = $make(['src-c' => $factsFor()], static fn (): array => ['exit' => 0, 'stdout' => '[]']);
SessionOfferStore::post('src-c', 'hook', ['state' => 'ROTATE'], '9', null, null, $NOW - 5);
$r = FlowService::rotate(['session' => 'src-c', 'handoff_id' => '9'], $deps);
assert_equal(true, $r['ok'] ?? null, 'claims: an empty claims list lets the rotate through');
assert_equal([['/usr/bin/claims-of', $UUID]], $log->runner, 'claims: the runner gets the command and the source conversation id');
assert_equal(0, count(array_filter((array) ($r['warnings'] ?? []), static fn ($w): bool => is_string($w) && str_contains($w, 'SESSIONEER_CLAIMS_CMD'))), 'claims: no unset-warning when the command is set');
foreach (['held list' => ['exit' => 0, 'stdout' => '[{"id":1764}]'], 'held object' => ['exit' => 0, 'stdout' => '{"claims":[{"id":1764}]}'], 'command failed' => ['exit' => 1, 'stdout' => '']] as $label => $answer) {
    $reset();
    $source('src-c');
    SessionOfferStore::post('src-c', 'hook', ['state' => 'ROTATE'], '9', null, null, $NOW - 5);
    [$deps, $log] = $make(['src-c' => $factsFor()], static fn (): array => $answer);
    $r = FlowService::rotate(['session' => 'src-c', 'handoff_id' => '9'], $deps);
    assert_equal(false, $r['ok'] ?? null, "claims: {$label} refuses the rotate");
    assert_true(is_string($r['message'] ?? null) && $r['message'] !== '', "claims: {$label} says why");
    assert_equal([], $log->launch, "claims: {$label} launches nothing");
    assert_equal(null, SidecarStore::read_sidecar('src-c')['rotated_to'] ?? null, "claims: {$label} writes no rotated_to");
    assert_true(SessionOfferStore::get('src-c', $NOW) !== null, "claims: {$label} leaves the offer live for a retry");
}
$ok = false;
[$deps, $log] = $make(['src-c' => $factsFor()], static function () use (&$ok): array {
    return ['exit' => 0, 'stdout' => $ok ? '[]' : '[{"id":1}]'];
});
assert_equal(false, FlowService::rotate(['session' => 'src-c', 'handoff_id' => '9'], $deps)['ok'] ?? null, 'claims: held, refused');
$ok = true;
assert_equal(true, FlowService::rotate(['session' => 'src-c', 'handoff_id' => '9'], $deps)['ok'] ?? null, 'claims: released, the retry on the same offer goes through');
putenv('SESSIONEER_CLAIMS_CMD');

echo "rotate: double tap launches once\n";
$reset();
$source('src-d');
SessionOfferStore::post('src-d', 'hook', ['state' => 'ROTATE'], '9', null, null, $NOW - 5);
[$deps, $log] = $make(['src-d' => $factsFor()]);
$first = FlowService::rotate(['session' => 'src-d', 'handoff_id' => '9'], $deps);
$second = FlowService::rotate(['session' => 'src-d', 'handoff_id' => '9'], $deps);
assert_equal([true, false], [$first['ok'] ?? null, $second['ok'] ?? null], 'tap: the second tap on the same offer is refused');
assert_equal(1, count($log->launch), 'tap: exactly one launch for two taps');
assert_equal(null, SessionOfferStore::get('src-d', $NOW), 'tap: the launched offer is no longer live');
$source('src-e');
SessionOfferStore::post('src-e', 'agent', ['state' => 'ROTATE'], '9', null, 'go', $NOW - 5);
SessionOfferStore::dismiss('src-e', $NOW);
[$deps, $log] = $make(['src-e' => $factsFor()]);
$late = FlowService::rotate(['session' => 'src-e', 'handoff_id' => '9'], $deps);
assert_true(is_bool($late['ok'] ?? null), 'tap: a dismissed offer still gives a handled reply');

echo "rotate: dry run\n";
$reset();
$source('src-f');
SessionOfferStore::post('src-f', 'hook', ['state' => 'ROTATE'], '9', null, null, $NOW - 5);
[$deps, $log] = $make(['src-f' => $factsFor()]);
$r = FlowService::rotate(['session' => 'src-f', 'handoff_id' => '9', 'dry_run' => true], $deps);
assert_equal([true, true], [$r['ok'] ?? null, $r['dry_run'] ?? null], 'dry run: ok and flagged');
assert_equal(PromptBuilder::handoff('9'), $r['prompt'] ?? null, 'dry run: shows the prompt it would send');
assert_equal([], $log->launch, 'dry run: launches nothing');
assert_equal(null, SidecarStore::read_sidecar('src-f')['rotated_to'] ?? null, 'dry run: writes no rotated_to');
assert_true(SessionOfferStore::get('src-f', $NOW) !== null, 'dry run: does not consume the offer');
$r = FlowService::rotate(['session' => 'src-f', 'handoff_id' => '9', 'dry_run' => true, 'prompt' => 'x'], $deps);
assert_equal(false, $r['ok'] ?? null, 'dry run: invalid input is still refused');
$r = FlowService::rotate(['session' => 'src-f', 'handoff_id' => '9'], $deps);
assert_equal([true, 1], [$r['ok'] ?? null, count($log->launch)], 'dry run: the real rotate afterwards still launches');

echo "rotate: dry run skips the claims check; a busy source may rotate\n";
putenv('SESSIONEER_CLAIMS_CMD=/usr/bin/claims-of');
$reset();
$source('src-h');
$source('src-busy', [], ['status' => 'working', 'mode' => 'plan']);
[$deps, $log] = $make(['src-h' => $factsFor(), 'src-busy' => $factsFor('claude', 'work', 'working')], static fn (): array => ['exit' => 0, 'stdout' => '[{"id":1}]']);
$r = FlowService::rotate(['session' => 'src-h', 'handoff_id' => '9', 'dry_run' => true], $deps);
assert_equal([true, []], [$r['ok'] ?? null, $log->runner], 'dry run: held claims do not refuse it and the runner is not called');
[$deps, $log] = $make(['src-busy' => $factsFor('claude', 'work', 'working')], static fn (): array => ['exit' => 0, 'stdout' => '[]']);
assert_equal(true, FlowService::rotate(['session' => 'src-busy', 'handoff_id' => '9'], $deps)['ok'] ?? null, 'a source that is not idle may rotate');
putenv('SESSIONEER_CLAIMS_CMD');

echo "rotate: per-source lock\n";
$reset();
$source('src-l');
$source('src-m');
$inner = [];
$known2 = ['src-l' => $factsFor(), 'src-m' => $factsFor()];
[$deps, $log] = $make($known2, null, function (array $req, int &$n) use (&$deps, &$inner) {
    static $depth = 0;
    if ($depth === 0) {
        $depth = 1;
        $inner['same'] = FlowService::rotate(['session' => 'src-l', 'handoff_id' => '2'], $deps);
        $inner['other'] = FlowService::rotate(['session' => 'src-m', 'handoff_id' => '2'], $deps);
        $depth = 0;
    }
    $n++;
    $name = 'recv-l' . $n;
    SidecarStore::write_sidecar($name, ['workdir' => '/w', 'agent' => 'claude', 'runtime' => 'headless']);
    return ['ok' => true, 'name' => $name, 'message' => ''];
});
$r = FlowService::rotate(['session' => 'src-l', 'handoff_id' => '1'], $deps);
assert_equal(true, $r['ok'] ?? null, 'lock: the outer rotate succeeds');
assert_equal(false, $inner['same']['ok'] ?? null, 'lock: a rotate of the same source while one is running is refused');
assert_equal(true, $inner['other']['ok'] ?? null, 'lock: a rotate of another source is not blocked');
assert_equal(true, FlowService::rotate(['session' => 'src-l', 'handoff_id' => '3'], $deps)['ok'] ?? null, 'lock: the lock is released after the rotate finishes');

echo "rotate: launch failure\n";
$reset();
$source('src-g');
[$deps, $log] = $make(['src-g' => $factsFor()], null, static fn (array $req, int $n): array => ['ok' => false, 'name' => null, 'message' => 'no capacity']);
$r = FlowService::rotate(['session' => 'src-g', 'handoff_id' => '4'], $deps);
assert_equal(false, $r['ok'] ?? null, 'failure: a failed launch is a failed rotate');
assert_true(str_contains((string) ($r['message'] ?? ''), 'no capacity'), 'failure: the launcher message is passed on');
assert_equal(null, SidecarStore::read_sidecar('src-g')['rotated_to'] ?? null, 'failure: no rotated_to for a receiver that did not start');
assert_equal([], $log->forbidden, 'failure: the source is still not archived');
[$deps, $log] = $make(['src-g' => $factsFor()]);
assert_equal(true, FlowService::rotate(['session' => 'src-g', 'handoff_id' => '4'], $deps)['ok'] ?? null, 'failure: the lock was released, a retry works');

echo "ok\n";
test_exit();
