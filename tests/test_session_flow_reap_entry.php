<?php

declare(strict_types=1);

// Entry point and wiring for FlowService::archive_replaced (task 1786, plan step 1978):
//   host-agent/flow_reap.php  standalone script, runs archive_replaced(FlowService::live()) once, logs each
//                             non-waiting result to stderr as one line, catches \Throwable (log, exit 0),
//                             no-op when nothing is a candidate
//   host-agent/systemd/sessioneer-flow-reap.service|.timer  oneshot + 30 s OnUnitActiveSec, same shape as the
//                             antigravity quota check units
//   host-agent/install.sh     installs them like the antigravity units and does not enable them
// Hermetic: the script runs as a child php process against temp SQLite files, with the claims command unset or
// every candidate decided before any kill (receiver gone or claims command missing), so no session is ever
// killed and no systemd, tmux, agent or network is touched. Written before the implementation.

require __DIR__ . '/lib/assert.php';

$root = dirname(__DIR__);
$script = $root . '/host-agent/flow_reap.php';
$service = $root . '/host-agent/systemd/sessioneer-flow-reap.service';
$timer = $root . '/host-agent/systemd/sessioneer-flow-reap.timer';
$install = $root . '/host-agent/install.sh';
$refService = $root . '/host-agent/systemd/sessioneer-antigravity-quota-check.service';

$read = static fn (string $f): string => is_file($f) ? (string) file_get_contents($f) : '';
$lines = static fn (string $text): array => array_values(array_filter(array_map('trim', explode("\n", $text)), static fn (string $l): bool => $l !== ''));

echo "files exist\n";
foreach (['flow_reap.php' => $script, 'service unit' => $service, 'timer unit' => $timer] as $label => $f) {
    assert_true(is_file($f), "$label exists");
}

echo "service unit: oneshot, same shape as the antigravity quota check\n";
$svc = $read($service);
assert_contains('Type=oneshot', $svc, 'service: Type=oneshot');
assert_contains('EnvironmentFile=-@REPO_ROOT@/host-agent/.env', $svc, 'service: optional .env like the other units');
assert_contains('ExecStart=@PHP_BIN@ @REPO_ROOT@/host-agent/flow_reap.php', $svc, 'service: runs flow_reap.php through the install placeholders');
assert_contains('[Unit]', $svc, 'service: has a [Unit] section');
assert_true(!str_contains($svc, '[Install]'), 'service: no [Install] section, the timer starts it');
assert_true(preg_match('/^Description=\S/m', $svc) === 1, 'service: has a description');
$refKeys = [];
foreach ($lines($read($refService)) as $l) {
    if (preg_match('/^(\w+)=/', $l, $m) === 1 && !in_array($m[1], ['Description', 'ExecStart'], true)) {
        $refKeys[] = $m[1];
    }
}
foreach (array_unique($refKeys) as $key) {
    assert_true(preg_match('/^' . $key . '=/m', $svc) === 1, "service: carries the same $key= key as the antigravity unit");
}

echo "timer unit: every 30 seconds, OnUnitActiveSec\n";
$tm = $read($timer);
assert_true(preg_match('/^OnUnitActiveSec=30(s|sec|seconds)?\s*$/m', $tm) === 1, 'timer: OnUnitActiveSec=30s');
assert_true(preg_match('/^OnBootSec=\S+\s*$/m', $tm) === 1, 'timer: OnBootSec= is set (first run after boot)');
assert_true(preg_match('/^OnCalendar=/m', $tm) === 0, 'timer: no OnCalendar (a slow run cannot pile up overlaps)');
assert_true(preg_match('/^Unit=sessioneer-flow-reap\.service\s*$/m', $tm) === 1, 'timer: starts the flow-reap service');
assert_true(preg_match('/^\[Timer\]/m', $tm) === 1 && preg_match('/^\[Install\]/m', $tm) === 1, 'timer: has [Timer] and [Install]');
assert_true(preg_match('/^WantedBy=timers\.target\s*$/m', $tm) === 1, 'timer: WantedBy=timers.target');

echo "install.sh: installed like the antigravity units, not enabled\n";
$sh = $read($install);
assert_true(preg_match('/^render_unit sessioneer-flow-reap\.service\s*$/m', $sh) === 1, 'install: renders the service unit with render_unit');
assert_true(preg_match('#^cp "\$SCRIPT_DIR/systemd/sessioneer-flow-reap\.timer" "\$UNIT_DIR/sessioneer-flow-reap\.timer"\s*$#m', $sh) === 1, 'install: copies the timer unit');
$wired = array_filter(explode("\n", $sh), static fn (string $l): bool => str_contains($l, 'flow-reap'));
assert_true($wired !== [], 'install: mentions flow-reap');
$enabling = array_filter($wired, static fn (string $l): bool => preg_match('/^\s*systemctl\b.*\benable\b/', $l) === 1 || preg_match('/^\s*systemctl\b.*\bstart\b/', $l) === 1);
assert_equal([], array_values($enabling), 'install: no line enables or starts the flow-reap units automatically');
$flowAt = strpos($sh, 'render_unit sessioneer-flow-reap.service');
$nextSection = $flowAt === false ? false : strpos($sh, "\n# ", $flowAt);
$block = $flowAt === false ? '' : substr($sh, $flowAt, $nextSection === false ? null : $nextSection - $flowAt);
$notEnabled = array_filter(explode("\n", $block), static fn (string $l): bool => preg_match('/flow[- ]reap/i', $l) === 1 && str_contains($l, 'NOT enabled'));
assert_true($notEnabled !== [], 'install: a flow-reap line saying NOT enabled follows the render_unit line');
assert_true(preg_match('/enable --now sessioneer-flow-reap\.timer/', $block) === 1, 'install: and the same block shows how to enable the timer');
$reloadAfter = $flowAt === false ? false : strpos($sh, 'systemctl --user daemon-reload', $flowAt);
assert_true($reloadAfter !== false, 'install: daemon-reload after the new units are copied');
$check = proc_open(['bash', '-n', $install], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $p);
$checkErr = '';
if (is_resource($check)) {
    stream_get_contents($p[1]);
    $checkErr = (string) stream_get_contents($p[2]);
    $code = proc_close($check);
    assert_equal(0, $code, 'install: bash -n finds no syntax error' . ($checkErr !== '' ? " ($checkErr)" : ''));
}

echo "flow_reap.php: static shape\n";
$src = $read($script);
assert_true($src !== '', 'script: is not empty');
assert_contains('archive_replaced', $src, 'script: calls archive_replaced');
assert_contains('FlowService::live()', $src, 'script: passes FlowService::live()');
assert_true(str_contains($src, 'Throwable'), 'script: handles \\Throwable');
if (is_file($script)) {
    $lint = proc_open([PHP_BINARY, '-l', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $lp);
    if (is_resource($lint)) {
        stream_get_contents($lp[1]);
        stream_get_contents($lp[2]);
        assert_equal(0, proc_close($lint), 'script: php -l is clean');
    }
}

$dir = sys_get_temp_dir() . '/sessioneer-test-flow-reap-entry-' . getmypid();
mkdir($dir . '/cache', 0700, true);
mkdir($dir . '/home', 0700, true);
register_shutdown_function(static function () use ($dir): void {
    exec('rm -rf ' . escapeshellarg($dir));
});
$env = static fn (array $extra = []): array => $extra + [
    'PATH' => (string) getenv('PATH'),
    'HOME' => $dir . '/home',
    'HOME_ROOT' => $dir . '/home',
    'SESSIONS_SQLITE_FILE' => $dir . '/sessions.sqlite',
    'PUSH_SQLITE_FILE' => $dir . '/push.sqlite',
    'SIDECAR_DIR' => $dir . '/sidecars',
    'CACHE_DIR' => $dir . '/cache',
    'SESSION_FLOW_THRESHOLDS' => $dir . '/none.conf',
    'CODEX_BRIDGE_SOCKET' => $dir . '/missing-codex.sock',
    'CLAUDE_HEADLESS_SOCKET' => $dir . '/missing-claude.sock',
    'OPENCODE_DB_PATH' => $dir . '/missing.db',
    'OPENCODE_AUTH_PATH' => $dir . '/missing.json',
    'OPENCODE_SERVE_URL' => 'http://127.0.0.1:1',
    'SOCKET_CLIENT_FAIL_FAST_IF_ABSENT' => '1',
    'CLAUDE_BIN' => '/nonexistent/claude',
    'CODEX_BIN' => '/nonexistent/codex',
];
/** @return array{0: int, 1: string, 2: string} exit code, stdout, stderr */
$execute = static function (array $environment) use ($script): array {
    $proc = proc_open(['timeout', '60', PHP_BINARY, $script], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $environment);
    if (!is_resource($proc)) {
        return [-1, '', 'cannot start'];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);

    return [proc_close($proc), $out, $err];
};
$db = static function (string $sql, array $args = []) use ($dir): array {
    $pdo = new PDO('sqlite:' . $dir . '/sessions.sqlite');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($args);

    return $stmt->columnCount() > 0 ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
};

if (is_file($script)) {
    echo "flow_reap.php: nothing to do\n";
    [$code, $out, $err] = $execute($env());
    assert_equal(0, $code, 'empty database: exit 0');
    assert_equal('', trim($err), 'empty database: nothing logged, a no-op');

    putenv("SESSIONS_SQLITE_FILE={$dir}/sessions.sqlite");
    putenv("SIDECAR_DIR={$dir}/sidecars");
    require __DIR__ . '/../vendor/autoload.php';
    // A candidate that is only waiting (claims command unset) and one whose receiver died (closed).
    \HostAgent\Stores\SidecarStore::write_sidecar('reap-wait-src', ['workdir' => '/w', 'agent_session_id' => 'conv-ws', 'agent' => 'claude', 'runtime' => 'headless']);
    \HostAgent\Stores\SessionStatusStore::write_status('reap-wait-src', ['status' => 'idle', 'mode' => 'plan']);
    \HostAgent\Stores\SidecarStore::write_sidecar('reap-wait-recv', ['workdir' => '/w', 'agent_session_id' => 'conv-wr', 'agent' => 'claude', 'runtime' => 'headless']);
    \HostAgent\Stores\SidecarStore::set_rotation('reap-wait-recv', 'reap-wait-src', null);
    \HostAgent\Stores\SidecarStore::set_rotation('reap-wait-src', null, 'reap-wait-recv');

    echo "flow_reap.php: waiting results are not logged\n";
    [$code, $out, $err] = $execute($env());
    assert_equal(0, $code, 'waiting only (claims command unset): exit 0');
    assert_equal('', trim($err), 'waiting only: nothing logged on stderr');
    $row = $db('SELECT * FROM sidecars WHERE session_name = ?', ['reap-wait-src'])[0] ?? [];
    assert_true(array_key_exists('rotation_closed_at', $row) && $row['rotation_closed_at'] === null, 'waiting only: the candidate stays open (column exists, NULL)');
    assert_true(\HostAgent\Stores\SidecarStore::read_sidecar('reap-wait-src') !== null, 'waiting only: the source session is untouched');

    echo "flow_reap.php: a closed result is logged as one line\n";
    \HostAgent\Stores\SidecarStore::write_sidecar('reap-gone-src', ['workdir' => '/w', 'agent_session_id' => 'conv-gs', 'agent' => 'claude', 'runtime' => 'headless']);
    \HostAgent\Stores\SessionStatusStore::write_status('reap-gone-src', ['status' => 'idle', 'mode' => 'plan']);
    \HostAgent\Stores\SidecarStore::set_rotation('reap-gone-src', null, 'reap-gone-recv-missing');
    \HostAgent\Stores\SidecarStore::write_sidecar('reap-gone2-src', ['workdir' => '/w', 'agent_session_id' => 'conv-g2', 'agent' => 'claude', 'runtime' => 'headless']);
    \HostAgent\Stores\SessionStatusStore::write_status('reap-gone2-src', ['status' => 'idle', 'mode' => 'plan']);
    \HostAgent\Stores\SidecarStore::set_rotation('reap-gone2-src', null, 'reap-gone2-recv-missing');
    [$code, $out, $err] = $execute($env());
    assert_equal(0, $code, 'closed: exit 0');
    $logged = $lines($err);
    assert_equal(2, count($logged), 'closed: exactly two stderr lines, one per non-waiting result (' . json_encode($logged) . ')');
    foreach (['reap-gone-src' => 'reap-gone2-src', 'reap-gone2-src' => 'reap-gone-src'] as $own => $other) {
        $mine = array_values(array_filter($logged, static fn (string $l): bool => str_contains($l, $own . ' ') || str_ends_with($l, $own) || str_contains($l, $own . ':')));
        assert_equal(1, count($mine), "closed: exactly one line names $own");
        assert_true(isset($mine[0]) && str_contains($mine[0], 'closed') && !str_contains($mine[0], $other), "closed: the line for $own says closed and does not name the other source");
    }
    assert_true(!str_contains($err, 'reap-wait-src'), 'closed: the waiting candidate is not logged');
    $row = $db('SELECT rotation_closed_at FROM sidecars WHERE session_name = ?', ['reap-gone-src'])[0] ?? [];
    assert_true(is_numeric($row['rotation_closed_at'] ?? null), 'closed: the candidate was closed in the database');
    $row2 = $db('SELECT rotation_closed_at FROM sidecars WHERE session_name = ?', ['reap-gone2-src'])[0] ?? [];
    assert_true(is_numeric($row2['rotation_closed_at'] ?? null), 'closed: the second candidate was closed in the database too');
    [$code, $out, $err] = $execute($env());
    assert_equal([0, ''], [$code, trim($err)], 'closed: the next run reports nothing for it');

    echo "flow_reap.php: a failing pass is logged and exits 0\n";
    file_put_contents($dir . '/not-a-directory', 'x');
    [$code, $out, $err] = $execute($env(['SESSIONS_SQLITE_FILE' => $dir . '/not-a-directory/sessions.sqlite']));
    assert_equal(0, $code, 'throwable: exit 0 even when the pass throws');
    assert_true(trim($err) !== '', 'throwable: the failure is logged to stderr');
}
else {
    assert_true(false, 'flow_reap.php is missing, so none of its runtime behaviour can be checked');
}

test_exit();
