// @ts-check
// "New content" / "loaded older content" / "jumped-to search result"
// highlight rings, the "New" divider, and #jump-to-new-btn - all the
// poll-time visual markers that call out what just changed on screen.
// Plain global functions/vars, same convention as common.js/scroll.js;
// own independent document.getElementById() lookups for #history-list/
// #jump-to-new-btn (session.js holds its own separate references to the
// same real elements for its own, unrelated purposes). Extracted from
// session.js 2026-08-24, fourth cut of the "split session.js into modules"
// pass.

// Minimum time the "New" divider/highlight must actually be on screen
// before it starts fading - without this, a poll that lands while the
// user is already scrolled to the bottom (the common case, since
// maybeAutoScroll() keeps them there) would have the divider intersect
// the viewport and start fading the instant it's inserted, defeating
// the entire point of marking it as new.
var NEW_CONTENT_VISIBLE_DELAY_MS = 2500;

// Must match the .new-content-highlight.fading box-shadow transition
// duration in <style> above - the delay before the highlight classes are
// actually removed from the DOM, so cleanup happens after the fade is
// visually complete rather than cutting it off mid-animation.
var NEW_CONTENT_HIGHLIGHT_FADE_MS = 1200;

// Same fade duration as .jump-target-highlight.fading's own transition
// in <style> above.
var JUMP_TARGET_HIGHLIGHT_FADE_MS = 1200;

// Landing on a search result - a distinct green ring (see .jump-target-
// highlight in <style> above), not the amber .new-content-highlight
// used for freshly-polled content, so the two read as different things
// (Andres's own ask, 2026-08-20). Reuses NEW_CONTENT_VISIBLE_DELAY_MS as
// a plain minimum-visible-before-fading timer (not IntersectionObserver-
// gated like markNewContent() below) - the scroll that brings this
// target into view already happened by the time this is called, so it's
// effectively "on screen" from the very first frame, same reasoning
// that timer exists for there.
function highlightJumpTarget(el) {
  el.classList.add('jump-target-highlight');

  setTimeout(function () {
    el.classList.add('fading');

    setTimeout(function () {
      el.classList.remove('jump-target-highlight', 'fading');
    }, JUMP_TARGET_HIGHLIGHT_FADE_MS);
  }, NEW_CONTENT_VISIBLE_DELAY_MS);
}

// Same fade duration as .older-content-highlight.fading's own transition
// in <style> above.
var OLDER_CONTENT_HIGHLIGHT_FADE_MS = 1200;

// Shared by every "highlight a batch of entries, then fade each one
// independently once it's actually been ON SCREEN for
// NEW_CONTENT_VISIBLE_DELAY_MS" ring - used for both the amber
// .new-content-highlight (fresh poll content) and the cyan
// .older-content-highlight ("Load older messages"/"Load until last
// message" - Andres's own ask 2026-08-22 for a visually distinct ring).
// Per-element, IntersectionObserver-gated fade (changed 2026-08-24, was
// a flat setTimeout batch-fade for the older-content ring specifically -
// Andres's own bug report: a large loaded batch can insert MOSTLY off-
// screen above the button just tapped, e.g. right after scrolling to the
// very top, or once "Show subagent calls and outputs" reveals entries
// that were display:none at insertion time - a fixed timer faded all of
// them together regardless of whether the user had actually scrolled up
// to see the ones further back). Each element gets its own fade clock,
// started only once THAT element intersects the viewport, exactly the
// markNewContent()/newEntryObserver behavior below, just parameterized
// by highlight class and fade duration so both call sites share one
// implementation.
function makeSeenFadeObserver(highlightClass, fadeMs) {
  if (typeof IntersectionObserver === 'undefined') {
    return null; // no observer support - markers just stay put, harmless
  }

  var observer = new IntersectionObserver(function (observerEntries) {
    observerEntries.forEach(function (observerEntry) {
      if (!observerEntry.isIntersecting) {
        return;
      }

      var el = observerEntry.target;
      observer.unobserve(el);

      setTimeout(function () {
        // .fading (not a straight classList.remove(highlightClass)) so
        // `transition` stays on the element for the whole animation -
        // removing the base class immediately would strip `transition`
        // at the same instant as `box-shadow`, snapping the ring off
        // instead of fading it.
        el.classList.add('fading');
        setTimeout(function () {
          el.classList.remove(highlightClass, 'fading');
        }, fadeMs);
      }, NEW_CONTENT_VISIBLE_DELAY_MS);
    });
  });

  return observer;
}

var olderContentFadeObserver = makeSeenFadeObserver('older-content-highlight', OLDER_CONTENT_HIGHLIGHT_FADE_MS);

function highlightLoadedOlderContent(entryElements) {
  entryElements.forEach(function (el) { el.classList.add('older-content-highlight'); });

  if (!olderContentFadeObserver) {
    return;
  }

  entryElements.forEach(function (el) { olderContentFadeObserver.observe(el); });
}

// Marks entries fresh off this poll cycle: a "New" divider above the
// batch plus a highlight ring on each entry in it, so it's obvious what
// just arrived without having to spot it by eye in a long list.
//
// Two independent lifecycles, on purpose:
//  - At most ONE divider ever exists. A newer call removes the previous
//    one immediately, no fade, no visibility check - it's purely a "new
//    stuff starts here" landmark, and once a newer batch has arrived,
//    that's no longer where new stuff starts. This also sidesteps a real
//    bug the per-batch version this replaced had: if a batch's entries
//    are ALL currently hidden (Andres toggles "Show subagent calls and
//    outputs" off, or just hasn't loaded yet - see the default-hidden
//    .entry-subagent-only rule in the <style> above - which sets
//    display:none on whole entries, not just a class), a
//    display:none element can never intersect the
//    viewport, so a fade condition requiring every element in a batch to
//    be seen could never be satisfied - the divider got stuck forever,
//    and a poll landing while it was still stuck (very likely, since it
//    could never clear) produced another one right after it, reading as
//    the "New" label repeating.
//  - Each entry's own highlight ring fades independently, the instant
//    THAT entry has actually been on screen for NEW_CONTENT_VISIBLE_DELAY_MS
//    - not tied to the divider, not tied to any other entry in the same
//    poll batch. A hidden entry's ring just never fades, same as before,
//    but that's invisible and harmless on its own now that it can't also
//    block the divider or any other entry's fade.
//  - markNewContent() itself skips creating the divider at all when
//    every entry in the batch is currently hidden this same way (the
//    "New" landmark would otherwise point at nothing actually visible
//    until the toggle is flipped on) - the highlight ring still gets
//    applied and observed either way, so it's ready to fade correctly
//    the moment the toggle reveals it.
//
// $beforeNode and every element in $entryElements must already be
// attached to the real #history-list element (not a detached
// DocumentFragment) - the IntersectionObserver below only fires once an
// element is actually connected to the document, and inserting into a
// fragment first would leave a window where "attached but not yet
// observed" could miss the very first paint.
var currentDivider = null;

// #jump-to-new-btn (Andres's own ask, 2026-08-22): shown only while
// there's a "New" divider AND it's actually scrolled out of view right
// now - checked directly against live geometry (getBoundingClientRect(),
// not IntersectionObserver) rather than piggybacking on
// newEntryObserver/dividerObserver below, which are deliberately DELAYED
// (NEW_CONTENT_VISIBLE_DELAY_MS, ~2.5s) before they act - that delay
// exists to give the highlight RING time to be visually noticed before
// it fades, which is the wrong timing for this button: found live
// 2026-08-22 (Andres) that reusing it left the button incorrectly shown
// for that whole ~2.5s window even when the new content was already on
// screen (e.g. already scrolled to the bottom when it arrived). Also
// decides which way the button points - up if the divider is above the
// visible area, down if below - rather than always assuming "below":
// correct for the common append-at-the-bottom case, but not guaranteed
// in general (e.g. scrolling back up through a divider just before its
// own delayed fade/removal runs).
//
// Called right after currentDivider is set (below), on every
// #page-content scroll (see the scroll listener further down), and
// (harmlessly redundant, but cheap insurance) from dividerObserver's own
// delayed callback too.
var jumpToNewBtn = /** @type {HTMLButtonElement|null} */ (document.getElementById('jump-to-new-btn'));

function updateJumpToNewVisibility() {
  if (!jumpToNewBtn) {
    return;
  }

  if (!currentDivider) {
    jumpToNewBtn.disabled = true;
    return;
  }

  var dividerRect = currentDivider.getBoundingClientRect();
  var pageContentRect = pageContent.getBoundingClientRect();
  var alreadyVisible = dividerRect.bottom > pageContentRect.top && dividerRect.top < pageContentRect.bottom;

  jumpToNewBtn.disabled = alreadyVisible;

  if (!alreadyVisible) {
    jumpToNewBtn.innerHTML = dividerRect.top < pageContentRect.top ? '&uarr; New' : '&darr; New';
  }
}

if (jumpToNewBtn) {
  pageContent.addEventListener('scroll', updateJumpToNewVisibility, { passive: true });
  updateJumpToNewVisibility();
}

// Jumps straight to the current "New" divider - same manual scrollTo()
// pattern the search-result jump uses (see the jumpLine handling further
// down), not scrollIntoView() (found live 2026-08-09: silently a no-op
// in at least one real headless-Chrome context there). Lands the
// divider near the TOP of the viewport rather than centered - unlike a
// search result (an isolated point of interest), this is the START of a
// run of new content meant to be read downward from here, so centering
// it would waste half the viewport on content already scrolled past.
var JUMP_TO_NEW_TOP_OFFSET_PX = 16;

function jumpToNewContent() {
  if (!currentDivider) {
    return;
  }

  var dividerRect = currentDivider.getBoundingClientRect();
  var pageContentRect = pageContent.getBoundingClientRect();
  var targetScrollTop = pageContent.scrollTop + (dividerRect.top - pageContentRect.top) - JUMP_TO_NEW_TOP_OFFSET_PX;
  pageContent.scrollTo({ top: Math.max(0, targetScrollTop), behavior: 'smooth' });
}

if (jumpToNewBtn) {
  jumpToNewBtn.addEventListener('click', jumpToNewContent);
}

var newEntryObserver = makeSeenFadeObserver('new-content-highlight', NEW_CONTENT_HIGHLIGHT_FADE_MS);

// $beforeNode may be null - a poll cycle that only upgraded an already-
// pending tool-call entry's result (see tailPendingCallState) rather than
// inserting anything brand new at the tail has no natural "new stuff
// starts here" position to anchor a divider to (the entry was already
// there, just its result slot got filled in) - $entryElements (the entry
// itself) still gets highlighted either way, just no divider that poll
// cycle.
function markNewContent(beforeNode, entryElements) {
  var list = document.getElementById('history-list');

  // Skip the divider when every touched entry is currently hidden
  // (subagent-only entries with the "Show subagent calls and outputs"
  // toggle off, see .entry-subagent-only above) - otherwise the "New"
  // landmark points at content that isn't actually visible, with
  // nothing real to see below it until the toggle is flipped on.
  var anyVisible = document.body.classList.contains('show-subagent')
    || entryElements.some(function (el) { return !el.classList.contains('entry-subagent-only'); });
  var dividerCreated = false;

  if (beforeNode && anyVisible) {
    if (currentDivider && currentDivider.parentNode) {
      currentDivider.parentNode.removeChild(currentDivider);
    }

    var divider = document.createElement('div');
    divider.className = 'select-none new-content-divider flex items-center gap-2 my-1 text-xs text-indigo-400';
    divider.innerHTML = '<span class="flex-1 border-t border-indigo-500/50"></span>'
      + '<span>New</span>'
      + '<span class="flex-1 border-t border-indigo-500/50"></span>';
    list.insertBefore(divider, beforeNode);
    currentDivider = divider;
    dividerCreated = true;
    updateJumpToNewVisibility();
  }

  entryElements.forEach(function (el) { el.classList.add('new-content-highlight'); });

  if (!newEntryObserver) {
    return; // no observer support - markers just stay put, harmless
  }

  entryElements.forEach(function (el) { newEntryObserver.observe(el); });

  if (!dividerCreated) {
    return; // no divider this cycle - nothing to fade-and-remove later
  }

  var dividerObserver = new IntersectionObserver(function (observerEntries) {
    observerEntries.forEach(function (observerEntry) {
      if (!observerEntry.isIntersecting) {
        return;
      }

      dividerObserver.disconnect();

      setTimeout(function () {
        if (currentDivider !== divider) {
          return; // already replaced by a newer one, nothing left to fade
        }

        divider.classList.add('fading');
        divider.addEventListener('transitionend', function () {
          if (divider.parentNode) {
            divider.parentNode.removeChild(divider);
          }
        }, { once: true });
        currentDivider = null;
        updateJumpToNewVisibility();
      }, NEW_CONTENT_VISIBLE_DELAY_MS);
    });
  });

  dividerObserver.observe(divider);
}
