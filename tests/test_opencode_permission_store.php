<?php
declare(strict_types=1);

/**
 * Exercises HostAgent\Services\PermissionStore (the Sessioneer OpenCode plugin bridge:
 * read pending permissions, read/write answer intent, join ses_* -> sidecar
 * name) in isolation - no live opencode process, no real tmux pane (the store
 * is pure JSON files, and the join is a sidecar-DB query against a fixture
 * sidecar dir).
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\PermissionStore;
use HostAgent\Stores\SidecarStore;

$realSidecarDir = '/run/user/' . getmyuid() . '/sessioneer-sessions';

$fixtureSidecarDir = sys_get_temp_dir() . '/sessioneer-test-ocps-sidecars-' . bin2hex(random_bytes(4));
$fixturePermDir = sys_get_temp_dir() . '/sessioneer-test-ocps-perms-' . bin2hex(random_bytes(4));
putenv("SIDECAR_DIR={$fixtureSidecarDir}");
putenv("OPENCODE_PERMISSION_DIR={$fixturePermDir}");

if (Config::sidecar_dir() === $realSidecarDir) {
    fwrite(STDERR, "REFUSING TO RUN: SIDECAR_DIR resolves to the real sidecar dir.\n");
    exit(1);
}

mkdir($fixtureSidecarDir, 0700, true);
mkdir($fixturePermDir, 0700, true);

try {
    // --- read_pending_permission: no record -> null; invalid id -> null ---
    assert_equal(null, PermissionStore::read_pending_permission('ses_missing'), 'read_pending_permission: null for a session with no record');
    assert_equal(null, PermissionStore::read_pending_permission('not-a-ses-id'), 'read_pending_permission: null for a non-ses_ id');

    $ses = 'ses_testperm123';

    // --- write + read round-trip ---
    $permission = [
        'id' => 'per_abc',
        'type' => 'require',
        'sessionID' => $ses,
        'title' => 'Access external directory ~/dotfiles/claude/agents',
        'pattern' => ['/home/user/dotfiles/claude/agents/*'],
        'metadata' => ['message' => 'Run bash: ls -la'],
    ];
    PermissionStore::write_pending_permission($ses, $permission);
    assert_equal('Access external directory ~/dotfiles/claude/agents', PermissionStore::read_pending_permission($ses)['title'] ?? null, 'write_pending_permission + read_pending_permission: round-trips the record');

    // --- answer intent write + consume (consume clears) ---
    assert_equal(null, PermissionStore::consume_answer_intent($ses), 'consume_answer_intent: null before any intent is written');
    PermissionStore::write_answer_intent($ses, 'allow');
    assert_equal('allow', PermissionStore::consume_answer_intent($ses), 'consume_answer_intent: returns the written intent');
    assert_equal(null, PermissionStore::consume_answer_intent($ses), 'consume_answer_intent: cleared after a consume');

    PermissionStore::write_answer_intent($ses, 'deny');
    assert_equal('deny', PermissionStore::consume_answer_intent($ses), 'consume_answer_intent: deny works too');

    // --- delete clears the whole record ---
    PermissionStore::delete_permission($ses);
    assert_equal(null, PermissionStore::read_pending_permission($ses), 'delete_permission: removes the record');

    // --- sidecar join: ses_* -> Sessioneer session name via a fixture sidecar ---
    $sessName = 'oc-test-ocps-' . getmypid();
    SidecarStore::write_sidecar($sessName, ['workdir' => sys_get_temp_dir(), 'spawned_at' => time(), 'agent_session_id' => $ses, 'spawned_by_app' => true, 'agent' => 'opencode']);
    assert_equal($sessName, PermissionStore::find_by_session_id($ses), 'find_by_session_id: resolves ses_* to the bound sidecar session name');
    assert_equal(null, PermissionStore::find_by_session_id('ses_nonexistent'), 'find_by_session_id: null for an unbound id');
    SidecarStore::delete_sidecar($sessName);
} finally {
    array_map('unlink', glob("{$fixtureSidecarDir}/*") ?: []);
    array_map('unlink', glob("{$fixturePermDir}/*") ?: []);
    @rmdir($fixtureSidecarDir);
    @rmdir($fixturePermDir);
}

test_exit();
