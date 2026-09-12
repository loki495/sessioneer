<?php
declare(strict_types=1);

/**
 * LIVE smoke test - the one file in this suite that spawns a REAL,
 * genuinely running `claude` binary (never fake_claude) in a real, brand
 * new, never-before-trusted directory, to prove this app's folder-trust
 * detection/handling (PromptParser::parse_blocking_prompt(),
 * PromptInteractionService::answer_prompt()) still actually works against
 * whatever the CURRENTLY INSTALLED Claude Code CLI renders - not just
 * against a fixture text file frozen at whatever version last captured it.
 * Andres's own idea (2026-09-11), after the v2.1.269 trust-dialog rewrite
 * (dropped its option numbers, reversed the default order - see
 * PromptParser.php's own docblock) broke this app silently, with every
 * existing test still green: they all replay a hand-typed or previously-
 * captured fixture, so none of them could ever have caught a REAL CLI
 * behavior change on their own. This file is the difference between "our
 * parser handles this shape" and "our parser handles whatever the CLI
 * actually renders today."
 *
 * Deliberately NOT run by tests/run.sh's default suite - see its own
 * --live flag and header comment. Skips itself gracefully (exit 0, not a
 * failure) when the real claude binary isn't on PATH, since not every
 * environment running this suite will have it installed (e.g. CI).
 *
 * Zero real API cost either way: it only ever answers "No, exit"
 * (declining trust), which Claude Code handles entirely on its own before
 * any model call is ever made - confirmed live, see PromptParser.php's own
 * docblock for the same finding. It never gets anywhere near a real
 * conversation.
 *
 * Placed directly under the real $HOME (never under $TMPDIR/tmp) precisely
 * BECAUSE it must be a genuinely untrusted directory when this runs: /tmp
 * itself commonly ends up trusted after enough manual testing (trust
 * cascades to subdirectories of an already-trusted ancestor - confirmed
 * live), which would make the CLI skip the dialog entirely and this test
 * would either wrongly fail or silently sail past the one thing it exists
 * to check. A fresh random folder directly under $HOME has no such
 * ancestor.
 *
 * Run directly: `bash tests/run.sh --live`, or standalone via the same
 * convention every other test file here follows:
 * `set -a && source tests/.env.testing; set +a; php tests/test_claude_trust_prompt_live.php`
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\PromptInteractionService;
use HostAgent\Services\PromptParser;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\SidecarStore;

const REAL_TMUX_SOCKET_LIVE_TRUST = '/tmp/tmux-1000/default';

if (Config::tmux_socket() === REAL_TMUX_SOCKET_LIVE_TRUST || Config::tmux_socket() === '') {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket (or is empty). Check tests/.env.testing.\n");
    exit(1);
}

// Never Config::claude_bin() here - that resolves to the fake_claude
// fixture stand-in in this test environment (see tests/.env.testing's own
// CLAUDE_BIN), and this file's whole point is exercising the REAL binary.
// Overridable for a non-standard install; defaults to PATH resolution,
// same as a real production launch.
$realClaudeBin = (string)(getenv('SESSIONEER_LIVE_CLAUDE_BIN') ?: 'claude');
$foundPath = trim((string)shell_exec('command -v ' . escapeshellarg($realClaudeBin) . ' 2>/dev/null'));

if ($foundPath === '') {
    echo "SKIP: real '{$realClaudeBin}' binary not found on PATH - nothing to smoke-test here.\n";
    exit(0);
}

$home = (string)getenv('HOME');

if ($home === '' || !is_dir($home)) {
    echo "SKIP: \$HOME is not set or not a real directory - can't place a genuinely untrusted test folder.\n";
    exit(0);
}

$liveTestDir = $home . '/.sessioneer-live-trust-smoke-' . bin2hex(random_bytes(6));
$fixtureSidecarDir = sys_get_temp_dir() . '/sessioneer-test-live-trust-sidecars-' . bin2hex(random_bytes(4));
putenv("SIDECAR_DIR={$fixtureSidecarDir}");
mkdir($fixtureSidecarDir, 0700, true);
mkdir($liveTestDir, 0700, true);

$sessionName = 'cc-live-trust-smoke-' . getmypid();

try {
    $spawn = TmuxService::tmux_run([
        'new-session', '-d', '-s', $sessionName, '-c', $liveTestDir,
        '-x', '200', '-y', '150', $realClaudeBin,
    ]);
    assert_equal(0, $spawn['exit'], 'live trust smoke: spawned the real claude binary in a fresh, never-before-trusted directory');

    // Give the real CLI a moment to actually render its startup dialog -
    // unlike every other test file's fake_claude/cat stand-in, this is a
    // genuine process starting up, so a bounded poll is used rather than
    // one fixed sleep.
    $prompt = null;
    $detectDeadline = microtime(true) + 10;

    while (microtime(true) < $detectDeadline) {
        usleep(300000);
        $prompt = PromptParser::parse_blocking_prompt(TmuxService::tmux_capture_pane($sessionName));

        if ($prompt !== null) {
            break;
        }
    }

    assert_true($prompt !== null, "live trust smoke: the REAL claude CLI's startup dialog is detected as a blocking prompt");
    assert_equal(true, $prompt['is_folder_trust'] ?? null, 'live trust smoke: detected as the folder-trust dialog specifically');

    $labels = array_column($prompt['options'] ?? [], 'label');
    assert_true(in_array('No, exit', $labels, true), 'live trust smoke: "No, exit" is offered');
    assert_true(in_array('Yes, I trust this folder', $labels, true), 'live trust smoke: "Yes, I trust this folder" is offered');

    // answer_prompt() requires a sidecar to count as a "currently active
    // managed session" (see TmuxService::list_tracked_tmux_sessions()),
    // same as every other answer_prompt() test in this suite.
    SidecarStore::write_sidecar($sessionName, ['workdir' => $liveTestDir, 'spawned_at' => time()]);

    $declineOption = null;

    foreach ($prompt['options'] ?? [] as $opt) {
        if ($opt['label'] === 'No, exit') {
            $declineOption = $opt['number'];
            break;
        }
    }

    assert_true($declineOption !== null, 'live trust smoke setup: found "No, exit"\'s option number');

    $answer = PromptInteractionService::answer_prompt($sessionName, $declineOption ?? 0);
    assert_true($answer['ok'] ?? false, 'live trust smoke: answer_prompt() succeeds against the REAL live dialog');

    // Declining trust exits Claude Code outright - the real process (and
    // therefore this tmux session, since it was the pane's only command)
    // should be gone shortly after. Confirms the actual Down-arrow+Enter
    // keys answer_prompt() sends for this shape (see
    // PromptInteractionService.php's own docblock) really do move the
    // REAL CLI's selection and confirm it - not just that our own fake_
    // claude/cat stand-in echoes them back, which is all every other
    // answer_prompt() test in this suite can prove.
    $stillThere = true;
    $exitDeadline = microtime(true) + 5;

    while (microtime(true) < $exitDeadline) {
        usleep(200000);
        $stillThere = TmuxService::tmux_run(['has-session', '-t', $sessionName])['exit'] === 0;

        if (!$stillThere) {
            break;
        }
    }

    assert_true(!$stillThere, 'live trust smoke: declining trust via answer_prompt() actually exits the real process (session ends)');
} finally {
    TmuxService::tmux_run(['kill-session', '-t', $sessionName]);
    SidecarStore::delete_sidecar($sessionName);
    array_map('unlink', glob("{$fixtureSidecarDir}/*") ?: []);
    @rmdir($fixtureSidecarDir);
    @rmdir($liveTestDir);
}

test_exit();
