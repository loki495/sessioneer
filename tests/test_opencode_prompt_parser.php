<?php
declare(strict_types=1);

/**
 * Exercises OpenCodePromptParser: the structural "is the TUI blocked at all"
 * gate (is_blocked - bottom-anchored, ignores scrollback) and the modal
 * decoder (parse_blocking_prompt -> permission / question modal), against real
 * captured pane fixtures.
 *
 * NOTE on scope: the app does NOT trust the pane for PERMISSION surfacing any
 * more (a pane footer can be a STALE resolved dialog - found live 2026-08-25,
 * the "feasibility" session showed a permission footer while actively
 * working). Permissions come authoritatively from the Sessioneer OpenCode plugin via
 * PermissionStore (see test_opencode_permission_store.php). This test covers
 * the parser's OWN correctness: the is_blocked() gate, and that the modal
 * decoder still works for the pane-derived QUESTION fallback.
 */

require __DIR__ . '/lib/assert.php';
require dirname(__DIR__) . '/host-agent/lib/Sessions.php';

use HostAgent\Services\Config;
use HostAgent\Services\OpenCodePromptParser;

$realTmuxSocket = '/tmp/tmux-' . getmyuid() . '/default';

$fixtureSidecarDir = sys_get_temp_dir() . '/sessioneer-test-ocpp-sidecars-' . bin2hex(random_bytes(4));
putenv("SIDECAR_DIR={$fixtureSidecarDir}");

if (Config::tmux_socket() === $realTmuxSocket) {
    fwrite(STDERR, "REFUSING TO RUN: TMUX_SOCKET resolves to the real host socket. Check tests/.env.testing.\n");
    exit(1);
}

mkdir($fixtureSidecarDir, 0700, true);

try {
    $permPane = (string)file_get_contents(__DIR__ . '/fixtures/opencode_permission_prompt_pane.txt');
    $alwaysPane = (string)file_get_contents(__DIR__ . '/fixtures/opencode_always_allow_prompt_pane.txt');
    $scrollbackPane = (string)file_get_contents(__DIR__ . '/fixtures/opencode_permission_pane_with_scrollback.txt');

    // --- is_blocked(): structural detection, bottom-anchored ---
    assert_true(OpenCodePromptParser::is_blocked($permPane), 'is_blocked: true for a permission-dialog pane');
    assert_true(OpenCodePromptParser::is_blocked($alwaysPane), 'is_blocked: true for the Always-allow confirmation pane');
    // A scrollback-polluted pane is STILL blocked - the live dialog sits at the
    // bottom; the diff/transcript text above it must not flip the verdict.
    assert_true(OpenCodePromptParser::is_blocked($scrollbackPane), 'is_blocked: true even with scrollback pollution above the dialog');
    assert_equal(false, OpenCodePromptParser::is_blocked("some idle output\nwith no modal footer\n"), 'is_blocked: false when there is no modal footer at all');

    // --- parse_blocking_prompt: permission shape ---
    $parsed = OpenCodePromptParser::parse_blocking_prompt($permPane);
    assert_equal('permission', $parsed['tool_name'] ?? null, 'parse_blocking_prompt: detects the permission modal');
    assert_equal('Access external directory ~/dotfiles/claude/agents', $parsed['question'] ?? null, 'parse_blocking_prompt: permission question is the "← Access ..." line');
    $labels = array_column($parsed['options'], 'label', 'number');
    assert_equal('Allow once', $labels[1] ?? null, 'parse_blocking_prompt: permission option 1 is Allow once');
    assert_equal('Allow always', $labels[2] ?? null, 'parse_blocking_prompt: permission option 2 is Allow always');
    assert_equal('Reject', $labels[3] ?? null, 'parse_blocking_prompt: permission option 3 is Reject');

    // Always-allow confirmation: options derived from ITS tab bar (Confirm/Cancel)
    $parsed2 = OpenCodePromptParser::parse_blocking_prompt($alwaysPane);
    assert_equal('permission', $parsed2['tool_name'] ?? null, 'parse_blocking_prompt: detects the Always-allow confirmation modal');
    $labels2 = array_column($parsed2['options'], 'label', 'number');
    assert_equal('Confirm', $labels2[1] ?? null, 'parse_blocking_prompt: Always-allow option 1 is Confirm');
    assert_equal('Cancel', $labels2[2] ?? null, 'parse_blocking_prompt: Always-allow option 2 is Cancel');

    // Scrollback-polluted pane: the anchored dialog (not the pasted diff above)
    // is what gets parsed - the question must come from the real bottom modal.
    $parsedS = OpenCodePromptParser::parse_blocking_prompt($scrollbackPane);
    assert_true($parsedS !== null, 'parse_blocking_prompt: parses a scrollback-polluted pane');
    assert_equal('permission', $parsedS['tool_name'] ?? null, 'parse_blocking_prompt: scrollback pane still resolves to the permission modal');

    // --- non-modal panes -> null ---
    assert_equal(null, OpenCodePromptParser::parse_blocking_prompt("plain working output\nno footer\n"), 'parse_blocking_prompt: null when not structurally blocked');

    // --- is_blocked / parse consistency: never "blocked" off a bare capture ---
    assert_equal(false, OpenCodePromptParser::is_blocked(''), 'is_blocked: false for empty capture');
} finally {
    array_map('unlink', glob("{$fixtureSidecarDir}/*") ?: []);
    @rmdir($fixtureSidecarDir);
}

test_exit();
