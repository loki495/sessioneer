<?php
$this->layout('layout', [
    'title' => $found ? (string)($detail['title'] ?? $detail['name']) : 'Sessioneer',
    'viewportContent' => 'width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=5, viewport-fit=cover',
    'fixedShell' => true,
]);
?>
<?php $this->start('style') ?>
<style>
  /* Toggled via the single "Show subagent calls and outputs" sidebar
     setting - a body-level class + CSS rule so it applies uniformly to
     blocks rendered later by the poll too, without needing to walk/re-tag
     the DOM on every render. Regular (non-subagent) tool_use/tool_result
     entries don't use this at all any more (see render_transcript_entries_
     html() in TranscriptView.php) - since 2026-08-08 those are always
     grouped into a collapsible "N tool calls" run instead, whose own
     <details> is its own show/hide affordance, scoped to just that run
     rather than the whole session.
     Hidden by DEFAULT, revealed only once body.show-subagent is added -
     the opposite of the naive "visible by default, hidden by a body.hide-
     subagent class" version this replaced (x-cloak-style fix, found live
     2026-08-08). The stored preference lives in localStorage, which PHP
     can't see at render time - session.js only adds/removes this class
     AFTER its own script runs, near the end of the page, well after first
     paint. The old hide-* version could therefore only ever hide subagent
     content a moment too late for anyone who'd actually turned the toggle
     off: it rendered visible by default and js had to catch up, a real
     flash of real content. Defaulting to hidden and revealing it once
     script confirms the (usually "on") preference means the flash - if
     any - is the far less jarring "briefly nothing, then it pops in" for
     the common case, never "here's real content, oops, hide it". */
  .subagent-detail,
  .subagent-use-block,
  .entry-subagent-only { display: none; }
  body.show-subagent .subagent-detail,
  body.show-subagent .subagent-use-block,
  body.show-subagent .entry-subagent-only { display: block; }
  /* Marks where newly-polled entries start (see markNewContent() in the
     <script> below) - opacity transition only, no layout-affecting
     property, so the fade-out never causes a scroll jump right as the user
     is looking at it. */
  .new-content-divider { opacity: 1; transition: opacity 0.8s ease-out; }
  .new-content-divider.fading { opacity: 0; }
  /* Highlights the actual new entry bubbles, not just the divider above
     them - a box-shadow glow rather than a background tint, so it doesn't
     fight with each entry's own role-color background/border (see
     entry_color_classes()). Three stacked shadows at decreasing alpha/
     increasing spread fake a radial gradient falloff (100% down to 0%
     alpha) - CSS box-shadow has no literal multi-stop gradient, this is
     the practical equivalent (picked over a single blurred shadow after
     a live side-by-side comparison). Same two-class fade pattern as
     .new-content-divider above and for the same reason: the `transition`
     property has to stay on the element for the whole fade, so toggling
     .fading (which zeroes every layer's alpha) is what animates, rather
     than removing .new-content-highlight itself mid-fade - that would
     strip `transition` at the same instant as `box-shadow`, snapping it
     off instead of fading (caught live: the ring vanished with no
     animation at all before this fix). */
  .new-content-highlight {
    transition: box-shadow 1.2s ease-out;
  }
  .new-content-highlight.fading {
    box-shadow: 0 0 0 1px rgba(251, 191, 36, 0), 0 0 8px 4px rgba(251, 191, 36, 0), 0 0 16px 8px rgba(251, 191, 36, 0);
  }
  /* A free-flowing assistant entry (see entry_wrapper_class()/entry-free-
     flowing) has no padding of its own, so the plain .new-content-highlight
     ring above sits flush against the text with no room to breathe (found
     live 2026-08-08).
     Two things were tried and rejected before this, both confirmed live
     against the real running app:
     - Widening box-shadow's spread: doesn't work AT ALL - a shadow's
       visible edge always starts exactly at the box's own border edge no
       matter how large its spread is; spread only changes how far OUT it
       reaches, not whether it touches. Confirmed live: widening it just
       made the ring thicker, still flush against the text.
     - outline + outline-offset: DOES create real separation (outline never
       affects box size/layout, unlike padding - confirmed live that a flex
       `gap`-based sibling distance was unchanged by it, unlike the
       equivalent padding+negative-margin trick, which visibly ate into
       that gap). But a crisp offset outline reads as a hard rectangular
       BOX suddenly appearing around the text (confirmed live via
       screenshot) - defeating the entire point of "free-flowing, no
       border/box" in the first place.
     What actually works: a ::before pseudo-element, inset OUTSIDE the real
     text via a negative `inset`, carrying the soft blurred box-shadow
     layers itself. Since the pseudo-element's own phantom box already
     starts several pixels away from the text, ITS box-shadow reads as a
     soft glow with genuine breathing room around the real text -
     `position: absolute` pulls it completely out of normal flow, so (like
     outline) it has zero effect on the real entry's size or its flex `gap`
     spacing to neighbors either. A real inset (-12px, bigger than the
     first attempt's -6px) plus near-zero spread (relying on blur radius
     alone for the glow's spatial extent, not spread's hard-edged band) is
     what actually reads as a diffuse ambient halo rather than a
     soft-edged-but-still-rectangular box - confirmed live via a zoomed
     screenshot crop, after an initial smaller-inset/higher-spread attempt
     still looked too close to a box at normal viewing size on a real
     phone. */
  .new-content-highlight.entry-free-flowing {
    position: relative;
  }
  .new-content-highlight.entry-free-flowing::before {
    content: '';
    position: absolute;
    margin: 5px;
    inset: -12px;
    border-radius: 0.625rem;
    box-shadow: 0 0 12px 0px rgba(251, 191, 36, 0.5), 0 0 24px 4px rgba(251, 191, 36, 0.2);
    transition: box-shadow 1.2s ease-out;
    pointer-events: none;
  }
  .new-content-highlight.entry-free-flowing.fading::before {
    box-shadow: 0 0 12px 0px rgba(251, 191, 36, 0), 0 0 24px 4px rgba(251, 191, 36, 0);
  }
  /* Landing on a search result (see highlightJumpTarget() in the <script>
     below) - a distinct green ring/glow, on purpose, so it doesn't read as
     "new message" (the amber ring above) when what actually happened is
     "you searched and jumped here" (Andres's own ask, 2026-08-20). Same
     box-shadow/fade shape as .new-content-highlight, just a different
     color and lit immediately (no NEW_CONTENT_VISIBLE_DELAY_MS wait -
     there's no risk of an intersection-observer race here, the scroll
     that lands on this target already happened by the time this class is
     added). */
  .jump-target-highlight {
    box-shadow: 0 0 0 1px rgba(52, 211, 153, 0.8), 0 0 8px 4px rgba(52, 211, 153, 0.5), 0 0 16px 8px rgba(52, 211, 153, 0.25);
    transition: box-shadow 1.2s ease-out;
  }
  .jump-target-highlight.fading {
    box-shadow: 0 0 0 1px rgba(52, 211, 153, 0), 0 0 8px 4px rgba(52, 211, 153, 0), 0 0 16px 8px rgba(52, 211, 153, 0);
  }
  .jump-target-highlight.entry-free-flowing {
    position: relative;
  }
  .jump-target-highlight.entry-free-flowing::before {
    content: '';
    position: absolute;
    margin: 5px;
    inset: -12px;
    border-radius: 0.625rem;
    box-shadow: 0 0 12px 0px rgba(52, 211, 153, 0.5), 0 0 24px 4px rgba(52, 211, 153, 0.2);
    transition: box-shadow 1.2s ease-out;
    pointer-events: none;
  }
  .jump-target-highlight.entry-free-flowing.fading::before {
    box-shadow: 0 0 12px 0px rgba(52, 211, 153, 0), 0 0 24px 4px rgba(52, 211, 153, 0);
  }
  /* Entries revealed by "Load older messages" (see highlightLoadedOlderContent()
     in the <script> below) - a distinct cyan ring, on purpose, so it reads
     as neither "new message" (the amber .new-content-highlight) nor "you
     searched and jumped here" (the emerald .jump-target-highlight) - cyan
     isn't used anywhere else in this app's transcript palette (user=rose,
     assistant=emerald, tool_use=sky, tool_result=violet, subagent/task-
     notification=fuchsia, plan=amber), so it can't be mistaken for any of
     those either (Andres's own ask 2026-08-22). Same box-shadow/fade shape
     as .new-content-highlight/.jump-target-highlight, just a different color. */
  .older-content-highlight {
    box-shadow: 0 0 0 1px rgba(34, 211, 238, 0.8), 0 0 8px 4px rgba(34, 211, 238, 0.5), 0 0 16px 8px rgba(34, 211, 238, 0.25);
    transition: box-shadow 1.2s ease-out;
  }
  .older-content-highlight.fading {
    box-shadow: 0 0 0 1px rgba(34, 211, 238, 0), 0 0 8px 4px rgba(34, 211, 238, 0), 0 0 16px 8px rgba(34, 211, 238, 0);
  }
  .older-content-highlight.entry-free-flowing {
    position: relative;
  }
  .older-content-highlight.entry-free-flowing::before {
    content: '';
    position: absolute;
    margin: 5px;
    inset: -12px;
    border-radius: 0.625rem;
    box-shadow: 0 0 12px 0px rgba(34, 211, 238, 0.5), 0 0 24px 4px rgba(34, 211, 238, 0.2);
    transition: box-shadow 1.2s ease-out;
    pointer-events: none;
  }
  .older-content-highlight.entry-free-flowing.fading::before {
    box-shadow: 0 0 12px 0px rgba(34, 211, 238, 0), 0 0 24px 4px rgba(34, 211, 238, 0);
  }
</style>
<?php $this->stop() ?>

<?php include __DIR__ . '/../sidebar.php'; ?>

<!-- #app-shell: a full-height flex column so #page-content can be the
     ONLY scrolling element on this page, instead of the whole body -
     needed to fix #compose-bar's position:fixed detaching mid-scroll on
     iOS Safari (see its own comment). #sidebar/#go-to-bottom-btn/the two
     layout.php modals deliberately stay OUTSIDE this div, as body-level
     siblings same as before - nesting a position:fixed element inside an
     overflow-hidden flex ancestor risks it being clipped, exactly the
     kind of thing worth not risking on the same browser this is already
     working around a fixed-position bug on. -->
<div id="app-shell" class="flex flex-col h-full min-h-0" style="<?= ($detail['agent'] ?? 'claude') === 'opencode' ? 'background-color: rgba(46, 16, 101, 0.16)' : (($detail['agent'] ?? 'claude') === 'codex' ? 'background-color: rgba(3, 78, 92, 0.14)' : (($detail['agent'] ?? 'claude') === 'antigravity' ? 'background-color: rgba(69, 26, 3, 0.12)' : '')) ?>">
<?php include __DIR__ . '/../header.php'; ?>

<div id="page-content" class="flex-1 min-h-0 overflow-y-auto overscroll-contain">
<div class="max-w-2xl lg:max-w-4xl mx-auto px-4 py-6">

  <?php if (!$found): ?>
    <div class="select-none rounded-lg px-4 py-3 text-sm bg-red-900/50 text-red-200 border border-red-700">
      <p class="font-medium">Session not found.</p>
      <p class="mt-1"><?= $this->e((string)($detail['message'] ?? 'Unknown error')) ?></p>
    </div>
  <?php else: ?>
    <?php if ($jumpLine !== null): ?>
      <!-- Landed here from a search result (see sidebar.php's search box/
           session.js) - the page above this loads a window ENDING at
           jumpLine, via session_history's existing `before` cursor, rather
           than the usual latest-tail page. "Back to latest" is a plain
           link (full navigation), not a JS action - simplest way back to
           the normal view, and matches this app's existing classic-link
           pattern for anything that isn't a repeated, high-frequency
           action (see CLAUDE.md's own AJAX-vs-redirect distinction). -->
      <div class="select-none mb-4 rounded-lg border border-sky-800/40 bg-sky-950/20 px-3 py-2 flex items-center justify-between gap-2 text-xs text-sky-300">
        <span>Showing a search result</span>
        <a href="/session.php?session=<?= urlencode($sessionName) ?>" class="text-sky-400 active:text-sky-200 font-medium">Back to latest &rarr;</a>
      </div>
    <?php endif; ?>

    <!-- #history-list and #load-more-btn are ALWAYS rendered (never
         conditionally, unlike the old version of this template) - both are
         captured ONCE by session.js at load time (document.getElementById,
         never re-queried), so a session opened before its first line
         exists (a brand-new session, or one that's genuinely empty so
         far) used to leave `list` permanently null for the rest of the
         page's life once either container was missing, crashing the very
         first appendPendingEntry() call (list.appendChild() on null) the
         moment a message was actually sent - found live 2026-08-08. The
         "nothing here yet" messaging now lives INSIDE #history-list as a
         plain placeholder note instead, which session.js's own
         appendPendingEntry()/pollHistory() already remove the moment any
         real content (optimistic or polled) actually lands - see
         removeHistoryEmptyNote() there. -->
    <!-- Two buttons, not one - Andres asked 2026-08-24 for a faster way to
         back-page through history than clicking "Load older messages"
         repeatedly, when what's actually wanted is "get back to the start
         of the most recent real exchange." #load-until-user-btn shares
         data-before with #load-more-btn (both page the SAME cursor - only
         which one moves it differs) and toggles hidden the same way, since
         either one running out of history at once is exactly the same
         "nothing left to load" state for both. -->
    <div class="flex gap-2 mb-2">
      <button type="button" id="load-more-btn"
        data-session="<?= $this->e($sessionName) ?>"
        data-before="<?= $nextBefore !== null ? (int)$nextBefore : '' ?>"
        class="select-none flex-1 rounded-lg border border-slate-800 bg-slate-900/50 active:bg-slate-800 text-xs text-slate-400 px-3 py-2 <?= $hasMore ? '' : 'hidden' ?>">
        Load older messages
      </button>
      <button type="button" id="load-until-user-btn"
        data-session="<?= $this->e($sessionName) ?>"
        class="select-none flex-1 rounded-lg border border-slate-800 bg-slate-900/50 active:bg-slate-800 text-xs text-slate-400 px-3 py-2 <?= $hasMore ? '' : 'hidden' ?>">
        Load until last message
      </button>
    </div>
    <div id="history-list" class="flex flex-col gap-2">
      <?php if (!$historyOk): ?>
        <p id="history-empty-note" class="select-none rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
          <?= $this->e((string)($history['message'] ?? 'No transcript available for this session.')) ?>
        </p>
      <?php elseif (empty($entries)): ?>
        <p id="history-empty-note" class="select-none rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
          Nothing recorded yet.
        </p>
      <?php else: ?>
        <?php
        $agentLabel = is_string($detail['agent_label'] ?? null) && $detail['agent_label'] !== ''
            ? $detail['agent_label']
            : (isset($detail['agent']) && $detail['agent'] === 'antigravity' ? 'Antigravity' : (isset($detail['agent']) && $detail['agent'] === 'codex' ? 'Codex' : 'Claude Code'));
        ?>
        <?= \App\Views\TranscriptView::render_transcript_entries_html($entries, $sessionName, false, is_string($detail['workdir'] ?? null) ? $detail['workdir'] : null, $agentLabel, $csrfToken) ?>
      <?php endif; ?>
    </div>

    <div id="thinking-indicator" class="mt-4">
      <?= \App\Views\TranscriptView::render_thinking_indicator_html($detail) ?>
    </div>

    <div id="turn-error" class="mt-4">
      <?= \App\Views\TranscriptView::render_turn_error_html($detail) ?>
    </div>

    <!-- The live, actionable prompt state sits after history, not pinned
         above it - reads like the "current" end of the chat, and is what
         the initial/poll-triggered auto-scroll brings into view. -->
    <div id="blocked-prompt-section" class="mt-4">
      <?= \App\Views\TranscriptView::render_blocked_prompt_section_html($detail, $csrfToken) ?>
    </div>
  <?php endif; ?>

</div>
</div>

<!-- #scroll-toolbar: docked flat scroll-control strip (Andres's own ask,
     2026-09-06) - replaced the four position:fixed circles that hovered
     over the right edge and covered chat text on mobile. A real flex child
     of #app-shell between #page-content and #compose-bar, so it takes
     layout space instead of overlaying content - no position:fixed, no
     footer-height repositioning (the same reason #compose-bar itself was
     taken off position:fixed, see compose-bar.php's own comment). All four
     controls always render, one equal quarter of the bar each (grid-cols-4),
     flat - no rounding, no per-control background - and simply sit muted
     (disabled) when their action isn't currently available: Top/Bottom at
     their own edge, Prev with no user history, New with no unread divider.
     Left-to-right: top, previous user, new, bottom. -->
<div id="scroll-toolbar" class="select-none flex-none bg-slate-950/95 border-t border-slate-800">
  <div class="max-w-2xl lg:max-w-4xl mx-auto flex text-xs divide-x divide-slate-800">
    <button type="button" id="go-to-top-btn"
      class="flex-1 select-none py-1 text-center text-slate-300 active:text-slate-100 disabled:cursor-default disabled:text-slate-600">
      Top
    </button>
    <button type="button" id="prev-user-btn"
      class="flex-1 select-none py-1 text-center text-slate-300 active:text-slate-100 disabled:cursor-default disabled:text-slate-600">
      Prev user
    </button>
    <button type="button" id="jump-to-new-btn"
      class="flex-1 select-none py-1 text-center text-amber-300 active:text-amber-200 disabled:cursor-default disabled:text-slate-600">
      New
    </button>
    <button type="button" id="go-to-bottom-btn"
      class="flex-1 select-none py-1 text-center text-slate-300 active:text-slate-100 disabled:cursor-default disabled:text-slate-600">
      Bottom
    </button>
  </div>
</div>

<?php include __DIR__ . '/../compose-bar.php'; ?>
</div>

<script>
window.SESSIONEER_BOOTSTRAP = <?= json_encode([
    'session' => $sessionName,
    'csrfToken' => $csrfToken,
    'newestLine' => $newestLine,
    'agentSessionId' => $detail['agent_session_id'] ?? null,
    'jumpLine' => $jumpLine,
    'workdir' => is_string($detail['workdir'] ?? null) ? $detail['workdir'] : null,
    'agent' => $detail['agent'] ?? 'claude',
    'agentLabel' => $agentLabel ?? 'Claude Code',
]) ?>;
</script>
<script src="<?= \App\Assets::versioned_url('/js/common.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/markdown.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/scroll.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/highlights.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/sidebar.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/search.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/session.js') ?>"></script>
