<?php

declare(strict_types=1);

/**
 * Session hierarchy on the host side: the `waiting_on` sidecar column (SidecarStore::set_waiting_on, read_sidecar),
 * the host action `waiting_on_set`, and the fields the `list` and `session_detail` actions gain
 * (`launched_by`, `launched`, `waiting_on`). Written from the Contract only. Intent notes:
 *  - storage: waiting is its own data, empty list when none, never inferred from launch ancestry;
 *  - validation: at most 8 targets, no duplicates, no self, names [A-Za-z0-9._-], targets must be known sidecars;
 *    every refusal leaves the stored list untouched;
 *  - list rows: launched_by is the parent's name only while the parent's sidecar exists; waiting_on entries carry
 *    state and since, a non-live target stays for 24 hours then drops from replies, a live target never expires;
 *  - detail: launched_by, launched (children) and waiting_on; cycles and many children must not loop or lose rows.
 * Assumptions (not stated by the Contract): `since` is a unix timestamp in seconds; set_waiting_on takes a list of
 * ['name' => ..., 'since' => ...]; a refused store call may either return a falsy/array reply or throw.
 * Uses an isolated sidecar database and a dead agent/manager: no tmux, no real agent, no real HOME.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';

set_time_limit(60);

$root = sys_get_temp_dir() . '/sessioneer-test-hierarchy-host-' . getmypid();
exec('rm -rf ' . escapeshellarg($root));
mkdir($root . '/cache', 0700, true);
foreach ([
    'HOME' => $root . '/home', 'SIDECAR_DIR' => $root . '/sidecars', 'SESSIONS_SQLITE_FILE' => $root . '/sessions.sqlite',
    'CACHE_DIR' => $root . '/cache', 'PUSH_SQLITE_FILE' => $root . '/push.sqlite', 'TMUX_SOCKET' => $root . '/tmux/socket',
    'OPENCODE_SERVE_URL' => 'http://127.0.0.1:1', 'OPENCODE_DB_PATH' => $root . '/no-opencode.db',
    'CLAUDE_HEADLESS_SOCKET' => $root . '/no-claude-manager.sock', 'CODEX_BRIDGE_SOCKET' => $root . '/no-codex-bridge.sock',
    'SOCKET_CLIENT_FAIL_FAST_IF_ABSENT' => '1', 'SESSION_LIST_CACHE_TTL_SECONDS' => '0', 'HEADLESS_SYNC_SECONDS' => '0',
    'SESSIONEER_AGENT_SOCKET' => '',
] as $key => $value) {
    putenv("{$key}={$value}");
}
foreach (['SESSIONEER_SELF', 'CODEX_THREAD_ID'] as $key) {
    putenv($key);
}
register_shutdown_function(static fn () => exec('rm -rf ' . escapeshellarg($root)));

require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Stores\SidecarStore;

if (!str_starts_with(Config::sessions_sqlite_path(), $root . '/')) {
    fwrite(STDERR, "REFUSING TO RUN: sidecar database is not isolated\n");
    exit(1);
}

// Reading a field of a reply that failed to arrive is the failure already reported, not a second one.
set_error_handler(static fn (int $no, string $msg): bool => (bool)preg_match('/Undefined array key|offset on null|Undefined variable/', $msg));

/** Writes a headless OpenCode sidecar (these appear in the list action, like the wake chip test's); $parent is its launcher. */
function hh_make(string $name, ?string $parent = null): void
{
    $row = ['workdir' => '/tmp', 'spawned_at' => time(), 'agent_session_id' => $name, 'spawned_by_app' => true, 'agent' => 'opencode', 'runtime' => 'headless'];
    if ($parent !== null) {
        $row['spawned_by'] = ['agent' => 'opencode', 'profile' => null, 'agent_session_id' => $parent, 'name' => $parent];
    }
    SidecarStore::write_sidecar($name, $row);
}

/** A reply field, with a null value kept apart from a missing key. */
function hh_get(array $a, string $key): mixed
{
    return array_key_exists($key, $a) ? $a[$key] : 'missing';
}

/** @return array<string, array<string, mixed>> the list action's rows by name */
function hh_list(): array
{
    $by = [];
    foreach (dispatch_action(['action' => 'list'])['sessions'] ?? [] as $row) {
        $by[(string)$row['name']] = $row;
    }

    return $by;
}

/** @return array<string, mixed> */
function hh_detail(string $name): array
{
    return dispatch_action(['action' => 'session_detail', 'session' => $name]);
}

/** @return list<string> */
function hh_names(mixed $entries): array
{
    return array_values(array_map(static fn (array $e): string => (string)$e['name'], is_array($entries) ? $entries : []));
}

/** @return list<string> the stored target names, in stored order */
function hh_stored(string $name): array
{
    return hh_names(SidecarStore::read_sidecar($name)['waiting_on'] ?? []);
}

/** Calls the store directly; a refusal by exception is as good as a refusal by return value. */
function hh_set(string $name, array $targets, ?int $since = null): void
{
    $since ??= time();
    try {
        SidecarStore::set_waiting_on($name, array_map(static fn (string $t): array => ['name' => $t, 'since' => $since], $targets));
    } catch (\Throwable $e) {
    }
}

function hh_act(string $session, mixed $targets): array
{
    return dispatch_action(['action' => 'waiting_on_set', 'session' => $session, 'targets' => $targets]);
}

foreach (['hh-parent', 'hh-child', 'hh-other', 'hh-t1', 'hh-t2', 'hh-t3', 'hh-t4', 'hh-t5', 'hh-t6', 'hh-t7', 'hh-t8', 'hh-t9'] as $n) {
    hh_make($n);
}
hh_make('hh-kid-a', 'hh-parent');

echo "Storage: waiting_on column\n";
assert_equal([], SidecarStore::read_sidecar('hh-parent')['waiting_on'] ?? null, 'a sidecar that never waited reads back an empty list');
assert_equal([], SidecarStore::read_sidecar('hh-kid-a')['waiting_on'] ?? null, 'ancestry alone implies no waiting: a child of a parent waits on nobody');
$before = time();
hh_set('hh-parent', ['hh-t1', 'hh-t2']);
$stored = SidecarStore::read_sidecar('hh-parent')['waiting_on'] ?? [];
assert_equal(['hh-t1', 'hh-t2'], hh_names($stored), 'set_waiting_on stores the targets in the given order');
assert_equal(['name', 'since'], array_keys($stored[0] ?? []), 'each stored entry is {name, since}');
assert_true(is_int($stored[0]['since'] ?? null) && $stored[0]['since'] >= $before - 1 && $stored[0]['since'] <= time() + 1, 'since is the unix time it was set');
hh_set('hh-parent', ['hh-t3']);
assert_equal(['hh-t3'], hh_stored('hh-parent'), 'a second set replaces the list');
assert_equal([], hh_stored('hh-other'), 'another session is untouched');
hh_set('hh-parent', []);
assert_equal([], hh_stored('hh-parent'), 'an empty list clears it');
$full = ['hh-t1', 'hh-t2', 'hh-t3', 'hh-t4', 'hh-t5', 'hh-t6', 'hh-t7', 'hh-t8'];
hh_set('hh-parent', $full);
assert_equal($full, hh_stored('hh-parent'), 'eight targets (the maximum) are accepted');
hh_set('hh-parent', ['hh-t1']);
hh_set('hh-parent', [...$full, 'hh-t9']);
assert_equal(['hh-t1'], hh_stored('hh-parent'), 'nine targets are refused and the old list stays');
hh_set('hh-parent', ['hh-t2', 'hh-t2']);
assert_equal(['hh-t1'], hh_stored('hh-parent'), 'a duplicate target is refused');
hh_set('hh-parent', ['hh-parent']);
assert_equal(['hh-t1'], hh_stored('hh-parent'), 'a session cannot wait on itself');
hh_set('hh-parent', ['hh-missing-session']);
assert_equal(['hh-t1'], hh_stored('hh-parent'), 'a target that is not a known sidecar is refused');
foreach (['bad name', 'a/b', '../x', 'x;rm', "x\ny", 'ünï', '', str_repeat('a', 4) . '*'] as $badName) {
    hh_set('hh-parent', [$badName]);
    assert_equal(['hh-t1'], hh_stored('hh-parent'), 'invalid name ' . json_encode($badName) . ' is refused');
}
hh_set('hh-missing-caller', ['hh-t1']);
assert_equal(null, SidecarStore::read_sidecar('hh-missing-caller'), 'waiting for a caller with no sidecar creates no sidecar');
hh_set('hh-parent', []);

echo "Storage: dots, dashes and underscores are valid name characters\n";
hh_make('Hh.odd_name-1');
hh_set('hh-parent', ['Hh.odd_name-1']);
assert_equal(['Hh.odd_name-1'], hh_stored('hh-parent'), 'a name using every allowed punctuation is accepted');
hh_set('hh-parent', []);

echo "Host action waiting_on_set\n";
$r = hh_act('hh-parent', ['hh-t1', 'hh-t2']);
assert_equal(true, $r['ok'] ?? null, 'ok for valid targets');
assert_equal(['hh-t1', 'hh-t2'], $r['waiting_on'] ?? null, 'the reply lists the target names');
assert_equal(['hh-t1', 'hh-t2'], hh_stored('hh-parent'), 'and the list is stored');
$r = hh_act('hh-parent', ['hh-t3']);
assert_equal(['hh-t3'], $r['waiting_on'] ?? null, 'a second call replaces the list');
$r = hh_act('hh-parent', []);
assert_equal([true, []], [$r['ok'] ?? null, $r['waiting_on'] ?? null], 'an empty target list clears and replies an empty list');
assert_equal([], hh_stored('hh-parent'), 'and nothing is stored');
hh_act('hh-parent', ['hh-t1']);
$bad = [
    'unknown target' => ['hh-nope'],
    'self' => ['hh-parent'],
    'duplicate' => ['hh-t1', 'hh-t1'],
    'nine targets' => ['hh-t1', 'hh-t2', 'hh-t3', 'hh-t4', 'hh-t5', 'hh-t6', 'hh-t7', 'hh-t8', 'hh-t9'],
    'space in name' => ['hh t1'],
    'path in name' => ['../hh-t1'],
    'empty name' => [''],
    'non string name' => [['hh-t1']],
    'a string, not a list' => 'hh-t1',
    'null' => null,
];
foreach ($bad as $label => $targets) {
    $r = hh_act('hh-parent', $targets);
    assert_equal(false, $r['ok'] ?? null, "refused: {$label}");
    assert_true(is_string($r['message'] ?? null) && $r['message'] !== '', "refused: {$label} explains why");
    assert_equal(['hh-t1'], hh_stored('hh-parent'), "refused: {$label} leaves the stored list untouched");
}
$r = hh_act('hh-ghost', ['hh-t1']);
assert_equal(false, $r['ok'] ?? null, 'refused: the calling session is unknown');
assert_equal(null, SidecarStore::read_sidecar('hh-ghost'), 'refused: and no sidecar appears for it');
assert_equal(false, dispatch_action(['action' => 'waiting_on_set', 'targets' => ['hh-t1']])['ok'] ?? null, 'refused: no session key');
hh_act('hh-parent', []);

echo "list rows: launched_by\n";
hh_make('hh-kid-b', 'hh-parent');
$rows = hh_list();
assert_true(isset($rows['hh-parent'], $rows['hh-kid-a'], $rows['hh-kid-b']), 'fixture: all sessions are listed');
assert_equal('hh-parent', hh_get($rows['hh-kid-a'], 'launched_by'), 'a child names its parent');
assert_equal('hh-parent', hh_get($rows['hh-kid-b'], 'launched_by'), 'every child names the same parent');
assert_equal(null, hh_get($rows['hh-parent'], 'launched_by'), 'a session nobody launched has launched_by null (the key is present)');
assert_equal([], $rows['hh-parent']['waiting_on'] ?? 'missing', 'a session with no waiting has an empty waiting_on list (the key is present)');
assert_true(!str_contains(json_encode($rows['hh-kid-a']['launched_by'] ?? null), '{'), 'launched_by is just the name, not the ancestry object');

echo "list rows: archived or missing parent\n";
hh_make('hh-orphan', 'hh-archived-parent');
assert_equal(null, hh_get(hh_list()['hh-orphan'], 'launched_by'), 'a parent with no sidecar (archived or never tracked) gives launched_by null');
hh_make('hh-doomed');
hh_make('hh-doomed-kid', 'hh-doomed');
assert_equal('hh-doomed', hh_get(hh_list()['hh-doomed-kid'], 'launched_by'), 'while the parent exists the link shows');
SidecarStore::delete_sidecar('hh-doomed');
assert_equal(null, hh_get(hh_list()['hh-doomed-kid'], 'launched_by'), 'once the parent sidecar is gone (archive) the link is null');
assert_equal('hh-doomed', SidecarStore::read_sidecar('hh-doomed-kid')['spawned_by']['name'] ?? null, 'but the stored launch ancestry is untouched');
assert_equal(null, hh_get(hh_detail('hh-doomed-kid'), 'launched_by'), 'the detail agrees: launched_by null when the parent sidecar is gone');

echo "list rows: cycles do not loop\n";
hh_make('hh-cyc-a', 'hh-cyc-b');
hh_make('hh-cyc-b', 'hh-cyc-a');
$rows = hh_list();
assert_equal(['hh-cyc-b', 'hh-cyc-a'], [$rows['hh-cyc-a']['launched_by'] ?? null, $rows['hh-cyc-b']['launched_by'] ?? null], 'A launched by B and B launched by A are both reported as stored');
assert_equal(['hh-cyc-b'], hh_detail('hh-cyc-a')['launched'] ?? null, 'detail of A: launched lists B once');
assert_equal('hh-cyc-b', hh_detail('hh-cyc-a')['launched_by'] ?? null, 'detail of A: launched_by is B');
hh_make('hh-self', 'hh-self');
assert_true(isset(hh_list()['hh-self']), 'a session recorded as launched by itself still lists');
assert_true(is_array(hh_detail('hh-self')['launched'] ?? null), 'and its detail answers');

echo "list rows: waiting_on entries and state\n";
hh_make('hh-w');
hh_make('hh-live-target');
hh_make('hh-gone-target');
hh_set('hh-w', ['hh-live-target', 'hh-gone-target']);
SidecarStore::delete_sidecar('hh-gone-target');
$w = hh_list()['hh-w']['waiting_on'] ?? [];
$by = [];
foreach ($w as $entry) {
    $by[$entry['name']] = $entry;
}
assert_equal(2, count($w), 'both targets are listed: a target that is no longer live stays');
assert_equal(['name', 'since', 'state'], array_values(array_intersect(['name', 'since', 'state'], array_keys($w[0] ?? []))), 'each entry has name, state and since');
assert_equal('live', $by['hh-live-target']['state'] ?? null, 'a listed target is live');
assert_true(in_array($by['hh-gone-target']['state'] ?? null, ['ended', 'archived', 'unknown'], true), 'a target with no live session has a non-live state');
assert_true(is_int($by['hh-live-target']['since'] ?? null), 'since is an int');
assert_equal(false, array_key_exists('launched_by', $w[0] ?? []), 'an entry is only name, state and since');
assert_equal(null, hh_get(hh_list()['hh-w'], 'launched_by'), 'waiting without ancestry stays distinct: launched_by is null');
hh_make('hh-w-kid', 'hh-parent');
assert_equal([], hh_list()['hh-w-kid']['waiting_on'] ?? 'missing', 'ancestry without waiting stays distinct: waiting_on is empty');
hh_set('hh-w-kid', ['hh-live-target']);
$kid = hh_list()['hh-w-kid'] ?? [];
assert_equal(['hh-parent', ['hh-live-target']], [$kid['launched_by'] ?? null, hh_names($kid['waiting_on'] ?? [])], 'a row can have both, and they are independent fields');

echo "list rows: 24 hour expiry\n";
hh_make('hh-exp');
hh_make('hh-exp-live');
hh_make('hh-exp-dead');
hh_set('hh-exp', ['hh-exp-live', 'hh-exp-dead'], time() - 90000);
SidecarStore::delete_sidecar('hh-exp-dead');
$names = hh_names(hh_list()['hh-exp']['waiting_on'] ?? []);
assert_equal(['hh-exp-live'], $names, 'a non-live target older than 24 hours drops from the list; a live one never expires');
assert_equal(['hh-exp-live'], hh_names(hh_detail('hh-exp')['waiting_on'] ?? []), 'the detail drops it too');
hh_make('hh-exp2');
hh_make('hh-exp2-dead');
hh_set('hh-exp2', ['hh-exp2-dead'], time() - 86400 + 600);
SidecarStore::delete_sidecar('hh-exp2-dead');
assert_equal(['hh-exp2-dead'], hh_names(hh_list()['hh-exp2']['waiting_on'] ?? []), 'a non-live target just inside 24 hours stays, greyed');
hh_make('hh-exp3');
hh_make('hh-exp3-live');
hh_set('hh-exp3', ['hh-exp3-live'], time() - 86400 * 30);
assert_equal(['hh-exp3-live'], hh_names(hh_list()['hh-exp3']['waiting_on'] ?? []), 'a live target waited on for 30 days is still shown');

echo "session_detail\n";
$d = hh_detail('hh-kid-a');
assert_equal('hh-parent', hh_get($d, 'launched_by'), 'detail: launched_by is the parent name');
assert_equal([], $d['launched'] ?? 'missing', 'detail: a leaf launched nobody (empty list, key present)');
assert_equal([], $d['waiting_on'] ?? 'missing', 'detail: waiting_on is an empty list when none');
$d = hh_detail('hh-parent');
$launched = $d['launched'] ?? [];
sort($launched);
assert_equal(['hh-kid-a', 'hh-kid-b', 'hh-w-kid'], $launched, 'detail: launched lists every child (hh-w-kid was launched by hh-parent too)');
assert_equal(null, hh_get($d, 'launched_by'), 'detail: no parent gives null');
foreach (range(1, 12) as $i) {
    hh_make("hh-many-{$i}", 'hh-many-parent');
}
hh_make('hh-many-parent');
$launched = hh_detail('hh-many-parent')['launched'] ?? [];
sort($launched);
$expected = array_map(static fn (int $i): string => "hh-many-{$i}", range(1, 12));
sort($expected);
assert_equal($expected, $launched, 'detail: twelve children are all listed');
assert_equal(12, count(array_filter(hh_list(), static fn (array $r): bool => ($r['launched_by'] ?? null) === 'hh-many-parent')), 'list: twelve rows name the same parent');
hh_make('hh-orphan-parent-kid', 'hh-nonexistent');
assert_true(!in_array('hh-orphan-parent-kid', hh_detail('hh-parent')['launched'] ?? [], true), 'detail: a child of someone else is not listed');
hh_make('hh-d');
hh_set('hh-d', ['hh-live-target']);
$dw = hh_detail('hh-d')['waiting_on'] ?? [];
assert_equal(['hh-live-target', 'live'], [$dw[0]['name'] ?? null, $dw[0]['state'] ?? null], 'detail: waiting_on entries carry name and state');
assert_true(array_key_exists('since', $dw[0] ?? []), 'detail: and since');
assert_equal(null, hh_get(hh_detail('hh-d'), 'launched_by'), 'detail: waiting without ancestry has launched_by null');
$unknown = hh_detail('hh-no-such-session');
assert_true(($unknown['ok'] ?? null) === false || !array_key_exists('launched', $unknown), 'detail of an unknown session is a refusal, not a hierarchy');

echo "Detail path: same live, ended and expiry logic as the list\n";
// A tmux sidecar with no tmux session behind it: the sidecar exists but the session is not in the runtime's list.
SidecarStore::write_sidecar('hh-ended-target', ['workdir' => '/tmp', 'spawned_at' => time(), 'agent_session_id' => 'conv-hh-ended', 'spawned_by_app' => true, 'agent' => 'claude', 'runtime' => 'tmux']);
hh_make('hh-dw');
hh_make('hh-dw-live');
hh_set('hh-dw', ['hh-dw-live', 'hh-ended-target']);
assert_true(!isset(hh_list()['hh-ended-target']), 'fixture: the tmux sidecar is not among the list rows');
assert_true(SidecarStore::read_sidecar('hh-ended-target') !== null, 'fixture: and its sidecar still exists');
$states = [];
foreach (hh_detail('hh-dw')['waiting_on'] ?? [] as $entry) {
    $states[$entry['name']] = $entry['state'] ?? null;
}
assert_equal(['hh-dw-live' => 'live', 'hh-ended-target' => 'ended'], $states, 'detail: a target with a sidecar but no live session is ended, a listed one is live');
$states = [];
foreach (hh_list()['hh-dw']['waiting_on'] ?? [] as $entry) {
    $states[$entry['name']] = $entry['state'] ?? null;
}
assert_equal(['hh-dw-live' => 'live', 'hh-ended-target' => 'ended'], $states, 'list: the same states (detail and list agree)');
hh_make('hh-dx');
hh_set('hh-dx', ['hh-dw-live', 'hh-ended-target'], time() - 90000);
assert_equal(['hh-dw-live'], hh_names(hh_detail('hh-dx')['waiting_on'] ?? []), 'detail: an ended target older than 24 hours is dropped, a live one never expires');
assert_equal(['hh-dw-live'], hh_names(hh_list()['hh-dx']['waiting_on'] ?? []), 'list: the same');
hh_make('hh-dy');
hh_set('hh-dy', ['hh-ended-target'], time() - 3600);
assert_equal(['hh-ended-target'], hh_names(hh_detail('hh-dy')['waiting_on'] ?? []), 'detail: an ended target inside 24 hours stays');

echo "waiting_on_set: a caller that is not a session name\n";
$r = hh_act('conv-hh-ended', ['hh-t1']);
assert_equal(false, $r['ok'] ?? null, 'a conversation id as the caller is refused');
assert_contains('session name', (string)($r['message'] ?? ''), 'the refusal says a session name is required');
$r = hh_act('00000000-1111-4222-8333-444444444444', ['hh-t1']);
assert_equal(false, $r['ok'] ?? null, 'a bare UUID as the caller is refused');
assert_contains('session name', (string)($r['message'] ?? ''), 'and says so');
assert_equal(null, SidecarStore::read_sidecar('conv-hh-ended'), 'and no sidecar appears for it');

echo "Launched sessions are not waits\n";
assert_equal([], hh_stored('hh-parent'), 'the parent launched children yet waits on nobody');
assert_equal([], hh_stored('hh-kid-a'), 'and a child does not wait on its launcher');

test_exit();
