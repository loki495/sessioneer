<?php

declare(strict_types=1);

/**
 * `--agent ollama` end to end: OllamaSessionLauncher behind the `launch*`
 * host-agent actions (a new headless session with a first prompt, reuse of a
 * tracked session id, list, kill, peer send and every refusal), plus
 * bin/sessioneer itself against the REAL host-agent/agent.php over a socket.
 *
 * The Ollama server is tests/fixtures/ollama_stub.php: no real model, no GPU,
 * and no network beyond 127.0.0.1.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/free_port.php';
require_once __DIR__ . '/lib/harness.php';
require_once dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Launch\LaunchService;
use HostAgent\Launch\OllamaSessionLauncher;
use HostAgent\Launch\SessionLauncher;
use HostAgent\Services\Config;
use HostAgent\Services\OllamaTranscriptService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;
use HostAgent\Stores\SqliteDb;

$root = sys_get_temp_dir() . '/sessioneer-test-launch-ollama-' . getmypid() . '-' . bin2hex(random_bytes(3));
@mkdir($root . '/work', 0700, true);
@mkdir($root . '/sidecars', 0700, true);
@mkdir($root . '/cache', 0700, true);

// Unconditional, not "only if unset": a test running inside a real Sessioneer
// session inherits its SIDECAR_DIR, and an "if unset" guard would then write
// into the host's real session rows.
$realSidecarDir = Config::sidecar_dir();
putenv('SIDECAR_DIR=' . $root . '/sidecars');
putenv('SESSIONS_SQLITE_FILE=' . $root . '/sessions.sqlite');
putenv('PUSH_SQLITE_FILE=' . $root . '/push.sqlite');
putenv('CACHE_DIR=' . $root . '/cache');
putenv('OLLAMA_URL=http://127.0.0.1:1');

if (Config::sidecar_dir() === $realSidecarDir) {
    fwrite(STDERR, "REFUSING TO RUN: SIDECAR_DIR resolves to the real host sidecar dir.\n");
    exit(1);
}

$stateFile = $root . '/stub-state.json';
file_put_contents($stateFile, json_encode(['mode' => 'normal']));

// --- stub server -----------------------------------------------------------------

$port = test_free_port();
$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/fixtures/ollama_stub.php', $stateFile],
    [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $serverPipes
);

if (!is_resource($server)) {
    fwrite(STDERR, "failed to start the Ollama stub\n");
    exit(1);
}

$stubUrl = "http://127.0.0.1:{$port}";
putenv("OLLAMA_URL={$stubUrl}");

/** @var array<int, string> $made */
$made = [];

register_shutdown_function(static function () use (&$made, &$server, $root): void {
    foreach ($made as $name) {
        $pid = OllamaTranscriptService::running_pid($name);

        if ($pid !== null && function_exists('posix_kill')) {
            @posix_kill($pid, SIGTERM);
        }

        SidecarStore::delete_sidecar($name);
        SessionStatusStore::delete_status($name);
    }

    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }

    SqliteDb::reset_connections_for_tests();
    @exec('rm -rf ' . escapeshellarg($root));
});

/** Waits for a session's turn to finish; returns its final status row. */
$wait_idle = static function (string $name, float $seconds = 20.0): array {
    $deadline = microtime(true) + $seconds;

    while (microtime(true) < $deadline) {
        $status = SessionStatusStore::read_status($name);

        if (($status['status'] ?? null) !== 'working') {
            return $status ?? [];
        }

        usleep(100000);
    }

    return SessionStatusStore::read_status($name) ?? [];
};

/** @param array<string, mixed> $request @return array<string, mixed> */
function launch_ollama(array $request): array
{
    return dispatch_action(['action' => 'launch', 'agent' => 'ollama'] + $request);
}

/** @return array{int, string, string} exit code, stdout, stderr */
function run_cli(array $args, string $socket): array
{
    $proc = proc_open([PHP_BINARY, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['SESSIONEER_AGENT_SOCKET' => $socket] + getenv());
    $out = (string)stream_get_contents($pipes[1]);
    $err = (string)stream_get_contents($pipes[2]);

    return [proc_close($proc), $out, $err];
}

$workdir = $root . '/work/project';
@mkdir($workdir, 0700, true);

// Give the stub a moment to bind.
$up = false;
for ($i = 0; $i < 30 && !$up; $i++) {
    usleep(100000);
    $up = @file_get_contents($stubUrl . '/api/version') !== false;
}
assert_true($up, 'stub server: reached on the reserved port');

echo "Contract\n";
$launcher = new OllamaSessionLauncher();
assert_true($launcher instanceof SessionLauncher, 'the Ollama launcher implements the SessionLauncher contract');
assert_equal('ollama', $launcher->agent_id(), 'and names its agent');
$registered = (new ReflectionClass(LaunchService::class))->getReflectionConstant('LAUNCHERS')->getValue();
assert_equal(OllamaSessionLauncher::class, $registered['ollama'] ?? null, 'it is registered in LaunchService');

echo "Refusals\n";
$before = count(SidecarStore::list_runtime_sidecars('headless'));
foreach ([
    ['no prompt and no model', ['workdir' => $workdir], 'needs a model (available: chat-one:1b)'],
    ['a model the server lacks', ['workdir' => $workdir, 'model' => 'nope:7b', 'prompt' => 'hi'], "Model 'nope:7b' is not on this Ollama server (available: chat-one:1b)"],
    ['an embedding model', ['workdir' => $workdir, 'model' => 'embed-one:latest', 'prompt' => 'hi'], 'is not on this Ollama server'],
    ['a relative workdir', ['workdir' => 'relative/dir', 'model' => 'chat-one:1b', 'prompt' => 'hi'], 'existing absolute workdir'],
    ['a missing workdir', ['workdir' => $root . '/nope', 'model' => 'chat-one:1b', 'prompt' => 'hi'], 'existing absolute workdir'],
    ['an untracked session id', ['agent_session_id' => 'ollama-headless-19700101-000000', 'prompt' => 'hi'], 'No tracked Ollama session found'],
    ['--effort', ['workdir' => $workdir, 'model' => 'chat-one:1b', 'prompt' => 'hi', 'effort' => 'high'], '--effort is not supported for Ollama'],
    ['--mode', ['workdir' => $workdir, 'model' => 'chat-one:1b', 'prompt' => 'hi', 'starting_mode' => 'plan'], '--mode is not supported for Ollama'],
    ['--profile', ['workdir' => $workdir, 'model' => 'chat-one:1b', 'prompt' => 'hi', 'profile' => 'work'], '--profile is not supported for Ollama'],
    ['--no-tools', ['workdir' => $workdir, 'model' => 'chat-one:1b', 'prompt' => 'hi', 'no_tools' => true], '--no-tools is not supported for Ollama'],
    ['--allowed-tools', ['workdir' => $workdir, 'model' => 'chat-one:1b', 'prompt' => 'hi', 'allowed_tools' => 'WebFetch'], '--allowed-tools is not supported for Ollama'],
    ['--disallowed-tools', ['workdir' => $workdir, 'model' => 'chat-one:1b', 'prompt' => 'hi', 'disallowed_tools' => 'Bash'], '--disallowed-tools is not supported for Ollama'],
    ['--task-tools', ['workdir' => $workdir, 'model' => 'chat-one:1b', 'prompt' => 'hi', 'enable_task_tools' => true], '--task-tools is not supported for Ollama'],
] as [$label, $request, $needle]) {
    $reply = launch_ollama($request);
    assert_equal(false, $reply['ok'] ?? null, "{$label} is refused");
    assert_contains($needle, (string)$reply['message'], "{$label}: with a clear message");
}
assert_equal($before, count(SidecarStore::list_runtime_sidecars('headless')), 'no refusal left a session behind');

echo "New session\n";
$new = launch_ollama(['prompt' => 'first words', 'workdir' => $workdir, 'model' => 'chat-one:1b']);
assert_true(($new['ok'] ?? false) === true, 'a new session launches' . (($new['ok'] ?? false) === true ? '' : ' - got ' . json_encode($new)));
$name = (string)($new['name'] ?? '');
$made[] = $name;
assert_equal('created', $new['action'] ?? null, 'reported as created');
assert_equal(true, $new['prompt_sent'] ?? null, 'with its first prompt delivered');

$sidecar = SidecarStore::read_sidecar($name) ?? [];
assert_equal('ollama', $sidecar['agent'] ?? null, 'the session is tracked as an Ollama one');
assert_equal('headless', $sidecar['runtime'] ?? null, 'on the headless runtime');
assert_equal($workdir, $sidecar['workdir'] ?? null, 'in the requested directory');
assert_equal($name, $sidecar['agent_session_id'] ?? null, 'bound to its own conversation id');
assert_equal(true, $sidecar['spawned_by_app'] ?? null, 'written by the app, like the dashboard\'s New Session');

$status = $wait_idle($name);
assert_equal('idle', $status['status'] ?? null, 'the turn finishes idle');
assert_equal(null, $status['last_turn_error'] ?? null, 'with no error reported');
$messages = OllamaTranscriptService::messages($name);
assert_equal(2, count($messages), 'the transcript holds the prompt and the answer');
assert_equal('first words', $messages[0]['content'] ?? null, 'the prompt is stored verbatim');
assert_contains('stub answer to: first words', (string)($messages[1]['content'] ?? ''), 'the reply lands in the transcript');

$row = array_values(array_filter($launcher->list_sessions([])['sessions'], static fn (array $s): bool => $s['name'] === $name))[0] ?? [];
assert_equal('idle', $row['status'] ?? null, 'the list shows the session idle');
assert_equal('chat-one:1b', $row['model'] ?? null, 'with the model it runs on');
assert_equal($workdir, $row['workdir'] ?? null, 'its directory');
assert_equal($name, $row['agent_session_id'] ?? null, 'and its conversation id');

echo "Reuse\n";
$reused = launch_ollama(['agent_session_id' => $name, 'prompt' => 'second words']);
assert_true(($reused['ok'] ?? false) === true, 'a tracked conversation is reused' . (($reused['ok'] ?? false) === true ? '' : ' - got ' . json_encode($reused)));
assert_equal($name, $reused['name'] ?? null, 'the SAME session receives the prompt');
assert_equal('reused', $reused['action'] ?? null, 'reported as reused');
$wait_idle($name);
$messages = OllamaTranscriptService::messages($name);
assert_equal(4, count($messages), 'the conversation accumulates');
assert_contains('stub answer to: second words', (string)($messages[3]['content'] ?? ''), 'and the second answer lands in it');
assert_equal(1, count(SidecarStore::list_runtime_sidecars('headless')), 'no second session was opened on the conversation');
$quiet = launch_ollama(['agent_session_id' => $name]);
assert_equal(true, $quiet['ok'] ?? null, 'reusing without a prompt is fine');
assert_equal(false, $quiet['prompt_sent'] ?? null, 'and sends nothing');
$otherModel = launch_ollama(['agent_session_id' => $name, 'model' => 'nope:7b']);
assert_equal(true, $otherModel['ok'] ?? null, 'a reuse keeps the session\'s own model, whatever --model says');
assert_equal('chat-one:1b', (string)(SessionStatusStore::read_status($name)['model'] ?? ''), 'so the model is unchanged');
$noPrompt = launch_ollama(['workdir' => $workdir, 'model' => 'chat-one:1b']);
assert_equal(true, $noPrompt['ok'] ?? null, 'a session can be created without a prompt' . (($noPrompt['ok'] ?? false) === true ? '' : ' - got ' . json_encode($noPrompt)));
assert_equal(false, $noPrompt['prompt_sent'] ?? null, 'which sends nothing');
$made[] = (string)($noPrompt['name'] ?? '');

echo "Peers and send\n";
exec('git -C ' . escapeshellarg($workdir) . ' init -q -b main 2>&1');
$peers = dispatch_action(['action' => 'launch_peers', 'agent' => 'ollama', 'from' => $name]);
assert_equal(true, $peers['ok'] ?? null, 'listing peers succeeds');
assert_equal([(string)($noPrompt['name'] ?? '')], array_column($peers['peers'] ?? [], 'name'), 'peers are the other Ollama sessions in the same repository');
assert_equal('main', $peers['peers'][0]['branch'] ?? null, 'with their branch');

$sent = dispatch_action(['action' => 'launch_send', 'agent' => 'ollama', 'from' => $name, 'target' => (string)($noPrompt['name'] ?? ''), 'message' => 'I am editing X']);
assert_true(($sent['ok'] ?? false) === true, 'a peer message is delivered' . (($sent['ok'] ?? false) === true ? '' : ' - got ' . json_encode($sent)));
assert_equal('sent', $sent['action'] ?? null, 'reported as sent');
$wait_idle((string)($noPrompt['name'] ?? ''));
$last = OllamaTranscriptService::messages((string)($noPrompt['name'] ?? ''));
assert_contains("[sessioneer peer: {$name} · {$workdir} · main] I am editing X", (string)($last[1]['content'] ?? ''), 'behind a stamp the host agent built from the sender');
$unknownTarget = dispatch_action(['action' => 'launch_send', 'agent' => 'ollama', 'from' => $name, 'target' => 'nobody', 'message' => 'hello?']);
assert_equal(false, $unknownTarget['ok'] ?? null, 'a message to an unknown target is refused');
assert_contains('No tracked Ollama session found for \'nobody\'', (string)$unknownTarget['message'], 'naming the target it could not find');

echo "Kill\n";
file_put_contents($stateFile, json_encode(['mode' => 'slow']));
$busy = launch_ollama(['agent_session_id' => $name, 'prompt' => 'a slow one']);
assert_equal(true, $busy['prompt_sent'] ?? null, 'a prompt starts a turn');
$refused = dispatch_action(['action' => 'launch_kill', 'agent' => 'ollama', 'target' => $name]);
assert_equal(false, $refused['ok'] ?? null, 'a working session is not stopped');
assert_contains('is working', (string)$refused['message'], 'with a message saying why');
assert_contains('--force', (string)$refused['message'], 'and how to override');
assert_true(SidecarStore::read_sidecar($name) !== null, 'the session is still there');
$forced = dispatch_action(['action' => 'launch_kill', 'agent' => 'ollama', 'target' => $name, 'force' => true]);
assert_equal(true, $forced['ok'] ?? null, '--force stops it anyway');
assert_equal(null, SidecarStore::read_sidecar($name), 'and it leaves the list');
assert_true(is_file(OllamaTranscriptService::transcript_path($name)), 'its transcript is kept (it is the only copy)');
file_put_contents($stateFile, json_encode(['mode' => 'normal']));

$idle = launch_ollama(['workdir' => $workdir, 'model' => 'chat-one:1b']);
$made[] = (string)($idle['name'] ?? '');
$killed = dispatch_action(['action' => 'launch_kill', 'agent' => 'ollama', 'target' => (string)($idle['name'] ?? '')]);
assert_equal(true, $killed['ok'] ?? null, 'an idle session is stopped');
assert_equal(null, SidecarStore::read_sidecar((string)($idle['name'] ?? '')), 'and leaves the list');

echo "CLI\n";
$bin = dirname(__DIR__) . '/bin/sessioneer';
$deadSocket = $root . '/no-agent.sock';

foreach ([
    ['--profile', 'work', '--profile has no meaning for --agent ollama'],
    ['--effort', 'high', '--effort has no meaning for --agent ollama'],
    ['--mode', 'plan', '--mode has no meaning for --agent ollama'],
    ['--no-tools', null, '--no-tools has no meaning for --agent ollama'],
    ['--task-tools', null, '--task-tools has no meaning for --agent ollama'],
] as [$option, $value, $needle]) {
    $args = [$bin, '--agent', 'ollama', '--workdir', $workdir, $option];

    if ($value !== null) {
        $args[] = $value;
    }

    $args[] = 'hi';
    [$code, , $err] = run_cli($args, $deadSocket);
    assert_equal(1, $code, "{$option} for --agent ollama: exit 1 before anything is launched");
    assert_contains($needle, $err, "{$option}: with a specific message");
}

$socketPath = $root . '/agent.sock';
$harness = start_harness([PHP_BINARY, dirname(__DIR__) . '/host-agent/agent.php'], $socketPath);

try {
    [$code, , $err] = run_cli([$bin, '--agent', 'ollama', '--wait', '--timeout', '30', '--workdir', $workdir, 'hi'], $socketPath);
    assert_equal(1, $code, 'a launch without --model is refused over the real agent');
    assert_contains('needs a model (available: chat-one:1b)', $err, 'naming the models the server has');

    [$code, $out, $err] = run_cli([$bin, '--agent', 'ollama', '--wait', '--timeout', '30', '--model', 'chat-one:1b', '--workdir', $workdir, 'hello from the cli'], $socketPath);
    assert_equal(0, $code, 'sessioneer --agent ollama --wait exits 0' . ($code === 0 ? '' : " - stderr: {$err}"));
    assert_contains('stub answer to: hello from the cli', $out, '--wait prints the model\'s reply');
    $cliName = trim($err);
    assert_true(str_starts_with($cliName, 'ollama-headless-'), '--wait names the session on stderr');

    [$code, $out] = run_cli([$bin, '--agent', 'ollama', '--list'], $socketPath);
    assert_equal(0, $code, 'sessioneer --agent ollama --list exits 0');
    assert_contains($cliName, $out, 'and lists the session it just made');

    [$code, $out] = run_cli([$bin, '--agent', 'ollama', '--kill', $cliName], $socketPath);
    assert_equal(0, $code, 'sessioneer --agent ollama --kill exits 0');
    assert_contains($cliName, $out, 'naming the session it stopped');
} finally {
    stop_harness($harness, $socketPath);
}

test_exit();
