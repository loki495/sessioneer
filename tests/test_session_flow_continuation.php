<?php
declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';
use HostAgent\SessionFlow\FlowDecider;
use HostAgent\SessionFlow\FlowService;
use HostAgent\Stores\SessionOfferStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SessionStatusStore;

$dir = sys_get_temp_dir() . '/sessioneer-continuation-' . getmypid();
mkdir($dir, 0700, true);
foreach (['PUSH_SQLITE_FILE' => 'push.sqlite', 'SESSIONS_SQLITE_FILE' => 'sessions.sqlite', 'SIDECAR_DIR' => 'sidecars', 'CACHE_DIR' => 'cache'] as $key => $file) {
    putenv($key . '=' . $dir . '/' . $file);
}
register_shutdown_function(static function () use ($dir): void { exec('rm -rf ' . escapeshellarg($dir)); });
const CONT_NOW = 2000000;
$source = ['live' => true, 'status' => 'idle'];
$offer = ['expires_at' => CONT_NOW + 1, 'handoff_id' => '123', 'plan_id' => '456'];
$policyExists = method_exists(FlowDecider::class, 'continuation');
assert_true($policyExists, 'pure continuation policy exists');
if ($policyExists) {
    $call = static fn (array $s, ?array $o, ?array $c): array => FlowDecider::continuation($s, $o, $c, CONT_NOW);
    foreach (['handoff' => $offer, 'plan' => ['expires_at' => (string)(CONT_NOW + 1), 'plan_id' => '456']] as $name => $o) {
        $r = $call($source, $o, []);
        $keys = array_keys($r); sort($keys);
        assert_equal(['action', 'can_rotate', 'why'], $keys, "$name: exact policy fields");
        assert_equal(['rotate', true], [$r['action'] ?? null, $r['can_rotate'] ?? null], "$name: idle and claim-free recommends rotate");
        assert_true(is_string($r['why'] ?? null) && trim($r['why']) !== '' && !str_contains($r['why'], "\n"), "$name: human one-line reason");
    }
    $cases = [
        'ended' => [['live' => false, 'status' => 'idle'], $offer, []],
        'working' => [['live' => true, 'status' => 'working'], $offer, []],
        'blocked' => [['live' => true, 'status' => 'blocked'], $offer, []],
        'unknown status' => [['live' => true, 'status' => 'unknown'], $offer, []],
        'unknown claims' => [$source, $offer, null],
        'malformed claim' => [$source, $offer, ['bad']],
        'held claim' => [$source, $offer, [['id' => 1]]],
        'stale dead' => [$source, $offer, [['isCurrentlyAlive' => false]]],
        'stale expired' => [$source, $offer, [['isExpired' => true]]],
        'stale timestamp' => [$source, $offer, [['expiresAt' => CONT_NOW]]],
        'stale ISO mixed' => [$source, $offer, [['expires_at' => gmdate('c', CONT_NOW)], ['id' => 2]]],
        'missing offer' => [$source, null, []],
        'only prompt' => [$source, ['expires_at' => CONT_NOW + 1, 'prompt' => 'context'], []],
        'bad id' => [$source, ['expires_at' => CONT_NOW + 1, 'handoff_id' => '1x'], []],
        'expired' => [$source, ['expires_at' => CONT_NOW, 'handoff_id' => '1'], []],
        'dismissed' => [$source, $offer + ['dismissed_at' => CONT_NOW], []],
        'launched' => [$source, $offer + ['launched_at' => CONT_NOW], []],
    ];
    $reasons = [];
    foreach ($cases as $name => [$s, $o, $c]) {
        $r = $call($s, $o, $c);
        assert_equal(['takeover', false], [$r['action'] ?? null, $r['can_rotate'] ?? null], "$name: takeover");
        assert_true(is_string($r['why'] ?? null) && trim($r['why']) !== '' && !str_contains($r['why'], "\n"), "$name: readable reason");
        $reasons[$name] = $r['why'] ?? null;
    }
    foreach (['ended', 'working', 'unknown claims', 'held claim', 'stale dead', 'missing offer'] as $category) {
        assert_equal(1, count(array_filter($reasons, static fn ($v, $k): bool => in_array($k, ['ended', 'working', 'unknown claims', 'held claim', 'stale dead', 'missing offer'], true) && $v === $reasons[$category], ARRAY_FILTER_USE_BOTH)), "$category has a distinct reason");
    }
}
$serviceExists = method_exists(FlowService::class, 'continuation');
$clickExists = method_exists(FlowService::class, 'continue_rotation');
assert_true($serviceExists, 'continuation service exists');
assert_true($clickExists, 'fresh continue rotation service exists');
if ($serviceExists) {
    $id = '11111111-1111-4111-8111-111111111111';
    SidecarStore::write_sidecar('src', ['workdir' => '/tmp', 'agent_session_id' => $id, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work']);
    SessionStatusStore::write_status('src', ['status' => 'idle', 'mode' => 'plan']);
    SessionOfferStore::post('src', 'agent', ['state' => 'ROTATE'], '123', '456', 'ready', CONT_NOW);
    putenv('SESSIONEER_CLAIMS_CMD=/fixture/claims');
    $detail = ['ok' => true, 'working' => false]; $answer = ['exit' => 0, 'stdout' => '[]']; $calls = []; $effects = [];
    $deps = [
        'clock' => static fn (): int => CONT_NOW,
        'facts' => static fn (): array => ['context_tokens' => 100, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']],
        'detail' => static function ($s) use (&$detail, &$calls): array { $calls[] = ['detail', $s]; return $detail; },
        'runner' => static function ($cmd, $conversation) use (&$answer, &$calls): array { $calls[] = [$cmd, $conversation]; return $answer; },
        'launch' => static function ($r) use (&$effects): array { $effects[] = ['launch', $r]; if (!isset($r['agent_session_id'])) SidecarStore::write_sidecar('receiver', ['workdir' => '/tmp', 'agent' => 'claude', 'runtime' => 'headless']); return ['ok' => true, 'name' => 'receiver']; },
        'send' => static function (...$args) use (&$effects): array { $effects[] = ['send', $args]; return ['ok' => true]; },
        'kill' => static function () use (&$effects): array { $effects[] = ['kill']; return ['ok' => true]; },
        'archive' => static function () use (&$effects): array { $effects[] = ['archive']; return ['ok' => true]; },
    ];
    foreach ([[], ['session' => ''], ['session' => '  '], ['session' => []]] as $bad) {
        $r = FlowService::continuation($bad, $deps);
        assert_equal(false, $r['ok'] ?? null, 'invalid session rejected');
        assert_true(is_string($r['message'] ?? null) && $r['message'] !== '', 'invalid session explains error');
    }
    $calls = []; $before = SessionOfferStore::get('src', CONT_NOW);
    $r = FlowService::continuation(['session' => 'src'], $deps);
    assert_equal([true, 'rotate', true, '123'], [$r['ok'] ?? null, $r['action'] ?? null, $r['can_rotate'] ?? null, $r['handoff_id'] ?? null], 'fresh idle detail and empty claims recommend handoff');
    assert_equal([['detail', 'src'], ['/fixture/claims', $id]], $calls, 'detail and claims each read once');
    assert_equal($before, SessionOfferStore::get('src', CONT_NOW), 'recommendation does not consume offer');
    assert_equal([], $effects, 'recommendation has no side effects');
    foreach (['bad JSON' => ['exit' => 0, 'stdout' => '{'], 'null claims' => ['exit' => 0, 'stdout' => '{"claims":null}'], 'object' => ['exit' => 0, 'stdout' => '{}'], 'claims object' => ['exit' => 0, 'stdout' => '{"claims":{}}'], 'claims keyed object' => ['exit' => 0, 'stdout' => '{"claims":{"a":{}}}'], 'scalar' => ['exit' => 0, 'stdout' => 'true'], 'bad member' => ['exit' => 0, 'stdout' => '[1]'], 'nonzero' => ['exit' => 1, 'stdout' => '[]']] as $label => $answer) {
        $calls = []; $r = FlowService::continuation(['session' => 'src'], $deps);
        assert_equal(['takeover', false], [$r['action'] ?? null, $r['can_rotate'] ?? null], "$label: claims unknown, fail closed");
        assert_true(count($calls) <= 2, "$label: runner at most once");
    }
    $answer = ['exit' => 0, 'stdout' => '{"claims":[]}'];
    assert_equal('rotate', FlowService::continuation(['session' => 'src'], $deps)['action'] ?? null, 'object claims list accepted');
    putenv('SESSIONEER_CLAIMS_CMD'); $calls = [];
    assert_equal('takeover', FlowService::continuation(['session' => 'src'], $deps)['action'] ?? null, 'unset command leaves claims unknown');
    assert_equal([['detail', 'src']], $calls, 'unset command never runs claims');
    putenv('SESSIONEER_CLAIMS_CMD=/fixture/claims');
    SidecarStore::write_sidecar('no-id', ['workdir' => '/tmp', 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work']);
    $calls = [];
    assert_equal('takeover', FlowService::continuation(['session' => 'no-id'], $deps)['action'] ?? null, 'missing conversation id leaves claims unknown');
    assert_equal([['detail', 'no-id']], $calls, 'missing id never runs claims');
    foreach ([['ok' => false], ['ok' => true, 'status' => 'working'], ['ok' => true, 'working' => true], ['ok' => true, 'working' => true, 'blocked_reason' => 'permission']] as $detail) {
        $calls = []; $r = FlowService::continuation(['session' => 'src'], $deps);
        assert_equal('takeover', $r['action'] ?? null, 'ended or busy detail recommends takeover');
        if (($detail['ok'] ?? false) !== true) { assert_equal(1, count($calls), 'ended source does not read claims'); }
    }
    if ($clickExists) {
        $detail = ['ok' => true, 'status' => 'idle'];
        foreach (['replace', 'dismiss', 'remove'] as $change) {
            $name = 'race-' . $change;
            SidecarStore::write_sidecar($name, ['workdir' => '/tmp', 'agent_session_id' => $id, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work']);
            SessionStatusStore::write_status($name, ['status' => 'idle', 'mode' => 'plan']);
            SessionOfferStore::post($name, 'agent', ['state' => 'ROTATE'], '111', null, 'old', CONT_NOW);
            $raceDeps = $deps; $first = true; $effects = [];
            $raceDeps['runner'] = static function () use (&$first, $change, $name): array {
                if ($first) {
                    $first = false;
                    if ($change === 'replace') SessionOfferStore::post($name, 'agent', ['state' => 'ROTATE'], '222', null, 'replacement', CONT_NOW);
                    elseif ($change === 'dismiss') SessionOfferStore::dismiss($name, CONT_NOW);
                    else SessionOfferStore::clear($name);
                }
                return ['exit' => 0, 'stdout' => '[]'];
            };
            $r = FlowService::continue_rotation(['session' => $name], $raceDeps);
            $launches = array_values(array_filter($effects, static fn ($e): bool => ($e[0] ?? '') === 'launch' && !isset($e[1]['agent_session_id'])));
            assert_true(count($launches) <= 1, "$change during claims check: at most one receiver");
            assert_true(!str_contains((string) ($launches[0][1]['prompt'] ?? ''), 'handoff in Dibs task 111.'), "$change during claims check: never dispatches old handoff");
            if ($change === 'replace') {
                $fresh = SessionOfferStore::get($name, CONT_NOW);
                $launchedFresh = count($launches) === 1 && str_contains((string) ($launches[0][1]['prompt'] ?? ''), 'handoff in Dibs task 222.') && $fresh === null && (SidecarStore::read_sidecar($name)['rotated_to'] ?? null) === 'receiver' && (SidecarStore::read_sidecar('receiver')['rotated_from'] ?? null) === $name;
                $refusedFresh = ($r['ok'] ?? null) === false && $launches === [] && ($fresh['handoff_id'] ?? null) === '222';
                assert_true($launchedFresh || $refusedFresh, 'replacement is either launched and linked or refused with replacement still available');
            } else {
                assert_equal([], $launches, "$change during claims check: no receiver from a withdrawn offer");
            }
        }
        foreach (['expired', 'dismissed', 'reserved'] as $case) {
            $name = 'old-' . $case;
            SidecarStore::write_sidecar($name, ['workdir' => '/tmp', 'agent_session_id' => $id, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work']);
            SessionStatusStore::write_status($name, ['status' => 'idle', 'mode' => 'plan']);
            SessionOfferStore::post($name, 'agent', ['state' => 'ROTATE'], '111', null, 'old', $case === 'expired' ? CONT_NOW - 86400 : CONT_NOW);
            if ($case === 'dismissed') SessionOfferStore::dismiss($name, CONT_NOW);
            if ($case === 'reserved') SessionOfferStore::claimLaunch($name, CONT_NOW);
            $answer = ['exit' => 0, 'stdout' => '[]']; $effects = [];
            $r = FlowService::continue_rotation(['session' => $name], $deps);
            if ($case === 'reserved') {
                assert_equal(false, $r['ok'] ?? null, 'reserved offer refuses duplicate click');
                assert_equal([], $effects, 'reserved offer is not dispatched or cleared for preparation');
                assert_equal(false, SessionOfferStore::claimLaunch($name, CONT_NOW), 'reserved offer remains claimed');
            } else {
                assert_equal(true, $r['ok'] ?? null, "$case never-launched offer permits preparation");
                assert_equal($id, $effects[0][1]['agent_session_id'] ?? null, "$case offer asks source instead of creating receiver");
                assert_contains('/rotate', (string) ($effects[0][1]['prompt'] ?? ''), "$case offer asks source to rotate");
            }
        }
        $detail = ['ok' => true, 'status' => 'idle']; $effects = [];
        $r = FlowService::continue_rotation(['session' => 'src', 'handoff_id' => '999'], $deps);
        assert_equal(true, $r['ok'] ?? null, 'fresh eligible click starts receiver');
        assert_equal(1, count($effects), 'eligible click dispatches exactly once');
        assert_true(str_contains((string) ($effects[0][1]['prompt'] ?? ''), '123') && !str_contains((string) ($effects[0][1]['prompt'] ?? ''), '999'), 'click uses current offer instead of browser id');
        assert_true(!isset($effects[0][1]['agent_session_id']), 'eligible click creates a receiver');
        assert_equal('receiver', SidecarStore::read_sidecar('src')['rotated_to'] ?? null, 'source links to receiver');
        assert_equal('src', SidecarStore::read_sidecar('receiver')['rotated_from'] ?? null, 'receiver links to source');
        $effects = []; $duplicate = FlowService::continue_rotation(['session' => 'src'], $deps);
        assert_equal(false, $duplicate['ok'] ?? null, 'consumed offer refuses duplicate click');
        assert_equal([], $effects, 'consumed offer is not cleared to dispatch preparation');
        assert_equal([], array_values(array_filter($effects, static fn ($e): bool => ($e[0] ?? '') === 'launch' && !isset($e[1]['agent_session_id']))), 'second click cannot create another receiver from consumed offer');
        SessionOfferStore::post('src', 'agent', ['state' => 'ROTATE'], '123', null, 'ready', CONT_NOW);
        $answer = ['exit' => 0, 'stdout' => '[{"id":1}]']; $effects = [];
        $r = FlowService::continue_rotation(['session' => 'src', 'handoff_id' => '999'], $deps);
        assert_equal(true, $r['ok'] ?? null, 'idle source with a new held claim is asked to prepare rotation');
        assert_equal($id, $effects[0][1]['agent_session_id'] ?? null, 'new held claim sends to source instead of creating receiver');
        assert_contains('/rotate', (string) ($effects[0][1]['prompt'] ?? ''), 'held claim preparation sends rotate instruction');
        foreach (['no context', 'unknown claims'] as $case) {
            SessionOfferStore::clear('src');
            if ($case === 'unknown claims') SessionOfferStore::post('src', 'agent', ['state' => 'ROTATE'], '123', null, 'ready', CONT_NOW);
            $answer = $case === 'unknown claims' ? ['exit' => 0, 'stdout' => 'null'] : ['exit' => 0, 'stdout' => '[]'];
            $effects = [];
            $r = FlowService::continue_rotation(['session' => 'src'], $deps);
            assert_equal(true, $r['ok'] ?? null, "$case: requests source preparation");
            assert_equal($id, $effects[0][1]['agent_session_id'] ?? null, "$case: targets source conversation");
            assert_contains('/rotate', (string) ($effects[0][1]['prompt'] ?? ''), "$case: sends rotate instruction");
        }
        SidecarStore::write_sidecar('plan-src', ['workdir' => '/tmp', 'agent_session_id' => $id, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work']);
        SessionStatusStore::write_status('plan-src', ['status' => 'idle', 'mode' => 'plan']);
        SessionOfferStore::post('plan-src', 'agent', ['state' => 'ROTATE'], null, '456', 'ready', CONT_NOW);
        $answer = ['exit' => 0, 'stdout' => '[]']; $effects = [];
        $r = FlowService::continue_rotation(['session' => 'plan-src', 'plan_id' => '999'], $deps);
        assert_equal(true, $r['ok'] ?? null, 'plan-only offer starts a receiver');
        assert_true(str_contains((string) ($effects[0][1]['prompt'] ?? ''), '456') && !str_contains((string) ($effects[0][1]['prompt'] ?? ''), '999'), 'plan-only click uses fresh offer, not browser id');
        assert_true(!isset($effects[0][1]['agent_session_id']), 'plan-only click creates receiver');
        $detail = ['ok' => true, 'status' => 'working']; $effects = [];
        $r = FlowService::continue_rotation(['session' => 'src', 'handoff_id' => '999'], $deps);
        assert_equal(false, $r['ok'] ?? null, 'click rechecks working status');
        assert_equal([], $effects, 'working source click does not launch or send');
        $detail = ['ok' => false];
        $r = FlowService::continue_rotation(['session' => 'src'], $deps);
        assert_equal(false, $r['ok'] ?? null, 'ended source click refused');
        assert_equal([], $effects, 'ended source click has no effects');
    }
}
require_once __DIR__ . '/../host-agent/lib/Sessions.php';
foreach (['flow_continuation', 'flow_continue_rotate'] as $action) {
    $reply = dispatch_action(['action' => $action, 'session' => '']);
    assert_equal(false, $reply['ok'] ?? null, "$action dispatcher rejects blank session");
    assert_true(is_string($reply['message'] ?? null) && $reply['message'] !== '' && $reply['message'] !== 'Unknown action', "$action reaches its handler rather than unknown-action fallback");
}
test_exit();
