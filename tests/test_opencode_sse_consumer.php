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

$parser = new OpenCodeSseFrameParser();
$wire = "data: {\"type\":\"question.asked\",\"properties\":{\"id\":\"que-one\",\"sessionID\":\"ses-one\",\"questions\":[{\"question\":\"Pick one\",\"header\":\"Choice\",\"options\":[{\"label\":\"A\"}]}]}}\n\n";
assert_equal([], $parser->push(substr($wire, 0, 27)), 'chunked SSE waits for its complete frame');
$events = $parser->push(substr($wire, 27));
assert_equal(1, count($events), 'chunked SSE emits one complete event');
assert_equal('question.asked', $events[0]['type'] ?? null, 'parser preserves event payload');
assert_equal([], $parser->push("data: {bad json}\n\n"), 'malformed frame is ignored without poisoning following events');
$bounded = new OpenCodeSseFrameParser(24);
assert_equal([], $bounded->push("data: 1234567890123456789012345\n"), 'oversized unterminated frame is discarded');

$v2 = new OpenCodeSseFrameParser();
$v2Events = $v2->push("data: {\"location\":{\"directory\":\"/work/b\"},\"type\":\"question.v2.asked\",\"data\":{\"id\":\"que-two\",\"sessionID\":\"ses-two\",\"questions\":[{\"question\":\"Continue?\"}]}}\n\n");
$normalized = OpenCodeSseFrameParser::normalize($v2Events[0]);
assert_equal('question.asked', $normalized['type'] ?? null, 'v2 question type normalizes to the lifecycle type');
assert_equal('/work/b', $normalized['directory'] ?? null, 'v2 location directory normalizes');

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
assert_equal('que-one', $blocked['request_id'] ?? null, 'event prompt stores request identity');
assert_equal('/work/a', $blocked['directory'] ?? null, 'event prompt records subscription directory');
assert_true(!OpenCodeSseConsumer::handle($events[0], '/work/b', $subscriptions['/work/b']), 'wrong-directory session event is ignored');
assert_equal('que-one', SessionStatusStore::read_status('ses-one')['blocked']['request_id'] ?? null, 'wrong-directory event cannot overwrite tracked prompt');

$newer = $events[0];
$newer['properties']['id'] = 'que-new';
assert_true(OpenCodeSseConsumer::handle($newer, '/work/a', $subscriptions['/work/a']), 'new question supersedes old request');
$oldReply = ['type' => 'question.replied', 'properties' => ['id' => 'que-one', 'sessionID' => 'ses-one']];
assert_true(!OpenCodeSseConsumer::handle($oldReply, '/work/a', $subscriptions['/work/a']), 'late completion cannot clear newer request');
assert_equal('que-new', SessionStatusStore::read_status('ses-one')['blocked']['request_id'] ?? null, 'new request remains after stale completion');
$newReply = ['type' => 'question.rejected', 'properties' => ['requestID' => 'que-new', 'sessionID' => 'ses-one']];
assert_true(OpenCodeSseConsumer::handle($newReply, '/work/a', $subscriptions['/work/a']), 'matching completion clears the event prompt');
assert_equal(null, SessionStatusStore::read_status('ses-one')['blocked'] ?? null, 'matching completion clears only matching prompt');

@unlink($db);
echo "OpenCode SSE consumer tests passed.\n";
test_exit();
