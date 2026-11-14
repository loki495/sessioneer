<?php

declare(strict_types=1);

/**
 * The Shortcuts API (ShortcutsService + routes): auth, listing/ordering, prompt shaping, answering,
 * messages, stale prompts and rate limiting. The host agent is a scripted fake that records every
 * action it receives, so the assertions also prove each write reuses the dashboard's own action.
 */
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';

use App\Services\ShortcutsService as Api;

$root = sys_get_temp_dir() . '/sessioneer-test-shortcuts-' . getmypid();
mkdir($root, 0700, true);
putenv('SHORTCUTS_STATE_DIR=' . $root);
putenv('SHORTCUTS_RATE_LIMIT=60');
register_shutdown_function(static function () use ($root): void { exec('rm -rf ' . escapeshellarg($root)); });

$token = str_repeat('s', 64);
putenv('SHORTCUTS_API_TOKEN=' . $token);

// ---- authentication -------------------------------------------------------------------------
$get = ['REQUEST_METHOD' => 'GET', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
$post = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json; charset=utf-8', 'HTTP_AUTHORIZATION' => 'Bearer ' . $token];
assert_equal(null, Api::guard($get, 'GET'), 'valid token accepted on GET');
assert_equal(null, Api::guard($post, 'POST'), 'valid token accepted on POST');
$none = $get; unset($none['HTTP_AUTHORIZATION']);
assert_equal(401, Api::guard($none, 'GET')['_http'], 'missing token is 401');
assert_equal(401, Api::guard(array_replace($get, ['HTTP_AUTHORIZATION' => 'Bearer wrong']), 'GET')['_http'], 'wrong or revoked token is 401');
assert_equal(401, Api::guard(array_replace($get, ['HTTP_AUTHORIZATION' => 'Basic ' . $token]), 'GET')['_http'], 'non-bearer scheme is 401');
putenv('SHORTCUTS_API_TOKEN=' . str_repeat('t', 64));
assert_equal(401, Api::guard($get, 'GET')['_http'], 'the old token stops working after rotation');
putenv('SHORTCUTS_API_TOKEN=');
assert_equal('not_configured', Api::guard($get, 'GET')['code'], 'an empty token disables the API (fails closed)');
putenv('SHORTCUTS_API_TOKEN=short');
assert_equal(503, Api::guard(array_replace($get, ['HTTP_AUTHORIZATION' => 'Bearer short']), 'GET')['_http'], 'a token under 32 chars is refused');
putenv('SHORTCUTS_API_TOKEN=' . $token);
assert_equal(405, Api::guard($post, 'GET')['_http'], 'wrong verb is 405');
assert_equal(403, Api::guard($get + ['HTTP_ORIGIN' => 'https://evil.example'], 'GET')['_http'], 'browser Origin rejected');
assert_equal(415, Api::guard(array_replace($post, ['CONTENT_TYPE' => 'text/plain']), 'POST')['_http'], 'non-JSON POST is 415');
assert_equal(413, Api::guard($post + ['CONTENT_LENGTH' => 99999], 'POST')['_http'], 'oversized body is 413');
assert_true(!str_contains(json_encode(Api::guard($none, 'GET')), $token), 'error body never echoes the token');

// ---- routes ---------------------------------------------------------------------------------
$path = '/front-controller-path';
$router = require __DIR__ . '/../src/routes.php';
assert_equal('/front-controller-path', $path, 'routes.php leaves the including front controller\'s $path untouched');
foreach (['sessions', 'session', 'messages', 'answers'] as $path) {
    assert_true($router->match('GET', '/api/shortcuts/v1/' . $path) !== null && $router->match('POST', '/api/shortcuts/v1/' . $path) !== null, "route registered: {$path}");
}

// ---- fake host agent ------------------------------------------------------------------------
$calls = [];
$details = [];
$reply = ['ok' => true, 'message' => 'done'];
$history = ['ok' => true, 'entries' => [
    ['role' => 'user', 'blocks' => [['kind' => 'text', 'text' => 'first']]],
    ['role' => 'assistant', 'blocks' => [['kind' => 'tool_use', 'text' => 'tool: x']]],
    ['role' => 'assistant', 'blocks' => [['kind' => 'text', 'text' => "second\n\nline"]]],
    ['role' => 'user', 'blocks' => [['kind' => 'tool_result', 'text' => '{}']]],
    ['role' => 'user', 'blocks' => [['kind' => 'text', 'text' => 'third']]],
    ['role' => 'assistant', 'blocks' => [['kind' => 'text', 'text' => str_repeat('z', 500)]]],
]];
$agent = static function (array $request) use (&$calls, &$details, &$reply, &$history): array {
    $calls[] = $request;
    switch ($request['action']) {
        case 'list':
            return ['ok' => true, 'sessions' => [
                ['name' => 'cc-idle', 'title' => 'Idle one', 'activity' => time() - 1500, 'working' => false, 'blocked_reason' => null, 'prompt_options' => []],
                ['name' => 'cc-block', 'title' => 'Blocked one', 'activity' => 100, 'working' => false, 'blocked_reason' => 'Run tests?', 'prompt_options' => [['number' => 1, 'label' => 'Yes']]],
                ['name' => 'cc-work', 'title' => 'Working one', 'activity' => 900, 'working' => true, 'blocked_reason' => null, 'prompt_options' => []],
                ['name' => 'cc-dupe', 'title' => 'Idle one', 'activity' => time() - 2400, 'working' => false, 'blocked_reason' => null, 'prompt_options' => []],
                ['name' => '../evil', 'title' => 'Bad id', 'activity' => 1],
            ]];
        case 'session_detail':
            return $details[$request['session']] ?? ['ok' => false, 'message' => 'Session not found: /home/secret/path'];
        case 'session_history':
            return $history;
        default:
            return $reply;
    }
};
$api = new Api($agent);

$single = ['ok' => true, 'title' => 'Blocked one', 'working' => false, 'blocked_reason' => 'Run tests?', 'prompt_request_id' => 'req-1', 'prompt_options' => [
    ['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No'], ['number' => 3, 'label' => 'Type something.'],
], 'prompt_questions' => null];
$multi = ['ok' => true, 'title' => 'Asking', 'working' => false, 'blocked_reason' => 'Pick', 'prompt_request_id' => null, 'prompt_options' => [], 'prompt_questions' => [
    ['question' => 'Which DB?', 'options' => [['label' => 'MySQL'], ['label' => 'SQLite']]],
    ['question' => 'Which extras?', 'multiSelect' => true, 'options' => [['label' => 'Redis'], ['label' => 'Queue'], ['label' => 'Cache']]],
]];
$permission = ['ok' => true, 'title' => 'Perm', 'working' => false, 'blocked_reason' => 'Allow Bash?', 'prompt_options' => [['number' => 1, 'label' => 'Yes'], ['number' => 2, 'label' => 'No']], 'prompt_questions' => null];
$idle = ['ok' => true, 'activity' => time() - 7200, 'title' => 'Idle one', 'working' => false, 'blocked_reason' => null, 'prompt_options' => [], 'prompt_questions' => null];
$details = ['cc-block' => $single, 'cc-multi' => $multi, 'cc-perm' => $permission, 'cc-idle' => $idle];

// ---- listing --------------------------------------------------------------------------------
$list = $api->sessions();
assert_equal(['1. Blocked one — NEEDS REPLY', '2. Working one — WORKING', '3. Idle one — IDLE 25m', '4. Idle one — IDLE 40m'], $list['menu'], 'idle sessions show how long they have been idle; others show no age');
assert_equal(['cc-block', 'cc-work', 'cc-idle', 'cc-dupe'], $list['ids'], 'attention first, then working, then idle by recency; invalid ids dropped');
assert_equal(4, $list['count'], 'count matches');
assert_equal('1. Blocked one — NEEDS REPLY', $list['menu'][0], 'menu label is numbered and shows status');
assert_equal($list['menu'], array_column($list['sessions'], 'display_name'), 'menu mirrors display_name');
assert_equal(count($list['menu']), count(array_unique($list['menu'])), 'labels stay unique even when two sessions share a title');
assert_equal(['id' => 'cc-block', 'display_name' => '1. Blocked one — NEEDS REPLY', 'status' => 'needs_reply', 'can_message' => false, 'pending_prompt_count' => 1], $list['sessions'][0], 'blocked session entry');
assert_equal(true, $list['sessions'][2]['can_message'], 'an idle session accepts messages');
$down = (new Api(static fn(array $r): array => []))->sessions();
assert_equal(503, $down['_http'], 'unreachable host agent is 503');

// ---- detail ---------------------------------------------------------------------------------
$d = $api->session('cc-block');
assert_equal('needs_reply', $d['status'], 'detail status');
assert_equal('single', $d['prompt_kind'], 'plain prompt is single');
assert_equal(1, $d['question_count'], 'one question');
assert_equal(['1. Yes', '2. No', '0. Type my own answer'], $d['questions'][0]['choices'], 'choices carry their option number, then the typed-answer entry');
assert_equal(['1', '2', '0'], $d['questions'][0]['choice_ids'], 'choice ids parallel the labels');
assert_equal(true, $d['questions'][0]['allows_text'], 'free-text option detected (its own label is replaced by the typed-answer entry)');
assert_equal('one', $d['questions'][0]['select_mode'], 'single-select mode');
assert_equal('Run tests?', $d['questions'][0]['question'], 'question text');
assert_equal(false, $d['can_message'], 'no messaging while a prompt waits');
assert_equal(false, $api->session('cc-perm')['questions'][0]['allows_text'], 'a permission prompt has no custom text');
$m = $api->session('cc-multi');
assert_equal('multi', $m['prompt_kind'], 'multi-question prompt');
assert_equal(2, $m['question_count'], 'two questions');
assert_equal(['many', 'one'], [$m['questions'][1]['select_mode'], $m['questions'][0]['select_mode']], 'select_mode tells Shortcuts whether to allow several picks');
assert_equal(true, $m['questions'][1]['multi_select'], 'multi-select flagged');
assert_equal(false, $m['questions'][1]['allows_text'], 'multi-select has no free text');
$i = $api->session('cc-idle');
assert_equal('Idle one — IDLE 2h', $i['display_name'], 'detail display name carries the idle age');
assert_equal(['none', '', 0, true], [$i['prompt_kind'], $i['prompt_id'], $i['question_count'], $i['can_message']], 'idle detail has no prompt and allows messages');
$recent = $i['recent_text'];
assert_equal(['AGENT: second line', 3], [explode("\n\n", $recent)[0], count(explode("\n\n", $recent))], 'recent_text keeps the last 3 text messages, skipping tool entries');
assert_true(str_contains($recent, 'AGENT: ' . str_repeat('z', 349) . '…'), 'long messages are truncated');
assert_equal('', $api->session('cc-block')['recent_text'], 'no recent text while a prompt is pending');
$history = ['ok' => false];
assert_equal('', $api->session('cc-idle')['recent_text'], 'history failure gives an empty string, not an error');
$history = ['ok' => true, 'entries' => [['role' => 'user', 'blocks' => [['kind' => 'text', 'text' => 'first']]], ['role' => 'assistant', 'blocks' => [['kind' => 'tool_use', 'text' => 'tool: x']]], ['role' => 'assistant', 'blocks' => [['kind' => 'text', 'text' => 'second']]], ['role' => 'user', 'blocks' => [['kind' => 'text', 'text' => 'third']]]]];
assert_equal(404, $api->session('cc-gone')['_http'], 'unknown session is 404');
assert_true(!str_contains(json_encode($api->session('cc-gone')), 'secret'), '404 never leaks agent text');
assert_equal(422, $api->session('../etc')['_http'], 'malformed id is 422');
assert_equal(422, $api->session('')['_http'], 'missing id is 422');
assert_equal($d['prompt_id'], $api->session('cc-block')['prompt_id'], 'prompt id is stable across reads');

// ---- answering: single ----------------------------------------------------------------------
$pid = $d['prompt_id'];
$calls = [];
$r = $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'choice' => '2']));
assert_equal(['ok' => true, 'message' => 'Answer sent'], $r, 'predefined answer succeeds');
$sent = end($calls);
assert_equal(['answer_prompt', 'cc-block', 2, 'No', 'req-1'], [$sent['action'], $sent['session'], $sent['option'], $sent['expected_label'], $sent['expected_request_id']], 'uses the dashboard answer_prompt with label and request id guards');

$calls = [];
assert_equal(true, $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'text' => '  use sqlite  ']))['ok'], 'custom text succeeds');
$sent = end($calls);
assert_equal(['answer_prompt_with_text', 3, 'use sqlite'], [$sent['action'], $sent['option'], $sent['text']], 'uses answer_prompt_with_text on the free-text option');

assert_equal(422, $api->answer(json_encode(['session' => 'cc-perm', 'prompt_id' => $api->session('cc-perm')['prompt_id'], 'text' => 'hi']))['_http'], 'text refused when the prompt has no text option');
assert_equal('invalid_choice', $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'choice' => '9']))['code'], 'unoffered choice rejected');
assert_equal('invalid_choice', $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'choice' => '3']))['code'], 'the free-text option is not a plain choice');
assert_equal('invalid_choice', $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'choice' => '0']))['code'], 'the typed-answer entry is not a plain choice');
assert_equal('invalid_answer', $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'choice' => '1', 'text' => 'x']))['code'], 'choice and text together rejected');
assert_equal('invalid_answer', $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid]))['code'], 'no answer rejected');
assert_equal(422, $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'text' => '   ']))['_http'], 'blank text rejected');
assert_equal(422, $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'text' => str_repeat('a', 9000)]))['_http'], 'oversized text rejected');
assert_equal(422, $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => 'zzz', 'choice' => '1']))['_http'], 'malformed prompt id rejected');
assert_equal(422, $api->answer(json_encode(['session' => '../x', 'prompt_id' => $pid, 'choice' => '1']))['_http'], 'malformed session id rejected');
assert_equal(400, $api->answer('{nope')['_http'], 'malformed JSON is 400');
assert_equal(400, $api->answer('[1,2]')['_http'], 'a JSON list is not an object');
assert_equal(404, $api->answer(json_encode(['session' => 'cc-gone', 'prompt_id' => $pid, 'choice' => '1']))['_http'], 'answer to unknown session is 404');
$calls = [];
$api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'choice' => '9']));
assert_equal([], array_filter($calls, static fn(array $c): bool => str_starts_with($c['action'], 'answer')), 'a rejected answer never reaches the host agent');

// ---- stale / already answered / duplicate ---------------------------------------------------
$details['cc-block'] = $idle;
$stale = $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'choice' => '1']));
assert_equal([409, 'no_pending_prompt'], [$stale['_http'], $stale['code']], 'answering after the prompt is gone is 409 (duplicate submission)');
$details['cc-block'] = array_replace($single, ['prompt_request_id' => 'req-2']);
$changed = $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'choice' => '1']));
assert_equal([409, 'prompt_changed'], [$changed['_http'], $changed['code']], 'a different prompt is 409');
$details['cc-block'] = $single;
$reply = ['ok' => false, 'message' => 'Rejected: the prompt changed since it was read'];
$race = $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'choice' => '1']));
assert_equal([409, 'prompt_changed'], [$race['_http'], $race['code']], 'a host-agent stale refusal maps to 409');
$reply = ['ok' => false, 'message' => 'tmux failed: /home/secret/x'];
$fail = $api->answer(json_encode(['session' => 'cc-block', 'prompt_id' => $pid, 'choice' => '1']));
assert_equal(502, $fail['_http'], 'other agent failure is 502');
assert_true(!str_contains(json_encode($fail), 'secret'), 'agent failure text is not leaked');
$reply = ['ok' => true, 'message' => 'done'];

// ---- answering: multi-question --------------------------------------------------------------
$mp = $api->session('cc-multi')['prompt_id'];
$calls = [];
assert_equal(true, $api->answer(json_encode(['session' => 'cc-multi', 'prompt_id' => $mp, 'answers' => ['2', '1,3']]))['ok'], 'multi-question answer succeeds');
$sent = end($calls);
assert_equal(['answer_multi_question', [2, [1, 3]]], [$sent['action'], $sent['answers']], 'uses answer_multi_question: number, then list for multi-select');
$calls = [];
$api->answer(json_encode(['session' => 'cc-multi', 'prompt_id' => $mp, 'answers' => ['text:Postgres', '2']]));
assert_equal([['text' => 'Postgres'], [2]], end($calls)['answers'], 'free text allowed on a single-select question');
assert_equal(422, $api->answer(json_encode(['session' => 'cc-multi', 'prompt_id' => $mp, 'answers' => ['1']]))['_http'], 'wrong answer count rejected');
assert_equal(422, $api->answer(json_encode(['session' => 'cc-multi', 'prompt_id' => $mp, 'answers' => ['1,2', '1']]))['_http'], 'two picks on a single-select question rejected');
assert_equal(422, $api->answer(json_encode(['session' => 'cc-multi', 'prompt_id' => $mp, 'answers' => ['1', 'text:no']]))['_http'], 'free text on a multi-select question rejected');
assert_equal(422, $api->answer(json_encode(['session' => 'cc-multi', 'prompt_id' => $mp, 'answers' => ['4', '1']]))['_http'], 'out-of-range option rejected');
assert_equal(422, $api->answer(json_encode(['session' => 'cc-multi', 'prompt_id' => $mp, 'answers' => ['0', '1']]))['_http'], 'the typed-answer entry is not an option number');
assert_equal(422, $api->answer(json_encode(['session' => 'cc-multi', 'prompt_id' => $mp, 'choice' => '1']))['_http'], 'multi prompt needs answers, not choice');

// A one-question form prompt is `single` and takes choice/text.
$details['cc-form1'] = ['ok' => true, 'title' => 'Form', 'working' => false, 'blocked_reason' => 'Pick', 'prompt_options' => [], 'prompt_questions' => [['question' => 'Rotate?', 'options' => [['label' => 'Yes'], ['label' => 'No']]]]];
$f = $api->session('cc-form1');
assert_equal(['single', 1], [$f['prompt_kind'], $f['question_count']], 'one-question form prompt is reported as single');
$calls = [];
assert_equal(true, $api->answer(json_encode(['session' => 'cc-form1', 'prompt_id' => $f['prompt_id'], 'choice' => '2']))['ok'], 'choice accepted on a one-question form prompt');
assert_equal(['answer_multi_question', [2]], [end($calls)['action'], end($calls)['answers']], 'it still uses answer_multi_question');
$calls = [];
$api->answer(json_encode(['session' => 'cc-form1', 'prompt_id' => $f['prompt_id'], 'text' => 'maybe']));
assert_equal([['text' => 'maybe']], end($calls)['answers'], 'text accepted on a one-question form prompt');
assert_equal(422, $api->answer(json_encode(['session' => 'cc-form1', 'prompt_id' => $f['prompt_id'], 'choice' => '7']))['_http'], 'bad choice rejected');
assert_equal(422, $api->answer(json_encode(['session' => 'cc-form1', 'prompt_id' => $f['prompt_id'], 'choice' => '0']))['_http'], 'typed-answer entry is not a choice here either');

// ---- messages -------------------------------------------------------------------------------
$calls = [];
assert_equal(['ok' => true, 'message' => 'Message sent'], $api->message(json_encode(['session' => 'cc-idle', 'text' => ' hello '])), 'message sent');
$sent = end($calls);
assert_equal(['send_message', 'cc-idle', 'hello', []], [$sent['action'], $sent['session'], $sent['text'], $sent['attachment_paths']], 'uses the dashboard send_message action');
$blocked = $api->message(json_encode(['session' => 'cc-perm', 'text' => 'hello']));
assert_equal([409, 'prompt_pending'], [$blocked['_http'], $blocked['code']], 'messages refused while a prompt is pending');
assert_equal(404, $api->message(json_encode(['session' => 'cc-gone', 'text' => 'hi']))['_http'], 'message to unknown session is 404');
assert_equal(422, $api->message(json_encode(['session' => 'cc-idle', 'text' => '']))['_http'], 'empty message rejected');
assert_equal(422, $api->message(json_encode(['session' => 'cc-idle']))['_http'], 'missing text rejected');
assert_equal(422, $api->message(json_encode(['session' => 'cc-idle', 'text' => ['a']]))['_http'], 'non-string text rejected');
assert_equal(422, $api->message(json_encode(['session' => 'cc-idle', 'text' => "a\0b"]))['_http'], 'NUL byte rejected');
assert_equal(422, $api->message(json_encode(['text' => 'hi']))['_http'], 'missing session rejected');
$reply = ['ok' => false, 'message' => 'boom /home/secret'];
$bad = $api->message(json_encode(['session' => 'cc-idle', 'text' => 'hi']));
assert_equal(502, $bad['_http'], 'delivery failure is 502');
assert_true(!str_contains(json_encode($bad), 'secret'), 'delivery failure text is not leaked');
$reply = ['ok' => true, 'message' => 'done'];

// ---- rate limit -----------------------------------------------------------------------------
putenv('SHORTCUTS_RATE_LIMIT=3');
$codes = [];
for ($n = 0; $n < 5; $n++) $codes[] = Api::rate_limit()['_http'] ?? 200;
assert_equal([200, 200, 200, 429, 429], $codes, 'per-minute limit enforced');
putenv('SHORTCUTS_RATE_LIMIT=60');

exit($GLOBALS['__sessioneer_test_failures'] > 0 ? 1 : 0);
