<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/lib/assert.php';

use App\Services\QuickPromptService as WebQuickPrompt;
use HostAgent\Services\QuickPromptService as HostQuickPrompt;
use HostAgent\Runtimes\ClaudeHeadlessManagerClient;
use HostAgent\Runtimes\ClaudeHeadlessRuntime;
use HostAgent\Runtimes\CodexHeadlessRuntime;
use HostAgent\Runtimes\RuntimeProvider;
use HostAgent\Stores\QuickPromptStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SessionStatusStore;

$root = sys_get_temp_dir() . '/sessioneer-test-quick-prompt-' . getmypid();
mkdir($root . '/project', 0700, true);
// Unconditional isolation, including direct invocation from a managed session.
putenv('SIDECAR_DIR=' . $root . '/sidecars');
putenv('SESSIONS_SQLITE_FILE=' . $root . '/sessions.sqlite');
putenv('QUICK_PROMPT_STATE_DIR=' . $root . '/web');
putenv('QUICK_PROMPT_PROJECTS=' . json_encode(['dibs' => $root . '/project']));
putenv('QUICK_PROMPT_DEFAULT_PROJECT=dibs');
putenv('QUICK_PROMPT_DEFAULT_AGENT=claude');
putenv('QUICK_PROMPT_DEFAULT_MODEL=');
putenv('QUICK_PROMPT_DEFAULT_PROFILE=');
putenv('QUICK_PROMPT_AGENTS=claude,codex');
putenv('QUICK_PROMPT_MODELS={"claude":["sonnet"],"codex":["configured-model"]}');
putenv('QUICK_PROMPT_BASE_URL=https://sessioneer.example');
putenv('QUICK_PROMPT_API_TOKEN=' . str_repeat('x', 64));
putenv('QUICK_PROMPT_WAIT_SECONDS=6');
putenv('QUICK_PROMPT_MAX_ANSWER_BYTES=2000');
putenv('SESSIONEER_DEBUG=0');
register_shutdown_function(static function () use ($root): void { exec('rm -rf ' . escapeshellarg($root)); });

$server = ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer ' . str_repeat('x', 64)];
assert_equal(null, WebQuickPrompt::guard($server), 'valid token accepted');
assert_equal('unauthorized', WebQuickPrompt::guard(array_replace($server, ['HTTP_AUTHORIZATION' => 'Bearer wrong']))['code'], 'incorrect token rejected');
$missing = $server; unset($missing['HTTP_AUTHORIZATION']);
assert_equal(401, WebQuickPrompt::guard($missing)['_http'], 'missing token is 401');
putenv('QUICK_PROMPT_API_TOKEN=');
assert_equal('not_configured', WebQuickPrompt::guard($server)['code'], 'authentication fails closed');
putenv('QUICK_PROMPT_API_TOKEN=' . str_repeat('x', 64));
assert_equal(403, WebQuickPrompt::guard($server + ['HTTP_ORIGIN' => 'https://evil.example'])['_http'], 'browser Origin rejected');
assert_equal(413, WebQuickPrompt::guard($server + ['CONTENT_LENGTH' => 100000])['_http'], 'body capped before reading');
assert_equal(415, WebQuickPrompt::guard(array_replace($server, ['CONTENT_TYPE' => 'text/plain']))['_http'], 'non-JSON rejected');
assert_equal(405, WebQuickPrompt::guard(array_replace($server, ['REQUEST_METHOD' => 'GET']))['_http'], 'wrong method rejected');
putenv('QUICK_PROMPT_RATE_LIMIT=2');
assert_equal(null, WebQuickPrompt::rate_limit(), 'first request allowed');
assert_equal(null, WebQuickPrompt::rate_limit(), 'second request allowed');
assert_equal(429, WebQuickPrompt::rate_limit()['_http'], 'shared token rate limited');

class QuickPromptFakeRuntime extends ClaudeHeadlessRuntime
{
    public int $creates = 0;
    public int $kills = 0;
    public array $options = [];
    public array $entry = [];
    public bool $failCreate = false;
    public ?Closure $duringCreate = null;
    public function __construct() {}
    public function create(array $options): array
    {
        $this->creates++; $this->options = $options;
        if ($this->duringCreate !== null) ($this->duringCreate)();
        if ($this->failCreate) return ['ok' => false, 'message' => '/secret/path token=SECRET'];
        return ['ok' => true, 'id' => 'claude-headless-' . $this->creates];
    }
    public function detail(string $sessionRef): array { return ['ok' => true, 'session' => $this->entry]; }
    public function kill(string $sessionRef): array { $this->kills++; return ['ok' => true]; }
}
class QuickPromptFakeCodex extends CodexHeadlessRuntime
{
    public array $options = [];
    public array $thread = [];
    public ?array $pending = null;
    public array $sent = [];
    public function __construct() {}
    public function create(array $options): array { $this->options = $options; return ['ok' => true, 'id' => 'codex-test-thread']; }
    public function send_message(string $sessionRef, string $text, array $attachmentPaths = []): array { $this->sent[] = $text; return ['ok' => true, 'turn_id' => 'turn-quick']; }
    public function detail(string $sessionRef): array { return ['ok' => true, 'session' => $this->thread]; }
    public function pending_prompt(string $sessionRef): ?array { return $this->pending; }
}
class QuickPromptFakeManager extends ClaudeHeadlessManagerClient
{
    public array $status = ['ok' => true, 'process' => 'running', 'state' => 'working'];
    public array $sent = [];
    public bool $failSend = false;
    public function __construct() {}
    public function request(string $method, array $params = []): array
    {
        if ($method === 'sessioneer/sendInput') {
            $this->sent[] = $params;
            return $this->failSend ? ['ok' => false, 'message' => 'SECRET /private/path'] : ['ok' => true];
        }
        return $this->status;
    }
}
$runtime = new QuickPromptFakeRuntime(); $codex = new QuickPromptFakeCodex(); $manager = new QuickPromptFakeManager();
$host = new HostQuickPrompt(static fn(string $agent): RuntimeProvider => $agent === 'claude' ? $runtime : $codex, $manager);
$number = 0;
$start = static function (array $input, ?string $key = null) use ($host, &$number): array {
    return $host->start(['input' => $input, 'key' => $key ?? hash('sha256', (string)++$number)]);
};
foreach ([
    [[], 'invalid_prompt'], [['prompt' => '  '], 'invalid_prompt'], [['prompt' => str_repeat('a', 16385)], 'invalid_prompt'],
    [['prompt' => "bad\xff"], 'invalid_prompt'], [['prompt' => 'x', 'agent' => 'shell'], 'invalid_agent'],
    [['prompt' => 'x', 'project' => '/etc'], 'invalid_project'], [['prompt' => 'x', 'project' => '../dibs'], 'invalid_project'],
    [['prompt' => 'x', 'mode' => 'auto'], 'invalid_mode'], [['prompt' => 'x', 'model' => '; rm -rf /'], 'invalid_model'],
    [['prompt' => 'x', 'profile' => 'unknown'], 'invalid_profile'], [['prompt' => 'x', 'agent' => 'codex', 'profile' => 'work'], 'invalid_profile'],
    [['prompt' => 'x', 'workdir' => '/etc'], 'invalid_request'], [['prompt' => 'x', 'session_id' => '../outside'], 'invalid_session'],
    [['prompt' => 'x', 'session_id' => 'unknown'], 'invalid_session'],
] as [$input, $code]) assert_equal($code, $start($input)['code'] ?? null, 'host rejects ' . $code . ': ' . json_encode($input));
assert_equal(0, $runtime->creates, 'invalid requests never create a session');
$key = hash('sha256', 'retry');
$created = $start(['prompt' => '  hello  ', 'model' => 'sonnet'], $key);
assert_equal('session', $created['type'], 'new session launched');
assert_equal('plan', $runtime->options['starting_mode'], 'Claude starts in plan mode');
assert_equal(true, $runtime->options['unique_name'], 'distinct submissions request collision-safe session references');
assert_equal($root . '/project', $runtime->options['workdir'], 'project resolves only from configuration');
assert_equal('hello', $manager->sent[0]['content'], 'prompt submitted via existing manager protocol');
assert_equal($created, $start(['prompt' => '  hello  ', 'model' => 'sonnet'], $key), 'retry returns same reference');
assert_equal(1, $runtime->creates, 'retry never creates duplicate session');
assert_equal(1, count($manager->sent), 'retry never delivers duplicate message');
assert_equal('idempotency_conflict', $start(['prompt' => 'different'], $key)['code'], 'same key with different body conflicts');
$parallelKey = hash('sha256', 'parallel-request');
$parallel = null;
$runtime->duringCreate = static function () use ($start, $parallelKey, &$parallel): void {
    $parallel = $start(['prompt' => 'parallel'], $parallelKey);
};
$start(['prompt' => 'parallel'], $parallelKey);
$runtime->duringCreate = null;
assert_equal('dispatch_pending', $parallel['code'], 'retry during dispatch returns specific pending outcome');
assert_equal(409, $parallel['_http'], 'pending reservation is a recoverable conflict');
assert_equal(null, QuickPromptStore::reserve(hash('sha256', 'pending'), 'fingerprint'), 'reserve pending request');
assert_equal(null, QuickPromptStore::reserve(hash('sha256', 'pending'), 'fingerprint')['response'], 'pending reservation stays unresolved');

$id = $created['session_id'];
$status = static fn(): array => $host->status(['session_id' => $id, 'request_id' => $key]);
assert_equal('running', $status()['status'], 'working session remains running');
$manager->status = ['ok' => true, 'state' => 'blocked', 'last_result' => ['text' => 'partial']];
assert_equal('blocked', $status()['status'], 'approval request stays blocked even with output');
$manager->status = ['ok' => true, 'state' => 'idle'];
assert_equal('unknown', $status()['status'], 'idle without terminal event is never an answer');
$manager->status['last_result'] = ['request_id' => $key, 'failed' => false, 'text' => 'Done', 'has_tools' => false];
assert_equal(true, $status()['completed'], 'terminal result explicitly marks completion');
$manager->status['last_result']['request_id'] = 'other-turn';
assert_equal('unknown', $status()['status'], 'unrelated terminal result cannot answer earlier request');
$manager->status['last_result']['request_id'] = $key;
$manager->status['last_result']['failed'] = true;
assert_equal('agent_failed', $status()['code'], 'terminal agent failure is handled');
$manager->status = ['ok' => false, 'message' => '/internal/path SECRET'];
assert_equal('unknown', $status()['status'], 'manager loss preserves usable session');
$manager->failSend = true;
$failedKey = hash('sha256', 'failed-delivery');
$failed = $start(['prompt' => 'next'], $failedKey);
$sentBeforeRetry = count($manager->sent);
assert_equal($failed, $start(['prompt' => 'next'], $failedKey), 'delivery failure retry preserves same session and error');
assert_equal($sentBeforeRetry, count($manager->sent), 'ambiguous delivery is never automatically repeated');
assert_equal('delivery_failed', $failed['code'], 'delivery failure handled');
assert_true(isset($failed['session_id']), 'created reference preserved on delivery failure');
assert_true(!str_contains(json_encode($failed), 'SECRET'), 'runtime errors sanitized');
$runtime->failCreate = true;
assert_equal('agent_unavailable', $start(['prompt' => 'new'])['code'], 'unavailable runtime handled');
$runtime->failCreate = false; $manager->failSend = false;
$manager->status = ['ok' => true, 'process' => 'running', 'state' => 'idle'];
SidecarStore::write_sidecar($id, ['workdir' => $root . '/project', 'profile' => null, 'agent' => 'claude', 'runtime' => 'headless']);
SessionStatusStore::update_status($id, ['mode' => 'plan', 'status' => 'idle', 'model' => 'sonnet']);
$continued = $start(['prompt' => 'continue', 'session_id' => $id, 'model' => 'sonnet']);
assert_equal($id, $continued['session_id'], 'compatible Claude continuation reuses explicit session');
SessionStatusStore::update_status($id, ['mode' => 'auto']);
assert_equal('invalid_session', $start(['prompt' => 'continue', 'session_id' => $id, 'model' => 'sonnet'])['code'], 'continuation never escalates permissions');
SessionStatusStore::update_status($id, ['mode' => 'plan']);
SidecarStore::write_sidecar($id, ['workdir' => '/etc', 'profile' => null, 'agent' => 'claude', 'runtime' => 'headless']);
assert_equal('invalid_session', $start(['prompt' => 'continue', 'session_id' => $id, 'model' => 'sonnet'])['code'], 'different-project continuation rejected');

$codexCreated = $start(['prompt' => 'codex hello', 'agent' => 'codex', 'model' => 'configured-model']);
assert_equal('session', $codexCreated['type'], 'Codex uses runtime infrastructure');
assert_equal(true, $codex->options['read_only'], 'Codex read-only requested');
assert_equal('codex hello', $codex->sent[0], 'Codex prompt delivered');
$codexId = $codexCreated['session_id'];
$codex->thread = ['turns' => [['id' => 'turn-quick', 'status' => 'inProgress', 'items' => [['type' => 'agentMessage', 'text' => 'partial']]]]];
assert_equal('running', $host->status(['session_id' => $codexId, 'turn_id' => 'turn-quick'])['status'], 'Codex partial output is not completion');
$codex->thread['turns'][0]['status'] = 'completed';
assert_equal('unknown', $host->status(['session_id' => $codexId, 'turn_id' => 'unrelated-turn'])['status'], 'unrelated Codex turn never supplies an answer');
assert_equal('unknown', $host->status(['session_id' => $codexId])['status'], 'uncorrelated Codex submission remains a session link');
assert_equal('partial', $host->status(['session_id' => $codexId, 'turn_id' => 'turn-quick'])['text'], 'Codex terminal turn can provide answer');
$codex->thread['turns'][0]['items'][] = ['type' => 'commandExecution', 'aggregatedOutput' => 'large output'];
assert_equal(true, $host->status(['session_id' => $codexId, 'turn_id' => 'turn-quick'])['has_tools'], 'Codex tools require dashboard');
$codex->pending = ['request_id' => 'approval'];
assert_equal('blocked', $host->status(['session_id' => $codexId, 'turn_id' => 'turn-quick'])['status'], 'Codex approval never auto-approved');
assert_equal('invalid_session', $start(['prompt' => 'next', 'agent' => 'codex', 'session_id' => $codexId, 'model' => 'configured-model'])['code'], 'unverifiable Codex continuation refused');

$clock = 0.0; $calls = [];
$probe = ['type' => 'session', 'status' => 'completed', 'completed' => true, 'text' => 'A short answer', 'session_id' => 'safe-id'];
$call = static function (array $request, float $seconds) use (&$calls, &$probe): array {
    $calls[] = $request;
    return $request['action'] === 'quick_prompt_start' ? ['type' => 'session', 'status' => 'running', 'session_id' => 'safe-id'] : $probe;
};
$web = new WebQuickPrompt($call, static function () use (&$clock): float { return $clock; }, static function (float $seconds) use (&$clock): void { $clock += $seconds; });
$submit = static fn(array $input): array => $web->submit(json_encode($input), $server + ['HTTP_HOST' => 'evil.example', 'HTTP_X_FORWARDED_HOST' => 'evil.example']);
foreach ([[], ['prompt' => ''], ['prompt' => '   '], ['prompt' => "\0text"], ['prompt' => str_repeat('a', 16385)]] as $input) assert_equal(422, $submit($input)['_http'], 'invalid prompt rejected in web tier');
assert_equal(400, $web->submit('{', $server)['_http'], 'malformed JSON handled');
assert_equal(400, $web->submit('{"prompt":"' . "\xff" . '"}', $server)['_http'], 'malformed UTF-8 JSON handled');
assert_equal('answer', $submit(['prompt' => 'hello'])['type'], 'immediate terminal short answer returned');
assert_equal('https://sessioneer.example/session.php?session=safe-id', $submit(['prompt' => 'hello'])['session_url'], 'URL ignores Host and forwarded headers');
$probe['text'] = str_repeat('a', 2001);
assert_equal('session', $submit(['prompt' => 'hello'])['type'], 'long result opens session');
$probe['text'] = 'short'; $probe['has_tools'] = true;
assert_equal('session', $submit(['prompt' => 'hello'])['type'], 'tool output opens session');
$probe = ['type' => 'session', 'status' => 'blocked', 'session_id' => 'safe-id'];
assert_equal('blocked', $submit(['prompt' => 'hello'])['status'], 'human interaction opens blocked session');
$probe = ['type' => 'session', 'status' => 'running', 'session_id' => 'safe-id', 'text' => 'partial'];
$clock = 0; $before = count($calls);
assert_equal('session', $submit(['prompt' => 'hello'])['type'], 'timeout returns running session');
assert_equal(6.0, $clock, 'wait bounded to configured deadline');
assert_equal(7, count($calls) - $before, 'one-second polling, no unnecessary subsecond requests');
assert_equal(0, $runtime->kills, 'HTTP wait never terminates a process');
foreach ($calls as $request) assert_true(!in_array($request['action'], ['kill', 'launch_kill', 'send_escape'], true), 'web never issues termination or approval');
$clock = 0;
$submit(['prompt' => "@sessioneer agent=codex model=configured-model project=dibs\nReview tests"]);
$dispatch = $calls[count($calls) - 7];
assert_equal('codex', $dispatch['input']['agent'], 'deterministic first-line agent hint parsed');
assert_equal('Review tests', $dispatch['input']['prompt'], 'routing hint removed from agent prompt');
assert_equal('invalid_hint', $submit(['prompt' => "@sessioneer agent=codex\nx", 'agent' => 'claude'])['code'], 'conflicting hints rejected');
putenv('QUICK_PROMPT_BASE_URL=https://user:password@sessioneer.example');
assert_equal('not_configured', $submit(['prompt' => 'x'])['code'], 'credential-bearing URL rejected');
putenv('QUICK_PROMPT_BASE_URL=http://sessioneer.example');
assert_equal('not_configured', $submit(['prompt' => 'x'])['code'], 'HTTPS required in configured public URL');
$unavailable = new WebQuickPrompt(static fn(array $request, float $seconds): array => ['ok' => false, 'message' => 'SECRET']);
putenv('QUICK_PROMPT_BASE_URL=https://sessioneer.example');
assert_equal('agent_unavailable', $unavailable->submit('{"prompt":"x"}', $server)['code'], 'unavailable host is sanitized recoverable error');

test_exit();
