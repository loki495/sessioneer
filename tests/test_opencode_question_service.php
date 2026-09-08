<?php
declare(strict_types=1);

/**
 * Exercises HostAgent\Services\OpenCodeQuestionService - the serve-API
 * question client (GET /question for detection, POST /question/{id}/reply for
 * answering) - against a throwaway `php -S` stub, so the real `opencode serve`
 * process is never contacted and no live tmux pane is needed.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\OpenCodeQuestionService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Runtimes\HeadlessRuntime;

$stub = __DIR__ . '/fixtures/opencode_question_stub.php';
$stateFile = sys_get_temp_dir() . '/sessioneer-test-ocq-state-' . bin2hex(random_bytes(4));
$statusDb = sys_get_temp_dir() . '/sessioneer-test-ocq-status-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv("SESSIONS_SQLITE_FILE={$statusDb}");
putenv("SESSIONEER_STUB_STATE={$stateFile}");
file_put_contents($stateFile, json_encode(['replies' => [], 'v2' => [], 'mode' => 'v2_success']));

// Pick a free port, start `php -S`, and point OPENCODE_SERVE_URL at it.
$port = 0;
$sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$sock) {
    fwrite(STDERR, "could not reserve a port: {$errstr}\n");
    exit(1);
}
$addr = stream_socket_get_name($sock, false);
fclose($sock);
$port = (int)substr($addr, strrpos($addr, ':') + 1);

$serverProc = proc_open(
    ['php', '-S', "127.0.0.1:{$port}", $stub],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes
);

if (!is_resource($serverProc)) {
    fwrite(STDERR, "failed to start php -S stub\n");
    exit(1);
}

putenv("OPENCODE_SERVE_URL=http://127.0.0.1:{$port}");

try {
    // Give the stub a moment to bind.
    $up = false;
    for ($i = 0; $i < 20 && !$up; $i++) {
        usleep(100000);
        $r = @file_get_contents("http://127.0.0.1:{$port}/question");
        $up = is_string($r);
    }
    assert_true($up, 'stub server: reached on the reserved port');

    // --- pending_question: finds a live question for the matching session ---
    $pending = OpenCodeQuestionService::pending_question('ses_stub123');
    assert_true($pending !== null, 'pending_question: returns a live question for the matching session');
    assert_equal('que_stubrequest', $pending['requestID'] ?? null, 'pending_question: captures the requestID');
    assert_equal('Which approach?', ($pending['questions'][0]['question'] ?? null), 'pending_question: carries the question text');

    // A different session has no pending question.
    assert_equal(null, OpenCodeQuestionService::pending_question('ses_other'), 'pending_question: null for a session with no live question');

    // --- to_prompt: canonical {question, context, options, multi_question} ---
    $prompt = OpenCodeQuestionService::to_prompt($pending);
    assert_equal('Which approach?', $prompt['question'] ?? null, 'to_prompt: question text');
    assert_equal('Approach', $prompt['context'] ?? null, 'to_prompt: header becomes context');
    assert_equal('question', $prompt['tool_name'] ?? null, 'to_prompt: tool_name is question');
    $labels = array_column($prompt['options'], 'label', 'number');
    assert_equal('Alpha', $labels[1] ?? null, 'to_prompt: option 1 is Alpha');
    assert_equal('Beta', $labels[2] ?? null, 'to_prompt: option 2 is Beta');

    // --- answer: v2 accepts its documented no-content success ---
    $answer = OpenCodeQuestionService::answer('ses_stub123', ['Alpha']);
    assert_equal(true, $answer['ok'] ?? null, 'answer: v2 204 is successful');

    $state = json_decode((string)file_get_contents($stateFile), true);
    $v2Reply = end($state['v2']) ?: [];
    assert_equal('que_stubrequest', $v2Reply['requestID'] ?? null, 'answer: v2 POSTed to the correct requestID');
    assert_equal([['Alpha']], $v2Reply['answers'] ?? null, 'answer: sent the chosen label as answers[[label]]');

    // Invalid labels and answer shape are rejected before an HTTP reply.
    assert_equal(null, OpenCodeQuestionService::validated_answers($pending['questions'], [['Alpha', 'Beta']]), 'validation: single select refuses multiple labels');
    assert_equal(null, OpenCodeQuestionService::validated_answers($pending['questions'], [['Not an option']]), 'validation: custom=false refuses an unknown label');
    assert_equal([['Alpha']], OpenCodeQuestionService::validated_answers($pending['questions'], ['Alpha']), 'validation: accepts the canonical single answer shape');
    $multiAndCustom = [
        ['options' => [['label' => 'One'], ['label' => 'Two']], 'multiple' => true, 'custom' => false],
        ['options' => [['label' => 'Known']], 'multiple' => false],
    ];
    assert_equal([['One', 'Two'], ['A custom answer']], OpenCodeQuestionService::validated_answers($multiAndCustom, [['One', 'Two'], ['A custom answer']]), 'validation: preserves multi-select labels and the default custom text');
    assert_equal(null, OpenCodeQuestionService::validated_answers($multiAndCustom, [['One'], ['Known', 'extra']]), 'validation: custom single-select rejects multiple values');

    // An SSE prompt remains answerable even when GET /question does not see
    // its instance. The v2 404 uses only the exact-directory v1 fallback.
    SessionStatusStore::update_status('ses_sse', ['status' => 'blocked', 'blocked' => [
        'source' => 'opencode_sse',
        'directory' => '/tmp/exact directory',
        'request_id' => 'que_stubrequest',
        'tool_name' => 'question',
        'question' => 'Which approach?',
        'options' => [['number' => 1, 'label' => 'Alpha']],
        'tool_input' => ['questions' => $pending['questions']],
    ]]);
    file_put_contents($stateFile, json_encode(['replies' => [], 'v2' => [], 'mode' => 'v2_not_found']));
    $runtimeAnswer = (new HeadlessRuntime())->answer_prompt('ses_sse', ['option' => 1]);
    assert_equal(true, $runtimeAnswer['ok'] ?? null, 'runtime: stored SSE prompt passes the pending guard and v1 fallback succeeds');
    $state = json_decode((string)file_get_contents($stateFile), true);
    $legacyReply = end($state['replies']) ?: [];
    assert_equal(rawurlencode('/tmp/exact directory'), $legacyReply['directory'] ?? null, 'v1 fallback: sends the exact stored directory header');
    assert_equal(null, SessionStatusStore::read_status('ses_sse')['blocked'] ?? null, 'success: atomically clears only the answered SSE prompt');

    // The shared browser form submits indexes and {text}, while OpenCode
    // accepts labels. The runtime translates against the fresh stored shape.
    $uiQuestions = [
        ['options' => [['label' => 'One'], ['label' => 'Two']], 'multiple' => true, 'custom' => false],
        ['options' => [['label' => 'Known']], 'multiple' => false],
    ];
    SessionStatusStore::update_status('ses_ui', ['status' => 'blocked', 'blocked' => [
        'source' => 'opencode_sse', 'directory' => '/tmp/exact directory', 'request_id' => 'que_stubrequest',
        'tool_name' => 'question', 'question' => 'First', 'options' => [], 'tool_input' => ['questions' => $uiQuestions],
    ]]);
    file_put_contents($stateFile, json_encode(['replies' => [], 'v2' => [], 'mode' => 'v2_success']));
    $uiAnswer = (new HeadlessRuntime())->answer_prompt('ses_ui', ['answers' => [[1, 2], ['text' => 'A custom answer']]]);
    assert_equal(true, $uiAnswer['ok'] ?? null, 'runtime: converts browser indexes and custom text to OpenCode labels');
    $state = json_decode((string)file_get_contents($stateFile), true);
    $uiReply = end($state['v2']) ?: [];
    assert_equal([['One', 'Two'], ['A custom answer']], $uiReply['answers'] ?? null, 'runtime: sends normalized label arrays');

    // A 500 is not proof that v2 is unsupported, so it must never replay on v1.
    SessionStatusStore::update_status('ses_sse', ['status' => 'blocked', 'blocked' => [
        'source' => 'opencode_sse', 'directory' => '/tmp/exact directory', 'request_id' => 'que_stubrequest',
        'tool_name' => 'question', 'question' => 'Which approach?', 'options' => [['number' => 1, 'label' => 'Alpha']],
        'tool_input' => ['questions' => $pending['questions']],
    ]]);
    file_put_contents($stateFile, json_encode(['replies' => [], 'v2' => [], 'mode' => 'v2_server_error']));
    $failed = (new HeadlessRuntime())->answer_prompt('ses_sse', ['option' => 1]);
    assert_equal(false, $failed['ok'] ?? null, 'v2 failure: rejects an ambiguous server error');
    $state = json_decode((string)file_get_contents($stateFile), true);
    assert_equal([], $state['replies'] ?? null, 'v2 failure: does not replay an answer through v1');
    assert_true(is_array(SessionStatusStore::read_status('ses_sse')['blocked'] ?? null), 'v2 failure: preserves the pending SSE prompt');

    // A 200 HTML/error body is not the documented v2 NoContent success.
    file_put_contents($stateFile, json_encode(['replies' => [], 'v2' => [], 'mode' => 'v2_html_200']));
    $htmlFailure = (new HeadlessRuntime())->answer_prompt('ses_sse', ['option' => 1]);
    assert_equal(false, $htmlFailure['ok'] ?? null, 'v2 response: rejects an unexpected 200 response body');
    assert_true(is_array(SessionStatusStore::read_status('ses_sse')['blocked'] ?? null), 'v2 response: does not clear a prompt on malformed success');

    // A scoped, structured legacy QuestionNotFound response means an SSE
    // event was missed; clear only that matching stale prompt.
    file_put_contents($stateFile, json_encode(['replies' => [], 'v2' => [], 'mode' => 'v1_not_found']));
    $staleServer = (new HeadlessRuntime())->answer_prompt('ses_sse', ['option' => 1]);
    assert_equal(false, $staleServer['ok'] ?? null, 'v1 not found: reports the already-resolved prompt');
    assert_equal(null, SessionStatusStore::read_status('ses_sse')['blocked'] ?? null, 'v1 not found: atomically clears only the matching stale SSE prompt');

    SessionStatusStore::update_status('ses_sse', ['status' => 'blocked', 'blocked' => [
        'source' => 'opencode_sse', 'directory' => '/tmp/exact directory', 'request_id' => 'que_stubrequest',
        'tool_name' => 'question', 'question' => 'Which approach?', 'options' => [['number' => 1, 'label' => 'Alpha']],
        'tool_input' => ['questions' => $pending['questions']],
    ]]);
    $stale = OpenCodeQuestionService::answer('ses_sse', ['Alpha'], 'que_replaced');
    assert_equal(false, $stale['ok'] ?? null, 'stale request: rejects an answer whose request id no longer matches');

    // Answer with no live question -> rejected.
    $noQ = OpenCodeQuestionService::answer('ses_other', ['Alpha']);
    assert_equal(false, $noQ['ok'] ?? null, 'answer: rejects when there is no live question for the session');
} finally {
    proc_terminate($serverProc);
    proc_close($serverProc);
    @unlink($stateFile);
    @unlink($statusDb);
}

test_exit();
