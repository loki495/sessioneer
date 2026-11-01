<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * This app's own manual/accept edits/plan/auto permission-mode vocabulary,
 * and translation to/from Claude Code's own two DIFFERENT representations
 * of the same four modes (its internal PermissionMode enum values, seen in
 * hook payloads/transcript lines, and its human-readable status-line
 * phrasing, seen in a live pane) - split out of PromptParser.php
 * (which mixed pane-title reading, prompt
 * detection/parsing, AND this vocabulary translation, reached into by both
 * PromptInteractionService::set_mode() and SessionLifecycleService::create_agent_session()) into
 * its own class, since none of it is actually pane-parsing logic - it's a
 * closed, fixed value space this app defines, not something scraped from a
 * pane the way a blocking prompt's content is. Constant/method names kept
 * identical to their old PromptParser:: ones - a mechanical move, not a
 * rename, to keep this refactor's diff reviewable.
 */
class PermissionMode
{
    /**
     * Every mode Claude Code's own Shift+Tab cycle visits, in the exact order
     * it cycles through them, mapped to the exact phrase it prints in its
     * bottom status line for each - all taken from a real running
     * session, not guessed. Three say "<mode> mode on"; "accept edits" is its
     * own inconsistency and just says "accept edits on" (no "mode") - caught
     * by testing against a real capture rather than a hand-written one, which
     * a plausible-looking regex-guess would have silently missed.
     */
    // dontAsk is deliberately absent: its status-line phrase has not been captured from a real session.
    public const CLAUDE_CODE_MODE_STATUS_PHRASES = [
        'manual' => 'manual mode on',
        'accept edits' => 'accept edits on',
        'plan' => 'plan mode on',
        'auto' => 'auto mode on',
    ];

    /**
     * Every hook payload's `permission_mode` field reports Claude Code's own
     * internal PermissionMode enum value - the same one already seen in a
     * transcript's `{"type":"permission-mode","permissionMode":...}` lines
     * and in a `setMode` permission_suggestion (`{"type":"setMode","mode":
     * "acceptEdits"}`) - not this app's own manual/accept edits/plan/auto
     * vocabulary (CLAUDE_CODE_MODE_STATUS_PHRASES's keys, also what
     * TranscriptView::MODE_OPTIONS and PromptInteractionService::set_mode() expect).
     * "default" is that enum's name for what this app calls "manual".
     */
    public const HOOK_PERMISSION_MODE_MAP = [
        'default' => 'manual',
        'acceptEdits' => 'accept edits',
        'plan' => 'plan',
        'auto' => 'auto',
        // Only reachable at launch (--mode dontAsk): not in Shift+Tab's cycle, so it has
        // no status-line phrase in CLAUDE_CODE_MODE_STATUS_PHRASES and tmux set_mode() refuses it.
        'dontAsk' => 'dontAsk',
    ];

    /**
     * Normalizes a hook payload's raw `permission_mode` value to this app's
     * own mode vocabulary - null for anything unrecognized (e.g.
     * "bypassPermissions", a future enum value this map hasn't seen yet)
     * rather than guessing, so a caller merging this into SessionStatusStore
     * can just omit the `mode` key entirely and leave whatever mode was
     * last known in place.
     */
    public static function normalize_hook_permission_mode(mixed $raw): ?string
    {
        return is_string($raw) ? (self::HOOK_PERMISSION_MODE_MAP[$raw] ?? null) : null;
    }

    /**
     * Reads the current permission mode straight from Claude Code's own
     * bottom status line (e.g. "⏸ manual mode on · ← for agents" or "⏵⏵ auto
     * mode on (shift+tab to cycle) · ← for agents") - there's no other way to
     * learn it live short of parsing the same status bar a human would read.
     * Returns null if the session isn't currently showing that line at all
     * (e.g. it's showing a blocking prompt instead).
     */
    public static function parse_current_mode(string $paneContent): ?string
    {
        foreach (self::CLAUDE_CODE_MODE_STATUS_PHRASES as $mode => $phrase) {
            if (str_contains($paneContent, $phrase)) {
                return $mode;
            }
        }

        return null;
    }
}
