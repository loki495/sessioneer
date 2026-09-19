<?php
declare(strict_types=1);

/**
 * Exercises PromptInteractionService::set_antigravity_model()/
 * move_antigravity_picker_cursor() against a fake, deterministic stand-in
 * for Antigravity's real `/model` picker (tests/fixtures/
 * fake_antigravity_picker.php - see its own docblock for what it does and
 * doesn't reproduce). All isolated fixture paths, never the real sidecar
 * dir or the real tmux socket - see tests/.env.testing for the isolated
 * TMUX_SOCKET this whole suite runs against.
 *
 * Found live 2026-08-24 (Andres: "when I change the model on an agy
 * session, it immediately reverts to the old one"): an earlier version of
 * set_antigravity_model() sent a fixed-count blind Up-then-Down key
 * sequence, trusting it would always converge - reproduced live that
 * Antigravity's real picker silently drops some fraction of rapid
 * keypresses, so a fixed count under- or over-shoots unpredictably. This
 * file exists specifically to guard against regressing back to that
 * blind-count shape.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\PromptInteractionService;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$realTmuxSocket = '/tmp/tmux-' . getmyuid() . '/default';

$fixtureSidecarDir = sys_get_temp_dir() . '/sessioneer-test-agy-model-switch-sidecars-' . bin2hex(random_bytes(4));

putenv("SIDECAR_DIR={$fixtureSidecarDir}");

if (Config::tmux_socket() === $realTmuxSocket) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket. Check tests/.env.testing.\n");
    exit(1);
}

mkdir($fixtureSidecarDir, 0700, true);

$fakePickerPath = __DIR__ . '/fixtures/fake_antigravity_picker.php';

/**
 * Spawns the fake picker at $startingRow (1-based) as a real tmux pane
 * named $sessionName, with a matching sidecar/status row so
 * set_antigravity_model()'s own guard checks (tracked session, agent,
 * not-busy) pass.
 */
function spawn_fake_picker_session(string $sessionName, string $fakePickerPath, int $startingRow): void
{
    $create = TmuxService::tmux_run(['new-session', '-d', '-s', $sessionName, '-c', sys_get_temp_dir(), 'php', $fakePickerPath, (string)$startingRow]);
    assert_equal(0, $create['exit'], "spawn_fake_picker_session({$sessionName}): created a live fixture tmux pane");

    // Give the fake picker's own PHP startup + `stty -icanon -echo` (see its
    // own docblock) time to actually take effect before any key is sent -
    // unlike the real agy CLI (already fully started and idle by the time
    // set_antigravity_model() is ever called against it in production),
    // this fixture process is freshly spawned right here, and a key sent
    // before its stty call lands stays buffered in canonical/line mode
    // (delivered all at once on the next newline) instead of processed
    // immediately - found live while writing this test: without this delay
    // the walk-to-row-1 phase intermittently reported it couldn't confirm
    // reaching the top row, timing-dependent on how fast PHP started up.
    usleep(300000);

    SidecarStore::write_sidecar($sessionName, ['workdir' => '/fixture', 'spawned_at' => time(), 'agent_session_id' => 'conv-' . $sessionName, 'spawned_by_app' => true, 'agent' => 'antigravity']);
    SessionStatusStore::update_status($sessionName, ['status' => 'idle']);
}

function teardown_fake_picker_session(string $sessionName): void
{
    TmuxService::tmux_run(['kill-session', '-t', $sessionName]);
    SidecarStore::delete_sidecar($sessionName);
    SessionStatusStore::delete_status($sessionName);
}

try {
    // --- already on the target row: converges with the picker never having to move ---

    $alreadyName = 'ag-test-model-already-' . bin2hex(random_bytes(3));
    spawn_fake_picker_session($alreadyName, $fakePickerPath, 1);
    $alreadyResult = PromptInteractionService::set_antigravity_model($alreadyName, 'gemini-3.7-flash');
    assert_equal(true, $alreadyResult['ok'], 'set_antigravity_model: succeeds when the picker opens already on the target row');
    assert_contains('gemini-3.7-flash', $alreadyResult['message'], 'set_antigravity_model: message names the model that was actually set');
    assert_contains('Model set to Gemini 3.7 Flash', TmuxService::tmux_capture_pane($alreadyName), 'set_antigravity_model: the fake picker confirms the correct row was selected');
    teardown_fake_picker_session($alreadyName);

    // --- starting row BELOW the target: must walk up past row 1 first, then back down ---

    $upThenDownName = 'ag-test-model-updown-' . bin2hex(random_bytes(3));
    spawn_fake_picker_session($upThenDownName, $fakePickerPath, 5); // starts on Claude Sonnet 4.6 (Thinking)
    $upThenDownResult = PromptInteractionService::set_antigravity_model($upThenDownName, 'gemini-3.6-flash'); // row 2
    assert_equal(true, $upThenDownResult['ok'], 'set_antigravity_model: succeeds walking from a row below the target back up past row 1');
    assert_contains('Model set to Gemini 3.6 Flash', TmuxService::tmux_capture_pane($upThenDownName), 'set_antigravity_model: lands on the correct row, not wherever count(PICKER_OPTIONS) blind Up presses alone would have left it short at');
    teardown_fake_picker_session($upThenDownName);

    // --- starting row ABOVE the target (the common case: default row 1 -> some later model) ---

    $downOnlyName = 'ag-test-model-downonly-' . bin2hex(random_bytes(3));
    spawn_fake_picker_session($downOnlyName, $fakePickerPath, 1);
    $downOnlyResult = PromptInteractionService::set_antigravity_model($downOnlyName, 'gpt-oss-120b-medium'); // row 7, the furthest possible walk
    assert_equal(true, $downOnlyResult['ok'], 'set_antigravity_model: succeeds walking the full distance to the last row');
    assert_contains('Model set to GPT-OSS 120B (Medium)', TmuxService::tmux_capture_pane($downOnlyName), 'set_antigravity_model: reaches the last row correctly');
    teardown_fake_picker_session($downOnlyName);

    // --- rejects an unrecognized model key without touching the pane at all ---

    $rejectName = 'ag-test-model-reject-' . bin2hex(random_bytes(3));
    spawn_fake_picker_session($rejectName, $fakePickerPath, 1);
    $rejectResult = PromptInteractionService::set_antigravity_model($rejectName, 'not-a-real-model');
    assert_equal(false, $rejectResult['ok'], 'set_antigravity_model: rejects an unrecognized model key');
    assert_true(!str_contains(TmuxService::tmux_capture_pane($rejectName), 'Model set to'), 'set_antigravity_model: never opens/touches the picker for a rejected model key');
    teardown_fake_picker_session($rejectName);

    // --- rejects a session whose SessionStatusStore says it is currently busy ---

    $busyName = 'ag-test-model-busy-' . bin2hex(random_bytes(3));
    spawn_fake_picker_session($busyName, $fakePickerPath, 1);
    SessionStatusStore::update_status($busyName, ['status' => 'working']);
    $busyResult = PromptInteractionService::set_antigravity_model($busyName, 'gemini-3.6-flash');
    assert_equal(false, $busyResult['ok'], 'set_antigravity_model: rejects a session currently marked busy');
    teardown_fake_picker_session($busyName);
} finally {
    array_map('unlink', glob("{$fixtureSidecarDir}/*") ?: []);
    @rmdir($fixtureSidecarDir);
}

test_exit();
