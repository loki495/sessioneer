// @ts-check
(function () {
  // #history-list only renders when $found is true (see session.php) - the
  // old #session-info card this used to check for is gone (Andres's own
  // ask, 2026-08-20: dropped in favor of just title+cwd in the fixed
  // header), so this is the new "was a real session found" sentinel.
  var list = document.getElementById('history-list');
  var headerTitle = document.getElementById('header-title');

  if (!list) {
    return; // session not found - nothing here to wire up
  }

  wireTouchTooltip(headerTitle);
  wireTouchTooltip(document.getElementById('header-cwd'));

  // Session-specific values that vary per page load (real transcript state,
  // not something this static file can know) - set by the small inline
  // bootstrap-data <script> tag session.php renders right before this file
  // is loaded.
  var sessionName = window.SESSIONEER_BOOTSTRAP.session;
  var csrfToken = window.SESSIONEER_BOOTSTRAP.csrfToken;
  // Used only to relativize a Write/Edit/Read tool-call entry's summary
  // filename (see relativizePath()) - fixed for the page's lifetime, same
  // as every other SESSIONEER_BOOTSTRAP value here.
  var sessionCwd = window.SESSIONEER_BOOTSTRAP.workdir || null;
  var btn = document.getElementById('load-more-btn');
  var untilUserBtn = document.getElementById('load-until-user-btn');
  var thinkingIndicator = document.getElementById('thinking-indicator');
  var turnError = document.getElementById('turn-error');
  var todoListSection = document.getElementById('todo-list-section');
  var blockedSection = document.getElementById('blocked-prompt-section');
  var composeBar = document.getElementById('compose-bar');
  var composeInputRow = document.getElementById('compose-input-row');
  var composeBlockedNote = document.getElementById('compose-blocked-note');
  var newestLine = window.SESSIONEER_BOOTSTRAP.newestLine;
  // /clear, /compact, --resume, and --fork-session all rotate Claude
  // Code's own transcript to a brand new session-id file while staying in
  // the same tmux pane (see host-agent/hooks/session_start.php) - none of
  // them ever appear as an entry INSIDE a transcript (there's nothing to
  // parse for), so a rotation is only detectable by this id changing
  // between polls. null means "not known yet" (e.g. a session_detail.php
  // call errors before this is ever set) - deliberately never treated as
  // a change on its own, only a real id -> a DIFFERENT real id is.
  var currentAgentSessionId = window.SESSIONEER_BOOTSTRAP.agentSessionId || null;
  var sessionAgent = window.SESSIONEER_BOOTSTRAP.agent || 'claude';
  var sessionAgentLabel = window.SESSIONEER_BOOTSTRAP.agentLabel || (sessionAgent === 'antigravity' ? 'Antigravity' : (sessionAgent === 'codex' ? 'Codex' : 'Claude Code'));

  // --- optimistic UI state: entries appended locally right after sending,
  // before a poll has confirmed they actually landed. See appendPendingEntry()
  // and pollHistory() below for the append/reconcile lifecycle, and
  // markBlockedSectionAnswerPending()/renderBlockedSection() for the
  // matching "answered, waiting to confirm" treatment on the blocked-prompt
  // card itself. ---
  var pendingEntries = [];
  var currentBlockedReason = null;
  var answerPendingReason = null;
  // The pending history bubble for a just-submitted prompt answer - unlike
  // a compose message (real text, closely matches its eventual transcript
  // entry), an answer's confirmed entry likely isn't literally the button
  // label text, so reconcilePendingEntries()'s content-matching may never
  // find it - found live: it stayed dimmed "Sending…" forever even once
  // the prompt itself had genuinely resolved. Tied directly to
  // answerPendingReason instead, in renderBlockedSection() below: the
  // prompt actually resolving is a far more reliable confirmation signal
  // for THIS entry specifically than generic content-matching.
  var answerPendingHistoryEl = null;
  var lastRenderedBlockedKey; // undefined, not null - see renderBlockedSection()
  var lastRenderedThinkingShown; // undefined, not null - see renderThinkingIndicator()
  var lastRenderedTurnError; // undefined, not null - see renderTurnError()
  // Mirrors the $composeBlocked SSR toggle above - hides the message
  // input (not the whole compose bar; quota/mode stay visible) while a
  // prompt is pending, forcing it to be answered first. The textarea
  // itself is only hidden via CSS, never removed from the DOM, so
  // whatever's been typed survives a prompt appearing mid-draft.
  function renderComposeVisibility(detail) {
    if (!composeInputRow || !composeBlockedNote) {
      return;
    }

    var wasHidden = composeInputRow.classList.contains('hidden');
    var isBlocked = !!detail.blocked_reason;

    composeInputRow.classList.toggle('hidden', isBlocked);
    composeBlockedNote.classList.toggle('hidden', !isBlocked);

    // Andres reported the textarea sometimes coming back too short (a
    // sliver, not its normal auto-grown height) right after a mid-typing
    // prompt cleared. The auto-grown height is a plain inline style on
    // #compose-textarea, unaffected in principle by the row's own
    // display:none while hidden - but autoGrowCompose() (defined further
    // down, in scope here via the enclosing IIFE) is only ever invoked by
    // direct user interaction (typing, sending, attaching), never on this
    // row becoming visible again, so nothing re-measures it at that point.
    // Re-running it right on the hidden->visible transition is a cheap,
    // self-correcting fix regardless of the exact browser mechanism that
    // left the stale height behind.
    //
    // typeof-guarded, not a direct reference: autoGrowCompose() is declared
    // with `function autoGrowCompose() {}` inside an `if` block further
    // down (the compose-bar setup guard), not directly in this IIFE's own
    // body - real, non-strict-mode browsers still hoist it here via Annex B
    // legacy web compatibility semantics (verified live), but tsc's static
    // analysis doesn't model that, hence the ts-expect-error below.
    // @ts-expect-error -- Annex B block-scoped function hoisting, see above
    if (wasHidden && !isBlocked && typeof autoGrowCompose === 'function') {
      // @ts-expect-error -- same Annex B hoisting as the typeof check above
      autoGrowCompose();
    }
  }

  // the compose-bar footer-height watcher/scrollToBottom()/scrollToTop()
  // and the go-to-bottom/go-to-top buttons' own state now live in
  // scroll.js (loaded before this file - see session.php), plain global
  // functions/vars, same convention as common.js - extracted 2026-08-24,
  // third cut of the "split session.js into modules" pass. pageContent
  // itself is also declared there now; every other reference to it in this
  // file falls through to that global (same pattern already used for
  // escapeHtml()/parseJsonResponse() from common.js), not redeclared
  // locally here. (The scroll controls are docked in #scroll-toolbar, not
  // position:fixed, since 2026-09-06 - see session.php.)

  // The slideable sidebar (other sessions' status/prompt, uploaded files,
  // plan/handoff files, confirm-before-answer/show-subagent settings, and
  // its swipe gestures) now lives in sidebar.js (loaded before this file -
  // see session.php), plain global functions/vars, same convention as
  // common.js/scroll.js/highlights.js - extracted 2026-08-24, fifth cut of
  // the "split session.js into modules" pass. pollAbortController is also
  // declared there now (needed early by its own refreshSidebarNotification());
  // startPolling()/stopPolling() below reassign it (not with `var`, so it
  // falls through to that same global) each time polling actually
  // (re)starts, same pattern already used for pageContent/currentDivider.

  var ROLE_LABELS = { user: 'User', assistant: 'Assistant', system: 'System' };

  // Polling interval: user-selectable (dropdown in the sticky header, 1/3/5/
  // 10/15s), persisted per-browser. Defaults to 3s.
  // POLL_INTERVAL_STORAGE_KEY/POLL_INTERVAL_ALLOWED_MS are shared with
  // index.js - see common.js.
  var pollIntervalMs = (function () {
    try {
      var stored = parseInt(window.localStorage.getItem(POLL_INTERVAL_STORAGE_KEY), 10);
      return POLL_INTERVAL_ALLOWED_MS.indexOf(stored) !== -1 ? stored : 3000;
    } catch (e) {
      return 3000;
    }
  })();

  // escapeHtml()/parseJsonResponse() are shared with index.js - see common.js.

  // isNearBottom()/isNearTop()/scrollToBottom()/scrollToTop()/
  // updateGoToBottomVisibility()/updateGoToTopVisibility()/maybeAutoScroll()
  // now live in scroll.js (loaded before this file - see session.php),
  // plain global functions, same convention as common.js - extracted
  // 2026-08-24, third cut of the "split session.js into modules" pass.

  // iOS's own native "tap the status bar to scroll to top" convention has
  // nothing to act on here (Andres's own ask 2026-08-22) - it targets the
  // window/document's own scroll position, but this app's fixed-shell
  // pages deliberately make #page-content the ONLY scrolling element (see
  // #app-shell's own comment in this file), so window never scrolls at
  // all for the native gesture to catch. This reproduces the same
  // convention manually: tapping the header's own background (not one of
  // its real tap targets - the back link, title/cwd, poll interval,
  // sidebar toggle) scrolls #page-content to top instead.
  var sessionHeader = document.getElementById('session-header');

  if (sessionHeader) {
    sessionHeader.addEventListener('click', function (e) {
      // Exclusion-based, not "click landed on the bare <header> element" -
      // the header has no padding of its own (only its inner grid row
      // does), so on a real phone-width viewport (no side margins from
      // max-w-2xl mx-auto) there's barely any background pixel that IS
      // the <header> element directly. Treating "not one of the real tap
      // targets" as the trigger instead correctly covers the row's own
      // padding/gutters too, not just a sliver that's rarely hit in
      // practice.
      if (!closestEventTarget(e, 'a, #header-title, #header-cwd, #poll-interval-select, #sidebar-toggle-btn')) {
        pageContent.scrollTo({ top: 0, behavior: 'smooth' });
      }
    });
  }

  // Mirrors BlockedPromptView::collapsible_summary().
  function collapsibleSummary(text) {
    var trimmed = text.trim();
    var firstLine = trimmed.split('\n', 1)[0];
    var summary = firstLine.length > 80 ? firstLine.slice(0, 80) + '…' : firstLine;
    return summary + (trimmed.length > summary.length ? ' …' : '');
  }

  // Mirrors TranscriptView::maybe_prettify_json() (PHP) - detects valid
  // JSON objects/arrays and returns a pretty-printed version with 2-space
  // indentation. Scalars (numbers, booleans, null) and non-JSON text
  // pass through unchanged.
  function maybePrettifyJson(text) {
    var trimmed = text.trim();
    if (!trimmed || (trimmed[0] !== '{' && trimmed[0] !== '[')) { return text; }
    try {
      var parsed = JSON.parse(trimmed);
      if (parsed && typeof parsed === 'object') {
        return JSON.stringify(parsed, null, 2);
      }
    } catch (e) { /* not valid JSON — pass through */ }
    return text;
  }

  // Mirrors BlockedPromptView::render_collapsible_block() - tool commands/
  // output default to collapsed (a <details>, no JS needed to expand/
  // collapse), except trivial content (short, single line - the summary
  // would show it in full anyway), which skips the wrapper entirely.
  function renderCollapsibleBlock(rawText, borderClass, textClass, prefix, forceOpen) {
    var trimmed = rawText.trim();
    var summary = collapsibleSummary(rawText);
    var full = escapeHtml(rawText);

    if (summary === trimmed) {
      return '<div class="copy-block rounded border ' + borderClass + ' bg-slate-950/60 overflow-x-auto px-2 py-1.5 text-xs ' + textClass + ' flex items-start justify-between gap-2"><span class="whitespace-pre">' + prefix + '<span class="copy-source">' + full + '</span></span><button type="button" class="copy-btn select-none shrink-0 text-[11px] text-slate-500 active:text-slate-300">Copy</button></div>';
    }

    var summaryHtml = escapeHtml(summary);

    return '<details' + (forceOpen ? ' open' : '') + ' class="group rounded border ' + borderClass + ' bg-slate-950/60">'
      + '<summary class="block w-full text-left cursor-pointer select-none whitespace-pre-wrap break-all px-2 py-1.5 text-xs ' + textClass + '"><span class="group-open:hidden">' + prefix + summaryHtml + '</span><span class="hidden group-open:inline">&#9650; Collapse</span></summary>'
      + '<pre class="copy-source whitespace-pre overflow-auto overscroll-contain max-h-64 px-2 pb-1.5 text-xs ' + textClass + '">' + full + '</pre>'
      + '<div class="flex border-t border-slate-800"><button type="button" class="copy-btn select-none flex-1 text-center text-[11px] text-slate-500 active:text-slate-300 py-1 border-r border-slate-800">Copy</button><button type="button" class="expand-fullscreen-btn select-none flex-1 text-center text-[11px] text-slate-500 active:text-slate-300 py-1">View full screen</button></div>'
      + '</details>';
  }

  // Mirrors BlockedPromptView::render_full_block() (PHP) - the same visual
  // shell as renderCollapsibleBlock()'s own expandable branch (a
  // scrollable, height-capped box with Copy + View-full-screen buttons),
  // but with no <details>/<summary> of its own, always showing the full
  // raw text - for a context that already has its OWN outer expand/
  // collapse toggle (renderToolCallEntry() below). Trivial (short, single-
  // line) content still skips the scrollable-box treatment entirely, same
  // as renderCollapsibleBlock()'s own trivial branch.
  function renderFullBlock(rawText, borderClass, textClass, prefix) {
    var trimmed = rawText.trim();
    var summary = collapsibleSummary(rawText);
    var full = escapeHtml(rawText);

    if (summary === trimmed) {
      return '<div class="copy-block rounded border ' + borderClass + ' bg-slate-950/60 overflow-x-auto px-2 py-1.5 text-xs ' + textClass + ' flex items-start justify-between gap-2"><span class="whitespace-pre">' + prefix + '<span class="copy-source">' + full + '</span></span><button type="button" class="copy-btn select-none shrink-0 text-[11px] text-slate-500 active:text-slate-300">Copy</button></div>';
    }

    return '<div class="copy-block rounded border ' + borderClass + ' bg-slate-950/60"><pre class="copy-source whitespace-pre overflow-auto overscroll-contain max-h-64 px-2 pb-1.5 text-xs ' + textClass + '">' + prefix + full + '</pre>'
      + '<div class="flex border-t border-slate-800"><button type="button" class="copy-btn select-none flex-1 text-center text-[11px] text-slate-500 active:text-slate-300 py-1 border-r border-slate-800">Copy</button><button type="button" class="expand-fullscreen-btn select-none flex-1 text-center text-[11px] text-slate-500 active:text-slate-300 py-1">View full screen</button></div>'
      + '</div>';
  }

  // Mirrors BlockedPromptView::render_collapsible_markdown_block() (PHP) -
  // same collapse/expand shell as renderCollapsibleBlock(), but the content
  // shown (once expanded, or the trivial row's content) is renderMarkdown()
  // output instead of raw escaped text - for real written prose from a
  // subagent (a tool_result block with agent_type set, or a task-
  // notification's own report), where literal "## Findings"/"- bullet"
  // markdown syntax instead of rendered formatting reads badly, same as a
  // 'text'-kind block already gets. The raw text still lives in a hidden
  // .copy-source span (not the visible rendered markdown), so Copy/View-
  // full-screen still copy/show the real markdown source.
  function renderCollapsibleMarkdownBlock(rawText, borderClass, textClass, prefix) {
    var trimmed = rawText.trim();
    var summary = collapsibleSummary(rawText);
    var rendered = renderMarkdown(rawText);
    var rawEscaped = escapeHtml(rawText);

    if (summary === trimmed) {
      return '<div class="copy-block rounded border ' + borderClass + ' bg-slate-950/60 overflow-x-auto px-2 py-1.5 text-xs ' + textClass + ' flex items-start justify-between gap-2"><div class="markdown-body flex-1">' + rendered + '</div><span class="copy-source sr-only">' + rawEscaped + '</span><button type="button" class="copy-btn select-none shrink-0 text-[11px] text-slate-500 active:text-slate-300">Copy</button></div>';
    }

    var summaryHtml = escapeHtml(summary);

    return '<details class="group rounded border ' + borderClass + ' bg-slate-950/60">'
      + '<summary class="block w-full text-left cursor-pointer select-none whitespace-pre-wrap break-all px-2 py-1.5 text-xs ' + textClass + '"><span class="group-open:hidden">' + prefix + summaryHtml + '</span><span class="hidden group-open:inline">&#9650; Collapse</span></summary>'
      + '<div class="markdown-body overflow-auto overscroll-contain max-h-64 px-2 pb-1.5 text-xs ' + textClass + '">' + rendered + '</div>'
      + '<span class="copy-source sr-only">' + rawEscaped + '</span>'
      + '<div class="flex border-t border-slate-800"><button type="button" class="copy-btn select-none flex-1 text-center text-[11px] text-slate-500 active:text-slate-300 py-1 border-r border-slate-800">Copy</button><button type="button" class="expand-fullscreen-btn select-none flex-1 text-center text-[11px] text-slate-500 active:text-slate-300 py-1">View full screen</button></div>'
      + '</details>';
  }

  // Mirrors TranscriptView::render_transcript_image_html() (PHP).
  function renderImageHtml(image) {
    var mediaType = escapeHtml(image.media_type);
    var data = escapeHtml(image.data);

    return '<img src="data:' + mediaType + ';base64,' + data + '" loading="lazy" alt="Image" class="transcript-image mt-1.5 rounded border border-slate-800 cursor-pointer w-24 h-24 object-cover">';
  }

  // Mirrors TranscriptView::attachment_url() (PHP).
  function attachmentUrl(line, fileUuid) {
    return '/session_attachment.php?session=' + encodeURIComponent(sessionName) + '&line=' + line + '&file_uuid=' + encodeURIComponent(fileUuid);
  }

  // Mirrors TranscriptView::render_transcript_attachments_html()/attachment.php
  // (PHP) - a real thumbnail (reusing the same .transcript-image
  // tap-to-expand class as an inline base64 image) for an image, a
  // download link with filename + size for anything else. The filename
  // is always its own separate real link, not wrapped around the image
  // itself - a click there needs to toggle the thumbnail (see the
  // delegated .transcript-image handler below), not navigate.
  function renderAttachmentsHtml(attachments, line) {
    if (!attachments || attachments.length === 0) {
      return '';
    }

    var itemsHtml = attachments.map(function (a) {
      var url = attachmentUrl(line, a.file_uuid);
      var filename = escapeHtml(a.filename);

      if (a.isImage) {
        return '<div><img src="' + url + '" loading="lazy" alt="' + filename + '" class="transcript-image rounded border border-slate-800 cursor-pointer w-24 h-24 object-cover">'
          + '<a href="' + url + '" target="_blank" rel="noopener" class="block mt-0.5 max-w-24 truncate text-[11px] text-slate-500 active:text-slate-300">' + filename + '</a></div>';
      }

      // download (not target="_blank") - see attachment.php (PHP) for why:
      // target="_blank" on a Content-Disposition: attachment response
      // opens a permanently blank tab instead of a real page.
      return '<a href="' + url + '" download="' + filename + '" class="flex items-center gap-1.5 rounded border border-slate-800 bg-slate-950/60 px-2 py-1.5 text-xs text-sky-300 active:text-sky-200">'
        + '<span aria-hidden="true">&#8681;</span>'
        + '<span class="truncate max-w-[12rem]">' + filename + '</span>'
        + '<span class="shrink-0 text-slate-500">' + escapeHtml(formatFileSize(a.size)) + '</span></a>';
    }).join('');

    return '<div class="mt-1.5 flex flex-wrap items-start gap-2">' + itemsHtml + '</div>';
  }

  // Mirrors MarkdownRenderer::render_html() (PHP, src/lib/Views/
  // MarkdownRenderer.php) - see that class's own docblock for the full
  // design rationale (a deliberately small parser for bold/lists/code
  // spans, not full CommonMark; only code fences and list-item runs get
  // pulled into their own elements, everything else stays one flowing
  // <p class="whitespace-pre-wrap"> exactly like before markdown parsing
  // existed, so a plain message with no lists/code fences - most of them -
  // renders with the same structure as always, just with inline styling).
  // Keep both in sync when touching either.
  //
  // MD_NUL is the delimiter for both placeholder-token passes below (code
  // blocks and inline code spans) - built via fromCharCode() rather than a
  // literal escape in the source, and matched via a dynamically-built
  // RegExp rather than a regex literal, specifically so nothing here is a
  // literal NUL byte sitting in this file (mirrors PHP's "\x00" - a real
  // NUL is effectively impossible to collide with in genuine chat text,
  // unlike an ASCII placeholder like " CB0 " would be - found live: an
  // earlier plain-space-delimited version of this had exactly that
  // collision risk, e.g. real text containing "the IC5 pin" would have
  // falsely matched the inline-code restore pass).
  // renderMarkdown() and its mdXxx() helpers now live in markdown.js
  // (loaded before this file - see session.php) - extracted 2026-08-24,
  // first cut of the "split session.js into modules" pass, since they're
  // pure text-to-HTML with no DOM/session state at all, unlike almost
  // everything else in this file.

  // Mirrors TranscriptView::render_transcript_block() (PHP) - isSubagent
  // picks the extra CSS class (subagent-use-block/subagent-detail) that the
  // single "Show subagent calls and outputs" toggle targets; a regular
  // (non-subagent) tool_use/tool_result block carries no such marker at
  // all, since it's never rendered standalone any more (see
  // renderToolCallEntry() below) - it's always inside its own standalone
  // tool-call entry instead, whose own <details> is the only show/hide
  // affordance it needs.
  //
  // forceFullBlock is true only when called from renderToolCallEntry()
  // below - a tool_use/tool_result block already sitting inside that
  // entry's own outer <details> renders its full content directly
  // (renderFullBlock()) rather than behind a SECOND nested collapse toggle
  // (renderCollapsibleBlock()), since the outer one already IS the
  // click-to-expand affordance for this block.
  function renderBlock(block, line, isSubagent, forceFullBlock) {
    var text = escapeHtml(block.text);
    var imageHtml = block.image ? renderImageHtml(block.image) : '';
    var attachmentsHtml = renderAttachmentsHtml(block.attachments, line);

    // break-words - see render_transcript_block() in session.php (the
    // PHP-side counterpart) for why: a long unbroken token (a constant
    // name, URL, hash, ...) in prose text can otherwise widen the whole
    // page horizontally instead of wrapping.
    switch (block.kind) {
      case 'text':
        return '<div class="copy-block" data-line="' + line + '"><div class="markdown-body text-sm lg:text-base text-slate-100">' + renderMarkdown(block.text) + '</div><span class="copy-source sr-only">' + text + '</span><button type="button" class="copy-btn select-none text-[11px] text-slate-500 active:text-slate-300 mt-0.5">Copy</button></div>';
      case 'plan':
        return '<div class="copy-block rounded border border-amber-800/40 bg-amber-950/20 px-3 py-2" data-line="' + line + '"><p class="copy-source whitespace-pre-wrap break-words text-sm lg:text-base text-amber-100">' + text + '</p><button type="button" class="copy-btn select-none text-[11px] text-amber-700 active:text-amber-500 mt-1">Copy</button></div>';
      case 'tool_use':
        // Collapsed by default regardless of the show/hide-subagent
        // toggle - it used to force-open when details were hidden (on the
        // theory that there'd be no result to click into for confirmation),
        // but that's backwards from what's wanted: collapsed either way.
        // block.description (when present - see TranscriptService::
        // tool_use_description()) renders as its own always-visible line,
        // never subject to renderCollapsibleBlock()'s own first-line-only
        // truncation the rest of the params are.
        return '<div class="tool-use-block' + (isSubagent ? ' subagent-use-block' : '') + '" data-line="' + line + '">'
          + (block.description ? '<p class="text-sm lg:text-base text-sky-200 mb-1">' + escapeHtml(block.description) + '</p>' : '')
          + (forceFullBlock
            ? renderFullBlock(block.text, 'border-sky-800/40', 'text-sky-300', '&rarr; ')
            : renderCollapsibleBlock(block.text, 'border-sky-800/40', 'text-sky-300', '&rarr; ')) + '</div>';
      case 'tool_result':
        // The image/attachments are SIBLINGS of .tool-detail, not nested
        // inside it - shown regardless of the show/hide-subagent toggle,
        // since a shared file is often the whole point of having run the
        // tool in the first place. A subagent's own tool_result
        // (agent_type set) is real written prose, not command/file
        // output - rendered as markdown. Deliberately does NOT follow
        // forceFullBlock (unlike tool_use) - tool output collapses by
        // default the same way a subagent's report already does, even
        // once the outer tool-call entry is open - a command is usually
        // short enough to just read, but its output can be arbitrarily
        // long, so showing it immediately in full defeats the point of
        // the outer collapse in the first place.
        return '<div class="tool-detail' + (isSubagent ? ' subagent-detail' : '') + '" data-line="' + line + '">'
          + (block.agent_type != null
            ? renderCollapsibleMarkdownBlock(block.text, 'border-slate-800', 'text-slate-400', '')
            : renderCollapsibleBlock(maybePrettifyJson(block.text), 'border-slate-800', 'text-slate-400', '')) + '</div>' + imageHtml + attachmentsHtml;
      case 'task_notification':
        // Mirrors TranscriptView::render_transcript_block()'s task_notification
        // case (PHP) - a backgrounded subagent's <task-notification> report,
        // see TranscriptService::parse_task_notification(). Its own
        // <result> is real written prose too, same markdown treatment.
        var statusLabel = block.status === 'completed' ? 'Subagent finished'
          : block.status === 'failed' ? 'Subagent failed'
          : block.status == null ? 'Subagent finished'
          : 'Subagent ' + block.status;
        var notificationDescription = block.summary != null ? (statusLabel + ': ' + block.summary) : statusLabel;
        return '<div class="tool-detail' + (isSubagent ? ' subagent-detail' : '') + '" data-line="' + line + '">'
          + '<p class="text-sm lg:text-base text-fuchsia-200 mb-1">' + escapeHtml(notificationDescription) + '</p>'
          + renderCollapsibleMarkdownBlock(block.text, 'border-fuchsia-800/40', 'text-fuchsia-300', '') + '</div>';
      case 'image':
        return imageHtml || (text ? '<div class="copy-block" data-line="' + line + '"><p class="copy-source break-words text-xs text-slate-600">' + text + '</p><button type="button" class="copy-btn select-none text-[11px] text-slate-700 active:text-slate-500">Copy</button></div>' : '');
      default:
        return text ? '<div class="copy-block" data-line="' + line + '"><p class="copy-source break-words text-xs text-slate-600">' + text + '</p><button type="button" class="copy-btn select-none text-[11px] text-slate-700 active:text-slate-500">Copy</button></div>' : '';
    }
  }

  // Mirrors entry_color_kind()/entry_color_classes() in session.php (the
  // PHP-side counterpart) - "user"/"assistant"/"tool_use"/"tool_result"/
  // "system" is not the same thing as entry.role (a tool_result entry
  // carries role="user" under the hood, same as a real typed message); an
  // entry with no text at all reads as a tool action regardless of its
  // literal role, so it's colored (and labeled - see renderEntry()) as
  // one instead - tool_use and tool_result get their own distinct kinds,
  // not lumped into one "tool" bucket, so a call and its output are never
  // confusable at a glance either.
  function entryColorKind(entry) {
    var blocks = entry.blocks || [];
    var hasText = blocks.some(function (b) { return b.kind === 'text'; });
    var hasToolUse = blocks.some(function (b) { return b.kind === 'tool_use'; });
    var hasToolResult = blocks.some(function (b) { return b.kind === 'tool_result'; });
    var isSubagent = blocks.some(function (b) { return b.agent_type != null; });
    var hasPlan = blocks.some(function (b) { return b.kind === 'plan'; });
    var hasTaskNotification = blocks.some(function (b) { return b.kind === 'task_notification'; });
    var planStatus = null;
    blocks.forEach(function (b) { if (b.plan_status != null) { planStatus = b.plan_status; } });

    // See TranscriptView::entry_color_kind() (PHP) for why this check comes
    // before the generic tool_use/tool_result one below - a presented/
    // approved/rejected plan should read as its own distinct thing, not
    // just another tool call.
    if (!hasText && planStatus != null) {
      return planStatus === 'approved' ? 'plan_approved' : 'plan_rejected';
    }

    if (!hasText && hasPlan) {
      return 'plan_presented';
    }

    // See TranscriptView::entry_color_kind() (PHP) for why this check comes
    // before the generic tool_use/tool_result one below - a subagent
    // launch/report should read as its own distinct thing, not just
    // another tool call.
    if (!hasText && isSubagent) {
      return hasToolUse ? 'subagent_call' : 'subagent_result';
    }

    // A backgrounded subagent's own <task-notification> report - same
    // "this is subagent stuff" color as subagent_call/subagent_result
    // above, see TranscriptView::entry_color_kind() (PHP) for why.
    if (!hasText && hasTaskNotification) {
      return 'subagent_result';
    }

    if (!hasText && hasToolUse) {
      return 'tool_use';
    }

    if (!hasText && hasToolResult) {
      return 'tool_result';
    }

    if (entry.role === 'assistant' || entry.role === 'user') {
      return entry.role;
    }

    return 'system';
  }

  function entryColorClasses(kind) {
    switch (kind) {
      case 'user':
        // See TranscriptView::entry_color_classes() (PHP) for why this is
        // rose, not indigo/blue - too close to sky (tool_use) otherwise.
        return { border: 'border-rose-800/60', bg: 'bg-rose-950/40', label: 'text-rose-300' };
      case 'assistant':
        return { border: 'border-emerald-800/60', bg: 'bg-emerald-950/40', label: 'text-emerald-300' };
      case 'tool_use':
        return { border: 'border-sky-800/60', bg: 'bg-sky-950/40', label: 'text-sky-300' };
      case 'tool_result':
        return { border: 'border-violet-800/60', bg: 'bg-violet-950/40', label: 'text-violet-300' };
      case 'subagent_call':
      case 'subagent_result':
        return { border: 'border-fuchsia-800/60', bg: 'bg-fuchsia-950/40', label: 'text-fuchsia-300' };
      case 'plan_presented':
      case 'plan_approved':
      case 'plan_rejected':
        return { border: 'border-amber-800/60', bg: 'bg-amber-950/40', label: 'text-amber-300' };
      default:
        return { border: 'border-slate-800', bg: 'bg-slate-900/50', label: 'text-slate-400' };
    }
  }

  function renderEntry(entry) {
    var colorKind = entryColorKind(entry);
    // See TranscriptView::entry_color_kind()'s label comment (PHP) - a
    // tool_use/tool_result entry is labeled "Tool", not its literal
    // user/assistant role, to match how it's actually colored. An
    // assistant entry shows the agent's name (Claude Code / Antigravity).
    var roleLabel = colorKind === 'assistant' ? sessionAgentLabel
      : colorKind === 'tool_use' ? 'Tool call'
      : colorKind === 'tool_result' ? 'Tool output'
      : colorKind === 'subagent_call' ? 'Subagent call'
      : colorKind === 'subagent_result' ? 'Subagent report'
      : colorKind === 'plan_presented' ? 'Plan'
      : colorKind === 'plan_approved' ? 'Plan approved'
      : colorKind === 'plan_rejected' ? 'Plan rejected'
      : (ROLE_LABELS[entry.role] || (entry.role ? escapeHtml(entry.role) : 'System'));
    var parsedMs = entry.timestamp ? Date.parse(entry.timestamp) : NaN;
    var timestamp = !isNaN(parsedMs) ? escapeHtml(relativeTimeLabel(Math.floor(parsedMs / 1000))) : '';
    var isSubagent = colorKind === 'subagent_call' || colorKind === 'subagent_result';
    var blocksHtml = (entry.blocks || []).map(function (b) { return renderBlock(b, entry.line, isSubagent); }).join('');
    var colors = entryColorClasses(colorKind);
    // Hides the WHOLE entry (not just the now-hidden tool_result/tool_use
    // block) once the single "Show subagent calls and outputs" toggle
    // turns off - see the PHP comment in render_transcript_entry() for why,
    // including why an entry carrying an image or a file attachment is
    // excluded either way, regardless of who it came from. A plain
    // (non-subagent) tool_use/tool_result entry never gets this marker at
    // all any more - see groupToolCalls() below.
    var hasAttachment = (entry.blocks || []).some(function (b) { return !!b.image || (b.attachments && b.attachments.length > 0); });
    var extraClass = (!hasAttachment && isSubagent) ? ' entry-subagent-only' : '';

    // Mirrors TranscriptView::entry_wrapper_class() (PHP) - a real user
    // message is a filled bubble (right-aligned, desktop-only, same as
    // before); a plain assistant reply is free-flowing text (no border/
    // background/max-width), even when it also carries tool_use blocks,
    // since those keep their own independent border regardless of the
    // entry wrapper around them - plus a bit of extra top margin, since
    // there's no border/bg left to visually separate it from whatever's
    // above. Every other kind keeps the boxed-card treatment unchanged.
    var wrapperClass;

    if (colorKind === 'assistant') {
      wrapperClass = 'entry-free-flowing mt-2 lg:max-w-full lg:self-start' + extraClass;
    } else {
      var isBubble = colorKind === 'user';
      var rounding = isBubble ? 'rounded-2xl' : 'rounded-lg';
      wrapperClass = rounding + ' border ' + colors.border + ' ' + colors.bg + ' px-3 py-2' + extraClass + ' lg:max-w-[75%] ' + (isBubble ? 'lg:self-end' : 'lg:self-start');
    }

    var div = document.createElement('div');
    div.className = wrapperClass;
    div.innerHTML = '<div class="select-none mb-1 flex items-center gap-2 text-xs text-slate-500">'
      + (roleLabel ? '<span class="font-medium ' + colors.label + '">' + roleLabel + '</span>' : '')
      + (timestamp ? '<span>' + timestamp + '</span>' : '')
      + '</div>'
      + '<div class="flex flex-col gap-1.5">' + blocksHtml + '</div>';

    return div;
  }

  // --- tool-call entries: mirrors TranscriptView::render_transcript_
  // entries_html()/render_tool_call_entry_html() (PHP) - each tool_use is
  // paired with the tool_result immediately following it (Claude Code
  // always writes them as consecutive entries) into ONE standalone,
  // individually collapsed <details> entry, right in the transcript flow.
  // Replaced the old "bundle a run of consecutive tool calls under one
  // shared 'N tool calls' toggle" design (Andres's own call 2026-08-22, see
  // git history if that ever needs resurrecting).
  //
  // Unlike the PHP side (which only ever renders one full batch at once -
  // initial page load, or one "load older" fetch, so a purely batch-local
  // pass is enough), this also has to handle the LIVE poll tail - a call
  // and its own result routinely land in two separate poll cycles (the
  // tool takes real time to run), so tailPendingCallState persists across
  // pollHistory() calls and lets a new result upgrade an already-rendered,
  // already-in-the-DOM call-only entry in place instead of appending a
  // second card for what's really one logical tool call. loadHistoryPage()
  // and the initial fallback poll use their own fresh, throwaway state instead
  // (createPendingCallState()) - a batch of OLDER entries being prepended
  // above everything already shown must never merge into (or be merged
  // into) whatever's already on screen. ---

  function entryIsGroupableToolCall(entry) {
    var colorKind = entryColorKind(entry);

    if (colorKind !== 'tool_use' && colorKind !== 'tool_result') {
      return false;
    }

    return !(entry.blocks || []).some(function (b) { return !!b.image || (b.attachments && b.attachments.length > 0); });
  }

  function entryBlocksHtml(entry, isSubagent, forceFullBlock) {
    return (entry.blocks || []).map(function (b) { return renderBlock(b, entry.line, isSubagent, forceFullBlock); }).join('');
  }

  function toolCallEntryTimestamp(entry) {
    var parsedMs = entry && entry.timestamp ? Date.parse(entry.timestamp) : NaN;
    return !isNaN(parsedMs) ? escapeHtml(relativeTimeLabel(Math.floor(parsedMs / 1000))) : '';
  }

  function firstTextBearingBlock(entry) {
    if (!entry) {
      return null;
    }

    return (entry.blocks || []).filter(function (b) { return !!b.text; })[0] || null;
  }

  // Mirrors TranscriptView::relativize_path() (PHP) - a plain string-prefix
  // strip, falling back to the unmodified absolute path whenever cwd is
  // unknown or the path isn't actually under it.
  function relativizePath(path, cwd) {
    if (!cwd) {
      return path;
    }

    var prefix = cwd.replace(/\/+$/, '') + '/';

    return path.indexOf(prefix) === 0 ? path.slice(prefix.length) : path;
  }

  // Mirrors TranscriptView::tool_call_entry_summary() (PHP) - Write/Edit/
  // Read get "Write relative/path.php" / "Edit ..." / "Read ..." - natural
  // language, not "ToolName(args)" (Andres's own call 2026-08-22) -
  // block.file_path relativized against sessionCwd. Bash gets "Ran
  // truncated command" (block.command, deliberately preferred over the
  // call's own `description` param - the real command is more useful at a
  // glance). Everything else: the call's own description when it has one,
  // else its summarized text truncated via collapsibleSummary(). Falls back
  // to the RESULT's own text only for the rare orphaned-result edge case
  // (no preceding call in this batch).
  function toolCallEntrySummary(callEntry, resultEntry) {
    var callBlock = firstTextBearingBlock(callEntry);

    if (callBlock) {
      if (callBlock.tool_name && callBlock.file_path) {
        return callBlock.tool_name + ' ' + relativizePath(callBlock.file_path, sessionCwd);
      }

      if (callBlock.tool_name === 'Bash' && callBlock.command) {
        return 'Ran ' + collapsibleSummary(callBlock.command);
      }

      if (callBlock.command && (callBlock.tool_name === 'bash' || callBlock.tool_name === 'execute' || callBlock.tool_name === 'run_command')) {
        return 'Ran ' + collapsibleSummary(callBlock.command);
      }

      return callBlock.description ? callBlock.description : collapsibleSummary(callBlock.text);
    }

    var resultBlock = firstTextBearingBlock(resultEntry);

    return resultBlock ? collapsibleSummary(resultBlock.text) : 'Tool call';
  }

  // Mirrors TranscriptView::render_tool_call_entry_html() (PHP) - the
  // result half is always wrapped in its own .tool-call-result-slot, even
  // when empty (a call with no result yet), so a later-arriving result can
  // upgrade it in place via upgradeToolCallEntryWithResult() below instead
  // of appending a second card. Both halves render with forceFullBlock=true
  // (entryBlocksHtml()'s third argument) - already sitting inside this
  // entry's own outer <details>, so no SECOND nested collapse-behind-a-
  // summary-line is needed for either one.
  function renderToolCallEntry(callEntry, resultEntry) {
    var timestamp = toolCallEntryTimestamp(callEntry || resultEntry);
    var callHtml = callEntry ? entryBlocksHtml(callEntry, false, true) : '';
    var resultHtml = resultEntry ? entryBlocksHtml(resultEntry, false, true) : '';

    var details = document.createElement('details');
    details.className = 'tool-call-entry rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-2 lg:max-w-[75%] lg:self-start';
    details.innerHTML = '<summary class="select-none cursor-pointer truncate text-xs font-medium text-slate-400">' + escapeHtml(toolCallEntrySummary(callEntry, resultEntry)) + '</summary>'
      + '<div class="mt-2 flex flex-col gap-1.5">'
      + (timestamp ? '<div class="select-none text-xs text-slate-500">' + timestamp + '</div>' : '')
      + callHtml + '<div class="tool-call-result-slot"></div></div>';

    var slot = details.querySelector('.tool-call-result-slot');

    if (slot) {
      slot.innerHTML = resultHtml;
    }

    return details;
  }

  // The summary text never needs updating here - a call, when present,
  // always already won it (see toolCallEntrySummary()), and this only ever
  // fires for a call that's already rendered (with its own summary set).
  function upgradeToolCallEntryWithResult(entryEl, resultEntry) {
    var slot = entryEl.querySelector('.tool-call-result-slot');

    if (slot) {
      slot.innerHTML = entryBlocksHtml(resultEntry, false, true);
    }
  }

  function createPendingCallState() {
    return { pendingCallEl: null };
  }

  // Renders a batch of entries into `container` (a DocumentFragment for a
  // one-shot batch, or the live `list` for the poll tail), pairing each
  // tool_use with its own following tool_result via renderToolCallEntry()
  // (see the block comment above), tracked via `state.pendingCallEl`.
  // Returns the distinct top-level nodes touched (created OR extended) in
  // this call, for the caller's own new-content highlighting.
  function renderEntriesGrouped(entries, state, container) {
    var touched = [];

    entries.forEach(function (entry) {
      if (!entryIsGroupableToolCall(entry)) {
        state.pendingCallEl = null;
        var el = renderEntry(entry);
        container.appendChild(el);
        touched.push(el);

        return;
      }

      var colorKind = entryColorKind(entry);

      if (colorKind === 'tool_use') {
        // A previous call that never got its own result (rare - e.g.
        // Claude was interrupted mid-tool) stays exactly as it rendered;
        // this new call starts its own fresh pending entry rather than
        // waiting on it.
        var callEl = renderToolCallEntry(entry, null);
        container.appendChild(callEl);
        touched.push(callEl);
        state.pendingCallEl = callEl;

        return;
      }

      if (state.pendingCallEl) {
        upgradeToolCallEntryWithResult(state.pendingCallEl, entry);

        if (touched.indexOf(state.pendingCallEl) === -1) {
          touched.push(state.pendingCallEl);
        }

        state.pendingCallEl = null;

        return;
      }

      // An orphaned result with no pending call in THIS state - shouldn't
      // normally happen, but a pagination boundary or a dropped call could
      // in principle leave one.
      var resultOnlyEl = renderToolCallEntry(null, entry);
      container.appendChild(resultOnlyEl);
      touched.push(resultOnlyEl);
    });

    return touched;
  }

  // Persists across pollHistory() calls (unlike loadHistoryPage()'s own
  // throwaway createPendingCallState()) - see the block comment above for why the
  // live poll tail specifically needs this.
  var tailPendingCallState = createPendingCallState();

  // Seeds tailPendingCallState from whatever the server already rendered,
  // if the page loaded (or was refreshed) mid-tool-run - i.e. the
  // transcript's last entry so far is itself a tool-call entry with an
  // empty result slot (see TranscriptView::render_transcript_entries_
  // html(), PHP). Without this, a page load/refresh landing mid-run would
  // leave tailPendingCallState at its fresh default (believing nothing is
  // pending), so the next poll's result would append a brand new second
  // <details> right after the server-rendered one instead of upgrading it
  // in place - same call, split across two cards for no reason other than
  // "the page happened to load in the middle of it".
  (function seedTailPendingCallFromServerRender() {
    var lastEl = list ? list.lastElementChild : null;

    if (!lastEl || !lastEl.classList.contains('tool-call-entry')) {
      return;
    }

    var resultSlot = lastEl.querySelector('.tool-call-result-slot');
    // An empty slot means the server rendered a call with no result yet
    // (the tool was still running as of that render) - the one case where
    // a follow-up poll's result needs to upgrade this exact element rather
    // than start a new entry.
    var hasPendingCall = !!resultSlot && resultSlot.children.length === 0 && resultSlot.textContent === '';

    tailPendingCallState.pendingCallEl = hasPendingCall ? lastEl : null;
  })();

  // --- optimistic history entries: rendered with renderEntry() itself (so
  // a pending compose message/prompt answer looks exactly like the real
  // thing once confirmed, just dimmed), tracked in pendingEntries so
  // pollHistory() can reconcile them against real incoming data - see
  // reconcilePendingEntries() below for the matching logic. ---

  function pendingEntryText(blocks) {
    var textBlock = (blocks || []).find(function (b) { return b.kind === 'text'; });
    return textBlock ? textBlock.text : '';
  }

  // --- pending-compose-message persistence: survives a navigation away
  // and back to this same session, found live 2026-08-08 (Andres: a
  // message sent while Claude was mid-turn, still showing dimmed, was
  // gone entirely after navigating to another page and back before it
  // was confirmed). pendingEntries itself is plain in-memory JS state,
  // wiped on any real page navigation - sessionStorage is the one piece
  // that actually survives it. Only ever tracks ONE compose message at a
  // time (the textarea/send button are disabled while a send is in
  // flight, so there's never a second concurrent one from this same
  // tab) - deliberately NOT used for prompt-answer pendings, which
  // already have their own more reliable answerPendingReason-based
  // reconciliation (see the comment on that near the top of this file)
  // rather than generic text-matching. ---
  var PENDING_MESSAGE_STORAGE_KEY = 'sessioneer-pending-message-' + sessionName;
  // A restored pending bubble that's actually already long confirmed
  // (the tab closed before its own poll could reconcile+clear storage,
  // then reopened well after Claude Code wrote the real transcript line)
  // would sit forever un-reconciled - pollHistory() only ever asks the
  // server for entries newer than THIS load's own newestLine, so an
  // already-rendered confirmation can never show up in a future `fresh`
  // batch to match against. Capping how old a restored entry can be
  // avoids that: Claude Code's own write latency for a compose send is a
  // couple of seconds at most (measured live elsewhere in this file), so
  // anything older than this is far more likely already-confirmed than
  // still genuinely in flight.
  var PENDING_MESSAGE_MAX_AGE_MS = 2 * 60 * 1000;

  function savePendingMessageToStorage(role, text) {
    try {
      window.sessionStorage.setItem(PENDING_MESSAGE_STORAGE_KEY, JSON.stringify({ role: role, text: text, savedAt: Date.now() }));
    } catch (e) {}
  }

  function clearPendingMessageFromStorage() {
    try {
      window.sessionStorage.removeItem(PENDING_MESSAGE_STORAGE_KEY);
    } catch (e) {}
  }

  // Called once at page load, before polling starts - re-renders whatever
  // compose message was still unconfirmed when this tab last navigated
  // away from this session, as a normal pending entry (pollHistory()'s
  // existing reconcilePendingEntries() then clears it exactly the same
  // way as any other pending entry, once the real confirming line
  // arrives).
  function restorePendingMessageFromStorage() {
    var raw;

    try {
      raw = window.sessionStorage.getItem(PENDING_MESSAGE_STORAGE_KEY);
    } catch (e) {
      return;
    }

    if (!raw) {
      return;
    }

    var saved;

    try {
      saved = JSON.parse(raw);
    } catch (e) {
      clearPendingMessageFromStorage();
      return;
    }

    if (!saved || typeof saved.text !== 'string' || saved.text === '' || typeof saved.savedAt !== 'number' || (Date.now() - saved.savedAt) > PENDING_MESSAGE_MAX_AGE_MS) {
      clearPendingMessageFromStorage();
      return;
    }

    appendPendingEntry(saved.role, [{ kind: 'text', text: saved.text }]);
  }

  // #history-list always exists now (see session.php's own comment on the
  // container) but starts with a placeholder note ("No transcript
  // available"/"Nothing recorded yet") when there's no real content yet -
  // removed the moment anything real actually shows up, optimistic or
  // polled, so it's not still sitting there once messages exist.
  function removeHistoryEmptyNote() {
    var note = document.getElementById('history-empty-note');

    if (note && note.parentNode) {
      note.parentNode.removeChild(note);
    }
  }

  function appendPendingEntry(role, blocks) {
    if (!list) {
      return null;
    }

    removeHistoryEmptyNote();

    var wasNearBottom = isNearBottom();
    var el = renderEntry({ role: role, timestamp: new Date().toISOString(), blocks: blocks });
    el.classList.add('opacity-50');
    el.dataset.pendingRole = role;
    el.dataset.pendingText = pendingEntryText(blocks);

    var pendingNote = document.createElement('span');
    pendingNote.className = 'italic';
    pendingNote.textContent = 'Sending…';
    el.querySelector('.mb-1').appendChild(pendingNote);

    // A real user message always clears any still-pending tool call at the
    // tail (see tailPendingCallState) - otherwise a later-arriving tool
    // result would try to upgrade an entry that's no longer actually at the
    // tail of the list, inserting itself above a message the user already
    // sent.
    tailPendingCallState.pendingCallEl = null;
    list.appendChild(el);
    pendingEntries.push(el);
    maybeAutoScroll(wasNearBottom);

    return el;
  }

  // Only used to undo a pending entry after a failed send - the success
  // path never calls this directly, pollHistory() reconciles pending
  // entries against real incoming data instead (see there).
  function removePendingEntry(el) {
    if (!el) {
      return;
    }

    if (el.parentNode) {
      el.parentNode.removeChild(el);
    }

    var idx = pendingEntries.indexOf(el);

    if (idx !== -1) {
      pendingEntries.splice(idx, 1);
    }
  }

  // Called by pollHistory() with whatever fresh entries just came back -
  // a pending entry is only cleared once one of them actually matches it
  // (same role + same text), not just because SOME fresh data landed.
  // That matters because a compose send's own confirming line can take a
  // couple of seconds to actually reach the transcript file (measured
  // live - Claude Code's own write latency, nothing this app controls),
  // so an earlier poll can easily see OTHER new content first; clearing
  // every pending entry on any fresh batch made the just-sent message
  // vanish (cleared as if confirmed) without ever actually rendering the
  // real one, since it genuinely wasn't in that batch yet.
  function reconcilePendingEntries(freshEntries) {
    if (pendingEntries.length === 0) {
      return;
    }

    pendingEntries = pendingEntries.filter(function (el) {
      // Trimmed, not an exact match - Claude Code's own transcript write
      // isn't guaranteed to preserve incidental leading/trailing
      // whitespace exactly as typed, and an exact-match requirement here
      // has no recovery path at all if it ever doesn't: a pending entry
      // that never matches just sits "Sending…" forever (found live
      // 2026-08-20 alongside the actual root cause, a missing tmux
      // paste-then-Enter delay in SessionService::send_message() - this
      // is a smaller, independent robustness improvement on top of that).
      var pendingTextTrimmed = el.dataset.pendingText.trim();
      var matched = freshEntries.some(function (entry) {
        return entry.role === el.dataset.pendingRole && pendingEntryText(entry.blocks).trim() === pendingTextTrimmed;
      });

      if (matched && el.parentNode) {
        el.parentNode.removeChild(el);
      }

      if (matched && el.dataset.pendingRole === 'user') {
        clearPendingMessageFromStorage();
      }

      return !matched;
    });
  }

  // The session-info card (title/name/workdir/attached/context-used%/
  // worktree/activity) is gone (Andres's own ask, 2026-08-20) - the fixed
  // header now carries just title + cwd (see header.php), and only the
  // title half needs live updating here (an AI-generated title can appear/
  // change mid-session; cwd never changes for a session's lifetime, so
  // header.php's own server-render is the only place it's ever needed).
  // Keeps the tooltip (title attribute) in sync with the visible text too,
  // not just textContent - otherwise a later title change would leave the
  // hover tooltip showing a stale one from page load.
  function renderStaticInfo(detail) {
    if (!headerTitle) {
      return;
    }

    var title = detail.title || detail.name;
    headerTitle.textContent = title;
    headerTitle.title = title;
  }

  // Mirrors render_thinking_indicator_html() - a single transient "is it
  // doing something right now" signal, never the actual thinking content
  // (that's dropped entirely server-side), and never shown at the same
  // time as the blocked-prompt section.
  //
  // Skips the rebuild when the shown/hidden state hasn't actually changed
  // - same "no-op unless something real changed" pattern as
  // renderBlockedSection(). Found live: rebuilding on every single poll
  // (the previous behavior) tore out and replaced the Stop button on
  // every cycle even while a session just sat "working" poll after poll
  // with nothing new to show - if that landed while a stop click's own
  // fetch was still in flight, the disabled state from the click handler
  // (see the delegated #stop-btn listener below) applied to the OLD,
  // now-detached button; the freshly rebuilt one showed up enabled again
  // mid-request, opening a real double-submit window. Only mattered while
  // working (never while blocked, per the two states' own mutual
  // exclusion above), so the key is just the shown/hidden boolean, not
  // the (static, unchanging) markup itself.
  function renderThinkingIndicator(detail) {
    if (!thinkingIndicator) {
      return;
    }

    // Optimistic override (Andres's own ask, 2026-08-24): right after
    // answering a blocked prompt, the real hook-fed working/blocked state
    // can lag a poll or more behind - the three answer-send handlers below
    // (plain option, free-text reply, multi-question) call this function
    // immediately with a synthetic {working:false, blocked_reason:null}
    // detail the instant answerPendingReason is set, so the bubble shows
    // without waiting on any poll at all, then this same check keeps it
    // showing across subsequent real polls until it naturally resolves.
    // Checked against THIS CALL's own detail.blocked_reason, never against
    // whatever answerPendingReason itself currently holds (that only gets
    // cleared later, inside renderBlockedSection() - see
    // restoreAnswerPendingIfSamePrompt()) - so the instant a poll reports a
    // genuinely NEW blocked_reason, this is false regardless of
    // answerPendingReason's still-stale value, and the real blocked-prompt
    // card always wins over the optimistic bubble, never clobbered by it.
    var optimisticWorking = answerPendingReason !== null && !detail.blocked_reason;
    var shouldShow = (!!detail.working || optimisticWorking) && !detail.blocked_reason;

    if (shouldShow === lastRenderedThinkingShown) {
      return;
    }

    lastRenderedThinkingShown = shouldShow;

    if (!shouldShow) {
      thinkingIndicator.innerHTML = '';
      return;
    }

    thinkingIndicator.innerHTML = '<div class="select-none rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-2 text-xs text-slate-400 flex items-center justify-between gap-2">'
      + '<span class="flex items-center gap-2">'
      + '<span class="flex items-center gap-1">'
      + '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:0ms"></span>'
      + '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:150ms"></span>'
      + '<span class="inline-block w-1.5 h-1.5 rounded-full bg-sky-400 animate-bounce" style="animation-delay:300ms"></span>'
      + '</span>'
      + '<span>Thinking&hellip;</span>'
      + '</span>'
      + '<button type="button" id="stop-btn" class="rounded border border-red-800/60 bg-red-950/40 active:bg-red-900/60 text-red-300 text-xs font-medium px-2 py-1">Stop</button>'
      + '</div>';
  }

  // Mirrors TranscriptView::render_turn_error_html() - Antigravity-only,
  // see that method's own docblock for why this exists (a quota-exhausted
  // turn writes nothing at all to Antigravity's own transcript file, so
  // without this a failed reply looks identical to one that's just slow).
  // Same dedup-by-last-rendered-value pattern as renderThinkingIndicator()
  // above, keyed on the error text itself rather than a boolean, since the
  // shown/hidden state alone isn't enough here - two DIFFERENT errors in a
  // row (e.g. two separate quota-exhausted questions) must each actually
  // replace the card, not no-op because something was already showing.
  function renderTurnError(detail) {
    if (!turnError) {
      return;
    }

    var errorText = detail.last_turn_error || null;

    if (errorText === lastRenderedTurnError) {
      return;
    }

    lastRenderedTurnError = errorText;

    if (!errorText) {
      turnError.innerHTML = '';
      return;
    }

    turnError.innerHTML = '<div class="select-none rounded-lg border border-amber-800/60 bg-amber-950/40 px-3 py-2 text-xs text-amber-300">'
      + '<div class="font-medium mb-1">Antigravity did not reply</div>'
      + '<div class="text-amber-300/80">' + escapeHtml(errorText) + '</div>'
      + '</div>';
  }

  // Mirrors TranscriptView::render_mode_toggle_html() - options are static
  // (rendered once server-side), only the selected value/disabled state
  // changes here. Left showing its last known value (just disabled) if
  // the mode becomes unreadable after having been known - not worth a
  // placeholder swap for what's a rare, transient state.
  function renderModeToggle(detail) {
    if (!modeSelect) {
      return;
    }

    modeSelect.disabled = !detail.current_mode;

    if (detail.current_mode) {
      modeSelect.value = detail.current_mode;
    }
  }

  // Mirrors TranscriptView::render_model_toggle_html() - same shape as
  // renderModeToggle() above, just a different field/element. Never shows
  // "default" as a value (see SelectableModel::family_from_raw_model()'s
  // own docblock in host-agent for why that can't be detected).
  function renderModelToggle(detail) {
    if (!modelSelect) {
      return;
    }

    modelSelect.disabled = !detail.current_model;

    if (detail.current_model) {
      modelSelect.value = detail.current_model;
    }
  }

  // Mirrors TranscriptView::render_antigravity_model_toggle_html() - same
  // shape as renderModelToggle() above, but always re-enabled here (see
  // AntigravitySelectableModel::parse_current_model()'s own docblock -
  // unlike Claude Code's set_model(), Antigravity's picker-driving switch
  // doesn't need a known starting position, so there's nothing to
  // permanently block the dropdown on - this is also what un-disables it
  // again after the change handler below disables it for the duration of
  // a request). Left on the placeholder option whenever the current model
  // isn't currently readable from the pane (e.g. a prompt is covering the
  // footer) rather than guessing.
  function renderAntigravityModelToggle(detail) {
    if (!antigravityModelSelect) {
      return;
    }

    antigravityModelSelect.disabled = false;
    antigravityModelSelect.value = detail.current_antigravity_model || '';
  }

  // Mirrors TranscriptView::render_todo_list_html()/sidebar/todo-list.php
  // (PHP) - the sidebar's live task checklist, sourced from the top-level
  // agent's own TodoWrite OR Task-family (TaskCreate/TaskUpdate) tool
  // calls (see SessionService::session_detail()'s cascade - both normalize
  // to the same {content, activeForm, status} shape server-side, so this
  // mirror doesn't need to know which source fed detail.todos). Full
  // innerHTML rebuild each poll (unlike renderModeToggle() above, which
  // only tweaks a couple attributes on a static <select>) since the actual
  // set of tasks/their statuses can change shape between polls, not just a
  // single value. Renders nothing at all (not an empty section) when the
  // session has never called either tool, or has cleared its list back to
  // empty - same "nothing to show" treatment the PHP side gives both cases.
  function renderTodoList(detail) {
    if (!todoListSection) {
      return;
    }

    var todos = detail.todos;

    if (!todos || !todos.length) {
      todoListSection.innerHTML = '';
      return;
    }

    var html = '<div class="px-4 py-3 border-t border-slate-800">'
      + '<span class="block text-xs font-medium text-slate-500 mb-2">Tasks</span>'
      + '<div class="flex flex-col gap-1.5">';

    todos.forEach(function (todo) {
      var status = todo.status;
      var textClass = status === 'completed' ? ' text-slate-500' : ' text-slate-200';
      var iconClass = status === 'in_progress' ? ' text-indigo-400' : (status === 'completed' ? ' text-emerald-500' : ' text-slate-600');
      var icon = status === 'completed' ? '&#10003;' : (status === 'in_progress' ? '&#9679;' : '&#9675;');
      var label = escapeHtml(status === 'in_progress' ? todo.activeForm : todo.content);

      html += '<div class="flex items-start gap-2 text-sm' + textClass + '">'
        + '<span class="shrink-0 leading-5' + iconClass + '">' + icon + '</span>'
        + '<span class="break-words' + (status === 'completed' ? ' line-through' : '') + '">' + label + '</span>'
        + '</div>';
    });

    html += '</div></div>';

    todoListSection.innerHTML = html;
  }

  // Mirrors BlockedPromptView::blocked_multi_question_html() (PHP) exactly -
  // the "answer every question at once" form for a multi-question
  // AskUserQuestion prompt, built from the full hook-fed question set
  // (detail.prompt_questions), not whichever tab the pane currently has up.
  // Free-text is only offered per single-select question, same scope limit
  // as the PHP side (see PromptParser::build_multi_question_key_sequence()'s
  // own docblock for why multiSelect + free-text together isn't supported).
  function renderMultiQuestionFormHtml(sessionName, csrfToken, questions) {
    var html = '<div class="select-none multi-question-wrapper mt-2" data-session="' + escapeHtml(sessionName) + '" data-csrf-token="' + escapeHtml(csrfToken) + '">';

    questions.forEach(function (q, qIndex) {
      var options = q.options || [];
      var isMulti = q.multiSelect === true;
      var inputType = isMulti ? 'checkbox' : 'radio';
      var inputName = 'q' + qIndex + (isMulti ? '[]' : '');
      var freetextValue = options.length + 1;

      html += '<div class="mb-3 last:mb-0" data-question-index="' + qIndex + '" data-multi="' + (isMulti ? '1' : '0') + '">'
        + '<p class="text-amber-200 text-sm font-medium mb-1.5">' + escapeHtml(q.question || '') + '</p>'
        + '<div class="flex flex-col gap-1.5">';

      options.forEach(function (opt, optIndex) {
        html += '<label class="flex items-center gap-2 text-sm text-amber-100">'
          + '<input type="' + inputType + '" name="' + inputName + '" value="' + (optIndex + 1) + '" class="accent-indigo-600">'
          + '<span class="break-words">' + escapeHtml(opt.label || '') + '</span>'
          + '</label>';
      });

      if (!isMulti) {
        html += '<label class="flex items-center gap-2 text-sm text-amber-100">'
          + '<input type="radio" name="' + inputName + '" value="' + freetextValue + '" class="freetext-toggle accent-indigo-600">'
          + '<span>Type something&hellip;</span>'
          + '</label>'
          // text-base (16px), not text-sm - iOS Safari auto-zooms the
          // whole viewport in on focusing any text input rendered under
          // 16px (see sidebar.php's own copy of this comment). A
          // <textarea>, not a plain <input type="text"> - see multi-
          // question.php's (PHP) own comment for the full reasoning,
          // including why a real newline never reaches the wire
          // (collectMultiQuestionAnswers() in common.js strips it).
          + '<div class="relative mt-1">'
          + '<textarea class="freetext-input hidden w-full resize-none rounded-lg bg-slate-800 border border-slate-700 text-base text-slate-100 px-2 py-1.5" rows="2" placeholder="Type your answer&hellip;"></textarea>'
          + '<button type="button" class="expand-edit-fullscreen-btn hidden absolute top-1 right-1 w-6 h-6 flex items-center justify-center rounded text-slate-500 active:text-slate-300 text-base leading-none" aria-label="Expand to full screen" tabindex="-1">&#10530;</button>'
          + '</div>';
      }

      html += '</div></div>';
    });

    html += '<button type="button" class="multi-question-submit-btn mt-1 rounded-lg bg-indigo-600 active:bg-indigo-700 text-white text-xs font-medium px-3 py-2">Send answers</button>'
      + '</div>';

    return html;
  }

  // A multi-question AskUserQuestion prompt's card - mirrors
  // BlockedPromptView::blocked_prompt_rich_html() (PHP)'s own
  // prompt_questions branch, same reasoning: prompt_context/prompt_options
  // only ever reflect whichever tab the pane currently has up, which this
  // form doesn't need or want shown alongside every question already
  // rendered here. Carries mt-2 same as renderOptionsCardHtml()'s own card
  // below (found live 2026-08-22, codebase audit: this was missing here,
  // so a session that only BECOMES blocked mid-poll - not already blocked
  // at page load - rendered its card sitting closer to the wrapper's edge
  // than blocked-prompt/rich.php's own top-level element, which always
  // has it). No free-text-reply box or collapsible context of its own -
  // renderBlockedSection()'s dispatcher skips ALL of that restoration
  // logic for this shape, not just the HTML building.
  function renderMultiQuestionCardHtml(detail) {
    return '<div class="mt-2 rounded-lg px-3 py-2 text-xs bg-amber-900/40 text-amber-200 border border-amber-700/60">'
      + '<p class="font-medium break-words">Waiting on input: ' + escapeHtml(detail.blocked_reason) + '</p>'
      + renderMultiQuestionFormHtml(sessionName, csrfToken, detail.prompt_questions)
      + '</div>';
  }

  // The regular (single-tab) prompt shape - a numbered-option/free-text
  // card, optionally preceded by the pending command/description this
  // prompt is asking about, as its OWN entry before the card rather than
  // nested inside it (mirrors BlockedPromptView::blocked_prompt_rich_html()
  // (PHP), same reasoning there - Andres's own explicit call, 2026-08-08:
  // readability over it now reading like a real, already-happened tool_use
  // entry).
  function renderOptionsCardHtml(detail) {
    var html = '';

    if (detail.prompt_context) {
      html += '<div class="rounded-lg border border-amber-700/60 bg-amber-900/40 px-3 py-2 mb-2 lg:max-w-[75%] lg:self-start">'
        + '<div class="select-none mb-1 flex items-center gap-2 text-xs text-slate-500"><span class="font-medium text-amber-300">Awaiting approval</span></div>'
        + '<div class="flex flex-col gap-1.5">' + renderCollapsibleBlock(detail.prompt_context, 'border-amber-700/40', 'text-amber-100', '') + '</div>'
        + '</div>';
    }

    html += '<div class="mt-2 rounded-lg px-3 py-2 text-xs bg-amber-900/40 text-amber-200 border border-amber-700/60">'
      + '<p class="font-medium break-words">Waiting on input: ' + escapeHtml(detail.blocked_reason) + '</p>';

    if (detail.prompt_options && detail.prompt_options.length) {
      html += renderPromptOptionsFormHtml(detail.prompt_options);
    }

    html += '</div>';

    return html;
  }

  // The numbered-option buttons plus, when one of them is "Type
  // something.", the paired (initially hidden) free-text reply box - the
  // third visually/behaviorally distinct shape the 2026-08-23 readability
  // audit called out, nested inside renderOptionsCardHtml() above rather
  // than a top-level dispatcher branch of its own: a free-text option only
  // ever shows up ALONGSIDE the other numbered options for the same
  // prompt, never in place of them.
  function renderPromptOptionsFormHtml(options) {
    var optionsHtml = '';
    var hasFreeText = false;

    options.forEach(function (opt) {
      var label = escapeHtml(opt.label);

      if (opt.label.toLowerCase().indexOf('type something') !== -1) {
        hasFreeText = true;
        // break-words + max-w-full - see BlockedPromptView::blocked_prompt_options_html() in
        // AgentClient.php (PHP) for why both are needed together (an
        // option label has no length limit imposed by the tool itself,
        // and break-words alone doesn't help without max-w-full capping
        // the flex item's width first).
        optionsHtml += '<button type="button" class="reveal-freetext-btn rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2 break-words max-w-full text-left" data-option="' + opt.number + '">'
          + opt.number + '. ' + label
          + '</button>';
        return;
      }

      optionsHtml += '<form method="post" action="/answer_prompt.php" data-confirm-label="' + label + '">'
        + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken) + '">'
        + '<input type="hidden" name="session" value="' + escapeHtml(sessionName) + '">'
        + '<input type="hidden" name="option" value="' + opt.number + '">'
        + '<input type="hidden" name="expected_label" value="' + label + '">'
        + '<button type="submit" class="rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2 break-words max-w-full text-left">'
        + opt.number + '. ' + label
        + '</button></form>';
    });

    var html = '<div class="select-none prompt-options-wrapper mt-2" data-session="' + escapeHtml(sessionName) + '" data-csrf-token="' + escapeHtml(csrfToken) + '">'
      + '<div class="flex flex-wrap gap-2">' + optionsHtml + '</div>';

    if (hasFreeText) {
      html += '<div class="freetext-reply hidden mt-2">'
        + '<div class="relative">'
        + '<textarea class="freetext-reply-textarea w-full resize-none rounded-lg bg-slate-800 border border-slate-700 text-base text-slate-100 pl-3 pr-14 py-2" rows="2" placeholder="Type your reply&hellip;"></textarea>'
        + '<button type="button" class="expand-edit-fullscreen-btn absolute top-1 right-8 w-6 h-6 flex items-center justify-center rounded text-slate-500 active:text-slate-300 text-base leading-none" aria-label="Expand to full screen" tabindex="-1">&#10530;</button>'
        + '<button type="button" class="freetext-reply-clear-btn hidden absolute top-1 right-1 w-6 h-6 flex items-center justify-center rounded text-slate-500 active:text-slate-300 text-lg leading-none" aria-label="Clear reply" tabindex="-1">&times;</button>'
        + '</div>'
        + '<button type="button" class="freetext-reply-send-btn mt-1 rounded-lg bg-indigo-600 active:bg-indigo-700 text-white text-xs font-medium px-3 py-1.5">Send</button>'
        + '</div>';
    }

    html += '</div>';

    return html;
  }

  // Mirrors BlockedPromptView::blocked_prompt_rich_html() - the JS-side
  // counterpart feeding the same poll. One unified card (question, the
  // pending command collapsed by default, Approve/Deny buttons) - not a
  // separate bubble, which read as something that already happened
  // rather than the thing still waiting on an answer. No attach-tip
  // here: it's only shown where there are no buttons to tap instead (the
  // dashboard's folder-trust rows - see renderStaticInfo() for why this
  // page never needs that fallback). Empties the section when no longer
  // blocked, so an answered prompt disappears without a reload. The
  // actual per-shape HTML building is a small dispatcher to
  // renderMultiQuestionCardHtml()/renderOptionsCardHtml() below - see its
  // own comment partway through.
  function renderBlockedSection(detail) {
    if (!blockedSection) {
      return;
    }

    // A poll is a no-op here whenever the blocked-prompt data hasn't
    // actually changed from what's already on screen - the common case
    // while a prompt just sits unanswered, poll after poll. Skipping the
    // rebuild entirely (rather than rebuilding every time and carefully
    // trying to restore state afterward) is what actually fixes the whole
    // family of poll-during-interaction bugs found live: lost textarea
    // focus/cursor, the page scrolling back to the top, an expanded
    // command's own scroll position resetting (so only its last lines were
    // visible), a manually-opened <details> snapping shut - none of that
    // needs preserving if the DOM was never touched to begin with.
    var key = JSON.stringify([detail.blocked_reason || null, detail.prompt_context || null, detail.prompt_options || null, detail.prompt_questions || null]);

    if (key === lastRenderedBlockedKey) {
      return;
    }

    lastRenderedBlockedKey = key;

    // The rebuild below can still occasionally happen while the free-text
    // reply box is open mid-draft or the command <details> is manually
    // expanded (typically just the first poll after page load, landing on
    // the same prompt the server already rendered) - preserved as a safety
    // net for that case, same mechanism as before, just rarely exercised now.
    var existingReply = /** @type {HTMLElement|null} */ (blockedSection.querySelector('.freetext-reply'));
    var freetextWasOpen = existingReply && !existingReply.classList.contains('hidden');
    var existingTextarea = /** @type {HTMLTextAreaElement|null} */ (existingReply ? existingReply.querySelector('.freetext-reply-textarea') : null);
    var freetextDraft = existingTextarea ? existingTextarea.value : '';
    var freetextOption = existingReply ? existingReply.dataset.option : null;
    var freetextHadFocus = existingTextarea !== null && existingTextarea === document.activeElement;
    var freetextSelectionStart = freetextHadFocus ? existingTextarea.selectionStart : null;
    var freetextSelectionEnd = freetextHadFocus ? existingTextarea.selectionEnd : null;

    var existingContextDetails = blockedSection.querySelector('details');
    var contextDetailsWasOpen = existingContextDetails ? existingContextDetails.open : false;
    var existingPre = existingContextDetails ? existingContextDetails.querySelector('pre') : null;
    var contextScrollTop = existingPre ? existingPre.scrollTop : 0;

    // Page scroll position, restored (if captured) after the rebuild below
    // - only when there was actually something on screen worth not
    // yanking the user away from, never fights normal scrolling otherwise.
    var scrollYBeforeRebuild = (freetextHadFocus || contextDetailsWasOpen) ? pageContent.scrollTop : null;

    if (!detail.blocked_reason) {
      blockedSection.innerHTML = '';
      currentBlockedReason = null;
      answerPendingReason = null;
      removePendingEntry(answerPendingHistoryEl);
      answerPendingHistoryEl = null;
      return;
    }

    currentBlockedReason = detail.blocked_reason;

    // Small dispatcher (2026-08-24 readability audit follow-up) - one
    // render-function per visually/behaviorally distinct prompt shape,
    // each returning a plain HTML string with no side effects of its own.
    // Everything stateful (the dirty-check/restore dance above and below)
    // stays here in the dispatcher, not pushed into the per-shape
    // functions, since the two shapes need genuinely different post-render
    // treatment (see the isMultiQuestion branch just below).
    var isMultiQuestion = detail.prompt_questions && detail.prompt_questions.length;
    var html = isMultiQuestion ? renderMultiQuestionCardHtml(detail) : renderOptionsCardHtml(detail);
    blockedSection.innerHTML = html;

    // A multi-question AskUserQuestion prompt's card is fully self-
    // contained (renderMultiQuestionFormHtml() has no free-text-reply box
    // or collapsible context of its own to restore state into - see
    // renderMultiQuestionCardHtml()'s own comment) - nothing below this
    // point applies to it.
    if (isMultiQuestion) {
      restoreAnswerPendingIfSamePrompt();
      return;
    }

    if (freetextWasOpen) {
      var newReply = /** @type {HTMLElement|null} */ (blockedSection.querySelector('.freetext-reply'));

      if (newReply) {
        newReply.classList.remove('hidden');
        newReply.dataset.option = freetextOption;
        var newTextarea = /** @type {HTMLTextAreaElement|null} */ (newReply.querySelector('.freetext-reply-textarea'));
        newTextarea.value = freetextDraft;

        if (freetextHadFocus) {
          newTextarea.focus();
          newTextarea.setSelectionRange(freetextSelectionStart, freetextSelectionEnd);
        }
      }
    }

    // After any draft restore above, not before - wireClearButton()'s own
    // initial visibility check needs the textarea's FINAL value (the
    // restored draft, if any), not the empty one it started with fresh
    // off the innerHTML rebuild.
    var freetextReplyEl = blockedSection.querySelector('.freetext-reply');

    if (freetextReplyEl) {
      wireClearButton(freetextReplyEl.querySelector('.freetext-reply-textarea'), freetextReplyEl.querySelector('.freetext-reply-clear-btn'), 'Clear this reply?');
    }

    if (contextDetailsWasOpen) {
      var newContextDetails = blockedSection.querySelector('details');

      if (newContextDetails) {
        newContextDetails.open = true;
        var newPre = newContextDetails.querySelector('pre');

        if (newPre) {
          newPre.scrollTop = contextScrollTop;
        }
      }
    }

    if (scrollYBeforeRebuild !== null) {
      // Applied a frame later, after the browser's own focus/reflow-driven
      // scroll-into-view (if any) has already happened, so this is the
      // last word rather than getting immediately overridden by it.
      requestAnimationFrame(function () {
        pageContent.scrollTop = scrollYBeforeRebuild;
      });
    }

    // A poll can land mid-flight, between an answer being submitted and the
    // next poll actually seeing it land - since this rebuilds the section
    // from scratch every time, that would otherwise silently drop the
    // "answered, waiting to confirm" dimming the instant a same-prompt poll
    // comes back. Reapplied here as long as the SAME prompt is still
    // showing; the moment it changes or clears, the answer really did land
    // (or a new prompt replaced it), so there's nothing left to reapply.
    restoreAnswerPendingIfSamePrompt();
  }

  // A poll can land mid-flight, between an answer being submitted and the
  // next poll actually seeing it land - since renderBlockedSection() rebuilds
  // the section from scratch every time, that would otherwise silently drop
  // the "answered, waiting to confirm" dimming the instant a same-prompt
  // poll comes back. Reapplied here as long as the SAME prompt is still
  // showing; the moment it changes or clears, the answer really did land
  // (or a new prompt replaced it), so there's nothing left to reapply.
  // Factored out of renderBlockedSection()'s own tail so its early-return
  // multi-question branch (which skips the rest of that function entirely)
  // can call it too.
  function restoreAnswerPendingIfSamePrompt() {
    if (answerPendingReason !== null && answerPendingReason === currentBlockedReason) {
      markBlockedSectionAnswerPending();
    } else {
      answerPendingReason = null;
      removePendingEntry(answerPendingHistoryEl);
      answerPendingHistoryEl = null;
    }
  }

  // Dims the blocked-prompt card and disables everything in it right after
  // submitting an answer (plain option, free-text, or the multi-question
  // form), and reapplied by renderBlockedSection() above if a poll rebuilds
  // the same still-pending prompt before confirmation arrives.
  // revertBlockedSectionPending() undoes this on a failed send; a
  // successful answer needs no explicit revert - the card either gets
  // replaced (new/no prompt) or re-dimmed by the check above on the next
  // rebuild, either way never left stale.
  function markBlockedSectionPending(noteText) {
    var card = blockedSection.firstElementChild;

    if (card) {
      card.classList.add('opacity-50');

      if (!card.querySelector('.answer-pending-note')) {
        var note = document.createElement('p');
        note.className = 'select-none answer-pending-note mt-2 text-amber-300/70 italic';
        note.textContent = noteText;
        card.appendChild(note);
      }
    }

    // :not([type="hidden"]) matters - found live: the plain-option answer
    // form (blocked-prompt/options.php) carries its session/csrf_token/
    // option fields as hidden <input>s INSIDE the very <form> being
    // submitted, and disabling a field before new FormData(form) reads it
    // silently drops it from what's actually sent (disabled fields are
    // excluded from FormData/form submission entirely, per the HTML spec) -
    // broadening this selector to plain "input" without the exclusion
    // blanked out that form's own POST body before the request even left
    // the browser, even though it still LOOKED like a real request went
    // out. Regression test: test_session_replay_browser.php's "the click's
    // real submit reached /answer_prompt.php" assertion.
    blockedSection.querySelectorAll('button, textarea, select, input:not([type="hidden"])').forEach(function (el) {
      if (el instanceof HTMLButtonElement || el instanceof HTMLTextAreaElement || el instanceof HTMLSelectElement || el instanceof HTMLInputElement) el.disabled = true;
    });
  }

  function markBlockedSectionAnswerPending() {
    markBlockedSectionPending('Answered - waiting to confirm…');
  }

  function revertBlockedSectionPending() {
    var card = blockedSection.firstElementChild;

    if (card) {
      card.classList.remove('opacity-50');
      var note = card.querySelector('.answer-pending-note');

      if (note) {
        note.remove();
      }
    }

    // Same :not([type="hidden"]) exclusion as markBlockedSectionPending()
    // above, kept symmetric even though re-enabling a hidden field was
    // never itself the bug - see that function's own comment.
    blockedSection.querySelectorAll('button, textarea, select, input:not([type="hidden"])').forEach(function (el) {
      if (el instanceof HTMLButtonElement || el instanceof HTMLTextAreaElement || el instanceof HTMLSelectElement || el instanceof HTMLInputElement) el.disabled = false;
    });
  }

  // Event delegation, not per-form listeners: covers both the
  // PHP-rendered forms on first paint and any poll-rebuilt ones, without
  // needing to re-attach anything after renderBlockedSection() replaces
  // the DOM. AJAX, not a real form submission - answering a prompt is
  // common enough that a full page reload per answer would be poor UX
  // (same reasoning as compose send).
  if (blockedSection) {
    blockedSection.addEventListener('submit', function (e) {
      var form = closestEventTarget(e, 'form[data-confirm-label]');

      if (!form) {
        return;
      }

      e.preventDefault();

      if (shouldConfirmBeforeAnswer() && !confirm('Send "' + form.dataset.confirmLabel + '" to this session?')) {
        return;
      }

      answerPendingReason = currentBlockedReason;
      markBlockedSectionAnswerPending();
      // See renderThinkingIndicator()'s own comment - shows the optimistic
      // thinking bubble immediately, without waiting on any poll.
      renderThinkingIndicator({ working: false, blocked_reason: null });
      var pendingEl = appendPendingEntry('user', [{ kind: 'text', text: form.dataset.confirmLabel }]);
      answerPendingHistoryEl = pendingEl;

      postAnswerPrompt(new FormData(form), 'answer-prompt')
        .then(function (data) {
          if (data && data.ok) {
            // The request only waits for the keys to be *sent*, not for
            // Claude Code to actually process them and redraw past the
            // prompt - polling immediately can still catch the old,
            // now-stale blocked state (found live: the prompt appeared
            // "stuck" until the next regular poll tick, up to the full
            // interval later). Same fix as the mode-select's redraw wait.
            setTimeout(pollOnce, 300);
          } else {
            alert((data && data.message) || 'Failed to send answer.');
            answerPendingReason = null;
            renderThinkingIndicator({ working: false, blocked_reason: currentBlockedReason });
            answerPendingHistoryEl = null;
            removePendingEntry(pendingEl);
            revertBlockedSectionPending();
          }
        })
        .catch(function () {
          alert('Network error - answer not sent.');
          answerPendingReason = null;
          renderThinkingIndicator({ working: false, blocked_reason: currentBlockedReason });
          answerPendingHistoryEl = null;
          removePendingEntry(pendingEl);
          revertBlockedSectionPending();
        });
    });
  }

  // --- free-text reply (the "Type something." option) - revealing the
  // textarea is its own deliberate step, so unlike the plain option
  // buttons above, sending it skips the confirm() dialog. ---
  if (blockedSection) {
    function submitFreetextReply(replyDiv) {
      var wrapper = replyDiv.closest('.prompt-options-wrapper');
      var textarea = replyDiv.querySelector('.freetext-reply-textarea');
      var sendBtn = replyDiv.querySelector('.freetext-reply-send-btn');
      var text = textarea.value;

      if (text.trim() === '') {
        return;
      }

      textarea.disabled = true;
      sendBtn.disabled = true;

      answerPendingReason = currentBlockedReason;
      markBlockedSectionAnswerPending();
      renderThinkingIndicator({ working: false, blocked_reason: null });
      var pendingEl = appendPendingEntry('user', [{ kind: 'text', text: text }]);
      answerPendingHistoryEl = pendingEl;

      postAnswerPrompt({
        session: wrapper.dataset.session,
        csrf_token: wrapper.dataset.csrfToken,
        option: replyDiv.dataset.option,
        text: text
      }, 'answer-prompt-freetext')
        .then(function (data) {
          if (data && data.ok) {
            // See the plain-option handler above for why this waits a beat
            // before polling instead of polling immediately.
            setTimeout(pollOnce, 300);
          } else {
            alert((data && data.message) || 'Failed to send reply.');
            textarea.disabled = false;
            sendBtn.disabled = false;
            answerPendingReason = null;
            renderThinkingIndicator({ working: false, blocked_reason: currentBlockedReason });
            answerPendingHistoryEl = null;
            removePendingEntry(pendingEl);
            revertBlockedSectionPending();
          }
        })
        .catch(function () {
          alert('Network error - reply not sent.');
          textarea.disabled = false;
          sendBtn.disabled = false;
          answerPendingReason = null;
          renderThinkingIndicator({ working: false, blocked_reason: currentBlockedReason });
          answerPendingHistoryEl = null;
          removePendingEntry(pendingEl);
          revertBlockedSectionPending();
        });
    }

    // Collects every question's answer (collectMultiQuestionAnswers() -
    // common.js, shared with index.js's own copy of this function) and
    // sends the whole set in one POST - see SessionService::
    // answer_multi_question(). Client-side "every question answered"
    // validation only - the host-agent re-validates every answer against
    // the real questions itself regardless.
    function submitMultiQuestionAnswers(wrapper) {
      var collected = collectMultiQuestionAnswers(wrapper);

      if (collected === null) {
        alert('Please answer every question before sending.');
        return;
      }

      answerPendingReason = currentBlockedReason;
      markBlockedSectionAnswerPending();
      renderThinkingIndicator({ working: false, blocked_reason: null });
      var pendingEl = appendPendingEntry('user', [{ kind: 'text', text: collected.summaryParts.join('\n') }]);
      answerPendingHistoryEl = pendingEl;

      postAnswerMultiQuestion(wrapper.dataset.session, wrapper.dataset.csrfToken, collected.answers, 'answer-multi-question')
        .then(function (data) {
          if (data && data.ok) {
            // Same reasoning as the plain-option handler above - the
            // request only waits for the keys to be sent, not for Claude
            // Code to actually process the whole sequence and redraw past
            // the prompt.
            setTimeout(pollOnce, 300);
          } else {
            alert((data && data.message) || 'Failed to send answers.');
            answerPendingReason = null;
            renderThinkingIndicator({ working: false, blocked_reason: currentBlockedReason });
            answerPendingHistoryEl = null;
            removePendingEntry(pendingEl);
            revertBlockedSectionPending();
          }
        })
        .catch(function () {
          alert('Network error - answers not sent.');
          answerPendingReason = null;
          renderThinkingIndicator({ working: false, blocked_reason: currentBlockedReason });
          answerPendingHistoryEl = null;
          removePendingEntry(pendingEl);
          revertBlockedSectionPending();
        });
    }

    blockedSection.addEventListener('click', function (e) {
      var revealBtn = closestEventTarget(e, '.reveal-freetext-btn');

      if (revealBtn) {
        var replyDiv = revealBtn.closest('.prompt-options-wrapper').querySelector('.freetext-reply');
        replyDiv.dataset.option = revealBtn.dataset.option;
        replyDiv.classList.toggle('hidden');

        if (!replyDiv.classList.contains('hidden')) {
          replyDiv.querySelector('.freetext-reply-textarea').focus();
        }

        return;
      }

      var sendBtn = closestEventTarget(e, '.freetext-reply-send-btn');

      if (sendBtn) {
        submitFreetextReply(sendBtn.closest('.freetext-reply'));
        return;
      }

      var submitBtn = closestEventTarget(e, '.multi-question-submit-btn');

      if (submitBtn) {
        submitMultiQuestionAnswers(submitBtn.closest('.multi-question-wrapper'));
      }
    });

    // Toggling any radio/checkbox in a multi-question question's group
    // shows/hides that question's own free-text input - handleMultiQuestionFreetextToggle()
    // (common.js, shared with index.js's own delegated listener) does the
    // actual work; only the listener registration itself is per-page.
    blockedSection.addEventListener('change', function (e) {
      handleMultiQuestionFreetextToggle(e.target);
    });

    // Plain Enter inserts a newline (the browser's own default - no
    // handling needed here); only Shift+Enter submits, same convention as
    // the compose box. shiftKeyPhysicallyHeld cross-check - see its own
    // doc comment in common.js.
    blockedSection.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.shiftKey && shiftKeyPhysicallyHeld && eventTargetHasClass(e, 'freetext-reply-textarea')) {
        e.preventDefault();
        submitFreetextReply(closestEventTarget(e, '.freetext-reply'));
      }
    });
  }

  var LOAD_MORE_BTN_LABEL = 'Load older messages';
  var LOAD_UNTIL_USER_BTN_LABEL = 'Load until last message';

  // Shared by both #load-more-btn and #load-until-user-btn (Andres's own
  // ask 2026-08-24: a faster way to back-page than clicking "Load older
  // messages" repeatedly, when what's actually wanted is "get back to the
  // start of the most recent real exchange") - they page the exact same
  // cursor (btn.dataset.before is the single source of truth for both;
  // #load-until-user-btn has no dataset.before of its own), only which one
  // moves it and how far differs. $untilUser=true adds until_user=1, which
  // makes the host-agent side (TranscriptService::read_transcript_page())
  // ignore the normal 30-entry limit and instead keep walking backward
  // until it includes a real, human-typed user message - the response
  // shape is identical either way, so nothing else here needs to know
  // which mode a given page actually came from.
  function loadHistoryPage(untilUser) {
    var beforeCursor = btn.dataset.before;
    var clickedBtn = untilUser ? untilUserBtn : btn;

    btn.disabled = true;

    if (untilUserBtn) {
      untilUserBtn.disabled = true;
    }

    clickedBtn.textContent = 'Loading…';

    var url = '/session_history.php?session=' + encodeURIComponent(sessionName) + '&limit=30'
      + (beforeCursor ? '&before=' + encodeURIComponent(beforeCursor) : '')
      + (untilUser ? '&until_user=1' : '');

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          clickedBtn.textContent = (data && data.message) || 'Could not load more.';
          return;
        }

        // A fresh, throwaway pending-call state (not tailPendingCallState,
        // which tracks the LIVE poll tail) - a batch of OLDER entries being
        // prepended above everything already shown must never merge into
        // (or be merged into) whatever's already rendered there.
        var fragment = document.createDocumentFragment();
        var loadedElements = renderEntriesGrouped(data.entries || [], createPendingCallState(), fragment);
        list.insertBefore(fragment, list.firstChild);
        highlightLoadedOlderContent(loadedElements);

        if (data.has_more && data.next_before !== null) {
          btn.dataset.before = data.next_before;
          btn.disabled = false;
          btn.textContent = LOAD_MORE_BTN_LABEL;

          if (untilUserBtn) {
            untilUserBtn.disabled = false;
            untilUserBtn.textContent = LOAD_UNTIL_USER_BTN_LABEL;
          }
        } else {
          btn.classList.add('hidden');

          if (untilUserBtn) {
            untilUserBtn.classList.add('hidden');
          }
        }
      })
      .catch(function () {
        btn.disabled = false;

        if (untilUserBtn) {
          untilUserBtn.disabled = false;
        }

        clickedBtn.textContent = 'Network error - try again';
      });
  }

  if (btn) {
    btn.addEventListener('click', function () {
      loadHistoryPage(false);
    });
  }

  if (untilUserBtn) {
    untilUserBtn.addEventListener('click', function () {
      loadHistoryPage(true);
    });
  }

  // --- visibility-gated polling: refreshes the info/blocked-prompt panel
  // and appends any new messages, but only while this tab is the visible,
  // foregrounded one - cleared on hidden, restarted (with an immediate
  // refresh) on visible, so a background tab doesn't keep hitting the
  // socket for nobody. ---
  var pollTimer = null; // pending setTimeout ID for the next cycle, or null while a cycle's own requests are in flight (nothing pending to clear right then)
  var pollingActive = false; // whether polling should keep going - distinct from pollTimer, which is null during a cycle's in-flight window
  // pollAbortController itself is declared in sidebar.js now (needed early
  // by its own refreshSidebarNotification()) - reassigned here (not
  // redeclared with `var`) each time polling (re)starts, so a lingering
  // abort from a previous stop can't affect a fresh one.
  pollAbortController = new AbortController();
  var pollRunning = false; // true while a pollOnce() cycle's requests are actually in flight - see pollOnce()'s own re-entrancy guard
  var pollQueuedAgain = false; // a pollOnce() call arrived while one was already running - run exactly one more pass once the current one finishes

  // Wipes the rendered history and pagination state clean, same in spirit
  // to how a real terminal clears on /clear - called once a rotation to a
  // brand new transcript file is detected (see currentAgentSessionId
  // above), since every already-rendered entry belongs to the now-
  // abandoned old file and the "Load older messages" cursor (btn.dataset.
  // before) points into it too.
  function resetHistoryForRotatedTranscript() {
    if (list) {
      list.innerHTML = '';
    }

    newestLine = null;
    pendingEntries = [];
    currentDivider = null;

    if (btn) {
      btn.classList.add('hidden');
    }

    if (untilUserBtn) {
      untilUserBtn.classList.add('hidden');
    }

  }

  function pollInfo(wasNearBottom) {
    return fetch('/session_detail.php?session=' + encodeURIComponent(sessionName), { credentials: 'same-origin', signal: pollAbortController.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          if (typeof data.agent_session_id === 'string' && data.agent_session_id !== '') {
            if (currentAgentSessionId !== null && data.agent_session_id !== currentAgentSessionId) {
              resetHistoryForRotatedTranscript();
            }

            currentAgentSessionId = data.agent_session_id;
          }

          renderStaticInfo(data);

          // maybeAutoScroll() below used to run unconditionally, every
          // single poll cycle, whether or not this poll's own data
          // actually changed anything - found live 2026-08-19: Andres
          // reported the scrollable transcript area visibly "jittering"
          // while typing a message, which is exactly what a smooth
          // scrollTo() re-firing every poll interval looks like even when
          // the target position hasn't meaningfully moved. The thinking
          // indicator, the blocked-prompt card, and (Antigravity only) the
          // turn-error card are the ones that actually add scrollable
          // content near the bottom worth auto-scrolling for -
          // renderModeToggle()/renderComposeVisibility() never do, and
          // renderStaticInfo() above touches the top of the page, not the
          // bottom - so this only calls it when one of those three actually
          // changed (all three already track their own last-rendered
          // key/state for their own poll-no-op dedup, reused here rather
          // than duplicating it).
          var thinkingShownBefore = lastRenderedThinkingShown;
          var blockedKeyBefore = lastRenderedBlockedKey;
          var turnErrorBefore = lastRenderedTurnError;

          renderThinkingIndicator(data);
          renderTurnError(data);
          renderModeToggle(data);
          renderModelToggle(data);
          renderAntigravityModelToggle(data);
          renderTodoList(data);
          renderBlockedSection(data);
          renderComposeVisibility(data);

          if (lastRenderedThinkingShown !== thinkingShownBefore || lastRenderedBlockedKey !== blockedKeyBefore || lastRenderedTurnError !== turnErrorBefore) {
            maybeAutoScroll(wasNearBottom);
          } else {
            updateGoToBottomVisibility();
          }
        }
      })
      .catch(function () {});
  }

  // highlightJumpTarget()/makeSeenFadeObserver()/highlightLoadedOlderContent()/
  // updateJumpToNewVisibility()/jumpToNewContent()/markNewContent() and the
  // "New" divider/jump-to-new-btn state now live in highlights.js (loaded
  // before this file - see session.php), plain global functions/vars, same
  // convention as common.js/scroll.js - extracted 2026-08-24, fourth cut of
  // the "split session.js into modules" pass. currentDivider is also
  // declared there now; resetHistoryForRotatedTranscript()'s own
  // `currentDivider = null;` below falls through to that global, same
  // pattern already used for pageContent (scroll.js) and escapeHtml()
  // (common.js).

  function pollHistory(wasNearBottom) {
    if (!list) {
      return Promise.resolve(); // no transcript for this session - nothing to append to
    }

    // Once there's a known newestLine, ask the server for only what's newer
    // than it (see TranscriptService::read_transcript_page_since() on the
    // host-agent side) instead of re-fetching and re-filtering the same
    // recent window every single poll cycle - only the very first poll of a
    // session with no history at all yet (newestLine still null) falls back
    // to the plain "most recent N" fetch.
    var url = '/session_history.php?session=' + encodeURIComponent(sessionName) + '&limit=50'
      + (newestLine !== null ? '&after=' + newestLine : '');

    return fetch(url, { credentials: 'same-origin', signal: pollAbortController.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          return;
        }

        // The server already guarantees every entry is newer than
        // newestLine (via &after= above) once that's known - this filter
        // only still does real work on the null-newestLine bootstrap poll,
        // where nothing's rendered yet and everything returned is "fresh".
        var fresh = (data.entries || []).filter(function (entry) {
          return newestLine === null || entry.line > newestLine;
        });

        if (fresh.length === 0) {
          return;
        }

        removeHistoryEmptyNote();
        reconcilePendingEntries(fresh);

        // tailPendingCallState persists across poll cycles (unlike
        // loadHistoryPage()'s own throwaway state) - see the block comment on it
        // for why: a call and its own result routinely land in separate
        // poll cycles, and this is what lets a later result upgrade an
        // already-rendered entry in place instead of appending a second
        // card.
        var fragment = document.createDocumentFragment();
        var touchedElements = renderEntriesGrouped(fresh, tailPendingCallState, fragment);
        fresh.forEach(function (entry) { newestLine = entry.line; });
        var firstNewNode = fragment.firstChild;
        list.appendChild(fragment);
        markNewContent(firstNewNode, touchedElements);
        maybeAutoScroll(wasNearBottom);
      })
      .catch(function () {});
  }

  // Self-rescheduling (setTimeout, not setInterval) rather than a fixed
  // tick: the next cycle is only queued once this one's requests have all
  // actually come back, so a slow response (or a fast interval like the
  // 1s option) can never pile up overlapping in-flight requests - each
  // cycle waits its full interval AFTER the previous one finishes, not on
  // a fixed clock regardless of how long that previous one took.
  //
  // That guarantee only ever covered the regular timer's own cycle()
  // calls, though - sendComposedMessage() also calls pollOnce() directly,
  // to pick up the just-sent message right away rather than waiting for
  // the next tick. If a scheduled cycle was already in flight at that
  // exact moment, two genuinely concurrent pollHistory() calls could
  // race: each computes `fresh`/reconciles pendingEntries against its own
  // snapshot of the shared, mutable `newestLine`, which is read again at
  // response-processing time rather than pinned to what it was when that
  // response's own fetch was issued - found live 2026-08-08 as the cause
  // of an optimistic "Sending…" bubble surviving alongside the real,
  // already-confirmed entry once both arrived close together. The guard
  // below makes pollOnce() itself re-entrant-safe regardless of caller:
  // a call that arrives while one's already running just marks "run
  // once more after this" instead of starting a second overlapping pass.
  function pollOnce() {
    if (pollRunning) {
      pollQueuedAgain = true;

      return Promise.resolve();
    }

    pollRunning = true;

    // Captured once, synchronously, before either fetch fires - both
    // independent responses use this same snapshot so a poll cycle either
    // scrolls once (if the user was at the bottom when it started) or not
    // at all, never a half-scrolled-then-not gap.
    var wasNearBottom = isNearBottom();

    // Uploaded files only need refetching while the sidebar's actually
    // open and showing them - same visibility gate the swipe-gesture
    // code already uses elsewhere for "is the sidebar open right now".
    var sidebarCurrentlyOpen = sidebar && !sidebar.classList.contains('translate-x-full');

    // refreshSidebarNotification() deliberately does NOT ride this cycle -
    // it has its own much slower timer in sidebar.js (see
    // startSidebarNotifyPolling() there for why).
    return Promise.all([
      pollInfo(wasNearBottom),
      pollHistory(wasNearBottom),
      sidebarCurrentlyOpen ? loadUploadedFiles() : Promise.resolve(),
      sidebarCurrentlyOpen ? loadPlanFiles() : Promise.resolve()
    ]).finally(function () {
      pollRunning = false;

      if (pollQueuedAgain) {
        pollQueuedAgain = false;
        pollOnce();
      }
    });
  }

  function startPolling() {
    // Its own timer, so it starts even if the fast cycle is already
    // running (and keeps its own independent cadence once going).
    startSidebarNotifyPolling();

    if (pollingActive) {
      return;
    }

    pollingActive = true;
    pollAbortController = new AbortController();

    function cycle() {
      pollOnce().finally(function () {
        if (pollingActive) {
          pollTimer = setTimeout(cycle, pollIntervalMs);
        }
      });
    }

    cycle();
  }

  function stopPolling() {
    stopSidebarNotifyPolling();

    if (!pollingActive) {
      return;
    }

    pollingActive = false;

    if (pollTimer !== null) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }

    pollAbortController.abort();
  }

  // Belt and suspenders on top of stopPolling()'s abort (which only fires
  // for an explicit hidden/switch-away while this script is still alive) -
  // guarantees any poll mid-flight is cancelled the instant the browser
  // actually tears the page down, e.g. navigating to a different session
  // via the sidebar.
  window.addEventListener('pagehide', function () {
    pollAbortController.abort();
  });

  // Changing the interval mid-poll restarts the timer at the new rate
  // immediately, rather than waiting out whatever was left of the old one.
  var pollIntervalSelect = document.getElementById('poll-interval-select');

  if (pollIntervalSelect) {
    pollIntervalSelect.value = String(pollIntervalMs);

    pollIntervalSelect.addEventListener('change', function () {
      var chosen = parseInt(pollIntervalSelect.value, 10);

      if (POLL_INTERVAL_ALLOWED_MS.indexOf(chosen) === -1) {
        return;
      }

      pollIntervalMs = chosen;

      try {
        window.localStorage.setItem(POLL_INTERVAL_STORAGE_KEY, String(chosen));
      } catch (e) {}

      var wasPolling = pollTimer !== null;
      stopPolling();

      if (wasPolling) {
        startPolling();
      }
    });
  }

  // --- message compose bar: sends free text to the session at any time,
  // same as attaching and typing - see send_message() in Sessions.php for
  // why a tmux paste-buffer is used instead of send-keys with the raw
  // text. AJAX, not a page reload per send (unlike Kill/Approve, which
  // are rare enough that a reload is fine) - this is the primary,
  // repeated interaction the compose box exists for. ---
  var composeTextarea = document.getElementById('compose-textarea');
  var composeSendBtn = document.getElementById('compose-send-btn');
  var composeStatus = document.getElementById('compose-status');
  var composeAttachmentsPreview = document.getElementById('compose-attachments-preview');

  if (composeTextarea && composeSendBtn) {
    var COMPOSE_MAX_HEIGHT_PX = 128; // matches max-h-32
    var COMPOSE_DRAFT_KEY = 'sessioneer-compose-draft-' + sessionName;
    var COMPOSE_ATTACHMENTS_KEY = 'sessioneer-compose-attachments-' + sessionName;

    // Files uploaded via the "+" button but not yet sent - shown as their
    // own removable chips above the textarea (see renderComposeAttachments()
    // below), not appended as visible "[Attached: ...]" text into the
    // user's own draft the way this used to work. That text still reaches
    // Claude - SessionService::send_message() (host-agent) adds it silently
    // right before the message is actually sent, from the plain paths this
    // array tracks, so it's real bookkeeping Claude needs but never
    // something the user has to see or accidentally edit/delete themselves.
    var pendingAttachments = []; // {path, filename, size}

    function autoGrowCompose() {
      composeTextarea.style.height = 'auto';
      composeTextarea.style.height = Math.min(composeTextarea.scrollHeight, COMPOSE_MAX_HEIGHT_PX) + 'px';
    }

    // Dims/disables Send whenever there's nothing (or only whitespace) AND
    // no pending attachment to send - an attachment-only send (no typed
    // text at all) is valid, same as SessionService::send_message() allows
    // server-side.
    function updateSendButtonState() {
      composeSendBtn.disabled = composeTextarea.value.trim() === '' && pendingAttachments.length === 0;
    }

    // Per-session draft, so it survives navigating to the dashboard or
    // switching sessions via the sidebar and coming back - lost otherwise,
    // since the textarea itself doesn't persist across a page load.
    function saveComposeDraft() {
      try {
        if (composeTextarea.value) {
          window.localStorage.setItem(COMPOSE_DRAFT_KEY, composeTextarea.value);
        } else {
          window.localStorage.removeItem(COMPOSE_DRAFT_KEY);
        }
      } catch (e) {
        // Private browsing / storage disabled - draft just isn't persisted.
      }
    }

    function clearComposeDraft() {
      try {
        window.localStorage.removeItem(COMPOSE_DRAFT_KEY);
      } catch (e) {}
    }

    // Same per-session persistence as the typed draft above, its own
    // separate key - an upload made, then the page reloaded/navigated away
    // from before Send was pressed, shouldn't silently lose track of a file
    // that's already sitting in .claude/uploads/ waiting to be referenced.
    function saveComposeAttachments() {
      try {
        if (pendingAttachments.length > 0) {
          window.localStorage.setItem(COMPOSE_ATTACHMENTS_KEY, JSON.stringify(pendingAttachments));
        } else {
          window.localStorage.removeItem(COMPOSE_ATTACHMENTS_KEY);
        }
      } catch (e) {}
    }

    function clearComposeAttachments() {
      pendingAttachments = [];

      try {
        window.localStorage.removeItem(COMPOSE_ATTACHMENTS_KEY);
      } catch (e) {}
    }

    function renderComposeAttachments() {
      if (!composeAttachmentsPreview) {
        return;
      }

      if (pendingAttachments.length === 0) {
        composeAttachmentsPreview.innerHTML = '';
        composeAttachmentsPreview.classList.add('hidden');
        return;
      }

      composeAttachmentsPreview.classList.remove('hidden');
      composeAttachmentsPreview.innerHTML = pendingAttachments.map(function (a, i) {
        return '<span class="inline-flex items-center gap-1.5 rounded-lg border border-slate-700 bg-slate-800 text-xs text-slate-300 pl-2 pr-1 py-1">'
          + '<span class="truncate max-w-[10rem]">' + escapeHtml(a.filename) + '</span>'
          + '<span class="shrink-0 text-slate-500">' + escapeHtml(formatFileSize(a.size)) + '</span>'
          + '<button type="button" class="remove-compose-attachment-btn select-none shrink-0 rounded-full w-5 h-5 flex items-center justify-center text-slate-400 active:text-red-300 active:bg-red-900/40" data-index="' + i + '" aria-label="Remove ' + escapeHtml(a.filename) + '">&times;</button>'
          + '</span>';
      }).join('');
    }

    try {
      var savedDraft = window.localStorage.getItem(COMPOSE_DRAFT_KEY);

      if (savedDraft) {
        composeTextarea.value = savedDraft;
        autoGrowCompose();
      }
    } catch (e) {}

    try {
      var savedAttachments = window.localStorage.getItem(COMPOSE_ATTACHMENTS_KEY);

      if (savedAttachments) {
        pendingAttachments = JSON.parse(savedAttachments) || [];
        renderComposeAttachments();
      }
    } catch (e) {}

    updateSendButtonState();

    function setComposeStatus(text) {
      if (text) {
        composeStatus.textContent = text;
        composeStatus.classList.remove('hidden');
      } else {
        composeStatus.textContent = '';
        composeStatus.classList.add('hidden');
      }
    }

    function sendComposedMessage() {
      var text = composeTextarea.value;

      if (text.trim() === '' && pendingAttachments.length === 0) {
        return;
      }

      composeTextarea.disabled = true;
      composeSendBtn.disabled = true;
      setComposeStatus('');

      // Mirrors SessionService::send_message()'s own "[Attached: path]"
      // line-building (host-agent) so the optimistic bubble shown here
      // already matches what the real transcript entry will read once it
      // actually arrives, even though the user's own draft never showed
      // this text at any point.
      var attachmentLines = pendingAttachments.map(function (a) { return '[Attached: ' + a.path + ']'; });
      var optimisticText = attachmentLines.length === 0 ? text : (text ? text.replace(/\s*$/, '') + '\n' : '') + attachmentLines.join('\n');

      var body = new URLSearchParams({ session: sessionName, csrf_token: csrfToken, message: text });
      pendingAttachments.forEach(function (a) { body.append('attachments[]', a.path); });

      var pendingEl = appendPendingEntry('user', [{ kind: 'text', text: optimisticText }]);
      savePendingMessageToStorage('user', optimisticText);

      fetch('/session_send.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        // keepalive: a plain page navigation (this app has no client-side
        // router - see CLAUDE.md - so leaving this session is always a
        // real browser navigation/unload) can otherwise abort an in-flight
        // fetch outright, not just lose track of it client-side - found
        // live 2026-08-08 as the likely cause behind a compose message
        // reported as genuinely gone, not just visually stale, after
        // navigating away fast enough. keepalive lets the request finish
        // even once the page that started it is gone. Body is a short
        // compose message/attachment-path list, well under the spec's
        // ~64KB keepalive request-body cap.
        keepalive: true,
        body: body.toString()
      })
        .then(function (r) { return parseJsonResponse(r, 'compose-send'); })
        .then(function (data) {
          if (data && data.ok) {
            composeTextarea.value = '';
            autoGrowCompose();
            clearComposeDraft();
            clearComposeAttachments();
            renderComposeAttachments();
            pollOnce(); // pick up the new message (and whatever happens next) right away, not on the next 15s tick
          } else {
            removePendingEntry(pendingEl);
            clearPendingMessageFromStorage();
            setComposeStatus((data && data.message) || 'Failed to send message.');
          }
        })
        .catch(function () {
          removePendingEntry(pendingEl);
          clearPendingMessageFromStorage();
          setComposeStatus('Network error - message not sent.');
        })
        .finally(function () {
          composeTextarea.disabled = false;
          updateSendButtonState();
          composeTextarea.focus();
        });
    }

    composeTextarea.addEventListener('input', function () {
      autoGrowCompose();
      saveComposeDraft();
      updateSendButtonState();
    });
    composeSendBtn.addEventListener('click', sendComposedMessage);
    wireClearButton(composeTextarea, document.getElementById('compose-textarea-clear-btn'), 'Clear this message?');

    // Plain Enter inserts a newline (the browser's own default - no
    // handling needed here); only Shift+Enter submits. The opposite of the
    // usual chat-box convention, deliberately: multi-line messages are
    // common enough here (pasted logs/commands) that submit-on-Enter kept
    // firing mid-paste/mid-thought. shiftKeyPhysicallyHeld cross-check -
    // see its own doc comment in common.js.
    composeTextarea.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && e.shiftKey && shiftKeyPhysicallyHeld) {
        e.preventDefault();
        sendComposedMessage();
      }
    });

    // --- attach files: uploads via /upload_file.php (relayed to the
    // host-agent, which writes into the session's own project workdir -
    // see save_uploaded_file() in Sessions.php), then adds each to
    // pendingAttachments as its own removable chip above the textarea
    // (renderComposeAttachments() above) - the actual "[Attached: path]"
    // text Claude needs is only ever added server-side at send time (see
    // sendComposedMessage() and SessionService::send_message()). ---
    var composeAttachBtn = document.getElementById('compose-attach-btn');
    var composeFileInput = document.getElementById('compose-file-input');
    var composeUploadStatus = document.getElementById('compose-upload-status');

    if (composeAttachBtn && composeFileInput && composeUploadStatus) {
      function setUploadStatus(text) {
        if (text) {
          composeUploadStatus.textContent = text;
          composeUploadStatus.classList.remove('hidden');
        } else {
          composeUploadStatus.textContent = '';
          composeUploadStatus.classList.add('hidden');
        }
      }

      function addPendingAttachment(path, filename, size) {
        pendingAttachments.push({ path: path, filename: filename, size: size });
        saveComposeAttachments();
        renderComposeAttachments();
        updateSendButtonState();

        // Immediate refresh (don't wait for the next poll tick) if the
        // sidebar's open and showing the list this upload just changed.
        if (sidebar && !sidebar.classList.contains('translate-x-full')) {
          loadUploadedFiles();
        }
      }

      // Resolves to true/false (success), never rejects - each file's
      // failure is reported via setUploadStatus() and shouldn't stop the
      // rest of a multi-file selection from still being attempted.
      function uploadOneFile(file) {
        var formData = new FormData();
        formData.append('session', sessionName);
        formData.append('csrf_token', csrfToken);
        formData.append('file', file);

        return fetch('/upload_file.php', { method: 'POST', credentials: 'same-origin', body: formData })
          .then(function (r) { return parseJsonResponse(r, 'upload-file'); })
          .then(function (data) {
            if (data && data.ok) {
              addPendingAttachment(data.path, data.filename, data.size);
              return true;
            }

            setUploadStatus('Failed to upload ' + file.name + ': ' + ((data && data.message) || 'Unknown error'));
            return false;
          })
          .catch(function () {
            setUploadStatus('Network error - ' + file.name + ' not uploaded.');
            return false;
          });
      }

      // Removing a pending (not-yet-sent) attachment deletes the real
      // uploaded file too, not just its chip - otherwise a changed-my-mind
      // upload sits abandoned in .claude/uploads/ forever with no other way
      // to clean it up. Delegated (not bound directly to each chip's own
      // button) since chips are rebuilt wholesale on every render.
      document.addEventListener('click', function (e) {
        var removeBtn = closestEventTarget(e, '.remove-compose-attachment-btn');

        if (!removeBtn) {
          return;
        }

        var index = parseInt(removeBtn.dataset.index, 10);
        var removed = pendingAttachments.splice(index, 1)[0];
        saveComposeAttachments();
        renderComposeAttachments();
        updateSendButtonState();

        if (!removed) {
          return;
        }

        fetch('/delete_uploaded_file.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, filename: removed.filename }).toString()
        }).catch(function () {});

        if (sidebar && !sidebar.classList.contains('translate-x-full')) {
          loadUploadedFiles();
        }
      });

      composeAttachBtn.addEventListener('click', function () {
        composeFileInput.click();
      });

      composeFileInput.addEventListener('change', function () {
        var files = Array.prototype.slice.call(composeFileInput.files || []);

        if (files.length === 0) {
          return;
        }

        composeAttachBtn.disabled = true;
        var hadError = false;

        // Sequential, not Promise.all - keeps the appended attachment
        // lines in a stable, predictable order even if individual upload
        // response times vary, and avoids hammering the host-agent with
        // N simultaneous file writes for one multi-file selection.
        files.reduce(function (chain, file, index) {
          return chain.then(function () {
            setUploadStatus('Uploading ' + file.name + ' (' + (index + 1) + '/' + files.length + ')…');

            return uploadOneFile(file).then(function (ok) {
              if (!ok) {
                hadError = true;
              }
            });
          });
        }, Promise.resolve())
          .then(function () {
            if (!hadError) {
              setUploadStatus('');
            }
          })
          .finally(function () {
            composeAttachBtn.disabled = false;
            composeFileInput.value = '';
          });
      });
    }
  }

  // --- mode select: jumps directly to the chosen mode (set_mode() in
  // Sessions.php works out the Shift+Tab steps and sends them, spaced
  // 300ms apart - verified live that back-to-back presses with no gap
  // get dropped). The request blocks until every press is sent, so by
  // the time it resolves the mode has already changed - the extra 300ms
  // below is just for Claude Code's last status-line redraw to land
  // before polling re-reads it. Disabled for the same window so a second
  // change can't race the first. ---
  var modeSelect = document.getElementById('mode-select');

  if (modeSelect) {
    modeSelect.addEventListener('change', function () {
      var chosenMode = modeSelect.value;
      modeSelect.disabled = true;

      fetch('/session_mode.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, mode: chosenMode }).toString()
      })
        .then(function () {
          setTimeout(pollOnce, 300);
        })
        .catch(function () {
          modeSelect.disabled = false;
        });
    });
  }

  // --- model select: drives Claude Code's real /model picker to switch
  // session-only (set_model() in Sessions.php works out and sends the
  // Up/Down/'s' key sequence - see its own docblock for the full mechanics,
  // confirmed live 2026-08-24). The request blocks until every press is
  // sent (can take longer than the mode toggle's own - up to ~11 steps vs
  // ~3 - since it always normalizes to row 1 first rather than assuming a
  // known starting position), so the same "extra 300ms after, disabled for
  // the same window" pattern as the mode select above still applies once
  // it resolves. ---
  var modelSelect = document.getElementById('model-select');

  if (modelSelect) {
    modelSelect.addEventListener('change', function () {
      var chosenModel = modelSelect.value;
      modelSelect.disabled = true;

      fetch('/session_model.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, model: chosenModel }).toString()
      })
        .then(function () {
          setTimeout(pollOnce, 300);
        })
        .catch(function () {
          modelSelect.disabled = false;
        });
    });
  }

  // --- antigravity model select: drives Antigravity's real /model picker
  // the same way (Up-to-row-1-then-Down-to-target, see
  // PromptInteractionService::set_antigravity_model()'s own docblock for
  // the mechanics), but UNLIKE the Claude Code select above, this always
  // overwrites Antigravity's ACCOUNT-WIDE default model - confirmed live
  // 2026-08-24, there is no session-only option in Antigravity's picker.
  // Same "disable during the request, let the next poll's
  // renderAntigravityModelToggle() reflect the real new value" pattern as
  // the Claude Code select above - AntigravitySelectableModel::
  // parse_current_model() reads it straight back off the live pane. ---
  var antigravityModelSelect = document.getElementById('antigravity-model-select');

  if (antigravityModelSelect) {
    antigravityModelSelect.addEventListener('change', function () {
      var chosenModel = antigravityModelSelect.value;
      antigravityModelSelect.disabled = true;

      fetch('/session_antigravity_model.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, model: chosenModel }).toString()
      })
        .then(function () {
          setTimeout(pollOnce, 300);
        })
        .catch(function () {
          antigravityModelSelect.disabled = false;
        });
    });
  }

  // --- opencode model select (headless session): the session's model list
  // is dynamic (the serve's /config/providers), so fill the dropdown from
  // /session_list_models.php then let the user pick; posts model + provider
  // to set_model (routed to the serve for a headless session). ES5 only -
  // this file deliberately avoids ES6+ (mobile Safari). ---
  var opencodeModelSelect = document.getElementById('opencode-model-select');

  if (opencodeModelSelect) {
    var ocCurrentModel = opencodeModelSelect.getAttribute('data-current-model') || '';
    var ocCurrentProvider = opencodeModelSelect.getAttribute('data-current-provider') || '';

    fetch('/session_list_models.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.models) {
          return;
        }

        var placeholder = opencodeModelSelect.options[0];
        while (opencodeModelSelect.options.length > 1) {
          opencodeModelSelect.remove(1);
        }

        data.models.forEach(function (m) {
          var opt = document.createElement('option');
          opt.value = m.id;
          opt.setAttribute('data-provider', m.providerID);
          opt.textContent = (m.providerID ? m.providerID + '/' : '') + (m.id || m.name || '');
          opencodeModelSelect.appendChild(opt);
        });

        // preselect the session's current model (if it appears in the list)
        for (var i = 1; i < opencodeModelSelect.options.length; i++) {
          var o = opencodeModelSelect.options[i];
          if (o.value === ocCurrentModel && o.getAttribute('data-provider') === ocCurrentProvider) {
            opencodeModelSelect.selectedIndex = i;
            break;
          }
        }
        opencodeModelSelect.disabled = false;
      })
      .catch(function () {});

    opencodeModelSelect.addEventListener('change', function () {
      var opt = opencodeModelSelect.options[opencodeModelSelect.selectedIndex];
      var chosenModel = opencodeModelSelect.value;
      var chosenProvider = opt ? opt.getAttribute('data-provider') : '';
      opencodeModelSelect.disabled = true;

      fetch('/session_model.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, model: chosenModel, model_provider: chosenProvider }).toString()
      })
        .then(function () {
          setTimeout(pollOnce, 300);
        })
        .catch(function () {
          opencodeModelSelect.disabled = false;
        });
    });
  }

  // --- Codex app-server model + reasoning effort selects. The catalog is
  // account-aware and dynamic, so never hard-code model names here. ---
  var codexModelSelect = document.getElementById('codex-model-select');
  var codexEffortSelect = document.getElementById('codex-effort-select');

  if (codexModelSelect && codexEffortSelect) {
    var codexModels = [];
    var codexCurrentModel = codexModelSelect.getAttribute('data-current-model') || '';
    var codexCurrentEffort = codexEffortSelect.getAttribute('data-current-effort') || '';

    function fillCodexEfforts(model) {
      while (codexEffortSelect.options.length > 1) { codexEffortSelect.remove(1); }
      var efforts = model && model.efforts ? model.efforts : [];
      efforts.forEach(function (effort) {
        var option = document.createElement('option');
        option.value = effort;
        option.textContent = effort.charAt(0).toUpperCase() + effort.slice(1);
        codexEffortSelect.appendChild(option);
      });
      codexEffortSelect.value = codexCurrentEffort || (model ? model.defaultEffort || '' : '');
      codexEffortSelect.disabled = efforts.length === 0;
    }

    function saveCodexSettings() {
      codexModelSelect.disabled = true;
      codexEffortSelect.disabled = true;
      fetch('/session_model.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, model: codexModelSelect.value, effort: codexEffortSelect.value }).toString()
      }).then(function () {
        codexCurrentModel = codexModelSelect.value;
        codexCurrentEffort = codexEffortSelect.value;
        codexModelSelect.disabled = false;
        fillCodexEfforts(codexModels[codexModelSelect.selectedIndex - 1] || null);
      }).catch(function () {
        codexModelSelect.disabled = false;
        fillCodexEfforts(codexModels[codexModelSelect.selectedIndex - 1] || null);
      });
    }

    fetch('/session_list_models.php?agent=codex', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok || !data.models) { return; }
        codexModels = data.models;
        data.models.forEach(function (model) {
          var option = document.createElement('option');
          option.value = model.id;
          option.textContent = model.name || model.id;
          codexModelSelect.appendChild(option);
        });
        codexModelSelect.value = codexCurrentModel;
        if (!codexModelSelect.value) {
          for (var i = 0; i < codexModels.length; i++) {
            if (codexModels[i].isDefault) { codexModelSelect.selectedIndex = i + 1; break; }
          }
        }
        codexModelSelect.disabled = false;
        fillCodexEfforts(codexModels[codexModelSelect.selectedIndex - 1] || null);
      }).catch(function () {});

    codexModelSelect.addEventListener('change', function () {
      codexCurrentEffort = '';
      fillCodexEfforts(codexModels[codexModelSelect.selectedIndex - 1] || null);
      saveCodexSettings();
    });
    codexEffortSelect.addEventListener('change', saveCodexSettings);
  }

  // --- transcript images: start as a small square thumbnail (see
  // renderImageHtml()/render_transcript_image_html()), tapping toggles to
  // full size and back - not a separate lightbox/modal, just swapping the
  // sizing classes on the same <img> in place. ---
  var TRANSCRIPT_IMAGE_THUMB_CLASSES = ['w-24', 'h-24', 'object-cover'];
  var TRANSCRIPT_IMAGE_FULL_CLASSES = ['w-full', 'h-auto', 'object-contain'];

  document.addEventListener('click', function (e) {
    var img = closestEventTarget(e, '.transcript-image');

    if (!img) {
      return;
    }

    var isThumbnail = img.classList.contains('w-24');
    img.classList.remove.apply(img.classList, isThumbnail ? TRANSCRIPT_IMAGE_THUMB_CLASSES : TRANSCRIPT_IMAGE_FULL_CLASSES);
    img.classList.add.apply(img.classList, isThumbnail ? TRANSCRIPT_IMAGE_FULL_CLASSES : TRANSCRIPT_IMAGE_THUMB_CLASSES);
  });

  // --- collapsible tool_use/tool_result blocks: tapping anywhere on one
  // toggles it, collapsed OR expanded (including inside the expanded
  // <pre>), not just the exact summary text/marker - a real mobile
  // annoyance otherwise (small precise tap target to collapse a long
  // command/output back down). The <summary>'s own native click-to-toggle
  // already handles the collapsed case (helped along by the block/w-full
  // class on it, so the whole row is a real tap target, not just wherever
  // the text glyphs render); this delegated handler is the backstop for
  // the rest of the <details> box, expanded content included. Three things
  // it must never do: double-toggle a tap that landed on <summary> itself
  // (native behavior already fired), collapse out from under an active
  // text selection (a plain click event doesn't fire for a scroll-drag
  // gesture to begin with, so normal scrolling/reading is unaffected
  // either way - this guard is specifically for "tap elsewhere to dismiss
  // a selection", not scrolling), or fire on the "View full screen"/"Copy"
  // buttons (see common.js) - collapsing the block right as its own
  // fullscreen modal opens over it (or right as its own Copy button shows
  // its "Copied!" feedback) would leave it collapsed once that resolves. ---
  document.addEventListener('click', function (e) {
    if (closestEventTarget(e, 'summary, .expand-fullscreen-btn, .copy-btn')) {
      return;
    }

    var details = closestEventTarget(e, '.tool-use-block details, .tool-detail details');

    if (!details) {
      return;
    }

    var selection = window.getSelection();

    if (selection && !selection.isCollapsed) {
      return;
    }

    details.open = !details.open;
  });

  // --- stop button: interrupts Claude mid-response (sends Escape, same
  // as pressing it while attached). Delegated at the document level, not
  // attached directly to the button, since renderThinkingIndicator()
  // recreates it (or removes it entirely) on every poll. ---
  document.addEventListener('click', function (e) {
    var stopBtn = closestEventTarget(e, '#stop-btn');

    if (!stopBtn) {
      return;
    }

    stopBtn.disabled = true;

    fetch('/session_escape.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken }).toString()
    })
      .then(function (r) { return parseJsonResponse(r, 'session-escape'); })
      .then(function (data) {
        if (!data || !data.ok) {
          alert((data && data.message) || 'Failed to stop.');
        }

        setTimeout(pollOnce, 300);
      })
      .catch(function () {
        alert('Network error - stop not sent.');
      })
      .finally(function () {
        stopBtn.disabled = false;
      });
  });

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      startPolling();
    } else {
      stopPolling();
    }
  });

  // Restored before the initial scroll-to-bottom below, so if it's the
  // newest thing on the page, landing at the bottom actually shows it.
  restorePendingMessageFromStorage();

  // Landing on a search result (see sidebar.php's search box below and
  // SessionController::show()'s own jump_line handling) - the page's own
  // initial history load already ends exactly at jumpLine (a full
  // navigation, not an in-place fetch - see the click handler below), so
  // this only has to find and highlight it, not fetch anything itself.
  // Falls back to the normal "land at the bottom" behavior if the element
  // somehow isn't there (e.g. a stale link to a line that's since been
  // pruned/rotated away).
  var jumpLine = window.SESSIONEER_BOOTSTRAP.jumpLine || null;
  var jumpTarget = jumpLine !== null ? list.querySelector('[data-line="' + jumpLine + '"]') : null;

  if (jumpTarget) {
    // Reveal it FIRST, before measuring anything - a jump target hidden
    // inside a collapsed tool-call entry <details> (openAncestorDetails(),
    // in common.js) or behind the "show subagent calls" toggle being off
    // gets a meaningless zeroed-out getBoundingClientRect() while hidden,
    // silently landing the scroll on the wrong spot (found live
    // 2026-08-20: "clicking on a result doesn't go to the right one").
    openAncestorDetails(jumpTarget);

    if (!document.body.classList.contains('show-subagent') && jumpTarget.closest('.subagent-detail, .subagent-use-block, .entry-subagent-only')) {
      applyShowSubagent(true);

      if (showSubagentToggle) {
        showSubagentToggle.checked = true;
      }

      try {
        window.localStorage.setItem(SHOW_SUBAGENT_KEY, '1');
      } catch (e) {}
    }

    // A plain scrollTo() computed from the element's own rect, not
    // scrollIntoView() - found live 2026-08-09: scrollIntoView() silently
    // no-ops on this page in at least one real headless-Chrome automation
    // context (verified: scrollTo() itself worked immediately when called
    // directly right after, in the very same context where
    // scrollIntoView() had just done nothing), so this avoids depending on
    // browser-specific scrollIntoView behavior entirely rather than
    // risking the same silent no-op for a real user. getBoundingClientRect()
    // is viewport-relative, but #page-content (not the viewport) is the
    // scrolling container, so this converts via #page-content's own rect.
    var jumpTargetRect = jumpTarget.getBoundingClientRect();
    var pageContentRect = pageContent.getBoundingClientRect();
    var jumpScrollTop = pageContent.scrollTop + (jumpTargetRect.top - pageContentRect.top) - (pageContent.clientHeight / 2) + (jumpTargetRect.height / 2);
    pageContent.scrollTo({ top: Math.max(0, jumpScrollTop), behavior: 'auto' });
    highlightJumpTarget(jumpTarget);
  } else {
    // Land at the bottom on open - the current/latest activity (and any
    // pending prompt) is what matters first, same as any chat app.
    // lastFixedFooterHeight/footerHeightKnown (scroll.js) seeded
    // explicitly/synchronously here, from #compose-bar's real height
    // right now, rather than left for its own ResizeObserver to record on
    // its own first delivery - that first delivery can already reflect a
    // LATER, grown height (quota data loading in shortly after this) with
    // no earlier delivery to diff against, since ResizeObserver only
    // guarantees the latest size, not every intermediate one. See that
    // callback's own comment for the full bug this closes.
    var composeBarEl = document.getElementById('compose-bar');

    if (composeBarEl) {
      lastFixedFooterHeight = composeBarEl.offsetHeight;
      footerHeightKnown = true;
    }

    scrollToBottom(false);
  }

  updateGoToBottomVisibility();

  if (document.visibilityState === 'visible') {
    startPolling();
  }

  // Sidebar search (isGlobalSearchScope()/runSessionSearch()/the result
  // renderers) now lives in search.js (loaded before this file - see
  // session.php), plain global functions/vars, same convention as
  // common.js/scroll.js/highlights.js/sidebar.js - extracted 2026-08-24,
  // sixth and final cut of the "split session.js into modules" pass.
})();
