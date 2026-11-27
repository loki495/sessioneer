<?php

declare(strict_types=1);

/**
 * `sessioneer/sendInput` with `synthetic_if_busy`: a REAL ClaudeHeadlessManager
 * driving the scripted tests/fixtures/fake_claude_stream, asserted on the exact
 * line the manager writes to the process. The line carries `isSynthetic: true`
 * only when the parameter is truthy AND the receiver is busy (a message without
 * its result, a pending prompt or question, or a main-thread assistant event
 * since the last result). Never spawns the real `claude`, never billable.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/lib/assert.php';
require_once __DIR__ . '/lib/claude_headless.php';

use HostAgent\Runtimes\ClaudeHeadlessManager;
use HostAgent\Services\Config;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$root = sys_get_temp_dir() . '/sessioneer-test-peer-busy-' . getmypid();
@mkdir($root . '/home/.claude/projects', 0700, true);
@mkdir($root . '/sidecars', 0700, true);

// Unconditional: a run from inside a Sessioneer session inherits the real SIDECAR_DIR.
$realSidecarDir = Config::sidecar_dir();
$realPushSqliteFile = Config::push_sqlite_path();
putenv('SIDECAR_DIR=' . $root . '/sidecars');
putenv('SESSIONS_SQLITE_FILE=' . $root . '/sessions.sqlite');
putenv('PUSH_SQLITE_FILE=' . $root . '/push.sqlite');

if (Config::sidecar_dir() === $realSidecarDir || Config::push_sqlite_path() === $realPushSqliteFile) {
    fwrite(STDERR, "REFUSING TO RUN: configuration resolves to the real host.\n");
    exit(1);
}

/** @var array<int, array{proc: resource, sock: string, log: string, fake: string}> $managers */
$managers = [];

/** @var array<int, string> $madeSessions */
$madeSessions = [];

register_shutdown_function(static function () use (&$managers, &$madeSessions, $root): void {
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

    exec('rm -rf ' . escapeshellarg($root));
});

$m = start_manager($root, 'mgr', ['CLAUDE_HEADLESS_MAX_CHILDREN' => '12']);

const SYNTHETIC = ['synthetic_if_busy' => true];

/** A new tracked session with no process (dormant). */
function session(): string
{
    global $root;
    static $n = 0;
    $name = sprintf('claude-headless-t%d-%06d', getmypid(), ++$n);
    make_session($root, $name);

    return $name;
}

/** A barrier number not used before (see the fake's BARRIER= / AFTER=). */
function mark(): int
{
    static $n = 100;

    return ++$n;
}

/** @param array<string, mixed> $extra @return array<string, mixed> */
function send(string $name, mixed $content, array $extra = []): array
{
    global $m;

    return call($m, 'sessioneer/sendInput', ['session' => $name, 'content' => $content] + $extra);
}

function ready(bool $ok, string $what): void
{
    if (!$ok) {
        assert_true(false, "precondition: {$what}");
    }
}

function saw(int $n): void
{
    global $m;
    ready(manager_saw($m, $n), "the manager processed marker {$n}");
}

/** A running process whose one turn has finished. */
function idle_session(): string
{
    $name = session();
    $n = mark();
    ready((send($name, "warm up AFTER={$n}")['ok'] ?? false) === true, 'the warm-up message is delivered');
    saw($n);

    return $name;
}

/** Sends $first to a new session and waits until the manager has processed the marker it emits. */
function scripted_session(string $first, string $marker = 'BARRIER'): string
{
    $name = session();
    $n = mark();
    ready((send($name, "{$first} {$marker}={$n}")['ok'] ?? false) === true, "'{$first}' is delivered");
    saw($n);

    return $name;
}

/** Mid-turn: a message written, no event and no result for it. */
function held_session(): string
{
    $name = session();
    ready((send($name, 'HOLD')['ok'] ?? false) === true, 'the HOLD message is delivered');
    ready((bool)wait_until(static fn (): bool => status_is($name, 'working')), 'the held turn is working');

    return $name;
}

function blocked_session(string $keyword = 'PERMISSION'): string
{
    $name = session();
    send($name, "{$keyword} please");
    ready((bool)wait_until(static fn (): bool => status_is($name, 'blocked')), "{$keyword} blocks the session");

    return $name;
}

function stop(string ...$names): void
{
    global $m;

    foreach ($names as $name) {
        call($m, 'sessioneer/stop', ['session' => $name]);
    }
}

function dormant(string $name): void
{
    global $m;
    ready((bool)wait_until(static fn (): bool => process_of($m, $name) === 'dormant'), 'the process is gone');
}

/** @var array<int, string> $mayBeMarked tokens of messages sent with a truthy parameter */
$mayBeMarked = [];

/**
 * Sends a uniquely tagged message and asserts the line the process received.
 *
 * @param array<string, mixed> $extra
 * @return array<string, mixed> the manager's reply
 */
function probe(string $name, bool $marked, string $why, array $extra = SYNTHETIC, string $text = ''): array
{
    global $m, $mayBeMarked;
    static $n = 0;
    $token = 'probe:' . ++$n . ':';
    $content = trim("{$token} {$text}");

    if (!empty($extra['synthetic_if_busy'])) {
        $mayBeMarked[] = $token;
    }

    $reply = send($name, $content, $extra);

    if (($reply['ok'] ?? false) !== true && !str_contains($text, 'HOOKBLOCK')) {
        assert_true(false, "{$why}: the message is delivered - got " . json_encode($reply));
    }

    assert_user_line($m, $token, $marked, $content, $why);

    return $reply;
}

// ========================================================= parameter handling

echo "Without synthetic_if_busy the key is never written\n";
$s = session();
probe($s, false, 'dormant, no parameter', []);
stop($s);
$s = idle_session();
probe($s, false, 'idle, no parameter', []);
stop($s);
$s = held_session();
probe($s, false, 'mid-turn, no parameter', []);
stop($s);
$s = blocked_session();
probe($s, false, 'blocked on a permission, no parameter', []);
stop($s);
$s = scripted_session('AUTO(main,hold)');
probe($s, false, 'autonomous turn, no parameter', []);
stop($s);
$s = scripted_session('MULTIREQ_RESULT', 'AFTER');
probe($s, false, 'turn ended with prompts open, no parameter', []);
stop($s);

echo "The parameter's value\n";
$s = held_session();
foreach (['false' => false, 'zero' => 0, 'an empty string' => '', 'null' => null] as $label => $falsy) {
    probe($s, false, "busy, synthetic_if_busy is {$label}: not marked", ['synthetic_if_busy' => $falsy]);
}
foreach (['1' => 1, 'the string "1"' => '1'] as $label => $truthy) {
    probe($s, true, "busy, synthetic_if_busy is {$label}: marked with a boolean true", ['synthetic_if_busy' => $truthy]);
}
stop($s);

// ====================================================== busy: (a) open message

echo "Busy: a message was written and its result has not arrived\n";
$s = session();
$n = mark();
assert_true((send($s, "HOLD opening {$n}", SYNTHETIC)['ok'] ?? false) === true, 'a synthetic_if_busy message starts a dormant session');
assert_user_line($m, "HOLD opening {$n}", false, "HOLD opening {$n}", 'the message that starts the process is not marked');
probe($s, true, 'the very next message is marked, before any result');
probe($s, true, 'and so is the one after it');
stop($s);

$s = held_session();
probe($s, true, 'a silent turn (no assistant event) is busy');
probe($s, true, 'multi-line and non-ASCII content is written unchanged', SYNTHETIC, "line one\nline \"two\" \\ / é ✓ {\"isSynthetic\":false}");
probe($s, true, 'a peer-shaped send (await_hooks too) is marked', SYNTHETIC + ['await_hooks' => true]);

$blocks = [['type' => 'text', 'text' => 'blocks:probe:'], ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => 'aGk=']]];
$mayBeMarked[] = 'blocks:probe:';
assert_true((send($s, $blocks, SYNTHETIC)['ok'] ?? false) === true, 'content blocks are accepted');
assert_user_line($m, 'blocks:probe:', true, $blocks, 'content blocks are marked and passed through unchanged');

$large = 'large:probe: ' . str_repeat('x', 200000);
$mayBeMarked[] = 'large:probe:';
assert_true((send($s, $large, SYNTHETIC)['ok'] ?? false) === true, 'a 200 kB message is accepted');
wait_until(static fn (): bool => user_lines($m, 'large:probe:') !== []);
$line = user_lines($m, 'large:probe:')[0] ?? [];
assert_true(($line['isSynthetic'] ?? null) === true && ($line['message']['content'] ?? null) === $large, 'a large message is marked and arrives whole');
stop($s);

$s = held_session();
$refused = probe($s, true, 'a prompt submitted mid-turn is marked even when a hook then refuses it', SYNTHETIC + ['await_hooks' => true], 'HOOKBLOCK');
assert_true(($refused['ok'] ?? null) === false && str_starts_with((string)($refused['message'] ?? ''), ClaudeHeadlessManager::PROMPT_HELD_PREFIX), 'and the refusal is still reported');
probe($s, true, 'the refusal does not close the turn that was already open');
stop($s);

// ====================================================== busy: (b) pending prompt

echo "Busy: a permission prompt or question is pending\n";
foreach (['PERMISSION' => 'a permission prompt', 'QUESTION' => 'a question'] as $keyword => $label) {
    $s = blocked_session($keyword);
    $pending = call($m, 'sessioneer/pendingPrompt', ['session' => $s])['prompt']['request_id'] ?? null;
    probe($s, true, "blocked on {$label}: marked");
    assert_equal('blocked', call($m, 'sessioneer/status', ['session' => $s])['state'] ?? null, 'the session stays blocked');
    assert_equal($pending, call($m, 'sessioneer/pendingPrompt', ['session' => $s])['prompt']['request_id'] ?? null, 'on the same prompt');
    call($m, 'sessioneer/answerPrompt', ['session' => $s, 'request_id' => $pending, 'response' => ['behavior' => 'allow']]);
    $n = mark();
    send($s, "settle AFTER={$n}");
    saw($n);
    probe($s, false, "once {$label} is answered and every turn has ended: not marked");
    stop($s);
}

$s = scripted_session('MULTIREQ_RESULT', 'AFTER');
$n = mark();
probe($s, true, 'the main turn ended but two prompts are open: marked', SYNTHETIC, "AFTER={$n}");
saw($n);
$n = mark();
probe($s, true, 'still marked after that message got its own result', SYNTHETIC, "AFTER={$n}");
saw($n);
call($m, 'sessioneer/answerPrompt', ['session' => $s, 'request_id' => 'multi-1', 'response' => ['behavior' => 'allow']]);
$n = mark();
probe($s, true, 'one prompt answered, one still open: marked', SYNTHETIC, "AFTER={$n}");
saw($n);
call($m, 'sessioneer/answerPrompt', ['session' => $s, 'request_id' => 'multi-2', 'response' => ['behavior' => 'deny', 'message' => 'no']]);
probe($s, false, 'the last prompt answered after the turn ended: not marked');
stop($s);

$s = scripted_session('ASKEND', 'AFTER');
probe($s, true, 'a question left open by an ended turn: marked');
stop($s);

$s = scripted_session('MULTIREQ_RESULT', 'AFTER');
$n = mark();
send($s, "HOOKBLOCK BARRIER={$n}", ['await_hooks' => true]);
saw($n);
probe($s, true, 'a hook-refused prompt leaves the open prompts, so still marked');
stop($s);

$s = scripted_session('ASKEND', 'AFTER');
$n = mark();
probe($s, true, 'the message that makes the CLI withdraw the question is written while it is pending', SYNTHETIC, "WITHDRAW AFTER={$n}");
saw($n);
probe($s, false, 'after the question is withdrawn and the turn ends: not marked');
stop($s);

// ================================================ busy: (c) unprompted turn

echo "Busy: the CLI runs a turn the manager did not start\n";
foreach ([
    'main' => 'a main-thread assistant event (parent_tool_use_id null) after the result',
    'nokey' => 'a main-thread assistant event with no parent_tool_use_id key',
    'sub,main' => 'a main-thread event following a subagent one',
    'main,sub' => 'a main-thread event followed by a subagent one',
] as $steps => $label) {
    $s = scripted_session("AUTO({$steps},hold)");
    probe($s, true, "{$label}: marked");
    probe($s, true, "{$label}: the next message is marked too");
    stop($s);
}

$s = scripted_session('AUTO(main,hold)');
$n = mark();
send($s, "HOOKBLOCK BARRIER={$n}", ['await_hooks' => true]);
saw($n);
probe($s, true, 'a hook-refused prompt does not end the unprompted turn');
stop($s);

foreach (['an interrupted' => true, 'a completed' => false] as $label => $interrupt) {
    $s = held_session();
    $n = mark();
    send($s, "TURN(main,hold) BARRIER={$n}");

    if ($interrupt) {
        call($m, 'sessioneer/interrupt', ['session' => $s]);
    } else {
        send($s, 'RELEASE');
    }

    saw($n);
    probe($s, true, "a queued message running its own turn after {$label} one: marked");
    stop($s);
}

$s = scripted_session('AUTO(main,ok)');
$n = mark();
probe($s, false, 'an unprompted turn that already ended: not marked', SYNTHETIC, "AFTER={$n}");
saw($n);
$n = mark();
send($s, "AUTO(main,hold) BARRIER={$n}");
saw($n);
probe($s, true, 'a later unprompted turn on the same process is busy again');
stop($s);

// ================================================================== not busy

echo "Not busy: no process yet\n";
$s = session();
probe($s, false, 'a dormant session, peer-shaped send: the process is started for it, not marked', SYNTHETIC + ['await_hooks' => true]);
stop($s);

foreach (['working', 'blocked'] as $stale) {
    $s = session();
    SessionStatusStore::update_status($s, ['status' => $stale]);
    probe($s, false, "no process although the stored status says {$stale}: not marked");
    stop($s);
}

echo "Not busy: idle process\n";
$s = session();
assert_true((call($m, 'sessioneer/spawn', ['session' => $s, 'fresh' => true])['ok'] ?? false) === true, 'a process is started with no message');
assert_equal('running', process_of($m, $s), 'and is running');
probe($s, false, 'a freshly started process: not marked');
stop($s);

$s = idle_session();
probe($s, false, 'after a completed turn: not marked');
stop($s);

$s = idle_session();
call($m, 'sessioneer/setMode', ['session' => $s, 'mode' => 'accept edits']);
call($m, 'sessioneer/setModel', ['session' => $s, 'model' => 'sonnet']);
call($m, 'sessioneer/interrupt', ['session' => $s]);
probe($s, false, 'control requests on an idle process do not make it busy');
stop($s);

echo "Not busy: after a result with nothing pending\n";
$s = session();
$n = mark();
send($s, "HOLD AFTER={$n}");
call($m, 'sessioneer/interrupt', ['session' => $s]);
saw($n);
probe($s, false, 'after an aborted result: not marked');
stop($s);

$s = session();
$barrier = mark();
$n = mark();
send($s, "TURN(main,hold) BARRIER={$barrier} AFTER={$n}");
saw($barrier);
call($m, 'sessioneer/interrupt', ['session' => $s]);
saw($n);
probe($s, false, 'after an aborted turn that had emitted assistant events: not marked');
stop($s);

foreach ([
    'AUTHFAIL' => 'an error result',
    'MULTIREQ_ABORT' => 'an aborted result that cleared its prompts',
    'CLEAR' => 'a conversation reset and its result',
] as $keyword => $label) {
    $s = scripted_session($keyword, 'AFTER');
    probe($s, false, "after {$label}: not marked");
    stop($s);
}

$s = session();
$n = mark();
send($s, "SLOW_THEN_QUEUE AFTER={$n}");
send($s, 'folded into the same turn');
saw($n);
probe($s, false, 'after one merged result covering two messages: not marked');
stop($s);

$s = held_session();
$n = mark();
send($s, "second AFTER={$n}");
call($m, 'sessioneer/interrupt', ['session' => $s]);
saw($n);
probe($s, false, 'after a queued message has received its own result too: not marked');
stop($s);

$s = scripted_session('AUTO(main,main,error)');
probe($s, false, 'after an unprompted turn that ended in an error: not marked');
stop($s);

$s = scripted_session('AUTO(main,hold)');
$n = mark();
send($s, "RELEASE AFTER={$n}");
saw($n);
probe($s, false, 'after a held unprompted turn gets its result: not marked');
stop($s);

echo "Not busy: subagent events only\n";
foreach (['sub' => 'one subagent event', 'sub,sub,sub' => 'several subagent events', 'main,ok,sub' => 'a subagent event after a finished unprompted turn'] as $steps => $label) {
    $s = scripted_session("AUTO({$steps})");
    probe($s, false, "{$label} while the main thread is idle: not marked");
    stop($s);
}

echo "Not busy: after a hook-refused prompt\n";
foreach (['awaiting its hooks' => ['await_hooks' => true], 'not awaiting them' => []] as $label => $extra) {
    foreach (['dormant', 'idle'] as $from) {
        $s = $from === 'idle' ? idle_session() : session();
        $n = mark();
        send($s, "HOOKBLOCK BARRIER={$n}", $extra);
        saw($n);
        probe($s, false, "refused prompt to a {$from} session, {$label}: not marked");
        stop($s);
    }
}

echo "Not busy: the busy process is gone\n";
foreach (['mid-turn' => 'HOLD', 'blocked' => 'PERMISSION', 'in an unprompted turn' => 'AUTO(main,hold)'] as $label => $keyword) {
    $s = $keyword === 'PERMISSION' ? blocked_session() : ($keyword === 'HOLD' ? held_session() : scripted_session($keyword));
    stop($s);
    dormant($s);
    probe($s, false, "stopped while {$label}, then messaged: the new process is not marked");
    stop($s);
}

$s = idle_session();
send($s, 'CRASH');
dormant($s);
probe($s, false, 'after a crash mid-turn: not marked');
stop($s);

$s = scripted_session('TURN(main,hold)');
kill_hard((int)call($m, 'sessioneer/status', ['session' => $s])['pid']);
dormant($s);
probe($s, false, 'after the process was killed in an unprompted turn: not marked');
stop($s);

echo "Busy state belongs to one session\n";
$busy = held_session();
$prompting = blocked_session();
$calm = idle_session();
probe($calm, false, 'an idle session next to busy ones: not marked');
probe($busy, true, 'the mid-turn one is');
probe($prompting, true, 'and so is the blocked one');
stop($busy, $prompting, $calm);

// ============================================================ reply, refusals

echo "The reply is unchanged\n";
foreach ([
    'a dormant session' => [static fn (): string => session(), [], 'hello'],
    'an idle session' => [static fn (): string => idle_session(), ['await_hooks' => true], 'hello'],
    'a mid-turn session' => [static fn (): string => held_session(), [], 'hello'],
    'a mid-turn session, awaiting hooks' => [static fn (): string => held_session(), ['await_hooks' => true], 'hello'],
    'a blocked session' => [static fn (): string => blocked_session(), [], 'hello'],
    'a session in an unprompted turn' => [static fn (): string => scripted_session('AUTO(main,hold)'), [], 'hello'],
    'a hook-refused prompt' => [static fn (): string => idle_session(), ['await_hooks' => true], 'HOOKBLOCK'],
] as $label => [$make, $extra, $text]) {
    $plain = $make();
    $without = send($plain, $text, $extra);
    stop($plain);
    $flagged = $make();
    $with = send($flagged, $text, $extra + SYNTHETIC);
    stop($flagged);
    $mayBeMarked[] = '"content":"' . $text . '"';
    assert_true(isset($without['ok']), "{$label}: the manager answers");
    assert_equal(canonical($without), canonical($with), "{$label}: same reply with and without synthetic_if_busy");
}

echo "Refusals are unchanged\n";
$blank = session();
$tmux = 'cc-tmux-t' . getmypid();
make_session($root, $tmux, ['runtime' => null]);
$homeless = 'claude-headless-t' . getmypid() . '-homeless';
make_session($root, $homeless, ['workdir' => $root . '/does-not-exist']);
$children = call($m, 'sessioneer/health')['children'] ?? null;
foreach ([
    'an unknown session' => ['claude-headless-t' . getmypid() . '-missing', 'x', 'Unknown session'],
    'a blank message' => [$blank, "  \n", 'empty message'],
    'a tmux session' => [$tmux, 'x', 'Not a headless Claude session'],
    'a path-like name' => ['../etc/passwd', 'x', 'Invalid session name'],
    'a missing working directory' => [$homeless, 'x', 'working directory'],
] as $label => [$target, $content, $needle]) {
    $with = send($target, $content, SYNTHETIC);
    assert_equal(false, $with['ok'] ?? null, "{$label}: refused");
    assert_contains($needle, (string)($with['message'] ?? ''), "{$label}: says why");
    assert_equal(canonical(send($target, $content)), canonical($with), "{$label}: the same refusal as without the parameter");
}
assert_equal($children, call($m, 'sessioneer/health')['children'] ?? null, 'no refused request started a process');
$s = held_session();
assert_contains('empty message', (string)(send($s, '   ', SYNTHETIC)['message'] ?? ''), 'a blank message to a busy session is still refused');
stop($s);

echo "Nothing else is ever marked\n";
$stray = [];
foreach (fake_log($m) as $row) {
    if (!isset($row['stdin'])) {
        continue;
    }

    $line = (array)json_decode((string)$row['stdin'], true);
    $asked = array_filter($mayBeMarked, static fn (string $token): bool => str_contains((string)$row['stdin'], $token)) !== [];

    if (array_key_exists('isSynthetic', $line) && ($line['isSynthetic'] !== true || !$asked)) {
        $stray[] = substr((string)$row['stdin'], 0, 200);
    }
}
assert_equal([], $stray, 'only messages sent with a truthy synthetic_if_busy ever carry the key, and only as true');

if ($GLOBALS['__sessioneer_test_failures'] > 0) {
    $tail = (string)@file_get_contents($m['log']);

    if (trim($tail) !== '') {
        fwrite(STDOUT, "\n--- manager log ---\n" . substr($tail, -1500) . "\n");
    }
}

test_exit();
