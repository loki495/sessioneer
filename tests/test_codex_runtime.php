<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';

use HostAgent\Agents\AgentRegistry;
use HostAgent\Runtimes\CodexBridgeClient;
use HostAgent\Runtimes\CodexHeadlessRuntime;
use HostAgent\Runtimes\RuntimeRegistry;
use HostAgent\Runtimes\RuntimeType;
use HostAgent\Services\CodexTranscriptService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SqliteDb;

// Direct invocation must be as isolated as tests/run.sh, including an
// inherited SESSIONS_SQLITE_FILE from a managed coding session.
$runtimeTestRoot = sys_get_temp_dir() . '/sessioneer-test-codex-runtime-' . getmypid();
mkdir($runtimeTestRoot, 0700, true);
putenv('SIDECAR_DIR=' . $runtimeTestRoot . '/sidecars');
putenv('SESSIONS_SQLITE_FILE=' . $runtimeTestRoot . '/sessions.sqlite');
register_shutdown_function(static function () use ($runtimeTestRoot): void {
    SqliteDb::reset_connections_for_tests();
    exec('rm -rf ' . escapeshellarg($runtimeTestRoot));
});

class FakeCodexBridgeClient extends CodexBridgeClient
{
    /** @var array<int,array{method:string,params:array<string,mixed>}> */
    public array $calls = [];

    public function request(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        return match ($method) {
            'thread/start' => ['ok' => true, 'result' => ['thread' => ['id' => 'codex-thread-1', 'cwd' => $params['cwd']]]],
            'thread/list' => ['ok' => true, 'result' => ['data' => [['id' => 'codex-thread-1']]]],
            'thread/read' => ['ok' => true, 'result' => ['thread' => ['id' => 'codex-thread-1', 'cwd' => '/tmp/project', 'status' => ['type' => 'idle']]]],
            'thread/resume' => ['ok' => true, 'result' => ['thread' => ['id' => 'codex-thread-1']]],
            'thread/archive' => ['ok' => true, 'result' => (object)[]],
            'sessioneer/sendInput' => ['ok' => true, 'result' => ['turn' => ['id' => 'turn-1']]],
            'sessioneer/interrupt', 'thread/settings/update' => ['ok' => true, 'result' => (object)[]],
            'sessioneer/pendingPrompt' => ['ok' => true, 'prompt' => null],
            default => ['ok' => false, 'message' => 'Unexpected method'],
        };
    }
}

class FakeUnmaterializedCodexBridgeClient extends CodexBridgeClient
{
    /** @var array<int,array{method:string,params:array<string,mixed>}> */
    public array $calls = [];

    public function request(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        if ($method === 'thread/read' && ($params['includeTurns'] ?? false) === true) {
            return ['ok' => false, 'message' => 'thread codex-empty is not materialized yet; includeTurns is unavailable before first user message'];
        }
        if ($method === 'thread/read') {
            return ['ok' => true, 'result' => ['thread' => ['id' => 'codex-empty', 'status' => ['type' => 'idle'], 'turns' => []]]];
        }
        if ($method === 'thread/resume') {
            return ['ok' => false, 'message' => 'no rollout found for thread id codex-empty'];
        }
        if ($method === 'sessioneer/sendInput') {
            return ['ok' => true, 'result' => ['turn' => ['id' => 'turn-empty']]];
        }
        return ['ok' => false, 'message' => 'Unexpected method'];
    }
}

class FakeUnsupportedTurnsCodexBridgeClient extends CodexBridgeClient
{
    /** @var array<int,array{method:string,params:array<string,mixed>}> */
    public array $calls = [];

    public function request(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        if ($method === 'thread/read' && ($params['includeTurns'] ?? false) === true) {
            return ['ok' => false, 'message' => 'list_turns is not supported yet'];
        }
        if ($method === 'thread/read') {
            return ['ok' => true, 'result' => ['thread' => ['id' => 'codex-current', 'status' => ['type' => 'idle']]]];
        }
        if ($method === 'thread/resume') {
            return ['ok' => true, 'result' => ['thread' => ['id' => 'codex-current']]];
        }
        return ['ok' => false, 'message' => 'Unexpected method'];
    }
}

class FakePaginatedCodexBridgeClient extends CodexBridgeClient
{
    /** @var array<int,array{method:string,params:array<string,mixed>}> */
    public array $calls = [];

    public function request(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        if ($method !== 'thread/list') return ['ok' => false, 'message' => 'Unexpected method'];
        if (($params['cursor'] ?? null) === 'page-2') {
            return ['ok' => true, 'result' => ['data' => [['id' => 'codex-old']], 'nextCursor' => null]];
        }
        return ['ok' => true, 'result' => ['data' => [['id' => 'codex-new']], 'nextCursor' => 'page-2']];
    }
}

class FakeActiveWriterCodexBridgeClient extends CodexBridgeClient
{
    /** @var array<int,array{method:string,params:array<string,mixed>}> */
    public array $calls = [];

    public function request(string $method, array $params = []): array
    {
        $this->calls[] = ['method' => $method, 'params' => $params];
        return match ($method) {
            'thread/read' => ['ok' => true, 'result' => ['thread' => ['id' => 'codex-owned', 'status' => ['type' => 'idle']]]],
            default => ['ok' => false, 'message' => 'Unexpected method'],
        };
    }
}

class FakeFailingCodexBridgeClient extends CodexBridgeClient
{
    public int $calls = 0;

    public function request(string $method, array $params = []): array
    {
        $this->calls++;
        return ['ok' => false, 'message' => "{$method} unavailable"];
    }
}

$adapter = AgentRegistry::get('codex');
assert_equal('Codex', $adapter->label(), 'Codex adapter is registered');
assert_equal([RuntimeType::HEADLESS], $adapter->supported_runtimes(), 'Codex is headless-only');
assert_true(RuntimeRegistry::runtime_for('codex', RuntimeType::TMUX) === null, 'Codex never resolves to tmux');
assert_true(RuntimeRegistry::runtime_for('codex', RuntimeType::HEADLESS) instanceof CodexHeadlessRuntime, 'Codex resolves to its own app-server runtime');

$fake = new FakeCodexBridgeClient();
$queueCommands = [];
$queueRunner = static function (array $cmd) use (&$queueCommands): array {
    $queueCommands[] = $cmd;
    return ['exit' => 0, 'stdout' => 'Queued message', 'stderr' => ''];
};
$runtime = new CodexHeadlessRuntime($fake, $queueRunner, '/usr/bin/codex');
$noCallFake = new FakeFailingCodexBridgeClient();
assert_equal(false, (new CodexHeadlessRuntime($noCallFake))->create(['workdir' => 'relative/path'])['ok'] ?? null, 'Codex create rejects a relative workdir');
assert_equal(0, $noCallFake->calls, 'Codex invalid create is rejected before contacting app-server');
$created = $runtime->create(['workdir' => '/tmp/project', 'model' => 'gpt-test']);
assert_true($created['ok'] === true, 'Codex thread creation succeeds');
assert_equal('codex-thread-1', $created['id'], 'Codex create returns the native thread id');
assert_equal('thread/start', $fake->calls[0]['method'], 'Codex create uses thread/start');
assert_equal('workspace-write', $fake->calls[0]['params']['sandbox'], 'Codex create uses the workspace-write sandbox');
$restrictedFake = new FakeCodexBridgeClient();
$restricted = new CodexHeadlessRuntime($restrictedFake, $queueRunner, '/usr/bin/codex');
assert_equal(true, $restricted->create(['workdir' => '/tmp/project', 'read_only' => true])['ok'], 'Quick Prompt can create a read-only Codex thread');
assert_equal('read-only', $restrictedFake->calls[0]['params']['sandbox'], 'Quick Prompt read-only option reaches native sandbox');
assert_equal('on-request', $restrictedFake->calls[0]['params']['approvalPolicy'], 'Quick Prompt preserves Codex approval policy');

assert_true($runtime->list()['ok'] === true, 'Codex thread list succeeds');
$pagedFake = new FakePaginatedCodexBridgeClient();
$paged = CodexTranscriptService::list_threads(true, $pagedFake, true);
assert_equal(['codex-new', 'codex-old'], array_column($paged['threads'] ?? [], 'id'), 'Codex catalog follows every native pagination cursor');
assert_equal(true, $pagedFake->calls[0]['params']['archived'] ?? null, 'Codex catalog requests the archived partition explicitly');
assert_true(in_array('vscode', $pagedFake->calls[0]['params']['sourceKinds'] ?? [], true), 'Codex catalog includes the source kind used by Sessioneer-created threads');
assert_equal('page-2', $pagedFake->calls[1]['params']['cursor'] ?? null, 'Codex catalog passes the native cursor to the next page');
$failedCatalog = CodexTranscriptService::list_threads(false, new FakeFailingCodexBridgeClient());
assert_equal(false, $failedCatalog['ok'] ?? null, 'Codex catalog preserves a handled app-server list failure');
assert_contains('unavailable', $failedCatalog['message'] ?? '', 'Codex catalog list failure retains the actionable dependency error');

$archiveHome = sys_get_temp_dir() . '/sessioneer-codex-archive-' . bin2hex(random_bytes(4));
@mkdir($archiveHome . '/.codex/archived_sessions', 0700, true);
$archiveId = '01a00000-0000-7000-8000-000000000001';
$archivePath = $archiveHome . '/.codex/archived_sessions/rollout-2026-08-28T00-00-00-' . $archiveId . '.jsonl';
file_put_contents($archivePath, json_encode(['type' => 'session_meta', 'payload' => ['session_id' => $archiveId, 'timestamp' => '2026-08-28T00:00:00Z', 'cwd' => '/tmp/archived-project']]) . "\n");
file_put_contents($archiveHome . '/.codex/archived_sessions/rollout-malformed.jsonl', "{not-json\n");
putenv("HOME_ROOT={$archiveHome}");
$rollouts = CodexTranscriptService::list_archived_rollouts();
assert_equal(1, count($rollouts), 'Codex archive fallback skips malformed rollout metadata without losing valid sessions');
assert_equal($archiveId, $rollouts[0]['id'] ?? null, 'Codex archive fallback discovers a rollout omitted by app-server thread/list');
assert_equal('/tmp/archived-project', $rollouts[0]['cwd'] ?? null, 'Codex archive fallback retains rollout workdir metadata');
assert_true(CodexTranscriptService::find_transcript_path($archiveId) !== null, 'Codex archived transcript resolves without an active sidecar');
putenv('HOME_ROOT');
@unlink($archivePath);
@unlink($archiveHome . '/.codex/archived_sessions/rollout-malformed.jsonl');
@rmdir($archiveHome . '/.codex/archived_sessions');
@rmdir($archiveHome . '/.codex');
@rmdir($archiveHome);
assert_true($runtime->detail('codex-thread-1')['ok'] === true, 'Codex thread detail succeeds');
assert_equal(3, count($fake->calls), 'Codex detail does not claim an idle thread through thread/resume');
assert_true($runtime->send_message('codex-thread-1', 'hello')['ok'] === true, 'Codex message send succeeds');
assert_equal('thread/read', $fake->calls[3]['method'], 'Codex message checks whether the thread has a materialized rollout');
assert_equal(['/usr/bin/codex', 'queue', '--thread', 'codex-thread-1', '--message', 'hello'], $queueCommands[0], 'Codex materialized message uses the owner-independent queue CLI');
assert_true($runtime->interrupt('codex-thread-1')['ok'] === true, 'Codex active turn can be interrupted');
assert_equal('sessioneer/interrupt', $fake->calls[4]['method'], 'Codex interrupt is server-native');
assert_true($runtime->update_settings('codex-thread-1', 'gpt-test-2', 'high')['ok'] === true, 'Codex sticky model and effort can be updated');
assert_equal('thread/settings/update', $fake->calls[5]['method'], 'Codex settings use the native thread method');
assert_true($runtime->kill('codex-thread-1')['ok'] === true, 'Codex close succeeds');
assert_equal('thread/archive', $fake->calls[6]['method'], 'Codex close archives rather than deleting');

$emptyFake = new FakeUnmaterializedCodexBridgeClient();
$emptyDetail = (new CodexHeadlessRuntime($emptyFake))->detail('codex-empty');
assert_true($emptyDetail['ok'] === true, 'Codex brand-new unmaterialized thread detail succeeds');
assert_equal(true, $emptyDetail['session']['writable'], 'Codex brand-new unmaterialized thread is writable before its rollout exists');
assert_equal(true, $emptyFake->calls[0]['params']['includeTurns'], 'Codex detail first requests retained turns');
assert_true(!array_key_exists('includeTurns', $emptyFake->calls[1]['params']), 'Codex brand-new detail falls back to metadata-only thread/read');
assert_equal(2, count($emptyFake->calls), 'Codex brand-new detail does not try to resume the unmaterialized thread');
$firstSend = (new CodexHeadlessRuntime($emptyFake, $queueRunner, '/usr/bin/codex'))->send_message('codex-empty', 'first message');
assert_true($firstSend['ok'] === true, 'Codex brand-new thread sends its first message without a persisted rollout');
assert_equal('turn-empty', $firstSend['turn_id'], 'first bridge submission retains its native turn correlation id');
assert_equal('sessioneer/sendInput', $emptyFake->calls[3]['method'], 'Codex brand-new first message stays on the private bridge that created the thread');

$unsupportedTurnsFake = new FakeUnsupportedTurnsCodexBridgeClient();
$unsupportedTurnsDetail = (new CodexHeadlessRuntime($unsupportedTurnsFake))->detail('codex-current');
assert_true($unsupportedTurnsDetail['ok'] === true, 'Codex detail falls back when the installed app-server does not support retained-turn listing');
assert_equal(true, $unsupportedTurnsFake->calls[0]['params']['includeTurns'], 'Codex unsupported-turn fallback first attempts retained turns');
assert_true(!array_key_exists('includeTurns', $unsupportedTurnsFake->calls[1]['params']), 'Codex unsupported-turn fallback retries metadata-only thread/read');

$ownedFake = new FakeActiveWriterCodexBridgeClient();
$ownedRuntime = new CodexHeadlessRuntime($ownedFake, $queueRunner, '/usr/bin/codex');
$ownedDetail = $ownedRuntime->detail('codex-owned');
assert_equal(true, $ownedDetail['session']['writable'] ?? null, 'Codex externally-owned thread remains writable through the global queue');
assert_equal(null, $ownedDetail['session']['readOnlyReason'] ?? null, 'Codex externally-owned detail has no stale single-writer warning');
$ownedSend = $ownedRuntime->send_message('codex-owned', 'queue across owners');
assert_equal(true, $ownedSend['ok'] ?? null, 'Codex externally-owned thread accepts queued message submission');
assert_equal(['/usr/bin/codex', 'queue', '--thread', 'codex-owned', '--message', 'queue across owners'], $queueCommands[1], 'Codex cross-owner send queues against the existing thread id');
assert_true(!in_array('thread/resume', array_column($ownedFake->calls, 'method'), true), 'Codex cross-owner detail and send never attempt to acquire ownership');

$attachmentRuntime = new CodexHeadlessRuntime(new FakeCodexBridgeClient(), $queueRunner, '/usr/bin/codex');
$attachmentSend = $attachmentRuntime->send_message('codex-thread-1', 'Review these', ['.claude/uploads/screenshot.png', '.claude/uploads/diagram.webp', '.claude/uploads/report.pdf']);
assert_equal(true, $attachmentSend['ok'] ?? null, 'Codex queued send supports images and path-based file mentions');
assert_equal(
    ['/usr/bin/codex', 'queue', '--thread', 'codex-thread-1', '--message', "Review these\n[Attached: .claude/uploads/report.pdf]", '--image', '/tmp/project/.claude/uploads/screenshot.png', '/tmp/project/.claude/uploads/diagram.webp'],
    $queueCommands[2],
    'Codex queued send maps images to --image and retains non-image attachments in the message'
);

$queueFailureRuntime = new CodexHeadlessRuntime(
    new FakeCodexBridgeClient(),
    static fn(array $cmd): array => ['exit' => 1, 'stdout' => '', 'stderr' => 'queue unavailable'],
    '/usr/bin/codex',
);
$queueFailure = $queueFailureRuntime->send_message('codex-thread-1', 'will fail');
assert_equal(false, $queueFailure['ok'] ?? null, 'Codex queue CLI failure is handled');
assert_contains('queue unavailable', $queueFailure['message'] ?? '', 'Codex queue CLI failure retains its actionable error');

$failingRuntime = new CodexHeadlessRuntime(new FakeFailingCodexBridgeClient());
assert_equal(false, $failingRuntime->detail('missing')['ok'] ?? null, 'Codex detail handles an unavailable or missing thread');
assert_equal(false, $failingRuntime->kill('missing')['ok'] ?? null, 'Codex archive propagates a handled app-server failure');
$emptySettingsFake = new FakeFailingCodexBridgeClient();
$emptySettingsRuntime = new CodexHeadlessRuntime($emptySettingsFake);
assert_equal(false, $emptySettingsRuntime->update_settings('codex-thread-1')['ok'] ?? null, 'Codex settings reject an empty update');
assert_equal(0, $emptySettingsFake->calls, 'Codex empty settings update is rejected before contacting app-server');

$webSearch = CodexTranscriptService::parse_item([
    'type' => 'webSearch',
    'query' => 'truncated display query',
    'action' => ['type' => 'search', 'queries' => ['first query', 'second query']],
    'results' => [['title' => 'Result', 'url' => 'https://example.test']],
], '2026-08-28T00:00:00Z', 7);
assert_equal('WebSearch', $webSearch['blocks'][0]['tool_name'] ?? null, 'Codex retained webSearch item renders as a tool call');
assert_contains('first query', $webSearch['blocks'][0]['text'] ?? '', 'Codex webSearch uses the complete action query list when available');
assert_equal('tool_result', $webSearch['blocks'][1]['kind'] ?? null, 'Codex webSearch retained results render as tool output');
assert_equal(7, $webSearch['line'] ?? null, 'Codex tool-call entry preserves its canonical transcript cursor');

$collabCall = CodexTranscriptService::parse_item([
    'type' => 'collabAgentToolCall',
    'tool' => 'spawnAgent',
    'model' => 'gpt-test',
    'prompt' => 'Inspect the service',
    'agentsStates' => ['agent-1' => ['status' => 'completed']],
], null, 8);
assert_equal('spawnAgent', $collabCall['blocks'][0]['tool_name'] ?? null, 'Codex collaboration item renders as a tool call');
assert_equal('gpt-test', $collabCall['blocks'][0]['agent_type'] ?? null, 'Codex collaboration item uses the canonical subagent marker');
assert_equal('tool_result', $collabCall['blocks'][1]['kind'] ?? null, 'Codex collaboration state renders as tool output');

$mcpCall = CodexTranscriptService::parse_item([
    'type' => 'mcpToolCall',
    'server' => 'github',
    'tool' => 'search',
    'arguments' => ['q' => 'issue'],
    'result' => ['ok' => true],
], null, 9);
assert_equal('github.search', $mcpCall['blocks'][0]['tool_name'] ?? null, 'Codex MCP item keeps both server and tool identity');

$statusDb = sys_get_temp_dir() . '/sessioneer-test-codex-status-' . bin2hex(random_bytes(4)) . '.sqlite';
putenv("SESSIONS_SQLITE_FILE={$statusDb}");
SqliteDb::reset_connections_for_tests();
SidecarStore::write_sidecar('codex-stale', ['agent' => 'codex', 'runtime' => RuntimeType::HEADLESS]);
SidecarStore::write_sidecar('claude-blocked', ['agent' => 'claude', 'runtime' => RuntimeType::TMUX]);
SessionStatusStore::update_status('codex-stale', ['status' => 'blocked', 'blocked' => ['question' => 'Allow?']]);
SessionStatusStore::update_status('claude-blocked', ['status' => 'blocked', 'blocked' => ['question' => 'Keep me']]);
$cleared = SessionStatusStore::clear_stale_blocked_for_agent('codex', 'app-server restarted');
assert_equal(1, $cleared, 'Codex restart recovery clears exactly its own stale persisted prompt');
assert_equal(null, SessionStatusStore::read_status('codex-stale')['blocked'] ?? null, 'Codex restart recovery removes the unanswerable prompt');
assert_equal('app-server restarted', SessionStatusStore::read_status('codex-stale')['last_turn_error'] ?? null, 'Codex restart recovery records why the turn was interrupted');
assert_equal('Keep me', SessionStatusStore::read_status('claude-blocked')['blocked']['question'] ?? null, 'Codex restart recovery leaves another agent prompt untouched');
SqliteDb::reset_connections_for_tests();
@unlink($statusDb);
@unlink($statusDb . '-wal');
@unlink($statusDb . '-shm');

echo "Codex runtime tests passed.\n";
test_exit();
