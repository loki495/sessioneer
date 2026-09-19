<?php

declare(strict_types=1);

/**
 * Tests for Codex session resumption via sessioneer_codex_resume().
 * Exercises the resume path for archived Codex threads - ensuring they're
 * re-adopted as headless sessions with the correct sidecar shape.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/../host-agent/lib/Sessions.php';

use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\Config;
use HostAgent\Services\SessionService;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\SidecarStore;

// Ensure we're using the test fixture environment
$realTmuxSocket = '/tmp/tmux-' . getmyuid() . '/default';

if (Config::tmux_socket() === $realTmuxSocket) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket. Check tests/.env.testing.\n");
    exit(1);
}

try {
    $testWorkdir = Config::www_root() . '/test-workdir-codex-resume';
    @mkdir($testWorkdir, 0755, true);

    $threadId1 = 'codex-thread-resume-1';
    $threadId2 = 'codex-thread-resume-2';

    // --- Codex resume: validation (sad paths) ---

    fwrite(STDERR, "Testing Codex resume validation...\n");

    // Test relative workdir rejection
    $result = sessioneer_codex_resume('relative/path', $threadId1);
    assert_equal(false, $result['ok'] ?? null, 'codex_resume: rejects a relative workdir');
    assert_true(str_contains($result['message'] ?? '', 'absolute path'), 'codex_resume: error mentions absolute path');

    // Test non-existent workdir rejection
    $result = sessioneer_codex_resume('/nonexistent/path/that/does/not/exist', $threadId1);
    assert_equal(false, $result['ok'] ?? null, 'codex_resume: rejects a non-existent workdir');
    assert_true(str_contains($result['message'] ?? '', 'does not exist'), 'codex_resume: error mentions existence');

    // Test empty workdir rejection
    $result = sessioneer_codex_resume('', $threadId1);
    assert_equal(false, $result['ok'] ?? null, 'codex_resume: rejects empty workdir');

    // --- Codex resume: happy path ---

    fwrite(STDERR, "Testing Codex resume happy path...\n");

    // Resume the Codex session
    $resumed = sessioneer_codex_resume($testWorkdir, $threadId1);

    assert_true($resumed['ok'] ?? false, 'codex_resume: ok=true');
    assert_equal($threadId1, $resumed['name'] ?? null, 'codex_resume: returns thread id as name');
    assert_equal($threadId1, $resumed['session'] ?? null, 'codex_resume: returns thread id as session');
    assert_equal($threadId1, $resumed['id'] ?? null, 'codex_resume: returns thread id as id');

    // Verify sidecar was written with correct shape
    $sidecar = SidecarStore::read_sidecar($threadId1);
    assert_true($sidecar !== null, 'codex_resume: sidecar was written');
    assert_equal('codex', $sidecar['agent'] ?? null, 'codex_resume: sidecar has agent=codex');
    assert_equal(RuntimeType::HEADLESS, $sidecar['runtime'] ?? null, 'codex_resume: sidecar has runtime=headless');
    assert_equal($threadId1, $sidecar['agent_session_id'] ?? null, 'codex_resume: sidecar has correct agent_session_id');
    assert_equal($testWorkdir, $sidecar['workdir'] ?? null, 'codex_resume: sidecar has correct workdir');
    assert_equal(true, $sidecar['spawned_by_app'] ?? null, 'codex_resume: sidecar has spawned_by_app=true');
    // Title should be null (will be populated on next sync from thread metadata)
    assert_equal(null, $sidecar['title'], 'codex_resume: title is null (populated later by sync)');

    // --- Codex resume: verify correct shape doesn't reach tmux path ---

    fwrite(STDERR, "Testing that resumed Codex session has headless runtime...\n");

    // If we can verify the sidecar exists with runtime=headless, we know it
    // won't be routed to SessionLifecycleService::resume_agent_session() (which
    // only handles tmux sessions). This is the clearest proof the bug is fixed.
    $sidecarAfter = SidecarStore::read_sidecar($threadId1);
    assert_true(
        ($sidecarAfter['runtime'] ?? null) === RuntimeType::HEADLESS,
        'codex_resume: resumed session has runtime=headless (prevents tmux routing)'
    );

    // --- Test another thread to ensure multiple resumes work ---

    fwrite(STDERR, "Testing multiple Codex resumes...\n");

    $resumed2 = sessioneer_codex_resume($testWorkdir, $threadId2);
    assert_true($resumed2['ok'] ?? false, 'codex_resume: ok=true for second thread');
    $sidecar2 = SidecarStore::read_sidecar($threadId2);
    assert_equal('codex', $sidecar2['agent'] ?? null, 'codex_resume: second sidecar has agent=codex');
    assert_equal(RuntimeType::HEADLESS, $sidecar2['runtime'] ?? null, 'codex_resume: second sidecar has runtime=headless');

    // --- Dispatch-level routing tests (proves routing logic handles Codex correctly) ---

    fwrite(STDERR, "Testing dispatch_action('resume') routing for Codex...\n");

    // Set up a Codex archive fixture for dispatch routing tests
    $archiveHome = sys_get_temp_dir() . '/sessioneer-dispatch-resume-' . bin2hex(random_bytes(4));
    @mkdir($archiveHome . '/.codex/archived_sessions', 0700, true);
    $codexThreadId = '02b00000-0000-7000-8000-000000000002';
    $archivePath = $archiveHome . '/.codex/archived_sessions/rollout-2026-08-29T12-30-45-' . $codexThreadId . '.jsonl';
    file_put_contents($archivePath, json_encode(['type' => 'session_meta', 'payload' => ['session_id' => $codexThreadId, 'timestamp' => '2026-08-29T12:30:45Z', 'cwd' => $testWorkdir]]) . "\n");
    putenv("HOME_ROOT={$archiveHome}");

    // Test 1: dispatch_action() routes a Codex id to sessioneer_codex_resume(), not resume_agent_session()
    $dispatchResult = dispatch_action([
        'action' => 'resume',
        'workdir' => $testWorkdir,
        'agent_session_id' => $codexThreadId,
    ]);

    assert_true($dispatchResult['ok'] ?? false, 'dispatch_action(resume): Codex thread routes correctly, ok=true');
    assert_equal($codexThreadId, $dispatchResult['name'] ?? null, 'dispatch_action(resume): Codex thread returns thread id as name');
    assert_equal($codexThreadId, $dispatchResult['session'] ?? null, 'dispatch_action(resume): Codex thread returns thread id as session');
    assert_equal($codexThreadId, $dispatchResult['id'] ?? null, 'dispatch_action(resume): Codex thread returns thread id as id');

    // Verify the sidecar was written (proving it went through sessioneer_codex_resume, not resume_agent_session)
    $dispatchedSidecar = SidecarStore::read_sidecar($codexThreadId);
    assert_true($dispatchedSidecar !== null, 'dispatch_action(resume): Codex thread wrote a sidecar');
    assert_equal('codex', $dispatchedSidecar['agent'] ?? null, 'dispatch_action(resume): Codex thread sidecar has agent=codex');
    assert_equal(RuntimeType::HEADLESS, $dispatchedSidecar['runtime'] ?? null, 'dispatch_action(resume): Codex thread sidecar has runtime=headless');

    // Test 2: dispatch_action() with a non-Codex id that doesn't resolve anywhere should NOT succeed (regression check)
    // A completely unrecognized id string falls through to resume_agent_session(), which spawns a
    // REAL tmux session (against the isolated test socket) with the fake claude binary before
    // failing/succeeding on its own terms - not a no-op. That spawned session must be torn down
    // before this file exits, or it leaks into every test file that runs after this one in the
    // same `tests/run.sh` invocation (confirmed live: it stalled test_ui_smoke.php's headless
    // browser pass). test_sessions_lifecycle.php's own resume-path tests establish the same
    // pattern (see its "TmuxService::tmux_run(['kill-server'])" after exercising
    // resume_agent_session()'s real-spawn path) - kill-server is the isolated test socket only,
    // never the real host tmux server (see this file's own TMUX_SOCKET guard above).
    $unknownId = 'totally-fake-unknown-id-12345';
    $unknownResult = dispatch_action([
        'action' => 'resume',
        'workdir' => $testWorkdir,
        'agent_session_id' => $unknownId,
    ]);
    // It should NOT be ok=true (since this id resolves nowhere and resume_agent_session will fail it)
    // and it should NOT have a sidecar with headless runtime (proving it didn't route to codex)
    $unknownSidecar = SidecarStore::read_sidecar($unknownId);
    assert_equal(null, $unknownSidecar, 'dispatch_action(resume): unknown id does NOT create a Codex sidecar');
    TmuxService::tmux_run(['kill-server']);

    // Cleanup dispatch test fixtures
    putenv('HOME_ROOT');
    @unlink($archivePath);
    @rmdir($archiveHome . '/.codex/archived_sessions');
    @rmdir($archiveHome . '/.codex');
    @rmdir($archiveHome);

    echo "✓ All Codex resume tests passed\n";
} finally {
    // Cleanup
    @shell_exec("rm -rf {$testWorkdir}");
    // Clean up sidecars written during tests
    if (isset($threadId1)) {
        SidecarStore::delete_sidecar($threadId1);
    }
    if (isset($threadId2)) {
        SidecarStore::delete_sidecar($threadId2);
    }
    if (isset($codexThreadId)) {
        SidecarStore::delete_sidecar($codexThreadId);
    }
}
