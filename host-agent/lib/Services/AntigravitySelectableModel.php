<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * The fixed 7-row vocabulary behind Antigravity's real `/model` picker, in
 * the EXACT order it renders them - checked against two
 * disposable scratch `agy` sessions (never the real tracked session), same
 * "confirm against the real thing" discipline SelectableModel.php's own
 * Claude Code vocabulary already used.
 *
 * UNLIKE Claude Code's own picker (see SelectableModel.php), Antigravity's
 * has no session-only confirm key - there is only one "enter Select"
 * action, and it always overwrites the ACCOUNT-WIDE default model, applying
 * to every future `agy` session including ones started by hand outside this
 * app. Switching the model inside one throwaway session,
 * then killing it and starting a brand new unrelated one, showed the new
 * session picked up the switched model rather than resetting - so
 * PromptInteractionService::set_antigravity_model() below is knowingly a
 * global-default switch, not a per-session one (a deliberate
 * decision, not an oversight).
 */
class AntigravitySelectableModel
{
    /**
     * Row order matches the real /model picker exactly. Each row also
     * carries whatever reasoning-effort level the picker last remembered
     * for that specific model on its own (switching models
     * changes the Effort slider's position too, per-model) - this app
     * doesn't touch Effort at all, only ever confirms whatever effort the
     * picker already has selected for the target row.
     */
    public const PICKER_OPTIONS = [
        'gemini-3.7-flash' => 'Gemini 3.7 Flash',
        'gemini-3.6-flash' => 'Gemini 3.6 Flash',
        'gemini-3.5-flash' => 'Gemini 3.5 Flash',
        'gemini-3.1-pro' => 'Gemini 3.1 Pro',
        'claude-sonnet-4.6-thinking' => 'Claude Sonnet 4.6 (Thinking)',
        'claude-opus-4.6-thinking' => 'Claude Opus 4.6 (Thinking)',
        'gpt-oss-120b-medium' => 'GPT-OSS 120B (Medium)',
    ];

    /**
     * Antigravity has no hook-fed statusline JSON to read the active model
     * from the way Claude Code does (see TranscriptService::
     * find_latest_model()) - the only place it's ever shown is the live
     * pane's own bottom-right footer, e.g. "Gemini 3.7 Flash · high"
     * (seen across three different picked models:
     * Gemini 3.7 Flash, Gemini 3.6 Flash, and back to Gemini 3.7 Flash at
     * a different effort - the "<label> · <effort>" shape held every
     * time). Same str_contains-against-known-phrases discipline as
     * PermissionMode::parse_current_mode() - safe here too since no
     * PICKER_OPTIONS label is a substring of another one. Returns null
     * (not a guess) if the pane doesn't contain any recognized label at
     * all, e.g. a prompt or the /model picker itself is currently covering
     * the footer.
     */
    public static function parse_current_model(string $paneContent): ?string
    {
        foreach (self::PICKER_OPTIONS as $key => $label) {
            if (str_contains($paneContent, $label)) {
                return $key;
            }
        }

        return null;
    }
}
