/* Sessioneer: every <dialog class="ui-sheet"> closes on a tap outside it, a swipe down from its header, or a swipe right anywhere inside it
   (except over a field, code block or anything that scrolls sideways, and the outer edge strips the browser keeps for its own gestures).
   Loaded by the dashboard and the session page. Plain ES5 (see CLAUDE.md). */
(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    // The sheet has no padding of its own, so a click whose target is the dialog itself landed on the backdrop.
    if (e.target && e.target.tagName === 'DIALOG' && e.target.classList.contains('ui-sheet')) { e.target.close(); }
  });

  var start = null, EDGE_PX = 24, SWIPE_PX = 80;
  // A finger on a field, code block or sideways-scrolling area is selecting or scrolling, not swiping.
  function scrollsSideways(el) {
    for (; el && el.nodeType === 1 && el !== document.body; el = el.parentNode) {
      if (el.matches('input, textarea, select, pre, code')) { return true; }
      if (el.scrollWidth > el.clientWidth + 1) {
        var ox = window.getComputedStyle(el).overflowX;
        if (ox === 'auto' || ox === 'scroll') { return true; }
      }
    }
    return false;
  }
  document.addEventListener('touchstart', function (e) {
    var t = e.touches[0];
    var dlg = e.target.closest ? e.target.closest('dialog.ui-sheet') : null;
    start = dlg && e.touches.length === 1
      ? { dlg: dlg, head: !!e.target.closest('.ui-sheet-head'), y: t.clientY, x: t.clientX,
          edge: t.clientX <= EDGE_PX || t.clientX >= window.innerWidth - EDGE_PX || scrollsSideways(e.target) }
      : null;
  }, { passive: true });
  document.addEventListener('touchend', function (e) {
    var s = start;
    start = null;
    if (!s || !e.changedTouches.length) { return; }
    var dy = e.changedTouches[0].clientY - s.y;
    var dx = e.changedTouches[0].clientX - s.x;
    if (s.head && dy >= 60 && dy > Math.abs(dx) * 1.5) { s.dlg.close(); }
    else if (!s.edge && dx >= SWIPE_PX && Math.abs(dy) <= dx / 2) { s.dlg.close(); }
  }, { passive: true });
}());
