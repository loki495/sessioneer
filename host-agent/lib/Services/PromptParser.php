<?php

declare(strict_types=1);

namespace HostAgent\Services;

/**
 * Everything about reading Claude Code's own pane title and detecting/
 * parsing a blocking interactive prompt from a captured tmux pane's raw
 * text - the pane-scraping half of this app (as opposed to TmuxService,
 * which just runs tmux commands and hands back raw output).
 */
class PromptParser
{
    /**
     * Fallback number of pane lines above the choice list to consider as
     * context, used only when no "●" tool-invocation marker (see
     * parse_blocking_prompt()) is found above the cursor - the trust dialog
     * is the one real prompt shape that has no such marker, and its own
     * explanation fits in ~8 lines. Andres reported a long command/file-
     * content preview showing truncated - found live: this used to be the
     * ONLY boundary, a fixed 15-line window regardless of how tall the actual
     * preview was, silently cutting off anything longer (a multi-line script,
     * a large file being written) with no visible sign anything was missing.
     */
    public const BLOCKING_PROMPT_CONTEXT_WINDOW = 15;

    /**
     * Matches one leading animated spinner glyph (plus trailing whitespace)
     * on Claude Code's pane title while actively working, e.g. "⠂ Fix login
     * bug" or "◐ Fix login bug". Deliberately matched by Unicode general
     * category (\p{So}, "Symbol, other") rather than a specific code-point
     * range: found live 2026-08-12 that Claude Code had switched its
     * spinner from Braille Patterns dots (U+2800-U+28FF, this app's
     * original and only match) to a rotating half-circle from the
     * Geometric Shapes block (U+25D0 ◐/◑/..., confirmed against a real
     * live pane title), silently breaking the working-status detection this
     * pattern used to also back (removed 2026-08-22 - see
     * SessionStatusStore/build_session_entry(), which now gets
     * working/idle/blocked from hook SEQUENCE instead, with no glyph to
     * match at all - clean_pane_title() below is this pattern's only
     * remaining caller). \p{So} already covers both of those blocks plus
     * Block Elements and effectively every other symbol/dingbat-style
     * spinner glyph set in common use (verified: both the old braille dots
     * and the new half-circle are category So) - the actual fix for the
     * root cause (a hardcoded narrow range that breaks every time the CLI's
     * spinner style changes) rather than just widening it to also include
     * this one new range.
     */
    private const SPINNER_GLYPH_PATTERN = '/^\p{So}+\s*/u';

    /**
     * Claude Code sets the terminal title to a short description of the
     * current task, prefixed with an animated spinner glyph while actively
     * working - tmux captures this as pane_title via the standard OSC title
     * escape sequence, no special tmux config needed. Strips the spinner so
     * only the description remains; an empty/spinner-only title (nothing
     * set yet, or a non-Claude process) returns null so callers can fall
     * back to the session name.
     */
    public static function clean_pane_title(string $title): ?string
    {
        $stripped = preg_replace(self::SPINNER_GLYPH_PATTERN, '', $title);
        $title = trim($stripped ?? $title);

        return $title !== '' ? $title : null;
    }

    /**
     * True for a line that's purely decorative box-drawing/rule characters
     * (─│╭╮╰╯┌┐└┘┏┓┗┛━┃ etc.) and whitespace - carries no information of its
     * own, so it's dropped from context rather than shown as a bare line of
     * dashes.
     */
    public static function is_decorative_pane_line(string $line): bool
    {
        return trim($line) !== '' && preg_match('/^[\s\x{2500}-\x{257F}\x{2580}-\x{259F}]*$/u', $line) === 1;
    }

    /**
     * Claude Code renders every interactive choice it needs a human for -
     * the "Do you trust the files in this folder?" prompt on first launch in
     * a new directory, tool-permission approval, etc. - as a numbered option
     * list with a leading "❯" cursor on the selected line. That marker is
     * stable across prompt wording/versions, so it's used as the detection
     * signal rather than matching specific prompt text. Returns the nearest
     * question-like line above the option list (if one is found within a few
     * lines) as a short human-readable reason, or a generic fallback.
     *
     * $question used to require a line ending in "?" within a narrow window,
     * but real prompts wrap their question across lines (verified against a
     * live capture of Claude Code's own trust dialog, where the "?" lands
     * mid-line, not at the end of any single one) - it now looks for a "?"
     * anywhere in a line and truncates there, falling back to the nearest
     * non-blank context line, and only to a generic label if there's truly
     * nothing above the choice list at all.
     *
     * @return array{question:string, context:string, options: array<int, array{number:int, label:string}>, multi_question:bool, is_folder_trust:bool}|null
     */
    public static function parse_blocking_prompt(string $paneContent): ?array
    {
        $lines = explode("\n", $paneContent);
        $choiceIndex = null;
        $unnumberedOptions = false;

        // Scans from the bottom: the ❯ cursor only ever appears on one line
        // per active choice list, but if the pane's visible screen still
        // holds an earlier, already-resolved list above the current one, the
        // most recent one - furthest down - is the one actually still
        // waiting on input.
        for ($i = count($lines) - 1; $i >= 0; $i--) {
            if (preg_match('/^\s*❯\s*\d+[.)]/u', $lines[$i]) === 1) {
                $choiceIndex = $i;
                break;
            }
        }

        // Found live 2026-09-11 (Andres: couldn't start a session in a new
        // folder - the app never saw the trust dialog as blocking at all,
        // and a "confirm" click killed the whole session instead): Claude
        // Code v2.1.269 dropped the leading digit from the initial
        // per-folder trust dialog specifically ("❯ No, exit" /
        // "  Yes, I trust this folder" - verified against a live capture,
        // see tests/fixtures/claude_folder_trust_prompt_pane_v2_1_269.txt),
        // so the digit-anchored scan above never finds it - every other
        // prompt shape (tool permission, AskUserQuestion) kept its numbers.
        // Anchored on this dialog's own distinctive footer
        // ("Enter to confirm · Esc to cancel", vs every numbered prompt's
        // "Enter to select · ↑/↓ to navigate · Esc to cancel") rather than
        // loosening the digit scan to accept any bare "❯ ..." line - the
        // plain chat compose box also renders a bare "❯ " cursor with
        // nothing after it, and that must never be misread as a prompt.
        // Bounded to a few lines above the footer since this dialog's own
        // option list is always short.
        if ($choiceIndex === null) {
            for ($i = count($lines) - 1; $i >= 0; $i--) {
                if (str_contains($lines[$i], 'Enter to confirm') && str_contains($lines[$i], 'Esc to cancel')) {
                    for ($j = $i - 1; $j >= max(0, $i - 8); $j--) {
                        if (preg_match('/^\s*❯\s*\S/u', $lines[$j]) === 1) {
                            $choiceIndex = $j;
                            $unnumberedOptions = true;
                            break;
                        }
                    }

                    break;
                }
            }
        }

        if ($choiceIndex === null) {
            return null;
        }

        // Claude Code prefixes each tool invocation with a "● " marker line
        // (e.g. "● Bash(...)", verified against a real capture) - the actual
        // top of the block being approved, found by scanning up from the
        // choice cursor for the nearest one. Far more reliable than a fixed
        // window: it's exactly as tall as the real content, whether that's 3
        // lines or 300. Only falls back to BLOCKING_PROMPT_CONTEXT_WINDOW for
        // prompt shapes with no such marker (the trust dialog).
        //
        // The scan never crosses a decorative separator line looking for
        // one, PROVIDED a multi-question tab bar ("←  ☐ Color  ☐ Animal
        // ✔ Submit  →") was already seen between the choice list and that
        // separator (found live 2026-08-09) - Claude Code prefixes a plain
        // assistant TEXT message with "● " too, not just a tool invocation,
        // and a decorative rule directly above a reshown tab bar is exactly
        // the boundary Claude Code itself draws between that unrelated
        // preceding prose and the fresh prompt (verified against a real
        // capture: a reshown AskUserQuestion tab has no tool-invocation
        // marker of its own at all). Gated on the tab bar specifically,
        // not on ANY separator, because a genuine tool invocation ALSO
        // uses one internally, between its own "● ToolName(...)" marker
        // and its own detail box (e.g. "Bash command") - stopping there
        // too would cut a real permission prompt's context off right
        // before reaching its own marker (caught live by this file's own
        // long-command-preview test regressing when a first attempt at
        // this fix stopped at ANY separator unconditionally).
        $markerIndex = null;
        $separatorIndex = null;
        $sawTabBarBelow = false;

        for ($i = $choiceIndex - 1; $i >= 0; $i--) {
            if (preg_match('/^\s*●/u', $lines[$i]) === 1) {
                $markerIndex = $i;
                break;
            }

            if (self::is_decorative_pane_line($lines[$i])) {
                if ($sawTabBarBelow) {
                    $separatorIndex = $i;
                    break;
                }

                continue;
            }

            if (str_contains($lines[$i], '←') && str_contains($lines[$i], '→')) {
                $sawTabBarBelow = true;
            }
        }

        // A multi-question AskUserQuestion's "Submit" review tab recaps
        // EVERY answered question, each as its own "● " bullet (verified
        // live against a real capture: "Repo visibility ..." / "What
        // should the GitHub repo be named?", each followed by its own
        // indented "→ <answer>" line) - the nearest-● scan just above only
        // ever finds the LAST one, so context ended up missing every
        // earlier question's own bullet, and the tab's own "Review your
        // answers" header, entirely (found live 2026-08-09: Andres
        // reported what looked like a redundant second confirmation - it
        // was actually this incomplete capture of a multi-answer review
        // screen, showing only its final bullet + "Ready to submit your
        // answers?", which read as its own separate already-answered
        // question rather than part of the same review). Walk further up
        // past consecutive ●-bullet/→-answer/blank lines - the shape of
        // this tab specifically - and use "Review your answers" as the
        // real top of the block if found among them, extending one step
        // further to the tab bar itself ("←  ☒ Visibility  ☒ Repo name
        // ✔ Submit  →", verified live) when it's right above that header -
        // the multi_question detection below only ever looks WITHIN this
        // same context window, so without this the Submit tab specifically
        // would silently lose its own Prev/Next question navigation.
        if ($markerIndex !== null) {
            $reviewIndex = null;
            $sawReviewHeader = false;

            for ($i = $markerIndex - 1; $i >= 0; $i--) {
                if (str_contains($lines[$i], 'Review your answers')) {
                    $sawReviewHeader = true;
                    $reviewIndex = $i;

                    continue;
                }

                if ($sawReviewHeader && str_contains($lines[$i], '←') && str_contains($lines[$i], '→')) {
                    $reviewIndex = $i;

                    break;
                }

                if (preg_match('/^\s*(●|→)/u', $lines[$i]) === 1 || trim($lines[$i]) === '') {
                    continue;
                }

                break;
            }

            if ($reviewIndex !== null) {
                $markerIndex = $reviewIndex;
            }
        }

        // The fixed-window fallback must never reach past a decorative
        // separator either, same reasoning as the marker scan above - a
        // window that's wider than the gap to the boundary would otherwise
        // still sweep in unrelated preceding content the marker scan itself
        // was just kept out of.
        $windowFloor = $separatorIndex !== null ? $separatorIndex + 1 : 0;
        $start = $markerIndex !== null ? $markerIndex : max($windowFloor, $choiceIndex - self::BLOCKING_PROMPT_CONTEXT_WINDOW);
        $contextLines = array_map('rtrim', array_slice($lines, $start, $choiceIndex - $start));
        $contextLines = array_values(array_filter($contextLines, fn(string $l) => !self::is_decorative_pane_line($l)));

        // A multi-question tab's current question renders with a leading
        // "│ " quote-marker (found live 2026-08-29, session blocked on
        // an admin-tool-exposure prompt: "│ How do you want
        // to handle adminer, redis-ui, and git..." vs the hook's raw
        // "How do you want to..." with no marker) - a single leading
        // box-drawing glyph is never real content, only ever decoration
        // is_decorative_pane_line() didn't catch because the REST of the
        // line has real text. Left unstripped, this made
        // PromptInteractionService::answer_multi_question()'s live-pane
        // pre-flight check ($paneScraped['question'] === $firstQuestionText)
        // fail on every submission for a prompt shaped this way, rejecting
        // it as "already moved on" even though it hadn't - not specific to
        // free-text answers, just whatever the user happened to be
        // submitting when this was caught.
        $contextLines = array_map(
            fn(string $l) => preg_replace('/^[\x{2500}-\x{257F}\x{2580}-\x{259F}]\s?/u', '', $l) ?? $l,
            $contextLines
        );

        while ($contextLines !== [] && trim($contextLines[0]) === '') {
            array_shift($contextLines);
        }

        while ($contextLines !== [] && trim(end($contextLines)) === '') {
            array_pop($contextLines);
        }

        $context = preg_replace('/\n{3,}/', "\n\n", implode("\n", $contextLines)) ?? implode("\n", $contextLines);

        // The question label groups $contextLines into paragraphs (consecutive
        // non-blank lines, joined with spaces) before picking one, rather than
        // searching line-by-line - real prompts wrap a single sentence across
        // several physical terminal lines (verified against a live capture of
        // the trust dialog: the "?" lands mid-line, with the rest of the
        // sentence continuing on the next one), so a per-line search would
        // either miss the question or truncate it mid-sentence. $context above
        // still preserves the original, unmerged line breaks for the full
        // verbatim display.
        $paragraphs = [];
        $current = [];

        foreach ($contextLines as $line) {
            if (trim($line) === '') {
                if ($current !== []) {
                    $paragraphs[] = trim(implode(' ', $current));
                    $current = [];
                }

                continue;
            }

            $current[] = trim($line);
        }

        if ($current !== []) {
            $paragraphs[] = trim(implode(' ', $current));
        }

        $question = null;

        for ($i = count($paragraphs) - 1; $i >= 0; $i--) {
            if (str_contains($paragraphs[$i], '?')) {
                $question = $paragraphs[$i];
                break;
            }
        }

        if ($question === null && $paragraphs !== []) {
            $question = end($paragraphs);
        }

        // Walks until the first blank line, not the first non-matching one -
        // a multi-question AskUserQuestion prompt (verified against a real,
        // live capture) interleaves each numbered option with its own
        // indented description line, plus a purely decorative divider before
        // a trailing "Chat about this" option. Neither of those matches the
        // option pattern, but neither should end the list early either - only
        // a genuine blank line (the real end of the choice block in every
        // captured prompt shape so far) does.
        $options = [];

        for ($i = $choiceIndex; $i < count($lines); $i++) {
            if (trim($lines[$i]) === '') {
                break;
            }

            if (preg_match('/^\s*❯?\s*(\d+)[.)]\s*(.+?)\s*$/u', $lines[$i], $m) === 1) {
                $options[] = ['number' => (int)$m[1], 'label' => $m[2]];
            }
        }

        // The unnumbered trust dialog (see $unnumberedOptions above) has no
        // digit for the numbered regex above to match at all, so $options
        // stays empty - build it here instead, assigning sequential numbers
        // by on-screen top-to-bottom order (the same order
        // PromptInteractionService::answer_prompt() uses to compute how many
        // Up/Down presses reach a given option).
        if ($options === [] && $unnumberedOptions) {
            $number = 1;

            for ($i = $choiceIndex; $i < count($lines); $i++) {
                if (trim($lines[$i]) === '') {
                    break;
                }

                $label = trim(preg_replace('/^\s*❯\s*/u', '', $lines[$i]) ?? $lines[$i]);

                if ($label !== '') {
                    $options[] = ['number' => $number, 'label' => $label];
                    $number++;
                }
            }
        }

        // A multi-question AskUserQuestion call renders as a tab bar - one tab
        // per question plus a trailing "Submit" tab, cycled with the Left/Right
        // arrow keys (verified live) - rather than one linear prompt. Detected
        // so the frontend can offer prev/next-question navigation alongside
        // the normal numbered-option buttons for whichever tab is showing.
        $multiQuestion = false;

        foreach ($contextLines as $line) {
            if (str_contains($line, '←') && str_contains($line, '→') && str_contains($line, 'Submit')) {
                $multiQuestion = true;
                break;
            }
        }

        // The initial per-folder trust check is the one prompt where declining
        // exits the whole session outright, rather than just declining one
        // action - every other prompt shape's "no" option just moves on. That
        // makes an "exit" option a reliable, wording-independent signal for
        // "this is the trust dialog specifically" (verified against a live
        // capture: its options are "Yes, I trust this folder" / "No, exit").
        // Used to keep the dashboard's per-row treatment to the plain
        // attach-and-look tip for this one case, while other prompts get the
        // richer context+buttons treatment there too.
        $isFolderTrust = false;

        foreach ($options as $opt) {
            if (stripos($opt['label'], 'exit') !== false) {
                $isFolderTrust = true;
                break;
            }
        }

        return [
            'question' => $question ?? 'Waiting on an interactive prompt (permission or trust dialog)',
            'context' => $context,
            'options' => $options,
            'multi_question' => $multiQuestion,
            'is_folder_trust' => $isFolderTrust,
        ];
    }

    public static function detect_blocking_prompt(string $paneContent): ?string
    {
        return self::parse_blocking_prompt($paneContent)['question'] ?? null;
    }

    /**
     * Renders a PreToolUse hook payload's tool_input into the same kind of
     * full-text preview a human attached over tmux would eventually see -
     * except never truncated by pane height/width, since it comes straight
     * from the hook's JSON rather than rendered terminal output. Returns null
     * for a shape this doesn't know how to render usefully (an unrecognized
     * tool with no fields worth showing raw), in which case the caller should
     * keep the pane-scraped context instead of replacing it with nothing.
     */
    public static function format_pending_tool_input(string $toolName, array $toolInput): ?string
    {
        switch ($toolName) {
            case 'Bash':
                $command = is_string($toolInput['command'] ?? null) ? $toolInput['command'] : null;

                if ($command === null) {
                    return null;
                }

                $description = is_string($toolInput['description'] ?? null) ? $toolInput['description'] : null;

                return ($description !== null ? "{$description}\n\n" : '') . $command;

            case 'Write':
                $path = is_string($toolInput['file_path'] ?? null) ? $toolInput['file_path'] : null;
                $content = is_string($toolInput['content'] ?? null) ? $toolInput['content'] : null;

                if ($path === null || $content === null) {
                    return null;
                }

                return "Write {$path}\n\n{$content}";

            case 'Edit':
                $path = is_string($toolInput['file_path'] ?? null) ? $toolInput['file_path'] : null;

                if ($path === null) {
                    return null;
                }

                $oldString = is_string($toolInput['old_string'] ?? null) ? $toolInput['old_string'] : '';
                $newString = is_string($toolInput['new_string'] ?? null) ? $toolInput['new_string'] : '';

                return "Edit {$path}\n\n--- old ---\n{$oldString}\n\n--- new ---\n{$newString}";

            default:
                $encoded = json_encode($toolInput, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

                return $encoded === false ? null : "{$toolName}\n\n{$encoded}";
        }
    }

    /**
     * The exact option text Claude Code renders for one permission_suggestion,
     * confirmed against four real captures (Bash/Write/Edit, plus a
     * `acceptEdits`-mode Bash call producing `addRules` instead of `setMode`
     * - see the todo file's research entry) - null for a suggestion type/
     * shape this doesn't recognize, so the caller can skip it rather than
     * render nothing useful. `setMode` only has a known phrasing for
     * "acceptEdits" (the only mode ever actually offered as a suggestion in
     * every capture so far - switching to plan/auto isn't offered this way).
     *
     * @param array<string, mixed> $suggestion
     */
    public static function permission_suggestion_option_label(array $suggestion): ?string
    {
        switch ($suggestion['type'] ?? null) {
            case 'setMode':
                return ($suggestion['mode'] ?? null) === 'acceptEdits'
                    ? 'Yes, and switch to accept edits (auto-approve file edits and common file commands) for this session (shift+tab)'
                    : null;

            case 'addDirectories':
                $dirs = is_array($suggestion['directories'] ?? null) ? array_values(array_filter($suggestion['directories'], 'is_string')) : [];

                return $dirs === [] ? null : 'Yes, and always allow access to ' . implode(', ', $dirs) . ' from this project';

            case 'addRules':
                $rules = is_array($suggestion['rules'] ?? null) ? $suggestion['rules'] : [];
                $ruleContent = is_array($rules[0] ?? null) && is_string($rules[0]['ruleContent'] ?? null) ? $rules[0]['ruleContent'] : null;

                return $ruleContent === null ? null : "Yes, and don't ask again for: {$ruleContent}";

            default:
                return null;
        }
    }

    /**
     * Builds the numbered option list ("1. Yes" / an optional middle
     * suggestion-derived option / "N. No") for a hook-fed blocked prompt -
     * the PermissionRequest-payload equivalent of parse_blocking_prompt()'s
     * own $options, confirmed live: when multiple suggestions are present in
     * one payload, only the most specific one gets its own numbered option
     * (addDirectories/addRules over setMode) - the others aren't offered at
     * all. `addRules` and `addDirectories` have never been observed
     * together in one payload (they come from different tool shapes), so
     * picking the first non-setMode suggestion in payload order is enough
     * to match "most specific wins" without needing a rank between the two.
     *
     * @param array<int, mixed> $suggestions
     * @return array<int, array{number:int, label:string}>
     */
    public static function build_options_from_permission_suggestions(array $suggestions): array
    {
        $chosen = null;

        foreach ($suggestions as $s) {
            if (is_array($s) && ($s['type'] ?? null) !== 'setMode') {
                $chosen = $s;
                break;
            }
        }

        if ($chosen === null) {
            foreach ($suggestions as $s) {
                if (is_array($s) && ($s['type'] ?? null) === 'setMode') {
                    $chosen = $s;
                    break;
                }
            }
        }

        $options = [['number' => 1, 'label' => 'Yes']];
        $next = 2;
        $label = $chosen !== null ? self::permission_suggestion_option_label($chosen) : null;

        if ($label !== null) {
            $options[] = ['number' => $next, 'label' => $label];
            $next++;
        }

        $options[] = ['number' => $next, 'label' => 'No'];

        return $options;
    }

    /**
     * Classifies a permission-menu option label into one of the three
     * intents this app's own guessed layout (build_options_from_
     * permission_suggestions() above) ever assigns a label - 'yes_once'
     * for the bare "Yes", 'yes_always' for a suggestion-augmented one
     * (every permission_suggestion_option_label() string starts with
     * "Yes," - a comma, not the bare word - whether it's an addRules/
     * addDirectories/setMode suggestion), 'no' for "No" and Claude Code's
     * own longer "No, and tell Claude..." phrasing alike. 'unknown' for
     * anything else (a prompt shape this classifier was never meant to
     * cover reaching it by mistake).
     *
     * Used to compare a GUESSED label against a REAL pane-scraped one by
     * MEANING rather than exact text - see PromptInteractionService::
     * answer_prompt()'s cross-check for why exact text can't be required
     * (Andres, 2026-09-02: real wording for the same suggestion can carry
     * extra detail the guess doesn't reproduce verbatim) while still
     * catching an actual menu mismatch (a guessed 'yes_always' landing on
     * a real 'no' is never a wording difference).
     */
    public static function classify_permission_option_intent(string $label): string
    {
        $normalized = strtolower(trim($label));

        if ($normalized === 'yes') {
            return 'yes_once';
        }

        if (str_starts_with($normalized, 'yes,') || str_starts_with($normalized, 'yes ')) {
            return 'yes_always';
        }

        if (str_starts_with($normalized, 'no')) {
            return 'no';
        }

        return 'unknown';
    }

    /**
     * The PermissionRequest-hook-fed equivalent of parse_blocking_prompt() +
     * augment_prompt_with_pending_tool() combined, built entirely from
     * SessionStatusStore's own `blocked` field - no pane capture, no
     * PendingToolStore correlation needed (see SessionStatusStore's own
     * docblock for why). Never called for AskUserQuestion - that keeps using
     * the existing pane-scraped path unchanged (see this class's
     * augment_prompt_with_pending_tool() docblock) - callers are expected to
     * check tool_name before reaching for this.
     *
     * "Do you want to proceed?" is Claude Code's own fixed question text for
     * every plain Bash/Write/Edit permission prompt (confirmed live,
     * verbatim - see tests/test_sessions_lifecycle.php's pane-scraped
     * equivalent), not a generic placeholder - there's no per-call question
     * text in the PermissionRequest payload to use instead, and downstream
     * consumers (e.g. NotificationContentBuilder::push_blocked_body())
     * already prefer prompt_tool_name/prompt_tool_input over blocked_reason
     * for this exact prompt shape, so the fixed text is never the only
     * signal shown for what's actually being asked.
     *
     * @param array{tool_name:?string, tool_input:?array, permission_suggestions?:array}|null $blocked
     * @return array{question:string, context:string, options:array, multi_question:bool, is_folder_trust:bool, tool_name:string, tool_input:array}|null
     */
    public static function build_prompt_from_hook_status(?array $blocked): ?array
    {
        if ($blocked === null) {
            return null;
        }

        $toolName = is_string($blocked['tool_name'] ?? null) ? $blocked['tool_name'] : null;
        $toolInput = is_array($blocked['tool_input'] ?? null) ? $blocked['tool_input'] : null;

        if ($toolName === null || $toolInput === null) {
            return null;
        }

        $suggestions = is_array($blocked['permission_suggestions'] ?? null) ? $blocked['permission_suggestions'] : [];

        return [
            'question' => 'Do you want to proceed?',
            'context' => self::format_pending_tool_input($toolName, $toolInput) ?? '',
            'options' => self::build_options_from_permission_suggestions($suggestions),
            'multi_question' => false,
            'is_folder_trust' => false,
            'tool_name' => $toolName,
            'tool_input' => $toolInput,
        ];
    }

    /**
     * Turns a full multi-question AskUserQuestion's `questions[]` (straight
     * from the PermissionRequest-hook-fed SessionStatusStore, never the
     * pane) plus the user's own answers, collected all at once in the app,
     * into the exact flat sequence of tmux actions needed to drive Claude
     * Code's real tab-bar UI to that same end state - letting
     * PromptInteractionService::answer_multi_question() send it without ever reading
     * the CURRENT tab off the pane. Every step below is confirmed against a
     * real, live multi-question call (3 questions: single-select,
     * multiSelect, single-select-with-free-text - see the todo file's
     * research entry, 2026-08-22), not assumed from the payload schema alone:
     *
     * - A single-select question's real (non-free-text) option: sending its
     *   digit alone selects AND auto-advances to the next tab - no Enter.
     * - A single-select question's free-text option (always the slot right
     *   after its declared options, e.g. option 4 for a 3-option question):
     *   sending that digit puts the option into an inline-editable state:
     *   the literal typed text replaces its label live, and Enter both
     *   confirms and auto-advances - same as a real option's digit.
     * - A multiSelect question: each digit TOGGLES that option's checkbox
     *   and does NOT advance - confirmed for 1 and 2 simultaneous toggles.
     *   Reaching the next tab needs an explicit Right arrow press. Free-text
     *   is NOT supported for a multiSelect question here even though Claude
     *   Code's own UI still offers a "Type something" checkbox for one -
     *   untested combination, deliberately out of scope (see $answers below).
     * - After the LAST question (auto-advanced or manually advanced via
     *   Right, confirmed for both), Claude Code always lands on its own
     *   "Review your answers" tab, which needs one more explicit
     *   confirmation: option 1, "Submit answers".
     *
     * Not independently confirmed, but inferred as a safe generalization
     * from the above (Right arrow is a plain tab-navigation primitive, not
     * something specific to a middle-of-sequence multiSelect question): that
     * the same "toggle then Right" shape still applies when a multiSelect
     * question happens to be the LAST one before the Review tab, not just a
     * middle one. Re-verify live before relying on this if it ever misbehaves.
     *
     * A lone SINGLE-SELECT question has no tab bar at all and is already
     * handled by the existing pane-scraped answer_prompt()/
     * answer_prompt_with_text() path - still rejected here, no "which tab"
     * ambiguity to begin with. A lone MULTISELECT question is different:
     * found live 2026-09-12 (Andres: sessioneer's own dashboard rendered
     * this shape via the plain one-button-submits-immediately options.php
     * form, with no way to check several boxes then confirm, unlike Claude
     * Code's own real TUI) - it DOES get a tab bar
     * ("← ☐ <header>  ✔ Submit →"), and is mechanically identical in every
     * respect verified live to a real 2+-question call: toggle the same way,
     * then Right lands on the exact same "Review your answers" / "1. Submit
     * answers" screen (confirmed against a real, disposable session, not
     * assumed from the 2+-question mechanics alone). So this only rejects a
     * lone question when it's NOT multiSelect, not "fewer than 2" as a
     * blanket rule.
     *
     * @param array<int, array{question?:mixed, header?:mixed, multiSelect?:mixed, options?:mixed}> $questions
     * @param array<int, mixed> $answers one entry per question, in the same order:
     *   - int (1-based index into that question's options) for a single-select
     *     real option.
     *   - array{text:string} for a single-select question's free-text option.
     *   - int[] (1-based indices, non-empty) for a multiSelect question's
     *     checked real options - an array carrying a `text` key is rejected
     *     for a multiSelect question (unsupported, see above).
     * @return array<int, array{type:string, value?:string}>|null a flat
     *   sequence of tmux actions (digit/right/text/enter, in send order) -
     *   null if $answers doesn't structurally match $questions at all
     *   (wrong count, an out-of-range option, a malformed shape) - the
     *   caller should treat null as a rejected request, never send a
     *   partial sequence.
     */
    public static function build_multi_question_key_sequence(array $questions, array $answers): ?array
    {
        $isLoneSingleSelect = count($questions) === 1 && (is_array($questions[0] ?? null) ? ($questions[0]['multiSelect'] ?? false) : false) !== true;

        if (count($questions) < 1 || $isLoneSingleSelect || count($answers) !== count($questions)) {
            return null;
        }

        $sequence = [];

        foreach (array_values($questions) as $i => $question) {
            if (!is_array($question)) {
                return null;
            }

            $options = is_array($question['options'] ?? null) ? $question['options'] : [];
            $optionCount = count($options);
            $multiSelect = ($question['multiSelect'] ?? false) === true;
            $answer = $answers[$i] ?? null;

            if ($multiSelect) {
                if (!is_array($answer) || $answer === [] || isset($answer['text'])) {
                    return null;
                }

                foreach ($answer as $optionIndex) {
                    if (!is_int($optionIndex) || $optionIndex < 1 || $optionIndex > $optionCount) {
                        return null;
                    }

                    $sequence[] = ['type' => 'digit', 'value' => (string)$optionIndex];
                }

                $sequence[] = ['type' => 'right'];
            } elseif (is_array($answer) && isset($answer['text'])) {
                if (!is_string($answer['text']) || trim($answer['text']) === '') {
                    return null;
                }

                $sequence[] = ['type' => 'digit', 'value' => (string)($optionCount + 1)];
                $sequence[] = ['type' => 'text', 'value' => $answer['text']];
                $sequence[] = ['type' => 'enter'];
            } elseif (is_int($answer) && $answer >= 1 && $answer <= $optionCount) {
                $sequence[] = ['type' => 'digit', 'value' => (string)$answer];
            } else {
                return null;
            }
        }

        $sequence[] = ['type' => 'digit', 'value' => '1'];

        return $sequence;
    }

    /**
     * Enriches a pane-parsed blocking prompt's context with the full,
     * untruncated tool_input recorded by the PreToolUse hook, when one is
     * available and actually looks like it belongs to the prompt currently on
     * screen - falls back to the pane-scraped $prompt completely unchanged
     * otherwise (no hook installed, a plain unmanaged claude session, or a
     * stale pending-tool file left over from a tool call answered outside
     * this app).
     *
     * The cross-check against the pane's own "● ToolName(...)" marker line
     * matters because this app only ever tracks one pending tool call per
     * session (the latest PreToolUse write wins) - for the rare case of
     * multiple tool calls queued in one assistant turn, that file could
     * belong to a different call than the one actually blocking. There's no
     * tool_use_id visible in the rendered pane to correlate more precisely
     * than the tool name, so a name mismatch is treated as "not this prompt"
     * and the pane-scraped context is kept instead.
     *
     * AskUserQuestion is the one exception to that marker check: it renders
     * with no "●" line at all (verified live - a "☐ <header>" line instead),
     * so there's nothing to cross-check its identity against, and the
     * pane-scraped question/context (already exactly what a human reading the
     * prompt sees) is left untouched rather than being replaced by a raw
     * tool_input JSON dump. tool_name/tool_input are still exposed on the
     * returned prompt either way, so callers (e.g. the push notification
     * body) can tell a real question apart from a permission prompt.
     *
     * @param array{question:string, context:string, options:array, multi_question:bool, is_folder_trust:bool} $prompt
     * @param array{tool_name:?string, tool_input:?array}|null $pendingTool
     * @return array{question:string, context:string, options:array, multi_question:bool, is_folder_trust:bool, tool_name?:string, tool_input?:array}
     */
    public static function augment_prompt_with_pending_tool(array $prompt, ?array $pendingTool): array
    {
        if ($pendingTool === null) {
            return $prompt;
        }

        $toolName = is_string($pendingTool['tool_name'] ?? null) ? $pendingTool['tool_name'] : null;
        $toolInput = is_array($pendingTool['tool_input'] ?? null) ? $pendingTool['tool_input'] : null;

        if ($toolName === null || $toolInput === null) {
            return $prompt;
        }

        if (preg_match('/^\s*●\s*([A-Za-z_]+)/u', $prompt['context'], $m) === 1 && strcasecmp($m[1], $toolName) !== 0) {
            return $prompt;
        }

        if ($toolName !== 'AskUserQuestion') {
            $fullContext = self::format_pending_tool_input($toolName, $toolInput);

            if ($fullContext !== null) {
                $prompt['context'] = $fullContext;
            }
        }

        $prompt['tool_name'] = $toolName;
        $prompt['tool_input'] = $toolInput;

        return $prompt;
    }
}
