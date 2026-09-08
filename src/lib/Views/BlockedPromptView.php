<?php

declare(strict_types=1);

namespace App\Views;

/**
 * Everything about rendering a session's "waiting on input" state -
 * shared between index.php's dashboard rows and session.php's detail
 * view so the two never drift apart.
 */
class BlockedPromptView extends View
{
    /**
     * The "waiting on input" amber panel shown for a blocked session,
     * including the manual tmux-attach fallback - shared between
     * index.php's list rows and session.php's detail view. Only used where
     * no Approve/Deny buttons are shown alongside it (the dashboard's
     * folder-trust-dialog rows - see prompt_is_folder_trust in Sessions.php),
     * since the attach tip is the only way to act on those from here.
     * Everywhere buttons ARE shown, blocked_prompt_rich_html() builds its own
     * equivalent panel inline instead - the tip is redundant once there's a
     * button to tap.
     *
     * @param array{blocked_reason?:?string, resume_hint?:?string} $session
     */
    public static function blocked_prompt_panel_html(array $session): string
    {
        if (empty($session['blocked_reason'])) {
            return '';
        }

        return self::render('blocked-prompt/panel', [
            'blockedReason' => (string)$session['blocked_reason'],
            'resumeHint' => $session['resume_hint'] ?? null,
        ]);
    }

    /**
     * The first line of $text, capped at 80 chars, plus a trailing " …" if
     * anything was cut off - used as a collapsed <summary> for a tool
     * command/output (or a pending, not-yet-approved one) so a one-line
     * "Bash(...)" call doesn't need expanding, while a long or multi-line one
     * shows just enough to recognize it.
     */
    public static function collapsible_summary(string $text): string
    {
        $trimmed = trim($text);
        $firstLine = explode("\n", $trimmed, 2)[0];
        $summary = mb_strlen($firstLine) > 80 ? mb_substr($firstLine, 0, 80) . '…' : $firstLine;

        return $summary . (mb_strlen($trimmed) > mb_strlen($summary) ? ' …' : '');
    }

    /**
     * A tool command/output (or pending one) defaults to collapsed (a
     * <details> element, no JS required to expand) - shared between
     * session.php's history blocks and the pending-action preview so neither
     * clutters the page with an always-expanded command by default.
     *
     * Trivial content (short enough, single line - the collapsed summary
     * would show it in full anyway) skips the <details> wrapper entirely and
     * renders as plain text instead: an expand affordance for something
     * that's already fully visible is just extra chrome, not a real collapse.
     *
     * The expanded/full text wraps by default (whitespace-pre-wrap +
     * break-words, so a long command or line of output reads as a wrapped
     * block rather than needing horizontal scrolling) - a lot of output
     * still scrolls vertically past its capped height, and the vertical
     * scroll shows exactly what's really there.
     */
    public static function render_collapsible_block(string $rawText, string $borderClass, string $textClass, string $prefix): string
    {
        $trimmed = trim($rawText);
        $summary = self::collapsible_summary($rawText);

        return self::render('blocked-prompt/collapsible-block', [
            'isExpandable' => $summary !== $trimmed,
            'borderClass' => $borderClass,
            'textClass' => $textClass,
            'prefix' => $prefix,
            'summary' => $summary,
            'rawText' => $rawText,
            'footerHtml' => self::expandable_footer_html(),
        ]);
    }

    /**
     * The Copy + View-full-screen footer row shown in every EXPANDABLE
     * branch of render_collapsible_block()/render_full_block()/
     * render_collapsible_markdown_block() - byte-identical across all
     * three (found via the 2026-08-23 readability audit), extracted here
     * once rather than duplicated in each template.
     */
    private static function expandable_footer_html(): string
    {
        return self::render('blocked-prompt/block-footer');
    }

    /**
     * The same visual shell as render_collapsible_block()'s own expandable
     * branch (a scrollable, height-capped box with Copy + View-full-screen
     * buttons) - but with no <details>/<summary> of its own, always showing
     * the full raw text. For a context that already has its OWN outer
     * expand/collapse toggle (TranscriptView::render_tool_call_entry_html(),
     * added 2026-08-22 when per-tool-call entries stopped being bundled
     * under a shared "N tool calls" group) - nesting a SECOND "collapsed to
     * one line, click to expand" toggle inside an already-deliberately-
     * opened one would mean two clicks to see one piece of output, not one.
     * Trivial (short, single-line) content still skips the scrollable-box
     * treatment entirely, same reasoning and same threshold as
     * render_collapsible_block()'s own trivial branch.
     */
    public static function render_full_block(string $rawText, string $borderClass, string $textClass, string $prefix): string
    {
        $trimmed = trim($rawText);
        $isExpandable = self::collapsible_summary($rawText) !== $trimmed;

        return self::render('blocked-prompt/full-block', [
            'isExpandable' => $isExpandable,
            'borderClass' => $borderClass,
            'textClass' => $textClass,
            'prefix' => $prefix,
            'rawText' => $rawText,
            'footerHtml' => self::expandable_footer_html(),
        ]);
    }

    /**
     * Same collapse/expand shell as render_collapsible_block() (a
     * <details>/<summary> for non-trivial content, a plain row for
     * trivial), but the content shown once expanded (or the trivial row's
     * content) is MarkdownRenderer::render_html($rawText) instead of a raw
     * escaped `<pre>`/`<span>` - for content that's real written prose from
     * a subagent (a tool_result carrying `agent_type`, or a task-
     * notification's own report), not command/file output, where showing
     * literal `## Findings`/`- bullet` markdown syntax instead of rendered
     * formatting reads badly (Andres's own call 2026-08-22, once assistant
     * text messages already got the same MarkdownRenderer treatment - a
     * subagent's report is exactly the same kind of content). The raw text
     * still lives in a hidden `.copy-source` span (not the visible
     * rendered markdown) so Copy/View-full-screen still copy/show the real
     * markdown source, not a lossy plain-text rendering of it - same
     * reasoning as the 'text' block kind's own copy-source handling
     * (TranscriptView::render_transcript_block()/block.php).
     */
    public static function render_collapsible_markdown_block(string $rawText, string $borderClass, string $textClass, string $prefix): string
    {
        $trimmed = trim($rawText);
        $summary = self::collapsible_summary($rawText);

        return self::render('blocked-prompt/collapsible-markdown-block', [
            'isExpandable' => $summary !== $trimmed,
            'borderClass' => $borderClass,
            'textClass' => $textClass,
            'prefix' => $prefix,
            'summary' => $summary,
            'rawText' => $rawText,
            'markdownHtml' => MarkdownRenderer::render_html($rawText),
            'footerHtml' => self::expandable_footer_html(),
        ]);
    }

    /**
     * Real Approve/Deny-style buttons for a blocked session's numbered
     * options - shared between session.php (its blocked-prompt section) and
     * index.php's dashboard rows (blocked_prompt_rich_html(), below) so the
     * two never drift apart. Empty string if there's nothing to answer.
     *
     * A "Type something." option (Claude Code always offers one on an
     * AskUserQuestion prompt) gets a reveal button instead of an
     * immediate-submit form - verified live that selecting it needs custom
     * typed text sent alongside it (see answer_prompt_with_text() in
     * Sessions.php), not just the bare numbered choice. The paired reply
     * box (hidden until revealed) is shared by whichever free-text option
     * is open, wired up by the delegated JS listener in index.php/session.php.
     *
     * @param array{name:string, prompt_options?:array<int, array{number:int, label:string}>} $session
     */
    public static function blocked_prompt_options_html(array $session, string $csrfToken): string
    {
        if (empty($session['prompt_options'])) {
            return '';
        }

        $hasFreeText = false;

        foreach ($session['prompt_options'] as $opt) {
            if (stripos((string)$opt['label'], 'type something') !== false) {
                $hasFreeText = true;
                break;
            }
        }

        return self::render('blocked-prompt/options', [
            'sessionName' => (string)$session['name'],
            'csrfToken' => $csrfToken,
            'options' => $session['prompt_options'],
            'hasFreeText' => $hasFreeText,
        ]);
    }

    /**
     * The "answer every question at once" form for a multi-question
     * AskUserQuestion prompt - session_questions ($session['prompt_questions'],
     * set only for this exact shape by SessionService::build_session_entry())
     * is the full hook-fed question set, not whichever tab the pane
     * currently has up, so every question renders regardless of live tab
     * position. Submitting posts to /answer_multi_question.php, which
     * computes and sends the whole tmux key sequence needed to reach that
     * end state in one shot (see SessionService::answer_multi_question()
     * and PromptParser::build_multi_question_key_sequence() for the
     * confirmed tab-bar mechanics this drives) - no Prev/Next navigation
     * needed here, unlike blocked_prompt_options_html()'s multi-question
     * handling. Free-text is only offered per single-select question - not
     * supported for a multiSelect question (see
     * build_multi_question_key_sequence()'s own docblock for why).
     *
     * @param array{name:string, prompt_questions?:?array} $session
     */
    public static function blocked_multi_question_html(array $session, string $csrfToken): string
    {
        if (empty($session['prompt_questions'])) {
            return '';
        }

        return self::render('blocked-prompt/multi-question', [
            'sessionName' => (string)$session['name'],
            'csrfToken' => $csrfToken,
            'questions' => $session['prompt_questions'],
        ]);
    }

    /**
     * The full "waiting on input" treatment for a dashboard row - the compact
     * panel, the actual pending context (the command/description/etc being
     * asked about, not just the bare question - session.php shows this same
     * context as its own bubble instead, so it isn't folded into
     * blocked_prompt_options_html() itself), and real Approve/Deny buttons.
     * Used for anything except the initial folder-trust dialog, which stays
     * on the plain tip only: declining it exits the whole session, a
     * decision that deserves attaching and looking, not a quick tap from a
     * list of rows - see prompt_is_folder_trust in Sessions.php.
     *
     * $includeLastMessage: the message that led up to the prompt (e.g. the
     * assistant explaining why it's about to run a command) is worth showing
     * on the dashboard, where there's no scrollback to see it in - pass true
     * there. session.php leaves it false (the default): that same message is
     * already the newest bubble in its own history list, right above this
     * card, so repeating it here would just be duplication.
     *
     * @param array{name:string, blocked_reason?:?string, resume_hint?:?string, prompt_context?:?string, prompt_options?:array<int, array{number:int, label:string}>} $session
     */
    public static function blocked_prompt_rich_html(array $session, string $csrfToken, bool $includeLastMessage = false): string
    {
        if (empty($session['blocked_reason'])) {
            return '';
        }

        $lastMessageHtml = $includeLastMessage
            ? self::last_message_preview_html($session['last_message'] ?? null, 'text-amber-300/80 italic mb-1')
            : '';

        // A multi-question AskUserQuestion prompt gets the full "answer
        // everything at once" form instead of the pane-scraped single-tab
        // treatment below - prompt_context/prompt_options only ever reflect
        // whichever tab the pane currently has up, which would be
        // confusing/redundant shown alongside every question already
        // rendered here.
        if (!empty($session['prompt_questions'])) {
            return self::render('blocked-prompt/rich', [
                'blockedReason' => (string)$session['blocked_reason'],
                'lastMessageHtml' => $lastMessageHtml,
                'optionsHtml' => self::blocked_multi_question_html($session, $csrfToken),
            ]);
        }

        // The pending command/description this prompt is asking about gets
        // its own entry, immediately before the card below rather than
        // nested inside it - Andres's own explicit call, 2026-08-08,
        // prioritizing readability (matches how a real tool_use entry reads
        // elsewhere in the transcript) over the tradeoff that it can now
        // read as "something that already happened" the way a real past
        // entry does, even though it's still only pending.
        $pendingContextEntryHtml = self::pending_context_entry_html((string)($session['prompt_context'] ?? ''));

        $optionsHtml = !empty($session['prompt_options'])
            ? self::blocked_prompt_options_html($session, $csrfToken)
            : '';

        return $pendingContextEntryHtml . self::render('blocked-prompt/rich', [
            'blockedReason' => (string)$session['blocked_reason'],
            'lastMessageHtml' => $lastMessageHtml,
            'optionsHtml' => $optionsHtml,
        ]);
    }

    /**
     * The standalone entry for pending_context_entry_html()'s caller - see
     * blocked_prompt_rich_html()'s own doc comment above for why this is
     * separate from the amber "waiting on input" card rather than nested
     * inside it. Styled like a real tool_use entry (TranscriptView::
     * render_transcript_entry()) - same border-radius/padding/max-width
     * shape, amber instead of sky since this is still only pending, no
     * timestamp since it isn't a real transcript line. Empty string when
     * there's no context to show.
     */
    public static function pending_context_entry_html(string $promptContext): string
    {
        if ($promptContext === '') {
            return '';
        }

        return self::render('blocked-prompt/pending-context-entry', [
            'contentHtml' => self::render_collapsible_block($promptContext, 'border-amber-700/40', 'text-amber-100', ''),
        ]);
    }

    /**
     * A compact one-line "Role: text preview" for a single transcript entry
     * (as returned by session_last_message() in Sessions.php) - deliberately
     * terse compared to session.php's full block-kind rendering, since this
     * sits in a space-constrained dashboard row rather than a dedicated
     * detail page. Empty string if there's nothing to show.
     *
     * @param array{role?:?string, blocks?:array<int, array{kind:string, text:string}>}|null $entry
     */
    public static function last_message_preview_html(?array $entry, string $extraClass = ''): string
    {
        if ($entry === null || empty($entry['blocks'])) {
            return '';
        }

        $role = $entry['role'] ?? 'system';
        $roleLabel = ucfirst((string)$role);
        $text = (string)($entry['blocks'][0]['text'] ?? '');
        $preview = mb_strlen($text) > 140 ? mb_substr($text, 0, 140) . '…' : $text;

        if ($preview === '') {
            return '';
        }

        $class = trim('text-xs text-slate-400 truncate ' . $extraClass);

        return self::render('blocked-prompt/last-message-preview', [
            'class' => $class,
            'roleLabel' => $roleLabel,
            'preview' => $preview,
        ]);
    }
}
