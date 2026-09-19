<?php

declare(strict_types=1);

/**
 * Tests that sessioneer_merge_headless_sessions() and sessioneer_headless_detail_shape()
 * (host-agent/lib/Sessions.php) surface a Codex question-type blocked
 * prompt's structured question data as `prompt_questions` - the second half
 * of PLAN.md Task 1 (fix A1: Codex question-type prompts unanswerable). See
 * tests/test_codex_prompt_protocol.php for the bridge-side response-
 * building fix. Both functions hardcoded `'prompt_questions' => null`
 * before this fix, so the shared multi-question form (session.js's
 * isMultiQuestion gate, BlockedPromptView::blocked_multi_question_html())
 * never rendered for a Codex session, regardless of question count -
 * falling back to a flattened single-option view built from only the first
 * question's options.
 *
 * Pre-seeds both sync throttle keys with a fresh timestamp so
 * sessioneer_headless_sync()/sessioneer_codex_sync() skip their real serve/bridge round
 * trips entirely - the fixtures below write sidecar/status state directly
 * (the "canonical shape the sync wrote" Sessions.php's own comment refers
 * to), so there is nothing for a real sync to usefully do here, and this
 * test environment has no real `opencode serve` or Codex bridge socket to
 * reach anyway.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\Config;
use HostAgent\Stores\GlobalStateStore;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SqliteDb;

$realTmuxSocket = '/tmp/tmux-' . getmyuid() . '/default';
$realSidecarDir = '/run/user/' . getmyuid() . '/sessioneer-sessions';

if (Config::tmux_socket() === $realTmuxSocket) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket. Check tests/.env.testing.\n");
    exit(1);
}

if (Config::sidecar_dir() === $realSidecarDir) {
    fwrite(STDERR, "REFUSING TO RUN: SIDECAR_DIR resolves to the real host sidecar dir. Check tests/.env.testing.\n");
    exit(1);
}

putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
$statusDb = sys_get_temp_dir() . '/sessioneer-test-codex-prompt-questions-' . bin2hex(random_bytes(4)) . '.sqlite';
$pushDb = sys_get_temp_dir() . '/sessioneer-test-codex-prompt-questions-push-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv("SESSIONS_SQLITE_FILE={$statusDb}");
putenv("PUSH_SQLITE_FILE={$pushDb}");
SqliteDb::reset_connections_for_tests();

GlobalStateStore::write('codex_headless_sessions_sync', ['last_sync' => time()]);
GlobalStateStore::write('headless_sessions_sync', ['last_sync' => time()]);

$multiQuestions = [
    ['id' => 'q1', 'question' => 'Deploy now?', 'options' => [['label' => 'Yes'], ['label' => 'No']]],
    ['id' => 'q2', 'question' => 'Notify team?', 'options' => [['label' => 'Yes'], ['label' => 'No']]],
];
$singleQuestion = [
    ['id' => 'q1', 'question' => 'Which environment?', 'options' => [['label' => 'Staging'], ['label' => 'Production']]],
];

$multiBlocked = [
    'tool_name' => 'question',
    'question' => 'Deploy now?',
    'context' => '',
    'options' => [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']],
    'multi_question' => true,
    'is_folder_trust' => false,
    'tool_input' => ['questions' => $multiQuestions],
    'request_id' => 'item-1',
];
$singleBlocked = [
    'tool_name' => 'question',
    'question' => 'Which environment?',
    'context' => '',
    'options' => [['number' => 1, 'label' => 'Staging'], ['number' => 2, 'label' => 'Production']],
    'multi_question' => false,
    'is_folder_trust' => false,
    'tool_input' => ['questions' => $singleQuestion],
    'request_id' => 'item-2',
];
$permissionBlocked = [
    'tool_name' => 'permission',
    'question' => 'Allow Codex to run this command?',
    'context' => 'rm -rf build',
    'options' => [['number' => 1, 'label' => 'Allow once'], ['number' => 2, 'label' => 'Allow for session'], ['number' => 3, 'label' => 'Deny'], ['number' => 4, 'label' => 'Deny and stop']],
    'multi_question' => false,
    'is_folder_trust' => false,
    'tool_input' => ['command' => 'rm -rf build'],
    'request_id' => 'item-3',
];
$externalBlocked = [
    'tool_name' => 'external_input',
    'question' => 'Approval required in Codex Remote.',
    'context' => 'Run the deployment command?',
    'options' => [],
    'multi_question' => false,
    'is_folder_trust' => false,
    'tool_input' => ['command' => 'deploy'],
    'external' => true,
];

function pqw_write_codex_sidecar(string $ref, string $title): void
{
    SidecarStore::write_sidecar($ref, [
        'workdir' => '/tmp/project',
        'spawned_at' => time(),
        'agent_session_id' => $ref,
        'spawned_by_app' => true,
        'agent' => 'codex',
        'runtime' => RuntimeType::HEADLESS,
        'title' => $title,
    ]);
}

/** @return array<string,mixed>|null */
function pqw_find_row(array $rows, string $name): ?array
{
    foreach ($rows as $row) {
        if (($row['name'] ?? null) === $name) return $row;
    }
    return null;
}

pqw_write_codex_sidecar('codex-multi-q', 'Deploy session');
SessionStatusStore::update_status('codex-multi-q', ['status' => 'blocked', 'blocked' => $multiBlocked]);

pqw_write_codex_sidecar('codex-single-q', 'Env session');
SessionStatusStore::update_status('codex-single-q', ['status' => 'blocked', 'blocked' => $singleBlocked]);

pqw_write_codex_sidecar('codex-permission', 'Approval session');
SessionStatusStore::update_status('codex-permission', ['status' => 'blocked', 'blocked' => $permissionBlocked]);

pqw_write_codex_sidecar('codex-idle', 'Idle session');

pqw_write_codex_sidecar('codex-external', 'Remote approval');
SessionStatusStore::update_status('codex-external', ['status' => 'blocked', 'blocked' => $externalBlocked]);

SidecarStore::write_sidecar('oc-blocked', [
    'workdir' => '/tmp/project',
    'spawned_at' => time(),
    'agent_session_id' => 'oc-blocked',
    'spawned_by_app' => true,
    'agent' => 'opencode',
    'runtime' => RuntimeType::HEADLESS,
    'title' => 'OpenCode session',
]);
SessionStatusStore::update_status('oc-blocked', ['status' => 'blocked', 'blocked' => [
    'tool_name' => 'permission',
    'question' => 'Allow this?',
    'options' => [['number' => 1, 'label' => 'Yes']],
]]);

// --- sessioneer_merge_headless_sessions() ---

$merged = sessioneer_merge_headless_sessions([]);

$multiRow = pqw_find_row($merged, 'codex-multi-q');
assert_true($multiRow !== null, 'the blocked Codex multi-question session is merged into the sessions list');
assert_equal($multiQuestions, $multiRow['prompt_questions'] ?? null, 'sessioneer_merge_headless_sessions() surfaces the full raw questions array as prompt_questions for a 2-question Codex prompt');

$singleRow = pqw_find_row($merged, 'codex-single-q');
assert_true($singleRow !== null, 'the blocked Codex single-question session is merged into the sessions list');
assert_equal($singleQuestion, $singleRow['prompt_questions'] ?? null, 'sessioneer_merge_headless_sessions() surfaces prompt_questions for a Codex question prompt even with only 1 question - unlike Claude Code\'s own count>=2 threshold, Codex has no working pane fallback for a single question');

$permRow = pqw_find_row($merged, 'codex-permission');
assert_true($permRow !== null, 'the blocked Codex permission-type session is merged into the sessions list');
assert_true(array_key_exists('prompt_questions', $permRow) && $permRow['prompt_questions'] === null, 'sessioneer_merge_headless_sessions() leaves prompt_questions null for a permission-type (approve/deny) prompt');
assert_true(!empty($permRow['prompt_options']), 'the permission-type prompt still gets its flattened prompt_options - the existing fallback path this task must not regress');

$idleRow = pqw_find_row($merged, 'codex-idle');
assert_true($idleRow !== null, 'an idle Codex session (no blocked prompt at all) is merged into the sessions list');
assert_true(array_key_exists('prompt_questions', $idleRow) && $idleRow['prompt_questions'] === null, 'an idle Codex session has a null prompt_questions, not a crash on a missing blocked array');

$externalRow = pqw_find_row($merged, 'codex-external');
assert_true($externalRow !== null, 'the externally answerable Codex prompt is merged into the sessions list');
assert_equal(true, $externalRow['prompt_external'] ?? null, 'sessioneer_merge_headless_sessions() marks a Remote-owned prompt as externally answerable');
assert_equal([], $externalRow['prompt_options'] ?? null, 'an externally answerable prompt exposes no nonfunctional Sessioneer answer controls');
assert_equal(null, $externalRow['prompt_questions'] ?? null, 'an externally answerable prompt exposes no nonfunctional Sessioneer question form');

$ocRow = pqw_find_row($merged, 'oc-blocked');
assert_true($ocRow !== null, 'the OpenCode session is merged into the sessions list');
assert_true(array_key_exists('prompt_questions', $ocRow) && $ocRow['prompt_questions'] === null, 'an OpenCode permission-type prompt still gets a null prompt_questions - this fix is Codex tool_name=question specific, no other agent/prompt shape is affected');

// --- sessioneer_headless_detail_shape() ---

$multiDetail = sessioneer_headless_detail_shape(['id' => 'codex-multi-q', 'directory' => '/tmp/project', 'title' => 'Deploy session'], 'codex');
assert_equal($multiQuestions, $multiDetail['prompt_questions'] ?? null, 'sessioneer_headless_detail_shape() surfaces prompt_questions for a 2-question Codex prompt');

$singleDetail = sessioneer_headless_detail_shape(['id' => 'codex-single-q', 'directory' => '/tmp/project', 'title' => 'Env session'], 'codex');
assert_equal($singleQuestion, $singleDetail['prompt_questions'] ?? null, 'sessioneer_headless_detail_shape() surfaces prompt_questions for a Codex question prompt with only 1 question too');

$permDetail = sessioneer_headless_detail_shape(['id' => 'codex-permission', 'directory' => '/tmp/project', 'title' => 'Approval session'], 'codex');
assert_true(array_key_exists('prompt_questions', $permDetail) && $permDetail['prompt_questions'] === null, 'sessioneer_headless_detail_shape() leaves prompt_questions null for a permission-type prompt');
assert_true(!empty($permDetail['prompt_options']), 'sessioneer_headless_detail_shape() still surfaces the permission-type prompt\'s flattened prompt_options');

$idleDetail = sessioneer_headless_detail_shape(['id' => 'codex-idle', 'directory' => '/tmp/project', 'title' => 'Idle session'], 'codex');
assert_true(array_key_exists('prompt_questions', $idleDetail) && $idleDetail['prompt_questions'] === null, 'sessioneer_headless_detail_shape() leaves prompt_questions null for an idle session with no blocked prompt');

$externalDetail = sessioneer_headless_detail_shape(['id' => 'codex-external', 'directory' => '/tmp/project', 'title' => 'Remote approval'], 'codex');
assert_equal(true, $externalDetail['prompt_external'] ?? null, 'sessioneer_headless_detail_shape() marks a Remote-owned prompt as externally answerable');
assert_equal([], $externalDetail['prompt_options'] ?? null, 'sessioneer_headless_detail_shape() offers no local controls for an externally answerable prompt');

// cleanup so these fixtures don't leak into other test files sharing the
// isolated sidecar dir
foreach (['codex-multi-q', 'codex-single-q', 'codex-permission', 'codex-idle', 'codex-external', 'oc-blocked'] as $ref) {
    SidecarStore::delete_sidecar($ref);
    SessionStatusStore::delete_status($ref);
}
SqliteDb::reset_connections_for_tests();
@unlink($statusDb);
@unlink($statusDb . '-wal');
@unlink($statusDb . '-shm');
@unlink($pushDb);
@unlink($pushDb . '-wal');
@unlink($pushDb . '-shm');

echo "Codex prompt_questions wiring tests passed.\n";
test_exit();
