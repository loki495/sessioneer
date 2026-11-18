<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/claude_headless.php';

use HostAgent\Services\Config;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$root = sys_get_temp_dir() . '/sessioneer-test-spawn-cleanup-' . bin2hex(random_bytes(6));
mkdir($root . '/home/.claude/projects', 0700, true);
mkdir($root . '/work', 0700, true);
$managers = [];
$madeSessions = [];
foreach ([
    'HOME' => $root . '/home', 'HOME_ROOT' => $root . '/home',
    'SESSIONS_SQLITE_FILE' => $root . '/sessions.sqlite', 'PUSH_SQLITE_FILE' => $root . '/push.sqlite',
    'SIDECAR_DIR' => $root . '/sidecars', 'CACHE_DIR' => $root . '/cache',
    'TMUX_SOCKET' => $root . '/tmux.sock', 'CLAUDE_HEADLESS_SOCKET' => $root . '/mgr.sock',
    'CLAUDE_BIN' => __DIR__ . '/fixtures/fake_claude_stream', 'CODEX_BIN' => '/nonexistent/codex',
    'CODEX_BRIDGE_SOCKET' => $root . '/missing-codex.sock', 'OPENCODE_DB_PATH' => $root . '/missing.db',
    'OPENCODE_AUTH_PATH' => $root . '/missing-auth.json', 'OPENCODE_SERVE_URL' => 'http://127.0.0.1:1',
    'SESSION_LIST_CACHE_TTL_SECONDS' => '0', 'SOCKET_CLIENT_FAIL_FAST_IF_ABSENT' => '1',
    'SPAWN_CLEANUP_CLAIMS_STATE' => $root . '/claims.json',
] as $key => $value) {
    putenv("{$key}={$value}");
}
foreach (['SESSIONEER_SELF', 'CODEX_THREAD_ID', 'CLAUDE_CONFIG_DIR', 'SESSIONEER_CLAIMS_CMD'] as $key) {
    putenv($key);
}
if (!str_starts_with(Config::sessions_sqlite_path(), $root . '/') || !str_starts_with(Config::sidecar_dir(), $root . '/')) {
    throw new RuntimeException('Refusing non-isolated session state');
}
register_shutdown_function(static function () use (&$managers, &$madeSessions, $root): void {
    foreach ($managers as $manager) {
        if (!is_resource($manager['proc'])) {
            continue;
        }
        if (proc_get_status($manager['proc'])['running']) {
            foreach ($madeSessions as $name) {
                call($manager, 'sessioneer/stop', ['session' => $name]);
            }
            proc_terminate($manager['proc'], SIGTERM);
        }
        proc_close($manager['proc']);
    }
    exec('rm -rf ' . escapeshellarg($root));
});
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';
$m = start_manager($root, 'mgr');

function spawn_parent(string $name, string $profile = 'work'): array
{
    global $root;
    $identity = ['agent' => 'claude', 'profile' => $profile, 'agent_session_id' => 'conversation-' . $name, 'name' => $name];
    make_session($root, $name, ['agent_session_id' => $identity['agent_session_id'], 'profile' => $profile]);
    SessionStatusStore::write_status($name, ['status' => 'idle', 'mode' => 'plan']);
    return $identity;
}

function spawn_child(?string $caller, string $prompt = 'fixture child'): string
{
    global $root, $m, $madeSessions;
    $request = ['action' => 'launch', 'workdir' => $root . '/work', 'prompt' => $prompt, 'profile' => 'personal'];
    if ($caller !== null) {
        $request['from'] = $caller;
    }
    $reply = dispatch_action($request);
    assert_equal(true, $reply['ok'] ?? null, 'fixture child launch succeeds');
    $name = (string) ($reply['name'] ?? '');
    if ($name === '') {
        throw new RuntimeException('Fixture launch failed: ' . json_encode($reply));
    }
    $madeSessions[] = $name;
    assert_true((bool) wait_until(static fn (): bool => status_is($name, 'idle')), 'fixture child completes its fake turn');
    assert_equal(true, call($m, 'sessioneer/stop', ['session' => $name])['ok'] ?? null, 'fixture child becomes dormant');
    return $name;
}

function spawned_names(array $reply): array
{
    $names = array_column($reply['sessions'] ?? [], 'name');
    sort($names);
    return $names;
}

function reap_result(array $reply, string $name): array
{
    $matches = array_values(array_filter($reply['results'] ?? [], static fn (array $row): bool => ($row['name'] ?? null) === $name));
    assert_equal(1, count($matches), 'reap reports exactly one result for ' . $name);
    $row = $matches[0] ?? [];
    assert_true(is_string($row['reason'] ?? null) && $row['reason'] !== '', 'reap result explains its reason');
    return $row;
}

function claims_reply(array $state = []): void
{
    global $root;
    file_put_contents($root . '/claims.json', json_encode($state + ['calls' => []]));
    putenv('SESSIONEER_CLAIMS_CMD=' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/fixtures/spawn_cleanup_claims.php'));
}

function claims_calls(): array
{
    global $root;
    return json_decode((string) file_get_contents($root . '/claims.json'), true)['calls'] ?? [];
}

echo "Canonical ancestry and direct children\n";
$parent = spawn_parent('fixture-parent');
$other = spawn_parent('fixture-other', 'personal');
$child = spawn_child($parent['agent_session_id']);
$childId = SidecarStore::read_sidecar($child)['agent_session_id'];
assert_equal($parent, dispatch_action(['action' => 'session_detail', 'session' => $child])['spawned_by'] ?? null, 'new launch resolves conversation id to canonical tracked parent');
$grandchild = spawn_child($child);
$foreign = spawn_child($other['name']);
$unknown = spawn_child('untracked-caller');
$unowned = spawn_child(null);
foreach ([$unknown, $unowned] as $name) {
    assert_equal(null, dispatch_action(['action' => 'session_detail', 'session' => $name])['spawned_by'] ?? null, 'untracked or missing caller invents no ancestry');
}
$before = SidecarStore::read_sidecar($child);
$listed = dispatch_action(['action' => 'launch_spawned', 'from' => $parent['name']]);
assert_equal(true, $listed['ok'] ?? null, 'spawned list succeeds for dormant tracked caller');
assert_equal([$child], spawned_names($listed), 'spawned lists only direct children, excluding foreign, unowned, self and grandchild');
assert_equal($parent, $listed['sessions'][0]['spawned_by'] ?? null, 'spawned list exposes canonical parent metadata');
assert_equal($before, SidecarStore::read_sidecar($child), 'listing is read-only');
assert_equal([$child], spawned_names(dispatch_action(['action' => 'launch_spawned', 'from' => $parent['agent_session_id']])), 'canonical caller conversation id finds the same children');
foreach ([null, 'missing-caller'] as $caller) {
    $request = ['action' => 'launch_spawned'];
    if ($caller !== null) {
        $request['from'] = $caller;
    }
    assert_equal(false, dispatch_action($request)['ok'] ?? null, 'spawned refuses missing or unresolved caller');
    $request['action'] = 'launch_reap';
    assert_equal(false, dispatch_action($request)['ok'] ?? null, 'reap refuses missing or unresolved caller');
    assert_equal($before, SidecarStore::read_sidecar($child), 'invalid caller leaves child untouched');
}
dispatch_action(['action' => 'launch', 'agent_session_id' => $childId, 'from' => $other['name']]);
assert_equal($parent, dispatch_action(['action' => 'session_detail', 'session' => $child])['spawned_by'] ?? null, 'reuse from another caller never reparents');
SidecarStore::set_rotation($child, 'rotation-source', 'rotation-target');
assert_equal($parent, dispatch_action(['action' => 'session_detail', 'session' => $child])['spawned_by'] ?? null, 'rotation links remain separate from launch ancestry');
SidecarStore::write_sidecar($child, array_merge(SidecarStore::read_sidecar($child), ['title' => 'renamed child']));
assert_equal($parent, dispatch_action(['action' => 'session_detail', 'session' => $child])['spawned_by'] ?? null, 'sidecar rewrite and dormancy preserve ancestry');
$transcriptDir = $root . '/home/.claude/projects/' . preg_replace('/[^A-Za-z0-9]/', '-', $root . '/work');
mkdir($transcriptDir, 0700, true);
file_put_contents($transcriptDir . '/' . $childId . '.jsonl', json_encode(['type' => 'user', 'cwd' => $root . '/work', 'message' => ['role' => 'user', 'content' => 'fixture transcript']]) . "\n");
assert_equal(true, dispatch_action(['action' => 'launch_kill', 'target' => $child])['ok'] ?? null, 'fixture conversation is archived before resume');
$resumed = dispatch_action(['action' => 'launch', 'agent_session_id' => $childId, 'from' => $other['name']]);
assert_equal(true, $resumed['ok'] ?? null, 'archived conversation resumes under another caller');
$resumedName = (string) ($resumed['name'] ?? '');
$madeSessions[] = $resumedName;
$resumedDetail = dispatch_action(['action' => 'session_detail', 'session' => $resumedName]);
assert_equal($childId, $resumedDetail['agent_session_id'] ?? null, 'resume keeps the existing conversation identity');
assert_true(($resumedDetail['spawned_by'] ?? null) !== $other, 'resume never assigns the resuming caller as a new parent');
assert_equal($parent, $resumedDetail['spawned_by'] ?? null, 'archive and resume preserve the exact original canonical parent');

echo "Claims failures refuse cleanup\n";
$cleanParent = spawn_parent('cleanup-parent');
$guarded = spawn_child($cleanParent['name']);
$guardedId = SidecarStore::read_sidecar($guarded)['agent_session_id'];
$reap = static fn (): array => dispatch_action(['action' => 'launch_reap', 'from' => $cleanParent['name']]);
foreach ([
    'unset command' => null,
    'empty command' => ['command' => ''],
    'held list' => ['stdout' => '[{"id":7}]'],
    'held envelope' => ['stdout' => '{"claims":[{"id":7}]}'],
    'failed command' => ['stdout' => '[]', 'exit' => 1],
    'empty output' => ['stdout' => ''],
    'broken json' => ['stdout' => '{oops'],
    'scalar' => ['stdout' => 'false'],
    'null' => ['stdout' => 'null'],
    'missing claims' => ['stdout' => '{}'],
    'null claims' => ['stdout' => '{"claims":null}'],
    'object claims' => ['stdout' => '{"claims":{}}'],
] as $label => $state) {
    claims_reply(($state ?? []) + ['target_id' => $guardedId]);
    if ($state === null) {
        putenv('SESSIONEER_CLAIMS_CMD');
    } elseif (isset($state['command'])) {
        putenv('SESSIONEER_CLAIMS_CMD=' . $state['command']);
    }
    $reply = $reap();
    assert_equal(false, reap_result($reply, $guarded)['archived'] ?? null, "$label: child is not archived");
    assert_true(SidecarStore::read_sidecar($guarded) !== null, "$label: child remains tracked");
}

echo "Fresh status, identity and claims guards\n";
foreach (['working', 'blocked', 'unknown'] as $status) {
    SessionStatusStore::write_status($guarded, ['status' => $status, 'mode' => 'plan']);
    claims_reply();
    assert_equal(false, reap_result($reap(), $guarded)['archived'] ?? null, "$status child is never forcibly archived");
    assert_true(SidecarStore::read_sidecar($guarded) !== null, "$status child remains tracked");
}
SessionStatusStore::delete_status($guarded);
claims_reply();
assert_equal(false, reap_result($reap(), $guarded)['archived'] ?? null, 'missing status is unverifiable');
foreach (['working', 'blocked'] as $status) {
    SessionStatusStore::write_status($guarded, ['status' => 'idle', 'mode' => 'plan']);
    claims_reply(['target_id' => $guardedId, 'target_name' => $guarded, 'change_status' => $status]);
    assert_equal(false, reap_result($reap(), $guarded)['archived'] ?? null, "child becoming $status during claims check is preserved");
    assert_true(in_array($guardedId, claims_calls(), true), 'claims lookup uses the canonical child conversation id');
    assert_equal($status, SessionStatusStore::read_status($guarded)['status'] ?? null, 'race fixture actually changed status during the lookup');
    assert_true(SidecarStore::read_sidecar($guarded) !== null, 'changed child is still tracked');
}
SessionStatusStore::write_status($guarded, ['status' => 'idle', 'mode' => 'plan']);
claims_reply(['target_id' => $guardedId, 'claim_after_first' => true]);
assert_equal(false, reap_result($reap(), $guarded)['archived'] ?? null, 'claim acquired after initial check is rechecked before archive');
assert_true(count(array_filter(claims_calls(), static fn (string $id): bool => $id === $guardedId)) >= 2, 'claims are refreshed at mutation after initial inspection');
assert_true(SidecarStore::read_sidecar($guarded) !== null, 'new claim holder remains tracked');
SessionStatusStore::write_status($guarded, ['status' => 'idle', 'mode' => 'plan']);
claims_reply(['target_id' => $guardedId, 'target_name' => $guarded, 'replace_owner' => $other]);
assert_equal(false, reap_result($reap(), $guarded)['archived'] ?? null, 'child ownership changing during claims check is preserved');
assert_equal($guardedId, SidecarStore::read_sidecar($guarded)['agent_session_id'] ?? null, 'ownership-only race preserves child conversation identity');
assert_equal('idle', SessionStatusStore::read_status($guarded)['status'] ?? null, 'ownership-only race preserves idle status');
assert_equal($other, SidecarStore::read_sidecar($guarded)['spawned_by'] ?? null, 'race fixture actually changes only parent ownership');
$identityChild = spawn_child($cleanParent['name']);
$identityChildId = SidecarStore::read_sidecar($identityChild)['agent_session_id'];
$identityParent = SidecarStore::read_sidecar($identityChild)['spawned_by'] ?? null;
claims_reply(['target_id' => $identityChildId, 'target_name' => $identityChild, 'replace_identity' => 'replacement-conversation']);
assert_equal(false, reap_result($reap(), $identityChild)['archived'] ?? null, 'same name rebound to another conversation during claims check is preserved');
assert_equal('replacement-conversation', SidecarStore::read_sidecar($identityChild)['agent_session_id'] ?? null, 'race fixture actually replaced the conversation identity');
assert_equal($identityParent, SidecarStore::read_sidecar($identityChild)['spawned_by'] ?? null, 'identity-only race preserves exact original ownership');
assert_equal('idle', SessionStatusStore::read_status($identityChild)['status'] ?? null, 'identity-only race preserves idle status');
assert_true(SidecarStore::read_sidecar($identityChild) !== null, 'replacement identity remains tracked');

echo "A live permission transition must refuse archive despite stale idle storage\n";
$liveParent = spawn_parent('live-race-parent');
$liveChild = spawn_child($liveParent['name']);
$liveId = SidecarStore::read_sidecar($liveChild)['agent_session_id'];
claims_reply(['target_id' => $liveId, 'target_name' => $liveChild, 'live_transition' => 'PERMISSION fixture self-check']);
$probe = proc_open([PHP_BINARY, __DIR__ . '/fixtures/spawn_cleanup_claims.php', $liveId], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $probePipes);
if (!is_resource($probe)) {
    throw new RuntimeException('Cannot start claims fixture self-check');
}
$probeOut = (string) stream_get_contents($probePipes[1]);
$probeErr = (string) stream_get_contents($probePipes[2]);
assert_equal(0, proc_close($probe), 'claims fixture self-check exits cleanly');
assert_equal('[]', $probeOut, 'claims fixture self-check returns empty claims');
assert_equal('', $probeErr, 'claims fixture self-check emits no diagnostics');
assert_equal('blocked', json_decode((string) file_get_contents($root . '/claims.json'), true)['live_transition_status'] ?? null, 'claims fixture independently proves its live transition works');
assert_equal('idle', SessionStatusStore::read_status($liveChild)['status'] ?? null, 'claims fixture independently proves stale idle storage with a blocked process');
assert_equal('blocked', call($m, 'sessioneer/status', ['session' => $liveChild])['state'] ?? null, 'actual fake process is blocked while stored status is idle');
assert_equal(true, call($m, 'sessioneer/stop', ['session' => $liveChild])['ok'] ?? null, 'self-check fake process stops before the cleanup race');
assert_equal(true, call($m, 'sessioneer/sendInput', ['session' => $liveChild, 'content' => 'ready before archive race'])['ok'] ?? null, 'race fixture has a live fake process');
assert_true((bool) wait_until(static fn (): bool => status_is($liveChild, 'idle')), 'live fixture is initially idle');
claims_reply(['target_id' => $liveId, 'target_name' => $liveChild, 'live_transition' => 'PERMISSION archive race']);
$liveReply = dispatch_action(['action' => 'launch_reap', 'from' => $liveParent['name']]);
assert_equal(false, reap_result($liveReply, $liveChild)['archived'] ?? null, 'live child becoming blocked during cleanup is never forcibly archived');
$claimState = json_decode((string) file_get_contents($root . '/claims.json'), true);
assert_equal('blocked', $claimState['live_transition_status'] ?? null, 'fixture confirms the live process transitioned before archive');
assert_equal('running', process_of($m, $liveChild), 'blocked fake process remains running after cleanup');
assert_true(SidecarStore::read_sidecar($liveChild) !== null, 'live blocked child remains tracked');
if (($claimState['live_transition_status'] ?? null) !== 'blocked') {
    call($m, 'sessioneer/sendInput', ['session' => $liveChild, 'content' => 'PERMISSION independent boundary probe']);
    assert_true((bool) wait_until(static fn (): bool => status_is($liveChild, 'blocked')), 'independent boundary fixture reaches a live permission prompt');
}
assert_equal(false, dispatch_action(['action' => 'launch_kill', 'target' => $liveChild])['ok'] ?? null, 'ordinary nonforced archive boundary refuses the live blocked fixture');
assert_equal('running', process_of($m, $liveChild), 'refusal preserves the actual live fake process');

echo "Idle claim-free children are archived once without recursion\n";
$successParent = spawn_parent('success-parent');
$eligible = spawn_child($successParent['name']);
$descendant = spawn_child($eligible);
claims_reply(['target_id' => SidecarStore::read_sidecar($eligible)['agent_session_id'], 'stdout' => '{"claims":[]}']);
$reply = dispatch_action(['action' => 'launch_reap', 'from' => $successParent['agent_session_id']]);
assert_equal(true, $reply['ok'] ?? null, 'caller-owned cleanup succeeds');
assert_equal(true, reap_result($reply, $eligible)['archived'] ?? null, 'idle claim-free direct child is archived');
assert_equal(null, SidecarStore::read_sidecar($eligible), 'archived child is removed');
assert_true(in_array(SidecarStore::read_sidecar($descendant)['agent_session_id'], claims_calls(), true) === false, 'cleanup never queries claims for a grandchild');
foreach ([$successParent['name'], $descendant, $foreign, $unowned, $unknown] as $name) {
    assert_true(SidecarStore::read_sidecar($name) !== null, 'cleanup preserves self, descendant, foreign and unowned session ' . $name);
}
assert_equal([], dispatch_action(['action' => 'launch_reap', 'from' => $successParent['name']])['results'] ?? null, 'repeated cleanup has no remaining direct child');
test_exit();
