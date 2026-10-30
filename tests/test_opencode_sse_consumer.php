<?php
declare(strict_types=1);

/** Fixture-only coverage for OpenCode's directory-scoped question SSE feed. */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/vendor/autoload.php';

use HostAgent\Services\OpenCodeSseConsumer;
use HostAgent\Services\OpenCodeSseFrameParser;
use HostAgent\Stores\SessionStatusStore;

$db = sys_get_temp_dir() . '/sessioneer-test-opencode-sse-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv('SESSIONS_SQLITE_FILE=' . $db);

/** A v2 form.created envelope carrying the `question` tool's form. */
$formEvent = static function (string $formId, string $sessionId, string $directory, array $overrides = []): array {
    $form = array_replace_recursive([
        'id' => $formId,
        'sessionID' => $sessionId,
        'title' => 'Questions',
        'metadata' => ['kind' => 'question'],
        'fields' => [[
            'key' => 'q0',
            'title' => 'Choice',
            'description' => 'Pick one',
            'type' => 'string',
            'options' => [['value' => 'a', 'label' => 'A', 'description' => '']],
            'custom' => true,
        ]],
    ], $overrides);
    return [
        'id' => 'evt_' . $formId,
        'created' => 1,
        'type' => 'form.created',
        'location' => ['directory' => $directory],
        'data' => ['form' => $form],
    ];
};

$parser = new OpenCodeSseFrameParser();
$wire = 'data: ' . json_encode($formEvent('frm-one', 'ses-one', '/work/a')) . "\n\n";
assert_equal([], $parser->push(substr($wire, 0, 27)), 'chunked SSE waits for its complete frame');
$events = $parser->push(substr($wire, 27));
assert_equal(1, count($events), 'chunked SSE emits one complete event');
assert_equal('form.created', $events[0]['type'] ?? null, 'parser preserves the raw v2 envelope');
assert_equal([], $parser->push("data: {bad json}\n\n"), 'malformed frame is ignored without poisoning following events');
$bounded = new OpenCodeSseFrameParser(24);
assert_equal([], $bounded->push("data: 1234567890123456789012345\n"), 'oversized unterminated frame is discarded');

$normalized = OpenCodeSseFrameParser::normalize($events[0]);
assert_equal('question.asked', $normalized['type'] ?? null, 'form.created normalizes to the question lifecycle type');
assert_equal('/work/a', $normalized['directory'] ?? null, 'form location directory normalizes');
assert_equal('frm-one', $normalized['properties']['id'] ?? null, 'form id becomes the request identity');
assert_equal('Pick one', $normalized['properties']['questions'][0]['question'] ?? null, 'field description becomes the question text');
assert_equal('Choice', $normalized['properties']['questions'][0]['header'] ?? null, 'field title becomes the question header');
assert_equal('A', $normalized['properties']['questions'][0]['options'][0]['label'] ?? null, 'field options carry their labels');

assert_equal(null, OpenCodeSseFrameParser::normalize($formEvent('frm-perm', 'ses-one', '/work/a', ['metadata' => ['kind' => 'permission']])), 'non-question forms are not questions');
assert_equal(null, OpenCodeSseFrameParser::normalize(['type' => 'session.created', 'location' => ['directory' => '/work/a'], 'data' => ['sessionID' => 'ses-one']]), 'unrelated events are ignored');
assert_equal(null, OpenCodeSseFrameParser::normalize(['type' => 'question.asked', 'properties' => ['id' => 'que-legacy', 'sessionID' => 'ses-one']]), 'v1 question envelopes are no longer understood');

$subscriptions = OpenCodeSseConsumer::subscriptions([
    ['session_name' => 'ses-one', 'agent_session_id' => 'ses-one', 'agent' => 'opencode'],
    ['session_name' => 'ses-two', 'agent_session_id' => 'ses-two', 'agent' => 'opencode'],
    ['session_name' => 'codex', 'agent_session_id' => 'codex', 'agent' => 'codex'],
], [
    ['id' => 'ses-one', 'location' => ['directory' => '/work/a']],
    ['id' => 'ses-two', 'location' => ['directory' => '/work/b']],
]);
assert_equal(['ses-one' => 'ses-one'], $subscriptions['/work/a'] ?? null, 'canonical v2 directory creates its own subscription');
assert_equal(['ses-two' => 'ses-two'], $subscriptions['/work/b'] ?? null, 'separate directory remains isolated');

assert_true(OpenCodeSseConsumer::handle($events[0], '/work/a', $subscriptions['/work/a']), 'tracked event writes a prompt');
$blocked = SessionStatusStore::read_status('ses-one')['blocked'] ?? null;
assert_equal('opencode_sse', $blocked['source'] ?? null, 'event prompt is marked with its source');
assert_equal('frm-one', $blocked['request_id'] ?? null, 'event prompt stores request identity');
assert_equal('/work/a', $blocked['directory'] ?? null, 'event prompt records subscription directory');
assert_true(!OpenCodeSseConsumer::handle($events[0], '/work/b', $subscriptions['/work/b']), 'wrong-directory session event is ignored');
assert_equal('frm-one', SessionStatusStore::read_status('ses-one')['blocked']['request_id'] ?? null, 'wrong-directory event cannot overwrite tracked prompt');
assert_true(!OpenCodeSseConsumer::handle($formEvent('frm-perm', 'ses-one', '/work/a', ['metadata' => ['kind' => 'permission']]), '/work/a', $subscriptions['/work/a']), 'a non-question form never blocks the session');

assert_true(OpenCodeSseConsumer::handle($formEvent('frm-new', 'ses-one', '/work/a'), '/work/a', $subscriptions['/work/a']), 'new question supersedes old request');
$oldReply = ['type' => 'form.replied', 'location' => ['directory' => '/work/a'], 'data' => ['id' => 'frm-one', 'sessionID' => 'ses-one', 'answer' => ['q0' => 'a']]];
assert_true(!OpenCodeSseConsumer::handle($oldReply, '/work/a', $subscriptions['/work/a']), 'late completion cannot clear newer request');
assert_equal('frm-new', SessionStatusStore::read_status('ses-one')['blocked']['request_id'] ?? null, 'new request remains after stale completion');
$newReply = ['type' => 'form.cancelled', 'location' => ['directory' => '/work/a'], 'data' => ['id' => 'frm-new', 'sessionID' => 'ses-one', 'message' => 'Cancelled']];
assert_true(OpenCodeSseConsumer::handle($newReply, '/work/a', $subscriptions['/work/a']), 'matching completion clears the event prompt');
assert_equal(null, SessionStatusStore::read_status('ses-one')['blocked'] ?? null, 'matching completion clears only matching prompt');

@unlink($db);
echo "OpenCode SSE consumer tests passed.\n";
test_exit();
