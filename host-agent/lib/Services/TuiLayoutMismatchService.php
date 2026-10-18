<?php

declare(strict_types=1);

namespace HostAgent\Services;

use HostAgent\Stores\GlobalStateStore;

/**
 * Records (and surfaces on the dashboard's health box) a real mismatch
 * between the option label PromptInteractionService::answer_prompt()
 * expected at some position - built by PromptParser::
 * build_options_from_permission_suggestions() from the PermissionRequest
 * hook payload - and the label a fresh pane-scrape actually found there.
 * A single inline "Refusing: ..." error on the answer_prompt.php response
 * is easy to miss or forget about; this makes the underlying "Claude
 * Code's permission-menu layout doesn't match what this app assumes"
 * problem visible dashboard-wide until the user has looked at it, the same
 * way an uninstalled hook or an unreachable OpenCode serve already are
 * (see PushHealthService::health_check()).
 *
 * One global slot, not one per session - this is a signal about this
 * app's own assumptions being stale against whatever Claude Code version
 * is running, not about any single session, so the most recent occurrence
 * is all that matters.
 */
class TuiLayoutMismatchService
{
    private const STATE_KEY = 'tui_layout_mismatch';
    private const STATE_VERSION = 2;

    /**
     * How long a recorded mismatch keeps failing the health check before
     * aging out on its own - long enough that the user won't miss it across
     * a normal usage gap, short enough that a one-off Claude Code release
     * hiccup already noticed and dismissed doesn't nag forever. No
     * "acknowledge"/dismiss action exists (yet) - the health box has no
     * per-check dismiss mechanism today, see HealthBoxView.
     */
    private const STALE_AFTER_SECONDS = 7 * 24 * 60 * 60;

    public static function record(string $sessionName, string $toolName, string $expectedLabel, string $realLabel): void
    {
        GlobalStateStore::write(self::STATE_KEY, [
            'version' => self::STATE_VERSION,
            'session' => $sessionName,
            'tool_name' => $toolName,
            'expected_label' => $expectedLabel,
            'real_label' => $realLabel,
            'detected_at' => time(),
        ]);
    }

    /**
     * @return array{key:string, section:string, label:string, ok:bool, detail:?string}
     */
    public static function health_check(): array
    {
        $state = GlobalStateStore::read(self::STATE_KEY);
        $check = ['key' => 'tui_layout_mismatch', 'section' => 'Claude Code', 'label' => 'Permission-menu layout'];

        if ($state === null) {
            return $check + ['ok' => true, 'detail' => null];
        }

        // Version 1 recorded the now-fixed hook-suggestion-versus-live-menu
        // mismatch. Only warnings produced by the label-aware flow remain
        // actionable after that fix is installed.
        if (($state['version'] ?? null) !== self::STATE_VERSION) {
            return $check + ['ok' => true, 'detail' => null];
        }

        $detectedAt = is_int($state['detected_at'] ?? null) ? $state['detected_at'] : 0;

        if (time() - $detectedAt > self::STALE_AFTER_SECONDS) {
            return $check + ['ok' => true, 'detail' => null];
        }

        $session = is_string($state['session'] ?? null) ? $state['session'] : 'a session';
        $expected = is_string($state['expected_label'] ?? null) ? $state['expected_label'] : '?';
        $real = is_string($state['real_label'] ?? null) ? $state['real_label'] : '?';

        return $check + [
            'ok' => false,
            'detail' => "Mismatch detected on {$session}: expected \"{$expected}\", pane showed \"{$real}\" - Claude Code's permission-prompt layout may have changed. An answer was refused rather than sent to the wrong option.",
        ];
    }
}
