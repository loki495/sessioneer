<?php

declare(strict_types=1);

/**
 * Which host-agent sends ask the manager to mark a message as synthetic when the
 * receiver is busy: a peer `launch_send` does (`synthetic_if_busy: true`), and
 * dashboard, launch, reuse, resume and --as-user sends never do. Driven through
 * the real actions against a REAL ClaudeHeadlessManager running the scripted
 * tests/fixtures/fake_claude_stream, with a recording proxy on the socket so the
 * exact request params are asserted next to the line the process received.
 * Never spawns the real `claude`, never billable.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/claude_headless.php';

$root = sys_get_temp_dir() . '/sessioneer-test-peer-send-' . getmypid();
@mkdir($root . '/home/.claude/projects', 0700, true);
@mkdir($root . '/work/project', 0700, true);

$realTmuxSocket = \HostAgent\Services\Config::tmux_socket();
$realSidecarDir = \HostAgent\Services\Config::sidecar_dir();

foreach ([
    'TMUX_SOCKET' => $root . '/tmux/socket',
    'CLAUDE_BIN' => __DIR__ . '/fixtures/fake_claude',
    'CACHE_DIR' => $root . '/cache',
    'SIDECAR_DIR' => $root . '/sidecars',
    'SESSIONS_SQLITE_FILE' => $root . '/sessions.sqlite',
    'PUSH_SQLITE_FILE' => $root . '/push.sqlite',
    'SESSION_LIST_CACHE_TTL_SECONDS' => '0',
    'OPENCODE_SERVE_URL' => 'http://127.0.0.1:1',
    'OPENCODE_DB_PATH' => $root . '/no-opencode.db',
    'CODEX_BRIDGE_SOCKET' => $root . '/no-codex-bridge.sock',
] as $key => $value) {
    putenv("{$key}={$value}");
}

if (\HostAgent\Services\Config::tmux_socket() === $realTmuxSocket || \HostAgent\Services\Config::sidecar_dir() === $realSidecarDir) {
    fwrite(STDERR, "REFUSING TO RUN: configuration resolves to the real host.\n");
    exit(1);
}

@mkdir((string)getenv('SIDECAR_DIR'), 0700, true);
@mkdir((string)getenv('CACHE_DIR'), 0700, true);
@mkdir(dirname((string)getenv('TMUX_SOCKET')), 0700, true);
putenv('HOME_ROOT=' . $root . '/home');
putenv('CLAUDE_HEADLESS_SOCKET=' . $root . '/proxy.sock');

require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

/** @var array<int, array{proc: resource, sock: string, log: string, fake: string}> $managers */
$managers = [];

/** @var array<int, string> $madeSessions */
$madeSessions = [];

/** @var array<int, resource> $proxies */
$proxies = [];

register_shutdown_function(static function () use (&$managers, &$madeSessions, &$proxies, $root): void {
    foreach ($proxies as $proxy) {
        @proc_terminate($proxy, SIGKILL);
        @proc_close($proxy);
    }

    foreach ($managers as $m) {
        if (is_resource($m['proc'])) {
            @proc_terminate($m['proc'], SIGTERM);
            usleep(300000);
            @proc_terminate($m['proc'], SIGKILL);
            @proc_close($m['proc']);
        }
    }

    foreach ($madeSessions as $name) {
        SidecarStore::delete_sidecar($name);
        SessionStatusStore::delete_status($name);
    }

    $socket = (string)getenv('TMUX_SOCKET');

    if (str_starts_with($socket, $root . '/')) {
        exec('tmux -S ' . escapeshellarg($socket) . ' kill-server 2>/dev/null');
    }

    exec('rm -rf ' . escapeshellarg($root));
});

$m = start_manager($root, 'mgr', ['CLAUDE_HEADLESS_MAX_CHILDREN' => '12']);

$proxyLog = $root . '/proxy.log';
$proxy = proc_open(
    [PHP_BINARY, __DIR__ . '/fixtures/manager_recording_proxy.php', $root . '/proxy.sock', $m['sock'], $proxyLog],
    [0 => ['pipe', 'r'], 1 => ['file', $root . '/proxy.out', 'a'], 2 => ['file', $root . '/proxy.out', 'a']],
    $proxyPipes
);

if (!is_resource($proxy) || !wait_until(static fn (): bool => file_exists($root . '/proxy.sock'))) {
    fwrite(STDERR, "cannot start the recording proxy\n");
    exit(1);
}

$proxies[] = $proxy;

$workdir = $root . '/work/project';
exec('git init -q -b main ' . escapeshellarg($workdir));

function mark(): int
{
    static $n = 100;

    return ++$n;
}

/** A unique tag to find one message's line in the fake process's log. */
function token(): string
{
    static $n = 0;

    return 'msg:' . ++$n . ':';
}

/** @return list<array<string, mixed>> the params of every sendInput request that went through the proxy */
function send_inputs(): array
{
    global $proxyLog;
    $params = [];

    foreach (@file($proxyLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $request = json_decode($line, true);

        if (($request['method'] ?? '') === 'sessioneer/sendInput') {
            $params[] = (array)($request['params'] ?? []);
        }
    }

    return $params;
}

/** @param array<string, mixed> $request @return array{0: array<string, mixed>, 1: list<array<string, mixed>>} reply and the sendInput params it caused */
function observe(array $request): array
{
    $before = count(send_inputs());
    $reply = dispatch_action($request);

    return [$reply, array_slice(send_inputs(), $before)];
}

/** @param array<string, mixed> $request @return array<string, mixed> */
function launch(array $request): array
{
    global $madeSessions;
    $reply = dispatch_action(['action' => 'launch'] + $request);

    if (($reply['name'] ?? '') !== '') {
        $madeSessions[] = (string)$reply['name'];
    }

    if (($reply['ok'] ?? false) !== true) {
        assert_true(false, 'precondition: the launch succeeds - got ' . json_encode($reply));
    }

    return $reply;
}

function ready(bool $ok, string $what): void
{
    if (!$ok) {
        assert_true(false, "precondition: {$what}");
    }
}

/** A new session whose first prompt is $keyword, settled into the state that keyword scripts. */
function receiver(string $keyword): string
{
    global $m, $workdir;
    $n = mark();
    $prompt = match ($keyword) {
        'HOLD' => 'HOLD',
        'PERMISSION' => 'PERMISSION please',
        'AUTO' => "AUTO(main,hold) BARRIER={$n}",
        'MULTIREQ_RESULT' => "MULTIREQ_RESULT AFTER={$n}",
        default => "warm up AFTER={$n}",
    };
    $name = (string)(launch(['prompt' => $prompt, 'workdir' => $workdir])['name'] ?? '');

    if ($keyword === 'HOLD') {
        ready((bool)wait_until(static fn (): bool => status_is($name, 'working')), 'the receiver is mid-turn');
    } elseif ($keyword === 'PERMISSION') {
        ready((bool)wait_until(static fn (): bool => status_is($name, 'blocked')), 'the receiver is blocked');
    } else {
        ready(manager_saw($m, $n), "the {$keyword} receiver reached its state");
    }

    return $name;
}

function stop(string $name): void
{
    global $m;
    call($m, 'sessioneer/stop', ['session' => $name]);
}

function assert_unmarked_line(string $token, string $why): void
{
    global $m;
    wait_until(static fn (): bool => user_lines($m, $token) !== []);
    $lines = user_lines($m, $token);
    assert_true(count($lines) === 1 && !array_key_exists('isSynthetic', $lines[0]), "{$why}: the line reaches the process with no isSynthetic key");
}

$sender = (string)(launch(['prompt' => 'sender', 'workdir' => $workdir])['name'] ?? '');
$stamp = "[sessioneer peer: {$sender} · {$workdir} · main]";
$peerSends = 0;

/** A peer send, asserting its request params and the line written. */
function peer(string $name, bool $marked, string $why, ?string $target = null): void
{
    global $m, $sender, $stamp, $peerSends;
    $token = token();
    [$reply, $sends] = observe(['action' => 'launch_send', 'from' => $sender, 'target' => $target ?? $name, 'message' => "{$token} I am editing X"]);
    $peerSends++;
    $content = "{$stamp} {$token} I am editing X";
    assert_true(($reply['ok'] ?? false) === true && ($reply['action'] ?? null) === 'sent', "{$why}: the peer message is delivered" . (($reply['ok'] ?? false) === true ? '' : ' - got ' . json_encode($reply)));
    assert_equal(
        [canonical(['session' => $name, 'content' => $content, 'await_hooks' => true, 'synthetic_if_busy' => true])],
        canonical($sends),
        "{$why}: one sendInput with session, stamped content, await_hooks and synthetic_if_busy"
    );
    assert_user_line($m, $token, $marked, $content, "{$why}: " . ($marked ? 'written as synthetic' : 'written without the key'));
}

/** Any other send: it must neither carry the parameter nor be written as synthetic. @param array<string, mixed> $request */
function ordinary(array $request, string $token, string $why): void
{
    global $madeSessions;
    [$reply, $sends] = observe($request);

    if (($reply['name'] ?? '') !== '') {
        $madeSessions[] = (string)$reply['name'];
    }

    assert_true(($reply['ok'] ?? false) === true, "{$why}: delivered" . (($reply['ok'] ?? false) === true ? '' : ' - got ' . json_encode($reply)));
    assert_equal(1, count($sends), "{$why}: one sendInput");
    assert_true($sends !== [] && !array_key_exists('synthetic_if_busy', $sends[0]), "{$why}: without synthetic_if_busy");
    assert_unmarked_line($token, $why);
}

// ============================================================= peer sends

echo "Peer send: busy receivers are marked\n";
$s = receiver('HOLD');
peer($s, true, 'mid-turn receiver');
peer($s, true, 'a second peer message to it');
$conversation = (string)(SidecarStore::read_sidecar($s)['agent_session_id'] ?? '');
ready($conversation !== '', 'the receiver has a conversation id');
peer($s, true, 'addressed by conversation id', $conversation);
stop($s);

$s = receiver('PERMISSION');
peer($s, true, 'blocked receiver');
assert_true(status_is($s, 'blocked'), 'which stays blocked');
stop($s);

$s = receiver('AUTO');
peer($s, true, 'receiver in an unprompted turn');
stop($s);

$s = receiver('MULTIREQ_RESULT');
peer($s, true, 'receiver whose turn ended with prompts open');
stop($s);

echo "Peer send: receivers that are not busy are not marked\n";
$s = receiver('idle');
peer($s, false, 'idle receiver');
stop($s);

$s = receiver('idle');
stop($s);
ready(process_of($m, $s) === 'dormant', 'the receiver is dormant');
peer($s, false, 'dormant receiver');
stop($s);

echo "Peer send: a hook refuses it\n";
$s = receiver('idle');
[$held, $sends] = observe(['action' => 'launch_send', 'from' => $sender, 'target' => $s, 'message' => 'HOOKBLOCK stale']);
$peerSends++;
assert_equal(false, $held['ok'] ?? null, 'reported as a failure');
assert_contains('Prompt not sent: Idle 935 min', (string)($held['message'] ?? ''), 'with the hook\'s reason');
assert_equal(true, $sends[0]['synthetic_if_busy'] ?? null, 'the request carried synthetic_if_busy');
peer($s, false, 'the next peer message, nothing else being open');
stop($s);

echo "Peer send: refusals send nothing\n";
$s = receiver('HOLD');
foreach ([
    'a blank message' => ['from' => $sender, 'target' => $s, 'message' => " \n"],
    'a missing target' => ['from' => $sender, 'message' => 'x'],
    'an unknown target' => ['from' => $sender, 'target' => 'nobody', 'message' => 'x'],
    'no sender' => ['target' => $s, 'message' => 'x'],
    'an untracked sender' => ['from' => 'cc-forged', 'target' => $s, 'message' => 'x'],
    'a session messaging itself' => ['from' => $s, 'target' => $s, 'message' => 'x'],
] as $label => $request) {
    [$reply, $sends] = observe(['action' => 'launch_send'] + $request);
    assert_equal(false, $reply['ok'] ?? null, "{$label}: refused");
    assert_equal([], $sends, "{$label}: nothing reaches the manager");
}
stop($s);

// ============================================================ other sends

echo "Dashboard and --as-user sends are never marked\n";
foreach (['HOLD' => 'mid-turn', 'PERMISSION' => 'blocked', 'AUTO' => 'unprompted turn'] as $keyword => $label) {
    $s = receiver($keyword);
    $token = token();
    [$reply, $sends] = observe(['action' => 'send_message', 'session' => $s, 'text' => "{$token} from the dashboard"]);
    assert_equal(true, $reply['ok'] ?? null, "dashboard send, {$label}: delivered");
    assert_equal(
        [canonical(['session' => $s, 'content' => "{$token} from the dashboard", 'await_hooks' => true])],
        canonical($sends),
        "dashboard send, {$label}: exactly session, content and await_hooks"
    );
    assert_unmarked_line($token, "dashboard send, {$label}");

    $token = token();
    ordinary(['action' => 'launch_send', 'target' => $s, 'message' => "{$token} typed by me", 'as_user' => true], $token, "--as-user send, {$label}");
    stop($s);
}

echo "Launch, reuse and resume prompts are never marked\n";
$token = token();
ordinary(['action' => 'launch', 'prompt' => "{$token} first words", 'workdir' => $workdir], $token, 'a new session\'s prompt');
stop((string)end($madeSessions));

$s = receiver('HOLD');
$conversation = (string)(SidecarStore::read_sidecar($s)['agent_session_id'] ?? '');
$token = token();
ordinary(['action' => 'launch', 'agent_session_id' => $conversation, 'prompt' => "{$token} reuse words"], $token, 'a reuse prompt to a busy session');
stop($s);

$s = receiver('idle');
$conversation = (string)(SidecarStore::read_sidecar($s)['agent_session_id'] ?? '');
$transcriptDir = $root . '/home/.claude/projects/' . preg_replace('/[^A-Za-z0-9]/', '-', $workdir);
@mkdir($transcriptDir, 0700, true);
file_put_contents("{$transcriptDir}/{$conversation}.jsonl", json_encode(['type' => 'user', 'cwd' => $workdir, 'message' => ['role' => 'user', 'content' => 'x']]) . "\n");
ready((dispatch_action(['action' => 'kill', 'session' => $s])['ok'] ?? false) === true, 'the session to resume is killed');
$token = token();
ordinary(['action' => 'launch', 'agent_session_id' => $conversation, 'prompt' => "{$token} resume words"], $token, 'a resume prompt');
stop((string)end($madeSessions));

echo "The mark never carries over to the next send\n";
$s = receiver('HOLD');
$conversation = (string)(SidecarStore::read_sidecar($s)['agent_session_id'] ?? '');
peer($s, true, 'first, a peer');
$token = token();
ordinary(['action' => 'send_message', 'session' => $s, 'text' => "{$token} me"], $token, 'then the dashboard');
peer($s, true, 'then a peer again');
$token = token();
ordinary(['action' => 'launch_send', 'target' => $s, 'message' => "{$token} me", 'as_user' => true], $token, 'then --as-user');
$token = token();
ordinary(['action' => 'launch', 'agent_session_id' => $conversation, 'prompt' => "{$token} me"], $token, 'then a reuse prompt');
stop($s);

echo "Only peer sends carry the parameter\n";
$flagged = array_values(array_filter(send_inputs(), static fn (array $p): bool => array_key_exists('synthetic_if_busy', $p)));
assert_equal($peerSends, count($flagged), 'as many requests carry synthetic_if_busy as there were peer sends');
assert_equal([], array_values(array_filter($flagged, static fn (array $p): bool => $p['synthetic_if_busy'] !== true || !str_starts_with((string)$p['content'], '[sessioneer peer: '))), 'and each is a stamped peer message with the value true');

// ================================================================== tmux

echo "tmux receivers are unchanged\n";
$tmuxBefore = array_column(\HostAgent\Services\TmuxService::list_tracked_tmux_sessions(), 'name');
$pane = dispatch_action(['action' => 'create', 'agent' => 'claude', 'runtime' => 'tmux', 'workdir' => $workdir]);
$paneName = (string)(array_values(array_diff(array_column(\HostAgent\Services\TmuxService::list_tracked_tmux_sessions(), 'name'), $tmuxBefore))[0] ?? '');
$madeSessions[] = $paneName;
ready(str_starts_with($paneName, 'cc-'), 'a tmux receiver exists - got ' . json_encode($pane));
$screen = static function () use ($paneName): string {
    $lines = [];
    exec('tmux -S ' . escapeshellarg((string)getenv('TMUX_SOCKET')) . ' capture-pane -p -J -t ' . escapeshellarg($paneName), $lines);

    return implode("\n", $lines);
};
[$reply, $sends] = observe(['action' => 'launch_send', 'from' => $sender, 'target' => $paneName, 'message' => 'to the pane']);
assert_true(($reply['ok'] ?? false) === true && ($reply['action'] ?? null) === 'sent', 'a peer message to a tmux receiver is delivered' . (($reply['ok'] ?? false) === true ? '' : ' - got ' . json_encode($reply)));
assert_true((bool)wait_until(static fn (): bool => str_contains($screen(), "{$stamp} to the pane")), 'typed into the pane with its stamp');
assert_true(!str_contains($screen(), 'isSynthetic') && !str_contains($screen(), 'synthetic_if_busy'), 'and nothing about synthetic marking is typed');
assert_equal([], $sends, 'without asking the headless manager');

test_exit();
