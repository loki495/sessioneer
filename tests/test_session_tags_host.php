<?php

declare(strict_types=1);

/**
 * Session tags on the host side: the `tags` sidecar column (SidecarStore::set_tags, read_sidecar), the host action
 * `tags_set`, `tags` on `list`, `session_detail`, `launch_list`, `launch_peers`, `launch_spawned`, `launch_show` and
 * `launch_whoami`, the `tag_filter` request key, and `tags` on `launch` and `create`. Written from the Contract only.
 * Intent notes:
 *  - storage: [] when none, set_tags replaces, the list survives write_sidecar() rewrites of the same session;
 *  - tags_set: session by name or conversation id; add appends after the existing ones, remove ignores absent tags,
 *    set replaces; values normalized, duplicates collapsed in first order; an invalid tag is `invalid tag: <input>`;
 *    more than 8 is refused whole, never cut; every refusal leaves the stored list untouched; an unknown session or one
 *    without a sidecar row is ok:false and creates nothing;
 *  - rows: every row carries `tags`, an array, possibly empty;
 *  - filter: tag_filter keeps only sessions carrying ALL given tags, exact match, and combines with each list's own
 *    scope (repository for peers, direct children for spawned);
 *  - launch: tags applied to a created or reused session, added to what it has; create takes a comma string or an array.
 * Isolated sidecar database, a local Ollama stub for launches (no prompt is ever sent, so no model turn runs), dead
 * sockets for every other agent and an isolated tmux socket: no real agent, no real HOME, no network beyond 127.0.0.1.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/free_port.php';

set_time_limit(90);

$root = sys_get_temp_dir() . '/sessioneer-test-tags-host-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($root . '/cache', 0700, true);
mkdir($root . '/repo', 0700, true);
mkdir($root . '/other', 0700, true);
exec('git init -q -b main ' . escapeshellarg($root . '/repo'));
exec('git init -q -b main ' . escapeshellarg($root . '/other'));
$port = test_free_port();
foreach ([
    'HOME' => $root . '/home', 'HOME_ROOT' => $root . '/home', 'SIDECAR_DIR' => $root . '/sidecars',
    'SESSIONS_SQLITE_FILE' => $root . '/sessions.sqlite', 'CACHE_DIR' => $root . '/cache',
    'PUSH_SQLITE_FILE' => $root . '/push.sqlite', 'TMUX_SOCKET' => $root . '/tmux/socket',
    'OPENCODE_SERVE_URL' => 'http://127.0.0.1:1', 'OPENCODE_DB_PATH' => $root . '/no-opencode.db',
    'CLAUDE_HEADLESS_SOCKET' => $root . '/no-claude-manager.sock', 'CODEX_BRIDGE_SOCKET' => $root . '/no-codex-bridge.sock',
    'CLAUDE_BIN' => '/nonexistent/claude', 'CODEX_BIN' => '/nonexistent/codex',
    'SOCKET_CLIENT_FAIL_FAST_IF_ABSENT' => '1', 'SESSION_LIST_CACHE_TTL_SECONDS' => '0', 'HEADLESS_SYNC_SECONDS' => '0',
    'OLLAMA_URL' => "http://127.0.0.1:{$port}", 'SESSIONEER_AGENT_SOCKET' => $root . '/no-agent.sock',
] as $key => $value) {
    putenv("{$key}={$value}");
}
foreach (['SESSIONEER_SELF', 'CODEX_THREAD_ID', 'CLAUDE_CONFIG_DIR', 'SESSIONEER_CLAIMS_CMD'] as $key) {
    putenv($key);
}

require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Stores\SidecarStore;

if (!str_starts_with(Config::sessions_sqlite_path(), $root . '/') || !str_starts_with(Config::sidecar_dir(), $root . '/')) {
    fwrite(STDERR, "REFUSING TO RUN: sidecar state is not isolated\n");
    exit(1);
}

file_put_contents($root . '/stub-state.json', json_encode(['mode' => 'normal']));
$stub = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/ollama_stub.php', $root . '/stub-state.json'],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', $root . '/stub.log', 'a']],
    $stubPipes
);
register_shutdown_function(static function () use (&$stub, $root): void {
    if (is_resource($stub)) {
        proc_terminate($stub);
        proc_close($stub);
    }
    exec('rm -rf ' . escapeshellarg($root));
});
$up = false;
for ($i = 0; $i < 50 && !$up; $i++) {
    usleep(100000);
    $up = @file_get_contents("http://127.0.0.1:{$port}/api/version") !== false;
}
assert_true($up, 'fixture: the Ollama stub is reachable');

// Reading a field of a reply that failed to arrive is the failure already reported, not a second one.
set_error_handler(static fn (int $no, string $msg): bool => (bool)preg_match('/Undefined array key|offset on null|Undefined variable/', $msg));

/** A headless Ollama sidecar: it shows in every list action without any process behind it. */
function th_make(string $name, ?string $parent = null, string $repo = 'repo', ?string $conversation = null): void
{
    global $root;
    $row = ['workdir' => "{$root}/{$repo}", 'spawned_at' => time(), 'agent_session_id' => $conversation ?? $name, 'spawned_by_app' => true, 'agent' => 'ollama', 'runtime' => 'headless'];
    if ($parent !== null) {
        $row['spawned_by'] = ['agent' => 'ollama', 'profile' => null, 'agent_session_id' => $parent, 'name' => $parent];
    }
    SidecarStore::write_sidecar($name, $row);
}

/** @param list<string> $tags */
function th_store(string $name, array $tags): void
{
    try {
        SidecarStore::set_tags($name, $tags);
    } catch (\Throwable $e) {
        assert_true(false, 'SidecarStore::set_tags failed: ' . $e->getMessage());
    }
}

function th_stored(string $name): mixed
{
    $row = SidecarStore::read_sidecar($name);

    return is_array($row) && array_key_exists('tags', $row) ? $row['tags'] : 'missing';
}

/** @return array<string, mixed> */
function th_act(string $session, mixed $mode, mixed $tags): array
{
    return dispatch_action(['action' => 'tags_set', 'session' => $session, 'mode' => $mode, 'tags' => $tags]);
}

/** @return array<string, array<string, mixed>> a list reply's rows by name */
function th_rows(array $reply, string $key = 'sessions'): array
{
    $by = [];
    foreach ($reply[$key] ?? [] as $row) {
        $by[(string)$row['name']] = $row;
    }

    return $by;
}

/** @return list<string> sorted row names of a list reply */
function th_names(array $reply, string $key = 'sessions'): array
{
    $names = array_keys(th_rows($reply, $key));
    sort($names);

    return $names;
}

function th_tags_of(array $row): mixed
{
    return array_key_exists('tags', $row) ? $row['tags'] : 'missing';
}

echo "Storage\n";
th_make('th-a');
th_make('th-b');
assert_true(method_exists(SidecarStore::class, 'set_tags'), 'SidecarStore::set_tags exists');
assert_equal([], th_stored('th-a'), 'a sidecar never tagged reads back an empty list');
th_store('th-a', ['worker', 'project x']);
assert_equal(['worker', 'project x'], th_stored('th-a'), 'set_tags stores the list in order');
assert_equal([], th_stored('th-b'), 'another session is untouched');
th_store('th-a', ['runner']);
assert_equal(['runner'], th_stored('th-a'), 'a second set_tags replaces the list');
th_make('th-a');
assert_equal(['runner'], th_stored('th-a'), 'the list survives a write_sidecar() rewrite of the same session');
SidecarStore::write_sidecar('th-a', ['workdir' => '/tmp', 'spawned_at' => 5, 'agent_session_id' => 'th-a', 'agent' => 'ollama', 'runtime' => 'headless', 'title' => 'Rewritten', 'spawned_by_app' => true]);
assert_equal(['runner'], th_stored('th-a'), 'and a rewrite that changes other columns');
th_make('th-a');
th_store('th-a', []);
assert_equal([], th_stored('th-a'), 'set_tags with an empty list clears');
th_make('th-a');
assert_equal([], th_stored('th-a'), 'a cleared list stays cleared after a rewrite');

echo "tags_set: set, add, remove\n";
$r = th_act('th-a', 'set', ['worker', 'project x']);
assert_equal(true, $r['ok'] ?? null, 'set: ok');
assert_equal(['worker', 'project x'], $r['tags'] ?? null, 'set: the reply lists the tags');
assert_equal(['worker', 'project x'], th_stored('th-a'), 'set: stored');
$r = th_act('th-a', 'add', ['runner', 'worker', 'closer']);
assert_equal(['worker', 'project x', 'runner', 'closer'], $r['tags'] ?? null, 'add: appended after the existing ones, a present tag keeps its place');
assert_equal(['worker', 'project x', 'runner', 'closer'], th_stored('th-a'), 'add: stored');
$r = th_act('th-a', 'add', ['runner']);
assert_equal([true, ['worker', 'project x', 'runner', 'closer']], [$r['ok'] ?? null, $r['tags'] ?? null], 'add: repeating it changes nothing');
$r = th_act('th-a', 'remove', ['project x', 'closer']);
assert_equal(['worker', 'runner'], $r['tags'] ?? null, 'remove: takes the named tags out, the rest keep their order');
assert_equal(['worker', 'runner'], th_stored('th-a'), 'remove: stored');
$r = th_act('th-a', 'remove', ['never there']);
assert_equal([true, ['worker', 'runner']], [$r['ok'] ?? null, $r['tags'] ?? null], 'remove: an absent tag is not an error');
$r = th_act('th-a', 'remove', ['worker', 'runner']);
assert_equal([true, []], [$r['ok'] ?? null, $r['tags'] ?? null], 'remove: the last tags leave an empty list');
th_act('th-a', 'set', ['a', 'b']);
$r = th_act('th-a', 'set', []);
assert_equal([true, [], []], [$r['ok'] ?? null, $r['tags'] ?? null, th_stored('th-a')], 'set with an empty list clears');
assert_equal([], th_stored('th-b'), 'none of this touched another session');

echo "tags_set: normalization and duplicates\n";
$r = th_act('th-a', 'set', ['Test-Builder', '  HANDOFF__receiver ', "project \t  x", 'a--_ b', '-edge_']);
assert_equal(['test builder', 'handoff receiver', 'project x', 'a b', 'edge'], $r['tags'] ?? null, 'set: lowercase, dash and underscore to space, whitespace collapsed and trimmed');
assert_equal(['test builder', 'handoff receiver', 'project x', 'a b', 'edge'], th_stored('th-a'), 'stored normalized');
$r = th_act('th-a', 'set', ['beta', 'Alpha', 'alpha', 'BETA', 'test_builder', 'test builder']);
assert_equal(['beta', 'alpha', 'test builder'], $r['tags'] ?? null, 'duplicates after normalization collapse, first order kept');
$r = th_act('th-a', 'add', ['Test-Builder', 'Gamma']);
assert_equal(['beta', 'alpha', 'test builder', 'gamma'], $r['tags'] ?? null, 'add: compares normalized values');
$r = th_act('th-a', 'remove', ['TEST_BUILDER']);
assert_equal(['beta', 'alpha', 'gamma'], $r['tags'] ?? null, 'remove: compares normalized values');
$long = str_repeat('a', 32);
$r = th_act('th-a', 'set', [$long, '0', 'a' . str_repeat(' ', 40) . 'b']);
assert_equal([$long, '0', 'a b'], $r['tags'] ?? null, '32 characters, a single digit and a long-before-collapsing tag are valid');

echo "tags_set: invalid tags\n";
th_act('th-a', 'set', ['keep']);
foreach ([
    'punctuation' => 'bad!',
    'dot' => 'a.b',
    'slash' => 'a/b',
    'comma inside a tag' => 'a,b',
    'markup' => '<b>',
    'quote' => 'a"b',
    'non ascii' => 'café',
    '33 characters' => str_repeat('a', 33),
    'empty' => '',
    'only separators' => '_-_',
] as $label => $bad) {
    foreach (['set', 'add'] as $mode) {
        $r = th_act('th-a', $mode, ['good', $bad]);
        assert_equal(false, $r['ok'] ?? null, "{$mode}, {$label}: refused");
        assert_equal("invalid tag: {$bad}", $r['error'] ?? null, "{$mode}, {$label}: error names the input");
        assert_equal(['keep'], th_stored('th-a'), "{$mode}, {$label}: nothing changed, not even the valid tag beside it");
    }
}

echo "tags_set: at most 8\n";
$eight = ['t1', 't2', 't3', 't4', 't5', 't6', 't7', 't8'];
$r = th_act('th-a', 'set', $eight);
assert_equal([true, $eight], [$r['ok'] ?? null, $r['tags'] ?? null], 'eight tags are accepted');
th_act('th-a', 'set', ['keep']);
$r = th_act('th-a', 'set', [...$eight, 't9']);
assert_equal(false, $r['ok'] ?? null, 'set with nine is refused');
assert_true(is_string($r['error'] ?? null) && $r['error'] !== '', 'and explains why');
assert_equal(['keep'], th_stored('th-a'), 'never cut to eight: the old list stays');
th_act('th-a', 'set', $eight);
$r = th_act('th-a', 'add', ['t9']);
assert_equal(false, $r['ok'] ?? null, 'adding a ninth is refused');
assert_true(is_string($r['error'] ?? null) && $r['error'] !== '', 'with an error');
assert_equal($eight, th_stored('th-a'), 'the eight stay');
$r = th_act('th-a', 'add', ['t3']);
assert_equal([true, $eight], [$r['ok'] ?? null, $r['tags'] ?? null], 'adding a tag a full session already has is not going beyond the limit');
$six = array_slice($eight, 0, 6);
th_act('th-a', 'set', $six);
$r = th_act('th-a', 'add', ['n1', 'n2', 'n3']);
assert_equal(false, $r['ok'] ?? null, 'six plus three is refused');
assert_equal($six, th_stored('th-a'), 'none of the three is added');
$r = th_act('th-a', 'add', ['n1', 'n2']);
assert_equal([true, 8], [$r['ok'] ?? null, count($r['tags'] ?? [])], 'six plus two reaches eight');
th_act('th-a', 'remove', ['n1']);
$r = th_act('th-a', 'add', ['n3']);
assert_equal([true, 8], [$r['ok'] ?? null, count($r['tags'] ?? [])], 'after a removal there is room again');

echo "tags_set: target by name or conversation id\n";
th_make('th-conv', null, 'repo', '5f0c1a2b-1111-4222-8333-444455556666');
$r = th_act('5f0c1a2b-1111-4222-8333-444455556666', 'set', ['by id']);
assert_equal([true, ['by id']], [$r['ok'] ?? null, $r['tags'] ?? null], 'a conversation id works as the session');
assert_equal(['by id'], th_stored('th-conv'), 'and tags the session that holds it');
assert_equal(null, SidecarStore::read_sidecar('5f0c1a2b-1111-4222-8333-444455556666'), 'no row is created under the id');
$r = th_act('th-conv', 'add', ['by name']);
assert_equal(['by id', 'by name'], $r['tags'] ?? null, 'and the name reaches the same list');

echo "tags_set: unknown sessions and bad requests\n";
foreach (['th-ghost' => 'unknown name', '00000000-1111-4222-8333-444444444444' => 'unknown conversation id', '' => 'blank session'] as $target => $label) {
    $r = th_act((string)$target, 'set', ['x']);
    assert_equal(false, $r['ok'] ?? null, "{$label}: ok false");
    assert_true(is_string($r['error'] ?? null) && $r['error'] !== '', "{$label}: with an error");
    assert_true((string)$target === '' || SidecarStore::read_sidecar((string)$target) === null, "{$label}: no sidecar row appears");
}
$r = dispatch_action(['action' => 'tags_set', 'mode' => 'set', 'tags' => ['x']]);
assert_equal(false, $r['ok'] ?? null, 'no session key: ok false');
assert_true(is_string($r['error'] ?? null), 'no session key: with an error');
th_make('th-gone');
SidecarStore::delete_sidecar('th-gone');
$r = th_act('th-gone', 'add', ['x']);
assert_equal(false, $r['ok'] ?? null, 'a session whose sidecar row is gone: ok false');
assert_true(is_string($r['error'] ?? null), 'with an error');
assert_equal(null, SidecarStore::read_sidecar('th-gone'), 'and it is not brought back');
th_act('th-a', 'set', ['keep']);
foreach (['replace', '', null] as $mode) {
    $r = th_act('th-a', $mode, ['x']);
    assert_equal(false, $r['ok'] ?? null, 'unknown mode ' . json_encode($mode) . ' is refused');
    assert_true(is_string($r['error'] ?? null) && $r['error'] !== '', 'mode ' . json_encode($mode) . ': with an error');
    assert_equal(['keep'], th_stored('th-a'), 'mode ' . json_encode($mode) . ': nothing changed');
}

echo "Rows: list and session_detail\n";
th_make('th-plain');
th_act('th-a', 'set', ['worker', 'project x']);
$rows = th_rows(dispatch_action(['action' => 'list']));
assert_true(isset($rows['th-a'], $rows['th-plain']), 'fixture: both sessions are listed');
assert_equal(['worker', 'project x'], th_tags_of($rows['th-a'] ?? []), 'list: the row carries the tags');
assert_equal([], th_tags_of($rows['th-plain'] ?? []), 'list: an untagged row carries an empty array');
assert_equal(['worker', 'project x'], th_tags_of(dispatch_action(['action' => 'session_detail', 'session' => 'th-a'])), 'session_detail: carries the tags');
assert_equal([], th_tags_of(dispatch_action(['action' => 'session_detail', 'session' => 'th-plain'])), 'session_detail: empty array when none');
th_act('th-a', 'remove', ['worker']);
assert_equal(['project x'], th_tags_of(th_rows(dispatch_action(['action' => 'list']))['th-a'] ?? []), 'list: reflects the next edit');

echo "Launch lists: rows and tag_filter\n";
foreach (SidecarStore::list_runtime_sidecars('headless') as $row) {
    SidecarStore::delete_sidecar($row['session_name']);
}
th_make('th-self');
th_make('th-ab', 'th-self');
th_make('th-a1', 'th-self');
th_make('th-b1', 'th-self');
th_make('th-none', 'th-self');
th_make('th-else', null, 'other');
th_make('th-grand', 'th-ab');
th_store('th-self', ['alpha', 'beta']);
th_store('th-ab', ['alpha', 'project x', 'beta']);
th_store('th-a1', ['alpha']);
th_store('th-b1', ['beta']);
th_store('th-none', []);
th_store('th-else', ['alpha', 'beta']);
th_store('th-grand', ['alpha', 'beta']);

$lists = [
    'launch_list' => ['request' => ['action' => 'launch_list', 'agent' => 'ollama'], 'key' => 'sessions', 'all' => ['th-a1', 'th-ab', 'th-b1', 'th-else', 'th-grand', 'th-none', 'th-self'], 'alpha' => ['th-a1', 'th-ab', 'th-else', 'th-grand', 'th-self'], 'both' => ['th-ab', 'th-else', 'th-grand', 'th-self']],
    'launch_peers' => ['request' => ['action' => 'launch_peers', 'agent' => 'ollama', 'from' => 'th-self'], 'key' => 'peers', 'all' => ['th-a1', 'th-ab', 'th-b1', 'th-grand', 'th-none'], 'alpha' => ['th-a1', 'th-ab', 'th-grand'], 'both' => ['th-ab', 'th-grand']],
    'launch_spawned' => ['request' => ['action' => 'launch_spawned', 'agent' => 'ollama', 'from' => 'th-self'], 'key' => 'sessions', 'all' => ['th-a1', 'th-ab', 'th-b1', 'th-none'], 'alpha' => ['th-a1', 'th-ab'], 'both' => ['th-ab']],
];
foreach ($lists as $action => $case) {
    $key = $case['key'];
    $reply = dispatch_action($case['request']);
    assert_equal(true, $reply['ok'] ?? null, "{$action}: ok");
    assert_equal($case['all'], th_names($reply, $key), "{$action}: unfiltered scope (fixture check)");
    $rows = th_rows($reply, $key);
    assert_equal(['alpha', 'project x', 'beta'], th_tags_of($rows['th-ab'] ?? []), "{$action}: a row carries its tags in order");
    assert_equal([], th_tags_of($rows['th-none'] ?? []), "{$action}: an untagged row carries an empty array");
    assert_equal(count($case['all']), count(array_filter($rows, static fn (array $row): bool => is_array($row['tags'] ?? null))), "{$action}: every row has a tags array");

    $reply = dispatch_action($case['request'] + ['tag_filter' => []]);
    assert_equal($case['all'], th_names($reply, $key), "{$action}: an empty tag_filter filters nothing");
    $reply = dispatch_action($case['request'] + ['tag_filter' => ['alpha']]);
    assert_equal(true, $reply['ok'] ?? null, "{$action} filtered: ok");
    assert_equal($case['alpha'], th_names($reply, $key), "{$action}: one tag keeps only sessions carrying it");
    foreach ([['alpha', 'beta'], ['beta', 'alpha']] as $filter) {
        assert_equal($case['both'], th_names(dispatch_action($case['request'] + ['tag_filter' => $filter]), $key), "{$action}: " . implode('+', $filter) . ' keeps sessions carrying ALL of them');
    }
    assert_equal(['th-ab'], th_names(dispatch_action($case['request'] + ['tag_filter' => ['project x']]), $key), "{$action}: a tag with a space matches");
    foreach ([['nope'], ['alpha', 'nope'], ['alph'], ['project']] as $filter) {
        $reply = dispatch_action($case['request'] + ['tag_filter' => $filter]);
        assert_equal([true, []], [$reply['ok'] ?? null, $reply[$key] ?? 'missing'], "{$action}: " . implode('+', $filter) . ' matches nothing: ok with an empty list');
    }
}

echo "launch_show and launch_whoami\n";
th_make('th-shown', null, 'repo', 'a1b2c3d4-1111-4222-8333-444455556666');
th_store('th-shown', ['reviewer', 'project x']);
$r = dispatch_action(['action' => 'launch_show', 'target' => 'th-shown']);
assert_equal([true, ['reviewer', 'project x']], [$r['ok'] ?? null, th_tags_of($r)], 'launch_show by name: carries tags');
$r = dispatch_action(['action' => 'launch_show', 'target' => 'a1b2c3d4-1111-4222-8333-444455556666']);
assert_equal(['reviewer', 'project x'], th_tags_of($r), 'launch_show by conversation id: carries tags');
assert_equal([], th_tags_of(dispatch_action(['action' => 'launch_show', 'target' => 'th-none'])), 'launch_show: empty array when none');
$r = dispatch_action(['action' => 'launch_whoami', 'session' => 'th-shown']);
assert_equal([true, 'th-shown', 'a1b2c3d4-1111-4222-8333-444455556666', ['reviewer', 'project x']], [$r['ok'] ?? null, $r['name'] ?? null, $r['conversation_id'] ?? null, th_tags_of($r)], 'launch_whoami resolves name, conversation and tags');
$r = dispatch_action(['action' => 'launch_whoami', 'session' => 'a1b2c3d4-1111-4222-8333-444455556666']);
assert_equal(['th-shown', 'a1b2c3d4-1111-4222-8333-444455556666'], [$r['name'] ?? null, $r['conversation_id'] ?? null], 'launch_whoami resolves a conversation id');
assert_equal([], th_tags_of(dispatch_action(['action' => 'launch_whoami', 'session' => 'th-none'])), 'launch_whoami: empty array when none');

echo "rotate forwards tags to receiver launch\n";
SidecarStore::write_sidecar('th-rotate', ['workdir' => $root . '/repo', 'agent_session_id' => 'rotate-conv', 'agent' => 'claude', 'runtime' => 'headless']);
$received = [];
$deps = [
    'facts' => static fn (string $name, array $row): ?array => $name === 'th-rotate' ? ['source' => ['agent' => 'claude']] : null,
    'clock' => static fn (): int => time(),
    'launch' => static function (array $request) use (&$received): array {
        $received[] = $request;
        th_make('th-receiver');
        return ['ok' => true, 'name' => 'th-receiver'];
    },
];
$rotate = \HostAgent\SessionFlow\FlowService::rotate(['session' => 'th-rotate', 'agent' => 'claude', 'prompt' => 'continue', 'tags' => ['handoff receiver', 'project x']], $deps);
assert_equal(true, $rotate['ok'] ?? null, 'rotate starts the receiver');
assert_equal(1, count($received), 'rotate invokes launch once');
assert_equal(['handoff receiver', 'project x'], $received[0]['tags'] ?? 'missing', 'receiver launch receives the exact tags');

echo "launch applies tags\n";
$launch = static fn (array $request): array => dispatch_action(['action' => 'launch', 'agent' => 'ollama'] + $request);
$new = $launch(['workdir' => $root . '/repo', 'model' => 'chat-one:1b', 'tags' => ['worker', 'project x'], 'set_name' => 'Tagged']);
assert_equal(true, $new['ok'] ?? null, 'launch with tags: ok (' . json_encode($new) . ')');
$name = (string)($new['name'] ?? '');
assert_equal(['worker', 'project x'], th_tags_of($new), 'launch reply carries tags');
assert_equal(['worker', 'project x'], th_stored($name), 'the created session carries them');
assert_equal('Tagged', SidecarStore::custom_titles()[$name] ?? null, 'set_name is applied beside them');
assert_equal(['worker', 'project x'], th_tags_of(th_rows(dispatch_action(['action' => 'launch_list', 'agent' => 'ollama']))[$name] ?? []), 'and the list shows them');
$reused = $launch(['agent_session_id' => $name, 'tags' => ['runner', 'worker']]);
assert_equal('reused', $reused['action'] ?? null, 'fixture: the tracked conversation is reused');
assert_equal(['worker', 'project x', 'runner'], th_stored($name), 'reuse adds to the existing tags, duplicates collapsed, first order kept');
assert_true(is_array($reused['tags'] ?? null) && array_diff(['runner', 'worker'], $reused['tags']) === [], 'the reuse reply carries tags');
th_store($name, $eight);
$overflow = $launch(['agent_session_id' => $name, 'tags' => ['t9']]);
assert_equal([true, 'reused', $eight, $eight], [$overflow['ok'] ?? null, $overflow['action'] ?? null, th_tags_of($overflow), th_stored($name)], 'reuse overflow succeeds and leaves tags unchanged');
assert_true(is_string($overflow['tags_error'] ?? null) && $overflow['tags_error'] !== '', 'reuse overflow reports tags_error');
th_store($name, ['worker', 'project x', 'runner']);
$plainReuse = $launch(['agent_session_id' => $name]);
assert_equal(true, $plainReuse['ok'] ?? null, 'reuse without tags: ok');
assert_equal(['worker', 'project x', 'runner'], th_stored($name), 'a launch without tags removes nothing');
$bare = $launch(['workdir' => $root . '/repo', 'model' => 'chat-one:1b']);
assert_equal([], th_tags_of($bare), 'launch without tags: the reply has an empty list');
assert_equal([], th_stored((string)($bare['name'] ?? '')), 'and the session is untagged');
assert_equal(['worker', 'project x', 'runner'], th_stored($name), 'a new launch does not touch other sessions');

echo "host validates launch and create before creation\n";
$snapshot = static function () use ($root): array {
    $db = new PDO('sqlite:' . Config::sessions_sqlite_path());
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
    $data = [];
    foreach ($tables as $table) {
        $data[$table] = $db->query('SELECT * FROM "' . str_replace('"', '""', $table) . '"')->fetchAll(PDO::FETCH_ASSOC);
    }
    return $data;
};
foreach (['launch', 'create'] as $action) {
    foreach ([['bad!'], ['t1','t2','t3','t4','t5','t6','t7','t8','t9']] as $invalid) {
        $before = $snapshot();
        clearstatcache(true, $root . '/stub.log');
        $logBefore = (string)file_get_contents($root . '/stub.log');
        $reply = dispatch_action(['action' => $action, 'agent' => 'ollama', 'workdir' => $root . '/repo', 'model' => 'chat-one:1b', 'tags' => $invalid]);
        assert_equal(false, $reply['ok'] ?? null, "{$action} rejects invalid tags");
        assert_equal($before, $snapshot(), "{$action} refusal leaves session and status records unchanged");
        assert_equal($logBefore, (string)file_get_contents($root . '/stub.log'), "{$action} refusal makes no backend request");
    }
}

echo "create accepts tags\n";
$create = static fn (array $extra): array => dispatch_action(['action' => 'create', 'agent' => 'ollama', 'workdir' => $root . '/repo', 'model' => 'chat-one:1b'] + $extra);
foreach ([
    'a comma string' => [['tags' => 'Worker, Test_Builder ,worker,project-x'], ['worker', 'test builder', 'project x']],
    'an array' => [['tags' => ['Runner', 'closer', 'runner']], ['runner', 'closer']],
    'a single tag string' => [['tags' => 'curator'], ['curator']],
    'an empty string' => [['tags' => ''], []],
    'an empty array' => [['tags' => []], []],
    'no tags key' => [[], []],
] as $label => [$extra, $expected]) {
    $made = $create($extra);
    assert_equal(true, $made['ok'] ?? null, "create with {$label}: ok (" . json_encode($made) . ')');
    assert_equal($expected, th_stored((string)($made['name'] ?? '')), "create with {$label}: stored " . json_encode($expected));
}


echo "launchers apply tags to tracked sessions\n";
foreach (['claude' => ['cc-tagged', 'claude-conversation'], 'codex' => ['codex-tagged', 'codex-tagged'], 'opencode' => ['ses_tagged', 'ses_tagged']] as $agent => [$session, $conversation]) {
    SidecarStore::write_sidecar($session, ['workdir' => $root . '/repo', 'spawned_at' => time(), 'agent_session_id' => $conversation, 'spawned_by_app' => true, 'agent' => $agent, 'runtime' => 'headless']);
    th_store($session, ['worker']);
    $reply = dispatch_action(['action' => 'launch', 'agent' => $agent, 'agent_session_id' => $conversation, 'tags' => ['runner']]);
    assert_equal([true, 'reused', ['worker', 'runner'], ['worker', 'runner']], [$reply['ok'] ?? null, $reply['action'] ?? null, th_tags_of($reply), th_stored($session)], "{$agent} reuse applies and returns tags");
}

echo "OpenCode archived conversation resume\n";
$openState = $root . '/opencode-stub.json';
file_put_contents($openState, json_encode(['sessions' => ['ses_archived' => ['id' => 'ses_archived', 'directory' => $root . '/repo', 'title' => 'Archived']]]));
$openPort = test_free_port();
$openStub = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$openPort}", __DIR__ . '/fixtures/opencode_stub.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $openPipes, null, ['SESSIONEER_OPENCODE_STUB_STATE' => $openState] + getenv());
try {
    $openUrl = "http://127.0.0.1:{$openPort}";
    $ready = false;
    for ($i = 0; $i < 50 && !$ready; $i++) {
        usleep(100000);
        $ready = @file_get_contents($openUrl . '/api/session') !== false;
    }
    assert_true($ready, 'OpenCode stub is reachable');
    putenv('OPENCODE_SERVE_URL=' . $openUrl);
    $resumed = dispatch_action(['action' => 'launch', 'agent' => 'opencode', 'agent_session_id' => 'ses_archived', 'workdir' => $root . '/repo', 'tags' => ['reviewer']]);
    assert_equal([true, 'resumed', ['reviewer'], ['reviewer']], [$resumed['ok'] ?? null, $resumed['action'] ?? null, th_tags_of($resumed), th_stored((string)($resumed['name'] ?? ''))], 'archived OpenCode conversation resumes with tags stored and returned');
} finally {
    if (is_resource($openStub)) {
        proc_terminate($openStub);
        proc_close($openStub);
    }
}

test_exit();
