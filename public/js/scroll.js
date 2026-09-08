// @ts-check
// session.php's docked scroll-toolbar controls - go-to-bottom, go-to-top,
// and previous-user-message (jump-to-new is owned by highlights.js) - plus
// the compose-bar footer's stick-to-bottom behavior. Extracted from
// session.js 2026-08-24 (second cut of the "split session.js into modules"
// pass).
//
// Swap 2026-09-06 (Andres's own report): these controls used to be
// position:fixed circles stacked over the page's right edge, which covered
// chat text on mobile. They now live inside #scroll-toolbar (see
// session.php), a real flex child of #app-shell between #page-content and
// #compose-bar, so they take layout space instead of overlaying content -
// dropping all the bottom-offset/repositioning math that used to hover
// them over the compose bar's variable height. The only reason
// #compose-bar's height is still watched is to keep the page glued to the
// bottom through footer resizes.
//
// Own independent document.getElementById() lookups, same convention as
// common.js - other files (session.js, highlights.js) look up the same real
// DOM elements by the same IDs independently rather than this module
// passing references around; a plain global function call (scrollToBottom(),
// maybeAutoScroll()) is how cross-file calls work here, matching
// escapeHtml()/parseJsonResponse() in common.js.
var pageContent = document.getElementById('page-content');
var goToBottomBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('go-to-bottom-btn'));
var goToTopBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('go-to-top-btn'));
var prevUserBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('prev-user-btn'));
var historyList = document.getElementById('history-list');

var SCROLL_BOTTOM_THRESHOLD_PX = 80;
var SCROLL_TOP_THRESHOLD_PX = 80;

// #page-content is the page's own scrolling container (see #app-shell in
// session.php), and #compose-bar takes real flex space instead of overlaying
// content (see compose-bar.php's own comment), so #page-content no longer
// needs bottom padding to clear it. Only #compose-bar's variable height is
// tracked - to stick to the bottom through footer resizes - starting as
// "unknown" until the ResizeObserver (or session.js's own synchronous seed)
// delivers a real height. See the ResizeObserver callback for why the
// seeding matters, not just the observer's own first delivery.
var lastFixedFooterHeight = 0;
// True once lastFixedFooterHeight actually reflects a real #compose-bar
// height - either from the ResizeObserver below firing at least once, or
// (see session.js's own "land at the bottom on open" branch) seeded
// synchronously up front. See the ResizeObserver callback for why the
// seeding matters, not just the observer's own first delivery.
var footerHeightKnown = false;

watchFixedFooterHeight(document.getElementById('compose-bar'), function (height) {
  // Stick to bottom through footer resizes - found live 2026-08-30
  // (Andres: iOS app-switch back to a session page "scrolls to top" and
  // the footer "renders too tall"). #compose-bar is a normal flex
  // sibling of #page-content, not an overlay (see compose-bar.php's own
  // comment) - every px it grows by is a px #page-content's clientHeight
  // shrinks by, with no compensating scrollTop change. The initial "land
  // at the bottom" scrollToBottom(false) call (session.js) runs
  // synchronously on page load, well before quota-footer.js's own
  // /quota.php fetch resolves and grows this same footer - reproduced
  // live: the gap between scrollTop+clientHeight and scrollHeight
  // measured ~140px right after that fetch settled, on a page where the
  // user had been sitting exactly at the bottom.
  //
  // This callback fires AFTER the browser has already relaid-out to the
  // NEW size, so there's no direct way to read #page-content's
  // clientHeight as it was a moment ago - but flex arithmetic guarantees
  // it was exactly today's clientHeight plus however much the footer
  // just grew (height - lastFixedFooterHeight), which is what this
  // reconstructs to check "was the user at the bottom right before this
  // specific resize" rather than the already-shifted layout. Symmetric
  // for a shrinking footer too (quota panel collapsing) - re-snapping is
  // harmless there since clientHeight only grew.
  //
  // footerHeightKnown/lastFixedFooterHeight can't rely solely on this
  // observer's own bookkeeping, though - two tried-and-measured-wrong
  // assumptions here: (1) that a plain footerHeightKnown flag set only
  // by THIS callback would catch the growth, which fails on a fast
  // (same-LAN) /quota.php response, where this callback's very FIRST
  // delivery can already report the fully-grown height (ResizeObserver
  // only guarantees delivering the latest size, not every intermediate
  // one - the small pre-fetch height may never get its own delivery to
  // diff against); and (2) tracking "am I at the bottom" via a
  // #page-content 'scroll' listener instead, which raced this same
  // callback in the wrong order in WebKit specifically - changing
  // #compose-bar's height alone (nothing about scrollTop) was enough to
  // fire a native 'scroll' event on #page-content, sometimes a
  // millisecond BEFORE this ResizeObserver callback saw the same resize,
  // reading isNearBottom() against the ALREADY-shrunk clientHeight and
  // wrongly clearing the flag first. session.js's own "land at the
  // bottom on open" branch seeds lastFixedFooterHeight/footerHeightKnown
  // synchronously instead, from the real #compose-bar height at the
  // exact moment it scrolls to bottom - giving this callback a real
  // "before" baseline even on its own first delivery, no race either way.
  if (footerHeightKnown && (pageContent.scrollTop + pageContent.clientHeight + (height - lastFixedFooterHeight)) >= (pageContent.scrollHeight - SCROLL_BOTTOM_THRESHOLD_PX)) {
    scrollToBottom(false);
  }

  footerHeightKnown = true;
  lastFixedFooterHeight = height;
});

// --- scroll-to-bottom: the toolbar control enables whenever there's more
// page below the viewport, and new content (polled messages, a
// freshly-appeared/updated prompt) only auto-scrolls into view if the
// user was already at the bottom - never yanks them away from history
// they scrolled up to read. ---

// #page-content is the page's own scrolling container (see #app-shell in
// session.php) rather than the whole body, so "near bottom" is measured
// against ITS scrollTop/clientHeight/scrollHeight - no visualViewport
// compensation needed here any more: #app-shell's body is sized with
// 100dvh (a viewport unit that itself shrinks when iOS's on-screen
// keyboard/dynamic toolbar appears), so #page-content's own clientHeight
// already reflects the real visible area without a separate check.
function isNearBottom() {
  return (pageContent.scrollTop + pageContent.clientHeight) >= (pageContent.scrollHeight - SCROLL_BOTTOM_THRESHOLD_PX);
}

function scrollToBottom(smooth) {
  pageContent.scrollTo({ top: pageContent.scrollHeight, behavior: smooth ? 'smooth' : 'auto' });
}

function updateGoToBottomVisibility() {
  if (goToBottomBtn) {
    goToBottomBtn.disabled = isNearBottom();
  }
}

function maybeAutoScroll(wasNearBottom) {
  if (wasNearBottom) {
    scrollToBottom(true);
  }

  updateGoToBottomVisibility();
}

if (goToBottomBtn) {
  pageContent.addEventListener('scroll', updateGoToBottomVisibility, { passive: true });
  goToBottomBtn.addEventListener('click', function () { scrollToBottom(true); });
  updateGoToBottomVisibility();
}

// --- scroll-to-top: the persistent counterpart above (Andres's own ask,
// 2026-08-23) - same "disabled while already there" treatment as
// #go-to-bottom-btn, its own dedicated threshold (SCROLL_TOP_THRESHOLD_PX,
// not SCROLL_BOTTOM_THRESHOLD_PX reused under a top-facing name) even
// though the two happen to share the same value today, since there's no
// reason the two edges must always stay tuned together. ---
function isNearTop() {
  return pageContent.scrollTop <= SCROLL_TOP_THRESHOLD_PX;
}

function scrollToTop(smooth) {
  pageContent.scrollTo({ top: 0, behavior: smooth ? 'smooth' : 'auto' });
}

function updateGoToTopVisibility() {
  if (goToTopBtn) {
    goToTopBtn.disabled = isNearTop();
  }
}

if (goToTopBtn) {
  pageContent.addEventListener('scroll', updateGoToTopVisibility, { passive: true });
  goToTopBtn.addEventListener('click', function () { scrollToTop(true); });
  updateGoToTopVisibility();
}

// --- scroll to previous user message: jump to the nearest earlier user
// message that's currently scrolled out of view (not visible in viewport).
// If no such message exists, defer to the #load-until-user-btn fallback
// to load more history. Always shown when history exists (unconditional,
// per Andres's own ask, 2026-08-30). ---

var PREV_USER_TOP_OFFSET_PX = 16;

function updatePrevUserBtnVisibility() {
  if (!prevUserBtn || !historyList) {
    return;
  }

  // Always available when there's any rendered history, disabled only if
  // history list itself is empty or doesn't exist
  var hasHistory = historyList.children.length > 0;
  prevUserBtn.disabled = !hasHistory;
}

function scrollToPrevUserMessage() {
  if (!historyList || !pageContent) {
    return;
  }

  var pageContentRect = pageContent.getBoundingClientRect();
  var userEntries = historyList.querySelectorAll('[data-role="user"]');
  var outOfViewCandidates = [];

  for (var i = 0; i < userEntries.length; i++) {
    var entry = userEntries[i];
    var entryRect = entry.getBoundingClientRect();

    // Check if the entry is entirely above the viewport (completely out of
    // view above). Visible means rect overlaps with viewport, so out-of-view
    // means: bottom edge is above the viewport top, i.e.
    // entryRect.bottom <= pageContentRect.top
    if (entryRect.bottom <= pageContentRect.top) {
      outOfViewCandidates.push({
        element: entry,
        rect: entryRect,
      });
    }
  }

  if (outOfViewCandidates.length === 0) {
    // No user message currently out of view above - defer to load-more
    // fallback, which will load more history if available
    document.getElementById('load-until-user-btn').click();
    return;
  }

  // Find the one with the largest rect.top (closest to the viewport top)
  var nearest = outOfViewCandidates.reduce(function (acc, candidate) {
    return candidate.rect.top > acc.rect.top ? candidate : acc;
  });

  // Scroll to it using the same manual scrollTo() pattern as jumpToNewContent()
  var targetScrollTop = pageContent.scrollTop + (nearest.rect.top - pageContentRect.top) - PREV_USER_TOP_OFFSET_PX;
  pageContent.scrollTo({ top: Math.max(0, targetScrollTop), behavior: 'smooth' });
}

if (prevUserBtn && historyList) {
  // Initial visibility check
  updatePrevUserBtnVisibility();

  // Listen to history changes - note: MutationObserver would be more
  // elegant, but using a simple polling approach via the existing scroll
  // listener (cheap, matches the codebase's existing minimal-observer
  // style) and re-checking on every click
  prevUserBtn.addEventListener('click', scrollToPrevUserMessage);
}
