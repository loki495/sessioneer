<?php

declare(strict_types=1);

/**
 * Unit tests for HostAgent\Services\CodexPromptProtocol - the normalize/
 * response logic extracted out of host-agent/codex_bridge.php
 * so it can be tested without spawning a real `codex app-server --stdio`
 * process or binding the bridge's UNIX socket. See PLAN.md Task 1 (fix A1:
 * Codex question-type prompts unanswerable) for the bug this fixes: the old
 * bridge code stringified a selected option's raw 1-based ordinal
 * ("1"/"2") instead of resolving it to the option's real label text before
 * sending it back to app-server.
 *
 * Pure functions, no process/socket/fixture setup needed - this file needs
 * nothing from tests/.env.testing.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';

use HostAgent\Services\CodexPromptProtocol;

// --- normalize_prompt(): item/tool/requestUserInput (question-type) ---

$singleQuestionParams = [
    'itemId' => 'item-1',
    'questions' => [
        [
            'id' => 'q1',
            'question' => 'Which environment?',
            'options' => [
                ['label' => 'Staging'],
                ['label' => 'Production'],
            ],
        ],
    ],
];
$singleNormalized = CodexPromptProtocol::normalize_prompt('item/tool/requestUserInput', $singleQuestionParams);
assert_equal('question', $singleNormalized['tool_name'], 'single-question requestUserInput normalizes to tool_name=question');
assert_equal('Which environment?', $singleNormalized['question'], 'single-question normalize surfaces the first question text');
assert_equal(false, $singleNormalized['multi_question'], 'a 1-question prompt is not flagged multi_question');
assert_equal([['number' => 1, 'label' => 'Staging'], ['number' => 2, 'label' => 'Production']], $singleNormalized['options'], 'single-question normalize numbers the first question\'s options 1-based');
assert_equal($singleQuestionParams['questions'], $singleNormalized['tool_input']['questions'], 'normalize preserves the full raw questions array in tool_input for the multi-question form to read');
assert_equal('item-1', $singleNormalized['request_id'], 'normalize carries the itemId through as request_id');
assert_equal(false, $singleNormalized['is_folder_trust'], 'a question-type prompt is never the folder-trust dialog');

$twoQuestionParams = [
    'itemId' => 'item-2',
    'questions' => [
        ['id' => 'q1', 'question' => 'Deploy now?', 'options' => [['label' => 'Yes'], ['label' => 'No']]],
        ['id' => 'q2', 'question' => 'Notify team?', 'options' => [['label' => 'Yes'], ['label' => 'No']]],
    ],
];
$twoNormalized = CodexPromptProtocol::normalize_prompt('item/tool/requestUserInput', $twoQuestionParams);
assert_equal(true, $twoNormalized['multi_question'], 'a 2-question prompt is flagged multi_question');
assert_equal(2, count($twoNormalized['tool_input']['questions']), 'normalize keeps both questions in tool_input, not just the first');

// --- normalize_prompt(): each *requestApproval method (permission-type) ---

$commandApproval = CodexPromptProtocol::normalize_prompt('item/commandExecution/requestApproval', ['itemId' => 'c1', 'command' => 'rm -rf build']);
assert_equal('permission', $commandApproval['tool_name'], 'commandExecution approval normalizes to tool_name=permission');
assert_equal('Allow Codex to run this command?', $commandApproval['question'], 'commandExecution approval uses the command-run question text');
assert_equal('rm -rf build', $commandApproval['context'], 'commandExecution approval surfaces the raw command as context');
assert_equal(4, count($commandApproval['options']), 'permission-type prompt always offers the fixed 4-option menu');
assert_equal(['number' => 1, 'label' => 'Allow once'], $commandApproval['options'][0], 'permission-type option 1 is Allow once');
assert_equal(['number' => 2, 'label' => 'Allow for session'], $commandApproval['options'][1], 'permission-type option 2 is Allow for session');
assert_equal(['number' => 3, 'label' => 'Deny'], $commandApproval['options'][2], 'permission-type option 3 is Deny');
assert_equal(['number' => 4, 'label' => 'Deny and stop'], $commandApproval['options'][3], 'permission-type option 4 is Deny and stop');

$fileChangeApproval = CodexPromptProtocol::normalize_prompt('item/fileChange/requestApproval', ['itemId' => 'c2']);
assert_equal('Allow Codex to change files?', $fileChangeApproval['question'], 'fileChange approval uses the file-change question text');

$permissionsApproval = CodexPromptProtocol::normalize_prompt('item/permissions/requestApproval', ['itemId' => 'c3', 'reason' => 'needs network access']);
assert_equal('Grant additional permissions?', $permissionsApproval['question'], 'permissions approval uses the grant-permissions question text');
assert_equal('needs network access', $permissionsApproval['context'], 'permissions approval falls back to reason when there is no command');

// --- prompt_response(): item/tool/requestUserInput - THE BUG FIX ---

$pendingSingle = [
    'request_id' => 42,
    'method' => 'item/tool/requestUserInput',
    'params' => $singleQuestionParams,
    'prompt' => $singleNormalized,
];

// The core bug: selecting option 1 ("Staging") must resolve to the label
// text "Staging", not the raw ordinal "1" the old bridge code sent
// (array_map('strval', $value)). This assertion fails against the OLD
// codex_prompt_response() behavior and passes against the fix.
$response = CodexPromptProtocol::prompt_response($pendingSingle, ['answers' => [1]]);
assert_equal(['Staging'], $response['answers']->q1['answers'] ?? null, 'selecting option 1 resolves to its real label text, not the raw ordinal "1"');
assert_true($response['answers']->q1['answers'][0] !== '1', 'the OLD bug (raw stringified ordinal as the answer) does not reproduce');

$response2 = CodexPromptProtocol::prompt_response($pendingSingle, ['answers' => [2]]);
assert_equal(['Production'], $response2['answers']->q1['answers'] ?? null, 'selecting option 2 resolves to its real label text');

// Free text: {'text': '...'} shape from collectMultiQuestionAnswers().
$response3 = CodexPromptProtocol::prompt_response($pendingSingle, ['answers' => [['text' => 'Some custom answer']]]);
assert_equal(['Some custom answer'], $response3['answers']->q1['answers'] ?? null, 'free-text answer is passed through verbatim');

// Multi-select: array of ordinals, each resolved to its own label -
// structurally unreachable from Codex's own questions today (they never
// set multiSelect), but the frontend form supports it generically.
$multiSelectQuestion = [
    'itemId' => 'item-3',
    'questions' => [
        ['id' => 'q1', 'question' => 'Which regions?', 'multiSelect' => true, 'options' => [['label' => 'US'], ['label' => 'EU'], ['label' => 'APAC']]],
    ],
];
$pendingMultiSelect = ['request_id' => 43, 'method' => 'item/tool/requestUserInput', 'params' => $multiSelectQuestion, 'prompt' => []];
$response4 = CodexPromptProtocol::prompt_response($pendingMultiSelect, ['answers' => [[1, 3]]]);
assert_equal(['US', 'APAC'], $response4['answers']->q1['answers'] ?? null, 'multi-select ordinals each resolve to their own label text, in order');

// Two-question prompt: answers matched by index against $pending's questions.
$pendingTwo = ['request_id' => 44, 'method' => 'item/tool/requestUserInput', 'params' => $twoQuestionParams, 'prompt' => $twoNormalized];
$response5 = CodexPromptProtocol::prompt_response($pendingTwo, ['answers' => [1, 2]]);
assert_equal(['Yes'], $response5['answers']->q1['answers'] ?? null, 'multi-question response resolves the first question\'s answer against its own options');
assert_equal(['No'], $response5['answers']->q2['answers'] ?? null, 'multi-question response resolves the second question\'s answer against its own options, not the first question\'s');

// --- prompt_response(): sad paths ---

// Out-of-range index - must never crash, falls back to the raw stringified
// ordinal defensively.
$responseOOB = CodexPromptProtocol::prompt_response($pendingSingle, ['answers' => [99]]);
assert_equal(['99'], $responseOOB['answers']->q1['answers'] ?? null, 'an out-of-range option index falls back to the raw stringified ordinal instead of crashing');

// Malformed/missing options array on a question - must never crash.
$noOptionsQuestion = [
    'itemId' => 'item-4',
    'questions' => [['id' => 'q1', 'question' => 'No options here']],
];
$pendingNoOptions = ['request_id' => 45, 'method' => 'item/tool/requestUserInput', 'params' => $noOptionsQuestion, 'prompt' => []];
$responseNoOptions = CodexPromptProtocol::prompt_response($pendingNoOptions, ['answers' => [1]]);
assert_equal(['1'], $responseNoOptions['answers']->q1['answers'] ?? null, 'a missing options array falls back to the raw stringified ordinal instead of crashing');

// A question with no supplied answer at all - resolves to an empty list,
// never crashes on a missing index.
$responseMissing = CodexPromptProtocol::prompt_response($pendingSingle, ['answers' => []]);
assert_equal([], $responseMissing['answers']->q1['answers'] ?? null, 'a question with no supplied answer resolves to an empty answers list');

// Malformed answers payload entirely (not an array) - treated as no answers.
$responseMalformed = CodexPromptProtocol::prompt_response($pendingSingle, ['answers' => 'not-an-array']);
assert_equal([], $responseMalformed['answers']->q1['answers'] ?? null, 'a non-array answers payload is treated as no answers, never crashes');

// A question with no string id is skipped entirely - never fatals on a
// malformed question shape.
$pendingBadQuestionId = [
    'request_id' => 46,
    'method' => 'item/tool/requestUserInput',
    'params' => ['questions' => [['question' => 'no id field', 'options' => []]]],
    'prompt' => [],
];
$responseBadId = CodexPromptProtocol::prompt_response($pendingBadQuestionId, ['answers' => [1]]);
assert_equal([], (array)$responseBadId['answers'], 'a question missing a string id is skipped, never fatals');

// --- prompt_response(): permission-type (approve/deny) - unaffected by this fix ---

$pendingCommand = ['request_id' => 50, 'method' => 'item/commandExecution/requestApproval', 'params' => ['command' => 'ls'], 'prompt' => []];
assert_equal(['decision' => 'accept'], CodexPromptProtocol::prompt_response($pendingCommand, ['option' => 1]), 'command approval option 1 maps to decision=accept');
assert_equal(['decision' => 'acceptForSession'], CodexPromptProtocol::prompt_response($pendingCommand, ['option' => 2]), 'command approval option 2 maps to decision=acceptForSession');
assert_equal(['decision' => 'decline'], CodexPromptProtocol::prompt_response($pendingCommand, ['option' => 3]), 'command approval option 3 maps to decision=decline');
assert_equal(['decision' => 'cancel'], CodexPromptProtocol::prompt_response($pendingCommand, ['option' => 4]), 'command approval option 4 maps to decision=cancel');
assert_equal(null, CodexPromptProtocol::prompt_response($pendingCommand, ['option' => 0]), 'command approval rejects an unrecognized option number');
assert_equal(null, CodexPromptProtocol::prompt_response($pendingCommand, []), 'command approval rejects a request with no option at all');

$pendingPermissions = ['request_id' => 51, 'method' => 'item/permissions/requestApproval', 'params' => ['permissions' => ['network' => true]], 'prompt' => []];
$permResponse = CodexPromptProtocol::prompt_response($pendingPermissions, ['option' => 2]);
assert_equal(['network' => true], $permResponse['permissions'] ?? null, 'permissions approval passes through the pending permissions payload');
assert_equal('session', $permResponse['scope'] ?? null, 'permissions approval acceptForSession maps to scope=session');
assert_equal(null, CodexPromptProtocol::prompt_response($pendingPermissions, ['option' => 3]), 'permissions approval rejects a decline decision (only accept/acceptForSession are valid results for this method)');

echo "Codex prompt protocol tests passed.\n";
test_exit();
