<?php

declare(strict_types=1);

/**
 * The real host agent process (`php host-agent/agent.php`, request on stdin, reply on stdout) when AT_BIN
 * names a binary that does not exist. PHP's proc_open() emits a warning for a missing binary, and the agent
 * runs with display_errors on STDOUT, so a leaked warning would corrupt the JSON reply of every wake action
 * and of the dashboard's `list` poll. In-process dispatch_action() tests cannot see that. Nothing here reaches
 * a real `at`, tmux or network (all paths point into a scratch directory).
 */

require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/wake_fixture.php';

$rig = new WakeRig('wake-agent-proc');
$missing = $rig->root . '/no-such-dir/at';
putenv('AT_BIN=' . $missing);
putenv('TMUX_SOCKET=' . $rig->root . '/tmux/socket');
putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
putenv('HEADLESS_SYNC_SECONDS=0');
putenv('CLAUDE_HEADLESS_SOCKET=' . $rig->root . '/no-claude-manager.sock');
putenv('CODEX_BRIDGE_SOCKET=' . $rig->root . '/no-codex-bridge.sock');
putenv('OPENCODE_DB_PATH=' . $rig->root . '/no-opencode.db');
putenv('SOCKET_CLIENT_FAIL_FAST_IF_ABSENT=1');
putenv('SESSION_LIST_CACHE_TTL_SECONDS=0');
putenv('PUSH_SQLITE_FILE=' . $rig->root . '/push.sqlite');

require_once __DIR__ . '/../vendor/autoload.php';

use HostAgent\Stores\SidecarStore;

SidecarStore::write_sidecar('ses_ProcA', ['workdir' => '/tmp', 'spawned_at' => time(), 'agent_session_id' => 'ses_ProcA', 'spawned_by_app' => true, 'agent' => 'opencode', 'runtime' => 'headless']);

/** @return array{string, string, int} stdout, stderr, exit code of the agent for one request */
function wake_agent_run(array $request): array
{
    $process = proc_open(
        ['php', '-d', 'display_errors=1', '-d', 'error_reporting=-1', dirname(__DIR__) . '/host-agent/agent.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    fwrite($pipes[0], json_encode($request));
    fclose($pipes[0]);
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);

    return [$out, $err, proc_close($process)];
}

$when = intdiv(time(), 60) * 60 + 7200;

try {
    foreach ([
        'launch_wake_list' => ['action' => 'launch_wake_list'],
        'launch_wake_show' => ['action' => 'launch_wake_show', 'job' => 11],
        'launch_wake_cancel' => ['action' => 'launch_wake_cancel', 'job' => 11],
        'launch_wake (scheduling)' => ['action' => 'launch_wake', 'target' => 'ses_ProcA', 'at' => $when, 'message' => 'continue'],
        'list (the dashboard poll)' => ['action' => 'list'],
    ] as $label => $request) {
        [$out, $err, $code] = wake_agent_run($request);
        $reply = json_decode($out, true);
        assert_equal(0, $code, "{$label}: the agent exits 0");
        assert_true(is_array($reply), "{$label}: stdout is exactly one JSON document (got " . substr($out, 0, 120) . ')');
        assert_true(!str_contains($out, 'Warning') && !str_contains($out, 'Notice') && !str_contains($out, 'Deprecated') && !str_contains($out, 'Fatal'), "{$label}: no PHP warning, notice or error text on stdout");
        assert_true(!str_contains($err, 'Fatal'), "{$label}: no fatal error on stderr");

        if ($request['action'] === 'list') {
            assert_equal(true, $reply['ok'] ?? null, "{$label}: still ok");
            assert_true(!isset($reply['message']), "{$label}: with no error message");
            assert_true(!str_contains($out, 'AT_BIN'), "{$label}: and no at error text");
            assert_true(in_array('ses_ProcA', array_column($reply['sessions'] ?? [], 'name'), true), "{$label}: the sessions are still listed");
            assert_true(!str_contains($out, 'pending_wake'), "{$label}: no chip when at cannot be reached");
        } elseif ($request['action'] === 'launch_wake') {
            assert_equal(false, $reply['ok'] ?? null, "{$label}: refused, not crashed");
        } else {
            assert_equal(false, $reply['ok'] ?? null, "{$label}: not ok");
            assert_equal('The at command is not available (AT_BIN=' . $missing . ')', $reply['message'] ?? null, "{$label}: says at is not available and names AT_BIN");
        }
    }
} finally {
    $rig->cleanup();
}

test_exit();
