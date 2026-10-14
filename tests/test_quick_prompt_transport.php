<?php

declare(strict_types=1);

/** Real HTTP -> host socket -> existing manager, always scripted fake Claude. */
require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';
require __DIR__ . '/lib/harness.php';
require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/free_port.php';
require __DIR__ . '/lib/claude_headless.php';

use App\AgentClient;
use HostAgent\Runtimes\ClaudeHeadlessManagerClient;

$root = sys_get_temp_dir() . '/sessioneer-test-quick-transport-' . getmypid();
mkdir($root . '/home/.claude/projects', 0700, true);
mkdir($root . '/project', 0700, true);
putenv('SIDECAR_DIR=' . $root . '/sidecars');
putenv('SESSIONS_SQLITE_FILE=' . $root . '/sessions.sqlite');
putenv('HOME_ROOT=' . $root . '/home');
putenv('PUSH_SQLITE_FILE=' . $root . '/push.sqlite');
putenv('QUICK_PROMPT_STATE_DIR=' . $root . '/rate');
putenv('QUICK_PROMPT_PROJECTS=' . json_encode(['project' => $root . '/project']));
putenv('QUICK_PROMPT_DEFAULT_PROJECT=project');
putenv('QUICK_PROMPT_AGENTS=claude');
putenv('QUICK_PROMPT_DEFAULT_AGENT=claude');
putenv('QUICK_PROMPT_DEFAULT_PROFILE=');
putenv('QUICK_PROMPT_DEFAULT_MODEL=');
putenv('QUICK_PROMPT_API_TOKEN=' . str_repeat('fixture-token-', 5));
putenv('QUICK_PROMPT_BASE_URL=https://trusted.example');
putenv('QUICK_PROMPT_WAIT_SECONDS=1');
putenv('QUICK_PROMPT_RATE_LIMIT=60');
putenv('SESSIONEER_ALLOWED_HOSTS=');
putenv('CLAUDE_BIN=' . __DIR__ . '/fixtures/fake_claude_stream');
putenv('CODEX_BRIDGE_SOCKET=' . $root . '/absent-codex.sock');
putenv('OPENCODE_SERVE_URL=http://127.0.0.1:1');
putenv('OPENCODE_DB_PATH=' . $root . '/absent-opencode.db');
$managers = []; $madeSessions = []; $agent = null; $server = null;
register_shutdown_function(static function () use (&$agent, &$server, &$managers, $root): void {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (is_resource($agent)) stop_harness($agent, $root . '/agent.sock');
    foreach ($managers as $manager) {
        if (is_resource($manager['proc'])) { proc_terminate($manager['proc']); proc_close($manager['proc']); }
    }
    exec('rm -rf ' . escapeshellarg($root));
});
$manager = start_manager($root, 'manager');
putenv('CLAUDE_HEADLESS_SOCKET=' . $manager['sock']);
$agent = start_harness([PHP_BINARY, dirname(__DIR__) . '/host-agent/agent.php'], $root . '/agent.sock');
putenv('SESSIONEER_AGENT_SOCKET=' . $root . '/agent.sock');
$port = test_free_port();
$base = 'http://127.0.0.1:' . $port;
$server = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__) . '/public', dirname(__DIR__) . '/public/index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $root . '/http.log', 'a'], 2 => ['file', $root . '/http.log', 'a']], $pipes, null, getenv());
fclose($pipes[0]);
assert_true((bool)wait_until(static function () use ($port): bool {
    $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
    if ($socket === false) return false;
    fclose($socket); return true;
}), 'isolated HTTP server ready');
$auth = ['-H', 'Authorization: Bearer ' . str_repeat('fixture-token-', 5), '-H', 'Content-Type: application/json', '--max-time', '20'];
$request = static function (array $input, string $key) use ($base, $auth): array {
    return curl_request('POST', $base . '/api/quick-prompt', array_merge($auth, ['-H', 'Idempotency-Key: ' . $key, '--data-binary', json_encode($input)]));
};
$unauth = curl_request('POST', $base . '/api/quick-prompt', ['-H', 'Content-Type: application/json', '--data-binary', '{"prompt":"secret"}']);
assert_equal(401, $unauth['status'], 'HTTP missing authentication rejected');
assert_equal('unauthorized', json_decode($unauth['body'], true)['code'], 'HTTP rejection has predictable JSON');
assert_equal([], call($manager, 'sessioneer/list')['children'], 'unauthorized HTTP request never creates an agent');
$wrong = curl_request('POST', $base . '/api/quick-prompt', ['-H', 'Authorization: Bearer wrong', '-H', 'Content-Type: application/json', '--data-binary', '{"prompt":"secret"}']);
assert_equal(401, $wrong['status'], 'HTTP incorrect authentication rejected');
$malformed = curl_request('POST', $base . '/api/quick-prompt', array_merge($auth, ['--data-binary', '{']));
assert_equal(400, $malformed['status'], 'HTTP malformed JSON rejected safely');
$badProject = $request(['prompt' => 'hello', 'project' => '/etc'], 'bad-project');
assert_equal(422, $badProject['status'], 'host validation failure crosses HTTP as 422');
assert_equal('invalid_project', json_decode($badProject['body'], true)['code'], 'host validation body sanitized');
assert_equal([], call($manager, 'sessioneer/list')['children'], 'invalid HTTP requests never create an agent');
$answer = $request(['prompt' => 'hello quick prompt'], 'answer-key');
$body = json_decode($answer['body'], true);
assert_equal(200, $answer['status'], 'real HTTP authenticated session dispatch succeeds');
assert_equal('answer', $body['type'], 'terminal fake-Claude result returns immediate answer');
assert_equal(call($manager, 'sessioneer/status', ['session' => $body['session_id']])['last_result']['text'], $body['text'] ?? null, 'answer equals authoritative terminal result');
assert_contains('echo: hello quick prompt [spawn=fresh', $body['text'], 'fake terminal answer belongs to fresh requested prompt');
assert_contains('mode=auto', $body['text'], 'the real manager applies auto mode after spawn and the fake agent confirms it');
assert_equal('https://trusted.example/session.php?session=' . $body['session_id'], $body['session_url'], 'trusted configured HTTPS session URL');
assert_true(!array_key_exists('_http', $body) && !array_key_exists('request_id', $body) && !array_key_exists('completed', $body), 'internal transport fields omitted');
$again = $request(['prompt' => 'hello quick prompt'], 'answer-key');
assert_equal($body['session_id'], json_decode($again['body'], true)['session_id'], 'HTTP retry returns same session');
assert_equal(1, count(call($manager, 'sessioneer/list')['children']), 'HTTP retry creates only one process');
assert_equal(false, fake_log($manager)[0]['env']['QUICK_PROMPT_TOKEN_PRESENT'], 'API token is never passed into the agent environment');
$slow = $request(['prompt' => 'SLOW_THEN_QUEUE wait beyond HTTP'], 'slow-key');
$slowBody = json_decode($slow['body'], true);
assert_equal('session', $slowBody['type'], 'HTTP deadline returns session while fake agent is working');
assert_equal('running', $slowBody['status'], 'HTTP timeout reports running');
$running = call($manager, 'sessioneer/status', ['session' => $slowBody['session_id']]);
assert_equal('running', $running['process'], 'managed process remains alive after HTTP timeout');
assert_equal('working', $running['state'], 'managed turn continues after HTTP timeout');
assert_true((bool)wait_until(static fn(): bool => status_is($slowBody['session_id'], 'idle')), 'turn later completes without any HTTP client attached');
$terminal = call($manager, 'sessioneer/status', ['session' => $slowBody['session_id']]);
assert_equal('merged: SLOW_THEN_QUEUE wait beyond HTTP (no queued message arrived)', $terminal['last_result']['text'] ?? null, 'later terminal answer preserved');
$blocked = $request(['prompt' => 'PERMISSION please'], 'blocked-key');
assert_equal('blocked', json_decode($blocked['body'], true)['status'], 'HTTP approval requirement returns blocked session');
$failed = $request(['prompt' => 'AUTHFAIL'], 'failed-key');
assert_equal(502, $failed['status'], 'agent error maps to handled HTTP 502');
assert_equal('agent_failed', json_decode($failed['body'], true)['code'], 'agent failure body predictable');
$origin = curl_request('POST', $base . '/api/quick-prompt', array_merge($auth, ['-H', 'Origin: https://evil.example', '--data-binary', '{"prompt":"hello"}']));
assert_equal(403, $origin['status'], 'HTTP Origin submissions denied');

$badHost = curl_request('POST', $base . '/api/quick-prompt', array_merge($auth, ['-H', 'Host: evil.example', '--data-binary', '{"prompt":"hello"}']));
assert_equal(421, $badHost['status'], 'existing Host validation still enforced');
assert_equal('host_not_allowed', json_decode($badHost['body'], true)['code'], 'Host rejection keeps JSON error contract');
$wrongVerb = curl_request('DELETE', $base . '/api/quick-prompt', $auth);
assert_equal(405, $wrongVerb['status'], 'all unsupported HTTP verbs rejected');
assert_equal('method_not_allowed', json_decode($wrongVerb['body'], true)['code'], 'unsupported verb keeps JSON error contract');

// A stalled host socket cannot keep the HTTP waiting path alive forever.
$stallScript = $root . '/stall.php';
file_put_contents($stallScript, '<?php stream_get_contents(STDIN); usleep(500000); echo "{}";');
$stall = start_harness([PHP_BINARY, $stallScript], $root . '/stall.sock');
try {
    putenv('SESSIONEER_AGENT_SOCKET=' . $root . '/stall.sock');
    $started = microtime(true);
    assert_equal(['ok' => false], AgentClient::agent_call_bounded(['action' => 'probe'], 0.05), 'bounded client handles stalled host');
    assert_true(microtime(true) - $started < 0.3, 'socket deadline actually bounds elapsed wait');
} finally { stop_harness($stall, $root . '/stall.sock'); }

test_exit();
