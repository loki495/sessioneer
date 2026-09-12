<?php

declare(strict_types=1);

namespace App\Views;

/**
 * session.php's transcript-rendering helpers - the history entries, the
 * session-info card, the thinking indicator, the mode select, and the
 * blocked-prompt section wrapper. Kept together (rather than split further)
 * since they all feed the same page and its matching JS-side mirrors (see
 * the comments on each method pointing at their session.js counterpart).
 */
class TranscriptView extends View
{
    /**
     * Mirrors PermissionMode::CLAUDE_CODE_MODE_STATUS_PHRASES's key order
     * (host-agent, a separate process reached only via the socket - not
     * directly shareable) - keys must match set_mode()'s $targetMode exactly.
     */
    public const MODE_OPTIONS = ['manual' => 'Manual', 'accept edits' => 'Accept Edits', 'plan' => 'Plan', 'auto' => 'Auto'];

    /**
     * Mirrors SelectableModel::PICKER_OPTIONS's key order (host-agent, a
     * separate process reached only via the socket - not directly
     * shareable) - keys must match set_model()'s $targetModel exactly.
     */
    public const MODEL_OPTIONS = ['default' => 'Default', 'sonnet' => 'Sonnet', 'fable' => 'Fable', 'opus' => 'Opus', 'haiku' => 'Haiku'];

    /**
     * Mirrors AntigravitySelectableModel::PICKER_OPTIONS's key order
     * (host-agent, a separate process reached only via the socket - not
     * directly shareable) - keys must match set_antigravity_model()'s
     * $targetModel exactly.
     */
    public const ANTIGRAVITY_MODEL_OPTIONS = [
        'gemini-3.7-flash' => 'Gemini 3.7 Flash',
        'gemini-3.6-flash' => 'Gemini 3.6 Flash',
        'gemini-3.5-flash' => 'Gemini 3.5 Flash',
        'gemini-3.1-pro' => 'Gemini 3.1 Pro',
        'claude-sonnet-4.6-thinking' => 'Claude Sonnet 4.6 (Thinking)',
        'claude-opus-4.6-thinking' => 'Claude Opus 4.6 (Thinking)',
        'gpt-oss-120b-medium' => 'GPT-OSS 120B (Medium)',
    ];

    /**
     * @param array{media_type:string, data:string} $image
     */
    // Starts as a small square thumbnail (cropped via object-cover, not
    // scaled - overflow-hidden isn't needed separately since object-cover
    // itself never overflows its box) - a full-size screenshot inline by
    // default would dominate the transcript. Tapping toggles to full size and
    // back (see the delegated click handler in session.js) by swapping these
    // classes for w-full h-auto object-contain, not a separate lightbox/modal.
    public static function render_transcript_image_html(array $image): string
    {
        return self::render('transcript/image', [
            'mediaType' => $image['media_type'],
            'data' => $image['data'],
        ]);
    }

    /** Mirrors formatFileSize() in session.js (JS-side counterpart). */
    public static function format_attachment_size(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / (1024 * 1024), 1) . ' MB';
    }

    /**
     * $line is the raw JSONL line number a transcript entry was parsed
     * from (see TranscriptService::read_transcript_page()'s 'line' field) -
     * along with $fileUuid, that's enough for the host-agent to re-derive
     * the real file path itself (see TranscriptService::read_attachment()),
     * without the browser ever seeing it. $isArchived routes to the
     * archived-session-view counterpart endpoint, keyed by agent_session_id
     * rather than a live tmux session name - a dormant session has no
     * sidecar for session_attachment.php's own action to resolve one from.
     */
    public static function attachment_url(string $sessionIdentifier, int $line, string $fileUuid, bool $isArchived = false): string
    {
        if ($isArchived) {
            return '/archived_session_attachment.php?agent_session_id=' . rawurlencode($sessionIdentifier) . '&line=' . $line . '&file_uuid=' . rawurlencode($fileUuid);
        }

        return '/session_attachment.php?session=' . rawurlencode($sessionIdentifier) . '&line=' . $line . '&file_uuid=' . rawurlencode($fileUuid);
    }

    /**
     * A file Claude sent via SendUserFile (or, in principle, any future
     * source of the same toolUseResult.attachments shape - see
     * TranscriptService::transcript_attachments_from_tool_use_result() on
     * the host-agent side) - an actual thumbnail (reusing the same
     * .transcript-image tap-to-expand class/behavior as an inline base64
     * image) for an image, a download link showing filename + size for
     * anything else. The filename is always its own separate real link
     * (not just a caption) - deliberately not wrapped around the image
     * itself, since a click there needs to toggle the thumbnail (see the
     * delegated .transcript-image handler in session.js), not navigate.
     *
     * @param array<int, array{file_uuid:string, filename:string, size:int, isImage:bool, media_type:string}> $attachments
     */
    public static function render_transcript_attachments_html(array $attachments, string $sessionIdentifier, int $line, bool $isArchived = false): string
    {
        if ($attachments === []) {
            return '';
        }

        $itemsHtml = '';

        foreach ($attachments as $attachment) {
            $itemsHtml .= self::render('transcript/attachment', [
                'url' => self::attachment_url($sessionIdentifier, $line, $attachment['file_uuid'], $isArchived),
                'filename' => $attachment['filename'],
                'sizeLabel' => self::format_attachment_size($attachment['size']),
                'isImage' => $attachment['isImage'],
            ]);
        }

        return self::render('transcript/attachments', ['itemsHtml' => $itemsHtml]);
    }

    /**
     * $isSubagent picks the extra CSS class (subagent-use-block/subagent-detail,
     * alongside the always-present tool-use-block/tool-detail) that the
     * single "Show subagent calls and outputs" sidebar toggle targets - see
     * session.php's <style> block. A regular (non-subagent) tool_use/
     * tool_result block carries no such marker at all, since 2026-08-08 it's
     * never rendered standalone any more (see render_transcript_entries_html()) -
     * it's always inside its own standalone tool-call entry instead, whose
     * own <details> is the only show/hide affordance it needs.
     *
     * $forceFullBlock is true only when called from
     * render_tool_call_entry_html() (added 2026-08-22, see that method's own
     * docblock) - the CALL half (tool_use) already sitting inside that
     * entry's own outer <details> renders its full content directly
     * (BlockedPromptView::render_full_block()) rather than behind a SECOND
     * nested collapse toggle, since the outer one already IS the click-to-
     * expand affordance for the call itself. The RESULT half (tool_result)
     * deliberately does NOT follow $forceFullBlock - Andres's own call
     * 2026-08-22: tool output should collapse-by-default the same way a
     * subagent's report already does (BlockedPromptView::
     * render_collapsible_block()/render_collapsible_markdown_block()),
     * even once the outer entry is open - a command is usually short
     * enough to just read, but its output can be arbitrarily long, so
     * showing it immediately in full defeats the point of the outer
     * collapse in the first place.
     *
     * @param array{kind:string, text:string, image?:array{media_type:string, data:string}, attachments?:array<int, array{file_uuid:string, filename:string, size:int, isImage:bool, media_type:string}>, question?:string, questions?:array<int, array{question?:string, header?:string, options?:array<int, array{label?:string}>, multiSelect?:bool, multiple?:bool, custom?:bool}>, header?:string, options?:array<int, array{number?:int, label?:string}>, answer?:string|null, pending?:bool, agent_type?:string, description?:string, status?:string, summary?:string} $block
     */
    public static function render_transcript_block(array $block, string $sessionIdentifier, int $line, bool $isArchived = false, bool $isSubagent = false, bool $forceFullBlock = false, ?string $csrfToken = null): string
    {
        $imageHtml = isset($block['image']) ? self::render_transcript_image_html($block['image']) : '';
        $attachmentsHtml = !empty($block['attachments']) ? self::render_transcript_attachments_html($block['attachments'], $sessionIdentifier, $line, $isArchived) : '';

        // The image/attachments are SIBLINGS of .tool-detail, not nested
        // inside it - unlike the raw text output, Andres wants a
        // screenshot or a shared file visible regardless of the
        // show/hide-subagent toggle, since it's often the whole point of
        // having run the tool in the first place.
        // A subagent's own tool_result (agent_type set - see
        // TranscriptService::parse_transcript_line()) and a task-
        // notification's <result> are both real written PROSE from a
        // subagent, not command/file output - rendered as markdown (see
        // BlockedPromptView::render_collapsible_markdown_block()) rather
        // than literal raw text, same treatment 'text'-kind blocks already
        // get. Never combined with forceFullBlock - a subagent tool_result
        // is excluded from tool-call-entry pairing entirely (see
        // entry_is_groupable_tool_call()), so it's never rendered inside
        // one; a task_notification isn't paired either.
        $collapsibleHtml = match (true) {
            $block['kind'] === 'tool_use' => $forceFullBlock
                ? BlockedPromptView::render_full_block($block['text'], 'border-sky-800/40', 'text-sky-300', '&rarr; ')
                : BlockedPromptView::render_collapsible_block($block['text'], 'border-sky-800/40', 'text-sky-300', '&rarr; '),
            $block['kind'] === 'tool_result' && isset($block['agent_type']) => BlockedPromptView::render_collapsible_markdown_block($block['text'], 'border-slate-800', 'text-slate-400', ''),
            $block['kind'] === 'tool_result' => BlockedPromptView::render_collapsible_block(self::maybe_prettify_json($block['text']), 'border-slate-800', 'text-slate-400', ''),
            $block['kind'] === 'task_notification' => BlockedPromptView::render_collapsible_markdown_block($block['text'], 'border-fuchsia-800/40', 'text-fuchsia-300', ''),
            default => '',
        };

        $description = $block['description'] ?? null;

        if ($block['kind'] === 'task_notification') {
            $status = $block['status'] ?? null;
            $summary = $block['summary'] ?? null;
            $statusLabel = match ($status) {
                'completed' => 'Subagent finished',
                'failed' => 'Subagent failed',
                null => 'Subagent finished',
                default => 'Subagent ' . $status,
            };
            $description = $summary !== null ? "{$statusLabel}: {$summary}" : $statusLabel;
        }

        // An opencode `question` block renders as a question card using the
        // SAME blocked-prompt options component Claude Code's questions use
        // (numbered answer buttons + the "Type something" free-text reveal).
        // While the question is still pending in a live, answerable session
        // it is fully interactive; once answered (or in a read-only/archived
        // view) the options render in the same button styling,
        // non-interactive, with the chosen answer shown.
        $questionHtml = '';

        if ($block['kind'] === 'question') {
            $question = (string)($block['question'] ?? $block['text']);
            $header = (string)($block['header'] ?? '');
            $options = $block['options'] ?? [];
            $questions = is_array($block['questions'] ?? null) ? $block['questions'] : [];
            $answer = $block['answer'] ?? null;
            $pending = !empty($block['pending']);
            $isMultiQuestion = count($questions) > 1
                || (!empty($questions) && (($questions[0]['multiSelect'] ?? $questions[0]['multiple'] ?? false) === true));

            $heading = $header !== '' ? '<p class="text-[11px] font-semibold uppercase tracking-wider text-amber-500 mb-1">' . htmlspecialchars($header, ENT_QUOTES, 'UTF-8') . '</p>' : '';
            $questionP = $isMultiQuestion ? '' : '<p class="text-sm lg:text-base text-amber-100">' . htmlspecialchars($question, ENT_QUOTES, 'UTF-8') . '</p>';

            if ($pending && $csrfToken !== null && !$isArchived) {
                // Live, still awaiting an answer - reuse the real component.
                $optionsHtml = $isMultiQuestion
                    ? BlockedPromptView::blocked_multi_question_html([
                        'name' => $sessionIdentifier,
                        'prompt_questions' => $questions,
                    ], $csrfToken)
                    : BlockedPromptView::blocked_prompt_options_html([
                        'name' => $sessionIdentifier,
                        'prompt_options' => $options,
                    ], $csrfToken);

                $questionHtml = '<div class="copy-block rounded border border-amber-700/40 bg-amber-950/20 px-3 py-2">' . $heading . $questionP . $optionsHtml . '<span class="copy-source sr-only">' . htmlspecialchars($question, ENT_QUOTES, 'UTF-8') . '</span><button type="button" class="copy-btn select-none text-[11px] text-amber-700 active:text-amber-500 mt-1">Copy</button></div>';
            } else {
                // Answered (or read-only) - same button styling, non-interactive.
                $optionsHtml = '';
                if ($options !== []) {
                    $optionsHtml = '<div class="mt-2 flex flex-wrap gap-2">';
                    foreach ($options as $opt) {
                        $num = (int)($opt['number'] ?? 0);
                        $label = htmlspecialchars((string)($opt['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $optionsHtml .= '<span class="rounded-lg border border-amber-700/60 bg-amber-900/40 text-amber-100 text-xs font-medium px-3 py-2 break-words max-w-full text-left">' . $num . '. ' . $label . '</span>';
                    }
                    $optionsHtml .= '</div>';
                }

                $answerHtml = ($answer !== null && $answer !== '')
                    ? '<p class="mt-2 text-xs text-amber-300/70">Answered: ' . htmlspecialchars((string)$answer, ENT_QUOTES, 'UTF-8') . '</p>'
                    : '';

                $questionHtml = '<div class="copy-block rounded border border-amber-700/40 bg-amber-950/20 px-3 py-2">' . $heading . $questionP . $optionsHtml . $answerHtml . '<span class="copy-source sr-only">' . htmlspecialchars($question, ENT_QUOTES, 'UTF-8') . '</span><button type="button" class="copy-btn select-none text-[11px] text-amber-700 active:text-amber-500 mt-1">Copy</button></div>';
            }
        }

        return self::render('transcript/block', [
            'kind' => $block['kind'],
            'text' => $block['text'],
            'markdownHtml' => $block['kind'] === 'text' ? MarkdownRenderer::render_html($block['text']) : '',
            'description' => $description,
            'line' => $line,
            'collapsibleHtml' => $collapsibleHtml,
            'imageHtml' => $imageHtml,
            'attachmentsHtml' => $attachmentsHtml,
            'subagentClass' => $isSubagent ? ($block['kind'] === 'tool_use' ? ' subagent-use-block' : ' subagent-detail') : '',
            'questionHtml' => $questionHtml,
        ]);
    }

    /**
     * The blocked-prompt card (question, the pending command/context in a
     * collapsed-by-default block, Approve/Deny buttons - all one unified
     * card, via the shared BlockedPromptView::blocked_prompt_rich_html()) - empty string when
     * the session isn't currently blocked. Placed after the history list, and
     * re-rendered in place by the same visibility-gated poll that appends new
     * messages, so it always sits right where the latest activity is, not
     * pinned above a long transcript.
     */
    public static function render_blocked_prompt_section_html(array $detail, string $csrfToken): string
    {
        return BlockedPromptView::blocked_prompt_rich_html($detail, $csrfToken);
    }

    /**
     * A single, transient "Claude is thinking…" indicator - never the actual
     * thinking content (TranscriptService drops that entirely), just a live
     * "something is happening right now" signal sourced from
     * SessionStatusStore's hook-fed status (UserPromptSubmit -> working,
     * Stop -> idle - see build_session_entry() in
     * host-agent/lib/Services/SessionService.php). Mutually exclusive with
     * the blocked-prompt section: a session that's actively working isn't
     * also sitting on an unanswered prompt.
     */
    public static function render_thinking_indicator_html(array $detail): string
    {
        if (empty($detail['working']) || !empty($detail['blocked_reason'])) {
            return '';
        }

        return self::render('transcript/thinking-indicator');
    }

    /**
     * Antigravity-only: a turn that failed without producing any reply at
     * all (e.g. account quota exhausted) writes NOTHING to Antigravity's
     * own transcript file - see host-agent/hooks/antigravity/stop.php's
     * own docblock for the live-verified finding behind this (grepped a
     * real transcript after three separate quota-exhausted questions: only
     * the USER_INPUT lines were ever written, never a response or an error
     * entry). Without this, that turn would look, from the transcript
     * alone, exactly like the question was silently ignored - no different
     * from Claude Code simply still being slow. Shown in the same spot the
     * thinking indicator itself occupies (mutually exclusive with it -
     * 'working' is false by the time last_turn_error is ever set, since
     * both are only ever written by the same Stop hook firing) so a failed
     * turn reads the same way a real reply would: something appears where
     * one was expected.
     */
    public static function render_turn_error_html(array $detail): string
    {
        $errorText = is_string($detail['last_turn_error'] ?? null) ? $detail['last_turn_error'] : null;

        if ($errorText === null || $errorText === '') {
            return '';
        }

        return self::render('transcript/turn-error', [
            'errorText' => $errorText,
        ]);
    }

    /**
     * The sidebar's live task checklist - sourced from the top-level
     * agent's own TodoWrite OR Task-family (TaskCreate/TaskUpdate) tool
     * calls, read straight off the transcript, no hook involved (see
     * HostAgent\Services\SessionDetailService::session_detail()'s cascade and
     * TranscriptService::find_current_task_list()/find_latest_todo_list()
     * for which one actually fed $detail['todos'] - both normalize to the
     * same {content, activeForm, status} shape, so this renderer doesn't
     * need to know or care which source it came from). Renders nothing
     * (not even an empty section) when the session has never called
     * either tool, or has cleared its list back to empty - both read as
     * "nothing to show" from the sidebar's point of view, and most
     * sessions never use either at all, so an empty section would just be
     * noise for the common case.
     */
    public static function render_todo_list_html(array $detail): string
    {
        $todos = $detail['todos'] ?? null;

        if (!is_array($todos) || $todos === []) {
            return '';
        }

        return self::render('sidebar/todo-list', ['todos' => $todos]);
    }

    /**
     * A small select showing the session's current permission mode, next to
     * the compose box - choosing a different one jumps straight to it (via
     * set_mode() in Sessions.php, which works out the needed Shift+Tab steps
     * server-side). Disabled while the mode can't currently be read from the
     * pane (e.g. a blocking prompt is covering the status line) - set_mode()
     * needs a known starting point to compute the jump.
     */
    public static function render_mode_toggle_html(array $detail): string
    {
        $mode = is_string($detail['current_mode'] ?? null) ? $detail['current_mode'] : null;

        return self::render('transcript/mode-toggle', [
            'mode' => $mode,
            'options' => self::MODE_OPTIONS,
        ]);
    }

    /**
     * A small select showing the session's current model family, next to
     * the mode toggle - choosing a different one drives Claude Code's real
     * /model picker to switch it, session-only (see SessionService::
     * set_model()'s own docblock for why that needs the picker rather than
     * typing `/model <name>` directly). Disabled whenever the current model
     * can't be determined (no assistant message yet this session, or the
     * transcript hasn't been found). Purely transcript-derived, this could
     * never show "default" as a value (SelectableModel::
     * family_from_raw_model() can't detect it from a raw model id alone) -
     * SessionStatusStore's `model` optimistic override (see its own
     * docblock) now covers that gap too: picking "Default" here is
     * reflected immediately, same as any other choice, until the next real
     * turn's transcript-derived value takes back over.
     */
    public static function render_model_toggle_html(array $detail): string
    {
        $model = is_string($detail['current_model'] ?? null) ? $detail['current_model'] : null;

        return self::render('transcript/model-toggle', [
            'model' => $model,
            'options' => self::MODEL_OPTIONS,
        ]);
    }

    /**
     * Antigravity equivalent of render_model_toggle_html() above, for an
     * antigravity-agent session. Shows the live current model as the
     * selected option (from $detail['current_antigravity_model'], read off
     * the pane's own footer text - see AntigravitySelectableModel::
     * parse_current_model()'s own docblock, there's no transcript-derived
     * signal the way Claude Code's TranscriptService::find_latest_model()
     * has), falling back to a plain "Switch model..." placeholder when it
     * isn't currently readable (e.g. a prompt is covering the footer).
     * Never disabled either way, unlike Claude Code's toggle - Antigravity's
     * set_antigravity_model() doesn't need a known starting position the
     * way Claude Code's Shift+Tab-based set_mode() does (it always
     * normalizes to row 1 first via repeated Up presses).
     *
     * Also unlike Claude Code's toggle, this always changes Antigravity's
     * ACCOUNT-WIDE default model, not a session-only switch - see
     * set_antigravity_model()'s own docblock for the live-verified finding
     * behind that, and session.js's matching comment on the wiring below.
     */
    public static function render_antigravity_model_toggle_html(array $detail): string
    {
        $model = is_string($detail['current_antigravity_model'] ?? null) ? $detail['current_antigravity_model'] : null;

        return self::render('transcript/antigravity-model-toggle', [
            'model' => $model,
            'options' => self::ANTIGRAVITY_MODEL_OPTIONS,
        ]);
    }

    /**
     * OpenCode (headless) session model toggle - a session-only switch driven
     * entirely client-side: the select is rendered empty with the session's
     * current model/provider carried as data attributes, then session.js
     * fetches the serve's available models (GET /session_list_models.php ->
     * list_models -> cached /config/providers) and fills the dropdown, and
     * posts the chosen model + provider to set_model. OpenCode has no mode
     * toggle (no Shift+Tab permission modes), so this is the only footer
     * extra for an opencode session - the compose bar renders no mode select.
     *
     * @param array<string, mixed> $detail
     */
    public static function render_opencode_model_toggle_html(array $detail): string
    {
        return self::render('transcript/opencode-model-toggle', [
            'model' => is_string($detail['current_model'] ?? null) ? $detail['current_model'] : null,
            'provider' => is_string($detail['current_provider'] ?? null) ? $detail['current_provider'] : null,
        ]);
    }

    /**
     * Codex app-server model catalog and reasoning-effort controls.
     * @param array<string,mixed> $detail
     */
    public static function render_codex_model_toggle_html(array $detail): string
    {
        return self::render('transcript/codex-model-toggle', [
            'model' => is_string($detail['current_model'] ?? null) ? $detail['current_model'] : null,
            'effort' => is_string($detail['current_effort'] ?? null) ? $detail['current_effort'] : null,
        ]);
    }

    /**
     * "user"/"assistant"/"tool_use"/"tool_result"/"system" - not the same
     * thing as $entry['role'] (Claude Code's own tool_result entries carry
     * role=user under the hood, same as a real typed message - there's no
     * separate "tool" role at the transcript level). An entry with no text at
     * all reads as a tool action, not a conversational message, regardless of
     * its literal role, so it's colored (and labeled - see render_transcript_
     * entry()) as one instead - tool_use and tool_result get their own
     * distinct kinds, not lumped into one "tool" bucket, so a call and its
     * output are never confusable at a glance either.
     *
     * @param array{role?:?string, blocks?:array<int, array{kind:string}>} $entry
     */
    public static function entry_color_kind(array $entry): string
    {
        $blocks = $entry['blocks'] ?? [];
        $hasText = false;
        $hasToolUse = false;
        $hasToolResult = false;
        $isSubagent = false;
        $hasPlan = false;
        $hasTaskNotification = false;
        $hasQuestion = false;
        $planStatus = null;

        foreach ($blocks as $block) {
            match ($block['kind'] ?? null) {
                'text' => $hasText = true,
                'tool_use' => $hasToolUse = true,
                'tool_result' => $hasToolResult = true,
                'plan' => $hasPlan = true,
                'task_notification' => $hasTaskNotification = true,
                'question' => $hasQuestion = true,
                default => null,
            };

            if (($block['agent_type'] ?? null) !== null) {
                $isSubagent = true;
            }

            if (($block['plan_status'] ?? null) !== null) {
                $planStatus = $block['plan_status'];
            }
        }

        // A presented/approved/rejected plan (ExitPlanMode - see 'plan'
        // kind and 'plan_status' in TranscriptService's
        // summarize_content_block()/parse_transcript_line()) gets its own
        // kind, ahead of the generic tool_use/tool_result check below, for
        // the same reason a subagent launch/report does: it's functionally
        // "waiting on you" in a way a routine tool call isn't, and deserves
        // to read as a distinct thing rather than just another tool call.
        if (!$hasText && $planStatus !== null) {
            return $planStatus === 'approved' ? 'plan_approved' : 'plan_rejected';
        }

        if (!$hasText && $hasPlan) {
            return 'plan_presented';
        }

        // An opencode `question` tool (see OpenCodeTranscriptService::
        // tool_part_to_blocks()) reads as its own kind too - same "waiting
        // on you" reasoning, so it renders as a question card rather than
        // another tool call.
        if (!$hasText && $hasQuestion) {
            return 'question';
        }

        // A subagent launch/report (Claude Code's "Agent" tool - see
        // agent_type in TranscriptService's summarize_content_block()/
        // parse_transcript_line()) gets its own kind, ahead of the generic
        // tool_use/tool_result check below, so it reads as a distinct "this
        // is a subagent" thing rather than just another tool call.
        if (!$hasText && $isSubagent) {
            return $hasToolUse ? 'subagent_call' : 'subagent_result';
        }

        // A backgrounded subagent's own <task-notification> report (see
        // TranscriptService::parse_task_notification()) - same "this is
        // subagent stuff" color as subagent_call/subagent_result above,
        // just a structurally different transcript shape (a plain-string
        // "user" entry, not a tool_result block at all).
        if (!$hasText && $hasTaskNotification) {
            return 'subagent_result';
        }

        if (!$hasText && $hasToolUse) {
            return 'tool_use';
        }

        if (!$hasText && $hasToolResult) {
            return 'tool_result';
        }

        return match ($entry['role'] ?? null) {
            'assistant' => 'assistant',
            'user' => 'user',
            default => 'system',
        };
    }

    /**
     * @return array{border:string, bg:string, label:string}
     */
    public static function entry_color_classes(string $kind): array
    {
        return match ($kind) {
            // Deliberately not indigo/blue - tool_use (below) is sky to match
            // the existing tool_use block-border convention, and indigo sits
            // too close to sky on the color wheel to reliably tell apart at a
            // glance (found live: they read as "the same color").
            'user' => ['border' => 'border-rose-800/60', 'bg' => 'bg-rose-950/40', 'label' => 'text-rose-300'],
            'assistant' => ['border' => 'border-emerald-800/60', 'bg' => 'bg-emerald-950/40', 'label' => 'text-emerald-300'],
            'tool_use' => ['border' => 'border-sky-800/60', 'bg' => 'bg-sky-950/40', 'label' => 'text-sky-300'],
            'tool_result' => ['border' => 'border-violet-800/60', 'bg' => 'bg-violet-950/40', 'label' => 'text-violet-300'],
            // Shared between call and report - same "this is subagent stuff"
            // color for both, told apart by role label alone, same as every
            // other kind here.
            'subagent_call', 'subagent_result' => ['border' => 'border-fuchsia-800/60', 'bg' => 'bg-fuchsia-950/40', 'label' => 'text-fuchsia-300'],
            // Same shared-color convention as subagent_call/subagent_result
            // above, extended to all three plan states (presented/approved/
            // rejected) - told apart by role label alone.
            'plan_presented', 'plan_approved', 'plan_rejected' => ['border' => 'border-amber-800/60', 'bg' => 'bg-amber-950/40', 'label' => 'text-amber-300'],
            // An opencode question prompt - amber like the blocked-prompt
            // card it mirrors (see entry_color_kind()'s comment).
            'question' => ['border' => 'border-amber-700/40', 'bg' => 'bg-amber-950/20', 'label' => 'text-amber-300'],
            default => ['border' => 'border-slate-800', 'bg' => 'bg-slate-900/50', 'label' => 'text-slate-400'],
        };
    }

    /**
     * The whole point of this app's session page: renders a full batch of
     * history entries (the initial SSR page load, or one "Load older
     * messages"/archived-history fragment fetch), pairing each tool_use
     * with the tool_result immediately following it (Claude Code always
     * writes them as consecutive entries) into ONE standalone, individually
     * collapsed entry via render_tool_call_entry_html() - every OTHER entry
     * (text, plan, subagent call/report, task-notification, ...) renders
     * through render_transcript_entry() unchanged.
     *
     * Replaced the old "bundle a whole run of consecutive tool calls under
     * one shared 'N tool calls' toggle" design (Andres's own call
     * 2026-08-22, see git history for render_tool_group_html()/tool-
     * group.php if that ever needs resurrecting) - a long run used to hide
     * behind one opaque breadcrumb with no way to scan it without opening
     * the whole thing; now each call is its own scannable one-line summary
     * right in the flow, no bundling regardless of how many are consecutive.
     *
     * $cwd (the session's own working directory) is used only to relativize
     * a Write/Edit/Read tool-call entry's summary filename (see
     * tool_call_entry_summary()) - null is a safe default (falls back to
     * the raw absolute path), so every existing caller that predates that
     * feature keeps working unchanged.
     *
     * @param array<int, array{role?:?string, timestamp?:?string, line?:int, blocks:array<int, array{kind:string, text:string}>}> $entries
     */
    public static function render_transcript_entries_html(array $entries, string $sessionIdentifier, bool $isArchived = false, ?string $cwd = null, ?string $agentLabel = null, ?string $csrfToken = null): string
    {
        $html = '';
        $count = count($entries);
        $index = 0;

        while ($index < $count) {
            $entry = $entries[$index];

            if (!self::entry_is_groupable_tool_call($entry)) {
                $html .= self::render_transcript_entry($entry, $sessionIdentifier, $isArchived, $agentLabel, $csrfToken);
                $index++;

                continue;
            }

            if (self::entry_color_kind($entry) === 'tool_use') {
                $next = $entries[$index + 1] ?? null;
                $nextIsResult = $next !== null && self::entry_is_groupable_tool_call($next) && self::entry_color_kind($next) === 'tool_result';
                $html .= self::render_tool_call_entry_html($entry, $nextIsResult ? $next : null, $sessionIdentifier, $isArchived, $cwd);
                $index += $nextIsResult ? 2 : 1;

                continue;
            }

            // An orphaned tool_result with no preceding call - shouldn't
            // normally happen (Claude Code always writes a call before its
            // result), but a pagination boundary could in principle split a
            // pair right down the middle.
            $html .= self::render_tool_call_entry_html(null, $entry, $sessionIdentifier, $isArchived, $cwd);
            $index++;
        }

        return $html;
    }

    /**
     * A subagent call/report is deliberately excluded (kept as its own
     * standalone card, unaffected by pairing - Andres's own call: those
     * stay on the older per-kind "Show subagent calls"/"Show subagent
     * outputs" toggle instead, see entry_color_kind()/render_transcript_
     * entry()'s $isSubagent handling), same as an image or file attachment
     * (must always stay visible on its own, never folded into a
     * collapsed-by-default entry - same "always visible" exemption
     * render_transcript_entry() already made for the old per-entry hide
     * toggle).
     *
     * @param array{blocks:array<int, array{kind:string}>} $entry
     */
    private static function entry_is_groupable_tool_call(array $entry): bool
    {
        $colorKind = self::entry_color_kind($entry);

        if ($colorKind !== 'tool_use' && $colorKind !== 'tool_result') {
            return false;
        }

        foreach ($entry['blocks'] as $block) {
            if (isset($block['image']) || !empty($block['attachments'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * One standalone <details> per tool call (a call+its own result, paired
     * the same way the old render_tool_pair_html() did) - Andres's own call
     * 2026-08-22: stop bundling consecutive tool calls into one shared "N
     * tool calls" toggle in favor of each call being its own individually
     * expandable entry, right in the transcript flow, collapsed to ONE
     * summary line by default (see tool_call_entry_summary()) rather than a
     * whole run hiding behind one opaque breadcrumb.
     *
     * Once opened, both halves render via BlockedPromptView::
     * render_full_block() - the full content directly, no SECOND nested
     * collapse-behind-a-summary-line inside an entry the user already
     * deliberately opened (render_collapsible_block() stays reserved for
     * contexts with no outer toggle of their own, e.g. the blocked-prompt
     * preview). The result half is always wrapped in its own .tool-call-
     * result-slot div, even when empty (a call with no result yet) - PHP
     * itself never needs it (a full page render always already has both
     * halves), but session.js's own live-poll mirror of this function does:
     * a call and its result can arrive in separate poll cycles, and that
     * div is what session.js fills in in place once the result lands,
     * rather than appending a second separate entry for what's really one
     * logical tool call.
     */
    private static function render_tool_call_entry_html(?array $callEntry, ?array $resultEntry, string $sessionIdentifier, bool $isArchived, ?string $cwd): string
    {
        $timestampSource = $callEntry ?? $resultEntry;
        $parsedTimestamp = is_string($timestampSource['timestamp'] ?? null) ? strtotime($timestampSource['timestamp']) : false;
        $timestamp = $parsedTimestamp !== false ? SessionRowView::relative_time($parsedTimestamp) : '';

        return self::render('transcript/tool-call-entry', [
            'summaryLabel' => self::tool_call_entry_summary($callEntry, $resultEntry, $cwd),
            'timestamp' => $timestamp,
            'callHtml' => $callEntry !== null ? self::render_entry_blocks_html($callEntry, $sessionIdentifier, $isArchived, true) : '',
            'resultHtml' => $resultEntry !== null ? self::render_entry_blocks_html($resultEntry, $sessionIdentifier, $isArchived, true) : '',
        ]);
    }

    /**
     * The one-line label shown on a tool-call entry's closed <summary>:
     *
     * - Write/Edit/Read: "Write relative/path.php" / "Edit ..." / "Read ..."
     *   - natural language, not "ToolName(args)" (Andres's own call
     *   2026-08-22) - the raw file_path TranscriptService::
     *   summarize_content_block() stashed on the block, relativized against
     *   $cwd (see relativize_path()) - a bare filename alone could be
     *   ambiguous (which of several same-named files?), but the full
     *   absolute path is mostly repeated noise (the session's own cwd), so
     *   relative is the readable middle ground.
     * - Bash: "Ran truncated command" - the real command, truncated via
     *   BlockedPromptView::collapsible_summary() same as everything else -
     *   deliberately NOT the call's own `description` param (present on
     *   nearly every real Bash call) - Andres's own call 2026-08-22: the
     *   actual command is more useful at a glance than a possibly-vague
     *   description.
     * - Everything else: the call's own description when it has one, else
     *   its summarized text truncated the same way.
     *
     * Falls back to the RESULT's own text only for the rare orphaned-result
     * edge case (no preceding call in this batch - a pagination boundary
     * split a pair).
     *
     * @param array{blocks:array<int, array{kind:string, text:string, description?:?string, tool_name?:?string, file_path?:?string, command?:?string}>}|null $callEntry
     * @param array{blocks:array<int, array{kind:string, text:string}>}|null $resultEntry
     */
    private static function tool_call_entry_summary(?array $callEntry, ?array $resultEntry, ?string $cwd): string
    {
        $callBlock = self::first_text_bearing_block($callEntry);

        if ($callBlock !== null) {
            $toolName = $callBlock['tool_name'] ?? null;
            $filePath = $callBlock['file_path'] ?? null;

            if ($toolName !== null && $filePath !== null) {
                return $toolName . ' ' . self::relativize_path($filePath, $cwd);
            }

            $command = $callBlock['command'] ?? null;

            if ($toolName === 'Bash' && $command !== null) {
                return 'Ran ' . BlockedPromptView::collapsible_summary($command);
            }

            if (in_array($toolName, ['bash', 'execute', 'run_command'], true) && $command !== null) {
                return 'Ran ' . BlockedPromptView::collapsible_summary($command);
            }

            $description = $callBlock['description'] ?? null;

            return $description !== null && $description !== '' ? $description : BlockedPromptView::collapsible_summary($callBlock['text']);
        }

        $resultBlock = self::first_text_bearing_block($resultEntry);

        return $resultBlock !== null ? BlockedPromptView::collapsible_summary($resultBlock['text']) : 'Tool call';
    }

    /**
     * Detects valid JSON strings (objects/arrays) and returns a
     * pretty-printed version with 2-space indentation. Everything else
     * passes through unchanged. Only triggers on content that decodes to
     * an array or object — plain scalar JSON strings (numbers, booleans,
     * null) are left alone since pretty-printing them adds no value.
     */
    private static function maybe_prettify_json(string $text): string
    {
        $trimmed = trim($text);

        if ($trimmed === '' || $trimmed[0] !== '{' && $trimmed[0] !== '[') {
            return $text;
        }

        $decoded = json_decode($trimmed, true);

        if (!is_array($decoded)) {
            return $text;
        }

        $pretty = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $pretty !== false ? $pretty : $text;
    }

    /**
     * A plain string-prefix strip, not realpath()-based - matches this
     * file's own general pragmatism elsewhere (no symlink resolution, no
     * filesystem access just to shorten a label). Falls back to the
     * unmodified absolute path whenever $cwd is unknown or $path isn't
     * actually under it (a tool operating outside the session's own
     * working directory, or a session with no tracked cwd at all).
     */
    private static function relativize_path(string $path, ?string $cwd): string
    {
        if ($cwd === null || $cwd === '') {
            return $path;
        }

        $prefix = rtrim($cwd, '/') . '/';

        return str_starts_with($path, $prefix) ? substr($path, strlen($prefix)) : $path;
    }

    /**
     * @param array{blocks:array<int, array{kind:string, text:string}>}|null $entry
     * @return array{kind:string, text:string}|null
     */
    private static function first_text_bearing_block(?array $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        foreach ($entry['blocks'] as $block) {
            if (($block['text'] ?? '') !== '') {
                return $block;
            }
        }

        return null;
    }

    /**
     * @param array{line?:int, blocks:array<int, array{kind:string, text:string}>} $entry
     */
    private static function render_entry_blocks_html(array $entry, string $sessionIdentifier, bool $isArchived, bool $forceFullBlock = false, ?string $csrfToken = null): string
    {
        $line = (int)($entry['line'] ?? 0);

        return implode('', array_map(
            static fn(array $block): string => self::render_transcript_block($block, $sessionIdentifier, $line, $isArchived, false, $forceFullBlock, $csrfToken),
            $entry['blocks']
        ));
    }

    /**
     * $sessionIdentifier is a live tmux session name, unless $isArchived is
     * true, in which case it's a agent_session_id instead (see
     * attachment_url()) - the archived-session read-only view's own
     * counterpart to session.php passes its agent_session_id here.
     *
     * @param array{role:?string, timestamp:?string, line?:int, blocks:array<int, array{kind:string, text:string}>} $entry
     */
    public static function render_transcript_entry(array $entry, string $sessionIdentifier, bool $isArchived = false, ?string $agentLabel = null, ?string $csrfToken = null): string
    {
        $role = $entry['role'] ?? 'system';
        $colorKind = self::entry_color_kind($entry);
        // A tool_use/tool_result entry's real role is user/assistant only
        // because that's how Claude Code's own message format works, not
        // because it's meaningfully "the user" or "the assistant" talking -
        // labeling it "Tool" instead matches how it's actually colored.
        // An assistant entry shows the agent's name (Claude Code / Antigravity).
        $roleLabel = match ($colorKind) {
            'assistant' => $agentLabel ?? 'Claude Code',
            'tool_use' => 'Tool call',
            'tool_result' => 'Tool output',
            'subagent_call' => 'Subagent call',
            'subagent_result' => 'Subagent report',
            'plan_presented' => 'Plan',
            'plan_approved' => 'Plan approved',
            'plan_rejected' => 'Plan rejected',
            'question' => 'Question',
            default => ucfirst((string)$role),
        };
        $parsedTimestamp = is_string($entry['timestamp'] ?? null) ? strtotime($entry['timestamp']) : false;
        $timestamp = $parsedTimestamp !== false ? SessionRowView::relative_time($parsedTimestamp) : '';
        $colors = self::entry_color_classes($colorKind);
        $isSubagent = $colorKind === 'subagent_call' || $colorKind === 'subagent_result';
        // Hides the WHOLE entry (not just the now-hidden tool_result/tool_use
        // block) once the single "Show subagent calls and outputs" toggle
        // turns off, since there'd be nothing left to show otherwise (a bare
        // role-label-only bubble). A plain (non-subagent) tool_use/
        // tool_result entry never gets this marker at all - it's always
        // paired into its own standalone tool-call entry instead (see
        // render_transcript_entries_html()), never rendered standalone. The
        // marker never applies to an entry carrying an image or a file
        // attachment either, regardless of who it came from (found live:
        // this was missing on the first pass, so an entry with a screenshot
        // still vanished entirely instead of just its text) - a shared file
        // is always worth keeping visible on its own.
        $hasAttachment = false;

        foreach ($entry['blocks'] as $block) {
            if (isset($block['image']) || !empty($block['attachments'])) {
                $hasAttachment = true;
                break;
            }
        }

        $extraClass = (!$hasAttachment && $isSubagent) ? ' entry-subagent-only' : '';

        $line = (int)($entry['line'] ?? 0);
        $blocksHtml = implode('', array_map(
            static fn(array $block): string => self::render_transcript_block($block, $sessionIdentifier, $line, $isArchived, $isSubagent, false, $csrfToken),
            $entry['blocks']
        ));

        return self::render('transcript/entry', [
            'labelClass' => $colors['label'],
            'roleLabel' => $roleLabel,
            'timestamp' => $timestamp,
            'blocksHtml' => $blocksHtml,
            'wrapperClass' => self::entry_wrapper_class($colorKind, $colors, $extraClass),
            'isUserEntry' => $colorKind === 'user',
        ]);
    }

    /**
     * Claude-app-style treatment: a real user message reads as a filled
     * bubble (own background, fully rounded, right-aligned on desktop - see
     * the isUserEntry comment this replaces for why that alignment stays
     * desktop-only); a plain assistant reply reads as free-flowing text,
     * no border/background/max-width, same as the real Claude app - even
     * when it also carries tool_use blocks alongside its text, since those
     * blocks keep their own independent border via
     * BlockedPromptView::render_collapsible_block() regardless of the
     * entry wrapper around them. Every other kind (tool call/output alone,
     * subagent, plan, system) keeps today's boxed-card treatment
     * unchanged - Andres's own call: those stay "as they are".
     *
     * A free-flowing assistant entry also gets a bit of extra top margin
     * (on top of #history-list's own flex gap-2) - without a border/bg to
     * separate it from whatever's above, consecutive entries read as too
     * cramped without it. The entry-free-flowing marker class carries no
     * styling of its own - it's what session.php's <style> block hooks the
     * new-content glow's wider ring onto specifically for this kind (found
     * live 2026-08-08: with no padding of its own, the glow's crisp inner
     * ring sat flush against the text, looking like a stray outline rather
     * than a glow with room to breathe).
     *
     * @param array{border:string, bg:string, label:string} $colors
     */
    private static function entry_wrapper_class(string $colorKind, array $colors, string $extraClass): string
    {
        if ($colorKind === 'assistant') {
            return 'entry-free-flowing mt-2 lg:max-w-full lg:self-start' . $extraClass;
        }

        $isBubble = $colorKind === 'user';
        $rounding = $isBubble ? 'rounded-2xl' : 'rounded-lg';

        return $rounding . ' border ' . $colors['border'] . ' ' . $colors['bg'] . ' px-3 py-2' . $extraClass . ' lg:max-w-[75%] ' . ($isBubble ? 'lg:self-end' : 'lg:self-start');
    }
}
