<?php

declare(strict_types=1);

// Step 5 of session-flow: HostAgent\SessionFlow\FlowService::rotate_request (host action flow_rotate_request).
// The offer tap with no handoff: send "/rotate ..." to the source session as one turn.
// Written before the implementation from the Contract only: fails until the method exists.
//
// Signature and deps pinned here:
//   FlowService::rotate_request(array $request, array $deps): array
//   request  session   string  the source session name (required)
//            agent, model, effort, profile   optional receiver arguments of the /rotate text; they go through
//                       PromptBuilder::rotateRequest($receiver = agent, $model, $effort, $profile), whose token rules apply;
//                       an absent agent means the source's own agent
//   deps     clock, facts (as in test_session_flow_service.php; source status must be 'idle'), launch
//            launch    callable(array $request): array, the same injected launch AdviseRunner uses for a resume:
//                       called once with agent, workdir (and profile for claude) of the source,
//                       agent_session_id of the source and prompt = the /rotate text
//   Reply: ['ok' => true, 'session' => source name, 'prompt' => the text sent]; failures ['ok' => false, 'message' => string].

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
$dir = sys_get_temp_dir() . '/sessioneer-test-flow-rotate-request-' . getmypid();
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

$NOW = 2_000_000;
$thresholds = Thresholds::fromFile($dir . '/none.conf');
$UUID = '22222222-2222-4222-8222-222222222222';
foreach (['rr-idle' => 'idle', 'rr-working' => 'working', 'rr-blocked' => 'blocked'] as $name => $status) {
    SidecarStore::write_sidecar($name, ['workdir' => '/w/' . $name, 'agent_session_id' => $UUID, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work']);
    SessionStatusStore::write_status($name, ['status' => $status]);
}
$facts = static fn (string $status): array => ['context_tokens' => 260000, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => $status]];
$known = ['rr-idle' => $facts('idle'), 'rr-working' => $facts('working'), 'rr-blocked' => $facts('blocked')];

$make = static function (array $known, array $reply = ['ok' => true, 'name' => 'rr-idle', 'message' => '']) use ($NOW, $thresholds): array {
    $log = new stdClass();
    $log->launch = [];
    $log->forbidden = [];
    return [[
        'clock' => static fn (): int => $NOW,
        'facts' => static fn (string $s, array $row): ?array => $known[$s] ?? null,
        'quota' => static fn (string $k): ?array => null,
        'receivers' => ['codex/main'],
        'thresholds' => $thresholds,
        'launch' => static function (array $req) use ($log, $reply): array {
            $log->launch[] = $req;
            return $reply;
        },
        'archive' => static function () use ($log): array {
            $log->forbidden[] = 'archive';
            return ['ok' => true];
        },
    ], $log];
};

echo "rotate_request: happy path\n";
[$deps, $log] = $make($known);
$r = FlowService::rotate_request(['session' => 'rr-idle', 'agent' => 'codex', 'model' => 'gpt-6', 'effort' => 'low', 'profile' => 'main'], $deps);
$expected = PromptBuilder::rotateRequest('codex', 'gpt-6', 'low', 'main');
assert_equal(true, $r['ok'] ?? null, 'ok');
assert_equal(1, count($log->launch), 'exactly one turn is sent');
$req = $log->launch[0] ?? [];
assert_equal($expected, $req['prompt'] ?? null, 'the turn is PromptBuilder::rotateRequest of the receiver arguments');
assert_equal($UUID, $req['agent_session_id'] ?? null, 'it is sent to the source conversation (a resume turn), not a new session');
assert_equal(['claude', 'work', '/w/rr-idle'], [$req['agent'] ?? null, $req['profile'] ?? null, $req['workdir'] ?? null], 'it uses the source agent, profile and workdir');
assert_equal('rr-idle', $r['session'] ?? null, 'the reply names the source session');
assert_equal($expected, $r['prompt'] ?? null, 'the reply carries the text sent');
assert_equal([], $log->forbidden, 'the source is not archived');

echo "rotate_request: defaults\n";
[$deps, $log] = $make($known);
$r = FlowService::rotate_request(['session' => 'rr-idle'], $deps);
assert_equal(true, $r['ok'] ?? null, 'only a session is enough');
assert_true(str_starts_with((string) ($log->launch[0]['prompt'] ?? ''), '/rotate ') && str_contains((string) ($log->launch[0]['prompt'] ?? ''), 'agent=claude'), 'an absent agent means the source agent');

echo "rotate_request: refused\n";
foreach (['rr-working', 'rr-blocked'] as $busy) {
    [$deps, $log] = $make($known);
    $r = FlowService::rotate_request(['session' => $busy], $deps);
    assert_equal(false, $r['ok'] ?? null, "{$busy}: a source that is not idle is refused");
    assert_true(is_string($r['message'] ?? null) && $r['message'] !== '', "{$busy}: the refusal says why");
    assert_equal([], $log->launch, "{$busy}: nothing was sent");
}
$bad = [
    'no session' => [],
    'empty session' => ['session' => ''],
    'unknown session' => ['session' => 'nobody'],
    'agent with a space' => ['session' => 'rr-idle', 'agent' => 'codex now'],
    'model with a newline' => ['session' => 'rr-idle', 'model' => "a\nb"],
    'effort with a slash command' => ['session' => 'rr-idle', 'effort' => '/exit'],
    'profile with a shell char' => ['session' => 'rr-idle', 'profile' => 'x;rm'],
];
[$deps, $log] = $make($known);
foreach ($bad as $label => $request) {
    $r = FlowService::rotate_request($request, $deps);
    assert_equal(false, $r['ok'] ?? null, "{$label} is a handled error");
    assert_true(is_string($r['message'] ?? null) && $r['message'] !== '', "{$label} has a message");
}
assert_equal([], $log->launch, 'nothing invalid was sent');

echo "rotate_request: double tap sends once\n";
SessionOfferStore::post('rr-idle', 'hook', ['state' => 'ROTATE'], null, null, null, $NOW - 5);
[$deps, $log] = $make($known);
$a = FlowService::rotate_request(['session' => 'rr-idle'], $deps);
$b = FlowService::rotate_request(['session' => 'rr-idle'], $deps);
assert_equal([true, false], [$a['ok'] ?? null, $b['ok'] ?? null], 'the second tap on the same offer is refused');
assert_equal(1, count($log->launch), 'two taps send /rotate once');
assert_equal(null, SessionOfferStore::get('rr-idle', $NOW), 'the claimed offer is no longer live');
[$deps, $log] = $make($known);
assert_equal(true, FlowService::rotate_request(['session' => 'rr-idle'], $deps)['ok'] ?? null, 'with no live offer the request proceeds');
SessionOfferStore::post('rr-working', 'hook', ['state' => 'ROTATE'], null, null, null, $NOW - 5);
FlowService::rotate_request(['session' => 'rr-working'], $deps);
assert_true(SessionOfferStore::get('rr-working', $NOW) !== null, 'a refused (not idle) request leaves the offer live');

echo "rotate_request: a failed send\n";
[$deps, $log] = $make($known, ['ok' => false, 'name' => null, 'message' => 'session is gone']);
$r = FlowService::rotate_request(['session' => 'rr-idle'], $deps);
assert_equal(false, $r['ok'] ?? null, 'a failed send is a failed request');
assert_true(str_contains((string) ($r['message'] ?? ''), 'session is gone'), 'the sender message is passed on');

echo "ok\n";
test_exit();
