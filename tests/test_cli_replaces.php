<?php

declare(strict_types=1);

/**
 * `flow_replaces` and `sessioneer --replaces` (Contract C and D): a session that continues another one tells
 * Sessioneer which session it replaces, and Sessioneer archives the source once it is idle and holds no claim. Written from
 * the Contract only. Intent notes:
 *  - host action flow_replaces {receiver, source, agent?}: the receiver must be a known session name, the source a name or a
 *    conversation id. Refused (ok:false, a message): unknown receiver; source equal to the receiver (by name or by
 *    conversation id); source is the receiver's spawned_by parent (the message points at --rotate and --replaces on a launch);
 *    source already has an open rotation to a different receiver that still exists (the message names that receiver).
 *    Every refusal leaves the stored links untouched. An unknown or untracked source is ok:true outcome:gone and links
 *    nothing. Otherwise the link is recorded both ways (source rotated_to, receiver rotated_from), a closed link is reopened,
 *    and the reply is {ok, source, receiver, outcome, detail}; the same pair again is allowed and retries. The claims
 *    command is unset here, so the single-pair attempt answers waiting and nothing is ever killed;
 *  - CLI standalone `--replaces SOURCE [--session RECEIVER] [--json]` without a prompt: one flow_replaces request, receiver
 *    from --session or else the calling session (SESSIONEER_SELF), none: exit 1 and no host call; combines with nothing else;
 *    plain output `replaces SOURCE: <outcome> (<detail>)`, --json prints the host reply; exit 0 on ok (also outcome gone), 1 on
 *    a refusal with the message on stderr and nothing on stdout;
 *  - CLI launch form `sessioneer "prompt" --replaces SOURCE`: a launch option. After a launch that succeeded the new session
 *    is linked as receiver of SOURCE; a failed launch links nothing. The Contract does not say whether the launch request
 *    carries `replaces` or a flow_replaces request follows, so either is accepted, but the receiver must be the new
 *    session and the source the given one.
 * Isolated sidecar database and cache dir, a fake host agent that records requests: no real session, tmux, agent, HOME or
 * network is touched.
 */

// Nothing in this file may ever reach the real host agent: the CLI under test could turn an unknown flag into a launch.
putenv('SESSIONEER_AGENT_SOCKET=' . sys_get_temp_dir() . '/sessioneer-test-cli-replaces-never.sock');

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';

set_time_limit(180);

$root = sys_get_temp_dir() . '/sessioneer-test-replaces-' . getmypid() . '-' . bin2hex(random_bytes(3));
mkdir($root . '/cache', 0700, true);
foreach ([
    'HOME' => $root . '/home', 'HOME_ROOT' => $root . '/home', 'SIDECAR_DIR' => $root . '/sidecars',
    'SESSIONS_SQLITE_FILE' => $root . '/sessions.sqlite', 'CACHE_DIR' => $root . '/cache',
    'PUSH_SQLITE_FILE' => $root . '/push.sqlite', 'TMUX_SOCKET' => $root . '/tmux/socket',
    'OPENCODE_SERVE_URL' => 'http://127.0.0.1:1', 'OPENCODE_DB_PATH' => $root . '/no-opencode.db',
    'CLAUDE_HEADLESS_SOCKET' => $root . '/no-claude-manager.sock', 'CODEX_BRIDGE_SOCKET' => $root . '/no-codex-bridge.sock',
    'CLAUDE_BIN' => '/nonexistent/claude', 'CODEX_BIN' => '/nonexistent/codex',
    'SOCKET_CLIENT_FAIL_FAST_IF_ABSENT' => '1', 'SESSION_LIST_CACHE_TTL_SECONDS' => '0', 'HEADLESS_SYNC_SECONDS' => '0',
    'SESSIONEER_AGENT_SOCKET' => $root . '/no-agent.sock',
] as $key => $value) {
    putenv("{$key}={$value}");
}
foreach (['SESSIONEER_SELF', 'CODEX_THREAD_ID', 'CLAUDE_CONFIG_DIR', 'SESSIONEER_CLAIMS_CMD'] as $key) {
    putenv($key);
}
register_shutdown_function(static function () use ($root): void {
    exec('rm -rf ' . escapeshellarg($root));
});

require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

if (!str_starts_with(Config::sessions_sqlite_path(), $root . '/') || !str_starts_with(Config::sidecar_dir(), $root . '/')) {
    fwrite(STDERR, "REFUSING TO RUN: sidecar state is not isolated\n");
    exit(1);
}

/** A tracked, idle session. */
function rp_make(string $name, ?string $conversation = null, ?string $parent = null): void
{
    $row = ['workdir' => '/w/' . $name, 'agent_session_id' => $conversation ?? 'conv-' . $name, 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work'];
    if ($parent !== null) {
        $row['spawned_by'] = ['agent' => 'claude', 'profile' => 'work', 'agent_session_id' => 'conv-' . $parent, 'name' => $parent];
    }
    SidecarStore::write_sidecar($name, $row);
    SessionStatusStore::write_status($name, ['status' => 'idle', 'mode' => 'plan']);
}

/** @return array<string, mixed> */
function rp_act(mixed $receiver, mixed $source, array $extra = []): array
{
    try {
        return dispatch_action(['action' => 'flow_replaces', 'receiver' => $receiver, 'source' => $source] + $extra);
    } catch (\Throwable $e) {
        return ['ok' => null, 'threw' => $e::class . ': ' . $e->getMessage()];
    }
}

function rp_message(array $reply): string
{
    return is_string($reply['message'] ?? null) ? $reply['message'] : '';
}

/** Whether a refusal is a real one for this action, not the dispatcher not knowing it. */
function rp_refused(array $reply, string $label): void
{
    assert_equal(false, $reply['ok'] ?? null, "$label: ok false");
    assert_true(rp_message($reply) !== '' && !preg_match('/unknown action|unsupported action|not implemented/i', rp_message($reply)) && !isset($reply['threw']), "$label: a message that explains the refusal (" . json_encode($reply) . ')');
}

/** @return array{to: mixed, from: mixed, closed: mixed} */
function rp_links(string $name): array
{
    $row = SidecarStore::read_sidecar($name);
    $closed = null;
    try {
        $pdo = new PDO('sqlite:' . Config::sessions_sqlite_path());
        $stmt = $pdo->prepare('SELECT rotation_closed_at FROM sidecars WHERE session_name = ?');
        $stmt->execute([$name]);
        $closed = $stmt->fetchColumn();
        $closed = $closed === false || $closed === null ? null : (int) $closed;
    } catch (\Throwable) {
        $closed = 'unreadable';
    }

    return ['to' => $row['rotated_to'] ?? null, 'from' => $row['rotated_from'] ?? null, 'closed' => $closed];
}

function rp_reset(): void
{
    foreach (glob(Config::sessions_sqlite_path() . '*') ?: [] as $f) {
        @unlink($f);
    }
    \HostAgent\Stores\SqliteDb::reset_connections_for_tests();
}

echo "host action flow_replaces: the link\n";
rp_reset();
rp_make('old-src');
rp_make('new-recv');
$reply = rp_act('new-recv', 'old-src');
assert_equal(true, $reply['ok'] ?? null, 'link: ok true (' . json_encode($reply) . ')');
assert_equal(['old-src', 'new-recv'], [$reply['source'] ?? null, $reply['receiver'] ?? null], 'link: the reply names source and receiver');
assert_true(is_string($reply['outcome'] ?? null) && in_array($reply['outcome'], ['archived', 'waiting', 'closed', 'error'], true), 'link: outcome is one of archived, waiting, closed, error');
assert_equal('waiting', $reply['outcome'] ?? null, 'link: with the claims command unset the single-pair attempt answers waiting');
assert_true(is_string($reply['detail'] ?? null) && $reply['detail'] !== '', 'link: detail is a non-empty string');
assert_equal(['new-recv', null, null], [rp_links('old-src')['to'], rp_links('old-src')['from'], rp_links('old-src')['closed']], 'link: the source records rotated_to the receiver, open');
assert_equal('old-src', rp_links('new-recv')['from'], 'link: the receiver records rotated_from the source');
assert_true(SidecarStore::read_sidecar('old-src') !== null, 'link: the source session is not removed (nothing killed with the claims command unset)');

echo "host action flow_replaces: the source by conversation id\n";
rp_reset();
rp_make('old-src2', 'conv-source-2');
rp_make('new-recv2');
$reply = rp_act('new-recv2', 'conv-source-2');
assert_equal(true, $reply['ok'] ?? null, 'by id: ok true (' . json_encode($reply) . ')');
assert_equal('new-recv2', rp_links('old-src2')['to'], 'by id: the link is recorded on the session that owns that conversation id');
assert_equal('old-src2', rp_links('new-recv2')['from'], 'by id: and the receiver points back to its name');

echo "host action flow_replaces: the same pair again retries\n";
$again = rp_act('new-recv2', 'old-src2');
assert_equal([true, 'new-recv2'], [$again['ok'] ?? null, rp_links('old-src2')['to']], 'again: allowed, the link is unchanged');
$third = rp_act('new-recv2', 'conv-source-2');
assert_equal(true, $third['ok'] ?? null, 'again: by conversation id too');
assert_true(is_string($third['outcome'] ?? null), 'again: the retry reports an outcome');
\HostAgent\Stores\SidecarStore::close_rotation('old-src2', 1234567);
assert_equal(1234567, rp_links('old-src2')['closed'], 'again: fixture, the link was closed');
$reopened = rp_act('new-recv2', 'old-src2');
assert_equal(true, $reopened['ok'] ?? null, 'again: a closed link for the same pair is accepted');
assert_equal(null, rp_links('old-src2')['closed'], 'again: and it is reopened');

echo "host action flow_replaces: a different earlier receiver\n";
rp_reset();
rp_make('src-x');
rp_make('recv-first');
rp_make('recv-second');
assert_equal(true, (rp_act('recv-first', 'src-x'))['ok'] ?? null, 'earlier receiver: the first link is accepted');
$reply = rp_act('recv-second', 'src-x');
rp_refused($reply, 'earlier receiver: an open rotation to another existing receiver');
assert_contains('recv-first', rp_message($reply), 'earlier receiver: the message names the receiver it is already linked to');
assert_equal('recv-first', rp_links('src-x')['to'], 'earlier receiver: the stored link is untouched');
assert_equal(null, rp_links('recv-second')['from'], 'earlier receiver: nothing recorded on the refused receiver');
SidecarStore::delete_sidecar('recv-first');
$reply = rp_act('recv-second', 'src-x');
assert_equal(true, $reply['ok'] ?? null, 'earlier receiver: once that receiver is gone the new one is accepted (' . json_encode($reply) . ')');
assert_equal('recv-second', rp_links('src-x')['to'], 'earlier receiver: and replaces the stale link');
rp_make('recv-third');
SidecarStore::close_rotation('src-x', 1234567);
$reply = rp_act('recv-third', 'src-x');
assert_equal(true, $reply['ok'] ?? null, 'earlier receiver: a closed rotation to another receiver does not block a new one (' . json_encode($reply) . ')');
assert_equal(['recv-third', null], [rp_links('src-x')['to'], rp_links('src-x')['closed']], 'earlier receiver: the new link is open');

echo "host action flow_replaces: refusals\n";
rp_reset();
rp_make('ref-src');
rp_make('ref-recv');
$before = [rp_links('ref-src'), rp_links('ref-recv')];
rp_refused(rp_act('ghost', 'ref-src'), 'unknown receiver');
rp_refused(rp_act('', 'ref-src'), 'empty receiver');
rp_refused(rp_act('conv-ref-recv-not-a-name', 'ref-src'), 'a receiver that is no session name');
assert_true(SidecarStore::read_sidecar('ghost') === null, 'unknown receiver: nothing is created for it');
rp_refused(rp_act('ref-recv', 'ref-recv'), 'source equals receiver by name');
rp_refused(rp_act('ref-recv', 'conv-ref-recv'), 'source equals receiver by conversation id');
assert_equal($before, [rp_links('ref-src'), rp_links('ref-recv')], 'refusals so far: no link was recorded or changed');

rp_make('boss');
rp_make('worker', null, 'boss');
foreach (['by name' => 'boss', 'by conversation id' => 'conv-boss'] as $label => $source) {
    $reply = rp_act('worker', $source);
    rp_refused($reply, "source is the spawned_by parent $label");
    assert_contains('--rotate', rp_message($reply), "spawner refusal $label: the message points at --rotate");
    assert_contains('--replaces', rp_message($reply), "spawner refusal $label: and at launching with --replaces");
}
assert_equal([null, null], [rp_links('boss')['to'], rp_links('worker')['from']], 'spawner refusal: nothing recorded');

echo "host action flow_replaces: gone\n";
rp_reset();
rp_make('gone-recv');
foreach (['unknown name' => 'no-such-session', 'unknown conversation id' => 'conv-never-seen'] as $label => $source) {
    $reply = rp_act('gone-recv', $source);
    assert_equal([true, 'gone'], [$reply['ok'] ?? null, $reply['outcome'] ?? null], "gone $label: ok true, outcome gone (" . json_encode($reply) . ')');
    assert_true(is_string($reply['detail'] ?? null), "gone $label: detail is a string");
}
assert_equal(null, rp_links('gone-recv')['from'], 'gone: nothing recorded on the receiver');
assert_true(SidecarStore::read_sidecar('no-such-session') === null, 'gone: no session row is invented for the source');
rp_refused(rp_act('also-missing', 'no-such-session'), 'unknown receiver beats an unknown source');

echo "host action flow_replaces: the receiver by conversation id\n";
rp_reset();
rp_make('rid-src', 'conv-rid-src');
rp_make('rid-recv', 'conv-rid-recv');
$reply = rp_act('conv-rid-recv', 'rid-src');
assert_equal(true, $reply['ok'] ?? null, 'receiver by id: ok true (' . json_encode($reply) . ')');
assert_equal(['rid-src', 'rid-recv'], [$reply['source'] ?? null, $reply['receiver'] ?? null], 'receiver by id: the reply names the receiver by its resolved session name');
assert_equal('rid-recv', rp_links('rid-src')['to'], 'receiver by id: the source records rotated_to the receiver name');
assert_equal('rid-src', rp_links('rid-recv')['from'], 'receiver by id: the receiver name records rotated_from the source');
assert_true(SidecarStore::read_sidecar('conv-rid-recv') === null, 'receiver by id: nothing is recorded under the conversation id');
rp_reset();
rp_make('rid2-src', 'conv-rid2-src');
rp_make('rid2-recv', 'conv-rid2-recv');
$reply = rp_act('conv-rid2-recv', 'conv-rid2-src');
assert_equal([true, 'rid2-src', 'rid2-recv'], [$reply['ok'] ?? null, $reply['source'] ?? null, $reply['receiver'] ?? null], 'both by id: linked and both names resolved (' . json_encode($reply) . ')');
assert_equal(['rid2-recv', 'rid2-src'], [rp_links('rid2-src')['to'], rp_links('rid2-recv')['from']], 'both by id: both directions recorded under the names');
$before = [rp_links('rid2-src'), rp_links('rid2-recv')];
rp_refused(rp_act('conv-never-tracked-receiver', 'rid2-src'), 'receiver by an unknown conversation id');
assert_equal($before, [rp_links('rid2-src'), rp_links('rid2-recv')], 'unknown receiver id: links untouched');
rp_refused(rp_act('conv-rid2-recv', 'rid2-recv'), 'receiver by id, source the same session by name');
rp_refused(rp_act('rid2-recv', 'conv-rid2-recv'), 'receiver by name, source the same session by id');
assert_equal($before, [rp_links('rid2-src'), rp_links('rid2-recv')], 'self refusals by mixed name and id: links untouched');

// ---------------------------------------------------------------- the CLI

require __DIR__ . '/lib/cli_flow.php';

$rig = new CliFlowRig('cli-replaces-' . getmypid());

/** @return array<string, mixed> */
$first = static fn (): array => $rig->requests()[0] ?? [];
$okReply = ['ok' => true, 'source' => 'cc-old', 'receiver' => 'cc-self', 'outcome' => 'waiting', 'detail' => 'the source is still working'];

try {
    echo "--replaces SOURCE: standalone\n";
    $rig->reply($okReply);
    [$code, $out, $err] = $rig->run(['--replaces', 'cc-old']);
    $req = $first();
    assert_equal(0, $code, '--replaces: exit 0 (' . trim($err) . ')');
    assert_equal("replaces cc-old: waiting (the source is still working)\n", $out, '--replaces: the plain line is "replaces SOURCE: <outcome> (<detail>)"');
    assert_equal(1, count($rig->requests()), '--replaces: exactly one host call');
    assert_equal(['flow_replaces', 'cc-self', 'cc-old'], [$req['action'] ?? null, $req['receiver'] ?? null, $req['source'] ?? null], '--replaces: flow_replaces with the calling session as receiver and the given source');

    $rig->reset();
    [$code, $out] = $rig->run(['--replaces', 'cc-old', '--session', 'cc-other']);
    $req = $first();
    assert_equal(0, $code, '--replaces --session: exit 0');
    assert_equal(['flow_replaces', 'cc-other', 'cc-old'], [$req['action'] ?? null, $req['receiver'] ?? null, $req['source'] ?? null], '--replaces --session: the receiver is the named session, not the calling one');

    $rig->reset();
    $rig->run(['--replaces', 'cc-old'], ['SESSIONEER_SELF' => false, 'CODEX_THREAD_ID' => false]);
    assert_equal([], $rig->requests(), '--replaces without a receiver and without a calling session: no host call');
    [$code, $out, $err] = $rig->run(['--replaces', 'cc-old'], ['SESSIONEER_SELF' => false]);
    assert_equal(1, $code, '--replaces without a receiver: exit 1');
    assert_true(trim($err) !== '' && $out === '', '--replaces without a receiver: a message on stderr, nothing on stdout');
    assert_equal([], $rig->requests(), '--replaces without a receiver: nothing sent');

    foreach (['archived' => 'killed', 'closed' => 'receiver gone', 'gone' => 'the source is not tracked', 'error' => 'claims backend exploded'] as $outcome => $detail) {
        $rig->reset();
        $rig->reply(['ok' => true, 'source' => 'cc-old', 'receiver' => 'cc-self', 'outcome' => $outcome, 'detail' => $detail]);
        [$code, $out] = $rig->run(['--replaces', 'cc-old']);
        assert_equal([0, "replaces cc-old: {$outcome} ({$detail})\n"], [$code, $out], "--replaces: outcome $outcome prints its line and exits 0 on an ok reply");
    }

    $rig->reset();
    $rig->reply($okReply);
    [$code, $out] = $rig->run(['--replaces', 'cc-old', '--json']);
    $json = json_decode($out, true);
    assert_equal([0, true, 'waiting', 'cc-old'], [$code, $json['ok'] ?? null, $json['outcome'] ?? null, $json['source'] ?? null], '--replaces --json: the host reply as JSON');
    assert_equal(1, count($rig->requests()), '--replaces --json: one host call');

    $rig->reset();
    $rig->reply(['ok' => false, 'message' => 'cc-old is the session that launched cc-self; use --rotate, or launch with --replaces']);
    [$code, $out, $err] = $rig->run(['--replaces', 'cc-old']);
    assert_equal(1, $code, '--replaces: a refusal exits 1');
    assert_contains('launched cc-self', $err, '--replaces: the refusal message is on stderr');
    assert_equal('', $out, '--replaces: nothing on stdout for a refusal');

    echo "--replaces: validation, nothing reaches the host\n";
    $bad = [
        'with list' => [['--replaces', 'cc-old', '--list'], '--replaces'],
        'with rotate' => [['--replaces', 'cc-old', '--rotate', '--handoff', '1'], '--replaces'],
        'with offer' => [['--replaces', 'cc-old', '--offer', '--handoff', '1'], '--replaces'],
        'with send' => [['--replaces', 'cc-old', '--send', 'cc-a'], '--replaces'],
        'with kill' => [['--replaces', 'cc-old', '--kill', 'cc-a'], '--replaces'],
        'with next' => [['--replaces', 'cc-old', '--next'], '--replaces'],
        'with a tag edit' => [['--replaces', 'cc-old', '--tag-add', 'a'], '--replaces'],
        'with resume' => [['--replaces', 'cc-old', '--resume', 'abc'], '--replaces'],
        'without a source' => [['--replaces'], '--replaces'],
        'with an empty source' => [['--replaces', ''], '--replaces'],
    ];
    foreach ($bad as $label => [$args, $needle]) {
        $rig->reset();
        [$code, $out, $err] = $rig->run($args);
        assert_equal(1, $code, "--replaces $label: exit 1");
        assert_contains($needle, $err, "--replaces $label: the message mentions $needle");
        assert_equal('', $out, "--replaces $label: nothing on stdout");
        assert_equal([], $rig->requests(), "--replaces $label: no request reached the host");
    }

    echo "--replaces with a prompt: a launch option\n";
    $launched = ['ok' => true, 'name' => 'cc-new-child', 'action' => 'created', 'source' => 'cc-old', 'receiver' => 'cc-new-child', 'outcome' => 'waiting', 'detail' => 'the receiver has not started yet'];
    $links = static function (array $requests) use ($rig): array {
        $found = [];
        foreach ($requests as $request) {
            if (($request['action'] ?? null) === 'flow_replaces') {
                $found[] = [$request['receiver'] ?? null, $request['source'] ?? null];
            } elseif (($request['action'] ?? null) === 'launch' && isset($request['replaces'])) {
                $found[] = ['cc-new-child', $request['replaces']];
            }
        }

        return $found;
    };
    $rig->reset();
    $rig->reply($launched);
    [$code, $out, $err] = $rig->run(['--replaces', 'cc-old', 'Continue the migration from the handoff.']);
    $requests = $rig->requests();
    assert_equal(0, $code, 'launch --replaces: exit 0 (' . trim($err) . ')');
    assert_equal('launch', $requests[0]['action'] ?? null, 'launch --replaces: the first host call is the launch');
    assert_equal('Continue the migration from the handoff.', $requests[0]['prompt'] ?? null, 'launch --replaces: the prompt is sent untouched');
    assert_equal([['cc-new-child', 'cc-old']], $links($requests), 'launch --replaces: the new session is linked as receiver of the source, once');
    assert_true(str_contains($out, 'cc-new-child'), 'launch --replaces: the new session name is still printed');
    foreach ($requests as $request) {
        if (($request['action'] ?? null) === 'flow_replaces') {
            assert_true(($request['receiver'] ?? null) !== 'cc-self', 'launch --replaces: the receiver is the new session, never the calling one');
        }
    }

    $rig->reset();
    $rig->reply(['ok' => false, 'message' => 'Could not start the session']);
    [$code, , $err] = $rig->run(['--replaces', 'cc-old', 'Continue the migration from the handoff.']);
    assert_equal(1, $code, 'launch --replaces, launch fails: exit 1');
    assert_equal([], $links($rig->requests()), 'launch --replaces, launch fails: no link is recorded');
    assert_true(count(array_filter($rig->requests(), static fn (array $r): bool => ($r['action'] ?? null) === 'flow_replaces')) === 0, 'launch --replaces, launch fails: flow_replaces is never called');
} finally {
    $rig->stop();
}

echo "OK\n";
test_exit();
