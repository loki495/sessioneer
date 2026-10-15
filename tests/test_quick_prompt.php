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
    public array $modes = [];
    public bool $failSetMode = false;
    public function set_mode(string $sessionRef, string $mode): array { $this->modes[] = $mode; return ['ok' => !$this->failSetMode]; }
}
class QuickPromptFakeCodex extends CodexHeadlessRuntime
{
    public array $options = [];
    public array $thread = [];
    public ?array $pending = null;
    public array $sent = [];
    public function __construct() {}
    public int $creates = 0;
    public function create(array $options): array { $this->options = $options; return ['ok' => true, 'id' => 'codex-test-thread' . (++$this->creates > 1 ? '-' . $this->creates : '')]; }
    public function send_message(string $sessionRef, string $text, array $attachmentPaths = []): array { $this->sent[] = $text; return ['ok' => true, 'turn_id' => 'turn-quick']; }
    public function detail(string $sessionRef): array { return ['ok' => true, 'session' => $this->thread]; }
    public function pending_prompt(string $sessionRef): ?array { return $this->pending; }
    public int $kills = 0;
    public function kill(string $sessionRef): array { $this->kills++; return ['ok' => true]; }
    public function status(string $sessionRef): array { return ['ok' => true, 'status' => $this->pending !== null ? 'blocked' : 'idle']; }
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
    [['prompt' => 'x', 'mode' => 'bypassPermissions'], 'invalid_mode'], [['prompt' => 'x', 'agent' => 'codex', 'mode' => 'plan'], 'invalid_mode'], [['prompt' => 'x', 'model' => '; rm -rf /'], 'invalid_model'],
    [['prompt' => 'x', 'profile' => 'unknown'], 'invalid_profile'], [['prompt' => 'x', 'agent' => 'codex', 'profile' => 'work'], 'invalid_profile'],
    [['prompt' => 'x', 'workdir' => '/etc'], 'invalid_request'], [['prompt' => 'x', 'session_id' => '../outside'], 'invalid_session'],
    [['prompt' => 'x', 'session_id' => 'unknown'], 'invalid_session'],
] as [$input, $code]) assert_equal($code, $start($input)['code'] ?? null, 'host rejects ' . $code . ': ' . json_encode($input));
assert_equal(0, $runtime->creates, 'invalid requests never create a session');
$key = hash('sha256', 'retry');
$created = $start(['prompt' => '  hello  ', 'model' => 'sonnet'], $key);
assert_equal('session', $created['type'], 'new session launched');
assert_equal(null, $runtime->options['starting_mode'], 'Claude is not spawned with the flag that is silently ignored for auto');
assert_equal('auto', $runtime->modes[array_key_last($runtime->modes)], 'auto mode is applied after spawn, like the dashboard does it');
assert_equal(true, $created['created'], 'a new session is marked as created by this request');
assert_equal(null, $created['intent'], 'no router, no intent');
$start(['prompt' => 'planning', 'mode' => 'plan']);
assert_equal('plan', $runtime->options['starting_mode'], 'plan mode stays selectable');
$modesBefore = count($runtime->modes);
assert_equal($modesBefore, count($runtime->modes), 'plan mode needs no later mode change');
putenv('QUICK_PROMPT_DEFAULT_MODE=plan');
$start(['prompt' => 'configured default']);
assert_equal('plan', $runtime->options['starting_mode'], 'the default mode is configurable');
putenv('QUICK_PROMPT_DEFAULT_MODE=bogus');
$start(['prompt' => 'bad configured default']);
assert_equal('plan', $runtime->options['starting_mode'], 'an invalid configured default falls back to the first allowed mode');
putenv('QUICK_PROMPT_DEFAULT_MODE=');
$killsBeforeModeFailure = $runtime->kills;
$runtime->failSetMode = true;
$modeFailed = $start(['prompt' => 'mode cannot be applied']);
$runtime->failSetMode = false;
assert_equal('agent_unavailable', $modeFailed['code'], 'a session whose auto mode cannot be applied is an error');
assert_true(!isset($modeFailed['session_id']) && $runtime->kills === $killsBeforeModeFailure + 1, 'and is stopped, not handed out');
assert_true(!in_array('mode cannot be applied', array_column($manager->sent, 'content'), true), 'and never received the prompt');
$firstCreates = $runtime->creates; $firstSent = count($manager->sent);
assert_equal(true, $runtime->options['unique_name'], 'distinct submissions request collision-safe session references');
assert_equal($root . '/project', $runtime->options['workdir'], 'project resolves only from configuration');
assert_equal('hello', $manager->sent[0]['content'], 'prompt submitted via existing manager protocol');
assert_equal($created, $start(['prompt' => '  hello  ', 'model' => 'sonnet'], $key), 'retry returns same reference');
assert_equal($firstCreates, $runtime->creates, 'retry never creates duplicate session');
assert_equal($firstSent, count($manager->sent), 'retry never delivers duplicate message');
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
$killsBeforeAgentFailure = $runtime->kills;
$agentFailed = $status();
assert_equal('agent_failed', $agentFailed['code'], 'terminal agent failure is handled');
assert_true(!isset($agentFailed['session_id']), 'a failed session is not handed out');
assert_equal($killsBeforeAgentFailure + 1, $runtime->kills, 'and is stopped');
assert_equal('agent_failed', $start(['prompt' => '  hello  ', 'model' => 'sonnet'], $key)['code'], 'a retry of the failed request gets the stored failure, not a dead session link');
$manager->status = ['ok' => false, 'message' => '/internal/path SECRET'];
assert_equal('unknown', $status()['status'], 'manager loss preserves usable session');
$manager->failSend = true;
$killsBeforeFailure = $runtime->kills;
$failedKey = hash('sha256', 'failed-delivery');
$failed = $start(['prompt' => 'next'], $failedKey);
$sentBeforeRetry = count($manager->sent);
assert_equal($failed, $start(['prompt' => 'next'], $failedKey), 'delivery failure retry preserves same session and error');
assert_equal($sentBeforeRetry, count($manager->sent), 'ambiguous delivery is never automatically repeated');
assert_equal('delivery_failed', $failed['code'], 'delivery failure handled');
assert_true(!isset($failed['session_id']), 'a session that could not take its prompt is archived, not handed out');
assert_equal($killsBeforeFailure + 1, $runtime->kills, 'and was stopped');
assert_true(!str_contains(json_encode($failed), 'SECRET'), 'runtime errors sanitized');
$runtime->failCreate = true;
assert_equal('agent_unavailable', $start(['prompt' => 'new'])['code'], 'unavailable runtime handled');
$runtime->failCreate = false; $manager->failSend = false;
putenv('SESSIONEER_DEBUG=1');
$runtime->duringCreate = static function (): void { throw new RuntimeException('/secret/path token=SECRET'); };
$debugFailure = $start(['prompt' => 'debug failure']);
assert_equal('agent_unavailable', $debugFailure['code'], 'debug mode still returns sanitized host failure');
assert_true(!str_contains(json_encode($debugFailure), 'SECRET'), 'debug mode never leaks exception diagnostics');
$runtime->duringCreate = null;
putenv('SESSIONEER_DEBUG=0');
$manager->status = ['ok' => true, 'process' => 'running', 'state' => 'idle'];
SidecarStore::write_sidecar($id, ['workdir' => $root . '/project', 'profile' => null, 'agent' => 'claude', 'runtime' => 'headless']);
SessionStatusStore::update_status($id, ['mode' => 'auto', 'status' => 'idle', 'model' => 'sonnet']);
$continued = $start(['prompt' => 'continue', 'session_id' => $id, 'model' => 'sonnet']);
assert_equal($id, $continued['session_id'], 'compatible Claude continuation reuses explicit session');
SessionStatusStore::update_status($id, ['mode' => 'manual']);
assert_equal('invalid_session', $start(['prompt' => 'continue', 'session_id' => $id, 'model' => 'sonnet'])['code'], 'continuation never runs in a mode other than the one it started in');
SessionStatusStore::update_status($id, ['mode' => 'auto']);
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

echo "Error causes\n";
$causeKey = hash('sha256', 'cause');
$causeSession = $start(['prompt' => 'cause'], $causeKey);
$cause = static function (string $raw, string $agent = 'claude') use (&$manager, $host, $causeSession, $causeKey): array {
    $manager->status = ['ok' => true, 'state' => 'idle', 'last_turn_error' => $raw, 'last_result' => ['request_id' => $causeKey, 'failed' => true, 'text' => '', 'has_tools' => false]];
    return $host->status(['session_id' => $causeSession['session_id'], 'request_id' => $causeKey]);
};
foreach ([
    ["You've hit your usage limit. Resets at 5pm (America/New_York).", 'quota_exhausted', 'Usage limit reached'],
    ['API Error: 429 rate limit exceeded', 'quota_exhausted', 'Usage limit reached'],
    ['Not logged in · Please run /login', 'auth_failed', 'Authentication failed'],
    ['API Error: 529 overloaded_error', 'service_unavailable', 'The model service is unavailable'],
    ["Requested permission mode 'auto' was not applied (Claude reports 'default')", 'mode_not_applied', 'The permission mode could not be applied'],
    ['Claude process exited unexpectedly (code 1): boom', 'agent_crashed', 'The agent process crashed'],
    ['Cannot set permission mode to auto: auto mode unavailable for this model', 'mode_not_applied', 'The permission mode could not be applied'],
    ['something odd happened', 'agent_error', 'The agent could not complete the prompt'],
] as [$raw, $reason, $headline]) {
    $err = $cause($raw);
    assert_equal('agent_failed', $err['code'], 'failure code stays stable for: ' . $reason);
    assert_equal($reason, $err['reason'], 'cause classified as ' . $reason);
    assert_true(str_starts_with($err['message'], $headline . ': '), 'message leads with what happened: ' . $reason);
    assert_true(str_contains($err['detail'], explode(' ', $raw)[0] === '' ? $raw : substr($raw, 0, 8)), 'and carries the agent\'s own words: ' . $reason);
    SidecarStore::write_sidecar($causeSession['session_id'], ['workdir' => $root . '/project', 'profile' => null, 'agent' => 'claude', 'runtime' => 'headless']);
}
$leaky = $cause("Failed reading /home/andres/.claude/credentials.json with token=sk-ant-api03-ABCDEFGHIJKLMNOPQRSTUVWXYZ0123 for me@example.com");
assert_true(!preg_match('~andres|credentials|sk-ant|ABCDEFGH|example\.com~', json_encode($leaky)), 'paths, tokens and addresses never reach the response');
SidecarStore::write_sidecar($causeSession['session_id'], ['workdir' => $root . '/project', 'profile' => null, 'agent' => 'claude', 'runtime' => 'headless']);
assert_true(mb_strlen($cause(str_repeat('word ', 200))['detail']) <= 241, 'long agent output is bounded');
SidecarStore::write_sidecar($causeSession['session_id'], ['workdir' => $root . '/project', 'profile' => null, 'agent' => 'claude', 'runtime' => 'headless']);
$manager->failSend = true;
$unreachable = $start(['prompt' => 'unreachable']);
$manager->failSend = false;
assert_equal('delivery_failed', $unreachable['code'], 'delivery failure keeps its code');
$codex->pending = null;
$codex->thread = ['turns' => [['id' => 'turn-fail', 'status' => 'failed', 'error' => ['message' => 'out of credits', 'codexErrorInfo' => 'usageLimitExceeded'], 'items' => []]]];
$codexFailed = $start(['prompt' => 'codex quota', 'agent' => 'codex', 'model' => 'configured-model']);
$codexErr = $host->status(['session_id' => $codexFailed['session_id'], 'turn_id' => 'turn-fail', 'request_id' => hash('sha256', 'codex-quota')]);
assert_equal('quota_exhausted', $codexErr['reason'], 'a Codex quota failure is reported as quota');
assert_contains('out of credits', $codexErr['detail'], 'with Codex\'s own explanation');

echo "Router and disposal\n";
class QuickPromptFakeRouterPost
{
    public int $calls = 0;
    public ?array $reply = null;
    public ?array $body = null;
    public float $timeout = 0.0;
    public function __invoke(string $url, array $body, float $timeout): ?array { $this->calls++; $this->body = $body; $this->timeout = $timeout; return $this->reply; }
}
$ask = static fn(array $picked): array => ['message' => ['content' => json_encode($picked)]];
$post = new QuickPromptFakeRouterPost();
$routed = new HostQuickPrompt(static fn(string $agent): RuntimeProvider => $agent === 'claude' ? $runtime : $codex, $manager, new HostAgent\Services\QuickPromptRouter($post));
$routedStart = static function (array $input) use ($routed, &$number): array { return $routed->start(['input' => $input, 'key' => hash('sha256', 'routed' . ++$number)]); };
$none = ['agent' => null, 'profile' => null, 'model' => null, 'project' => null, 'intent' => 'session'];
$routedStart(['prompt' => 'no router configured']);
assert_equal(0, $post->calls, 'the router is off until QUICK_PROMPT_ROUTER_URL is set');
putenv('QUICK_PROMPT_ROUTER_URL=http://router.test');
$post->reply = $ask(['intent' => 'answer'] + $none);
$r = $routedStart(['prompt' => 'what is 2+2']);
assert_equal('answer', $r['intent'], 'the router says whether this is a one-off answer');
assert_equal(true, $r['created'], 'and the new session is marked created');
assert_equal('auto', $runtime->modes[array_key_last($runtime->modes)], 'defaults still apply for fields it leaves null');
assert_equal('what is 2+2', $post->body['messages'][1]['content'], 'the router sees the prompt as data in its own message');
assert_true(str_contains($post->body['messages'][0]['content'], '"dibs"'), 'and is told only the configured project names');
assert_true(!str_contains($post->body['messages'][0]['content'], $root), 'never a filesystem path');
$codexCreates = count($codex->sent);
$r = $routedStart(['prompt' => 'use codex to explain this'] );
assert_equal(count($codex->sent), $codexCreates, 'no change when the router picks nothing');
$post->reply = $ask(['agent' => 'codex', 'project' => 'dibs', 'intent' => 'session'] + $none);
$r = $routedStart(['prompt' => 'use codex to review the tests']);
assert_equal(count($codex->sent), $codexCreates + 1, 'the router can pick Codex from the prompt');
assert_equal(true, $codex->options['read_only'], 'which still starts read-only');
$claudeCreates = $runtime->creates;
$r = $routedStart(['prompt' => 'use codex to review the tests', 'agent' => 'claude']);
assert_equal($claudeCreates + 1, $runtime->creates, 'an explicit client field beats the router');
$post->reply = $ask(['agent' => 'codex', 'profile' => 'work', 'model' => 'sonnet', 'project' => 'dibs', 'intent' => 'session']);
$claudeCreates = $runtime->creates; $codexCreates = count($codex->sent);
$guarded = $routedStart(['prompt' => 'what is the capital of France']);
assert_equal($claudeCreates + 1, $runtime->creates, 'values the prompt never mentions are ignored, even when the model offers them');
assert_equal($codexCreates, count($codex->sent), 'so a prompt with no agent named never goes to Codex');
assert_equal('session', $guarded['intent'], 'while the intent is still taken from the router');
assert_equal(20.0, $post->timeout, 'the router gets a timeout long enough for a cold model load');
assert_equal('1h', $post->body['keep_alive'], 'and asks Ollama to keep the model loaded');
putenv('QUICK_PROMPT_ROUTER_TIMEOUT_SECONDS=99');
$routedStart(['prompt' => 'timeout is capped']);
assert_equal(60.0, $post->timeout, 'the timeout is capped');
putenv('QUICK_PROMPT_ROUTER_TIMEOUT_SECONDS=');
$post->reply = $ask(['agent' => 'shell', 'project' => '/etc', 'model' => '; rm -rf /'] + $none);
$r = $routedStart(['prompt' => 'hostile router output']);
assert_equal('session', $r['type'], 'values outside the allowlists are ignored, the defaults apply');
assert_equal($root . '/project', $runtime->options['workdir'], 'and the project stays the configured one');
$post->reply = $ask(['agent' => 'codex', 'model' => 'sonnet'] + $none);
$codexCreates = count($codex->sent);
$routedStart(['prompt' => 'codex with a Claude-only model']);
assert_equal($codexCreates + 1, count($codex->sent), 'a model the routed agent cannot use is dropped, not an error');
$post->reply = null;
assert_equal('session', $routedStart(['prompt' => 'router down'])['type'], 'an unreachable router never blocks a prompt');
$post->reply = ['message' => ['content' => 'not json']];
assert_equal('session', $routedStart(['prompt' => 'router garbage'])['type'], 'neither does garbage output');
putenv('QUICK_PROMPT_ROUTER_URL=');

$manager->status = ['ok' => true, 'process' => 'running', 'state' => 'idle', 'open_turns' => 0];
$answerKey = hash('sha256', 'dispose-me');
$answerSession = $start(['prompt' => 'dispose'], $answerKey);
$killsBefore = $runtime->kills;
$finalAnswer = ['type' => 'answer', 'text' => 'four', 'archived' => true];
$unknown = $host->dispose(['session_id' => 'never-created', 'request_id' => $answerKey, 'response' => $finalAnswer]);
assert_equal(false, $unknown['ok'], 'only sessions this feature created can be disposed');
assert_equal(false, $host->dispose(['session_id' => $answerSession['session_id'], 'request_id' => 'bad', 'response' => $finalAnswer])['ok'], 'a malformed request id is refused');
$manager->status['state'] = 'working';
assert_equal(false, $host->dispose(['session_id' => $answerSession['session_id'], 'request_id' => $answerKey, 'response' => $finalAnswer])['archived'], 'a working session is never stopped');
$manager->status['state'] = 'blocked';
assert_equal(false, $host->dispose(['session_id' => $answerSession['session_id'], 'request_id' => $answerKey, 'response' => $finalAnswer])['archived'], 'neither is one waiting on a prompt');
assert_equal($killsBefore, $runtime->kills, 'nothing was stopped');
$manager->status['state'] = 'idle';
putenv('QUICK_PROMPT_ARCHIVE_ANSWERS=0');
assert_equal(false, $host->dispose(['session_id' => $answerSession['session_id'], 'request_id' => $answerKey, 'response' => $finalAnswer])['archived'], 'archiving can be switched off');
putenv('QUICK_PROMPT_ARCHIVE_ANSWERS=1');
$disposed = $host->dispose(['session_id' => $answerSession['session_id'], 'request_id' => $answerKey, 'response' => $finalAnswer]);
assert_equal(true, $disposed['archived'], 'an idle one-off session is archived');
assert_equal($killsBefore + 1, $runtime->kills, 'by stopping it');
assert_equal(null, SidecarStore::read_sidecar($answerSession['session_id']), 'and removing its live row');
assert_equal($finalAnswer, $start(['prompt' => 'dispose'], $answerKey), 'a retry returns the answer, not a link to the archived session');
$codexAnswer = $start(['prompt' => 'codex dispose', 'agent' => 'codex', 'model' => 'configured-model']);
$codex->pending = null;
$codexKillsBefore = $codex->kills;
assert_equal(true, $host->dispose(['session_id' => $codexAnswer['session_id'], 'request_id' => hash('sha256', 'codex-dispose-key'), 'response' => $finalAnswer])['archived'], 'Codex one-off threads are archived too');
assert_equal($codexKillsBefore + 1, $codex->kills, 'through the thread archive call');

$killsAtWebStart = $runtime->kills;
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
assert_equal($killsAtWebStart, $runtime->kills, 'HTTP wait never terminates a process');
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

echo "Web: archiving one-off answers\n";
$disposeCalls = [];
$disposeReply = ['ok' => true, 'archived' => true];
$startReply = ['type' => 'session', 'status' => 'running', 'session_id' => 'safe-id', 'request_id' => str_repeat('a', 64), 'created' => true, 'intent' => 'answer'];
$probe = ['type' => 'session', 'status' => 'completed', 'completed' => true, 'text' => 'Four', 'session_id' => 'safe-id'];
$disposing = new WebQuickPrompt(static function (array $request, float $seconds) use (&$disposeCalls, &$disposeReply, &$startReply, &$probe): array {
    if ($request['action'] === 'quick_prompt_dispose') { $disposeCalls[] = $request; return $disposeReply; }
    return $request['action'] === 'quick_prompt_start' ? $startReply : $probe;
}, static fn(): float => 0.0, static function (float $seconds): void {});
$answered = $disposing->submit('{"prompt":"2+2?"}', $server);
assert_equal(['type' => 'answer', 'text' => 'Four', 'archived' => true], $answered, 'an archived answer carries no link to the archived session');
assert_equal(str_repeat('a', 64), $disposeCalls[0]['request_id'], 'the host is told which request to finalize');
assert_equal($answered, $disposeCalls[0]['response'], 'with the exact answer a retry should get');
$disposeCalls = []; $startReply['intent'] = 'session';
assert_equal('answer', $disposing->submit('{"prompt":"x"}', $server)['type'], 'a session not judged a one-off is kept');
assert_equal([], $disposeCalls, 'and never asked to be archived');
$startReply['intent'] = 'answer'; unset($startReply['created']);
$disposing->submit('{"prompt":"x"}', $server);
assert_equal([], $disposeCalls, 'a continued session is never archived');
$startReply['created'] = true; $disposeReply = ['ok' => true, 'archived' => false];
$kept = $disposing->submit('{"prompt":"x"}', $server);
assert_equal('safe-id', $kept['session_id'], 'when the host declines to archive, the answer still links to the live session');
$probe['has_tools'] = true; $disposeCalls = [];
assert_equal('session', $disposing->submit('{"prompt":"x"}', $server)['type'], 'tool output stays a session');
assert_equal([], $disposeCalls, 'and is not archived');

$withCause = new WebQuickPrompt(static fn(array $request, float $seconds): array => ['type' => 'error', 'code' => 'agent_failed', 'reason' => 'quota_exhausted', 'message' => 'Usage limit reached: resets at 5pm', 'detail' => 'resets at 5pm', '_http' => 502, 'secret' => 'x']);
$causeReply = $withCause->submit('{"prompt":"x"}', $server);
assert_equal('quota_exhausted', $causeReply['reason'], 'the web tier passes the cause through');
assert_equal('Usage limit reached: resets at 5pm', $causeReply['message'], 'and the readable message');
assert_equal(502, $causeReply['_http'], 'with the host status');
assert_true(!isset($causeReply['secret']), 'but only the known fields');
$badReason = new WebQuickPrompt(static fn(array $request, float $seconds): array => ['type' => 'error', 'code' => 'agent_failed', 'reason' => "x\n<script>", 'message' => 'm', '_http' => 502]);
assert_true(!isset($badReason->submit('{"prompt":"x"}', $server)['reason']), 'a malformed reason is dropped');

test_exit();
