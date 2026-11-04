/* Sessioneer v2: every <dialog class="v2-sheet"> closes on a tap outside it or a swipe down from its header.
   Loaded by the dashboard and the session page. Plain ES5 (see CLAUDE.md). */
(function () {
  'use strict';

  document.addEventListener('click', function (e) {
    // The sheet has no padding of its own, so a click whose target is the dialog itself landed on the backdrop.
    if (e.target && e.target.tagName === 'DIALOG' && e.target.classList.contains('v2-sheet')) { e.target.close(); }
  });

  var start = null;
  document.addEventListener('touchstart', function (e) {
    var head = e.target.closest ? e.target.closest('.v2-sheet-head') : null;
    start = head && e.touches.length === 1 ? { dlg: head.closest('dialog'), y: e.touches[0].clientY, x: e.touches[0].clientX } : null;
  }, { passive: true });
  document.addEventListener('touchend', function (e) {
    var s = start;
    start = null;
    if (!s || !s.dlg || !e.changedTouches.length) { return; }
    var dy = e.changedTouches[0].clientY - s.y;
    var dx = e.changedTouches[0].clientX - s.x;
    if (dy >= 60 && dy > Math.abs(dx) * 1.5) { s.dlg.close(); }
  }, { passive: true });
}());
