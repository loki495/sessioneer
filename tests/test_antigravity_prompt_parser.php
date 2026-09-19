<?php
declare(strict_types=1);

/**
 * Exercises AntigravityPromptParser::parse_blocking_prompt() against real
 * captured pane shapes (see fixtures/antigravity_permission_prompt_pane.txt,
 * a byte-for-byte real `tmux capture-pane` output from a live reproduction
 * 2026-08-24), plus PromptInteractionService::answer_prompt()'s agent
 * routing end to end against a real fixture tmux pane.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\AntigravityPromptParser;
use HostAgent\Services\Config;
use HostAgent\Services\PromptInteractionService;
use HostAgent\Services\TmuxService;
use HostAgent\Stores\SessionStatusStore;
use HostAgent\Stores\SidecarStore;

$realTmuxSocket = '/tmp/tmux-' . getmyuid() . '/default';

$fixtureSidecarDir = sys_get_temp_dir() . '/sessioneer-test-agy-prompt-sidecars-' . bin2hex(random_bytes(4));
putenv("SIDECAR_DIR={$fixtureSidecarDir}");

if (Config::tmux_socket() === $realTmuxSocket) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket. Check tests/.env.testing.\n");
    exit(1);
}

mkdir($fixtureSidecarDir, 0700, true);

try {
    // --- a real captured pane, including a label that wraps across 2 printed lines ---
    // (found live 2026-08-24: "Yes, and always allow in this conversation for
    // commands that start with 'echo'" prints as two lines - this fixture
    // file is that exact real capture, not hand-written)

    $realPane = (string)file_get_contents(__DIR__ . '/fixtures/antigravity_permission_prompt_pane.txt');
    $parsed = AntigravityPromptParser::parse_blocking_prompt($realPane);

    assert_true($parsed !== null, 'parse_blocking_prompt: detects a real permission-prompt pane');
    assert_equal('Do you want to proceed?', $parsed['question'] ?? null, 'parse_blocking_prompt: question text');
    assert_contains('Requesting permission for:', $parsed['context'] ?? '', 'parse_blocking_prompt: context includes the leading label');
    assert_contains('echo hello-sessioneer-test', $parsed['context'] ?? '', 'parse_blocking_prompt: context includes the actual command');
    assert_equal(4, count($parsed['options'] ?? []), 'parse_blocking_prompt: all 4 options found, not cut short at the wrapped one');
    assert_equal('Yes', $parsed['options'][0]['label'] ?? null, 'parse_blocking_prompt: option 1 label');
    assert_equal(
        "Yes, and always allow in this conversation for commands that start with 'echo'",
        $parsed['options'][1]['label'] ?? null,
        'parse_blocking_prompt: option 2\'s wrapped continuation line is joined onto its label, not dropped or treated as the end of the list'
    );
    assert_equal(
        "Yes, and always allow for commands that start with 'echo' (Persist to settings.json)",
        $parsed['options'][2]['label'] ?? null,
        'parse_blocking_prompt: option 3\'s own wrapped continuation is joined correctly too'
    );
    assert_equal('No', $parsed['options'][3]['label'] ?? null, 'parse_blocking_prompt: option 4 (after the wrapped ones) still parses correctly');
    assert_equal(false, $parsed['multi_question'] ?? null, 'parse_blocking_prompt: never a multi-question shape - Antigravity has no AskUserQuestion-style tab bar');
    assert_equal(false, $parsed['is_folder_trust'] ?? null, 'parse_blocking_prompt: never the folder-trust shape - not a prompt this parser recognizes');

    // --- Antigravity 1.2.1: dynamic question ("Run this command?") and 2 options ---

    $realPaneV121 = (string)file_get_contents(__DIR__ . '/fixtures/antigravity_permission_prompt_pane_v121.txt');
    $parsedV121 = AntigravityPromptParser::parse_blocking_prompt($realPaneV121);

    assert_true($parsedV121 !== null, 'parse_blocking_prompt: detects agy 1.2.1 command permission prompt');
    assert_equal('Run this command?', $parsedV121['question'] ?? null, 'parse_blocking_prompt: question text is Run this command?');
    assert_contains('Requesting permission for:', $parsedV121['context'] ?? '', 'parse_blocking_prompt: context includes Requesting permission for:');
    assert_contains('tmux capture-pane', $parsedV121['context'] ?? '', 'parse_blocking_prompt: context includes the command');
    assert_equal(2, count($parsedV121['options'] ?? []), 'parse_blocking_prompt: exactly 2 options found');
    assert_equal('Yes, run command', $parsedV121['options'][0]['label'] ?? null, 'parse_blocking_prompt: option 1 label');
    assert_equal('No, cancel', $parsedV121['options'][1]['label'] ?? null, 'parse_blocking_prompt: option 2 label');

    // --- Antigravity 1.2.1: file creation ("Allow creation of this file?") ---

    $fileCreatePane = (string)file_get_contents(__DIR__ . '/fixtures/antigravity_file_creation_prompt_pane_v121.txt');
    $parsedFileCreate = AntigravityPromptParser::parse_blocking_prompt($fileCreatePane);

    assert_true($parsedFileCreate !== null, 'parse_blocking_prompt: detects agy 1.2.1 file creation permission prompt');
    assert_equal('Allow creation of this file?', $parsedFileCreate['question'] ?? null, 'parse_blocking_prompt: question text is Allow creation of this file?');
    assert_equal(2, count($parsedFileCreate['options'] ?? []), 'parse_blocking_prompt: 2 options for file creation');
    assert_equal('Yes, allow creation', $parsedFileCreate['options'][0]['label'] ?? null, 'parse_blocking_prompt: file creation option 1');
    assert_equal('No, deny creation', $parsedFileCreate['options'][1]['label'] ?? null, 'parse_blocking_prompt: file creation option 2');

    // --- is_prompt_question checks ---

    assert_true(AntigravityPromptParser::is_prompt_question('Run this command?'), 'is_prompt_question: Run this command?');
    assert_true(AntigravityPromptParser::is_prompt_question('Allow creation of this file?'), 'is_prompt_question: Allow creation of this file?');
    assert_true(AntigravityPromptParser::is_prompt_question('Accept this file edit?'), 'is_prompt_question: Accept this file edit?');
    assert_true(AntigravityPromptParser::is_prompt_question('Allow access to this URL?'), 'is_prompt_question: Allow access to this URL?');
    assert_true(AntigravityPromptParser::is_prompt_question('Allow calling this tool?'), 'is_prompt_question: Allow calling this tool?');
    assert_true(AntigravityPromptParser::is_prompt_question('Do you want to proceed?'), 'is_prompt_question: Do you want to proceed?');
    assert_equal(false, AntigravityPromptParser::is_prompt_question('What other files reference this one?'), 'is_prompt_question: non-approval question');
    assert_equal(false, AntigravityPromptParser::is_prompt_question('Just some prose with no question mark'), 'is_prompt_question: statement');

    // --- no recognized prompt line at all -> null, not a guess ---

    assert_equal(null, AntigravityPromptParser::parse_blocking_prompt("Just some normal idle pane\n> \n"), 'parse_blocking_prompt: null when the pane shows no recognized prompt at all');

    // --- the question line present but no numbered options after it -> null ---

    assert_equal(null, AntigravityPromptParser::parse_blocking_prompt("Do you want to proceed?\n\nsomething unrelated\n"), 'parse_blocking_prompt: null when nothing that looks like a numbered option follows the question (Do you want to proceed?)');
    assert_equal(null, AntigravityPromptParser::parse_blocking_prompt("Run this command?\n\nsomething unrelated\n"), 'parse_blocking_prompt: null when nothing that looks like a numbered option follows the question (Run this command?)');

    // --- end-to-end: PromptInteractionService::answer_prompt() routes an antigravity session through this parser, not Claude's ---

    $sessionName = 'ag-test-answerprompt-' . bin2hex(random_bytes(3));
    $create = TmuxService::tmux_run(['new-session', '-d', '-s', $sessionName, '-c', sys_get_temp_dir(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $create['exit'], 'answer_prompt end-to-end test setup: created a live fixture tmux pane');
    TmuxService::tmux_run(['send-keys', '-t', $sessionName, '-l', $realPane]);

    SidecarStore::write_sidecar($sessionName, ['workdir' => '/fixture', 'spawned_at' => time(), 'agent_session_id' => 'conv-x', 'spawned_by_app' => true, 'agent' => 'antigravity']);
    SessionStatusStore::update_status($sessionName, ['status' => 'idle']);

    $answerResult = PromptInteractionService::answer_prompt($sessionName, 1);
    assert_equal(true, $answerResult['ok'], 'answer_prompt: recognizes an antigravity session\'s pane-scraped prompt and accepts a valid option');

    $rejectResult = PromptInteractionService::answer_prompt($sessionName, 99);
    assert_equal(false, $rejectResult['ok'], 'answer_prompt: still rejects an option number the (antigravity) prompt never offered');

    TmuxService::tmux_run(['kill-session', '-t', $sessionName]);
    SidecarStore::delete_sidecar($sessionName);
    SessionStatusStore::delete_status($sessionName);

    // --- end-to-end with agy 1.2.1 2-option format ---

    $sessionNameV121 = 'ag-test-answerprompt-v121-' . bin2hex(random_bytes(3));
    $createV121 = TmuxService::tmux_run(['new-session', '-d', '-s', $sessionNameV121, '-c', sys_get_temp_dir(), 'bash', '-c', 'stty -echo; exec cat']);
    assert_equal(0, $createV121['exit'], 'answer_prompt v121 test setup: created live fixture tmux pane');
    TmuxService::tmux_run(['send-keys', '-t', $sessionNameV121, '-l', $realPaneV121]);

    SidecarStore::write_sidecar($sessionNameV121, ['workdir' => '/fixture', 'spawned_at' => time(), 'agent_session_id' => 'conv-v121', 'spawned_by_app' => true, 'agent' => 'antigravity']);
    SessionStatusStore::update_status($sessionNameV121, ['status' => 'idle']);

    $answerResultV121 = PromptInteractionService::answer_prompt($sessionNameV121, 2);
    assert_equal(true, $answerResultV121['ok'], 'answer_prompt: accepts option 2 on agy 1.2.1 prompt');

    $rejectResultV121 = PromptInteractionService::answer_prompt($sessionNameV121, 3);
    assert_equal(false, $rejectResultV121['ok'], 'answer_prompt: rejects option 3 on 2-option agy 1.2.1 prompt');

    TmuxService::tmux_run(['kill-session', '-t', $sessionNameV121]);
    SidecarStore::delete_sidecar($sessionNameV121);
    SessionStatusStore::delete_status($sessionNameV121);
} finally {
    array_map('unlink', glob("{$fixtureSidecarDir}/*") ?: []);
    @rmdir($fixtureSidecarDir);
}

test_exit();
