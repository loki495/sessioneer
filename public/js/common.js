// @ts-check
// Shared, byte-identical helpers between session.js and index.js - kept as
// plain top-level globals (loaded before either page script, see the
// <script> order in session.php/index.php) rather than an ES module, to
// match this app's existing no-build, no-module script style.

// --- fixed-shell viewport height: <body class="h-[var(--app-vh,100dvh)]">
// (layout.php, only on $fixedShell pages - session.php/index.php) defaults
// to the CSS 100dvh unit, which is SUPPOSED to already track the visible
// area shrinking/growing as iOS Safari's on-screen keyboard opens/closes,
// no JS needed - and does, for the OPEN direction. Found live 2026-08-22
// (Andres): closing the keyboard (or just blurring the compose textarea)
// left the page stuck at the shorter, keyboard-open height - #app-shell's
// header snapped back down correctly (it's sized off #app-shell's own
// flex-column height, not the keyboard-relative body height directly),
// but #page-content stayed squeezed, as if body's own 100dvh had frozen at
// its smallest recent value instead of recomputing back up. A known WebKit
// quirk, not anything this app's own layout does wrong - `dvh` not
// reliably recomputing on keyboard dismiss specifically (vs. resulting
// fine on open) has been reported across multiple iOS Safari versions.
// window.visualViewport.height is a lower-level, independently-updating
// API describing the actually-visible area (keyboard excluded) that does
// NOT share this bug - mirroring it into a CSS custom property (updated on
// every visualViewport 'resize', which fires for BOTH directions, not just
// open) and having layout.php's fixed-shell body reference that instead of
// the bare unit sidesteps the WebKit bug entirely, while still falling
// back to plain `100dvh` (the var()'s second argument) for any browser
// without visualViewport support at all. Not verified against a real iOS
// device yet - based on the well-established visualViewport workaround for
// this exact class of bug, not guessed from nothing.
if (window.visualViewport) {
  // Found live 2026-08-22 (Andres): the previous version of this also
  // called syncAppViewportHeight() once synchronously here, at script-load
  // time, to cover the (rare) case of loading the page while the keyboard
  // was already open. That synchronous first reading turned out to be
  // unreliable specifically on a FRESH page load (typing the URL/opening
  // from a home-screen icon, not a same-tab in-app navigation) - iOS
  // Safari's own toolbar/chrome hasn't necessarily settled into its final
  // size yet at that instant, so --app-vh could get locked to a slightly
  // wrong value, leaving an extra gap at the bottom of the fixed shell
  // until the next real resize event happened to correct it. Dropping the
  // synchronous call and relying purely on the 'resize' listener below
  // fixes this: --app-vh simply stays unset (falling back to the plain
  // `100dvh` in the var(), same as a browser with no visualViewport
  // support at all) until visualViewport reports its first real,
  // settled measurement.
  var lastAppViewportHeight = window.visualViewport.height;

  // Minimum height delta (px) to treat a resize as a real keyboard
  // open/close transition rather than incidental viewport settling.
  var KEYBOARD_RESIZE_MIN_DELTA_PX = 100;

  var syncAppViewportHeight = function () {
    var newHeight = window.visualViewport.height;
    var delta = newHeight - lastAppViewportHeight;
    lastAppViewportHeight = newHeight;

    document.documentElement.style.setProperty('--app-vh', newHeight + 'px');

    // Found live 2026-08-22 (Andres, two related reports): focusing the
    // compose textarea "scrolls way up past the viewport until I start
    // typing", and sending a message (sendComposedMessage()'s own
    // .focus() call in session.js) "scrolls up to the first loaded
    // message instead of the bottom" - both involve a text input gaining
    // focus on a real device with an on-screen keyboard. Root cause: the
    // browser's own native "scroll the focused element into view" runs
    // the INSTANT focus happens, using whatever body height was in effect
    // at THAT moment - but on a real device, the keyboard's own animation
    // and this visualViewport 'resize' event (what actually shrinks
    // --app-vh, see above) land a beat AFTER focus, so the native scroll
    // fires first against the OLD (still full-height) layout, then the
    // height changes underneath it, leaving the scroll position wrong for
    // the NEW (shorter) layout. Re-running scrollIntoView() here, now that
    // --app-vh is finally correct, gives the browser a fresh, accurate
    // measurement instead of leaving whatever stale calculation it made
    // before the resize. Scoped to text inputs/textareas only - re-
    // scrolling for some other focused element (a button, a link) would
    // be pointless and could fight with an unrelated intentional scroll
    // position.
    //
    // Gated on a real keyboard-sized delta (found live 2026-08-22,
    // Andres): correcting on EVERY resize, however small, meant a small
    // incidental viewport-settling adjustment (e.g. iOS chrome finishing
    // its own layout shortly after a fresh page load) could yank an
    // already-focused textarea back into view even after the user had
    // deliberately scrolled elsewhere - reported as "can scroll down
    // further but get snapped back to the text area".
    if (Math.abs(delta) < KEYBOARD_RESIZE_MIN_DELTA_PX) {
      return;
    }

    var focused = document.activeElement;

    if (focused && (focused.tagName === 'TEXTAREA' || focused.tagName === 'INPUT')) {
      focused.scrollIntoView({ block: 'nearest' });
    }
  };
  window.visualViewport.addEventListener('resize', syncAppViewportHeight);
}

// --- fixed-shell outer-window scroll trap: keyboard focus panning the
// WHOLE PAGE off-screen on iOS Safari
// Found live 2026-08-22 (Andres, confirmed via a real iOS Safari screen
// recording): focusing the compose textarea didn't just misscroll - the
// ENTIRE page content vanished for several seconds, leaving nothing but
// blank body background and the floating #go-to-bottom-btn hanging alone
// in empty space. This is a DIFFERENT, longstanding iOS Safari quirk from
// the 100dvh one above: focusing an input can pan the whole LAYOUT
// VIEWPORT upward - not a DOM scroll of any element, so `overflow: hidden`
// on body/html (already set for every fixed-shell page, see layout.php)
// does NOT prevent it. And because iOS Safari positions `position: fixed`
// elements relative to the LAYOUT viewport rather than the visual one,
// a fixed element pans right along with the rest of the page instead of
// staying put - which is exactly why the floating button (position:fixed)
// was the one thing still visible, panned along with everything else,
// while #app-shell's real content scrolled off-screen with it.
// #page-content is deliberately the ONLY element meant to scroll on a
// fixed-shell page (see #app-shell's own comment in session.php/
// index.php) - window/document itself has nothing legitimate to scroll
// to, ever, on these pages - so snapping it straight back to (0, 0) the
// instant it drifts is safe and is the standard fix for this exact class
// of bug. Gated on #app-shell's presence so this never runs on a page
// (archived_session.php) that isn't built as a fixed shell in the first
// place.
if (document.getElementById('app-shell')) {
  window.addEventListener('scroll', function () {
    if (window.scrollX !== 0 || window.scrollY !== 0) {
      window.scrollTo(0, 0);
    }
  });
}

// Setting: whether answering a plain prompt option asks for confirmation
// first. Shared localStorage key between session.php's sidebar checkbox and
// index.php's dashboard rows (which answer prompts too but have no sidebar
// of their own to host the toggle) - both must read/write the same key.
var CONFIRM_BEFORE_ANSWER_KEY = 'sessioneer-confirm-before-answer';

function shouldConfirmBeforeAnswer() {
  try {
    return window.localStorage.getItem(CONFIRM_BEFORE_ANSWER_KEY) !== '0';
  } catch (e) {
    return true;
  }
}

// Setting: whether sessions tagged as orchestrator-worker "workers" (see
// row.php's data-kind="worker" and the [WORKER ...] session-tagging
// convention in ~/dotfiles/ai/skills/orchestrator-worker/SKILL.md) show in
// the dashboard's session list and the sidebar's "other sessions" list.
// Global (not per-session) - unlike SHOW_SUBAGENT_KEY, there's no "this
// session's own output" framing here, it's one cross-app preference for
// whether short-lived bounded-task sessions clutter the list at all.
// Default HIDDEN (opposite of every other toggle in this file) - the whole
// point is that a worker session looks identical to a human-driven one
// unless deliberately surfaced, so the safe default is out of the way.
var SHOW_WORKER_SESSIONS_KEY = 'sessioneer-show-worker-sessions';

function shouldShowWorkerSessions() {
  try {
    return window.localStorage.getItem(SHOW_WORKER_SESSIONS_KEY) === '1';
  } catch (e) {
    return false;
  }
}

// --- physical Shift-key tracking: every Shift+Enter-submits handler in
// this app (session.php's compose box and freetext prompt reply,
// index.php's dashboard freetext prompt reply) needs to know Shift is
// GENUINELY held, not just trust event.shiftKey on the Enter keydown
// itself - found live 2026-08-08, Andres reported a second Enter press
// on his phone sending instead of adding a newline (Enter should always
// just insert a newline; only Shift+Enter submits, everywhere text gets
// typed in this app). Best-guess cause: some mobile virtual keyboards
// auto-capitalize the next letter after a newline and appear to leak
// that as shiftKey: true on the following Enter's own keydown event,
// even with no real Shift key involved at all. A virtual keyboard's own
// on-screen "shift" toggle never dispatches a real, standalone Shift
// keydown/keyup the way a physical key does, so this independently-
// tracked flag naturally stays false on mobile (an actual Bluetooth-
// paired keyboard's Shift key still works, same as desktop) - it only
// ever becomes true from a real Shift keydown, which is exactly what
// was missing from the false positive this guards against. ---
var shiftKeyPhysicallyHeld = false;
document.addEventListener('keydown', function (e) {
  if (e.key === 'Shift') {
    shiftKeyPhysicallyHeld = true;
  }
});
document.addEventListener('keyup', function (e) {
  if (e.key === 'Shift') {
    shiftKeyPhysicallyHeld = false;
  }
});

// Polling interval: user-selectable (dropdown in both pages' headers, 1/3/5/
// 10/15s), persisted per-browser and shared across pages via this key so a
// chosen interval carries over from one to the other.
var POLL_INTERVAL_STORAGE_KEY = 'sessioneer-poll-interval-ms';
var POLL_INTERVAL_ALLOWED_MS = [1000, 3000, 5000, 10000, 15000];

// Reads the raw response text and only then tries to parse it (instead of
// jumping straight to r.json(), which throws before you ever see the body)
// so a parse failure can report the actual status code and a body snippet
// right in the alert/inline error, not just a bare "something went wrong" -
// no DevTools needed to tell which endpoint failed or why.
function parseJsonResponse(r, label) {
  return r.text().then(function (text) {
    try {
      return JSON.parse(text);
    } catch (e) {
      return { ok: false, message: 'Unexpected response [' + label + '] (status ' + r.status + '): ' + text.slice(0, 200) };
    }
  });
}

// --- Shared "answer a blocked prompt" helpers - the collect/validate step
// and the actual fetch() call are identical between session.js (which
// layers its own local "pending" transcript-entry UI on top of these) and
// index.js (which just swaps the dashboard row for a confirmation note) -
// see each file's own call sites for what's still page-specific enough to
// stay there. Extracted here 2026-08-23 after a bug (stray
// data-question-index attribute breaking BOTH files' own copies of
// collectMultiQuestionAnswers() the same way - see CONTRIBUTING.md) made
// the duplication itself the risk, not just the repetition.

/**
 * POSTs to /answer_prompt.php - $bodyParams is anything URLSearchParams'
 * own constructor accepts (a FormData, for the real <form> the plain-
 * option buttons render as, or a plain {session, csrf_token, option, text}
 * object for the free-text reply path, which has no real <form> of its
 * own). $label only ever feeds parseJsonResponse()'s own error text.
 */
function postAnswerPrompt(bodyParams, label) {
  return fetch('/answer_prompt.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(bodyParams).toString()
  }).then(function (r) { return parseJsonResponse(r, label); });
}

/**
 * POSTs a whole multi-question AskUserQuestion answer set to
 * /answer_multi_question.php - see SessionService::answer_multi_question()
 * (host-agent) for what happens to $answers server-side, and
 * collectMultiQuestionAnswers() below for the shape it's built in.
 */
function postAnswerMultiQuestion(sessionName, csrfToken, answers, label) {
  return fetch('/answer_multi_question.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams({
      session: sessionName,
      csrf_token: csrfToken,
      answers: JSON.stringify(answers)
    }).toString()
  }).then(function (r) { return parseJsonResponse(r, label); });
}

/**
 * Walks a .multi-question-wrapper's per-question [data-question-index]
 * groups (see BlockedPromptView::blocked_multi_question_html()/
 * renderMultiQuestionFormHtml() (session.js) for how they're built),
 * collecting each one's answer in the exact {int, {text}, int[]} shape
 * PromptParser::build_multi_question_key_sequence() (host-agent) expects -
 * one entry per question, in order. Returns null the moment ANY question
 * is still unanswered - the caller alerts and stops, same message both
 * pages already show; never a partial/best-effort answer set.
 *
 * summaryParts (one "Question: chosen label" string per question) is only
 * actually READ by session.js (to preview what's about to be sent, as a
 * fake pending transcript entry) - collected unconditionally anyway since
 * it falls out of the same walk for free, and index.js's dashboard variant
 * (which has no transcript to preview into) just ignores it.
 *
 * @return {{answers: Array, summaryParts: string[]}|null}
 */
function collectMultiQuestionAnswers(wrapper) {
  var questionDivs = wrapper.querySelectorAll('[data-question-index]');
  var answers = [];
  var summaryParts = [];

  for (var i = 0; i < questionDivs.length; i++) {
    var qDiv = questionDivs[i];
    var qLabel = qDiv.querySelector('p').textContent;

    if (qDiv.dataset.multi === '1') {
      var checked = Array.prototype.slice.call(qDiv.querySelectorAll('input[type="checkbox"]:checked'));

      if (checked.length === 0) {
        return null;
      }

      answers.push(checked.map(function (el) { return parseInt(el.value, 10); }));
      summaryParts.push(qLabel + ': ' + checked.map(function (el) { return el.closest('label').querySelector('span').textContent; }).join(', '));
      continue;
    }

    var selected = qDiv.querySelector('input[type="radio"]:checked');

    if (!selected) {
      return null;
    }

    if (selected.classList.contains('freetext-toggle')) {
      var freetextInput = qDiv.querySelector('.freetext-input');
      // .freetext-input is a real multi-line <textarea> (comfortable
      // fullscreen-editor typing room - see its own template comment), but
      // any embedded newline is joined into a space here before it's ever
      // sent - PromptParser::build_multi_question_key_sequence() drives
      // this specific field with `tmux send-keys -l` followed by one final
      // literal Enter, and a raw newline byte landing mid-string there
      // would almost certainly be read as an early Enter by the live
      // AskUserQuestion tab UI, submitting partial text and desyncing
      // every answer after it.
      var typedText = freetextInput ? freetextInput.value.replace(/\r?\n/g, ' ').trim() : '';

      if (typedText === '') {
        return null;
      }

      answers.push({ text: typedText });
      summaryParts.push(qLabel + ': ' + typedText);
      continue;
    }

    answers.push(parseInt(selected.value, 10));
    summaryParts.push(qLabel + ': ' + selected.closest('label').querySelector('span').textContent);
  }

  return { answers: answers, summaryParts: summaryParts };
}

/**
 * Shows/hides a single-select multi-question question's free-text input
 * based on whether its "Type something…" radio is the one now checked -
 * shared handling for session.js's and index.js's own delegated `change`
 * listeners (each wired to a different container - #blocked-prompt-section
 * vs the whole document - so the listener registration itself stays
 * per-page; only this handling logic is shared). No-op for anything that
 * isn't a single-select multi-question question: a multiSelect qDiv (no
 * free-text input to begin with - see build_multi_question_key_sequence()'s
 * own docblock for why), or an event target outside any
 * [data-question-index] at all.
 */
function handleMultiQuestionFreetextToggle(target) {
  var qDiv = target.closest('[data-question-index]');

  if (!qDiv || qDiv.dataset.multi === '1') {
    return;
  }

  var freetextInput = qDiv.querySelector('.freetext-input');
  var freetextToggle = qDiv.querySelector('.freetext-toggle');
  var expandBtn = qDiv.querySelector('.expand-edit-fullscreen-btn');

  if (!freetextInput || !freetextToggle) {
    return;
  }

  if (freetextToggle.checked) {
    freetextInput.classList.remove('hidden');
    freetextInput.focus();

    if (expandBtn) {
      expandBtn.classList.remove('hidden');
    }
  } else {
    freetextInput.classList.add('hidden');

    if (expandBtn) {
      expandBtn.classList.add('hidden');
    }
  }
}

function escapeHtml(text) {
  var div = document.createElement('div');
  div.textContent = text;
  return div.innerHTML;
}

// Shared by every text input/textarea in the app that has its own clear
// (x) button (Andres's own explicit ask, 2026-08-20: every text field
// needs one, not just relying on browser-native type="search" clear
// icons - inconsistently styled/positioned across browsers, and
// textareas never get one at all). Only visible while there's something
// to clear; clearing dispatches a real 'input' event so any listener
// depending on that (auto-grow, draft-saving, send-button enable/
// disable, a debounced search) fires exactly as if the user had deleted
// the text by hand, not left stale by a raw .value assignment.
//
// confirmMessage (optional, added 2026-08-23 per Andres's ask): when
// given, clicking asks confirm(confirmMessage) first and does nothing on
// Cancel. Only passed for fields where clearing can destroy real typed
// content worth protecting against a stray tap (the compose box, a
// blocked-prompt free-text reply) - deliberately omitted for search/
// filter clear buttons, where clearing is trivially reversible (just
// retype the search) and a confirm dialog would only add friction to the
// common case.
function wireClearButton(fieldEl, buttonEl, confirmMessage) {
  if (!fieldEl || !buttonEl) {
    return;
  }

  function updateVisibility() {
    buttonEl.classList.toggle('hidden', fieldEl.value === '');
  }

  fieldEl.addEventListener('input', updateVisibility);
  updateVisibility();

  buttonEl.addEventListener('click', function () {
    if (confirmMessage && !confirm(confirmMessage)) {
      return;
    }

    fieldEl.value = '';
    fieldEl.dispatchEvent(new Event('input', { bubbles: true }));
    fieldEl.focus();
  });
}

// Makes an element's native `title` tooltip also appear on tap, not just
// hover - iOS Safari (and touch browsers generally) never show a native
// title="..." tooltip on tap at all, so anything relying on it alone as
// the "see the full untruncated text" affordance (a truncated header
// title/cwd, say) is effectively inaccessible on a touch device (Andres's
// own report, 2026-08-20). Shows a small floating bubble with the same
// text, centered under the element, dismissed by tapping anywhere else or
// after a few seconds either way.
function wireTouchTooltip(el) {
  if (!el) {
    return;
  }

  var bubble = null;
  var removeBubbleTimer = null;

  function removeBubble() {
    clearTimeout(removeBubbleTimer);

    if (bubble && bubble.parentNode) {
      bubble.parentNode.removeChild(bubble);
    }

    bubble = null;
    document.removeEventListener('touchstart', dismissIfOutside, true);
    document.removeEventListener('click', dismissIfOutside, true);
  }

  function dismissIfOutside(e) {
    if (e.target !== el && !el.contains(e.target)) {
      removeBubble();
    }
  }

  el.addEventListener('click', function (e) {
    if (bubble) {
      removeBubble();
      return;
    }

    var text = el.getAttribute('title');

    if (!text) {
      return;
    }

    e.stopPropagation();

    bubble = document.createElement('div');
    bubble.className = 'fixed z-50 left-1/2 -translate-x-1/2 max-w-[90vw] rounded-lg border border-slate-700 bg-slate-800 text-slate-100 text-xs px-2 py-1.5 shadow-lg break-words text-center';
    bubble.textContent = text;
    document.body.appendChild(bubble);
    bubble.style.top = (el.getBoundingClientRect().bottom + 6) + 'px';

    // Listeners added a tick later, not in this same handler - otherwise
    // this very click (which also bubbles up to document) would
    // immediately dismiss the bubble it just created.
    setTimeout(function () {
      document.addEventListener('touchstart', dismissIfOutside, true);
      document.addEventListener('click', dismissIfOutside, true);
    }, 0);

    removeBubbleTimer = setTimeout(removeBubble, 4000);
  });
}

// Mirrors App\Views\SessionRowView::relative_time() so a poll-refreshed
// timestamp reads the same as the server-rendered one. Shared by
// session.js's own live updates and index.js's search results (moved here
// from session.js 2026-08-20 - a pure function, no reason it couldn't
// serve both).
function relativeTimeLabel(timestamp) {
  var diff = Math.floor(Date.now() / 1000) - timestamp;

  if (diff < 60) return 'just now';
  if (diff < 3600) return Math.floor(diff / 60) + ' min ago';

  if (diff < 86400) {
    var h = Math.floor(diff / 3600);
    return h + ' hr' + (h > 1 ? 's' : '') + ' ago';
  }

  var d = Math.floor(diff / 86400);
  return d + ' day' + (d > 1 ? 's' : '') + ' ago';
}

// Wraps the query itself in <mark> within an already-escaped snippet -
// escaping first, then matching against the SAME escaping applied to the
// query, so a query containing &/</> still lines up with what actually
// appears in the escaped snippet text. $& in the replacement re-inserts
// the exact matched text (preserving its original casing) rather than the
// query's own casing. Shared by session.js's own sidebar search and
// index.js's dashboard-wide search (Andres's own ask, 2026-08-20:
// "highlight the match").
function highlightSnippet(snippet, query) {
  var escapedSnippet = escapeHtml(snippet);
  var escapedQuery = escapeHtml(query).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');

  if (escapedQuery === '') {
    return escapedSnippet;
  }

  return escapedSnippet.replace(new RegExp('(' + escapedQuery + ')', 'ig'), '<mark class="bg-amber-400/30 text-amber-200 rounded-sm">$&</mark>');
}

// --- copy-to-clipboard: shared by every ".copy-btn" this app renders
// (transcript text/plan entries, tool_use/tool_result collapsible blocks,
// the fullscreen text modal - see openFullscreenTextModal() below and the
// PHP/JS mirrors that render these buttons: BlockedPromptView::render_
// collapsible_block()/renderCollapsibleBlock(), transcript/block.php/
// renderBlock()). navigator.clipboard requires a secure context (HTTPS or
// localhost) - this app is also reachable over plain HTTP on the LAN (see
// README), where it's simply undefined, so a real fallback (not just a
// silent no-op) matters here, not just for ancient browsers. ---
function copyTextToClipboard(text) {
  if (navigator.clipboard && window.isSecureContext) {
    return navigator.clipboard.writeText(text);
  }

  return new Promise(function (resolve, reject) {
    var textarea = document.createElement('textarea');
    textarea.value = text;
    // Off-screen, not display:none/hidden - execCommand('copy') needs the
    // element to actually be selectable, which a non-rendered element isn't.
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.setAttribute('readonly', '');
    document.body.appendChild(textarea);
    textarea.select();
    textarea.setSelectionRange(0, textarea.value.length);

    try {
      document.execCommand('copy') ? resolve(undefined) : reject(new Error('execCommand(copy) returned false'));
    } catch (e) {
      reject(e);
    } finally {
      document.body.removeChild(textarea);
    }
  });
}

// DOM event targets are typed as EventTarget because non-element targets are
// possible in the platform generally. Sessioneer's delegated handlers need closest()
// only when the target is a real Element; centralize that runtime guard so the
// handlers are both checkJs-clean and safe for synthetic/non-element events.
function closestEventTarget(event, selector) {
  return event.target instanceof Element ? event.target.closest(selector) : null;
}

function eventTargetHasClass(event, className) {
  return event.target instanceof Element && event.target.classList.contains(className);
}

// Finds the text this trigger button is meant to copy - either its own
// nearest <details> (an expandable collapsible block) or the nearest
// ".copy-block" wrapper (every other copy-btn site: non-expandable
// collapsible blocks, transcript text/plan/system entries) - then copies
// its ".copy-source" descendant's real textContent, same "read it straight
// off the rendered element" approach as openFullscreenTextModal() below,
// so copied text can never drift from what's actually shown.
document.addEventListener('click', function (e) {
  var trigger = closestEventTarget(e, '.copy-btn');

  if (!trigger) {
    return;
  }

  var container = trigger.closest('details, .copy-block');
  var source = container ? container.querySelector('.copy-source') : null;

  if (!source) {
    return;
  }

  var originalLabel = trigger.textContent;

  copyTextToClipboard(source.textContent).then(function () {
    trigger.textContent = 'Copied!';
  }, function () {
    trigger.textContent = 'Copy failed';
  }).finally(function () {
    setTimeout(function () {
      trigger.textContent = originalLabel;
    }, 1200);
  });
});

// --- full screen text view: a shared "View full screen" affordance for
// any long tool call/output or prompt context that's still hard to read
// even expanded (BlockedPromptView::render_collapsible_block()/
// render_full_block() in PHP, renderCollapsibleBlock()/renderFullBlock() in
// session.js - all four share this one modal rather than each page rolling
// its own). Reads the text straight off the triggering button's own
// nearest "details, .copy-block" ancestor's "pre.copy-source" instead of a
// data attribute, so there's no risk of the modal ever showing something
// different from what was actually expanded on the page - same lookup as
// the ".copy-btn" handler above (found live 2026-08-22: this used to look
// for `details` alone, which was fine when every collapsible block had its
// own <details> wrapper, but render_full_block()'s expandable branch
// deliberately has none - nested inside a tool-call entry's own outer
// <details>, a bare `details`-only lookup walked straight past this
// button's own block and grabbed the FIRST .copy-source in the whole
// entry instead, e.g. clicking "View full screen" on a tool's OUTPUT
// showed its CALL params instead - matching `.copy-block` too, which
// render_full_block() always sets on its own outer element regardless of
// which branch, is what actually stops the search at the right block). ---
var fullscreenTextModal = document.getElementById('fullscreen-text-modal');
var fullscreenTextModalContent = document.getElementById('fullscreen-text-modal-content');
var fullscreenTextModalClose = document.getElementById('fullscreen-text-modal-close');
var fullscreenTextModalWrapToggle = document.getElementById('fullscreen-text-modal-wrap-toggle');

if (fullscreenTextModal && fullscreenTextModalContent && fullscreenTextModalClose) {
  var bodyOverflowBeforeModal = '';
  // The original raw source (markdown or plain) behind whatever's
  // currently shown - see openFullscreenTextModal()'s own comment for why
  // the Copy button reads this instead of fullscreenTextModalContent's own
  // textContent.
  var fullscreenTextModalRawText = '';
  // Persisted across sessions/tabs, same convention as every other
  // sidebar-style toggle in this app (see SHOW_TOOL_DETAILS_KEY etc in
  // session.js) - defaults to off (horizontal-scroll, today's original
  // behavior) when unset.
  var FULLSCREEN_TEXT_WRAP_KEY = 'sessioneer-fullscreen-text-wrap';

  function fullscreenTextWrapEnabled() {
    try {
      return window.localStorage.getItem(FULLSCREEN_TEXT_WRAP_KEY) === '1';
    } catch (e) {
      return false;
    }
  }

  function applyFullscreenTextWrap(wrap) {
    fullscreenTextModalContent.classList.toggle('whitespace-pre', !wrap);
    fullscreenTextModalContent.classList.toggle('whitespace-pre-wrap', wrap);
    fullscreenTextModalContent.classList.toggle('break-words', wrap);

    if (fullscreenTextModalWrapToggle) {
      fullscreenTextModalWrapToggle.textContent = wrap ? 'Wrap: On' : 'Wrap: Off';
      fullscreenTextModalWrapToggle.setAttribute('aria-pressed', wrap ? 'true' : 'false');
    }
  }

  // $html (Andres's own ask, 2026-08-23 - "we need markdown in all the full
  // size modals"): the block's own already-rendered .markdown-body HTML,
  // when the expanded block is markdown-kind - see the click handler below,
  // which is the only caller that ever passes it. Reuses that SAME
  // rendering (server-side MarkdownRenderer::render_html() or its
  // poll-time JS mirror renderMarkdown(), whichever produced the inline
  // .markdown-body sibling in the first place) rather than re-parsing the
  // raw text a second time here - one rendering, shown twice, guaranteed
  // to look identical inline and full-screen. $text is ALWAYS the raw
  // source (markdown or plain) - kept in fullscreenTextModalRawText for
  // the Copy button, which should always copy the original source a user
  // could paste elsewhere, not the rendered HTML's plain-text content
  // (stripping **bold**/etc markers would lose the original formatting).
  function openFullscreenTextModal(text, html) {
    fullscreenTextModalRawText = text;

    if (html) {
      fullscreenTextModalContent.innerHTML = html;
      fullscreenTextModalContent.classList.add('markdown-body');
      fullscreenTextModalContent.classList.remove('whitespace-pre', 'whitespace-pre-wrap', 'break-words');

      if (fullscreenTextModalWrapToggle) {
        // Wrap/no-wrap doesn't apply to rendered markdown - it already
        // wraps via its own whitespace-pre-wrap/break-words classes
        // (MarkdownRenderer::render_prose()), and a fenced code block
        // inside it scrolls horizontally on its own regardless.
        fullscreenTextModalWrapToggle.classList.add('hidden');
      }
    } else {
      fullscreenTextModalContent.textContent = text;
      fullscreenTextModalContent.classList.remove('markdown-body');
      applyFullscreenTextWrap(fullscreenTextWrapEnabled());

      if (fullscreenTextModalWrapToggle) {
        fullscreenTextModalWrapToggle.classList.remove('hidden');
      }
    }

    fullscreenTextModal.classList.remove('hidden');
    // Prevents the page behind the modal from also scrolling on iOS
    // Safari (the modal's own <pre> captures touch-scroll fine on its
    // own, but background scroll can still "leak through" underneath a
    // plain fixed overlay there without this).
    bodyOverflowBeforeModal = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
  }

  function closeFullscreenTextModal() {
    fullscreenTextModal.classList.add('hidden');
    fullscreenTextModalContent.textContent = '';
    fullscreenTextModalContent.classList.remove('markdown-body');
    fullscreenTextModalRawText = '';
    document.body.style.overflow = bodyOverflowBeforeModal;
  }

  document.addEventListener('click', function (e) {
    var trigger = closestEventTarget(e, '.expand-fullscreen-btn');

    if (trigger) {
      var container = trigger.closest('details, .copy-block');
      var pre = container ? container.querySelector('.copy-source') : null;
      // Present only on a markdown-kind block (BlockedPromptView::
      // collapsible-markdown-block.php / TranscriptView's own 'text' block
      // rendering, and their session.js poll-time mirrors) - a plain block
      // has no such sibling, so html stays undefined and
      // openFullscreenTextModal() falls back to its plain-text behavior.
      var markdownBody = container ? container.querySelector('.markdown-body') : null;

      if (pre) {
        openFullscreenTextModal(pre.textContent, markdownBody ? markdownBody.innerHTML : null);
      }

      return;
    }

    if (e.target === fullscreenTextModalWrapToggle) {
      var nextWrap = !fullscreenTextWrapEnabled();

      try {
        window.localStorage.setItem(FULLSCREEN_TEXT_WRAP_KEY, nextWrap ? '1' : '0');
      } catch (ignored) {}

      applyFullscreenTextWrap(nextWrap);

      return;
    }

    if (closestEventTarget(e, '#fullscreen-text-modal-copy')) {
      var copyTrigger = closestEventTarget(e, '#fullscreen-text-modal-copy');
      var originalLabel = copyTrigger.textContent;

      copyTextToClipboard(fullscreenTextModalRawText).then(function () {
        copyTrigger.textContent = 'Copied!';
      }, function () {
        copyTrigger.textContent = 'Copy failed';
      }).finally(function () {
        setTimeout(function () {
          copyTrigger.textContent = originalLabel;
        }, 1200);
      });

      return;
    }

    if (e.target === fullscreenTextModalClose || e.target === fullscreenTextModal) {
      closeFullscreenTextModal();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !fullscreenTextModal.classList.contains('hidden')) {
      closeFullscreenTextModal();
    }
  });

  // --- swipe-right to close (touch devices) - Andres's own ask 2026-08-22
  // (reversed from an initial swipe-LEFT the same day, per Andres's
  // follow-up correction), the close button/Escape were the only ways out
  // before this. Same
  // distance/ratio thresholds as session.js's own sidebar swipe gesture
  // (SWIPE_MIN_DISTANCE_PX/SWIPE_MAX_VERTICAL_RATIO there), deliberately
  // NOT duplicated as shared constants - this modal's gesture is
  // independent of session.js's (this file loads on every page that has
  // the modal, session.js only on session.php) and matching the same
  // "how far/how straight" feel is the only actual coupling that matters.
  // Deliberately does NOT exclude touches starting inside the modal's own
  // scrollable <pre> content (unlike the sidebar gesture's .overflow-auto
  // exclusion) - almost the entire modal IS that <pre>, so excluding it
  // would leave swipe-to-close only reachable from the thin header bar.
  // A genuine fast, mostly-horizontal swipe (the same threshold already
  // trusted to tell a real gesture apart from scrolling elsewhere in this
  // app) is distinct enough from the slower horizontal drag reading a long
  // unwrapped line needs that the two don't meaningfully collide in
  // practice. ---
  var FULLSCREEN_SWIPE_MIN_DISTANCE_PX = 80;
  var FULLSCREEN_SWIPE_MAX_VERTICAL_RATIO = 0.5;
  var fullscreenTouchStartX = null;
  var fullscreenTouchStartY = null;

  fullscreenTextModal.addEventListener('touchstart', function (e) {
    if (e.touches.length !== 1) {
      fullscreenTouchStartX = null;
      fullscreenTouchStartY = null;
      return;
    }

    fullscreenTouchStartX = e.touches[0].clientX;
    fullscreenTouchStartY = e.touches[0].clientY;
  }, { passive: true });

  fullscreenTextModal.addEventListener('touchend', function (e) {
    if (fullscreenTouchStartX === null || e.changedTouches.length !== 1) {
      fullscreenTouchStartX = null;
      fullscreenTouchStartY = null;
      return;
    }

    var deltaX = e.changedTouches[0].clientX - fullscreenTouchStartX;
    var deltaY = e.changedTouches[0].clientY - fullscreenTouchStartY;
    fullscreenTouchStartX = null;
    fullscreenTouchStartY = null;

    if (deltaX <= FULLSCREEN_SWIPE_MIN_DISTANCE_PX || Math.abs(deltaY) > Math.abs(deltaX) * FULLSCREEN_SWIPE_MAX_VERTICAL_RATIO) {
      return;
    }

    closeFullscreenTextModal();
  }, { passive: true });

  // Found live 2026-08-22 (Andres): swipe-to-close worked for some
  // fullscreen views but not others - reported specifically for a
  // subagent's report, which tends to be long/prose-heavy (real
  // paragraphs, often long unbroken lines with "Wrap: Off" the default)
  // and so is more likely than typical short tool output to actually need
  // scrolling inside the modal's own <pre>. Root cause: no touchcancel
  // handler existed - if iOS cancels a touch sequence mid-gesture (which
  // it does whenever a scroll/pan takes over, far more likely on tall
  // scrollable content than on short content that never scrolls),
  // touchend never fires at all, leaving fullscreenTouchStartX/Y stuck
  // from the abandoned gesture. The NEXT independent swipe attempt's
  // touchend then computed its delta against those STALE coordinates
  // instead of its own real start point, silently producing wrong
  // results. Resetting on touchcancel, same as the other early-return
  // branches above already do, closes that gap.
  fullscreenTextModal.addEventListener('touchcancel', function () {
    fullscreenTouchStartX = null;
    fullscreenTouchStartY = null;
  }, { passive: true });
}

// Exposed on window specifically - openFullscreenTextModal() is declared
// inside the DOM-guard block above (a block scope, not the top-level one
// parseJsonResponse()/escapeHtml() live in), so it would NOT otherwise be
// reachable from sidebar.js/session.js as a plain global. Sidebar's "Open
// todo file" link (Andres 2026-08-25) calls this directly.
window.openFullscreenTextModal = openFullscreenTextModal;

// --- Editable fullscreen text editor (Andres's own ask, 2026-08-24: a way
// to expand a text area to full screen while typing, both compose and
// answering a blocked prompt) - triggered by any .expand-edit-fullscreen-btn
// (compose-textarea, blocked-prompt/options.php's freetext-reply-textarea,
// and blocked-prompt/multi-question.php's per-question freetext-input, on
// both session.php and index.php). A genuinely separate modal from the
// read-only fullscreen-text-modal above - see #fullscreen-edit-modal's own
// comment in layout.php for why. ---
var fullscreenEditModal = document.getElementById('fullscreen-edit-modal');
var fullscreenEditModalTextarea = document.getElementById('fullscreen-edit-modal-textarea');
var fullscreenEditModalClose = document.getElementById('fullscreen-edit-modal-close');

if (fullscreenEditModal && fullscreenEditModalTextarea && fullscreenEditModalClose) {
  // The real <textarea> currently being edited full screen - null whenever
  // the modal is closed. Every keystroke in the modal mirrors straight
  // back into it (see the 'input' listener below), so the source field is
  // always authoritative the whole time the modal is open: there's no
  // separate "did they mean to keep this" state to manage, and closing
  // the modal (the close button, Escape, or backdrop click) never needs to
  // decide between saving and discarding.
  var fullscreenEditModalSource = null;
  var fullscreenEditModalBodyOverflowBefore = '';

  function openFullscreenEditModal(sourceTextarea) {
    fullscreenEditModalSource = sourceTextarea;
    fullscreenEditModalTextarea.value = sourceTextarea.value;
    fullscreenEditModalTextarea.placeholder = sourceTextarea.placeholder || '';
    fullscreenEditModal.classList.remove('hidden');
    fullscreenEditModalBodyOverflowBefore = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    fullscreenEditModalTextarea.focus();
    // Cursor at the end - matches where continuing to type would land if
    // the user had just kept typing in the original field instead of
    // expanding it.
    var end = fullscreenEditModalTextarea.value.length;
    fullscreenEditModalTextarea.setSelectionRange(end, end);
  }

  function closeFullscreenEditModal() {
    fullscreenEditModal.classList.add('hidden');
    fullscreenEditModalTextarea.value = '';
    fullscreenEditModalSource = null;
    document.body.style.overflow = fullscreenEditModalBodyOverflowBefore;
  }

  // A real, dispatched `input` event (bubbles:true), not just a value
  // assignment - so anything already listening on the source field
  // (compose-textarea's autosize/draft-save/send-button-enable logic,
  // wireClearButton()'s show/hide) reacts exactly as if the user had
  // typed there directly, with no separate sync-on-close step needed.
  fullscreenEditModalTextarea.addEventListener('input', function () {
    if (!fullscreenEditModalSource) {
      return;
    }

    fullscreenEditModalSource.value = fullscreenEditModalTextarea.value;
    fullscreenEditModalSource.dispatchEvent(new Event('input', { bubbles: true }));
  });

  document.addEventListener('click', function (e) {
    var trigger = closestEventTarget(e, '.expand-edit-fullscreen-btn');

    if (trigger) {
      // Every current call site wraps its own <textarea> + this button
      // together in one .relative container (compose-bar.php, blocked-
      // prompt/options.php, blocked-prompt/multi-question.php) - the same
      // shape wireClearButton()'s own callers already use for their clear
      // buttons, reused here rather than inventing a second convention.
      var container = trigger.closest('.relative');
      var textarea = container ? container.querySelector('textarea') : null;

      if (textarea) {
        openFullscreenEditModal(textarea);
      }

      return;
    }

    if (e.target === fullscreenEditModalClose || e.target === fullscreenEditModal) {
      closeFullscreenEditModal();
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && !fullscreenEditModal.classList.contains('hidden')) {
      closeFullscreenEditModal();
    }
  });
}

// --- fixed-footer height tracking: shared between session.php's compose
// bar and index.php's dashboard footer, both position:fixed at the bottom
// of the viewport with genuinely variable height (the quota box alone is
// user-collapsible AND shows a variable number of lines depending on live
// data, and the push-notify button/mode-toggle rows can each independently
// appear or disappear). A static CSS padding-bottom guess (the class list's
// own pb-* is only the no-ResizeObserver CSS fallback) left the last
// history entries/dashboard rows tucked behind the footer whenever it grew
// taller than that guess - found live on the dashboard too, not just
// session.php, which is why this moved here instead of staying a
// session.js-only fix. ---
function watchFixedFooterHeight(footerEl, onHeightChange) {
  if (!footerEl || !window.ResizeObserver) {
    return;
  }

  new ResizeObserver(function () {
    onHeightChange(footerEl.offsetHeight);
  }).observe(footerEl);
}

// Shared by session.js/archived-session.js's own "jump to a search
// result" scroll logic - a jump target sitting inside a collapsed
// tool-call entry (<details class="tool-call-entry">, closed by default -
// see TranscriptView::render_tool_call_entry_html()) is present in the DOM
// but not actually rendered while its <details> stays closed, so its own
// getBoundingClientRect() comes back as if it doesn't exist at all
// (browsers never lay out a closed <details>'s children), making any
// scroll-to-it calculation land on the wrong spot entirely - found live
// 2026-08-20 (Andres: "clicking on a result doesn't go to the right
// one"). Opens every ancestor <details>, not just the immediate one, in
// case of nesting.
function openAncestorDetails(target) {
  var el = target.parentElement;

  while (el) {
    if (el.tagName === 'DETAILS' && !el.open) {
      el.open = true;
    }

    el = el.parentElement;
  }
}

// --- navigation-away loading blanket: covers the iOS edge-swipe-back
// gesture (Andres, 2026-08-08) - going from session.php to the dashboard
// via that gesture left the old page's content sitting frozen on screen
// for however long the browser actually took to swap in the next page,
// unlike a real native-app transition. There's no click handler to hook
// for a swipe gesture the way there is for a normal link tap, so this
// listens for the one event that fires regardless of WHAT triggered the
// navigation - swipe-back, the browser's own back button, a plain link
// tap, all of it. pagehide fires right as the current page is being torn
// down for that navigation, whether it succeeds or not; showing the
// blanket immediately there means it's already covering the screen by the
// time anything visibly changes. ---
var navigationBlanket = document.getElementById('navigation-blanket');

if (navigationBlanket) {
  window.addEventListener('pagehide', function () {
    navigationBlanket.classList.remove('hidden');
  });

  // pagehide ALSO fires when the page is merely being tucked into the
  // bfcache (backgrounding the browser/switching apps can trigger this on
  // iOS, not just a real navigation) - persisted=true on the matching
  // pageshow is what tells the two apart. Without hiding it back here,
  // the blanket would stay stuck over a page the user never actually
  // left, with no real navigation ever coming along to replace it.
  window.addEventListener('pageshow', function (e) {
    if (!e.persisted) {
      return;
    }

    // The dashboard specifically (not session.php/archived_session.php,
    // which intentionally preserve scroll position/compose drafts across
    // app-backgrounding-triggered bfcache restores - reloading THOSE would
    // regress that on purpose-built behavior): a genuine bfcache restore (a
    // real history back-navigation - either browser back/forward, or the
    // swipe-right gesture below/sidebar.js's own copy of it, both of which
    // navigate via a plain forward `location.href` and so should normally
    // NOT hit this at all, but iOS's own native edge-swipe-back gesture can
    // ALSO fire independently of this app's JS and does a real bfcache
    // restore) serves the exact DOM the dashboard had at the moment it was
    // left, frozen - unlike session.php's live-polled content, nothing here
    // ever refreshes that on its own, so a stale header/session list can sit
    // there indefinitely instead of just the one poll cycle a fresh load
    // would need. #new-session-details only exists on the dashboard.
    if (document.getElementById('new-session-details')) {
      location.reload();

      return;
    }

    navigationBlanket.classList.add('hidden');
  });
}

// --- shared horizontal-swipe detector (touch devices) - the guard logic
// (ignore touches inside a horizontally-scrollable block, an active text
// selection, or a textarea) used to live duplicated per page; extracted
// 2026-09-30 so archived_session.php could get its own swipe-right-to-
// dashboard gesture without copying it a second time. archived_session.php
// previously had NO swipe-gesture JS of its own at all and relied entirely
// on the browser/OS's native edge-swipe-back - which isn't available in
// every context (e.g. a home-screen-installed iOS PWA running in standalone
// display mode has no browser chrome to swipe from at all), so swiping
// right there could do nothing (Andres, 2026-09-30). sidebar.js (session.php)
// uses this for its open/close-sidebar-or-navigate gesture; archived-
// session.js uses it for a plain swipe-right-to-dashboard, matching
// session.php's behavior. Ignored for anything that isn't a clearly
// horizontal gesture, so it doesn't fight with normal vertical scrolling.
function initHorizontalSwipe(handlers) {
  var SWIPE_MIN_DISTANCE_PX = 80;
  var SWIPE_MAX_VERTICAL_RATIO = 0.5;
  var touchStartX = null;
  var touchStartY = null;

  // A non-collapsed selection means this touch is (or might become)
  // dragging a text-selection handle, not swiping - those handles are
  // native OS chrome, not real DOM elements, so there's no element to
  // target-check the way the scrollable-block case below does; checking
  // the selection itself is the only reliable signal. Checked on both
  // touchstart (the selection already exists from an earlier long-press)
  // and touchend (in case it changed mid-touch), since real devices vary
  // in whether those are the same touch sequence or two separate ones.
  function touchTargetsActiveSelection() {
    var selection = window.getSelection();
    return !!selection && !selection.isCollapsed;
  }

  // window.getSelection() above never sees a selection inside a <textarea>
  // - form controls keep their own separate selectionStart/selectionEnd
  // state, invisible to the document-level Selection API - so a swipe-to-
  // select drag starting there used to fall straight through to the
  // sidebar/back-navigation gesture instead. Any touch landing on a
  // textarea at all is excluded here, not just an active-selection one:
  // that gesture belongs to the textarea (caret placement, selecting,
  // scrolling a tall one), never to the app-level swipe.
  function touchTargetsTextarea(e) {
    return !!closestEventTarget(e, 'textarea');
  }

  document.addEventListener('touchstart', function (e) {
    // Ignore touches starting inside a horizontally-scrollable command/
    // output block - that gesture is for scrolling the block itself, not
    // for the app-level swipe.
    if (e.touches.length !== 1 || closestEventTarget(e, '.overflow-x-auto, .overflow-auto') || touchTargetsActiveSelection() || touchTargetsTextarea(e)) {
      touchStartX = null;
      touchStartY = null;
      return;
    }

    touchStartX = e.touches[0].clientX;
    touchStartY = e.touches[0].clientY;
  }, { passive: true });

  document.addEventListener('touchend', function (e) {
    if (touchStartX === null || e.changedTouches.length !== 1 || touchTargetsActiveSelection() || touchTargetsTextarea(e)) {
      touchStartX = null;
      touchStartY = null;
      return;
    }

    var deltaX = e.changedTouches[0].clientX - touchStartX;
    var deltaY = e.changedTouches[0].clientY - touchStartY;
    touchStartX = null;
    touchStartY = null;

    if (Math.abs(deltaX) < SWIPE_MIN_DISTANCE_PX || Math.abs(deltaY) > Math.abs(deltaX) * SWIPE_MAX_VERTICAL_RATIO) {
      return;
    }

    if (deltaX < 0 && handlers.onSwipeLeft) {
      handlers.onSwipeLeft();
    } else if (deltaX > 0 && handlers.onSwipeRight) {
      handlers.onSwipeRight();
    }
  }, { passive: true });
}
