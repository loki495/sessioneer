<?php
declare(strict_types=1);

/**
 * Exercises AntigravityHookService::check_session_hook()/install_session_hook()
 * (the ~/.gemini/config/hooks.json read-modify-write logic covering the 4
 * hooks this app installs for Antigravity - see
 * docs/history/antigravity-adapter-plan.md Phase 3) and the actual
 * host-agent/hooks/antigravity/{pre_invocation,pre_tool_use,post_tool_use,stop}.php
 * scripts `agy` invokes - both against isolated fixture paths, never the
 * real ~/.gemini/config/hooks.json or the real sidecar dir. Mirrors
 * test_session_hook.php's own structure/isolation discipline for the
 * Claude Code side.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\AntigravityHookService;
use HostAgent\Services\Config;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\PendingToolStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$realHomeRoot = Config::home_root();

$fixtureHome = sys_get_temp_dir() . '/sessioneer-test-agy-hook-home-' . bin2hex(random_bytes(4));
$fixtureSidecarDir = sys_get_temp_dir() . '/sessioneer-test-agy-hook-sidecars-' . bin2hex(random_bytes(4));

putenv("HOME_ROOT={$fixtureHome}");
putenv("SIDECAR_DIR={$fixtureSidecarDir}");

if (Config::home_root() === $realHomeRoot) {
    fwrite(STDERR, "REFUSING TO RUN: HOME_ROOT still resolves to the real home directory.\n");
    exit(1);
}

mkdir($fixtureSidecarDir, 0700, true);

$hooksPath = Config::antigravity_hooks_path();

/**
 * Same shared subprocess runner shape as test_session_hook.php's own
 * run_status_hook_script() - all 4 Antigravity hook scripts share the
 * same SESSIONEER_SESSION_NAME-gated, stdin-JSON-in/stdout-string-out contract.
 *
 * @param array<string, mixed>|null $payload
 */
function run_antigravity_hook_script(string $scriptPath, ?string $sessioneerSessionName, ?array $payload): string
{
    $env = [
        'HOME_ROOT' => Config::home_root(),
        'SIDECAR_DIR' => Config::sidecar_dir(),
        'TMUX_SOCKET' => Config::tmux_socket(),
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
    ];

    if ($sessioneerSessionName !== null) {
        $env['SESSIONEER_SESSION_NAME'] = $sessioneerSessionName;
    }

    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(['php', $scriptPath], $descriptors, $pipes, null, $env);

    if (!is_resource($process)) {
        assert_true(false, 'run_antigravity_hook_script: failed to start subprocess');
        return '';
    }

    fwrite($pipes[0], $payload !== null ? json_encode($payload) : '');
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($process);

    return (string)$stdout;
}

$hooksDir = dirname(__DIR__) . '/host-agent/hooks/antigravity';

try {
    // --- AntigravityHookService::check_session_hook()/install_session_hook(): fresh machine ---

    $check = AntigravityHookService::check_session_hook();
    assert_equal(true, $check['ok'], 'check_session_hook: ok on a missing hooks.json');
    assert_equal(false, $check['installed'], 'check_session_hook: not installed when hooks.json does not exist yet');

    $install = AntigravityHookService::install_session_hook();
    assert_equal(true, $install['ok'], 'install_session_hook: succeeds on a missing hooks.json');
    assert_equal(true, $install['installed'], 'install_session_hook: reports installed after creating the file');
    assert_true(is_file($hooksPath), 'install_session_hook: creates ~/.gemini/config/hooks.json');

    $afterInstall = AntigravityHookService::check_session_hook();
    assert_equal(true, $afterInstall['installed'], 'check_session_hook: installed=true right after install_session_hook()');

    // --- real schema shape - PreToolUse/PostToolUse grouped (matcher +
    // hooks wrapper), PreInvocation/Stop flat (bare {type,command} list) ---

    $decoded = json_decode((string)file_get_contents($hooksPath), true);
    $group = $decoded['sessioneer'] ?? null;
    assert_true(is_array($group), 'install_session_hook: writes under the sessioneer hook-group name');
    assert_equal('.*', $group['PreToolUse'][0]['matcher'] ?? null, 'install_session_hook: PreToolUse is grouped with a matcher');
    assert_equal(Config::antigravity_pre_tool_use_hook_command(), $group['PreToolUse'][0]['hooks'][0]['command'] ?? null, 'install_session_hook: PreToolUse command is correct');
    assert_equal(Config::antigravity_post_tool_use_hook_command(), $group['PostToolUse'][0]['hooks'][0]['command'] ?? null, 'install_session_hook: PostToolUse command is correct');
    assert_equal(Config::antigravity_pre_invocation_hook_command(), $group['PreInvocation'][0]['command'] ?? null, 'install_session_hook: PreInvocation is flat (no matcher wrapper) with the correct command');
    assert_equal(Config::antigravity_stop_hook_command(), $group['Stop'][0]['command'] ?? null, 'install_session_hook: Stop is flat with the correct command');
    assert_true(!isset($group['PreInvocation'][0]['matcher']), 'install_session_hook: PreInvocation has no matcher key at all (flat shape, not grouped)');

    // --- idempotent: calling again does not duplicate ---

    $secondInstall = AntigravityHookService::install_session_hook();
    assert_equal(true, $secondInstall['installed'], 'install_session_hook: calling twice still reports installed');
    $afterSecond = json_decode((string)file_get_contents($hooksPath), true);
    assert_equal(1, count($afterSecond['sessioneer']['PreToolUse']), 'install_session_hook: calling twice does not duplicate the PreToolUse matcher group');
    assert_equal(1, count($afterSecond['sessioneer']['PreInvocation']), 'install_session_hook: calling twice does not duplicate the flat PreInvocation entry');

    // --- preserves an unrelated hook-group Andres (or a plugin) already has ---

    unlink($hooksPath);
    file_put_contents($hooksPath, json_encode([
        'my-own-lint-hook' => ['PostToolUse' => [['matcher' => 'run_command', 'hooks' => [['type' => 'command', 'command' => './scripts/lint.sh']]]]],
    ]));
    $withExisting = AntigravityHookService::install_session_hook();
    assert_equal(true, $withExisting['ok'], 'install_session_hook: succeeds alongside an unrelated pre-existing hook group');
    $afterExisting = json_decode((string)file_get_contents($hooksPath), true);
    assert_equal('./scripts/lint.sh', $afterExisting['my-own-lint-hook']['PostToolUse'][0]['hooks'][0]['command'] ?? null, 'install_session_hook: leaves an unrelated hook group completely untouched');
    assert_true(isset($afterExisting['sessioneer']), 'install_session_hook: adds its own group alongside the existing one');

    // --- malformed hooks.json: refuses rather than overwriting ---

    unlink($hooksPath);
    file_put_contents($hooksPath, '{not valid json');
    $malformedCheck = AntigravityHookService::check_session_hook();
    assert_equal(false, $malformedCheck['ok'], 'check_session_hook: refuses a malformed hooks.json');
    $malformedInstall = AntigravityHookService::install_session_hook();
    assert_equal(false, $malformedInstall['ok'], 'install_session_hook: refuses a malformed hooks.json');
    assert_equal('{not valid json', file_get_contents($hooksPath), 'install_session_hook: leaves a malformed hooks.json byte-for-byte untouched');
    unlink($hooksPath);

    // --- pre_invocation.php: no-op without SESSIONEER_SESSION_NAME ---

    $noEnvOut = run_antigravity_hook_script("{$hooksDir}/pre_invocation.php", null, ['conversationId' => 'conv-a']);
    assert_equal('{}', trim($noEnvOut), 'pre_invocation.php: outputs {} even with no SESSIONEER_SESSION_NAME (globally-registered hook, must never break an untracked session)');

    // --- pre_invocation.php: first firing binds the sidecar, marks working ---

    $piName = 'ag-test-' . bin2hex(random_bytes(3));
    SidecarStore::write_sidecar($piName, ['workdir' => '/fixture/workdir', 'spawned_at' => 1000, 'agent_session_id' => null, 'spawned_by_app' => true, 'agent' => 'antigravity']);

    $piOut = run_antigravity_hook_script("{$hooksDir}/pre_invocation.php", $piName, ['conversationId' => 'conv-first', 'workspacePaths' => ['/fixture/workdir']]);
    assert_equal('{}', trim($piOut), 'pre_invocation.php: outputs {}');
    $piSidecar = SidecarStore::read_sidecar($piName);
    assert_equal('conv-first', $piSidecar['agent_session_id'] ?? null, 'pre_invocation.php: binds the sidecar to the real conversationId on first firing');
    assert_equal('/fixture/workdir', $piSidecar['workdir'] ?? null, 'pre_invocation.php: preserves the existing workdir across the bind');
    assert_equal(1000, $piSidecar['spawned_at'] ?? null, 'pre_invocation.php: preserves the existing spawned_at across the bind');
    assert_equal('antigravity', $piSidecar['agent'] ?? null, 'pre_invocation.php: preserves the existing agent across the bind');
    assert_equal('working', SessionStatusStore::read_status($piName)['status'] ?? null, 'pre_invocation.php: marks the session working');

    // --- pre_invocation.php: a later firing with the SAME conversationId is a no-op rebind ---

    run_antigravity_hook_script("{$hooksDir}/pre_invocation.php", $piName, ['conversationId' => 'conv-first', 'workspacePaths' => ['/fixture/workdir']]);
    assert_equal('conv-first', SidecarStore::read_sidecar($piName)['agent_session_id'] ?? null, 'pre_invocation.php: a repeat firing with the same conversationId leaves the binding unchanged');

    // --- pre_invocation.php: refuses to rebind onto an id already live on a DIFFERENT tracked session ---
    // agent_session_id_already_live() checks REAL tracked tmux sessions
    // (a live tmux pane + a sidecar - see TmuxService::list_tracked_tmux_sessions()),
    // not just a sidecar row in isolation, so this needs a real fixture pane.

    $piOtherName = 'ag-test-other-' . bin2hex(random_bytes(3));
    $piOtherCreate = TmuxService::tmux_run(['new-session', '-d', '-s', $piOtherName, '-c', sys_get_temp_dir(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $piOtherCreate['exit'], 'already-live test setup: created a live fixture tmux pane');
    SidecarStore::write_sidecar($piOtherName, ['workdir' => '/x', 'spawned_at' => time(), 'agent_session_id' => 'conv-taken', 'spawned_by_app' => true, 'agent' => 'antigravity']);
    run_antigravity_hook_script("{$hooksDir}/pre_invocation.php", $piName, ['conversationId' => 'conv-taken', 'workspacePaths' => ['/fixture/workdir']]);
    assert_equal('conv-first', SidecarStore::read_sidecar($piName)['agent_session_id'] ?? null, 'pre_invocation.php: never rebinds onto a conversationId already live on a different tracked session');
    TmuxService::tmux_run(['kill-session', '-t', $piOtherName]);
    SidecarStore::delete_sidecar($piOtherName);
    SidecarStore::delete_sidecar($piName);

    // --- pre_invocation.php: workspacePaths[0] used as a fallback workdir when the sidecar has none ---

    $piFallbackName = 'ag-test-fallback-' . bin2hex(random_bytes(3));
    SidecarStore::write_sidecar($piFallbackName, ['workdir' => null, 'spawned_at' => time(), 'agent_session_id' => null, 'spawned_by_app' => true, 'agent' => 'antigravity']);
    run_antigravity_hook_script("{$hooksDir}/pre_invocation.php", $piFallbackName, ['conversationId' => 'conv-fallback', 'workspacePaths' => ['/from/workspace/paths']]);
    assert_equal('/from/workspace/paths', SidecarStore::read_sidecar($piFallbackName)['workdir'] ?? null, 'pre_invocation.php: falls back to workspacePaths[0] as workdir when the sidecar has none');
    SidecarStore::delete_sidecar($piFallbackName);

    // --- pre_tool_use.php: no-op without SESSIONEER_SESSION_NAME, still returns a valid decision ---

    $ptuNoEnvOut = run_antigravity_hook_script("{$hooksDir}/pre_tool_use.php", null, ['toolCall' => ['name' => 'run_command', 'args' => ['CommandLine' => 'ls']]]);
    assert_equal('{"decision":"ask"}', trim($ptuNoEnvOut), 'pre_tool_use.php: still returns a valid decision even with no SESSIONEER_SESSION_NAME (globally-registered, must never break an untracked session)');

    // --- pre_tool_use.php: "ask", not "allow" - confirmed live 2026-08-24 that "allow" does not suppress the real approval UI ---

    $ptuName = 'ag-test-ptu-' . bin2hex(random_bytes(3));
    $ptuOut = run_antigravity_hook_script("{$hooksDir}/pre_tool_use.php", $ptuName, ['toolCall' => ['name' => 'run_command', 'args' => ['CommandLine' => 'echo hi']], 'stepIdx' => 3]);
    assert_equal('{"decision":"ask"}', trim($ptuOut), 'pre_tool_use.php: always returns decision=ask (not allow - see this script\'s own docblock for why)');
    $pending = PendingToolStore::read_pending_tool($ptuName);
    assert_equal('run_command', $pending['tool_name'] ?? null, 'pre_tool_use.php: records the real tool_name');
    assert_equal('echo hi', $pending['tool_input']['CommandLine'] ?? null, 'pre_tool_use.php: records the real tool_input (toolCall.args)');
    assert_equal('working', SessionStatusStore::read_status($ptuName)['status'] ?? null, 'pre_tool_use.php: marks the session working');

    // --- pre_tool_use.php: malformed/missing toolCall - no PendingToolStore write, still a valid decision ---

    $ptuBadOut = run_antigravity_hook_script("{$hooksDir}/pre_tool_use.php", $ptuName, ['stepIdx' => 4]);
    assert_equal('{"decision":"ask"}', trim($ptuBadOut), 'pre_tool_use.php: still returns a valid decision when toolCall is missing');

    // --- post_tool_use.php: clears PendingToolStore ---

    $postOut = run_antigravity_hook_script("{$hooksDir}/post_tool_use.php", $ptuName, ['stepIdx' => 3, 'error' => '']);
    assert_equal('{}', trim($postOut), 'post_tool_use.php: outputs {}');
    assert_equal(null, PendingToolStore::read_pending_tool($ptuName), 'post_tool_use.php: clears the pending tool call once it finishes');

    // --- post_tool_use.php: safe no-op with nothing pending, and without SESSIONEER_SESSION_NAME ---

    $postAgainOut = run_antigravity_hook_script("{$hooksDir}/post_tool_use.php", $ptuName, ['stepIdx' => 3]);
    assert_equal('{}', trim($postAgainOut), 'post_tool_use.php: safe no-op when nothing is pending');
    $postNoEnvOut = run_antigravity_hook_script("{$hooksDir}/post_tool_use.php", null, ['stepIdx' => 3]);
    assert_equal('{}', trim($postNoEnvOut), 'post_tool_use.php: still outputs {} with no SESSIONEER_SESSION_NAME');

    // --- stop.php: marks idle, last_message from the real transcript's last PLANNER_RESPONSE ---

    $fixtureTranscriptPath = sys_get_temp_dir() . '/sessioneer-test-agy-transcript-' . bin2hex(random_bytes(4)) . '.jsonl';
    file_put_contents($fixtureTranscriptPath, implode("\n", [
        json_encode(['step_index' => 0, 'source' => 'USER_EXPLICIT', 'type' => 'USER_INPUT', 'status' => 'DONE', 'content' => 'do the thing']),
        json_encode(['step_index' => 1, 'source' => 'MODEL', 'type' => 'PLANNER_RESPONSE', 'status' => 'DONE', 'tool_calls' => [['name' => 'run_command', 'args' => ['CommandLine' => 'echo hi']]], 'content' => null]),
        json_encode(['step_index' => 2, 'source' => 'MODEL', 'type' => 'GENERIC', 'status' => 'DONE', 'content' => 'The command exited with code 0.'], JSON_UNESCAPED_SLASHES),
        json_encode(['step_index' => 3, 'source' => 'MODEL', 'type' => 'PLANNER_RESPONSE', 'status' => 'DONE', 'content' => 'done, the thing is finished']),
    ]) . "\n");

    SessionStatusStore::update_status($ptuName, ['status' => 'working', 'blocked' => null]);
    $stopOut = run_antigravity_hook_script("{$hooksDir}/stop.php", $ptuName, ['executionNum' => 0, 'terminationReason' => 'NO_TOOL_CALL', 'fullyIdle' => true, 'transcriptPath' => $fixtureTranscriptPath]);
    assert_equal('{"decision":"allow_stop"}', trim($stopOut), 'stop.php: always returns decision=allow_stop (a non-"continue" sentinel - never forces continuation)');
    $stopStatus = SessionStatusStore::read_status($ptuName);
    assert_equal('idle', $stopStatus['status'] ?? null, 'stop.php: marks the session idle');
    assert_equal(null, $stopStatus['blocked'], 'stop.php: clears any blocked state');
    assert_equal('done, the thing is finished', $stopStatus['last_message'] ?? null, 'stop.php: last_message comes from the transcript\'s LAST PLANNER_RESPONSE entry, skipping the earlier tool-calls-only one with content:null');
    assert_equal(null, $stopStatus['last_turn_error'] ?? null, 'stop.php: a turn that DID get a real response never sets last_turn_error - the transcript-tail check short-circuits before ever touching the pane');
    unlink($fixtureTranscriptPath);

    // --- stop.php: a turn that got NO response at all (quota exhausted, etc.) - Antigravity itself
    // writes nothing to the transcript for this (confirmed live 2026-08-24, see this function's own
    // docblock in stop.php), so the only place the failure is ever visible is the live pane's own
    // "⚠ ..." banner text - last_turn_error captures it from there instead ---

    $stopErrName = 'ag-test-stoperr-' . bin2hex(random_bytes(3));
    $stopErrCreate = TmuxService::tmux_run(['new-session', '-d', '-s', $stopErrName, '-c', sys_get_temp_dir(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $stopErrCreate['exit'], 'stop.php unanswered-turn test setup: created a live fixture tmux pane');
    // A stale, OLDER error further up in scrollback, and a real answered
    // exchange for a DIFFERENT session's own test above already covers the
    // "must not false-positive on an old ⚠ line" case via a transcript with
    // a real response - this pane only ever needs the CURRENT exchange.
    TmuxService::tmux_run(['send-keys', '-t', $stopErrName, '-l', "> What models still have quota available?\n⚠ Individual quota reached. Please upgrade your subscription to increase your limits. Resets in 5h.\nError ID: fixture-error-id\n"]);

    $stopErrTranscriptPath = sys_get_temp_dir() . '/sessioneer-test-agy-transcript-' . bin2hex(random_bytes(4)) . '.jsonl';
    file_put_contents($stopErrTranscriptPath, json_encode(['step_index' => 0, 'source' => 'USER_EXPLICIT', 'type' => 'USER_INPUT', 'status' => 'DONE', 'content' => 'What models still have quota available?']) . "\n");

    $stopErrOut = run_antigravity_hook_script("{$hooksDir}/stop.php", $stopErrName, ['executionNum' => 0, 'terminationReason' => 'NO_TOOL_CALL', 'fullyIdle' => true, 'transcriptPath' => $stopErrTranscriptPath]);
    assert_equal('{"decision":"allow_stop"}', trim($stopErrOut), 'stop.php: still a valid decision for an unanswered turn');
    $stopErrStatus = SessionStatusStore::read_status($stopErrName);
    assert_equal('⚠ Individual quota reached. Please upgrade your subscription to increase your limits. Resets in 5h.', $stopErrStatus['last_turn_error'] ?? null, 'stop.php: last_turn_error captures the pane\'s own "⚠ ..." banner when the transcript shows no response at all for the most recent turn');
    assert_equal(null, $stopErrStatus['last_message'] ?? null, 'stop.php: last_message stays null for an unanswered turn - there really is no PLANNER_RESPONSE to find');

    unlink($stopErrTranscriptPath);
    TmuxService::tmux_run(['kill-session', '-t', $stopErrName]);
    SessionStatusStore::delete_status($stopErrName);

    // --- pre_invocation.php: clears a stale last_turn_error the moment a NEW turn starts,
    // so a failed reply's banner doesn't linger once the user has already moved on ---

    $piClearName = 'ag-test-piclear-' . bin2hex(random_bytes(3));
    SessionStatusStore::update_status($piClearName, ['status' => 'idle', 'last_turn_error' => '⚠ stale error from a previous turn']);
    run_antigravity_hook_script("{$hooksDir}/pre_invocation.php", $piClearName, ['conversationId' => 'conv-piclear', 'workspacePaths' => ['/fixture/workdir']]);
    assert_equal(null, SessionStatusStore::read_status($piClearName)['last_turn_error'] ?? null, 'pre_invocation.php: clears last_turn_error when a new turn starts');
    SidecarStore::delete_sidecar($piClearName);
    SessionStatusStore::delete_status($piClearName);

    // --- stop.php: no transcript path at all - still marks idle, just no last_message ---

    $stopNoTranscriptOut = run_antigravity_hook_script("{$hooksDir}/stop.php", $ptuName, ['executionNum' => 1, 'terminationReason' => 'NO_TOOL_CALL', 'fullyIdle' => true]);
    assert_equal('{"decision":"allow_stop"}', trim($stopNoTranscriptOut), 'stop.php: still a valid decision with no transcriptPath in the payload');
    assert_equal('idle', SessionStatusStore::read_status($ptuName)['status'] ?? null, 'stop.php: still marks idle with no transcriptPath');

    // --- stop.php: no-op without SESSIONEER_SESSION_NAME, still a valid decision ---

    $stopNoEnvOut = run_antigravity_hook_script("{$hooksDir}/stop.php", null, ['executionNum' => 0]);
    assert_equal('{"decision":"allow_stop"}', trim($stopNoEnvOut), 'stop.php: still returns a valid decision even with no SESSIONEER_SESSION_NAME');

    SessionStatusStore::delete_status($ptuName);
    PendingToolStore::delete_pending_tool($ptuName);
} finally {
    @unlink($hooksPath);
    @rmdir(dirname($hooksPath));
    @rmdir(dirname(dirname($hooksPath)));
    array_map('unlink', glob("{$fixtureSidecarDir}/*") ?: []);
    @rmdir($fixtureSidecarDir);
    @rmdir($fixtureHome);
}

test_exit();
