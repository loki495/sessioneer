<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Detects a blocked OpenCode TUI prompt (a modal overlay) from a pane capture.
 *
 * Foolproof-first: string-scanning the WHOLE pane for
 * keywords is unreliable because the pane's scrollback/transcript can contain
 * arbitrary assistant output that happens to mention "Permission required",
 * "Allow once", or even a pasted source diff (a git-diff paste sitting above the real dialog is
 * turned by a naive top-down scan into a nonsense "question"). So the approach is two
 * stage:
 *
 *   1. is_blocked(): decide from STRUCTURE whether a modal is up at all. The
 *      active modal always renders its interaction footer as the last content
 *      line in the capture (the line above the persistent "• OpenCode x.y.z"
 *      status/version line). When the TUI is working or idle, the bottom of
 *      the capture is ordinary output - no such footer. This never consults
 *      scrollback, because the modal is bottom-anchored and the visible
 *      capture's bottom is always the live frame, not history.
 *   2. Only once blocked, extract the modal text from the region between the
 *      modal's heading and that footer - never above it.
 *
 * Two modal shapes:
 *   - permission: "△ Permission required"/"△ Always allow" dialog over a
 *     tab bar whose footer is "⇆ select  enter confirm", with the option
 *     labels (Allow once / Allow always / Reject, or Confirm / Cancel)
 *     inline on the same footer line.
 *   - question:  a numbered-options question over a footer of
 *     "⇆ tab  enter submit  esc dismiss", options listed as "N. ..." above.
 */
class OpenCodePromptParser
{
    /** Interaction-footer markers that identify a blocked modal. */
    private const FOOTER_MARKERS = ['enter confirm', 'enter submit', 'esc dismiss'];

    /**
     * Structural "is the TUI blocked on a modal at all" check. Looks ONLY at
     * the last content line of the capture (the line just above the trailing
     * "• OpenCode x.y.z" version/status line): if it's an interaction footer
     * the TUI is waiting on a modal; otherwise it's working or idle. This is
     * bottom-anchored, so scrollback above (past output, pasted text) can
     * never cause a false positive.
     */
    public static function is_blocked(string $paneContent): bool
    {
        return self::interaction_footer_line($paneContent) !== null;
    }

    /**
     * @return array{question:string, context:string, options:array<int, array{number:int, label:string}>, multi_question:bool, tool_name?:string}|null
     */
    public static function parse_blocking_prompt(string $paneContent): ?array
    {
        // Never look for a prompt unless the TUI is structurally blocked.
        if (!self::is_blocked($paneContent)) {
            return null;
        }

        // Which modal is it? The footer line's hint distinguishes them:
        //   "⇆ tab  enter submit  esc dismiss" -> question
        //   "⇆ select  enter confirm"           -> permission
        $footer = self::interaction_footer_line($paneContent);
        if ($footer === null) {
            return null;
        }

        if (strpos($footer, 'enter submit') !== false) {
            return self::parse_question_modal($paneContent, $footer);
        }

        return self::parse_permission_modal($paneContent, $footer);
    }

    /**
     * Returns the last content line of the capture if it is an interaction
     * footer, else null. The persistent "• OpenCode x.y.z" line is always the
     * very last line; the footer (if any) is the line above it. Guards for a
     * bare footer with a trailing gutter char, and skips trailing blank lines.
     */
    private static function interaction_footer_line(string $paneContent): ?string
    {
        $lines = explode("\n", $paneContent);
        // Walk backward past trailing blank lines AND the persistent "• OpenCode
        // x.y.z" status line (which is always the last content line and carries
        // no interaction hint) to the first real content line above it.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            $line = rtrim($lines[$i]);
            if (trim($line) === '') {
                continue;
            }
            // Skip the persistent OpenCode version/status line (the "• OpenCode
            // 1.18.21" footer) - it is not an interaction hint, and it is what
            // sits BELOW the real modal footer.
            if (preg_match('#•+\s*OpenCode\s+[\d.]+#u', $line)) {
                continue;
            }
            // That content line is the interaction footer only if it carries
            // one of the modal's control hints.
            if (self::line_has_marker($line)) {
                return $line;
            }
            // The last content line above the status is not a footer (ordinary
            // output) - not blocked. Do NOT keep scanning further up: only the
            // line directly above the status/footer region is authoritative.
            return null;
        }

        return null;
    }

    private static function line_has_marker(string $line): bool
    {
        foreach (self::FOOTER_MARKERS as $marker) {
            if (strpos($line, $marker) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalizes the capture: strips the TUI gutter ("┃ "), trims each line,
     * and drops blank lines, so the remaining code can compare content without
     * re-handling the box-drawing chrome. Also records the source line index of
     * the interaction footer so the modal region can be bounded from below.
     *
     * @return array{lines:array<int, string>, footerIndex:int}
     */
    private static function clean_with_footer(string $paneContent): array
    {
        $raw = explode("\n", $paneContent);
        $clean = [];
        $footerIndex = -1;

        foreach ($raw as $idx => $line) {
            $stripped = preg_replace('/^\s*[┃│|]\s*/u', '', $line);
            $stripped = trim($stripped ?? $line);
            if ($stripped === '') {
                continue;
            }
            $clean[] = ['line' => $stripped, 'src' => $idx];
        }

        // The footer is the last clean line that carries a marker.
        for ($i = count($clean) - 1; $i >= 0; $i--) {
            if (self::line_has_marker($clean[$i]['line'])) {
                $footerIndex = $i;
                break;
            }
        }

        return ['lines' => array_column($clean, 'line'), 'footerIndex' => $footerIndex];
    }

    /**
     * Permission modal: "△ Permission required"/"△ Always allow" dialog, with
     * the option labels inline on the footer line itself (left of the control
     * hints), e.g. "Confirm   Cancel   ⇆ select  enter confirm". The heading
     * and the description/pattern lines sit between the modal's top and the
     * footer; extraction is bounded to that region.
     *
     * @return array{question:string, context:string, options:array<int, array{number:int, label:string}>, multi_question:bool, tool_name:string}
     */
    private static function parse_permission_modal(string $paneContent, string $footer): array
    {
        $parsed = self::clean_with_footer($paneContent);
        $lines = $parsed['lines'];
        $footerIndex = $parsed['footerIndex'];

        // The footer string passed in is the RAW line (still carrying the TUI
        // gutter "┃ "). Strip it, or the gutter char becomes a phantom option.
        $footerLine = trim(preg_replace('/^\s*[┃│|]\s*/u', '', $footer) ?? $footer);

        // Option labels inline on the footer line, before the control hints,
        // separated by runs of 2+ spaces.
        $optionTokens = preg_split('/\s{2,}/u', $footerLine);
        $options = [];
        $number = 1;
        foreach ($optionTokens as $token) {
            $token = trim($token);
            if ($token === '') {
                continue;
            }
            // Stop at the first control-hint token (right of the labels).
            if (preg_match('/^(ctrl\+f|fullscreen|⇆|select|enter\s+confirm|enter\s+submit|esc\s+dismiss|OpenCode\s+[\d.]+|~\/)/u', $token)) {
                break;
            }
            $options[] = ['number' => $number, 'label' => $token];
            $number++;
        }

        // Heading + question + context: scan the modal region ABOVE the
        // footer, bottom-up, for the "△ <heading>" line and the "← Access"
        // line, and stop drifting too far up (the modal is compact).
        $heading = null;
        $headingIndex = null;
        $question = null;
        for ($i = $footerIndex - 1; $i >= 0; $i--) {
            $line = $lines[$i];
            if ($question === null) {
                $qIdx = self::arrow_question($line);
                if ($qIdx !== null) {
                    $question = $qIdx;
                }
            }
            if (str_contains($line, 'Permission required')) {
                $heading = 'Permission required';
                $headingIndex = $i;
                break;
            }
            if (str_contains($line, 'Always allow')) {
                $heading = 'Always allow';
                $headingIndex = $i;
                break;
            }
            if ($footerIndex - $i > 15) {
                break;
            }
        }

        if ($heading === null) {
            return ['question' => $question ?? 'Permission required', 'context' => '', 'options' => $options, 'multi_question' => false, 'tool_name' => 'permission'];
        }

        // Context: lines between the heading and the footer, in reading order
        // (excluding the "← Access" question line and the cwd/version chrome).
        $contextLines = [$heading];
        for ($i = $headingIndex + 1; $i < $footerIndex; $i++) {
            $line = $lines[$i];
            if (self::arrow_question($line) !== null) {
                continue;
            }
            if (preg_match('#^~/#u', $line) || strpos($line, 'OpenCode ') === 0) {
                continue;
            }
            $contextLines[] = $line;
        }
        $context = implode("\n", array_unique($contextLines));

        return [
            'question' => $question ?? $heading,
            'context' => $context,
            'options' => $options !== [] ? $options : [['number' => 1, 'label' => 'Allow once'], ['number' => 2, 'label' => 'Allow always'], ['number' => 3, 'label' => 'Reject']],
            'multi_question' => false,
            'tool_name' => 'permission',
        ];
    }

    /**
     * Question modal: numbered options "N. ..." above a footer of
     * "⇆ tab  enter submit  esc dismiss". Options are the numbered lines; the
     * question is the nearest non-option, non-gutter line above them; bounded
     * below by the footer.
     *
     * @return array{question:string, context:string, options:array<int, array{number:int, label:string}>, multi_question:bool, tool_name:string}
     */
    private static function parse_question_modal(string $paneContent, string $footer): array
    {
        $parsed = self::clean_with_footer($paneContent);
        $lines = $parsed['lines'];
        $footerIndex = $parsed['footerIndex'];

        // Find the numbered options in the region ABOVE the footer (never
        // scan below it, and never past the modal's top - the modal is
        // bounded, so cap the search window to a reasonable height).
        $options = [];
        $firstOptionIndex = null;
        $lastOptionIndex = null;
        $scanStart = max(0, $footerIndex - 25);
        for ($i = $scanStart; $i < $footerIndex; $i++) {
            if (preg_match('/^\s*(\d+)\.\s+(.+)/', $lines[$i], $m) === 1) {
                $options[] = ['number' => (int)$m[1], 'label' => trim($m[2])];
                if ($firstOptionIndex === null) {
                    $firstOptionIndex = $i;
                }
                $lastOptionIndex = $i;
            }
        }

        if ($options === []) {
            return ['question' => 'Waiting on input', 'context' => '', 'options' => $options, 'multi_question' => false, 'tool_name' => 'question'];
        }

        // Question: the nearest non-option, non-gutter line above the first
        // option, bounded by the modal region's top.
        $question = null;
        for ($i = $firstOptionIndex - 1; $i >= 0 && $firstOptionIndex - $i <= 12; $i--) {
            $line = trim($lines[$i]);
            if ($line !== '' && !preg_match('/^\s*\d+\./', $line)) {
                // Skip pure decoration (horizontal rules).
                if (str_repeat('─', 10) === substr($line, 0, 10)) {
                    continue;
                }
                $question = $line;
                break;
            }
        }

        // Context: a couple of lines above the first option (the modal's
        // leading labels/instructions), bounded to the modal region.
        $contextLines = [];
        $contextStart = max(0, $firstOptionIndex - 6);
        for ($i = $contextStart; $i < $firstOptionIndex; $i++) {
            $line = trim($lines[$i]);
            if ($line !== '' && strpos($line, '↑↓') === false && strpos($line, '⇆') === false) {
                $contextLines[] = $line;
            }
        }
        $context = implode("\n", $contextLines);

        return [
            'question' => $question ?? ($contextLines[0] ?? 'Waiting on input'),
            'context' => $context,
            'options' => $options,
            'multi_question' => false,
            'tool_name' => 'question',
        ];
    }

    /**
     * If $line is a "← Access ..." question line, returns the text after the
     * arrow (handling the multibyte arrow glyph), else null.
     */
    private static function arrow_question(string $line): ?string
    {
        $idx = strpos($line, '←');
        if ($idx === false) {
            return null;
        }

        $text = trim(mb_substr($line, $idx + mb_strlen('←')));

        return $text !== '' ? $text : null;
    }
}
