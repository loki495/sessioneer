<?php

declare(strict_types=1);

// FlowService::archive_replaced(array $deps): array (task 1786, plan step 1978; Contract A and B).
// Sessioneer, not the receiver, archives a rotated source once it is safe. Candidate = sidecar with a non-empty
// rotated_to and rotation_closed_at NULL (column set by SidecarStore::close_rotation(string $source, int $now)).
// Steps per candidate, with fresh reads, in this order:
//   a. source and receiver sidecars exist, receiver != source (source gone: closed; receiver gone: closed, no kill)
//   b. SESSIONEER_CLAIMS_CMD set, else waiting
//   c. the receiver has started (a conversation id), else waiting ("the receiver has not started yet"); the
//      receiver's claims are never read (no receiver-claim gate)
//   d. source has a conversation id and status exactly idle and its claims list empty (strict read), else waiting
//   e. $deps['kill'](['target' => name, 'agent' => agent]) never with force: ok:true archived,
//      ok:false "unknown/not found" closed, other ok:false waiting
// FlowService::archive_source(string $source, array $deps): array runs a-e for one open rotation under the
// flow-reap lock (busy: outcome waiting, "a reap pass is running"); rotate() calls it and adds archive:{outcome,detail}.
// Isolation: temp SQLite files and cache dir; fake `runner` (claims command) and `kill` deps that record
// calls. No process, session, tmux or network is touched. Written before the implementation: every call
// goes through $run(), which reports a missing method as per-assertion failures instead of a fatal.
//
// Deps shape pinned here: clock (): int, runner (string $command, string $conversationId): array{exit,stdout},
// kill (array $request): array. Result: ['ok' => true, 'results' => list<['source','receiver','outcome','detail']>]
// plus 'skipped' => true when the per-run lock is held.

$autoload = __DIR__ . '/../vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    spl_autoload_register(static function (string $c): void {
        foreach (['HostAgent\\' => '/../host-agent/lib/', 'App\\' => '/../src/lib/'] as $prefix => $dirPart) {
            $f = __DIR__ . $dirPart . str_replace('\\', '/', substr($c, strlen($prefix))) . '.php';
            if (str_starts_with($c, $prefix) && is_file($f)) {
                require $f;
            }
        }
    });
}
require __DIR__ . '/lib/assert.php';

$dir = sys_get_temp_dir() . '/sessioneer-test-flow-archive-replaced-' . getmypid();
mkdir($dir, 0700, true);
mkdir($dir . '/cache', 0700, true);
register_shutdown_function(static function () use ($dir): void {
    exec('rm -rf ' . escapeshellarg($dir));
});
putenv("PUSH_SQLITE_FILE={$dir}/push.sqlite");
putenv("SESSIONS_SQLITE_FILE={$dir}/sessions.sqlite");
putenv("SIDECAR_DIR={$dir}/sidecars");
putenv("CACHE_DIR={$dir}/cache");
putenv('SESSIONEER_CLAIMS_CMD=/usr/bin/claims-of');
putenv('CLAUDE_BIN=/fake/claude');
putenv('CODEX_BIN=/fake/codex');

use HostAgent\SessionFlow\FlowService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\SessionFlow\Thresholds;
use HostAgent\Stores\SqliteDb;

$NOW = 2_000_000;
$CMD = '/usr/bin/claims-of';
$LOCK = $dir . '/cache/flow-reap.lock';

$reset = static function () use ($dir): void {
    foreach (glob($dir . '/*.sqlite*') ?: [] as $f) {
        @unlink($f);
    }
    SqliteDb::reset_connections_for_tests();
    putenv('SESSIONEER_CLAIMS_CMD=/usr/bin/claims-of');
};

/** rotation_closed_at read straight from the fixture database: int, null, 'no row' or 'no column'. */
$closedAt = static function (string $name) use ($dir): int|string|null {
    try {
        $pdo = new PDO('sqlite:' . $dir . '/sessions.sqlite');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $stmt = $pdo->prepare('SELECT rotation_closed_at FROM sidecars WHERE session_name = ?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();

        return $value === false ? 'no row' : ($value === null ? null : (int) $value);
    } catch (Throwable) {
        return 'no column';
    }
};

/** A candidate pair: source rotated to receiver, as a successful fresh rotate leaves it. */
$mk = static function (string $src, string $recv, array $o = []): void {
    $srcId = array_key_exists('src_id', $o) ? $o['src_id'] : "conv-{$src}";
    $recvId = array_key_exists('recv_id', $o) ? $o['recv_id'] : "conv-{$recv}";
    SidecarStore::write_sidecar($src, ['workdir' => '/w/' . $src, 'agent_session_id' => $srcId, 'agent' => $o['src_agent'] ?? 'claude', 'runtime' => 'headless', 'profile' => 'work']);
    $status = array_key_exists('src_status', $o) ? $o['src_status'] : ['status' => 'idle', 'mode' => 'plan'];
    if ($status !== null) {
        SessionStatusStore::write_status($src, $status);
    }
    if (($o['recv_exists'] ?? true) === true) {
        SidecarStore::write_sidecar($recv, ['workdir' => '/w/' . $recv, 'agent_session_id' => $recvId, 'agent' => 'claude', 'runtime' => 'headless']);
        SessionStatusStore::write_status($recv, ['status' => 'working', 'mode' => 'plan']);
        SidecarStore::set_rotation($recv, $src, null);
    }
    SidecarStore::set_rotation($src, null, $recv);
};

/**
 * @param array<string, mixed> $claims conversation id => stdout string | ['exit' =>, 'stdout' =>] | callable(string $conv): array
 * @return array{0: array<string, mixed>, 1: stdClass}
 */
$make = static function (array $claims = [], ?callable $kill = null) use ($NOW): array {
    $log = new stdClass();
    $log->runner = [];
    $log->kill = [];
    $deps = [
        'clock' => static fn (): int => $NOW,
        'runner' => static function (string $command, string $conv) use ($log, $claims): array {
            $log->runner[] = [$command, $conv];
            $answer = $claims[$conv] ?? '[]';
            if (is_callable($answer)) {
                $answer = $answer($conv);
            }

            return is_array($answer) ? $answer : ['exit' => 0, 'stdout' => $answer];
        },
        'kill' => static function (array $request) use ($log, $kill): array {
            $log->kill[] = $request;

            return $kill !== null ? $kill($request) : ['ok' => true, 'message' => 'archived'];
        },
    ];

    return [$deps, $log];
};

$run = static function (array $deps): array {
    if (!method_exists(FlowService::class, 'archive_replaced')) {
        return ['ok' => null, 'results' => null, 'missing' => true];
    }
    try {
        return FlowService::archive_replaced($deps);
    } catch (Throwable $e) {
        return ['ok' => null, 'results' => null, 'threw' => $e::class . ': ' . $e->getMessage()];
    }
};
$bySource = static function (array $r): array {
    $by = [];
    foreach ((array) ($r['results'] ?? []) as $row) {
        if (is_array($row) && isset($row['source'])) {
            $by[(string) $row['source']] = $row;
        }
    }

    return $by;
};
$outcome = static fn (array $r, string $src): mixed => ($bySource($r))[$src]['outcome'] ?? 'no entry';
$norm = static function (array $requests): array {
    return array_map(static function (array $q): array {
        ksort($q);

        return $q;
    }, $requests);
};
/** Every kill request names an allowed source, an agent, and never carries force. */
$safe = static function (stdClass $log, array $allowed, string $label) use ($norm): void {
    foreach ($log->kill as $q) {
        assert_true(is_array($q) && in_array($q['target'] ?? null, $allowed, true), "$label: only a candidate source is ever killed (" . json_encode($q) . ')');
        assert_true(is_array($q) && !array_key_exists('force', $q) && is_string($q['agent'] ?? null) && $q['agent'] !== '', "$label: kill never carries force and always names the agent");
    }
};
/** close_rotation behind a guard so a missing method fails assertions instead of aborting the file. */
$close = static function (string $source, int $now): void {
    if (method_exists(SidecarStore::class, 'close_rotation')) {
        SidecarStore::close_rotation($source, $now);
    }
};

echo "api\n";
assert_true(method_exists(FlowService::class, 'archive_replaced'), 'FlowService::archive_replaced exists');
assert_true(method_exists(SidecarStore::class, 'close_rotation'), 'SidecarStore::close_rotation exists');

echo "no candidates\n";
$reset();
SidecarStore::write_sidecar('plain', ['workdir' => '/w', 'agent_session_id' => 'conv-plain', 'agent' => 'claude', 'runtime' => 'headless']);
SessionStatusStore::write_status('plain', ['status' => 'idle', 'mode' => 'plan']);
SidecarStore::write_sidecar('only-receiver', ['workdir' => '/w', 'agent_session_id' => 'conv-or', 'agent' => 'claude', 'runtime' => 'headless']);
SidecarStore::set_rotation('only-receiver', 'plain', null);
SidecarStore::write_sidecar('empty-to', ['workdir' => '/w', 'agent_session_id' => 'conv-et', 'agent' => 'claude', 'runtime' => 'headless']);
SessionStatusStore::write_status('empty-to', ['status' => 'idle', 'mode' => 'plan']);
SidecarStore::set_rotation('empty-to', null, '');
[$deps, $log] = $make();
$r = $run($deps);
assert_equal(true, $r['ok'] ?? null, 'none: ok true');
assert_equal([], $r['results'] ?? null, 'none: a plain session, a receiver (rotated_from only) and an empty rotated_to are not candidates');
assert_true(!isset($r['skipped']), 'none: not skipped');
assert_equal([[], []], [$log->kill, $log->runner], 'none: nothing killed, no claims command run');
assert_true(is_file($LOCK), 'none: the per-run lock file is flow-reap.lock in the cache dir');
$reset();
[$deps, $log] = $make();
assert_equal([true, []], [($run($deps))['ok'] ?? null, ($run($deps))['results'] ?? null], 'none: an empty database is ok with no results');

echo "happy path: receiver started, source idle and claim-free (the receiver's claims are never read)\n";
$reset();
$mk('s1', 'r1');
$mk('s-other', 'r-other', ['recv_exists' => false]);
$close('s-other', $NOW);
[$deps, $log] = $make(['conv-s1' => '[]']);
$r = $run($deps);
assert_equal(true, $r['ok'] ?? null, 'happy: ok');
assert_equal(1, count((array) ($r['results'] ?? [])), 'happy: one entry, only for the candidate');
$row = $bySource($r)['s1'] ?? [];
assert_equal(['s1', 'r1', 'archived'], [$row['source'] ?? null, $row['receiver'] ?? null, $row['outcome'] ?? null], 'happy: entry names source, receiver and outcome archived');
assert_true(is_string($row['detail'] ?? null), 'happy: detail is a string');
assert_equal([['agent' => 'claude', 'target' => 's1']], $norm($log->kill), 'happy: kill called exactly once with only target and agent');
assert_equal([[$CMD, 'conv-s1']], $log->runner, 'happy: the claims command runs once, for the source id only; the receiver id is never asked');
assert_equal($NOW, $closedAt('s1'), 'happy: rotation_closed_at equals clock() at close');
assert_equal(null, $closedAt('r1'), 'happy: nothing is marked on the receiver');
$r2 = $run($deps);
assert_equal(true, $r2['ok'] ?? null, 'happy: second call ok');
assert_equal([], $r2['results'] ?? null, 'happy: a second call right after reports nothing for it');
assert_equal(1, count($log->kill), 'happy: and does not kill again');
SidecarStore::write_sidecar('s1', ['workdir' => '/w/s1', 'agent_session_id' => 'conv-s1', 'agent' => 'claude', 'runtime' => 'headless', 'profile' => 'work', 'title' => 'sync rewrote me']);
assert_equal([], ($run($deps))['results'] ?? null, 'happy: a sidecar rewrite (headless sync) does not reopen the candidate');
$safe($log, ['s1'], 'happy');

echo "claims list shapes and the source agent\n";
$reset();
$mk('s2', 'r2', ['src_agent' => 'codex']);
[$deps, $log] = $make(['conv-s2' => '{"claims":[]}']);
$r = $run($deps);
assert_equal('archived', $outcome($r, 's2'), 'shapes: {"claims":[]} for the source archives');
assert_equal([['agent' => 'codex', 'target' => 's2']], $norm($log->kill), 'shapes: kill names the source agent from its sidecar');

echo "b. claims command not configured fails closed\n";
$reset();
putenv('SESSIONEER_CLAIMS_CMD');
$mk('s3', 'r3');
[$deps, $log] = $make();
$r = $run($deps);
assert_equal('waiting', $outcome($r, 's3'), 'unset cmd: waiting');
assert_equal([[], []], [$log->kill, $log->runner], 'unset cmd: nothing archived and no command run');
assert_equal(null, $closedAt('s3'), 'unset cmd: still a candidate');
putenv('SESSIONEER_CLAIMS_CMD=/usr/bin/claims-of');
$r = $run($deps);
assert_equal('archived', $outcome($r, 's3'), 'unset cmd: once configured the same candidate is archived');
$reset();
putenv('SESSIONEER_CLAIMS_CMD=');
$mk('s3e', 'r3e');
[$deps, $log] = $make();
$r = $run($deps);
assert_equal('waiting', $outcome($r, 's3e'), 'empty cmd (SESSIONEER_CLAIMS_CMD=): waiting');
assert_equal([[], []], [$log->kill, $log->runner], 'empty cmd: no runner call and nothing killed');
assert_equal(null, $closedAt('s3e'), 'empty cmd: still a candidate');
putenv('SESSIONEER_CLAIMS_CMD=/usr/bin/claims-of');

echo "c. the receiver's claims are never read; a receiver that has not started waits\n";
$cases = [
    'no claims' => ['[]'], 'a claim' => ['[{"id":1}]'], 'command failed' => [['exit' => 1, 'stdout' => '']],
    'not json' => ['not-json'], 'empty output' => [''], 'literal null' => ['null'], 'claims null' => ['{"claims":null}'],
    'a runner that throws' => [static function (): array {
        throw new RuntimeException('receiver claims must not be asked');
    }],
];
foreach ($cases as $label => [$answer]) {
    $reset();
    $mk('s4', 'r4');
    [$deps, $log] = $make(['conv-r4' => $answer]);
    $r = $run($deps);
    assert_equal('archived', $outcome($r, 's4'), "receiver claims $label: archived, the receiver's claims decide nothing");
    assert_equal([['agent' => 'claude', 'target' => 's4']], $norm($log->kill), "receiver claims $label: the source is killed once");
    assert_true(!in_array([$CMD, 'conv-r4'], $log->runner, true), "receiver claims $label: the claims command is never run with the receiver id");
    assert_equal($NOW, $closedAt('s4'), "receiver claims $label: the candidate is closed at clock()");
}
foreach (['null id' => null, 'empty id' => ''] as $label => $id) {
    $reset();
    $mk('s5', 'r5', ['recv_id' => $id]);
    [$deps, $log] = $make();
    $r = $run($deps);
    assert_equal('waiting', $outcome($r, 's5'), "receiver $label: waiting");
    assert_contains('the receiver has not started yet', (string) (($bySource($r))['s5']['detail'] ?? ''), "receiver $label: the detail says the receiver has not started yet");
    assert_equal([], $log->kill, "receiver $label: nothing archived");
    assert_equal(null, $closedAt('s5'), "receiver $label: stays a candidate");
    foreach ($log->runner as [$c, $conv]) {
        assert_true($conv !== '', "receiver $label: the claims command is never run with an empty id");
    }
}
$reset();
$mk('s6', 'r6', ['recv_id' => null]);
[$deps, $log] = $make();
assert_equal('waiting', $outcome($run($deps), 's6'), 'receiver start retry: waiting while the receiver has no conversation id');
SidecarStore::write_sidecar('r6', ['workdir' => '/w/r6', 'agent_session_id' => 'conv-r6', 'agent' => 'claude', 'runtime' => 'headless']);
assert_equal('archived', $outcome($run($deps), 's6'), 'receiver start retry: archived on a later pass once the receiver has started');
$reset();
$mk('s6b', 'r6b', ['recv_id' => null, 'src_status' => ['status' => 'working', 'mode' => 'plan']]);
[$deps, $log] = $make();
assert_equal('waiting', $outcome($run($deps), 's6b'), 'receiver not started and source working: waiting');
assert_equal([], $log->kill, 'receiver not started and source working: nothing archived');

echo "d. the source must be idle\n";
foreach (['Idle (capitalised)' => ['status' => 'Idle', 'mode' => 'plan'], 'idle with a leading space' => ['status' => ' idle', 'mode' => 'plan'], 'working' => ['status' => 'working', 'mode' => 'plan'], 'blocked' => ['status' => 'blocked', 'mode' => 'plan', 'blocked' => ['tool' => 'Bash']], 'unknown' => ['status' => 'unknown', 'mode' => 'plan'], 'no status row' => null, 'empty status' => ['status' => '', 'mode' => 'plan']] as $label => $status) {
    $reset();
    $mk('s7', 'r7', ['src_status' => $status]);
    [$deps, $log] = $make();
    $r = $run($deps);
    assert_equal('waiting', $outcome($r, 's7'), "source $label: waiting");
    assert_equal([], $log->kill, "source $label: never killed");
    assert_equal(null, $closedAt('s7'), "source $label: stays a candidate");
}

echo "d. the source must hold no claim\n";
$cases = [
    'a claim' => ['[{"id":3}]'], 'a claims object' => ['{"claims":[{"id":3}]}'], 'command failed' => [['exit' => 1, 'stdout' => '[]']],
    'not json' => ['{oops'], 'empty output' => [''],
    'scalar 5' => ['5'], 'scalar string' => ['"x"'], 'scalar true' => ['true'], 'literal null' => ['null'],
    'object without a claims key (a non-empty list as in rotate)' => ['{"other":1}'],
];
foreach ($cases as $label => [$answer]) {
    $reset();
    $mk('s8', 'r8');
    [$deps, $log] = $make(['conv-s8' => $answer]);
    $r = $run($deps);
    assert_equal('waiting', $outcome($r, 's8'), "source $label: waiting");
    assert_equal([], $log->kill, "source $label: nothing archived");
    assert_equal(null, $closedAt('s8'), "source $label: stays a candidate");
}
foreach (['null id' => null, 'empty id' => ''] as $label => $id) {
    $reset();
    $mk('s9', 'r9', ['src_id' => $id]);
    [$deps, $log] = $make();
    $r = $run($deps);
    assert_equal('waiting', $outcome($r, 's9'), "source $label: waiting, claims cannot be checked");
    assert_equal([], $log->kill, "source $label: nothing archived");
    foreach ($log->runner as [$c, $conv]) {
        assert_true($conv !== '', "source $label: the claims command is never run with an empty id");
    }
}
$reset();
$mk('s9b', 'r9b');
$held = true;
[$deps, $log] = $make(['conv-s9b' => static function () use (&$held): array {
    return ['exit' => 0, 'stdout' => $held ? '[{"id":5}]' : '[]'];
}]);
assert_equal('waiting', $outcome($run($deps), 's9b'), 'source claim retry: waiting while it holds one');
$held = false;
assert_equal('archived', $outcome($run($deps), 's9b'), 'source claim retry: archived once it released it');

echo "a. a vanished session closes the candidate\n";
$reset();
putenv('SESSIONEER_CLAIMS_CMD');
$mk('s10', 'r10', ['recv_exists' => false]);
[$deps, $log] = $make();
$r = $run($deps);
assert_equal('closed', $outcome($r, 's10'), 'receiver gone: closed (even with the claims command unset, step a comes first)');
assert_equal([[], []], [$log->kill, $log->runner], 'receiver gone: the source is not archived and no command ran');
assert_equal($NOW, $closedAt('s10'), 'receiver gone: rotation_closed_at equals clock() at close');
assert_true(SidecarStore::read_sidecar('s10') !== null, 'receiver gone: the source sidecar is untouched');
assert_equal([], ($run($deps))['results'] ?? null, 'receiver gone: a second call reports nothing');
$reset();
$mk('s10b', 's10b');
[$deps, $log] = $make();
$r = $run($deps);
assert_equal('closed', $outcome($r, 's10b'), 'receiver is the source: closed');
assert_equal([], $log->kill, 'receiver is the source: nothing killed');
assert_equal($NOW, $closedAt('s10b'), 'receiver is the source: closed at clock()');
assert_equal([], ($run($deps))['results'] ?? null, 'receiver is the source: a second call reports nothing');

// Two candidates; the first one processed removes a session of the other between listing and handling it.
foreach (['source' => 'source sidecar', 'receiver' => 'receiver sidecar'] as $which => $label) {
    $reset();
    $mk('sa', 'ra');
    $mk('sb', 'rb');
    $owner = ['conv-ra' => 'sa', 'conv-sa' => 'sa', 'conv-rb' => 'sb', 'conv-sb' => 'sb'];
    $deleted = null;
    $hook = static function (string $conv) use (&$deleted, $owner, $which): array {
        if ($deleted === null) {
            $other = $owner[$conv] === 'sa' ? 'sb' : 'sa';
            $deleted = $other;
            SidecarStore::delete_sidecar($which === 'source' ? $other : 'r' . substr($other, 1));
        }

        return ['exit' => 0, 'stdout' => str_starts_with($conv, 'conv-r') ? '[{"id":1}]' : '[]'];
    };
    [$deps, $log] = $make(['conv-ra' => $hook, 'conv-sa' => $hook, 'conv-rb' => $hook, 'conv-sb' => $hook]);
    $r = $run($deps);
    $outcomes = array_column((array) ($r['results'] ?? []), 'outcome');
    sort($outcomes);
    assert_equal(['archived', 'closed'], $outcomes, "fresh read: a $label deleted mid-pass closes only that candidate, the other is archived");
    assert_equal('closed', $outcome($r, (string) $deleted), "fresh read ($which gone): the affected candidate is closed");
    $other = $deleted === 'sa' ? 'sb' : 'sa';
    assert_equal([['agent' => 'claude', 'target' => $other]], $norm($log->kill), "fresh read ($which gone): only the healthy candidate's source is killed");
    assert_equal([], ($run($deps))['results'] ?? null, "fresh read ($which gone): a second call reports nothing");
}

echo "e. the kill reply decides the outcome\n";
$replies = [
    'unknown session' => [['ok' => false, 'message' => 'Unknown session s11'], 'closed', true],
    'UNKNOWN uppercase' => [['ok' => false, 'message' => 'UNKNOWN SESSION s11'], 'closed', true],
    'NOT FOUND uppercase' => [['ok' => false, 'message' => 'Session s11 NOT FOUND'], 'closed', true],
    'not found' => [['ok' => false, 'message' => 'Session s11 not found'], 'closed', true],
    'became working' => [['ok' => false, 'message' => 'Session s11 is working; refusing without force'], 'waiting', false],
    'became blocked' => [['ok' => false, 'message' => 'Session s11 is blocked on a prompt'], 'waiting', false],
    'refused without a message' => [['ok' => false], 'waiting', false],
    'ok' => [['ok' => true, 'message' => 'done'], 'archived', true],
];
foreach ($replies as $label => [$reply, $expectOutcome, $closes]) {
    $reset();
    $mk('s11', 'r11');
    [$deps, $log] = $make([], static fn (array $q): array => $reply);
    $r = $run($deps);
    assert_equal($expectOutcome, $outcome($r, 's11'), "kill $label: outcome $expectOutcome");
    assert_equal(1, count($log->kill), "kill $label: asked once");
    assert_true(is_string(($bySource($r))['s11']['detail'] ?? null), "kill $label: detail is a string");
    $closed = $closedAt('s11');
    assert_equal($closes ? $NOW : null, $closed, "kill $label: " . ($closes ? 'closed at clock()' : 'the candidate stays open'));
    $again = $run($deps);
    if ($closes) {
        assert_equal([], $again['results'] ?? null, "kill $label: closed, so the next pass reports nothing");
        assert_equal(1, count($log->kill), "kill $label: and does not kill again");
    } else {
        assert_equal('waiting', $outcome($again, 's11'), "kill $label: still a candidate on the next pass");
        assert_equal(2, count($log->kill), "kill $label: retried on the next pass");
    }
    $safe($log, ['s11'], "kill $label");
}

echo "exceptions are isolated per candidate\n";
$reset();
$mk('sx', 'rx');
$mk('sy', 'ry');
[$deps, $log] = $make(['conv-sx' => static function (): array {
    throw new RuntimeException('claims backend exploded');
}]);
$r = $run($deps);
assert_equal(true, $r['ok'] ?? null, 'runner throws: the pass is still ok');
assert_equal(2, count((array) ($r['results'] ?? [])), 'runner throws: one entry per candidate');
assert_equal(['error', 'archived'], [$outcome($r, 'sx'), $outcome($r, 'sy')], 'runner throws: error for the broken one, the other still archived');
assert_equal([['agent' => 'claude', 'target' => 'sy']], $norm($log->kill), 'runner throws: only the healthy source killed');
assert_true(is_string(($bySource($r))['sx']['detail'] ?? null), 'runner throws: error entry has a detail string');
assert_equal(null, $closedAt('sx'), 'runner throws: the errored candidate stays open');
$boom = true;
[$deps2, $log2] = $make(['conv-sx' => static function () use (&$boom): array {
    if ($boom) {
        throw new RuntimeException('claims backend exploded');
    }

    return ['exit' => 0, 'stdout' => '[]'];
}]);
assert_equal('error', $outcome($run($deps2), 'sx'), 'error retry: still an error on the next pass');
$boom = false;
assert_equal('archived', $outcome($run($deps2), 'sx'), 'error retry: archived once the failure is gone');
$reset();
$mk('sx', 'rx');
$mk('sy', 'ry');
[$deps, $log] = $make([], static function (array $q): array {
    if ($q['target'] === 'sx') {
        throw new RuntimeException('kill exploded');
    }

    return ['ok' => true];
});
$r = $run($deps);
assert_equal(['error', 'archived'], [$outcome($r, 'sx'), $outcome($r, 'sy')], 'kill throws: error for that candidate, the other still archived');
assert_equal(2, count($log->kill), 'kill throws: both were attempted');
assert_equal($NOW, $closedAt('sy'), 'kill throws: the archived one is closed at clock()');
assert_equal(null, $closedAt('sx'), 'kill throws: the errored candidate stays open for retry');
$lockProbe = fopen($LOCK, 'c');
assert_true($lockProbe !== false && flock($lockProbe, LOCK_EX | LOCK_NB), 'kill throws: the run lock was released');
if ($lockProbe !== false) {
    flock($lockProbe, LOCK_UN);
    fclose($lockProbe);
}

echo "several candidates, one entry each\n";
$reset();
$mk('m1', 'n1');
$mk('m2', 'n2');
$mk('m3', 'n3', ['recv_exists' => false]);
$mk('m4', 'n4', ['src_status' => ['status' => 'working', 'mode' => 'plan']]);
SidecarStore::write_sidecar('bystander', ['workdir' => '/w', 'agent_session_id' => 'conv-by', 'agent' => 'claude', 'runtime' => 'headless']);
SessionStatusStore::write_status('bystander', ['status' => 'idle', 'mode' => 'plan']);
[$deps, $log] = $make(['conv-m2' => '[]']);
$r = $run($deps);
assert_equal(4, count((array) ($r['results'] ?? [])), 'mixed: four candidates, four entries, nothing for the bystander');
assert_equal(['archived', 'archived', 'closed', 'waiting'], [$outcome($r, 'm1'), $outcome($r, 'm2'), $outcome($r, 'm3'), $outcome($r, 'm4')], 'mixed: each candidate gets its own outcome (m2 archives although its receiver holds no claim)');
assert_equal(['n1', 'n2', 'n3', 'n4'], array_map(static fn (string $s): mixed => ($bySource($r))[$s]['receiver'] ?? null, ['m1', 'm2', 'm3', 'm4']), 'mixed: each entry names its receiver');
assert_equal([['agent' => 'claude', 'target' => 'm1'], ['agent' => 'claude', 'target' => 'm2']], $norm($log->kill), 'mixed: exactly two kills, for the two archived sources');
$safe($log, ['m1', 'm2'], 'mixed');
assert_true(SidecarStore::read_sidecar('bystander') !== null && SidecarStore::read_sidecar('n1') !== null, 'mixed: receivers and bystanders are never touched');

echo "claims lists: null inside an object counts as empty\n";
$reset();
$mk('t0', 'u0');
[$deps, $log] = $make(['conv-t0' => '{}']);
assert_equal('archived', $outcome($run($deps), 't0'), 'source {} is an empty list, claim-free (rotate semantics)');
$reset();
$mk('t1', 'u1');
[$deps, $log] = $make(['conv-t1' => '{"claims":null}']);
assert_equal('archived', $outcome($run($deps), 't1'), 'source {"claims":null} is claim-free');
$reset();
$mk('t2', 'u2');
[$deps, $log] = $make(['conv-u2' => '{"claims":null}']);
assert_equal('archived', $outcome($run($deps), 't2'), 'receiver {"claims":null} is not read, so the source is archived');
assert_equal([['agent' => 'claude', 'target' => 't2']], $norm($log->kill), 'receiver {"claims":null}: the source is killed');

echo "set_rotation reopens a closed link\n";
$reset();
$mk('o1', 'p1');
$close('o1', 1234567);
SidecarStore::set_rotation('o1', 'earlier-source', null);
assert_equal(1234567, $closedAt('o1'), 'reopen: set_rotation with only a from value keeps it closed');
SidecarStore::set_rotation('o1', null, 'p1');
assert_equal(null, $closedAt('o1'), 'reopen: set_rotation with a non-null to sets rotation_closed_at back to NULL');
[$deps, $log] = $make();
assert_equal('archived', $outcome($run($deps), 'o1'), 'reopen: the source is a candidate again');
$reset();
$mk('o2', 'p2');
$close('o2', 1234567);
$logRot = new stdClass();
$logRot->kill = [];
$rotateDeps = [
    'clock' => static fn (): int => $NOW,
    'facts' => static fn (string $s, array $row): ?array => $s === 'o2' ? ['context_tokens' => 260000, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']] : null,
    'quota' => static fn (string $k): ?array => null,
    'receivers' => ['claude/work'],
    'thresholds' => Thresholds::fromFile($dir . '/none.conf'),
    // The new receiver exists but has not started (no conversation id yet), as right after a launch.
    'launch' => static function (array $req): array {
        SidecarStore::write_sidecar('p2-new', ['workdir' => '/w/p2-new', 'agent' => 'claude', 'runtime' => 'headless']);

        return ['ok' => true, 'name' => 'p2-new', 'message' => ''];
    },
    'runner' => static fn (string $command, string $conv): array => ['exit' => 0, 'stdout' => '[]'],
    'kill' => static function (array $request) use ($logRot): array {
        $logRot->kill[] = $request;

        return ['ok' => true, 'message' => 'archived'];
    },
];
[$deps, $log] = $make();
$rot = FlowService::rotate(['session' => 'o2', 'handoff_id' => '5'], $rotateDeps);
assert_equal([true, 'p2-new'], [$rot['ok'] ?? null, $rot['session'] ?? null], 'reopen: the second rotate of a closed source succeeds');
assert_equal(['waiting'], [$rot['archive']['outcome'] ?? null], 'reopen: rotate replies archive.outcome waiting while the receiver has not started');
assert_contains('the receiver has not started yet', (string) ($rot['archive']['detail'] ?? ''), 'reopen: rotate replies archive.detail, the receiver has not started yet');
assert_equal(null, $closedAt('o2'), 'reopen: a successful fresh rotate reopens the link');
assert_equal([], $logRot->kill, 'reopen: nothing is killed while the receiver has not started');
SidecarStore::write_sidecar('p2-new', ['workdir' => '/w/p2-new', 'agent_session_id' => 'conv-p2-new', 'agent' => 'claude', 'runtime' => 'headless']);
$r = $run($deps);
assert_equal('archived', $outcome($r, 'o2'), 'reopen: the re-rotated source is archived on a pass after the new receiver started, with no claim on it');
assert_equal('p2-new', ($bySource($r))['o2']['receiver'] ?? null, 'reopen: and the entry names the new receiver');

echo "rotate() archives the source right away when the receiver has started (Contract B)\n";
$reset();
$mk('o5', 'p5');
$close('o5', 1234567);
$log5 = new stdClass();
$log5->kill = [];
$started = $rotateDeps;
$started['facts'] = static fn (string $s, array $row): ?array => ['context_tokens' => 260000, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']];
$started['launch'] = static function (array $req): array {
    SidecarStore::write_sidecar('p5-new', ['workdir' => '/w/p5-new', 'agent_session_id' => 'conv-p5-new', 'agent' => 'claude', 'runtime' => 'headless']);

    return ['ok' => true, 'name' => 'p5-new', 'message' => ''];
};
$started['kill'] = static function (array $request) use ($log5): array {
    $log5->kill[] = $request;

    return ['ok' => true, 'message' => 'archived'];
};
putenv('SESSIONEER_CLAIMS_CMD=/usr/bin/claims-of');
$rot = FlowService::rotate(['session' => 'o5', 'handoff_id' => '5'], $started);
assert_equal(true, $rot['ok'] ?? null, 'rotate archive: ok');
assert_equal('archived', $rot['archive']['outcome'] ?? null, 'rotate archive: archive.outcome archived right after the link is recorded');
assert_true(is_string($rot['archive']['detail'] ?? null), 'rotate archive: archive.detail is a string');
assert_equal([['agent' => 'claude', 'target' => 'o5']], $norm($log5->kill), 'rotate archive: the source is killed once, without force');
assert_equal($NOW, $closedAt('o5'), 'rotate archive: the link is closed at clock()');
$reset();
$mk('o6', 'p6');
$log5->kill = [];
putenv('SESSIONEER_CLAIMS_CMD');
$rot = FlowService::rotate(['session' => 'o6', 'handoff_id' => '5'], $started);
assert_equal(true, $rot['ok'] ?? null, 'rotate archive, claims command unset: the rotate itself still succeeds');
assert_equal('waiting', $rot['archive']['outcome'] ?? null, 'rotate archive, claims command unset: archive.outcome waiting');
assert_equal([], $log5->kill, 'rotate archive, claims command unset: nothing killed');
putenv('SESSIONEER_CLAIMS_CMD=/usr/bin/claims-of');
$reset();
$mk('o7', 'p7');
$close('o7', 1234567);
$log5->kill = [];
$busy = fopen($LOCK, 'c');
assert_true($busy !== false && flock($busy, LOCK_EX | LOCK_NB), 'rotate archive, reap pass running: the test holds the flow-reap lock');
$rot = FlowService::rotate(['session' => 'o7', 'handoff_id' => '5'], $started);
assert_equal(true, $rot['ok'] ?? null, 'rotate archive, reap pass running: the rotate itself still succeeds');
assert_equal('waiting', $rot['archive']['outcome'] ?? null, 'rotate archive, reap pass running: archive.outcome waiting');
assert_contains('a reap pass is running', (string) ($rot['archive']['detail'] ?? ''), 'rotate archive, reap pass running: archive.detail says a reap pass is running');
assert_equal([], $log5->kill, 'rotate archive, reap pass running: nothing killed');
assert_equal(null, $closedAt('o7'), 'rotate archive, reap pass running: the link stays open for the next pass');
if ($busy !== false) {
    flock($busy, LOCK_UN);
    fclose($busy);
}

echo "archive_source: one open rotation, the same check, under the flow-reap lock (Contract B)\n";
$callSource = static function (string $source, array $deps): array {
    if (!method_exists(FlowService::class, 'archive_source')) {
        return ['missing' => true];
    }
    try {
        return FlowService::archive_source($source, $deps);
    } catch (Throwable $e) {
        return ['threw' => $e::class . ': ' . $e->getMessage()];
    }
};
assert_true(method_exists(FlowService::class, 'archive_source'), 'FlowService::archive_source exists');
$reset();
$mk('as1', 'ar1');
[$deps, $log] = $make();
$r = $callSource('as1', $deps);
assert_equal('archived', $r['outcome'] ?? ($r['threw'] ?? 'no outcome'), 'archive_source: archived for a started receiver and an idle, claim-free source');
assert_true(is_string($r['detail'] ?? null), 'archive_source: detail is a string');
assert_equal([['agent' => 'claude', 'target' => 'as1']], $norm($log->kill), 'archive_source: the source is killed once, without force');
assert_equal([[$CMD, 'conv-as1']], $log->runner, 'archive_source: only the source claims are read');
assert_equal($NOW, $closedAt('as1'), 'archive_source: the link is closed at clock()');
assert_equal(null, $closedAt('ar1'), 'archive_source: nothing is marked on the receiver');
$reset();
$mk('as2', 'ar2', ['recv_id' => null]);
[$deps, $log] = $make();
$r = $callSource('as2', $deps);
assert_equal('waiting', $r['outcome'] ?? ($r['threw'] ?? 'no outcome'), 'archive_source: waiting while the receiver has not started');
assert_contains('the receiver has not started yet', (string) ($r['detail'] ?? ''), 'archive_source: detail says so');
assert_equal([[], null], [$log->kill, $closedAt('as2')], 'archive_source: nothing killed, the link stays open');
$reset();
$mk('as3', 'ar3', ['src_status' => ['status' => 'working', 'mode' => 'plan']]);
[$deps, $log] = $make();
assert_equal('waiting', ($callSource('as3', $deps))['outcome'] ?? 'no outcome', 'archive_source: waiting while the source is working');
$reset();
$mk('as4', 'ar4');
[$deps, $log] = $make(['conv-as4' => '[{"id":7}]']);
assert_equal('waiting', ($callSource('as4', $deps))['outcome'] ?? 'no outcome', 'archive_source: waiting while the source holds a claim');
assert_equal([], $log->kill, 'archive_source: a source holding a claim is never killed');
$reset();
$mk('as5', 'ar5', ['recv_exists' => false]);
[$deps, $log] = $make();
assert_equal('closed', ($callSource('as5', $deps))['outcome'] ?? 'no outcome', 'archive_source: closed when the receiver is gone');
assert_equal($NOW, $closedAt('as5'), 'archive_source: and the link is closed at clock()');
$reset();
$mk('as6', 'ar6');
[$deps, $log] = $make([], static fn (array $q): array => ['ok' => false, 'message' => 'Unknown session as6']);
assert_equal('closed', ($callSource('as6', $deps))['outcome'] ?? 'no outcome', 'archive_source: closed when the kill reply says the session is unknown');
$reset();
$mk('as7', 'ar7');
putenv('SESSIONEER_CLAIMS_CMD');
[$deps, $log] = $make();
assert_equal('waiting', ($callSource('as7', $deps))['outcome'] ?? 'no outcome', 'archive_source: waiting while the claims command is unset');
assert_equal([[], []], [$log->kill, $log->runner], 'archive_source, claims command unset: nothing killed, no command run');
putenv('SESSIONEER_CLAIMS_CMD=/usr/bin/claims-of');
$reset();
$mk('as8', 'ar8');
[$deps, $log] = $make();
$handle = fopen($LOCK, 'c');
assert_true($handle !== false && flock($handle, LOCK_EX | LOCK_NB), 'archive_source lock: the test holds the flow-reap lock');
$r = $callSource('as8', $deps);
assert_equal('waiting', $r['outcome'] ?? ($r['threw'] ?? 'no outcome'), 'archive_source lock: busy returns waiting');
assert_contains('a reap pass is running', (string) ($r['detail'] ?? ''), 'archive_source lock: detail says a reap pass is running');
assert_equal([[], []], [$log->kill, $log->runner], 'archive_source lock: nothing killed, no command run while busy');
assert_equal(null, $closedAt('as8'), 'archive_source lock: the link stays open');
if ($handle !== false) {
    flock($handle, LOCK_UN);
    fclose($handle);
}
assert_equal('archived', ($callSource('as8', $deps))['outcome'] ?? 'no outcome', 'archive_source lock: once released the next call archives');
$probe = fopen($LOCK, 'c');
assert_true($probe !== false && flock($probe, LOCK_EX | LOCK_NB), 'archive_source: the lock is released afterwards');
if ($probe !== false) {
    flock($probe, LOCK_UN);
    fclose($probe);
}

$reset();
$mk('o3', 'p3');
$close('o3', 1234567);
$rotateDeps['facts'] = static fn (string $s, array $row): ?array => ['context_tokens' => 260000, 'idle_seconds' => 60, 'source' => ['agent' => 'claude', 'profile' => 'work', 'status' => 'idle']];
$rotateDeps['launch'] = static fn (array $req): array => ['ok' => false, 'name' => null, 'message' => 'no capacity'];
FlowService::rotate(['session' => 'o3', 'handoff_id' => '5'], $rotateDeps);
assert_equal(1234567, $closedAt('o3'), 'reopen: a failed rotate does not reopen the link');

echo "closed candidates are never revisited\n";
$reset();
$mk('c1', 'd1');
$close('c1', 1234567);
assert_equal(1234567, $closedAt('c1'), 'close_rotation stores the given time');
[$deps, $log] = $make(['conv-d1' => '[{"id":1}]']);
$r = $run($deps);
assert_equal([], $r['results'] ?? null, 'closed: a closed candidate that would otherwise be archived is not reported');
assert_equal([[], []], [$log->kill, $log->runner], 'closed: and nothing is killed or asked');

echo "rotation_closed_at survives a sidecar rewrite\n";
SidecarStore::write_sidecar('c1', ['workdir' => '/w/other', 'agent_session_id' => 'conv-c1', 'agent' => 'claude', 'runtime' => 'headless', 'title' => 'renamed']);
assert_equal(1234567, $closedAt('c1'), 'rewrite: rotation_closed_at is preserved');
assert_equal('d1', SidecarStore::read_sidecar('c1')['rotated_to'] ?? null, 'rewrite: rotated_to is preserved');
SidecarStore::set_rotation('c1', null, 'd1');
assert_equal(null, $closedAt('c1'), 'rewrite: any non-null $to, even the same receiver, reopens the link');

echo "per-run lock\n";
$reset();
$mk('l1', 'p1');
[$deps, $log] = $make();
$handle = fopen($LOCK, 'c');
assert_true($handle !== false && flock($handle, LOCK_EX | LOCK_NB), 'lock: the test holds the lock file');
$r = $run($deps);
assert_equal([true, [], true], [$r['ok'] ?? null, $r['results'] ?? null, $r['skipped'] ?? null], 'lock: held by another run returns ok, empty results and skipped');
assert_equal([[], []], [$log->kill, $log->runner], 'lock: a skipped call touches nothing');
assert_equal(null, $closedAt('l1'), 'lock: the candidate is untouched');
if ($handle !== false) {
    flock($handle, LOCK_UN);
    fclose($handle);
}
$r = $run($deps);
assert_equal('archived', $outcome($r, 'l1'), 'lock: once released the next call runs');
assert_true(!isset($r['skipped']), 'lock: and is not flagged skipped');

echo "the CLAIMS command line is passed through unchanged\n";
$reset();
putenv('SESSIONEER_CLAIMS_CMD=/opt/bin/dibs claims --json');
$mk('q1', 'q2');
[$deps, $log] = $make();
$run($deps);
assert_equal([['/opt/bin/dibs claims --json', 'conv-q1']], $log->runner, 'cmd: the configured command string is handed to the runner as is, with the source conversation id (the receiver is never asked)');
putenv('SESSIONEER_CLAIMS_CMD=/usr/bin/claims-of');

test_exit();
