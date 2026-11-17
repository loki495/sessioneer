<?php

declare(strict_types=1);

/** Fixture-only daemon takeover regression. Each case owns its processes, tmux and SQLite. */
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/claude_headless.php';

use HostAgent\Services\BareProcessService;
use HostAgent\Services\Config;
use HostAgent\Services\ProcessInspector;
use HostAgent\Services\SessionLifecycleService;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\SidecarStore;

$root = sys_get_temp_dir() . '/sessioneer-test-tmp-takeover-' . bin2hex(random_bytes(6));
$originalSocket = Config::tmux_socket();
$originalSidecar = Config::sidecar_dir();
$originalPush = Config::push_sqlite_path();
foreach (['home/.claude/daemon', 'home/.claude/projects/project', 'project', 'tmux', 'sidecars', 'cache', 'cc-daemon-1000/worker/spare'] as $dir) {
    mkdir($root . '/' . $dir, 0700, true);
}
$wrapper = $root . '/fake-claude';
file_put_contents($wrapper, "#!/bin/bash\nexec -a \"\$0\" perl -e 'sleep 300' -- \"\$@\"\n");
chmod($wrapper, 0755);
foreach ([
    'HOME' => $root . '/home', 'HOME_ROOT' => $root . '/home',
    'WWW_ROOT' => __DIR__ . '/fixtures/www_root', 'TMUX_SOCKET' => $root . '/tmux/socket',
    'SIDECAR_DIR' => $root . '/sidecars', 'CACHE_DIR' => $root . '/cache',
    'PUSH_SQLITE_FILE' => $root . '/push.sqlite', 'SESSIONS_SQLITE_FILE' => $root . '/sessions.sqlite',
    'CLAUDE_BIN' => $wrapper, 'SESSION_LIST_CACHE_TTL_SECONDS' => '0',
    'OPENCODE_SERVE_URL' => 'http://127.0.0.1:1', 'OPENCODE_DB_PATH' => $root . '/missing.db',
    'CODEX_BRIDGE_SOCKET' => $root . '/missing-codex.sock', 'CLAUDE_HEADLESS_SOCKET' => $root . '/missing-claude.sock',
    'SOCKET_CLIENT_FAIL_FAST_IF_ABSENT' => '1', 'TAKE_OVER_EXIT_WAIT_SECONDS' => '5',
] as $key => $value) {
    putenv("$key=$value");
}
if (Config::tmux_socket() === $originalSocket || Config::sidecar_dir() === $originalSidecar || Config::push_sqlite_path() === $originalPush) {
    fwrite(STDERR, "REFUSING TO RUN: test state resolves to host state.\n");
    exit(1);
}
require_once __DIR__ . '/../host-agent/lib/Sessions.php';
$procs = [];
$pipesHeld = [];
register_shutdown_function(static function () use (&$procs, &$pipesHeld, $root): void {
    foreach ($pipesHeld as $pipes) {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }
    foreach ($procs as $proc) {
        if (is_resource($proc)) {
            @proc_terminate($proc, SIGKILL);
            @proc_close($proc);
        }
    }
    exec('tmux -S ' . escapeshellarg($root . '/tmux/socket') . ' kill-server 2>/dev/null');
    exec('rm -rf ' . escapeshellarg($root));
});

$start = static function (string $cwd, array $args = []) use (&$procs, &$pipesHeld): int {
    $proc = proc_open(array_merge([Config::claude_bin()], $args), [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'a'], 2 => ['file', '/dev/null', 'a']], $pipes, $cwd);
    if (!is_resource($proc)) {
        throw new RuntimeException('Failed to start fixture');
    }
    $procs[] = $proc;
    $pipesHeld[] = $pipes;
    $pid = (int)proc_get_status($proc)['pid'];
    assert_true((bool)wait_until(static fn (): bool => in_array($pid, array_column(ProcessInspector::find_claude_processes(), 'pid'), true), 5.0), 'fixture process is visible');
    return $pid;
};

$poolRow = ['pid' => 200, 'cwd' => '/tmp/cc-daemon-1000/abc/spare', 'resolved_agent_session_id' => 'sess-a'];
$projectRow = ['pid' => 201, 'cwd' => $root . '/project', 'resolved_agent_session_id' => 'sess-a'];
$independentRows = [
    ['pid' => 202, 'cwd' => $root . '/other-project', 'resolved_agent_session_id' => 'sess-b'],
    ['pid' => 203, 'cwd' => $root . '/unresolved'],
];
foreach ([[$poolRow, $projectRow], [$projectRow, $poolRow]] as $index => $pair) {
    $rows = BareProcessService::declutter_bare_list(array_merge($pair, $independentRows));
    $byPid = array_column($rows, null, 'pid');
    $label = $index === 0 ? 'wrapper first' : 'child first';
    assert_equal(3, count($rows), "$label: only the confirmed pair is collapsed");
    assert_true(isset($byPid[201]) && !isset($byPid[200]), "$label: display retains the temporary project worker");
    assert_equal(1, $byPid[201]['hidden_worker_count'] ?? null, "$label: retained worker reports its sibling");
    assert_true(isset($byPid[202], $byPid[203]), "$label: unrelated temporary rows remain visible");
    assert_true(!isset($byPid[202]['hidden_worker_count']) && !isset($byPid[203]['hidden_worker_count']), "$label: unrelated rows have no hidden siblings");
}

$case = 0;
foreach ([$root . '/project', __DIR__ . '/fixtures/www_root/project-a'] as $project) {
    foreach (['wrapper', 'child'] as $target) {
        foreach (['wrapper-first', 'child-first'] as $order) {
            ++$case;
            $id = sprintf('%08d-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $case);
            $pool = $root . '/cc-daemon-1000/worker/spare';
            $paths = $order === 'wrapper-first' ? ['wrapper' => $pool, 'child' => $project] : ['child' => $project, 'wrapper' => $pool];
            $pids = [];
            foreach ($paths as $role => $path) {
                $pids[$role] = $start($path);
            }
            file_put_contents($root . '/home/.claude/daemon/roster.json', json_encode([
                'proto' => 1, 'workers' => ['test' => ['pid' => $pids['wrapper'], 'replPid' => $pids['child'], 'sessionId' => $id, 'cwd' => $project]],
            ]));
            file_put_contents($root . '/home/.claude/projects/project/' . $id . '.jsonl', json_encode([
                'type' => 'user', 'cwd' => $project, 'message' => ['role' => 'user', 'content' => 'hello'],
            ]) . "\n");
            $label = basename($project) . " target=$target order=$order";
            $r = BareProcessService::take_over_bare_process($pids[$target]);
            assert_equal(true, $r['ok'] ?? null, "$label: confirmed takeover succeeds (" . json_encode($r) . ')');
            assert_true(!($r['needs_choice'] ?? false), "$label: confirmed identity needs no picker");
            $name = $r['name'] ?? '';
            $sidecar = is_string($name) && $name !== '' ? SidecarStore::read_sidecar($name) : [];
            assert_equal($project, $sidecar['workdir'] ?? null, "$label: preserves the project directory");
            assert_equal($id, $sidecar['agent_session_id'] ?? null, "$label: resumes the confirmed conversation");
            assert_true(!pid_alive($pids['wrapper']) && !pid_alive($pids['child']), "$label: both old workers exited");
            if (is_string($name) && $name !== '') {
                TmuxService::tmux_run(['kill-session', '-t', $name]);
                SidecarStore::delete_sidecar($name);
            }
            unlink($root . '/home/.claude/daemon/roster.json');
        }
    }
}

// Missing or conflicting roster context must never strand a conversation in the pool.
foreach (['missing', 'conflicting'] as $context) {
    $id = $context === 'missing' ? 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' : 'bbbbbbbb-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $project = $root . '/project';
    $pool = $root . '/cc-daemon-1000/worker/spare';
    $wrapperPid = $start($pool);
    $childPid = $start($project);
    $worker = ['pid' => $wrapperPid, 'replPid' => $childPid, 'sessionId' => $id];
    if ($context === 'conflicting') {
        $worker['cwd'] = __DIR__ . '/fixtures/www_root/project-a';
    }
    file_put_contents($root . '/home/.claude/daemon/roster.json', json_encode(['proto' => 1, 'workers' => ['test' => $worker]]));
    $before = array_column(TmuxService::list_tracked_tmux_sessions(), 'name');
    $r = BareProcessService::take_over_bare_process($wrapperPid);
    $name = $r['name'] ?? '';
    $sidecar = is_string($name) && $name !== '' ? SidecarStore::read_sidecar($name) : [];
    if (($r['ok'] ?? false) === true && !($r['needs_choice'] ?? false)) {
        assert_equal($project, $sidecar['workdir'] ?? null, "$context roster cwd: successful takeover uses the live project");
        assert_equal($id, $sidecar['agent_session_id'] ?? null, "$context roster cwd: successful takeover keeps the confirmed conversation");
        assert_true(!pid_alive($wrapperPid) && !pid_alive($childPid), "$context roster cwd: successful takeover stops both original workers");
    } else {
        assert_true(pid_alive($wrapperPid) && pid_alive($childPid), "$context roster cwd: refusal leaves both workers running");
        assert_equal($before, array_column(TmuxService::list_tracked_tmux_sessions(), 'name'), "$context roster cwd: refusal creates no replacement");
    }
    if (is_string($name) && $name !== '') {
        TmuxService::tmux_run(['kill-session', '-t', $name]);
        SidecarStore::delete_sidecar($name);
    }
    foreach ($procs as $proc) {
        if (is_resource($proc) && in_array((int)proc_get_status($proc)['pid'], [$wrapperPid, $childPid], true)) {
            proc_terminate($proc, SIGKILL);
            proc_close($proc);
        }
    }
    unlink($root . '/home/.claude/daemon/roster.json');
}

// A regular confirmed bare process under /tmp is not daemon infrastructure.
$id = '99999999-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$pid = $start($root . '/project', ['--resume', $id]);
$r = BareProcessService::take_over_bare_process($pid);
assert_equal(true, $r['ok'] ?? null, 'ordinary temporary bare process can be taken over');
$name = $r['name'] ?? '';
$sidecar = is_string($name) && $name !== '' ? SidecarStore::read_sidecar($name) : [];
assert_equal($root . '/project', $sidecar['workdir'] ?? null, 'ordinary temporary bare process keeps its directory');
assert_equal($id, $sidecar['agent_session_id'] ?? null, 'ordinary temporary bare process keeps its confirmed identity');
if (is_string($name) && $name !== '') {
    TmuxService::tmux_run(['kill-session', '-t', $name]);
    SidecarStore::delete_sidecar($name);
}
assert_equal(false, BareProcessService::take_over_bare_process(999999)['ok'] ?? null, 'unknown process is refused');
assert_equal(false, SessionLifecycleService::resume_agent_session('relative/project', 'eeeeeeee-aaaa-4aaa-8aaa-aaaaaaaaaaaa')['ok'] ?? null, 'relative workdir remains refused');

$confirmedId = 'dddddddd-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
$unresolvedPid = $start($root . '/project');
$confirmed = BareProcessService::take_over_bare_process_with_id($unresolvedPid, $root . '/project', $confirmedId);
assert_equal(true, $confirmed['ok'] ?? null, 'externally confirmed conversation can be taken over');
$confirmedName = $confirmed['name'] ?? '';
$confirmedSidecar = is_string($confirmedName) && $confirmedName !== '' ? SidecarStore::read_sidecar($confirmedName) : [];
assert_equal($root . '/project', $confirmedSidecar['workdir'] ?? null, 'externally confirmed takeover keeps its directory');
assert_equal($confirmedId, $confirmedSidecar['agent_session_id'] ?? null, 'externally confirmed takeover keeps its identity');

test_exit();
