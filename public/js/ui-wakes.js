/* Sessioneer wake-up rows, shared by the dashboard fold and the session info sheet.
   The server renders the rows (UiView::wakes_html); this wires their times, "Show full prompt"
   and Cancel. Plain ES5 (see CLAUDE.md). */
(function () {
  'use strict';

  function closest(el, sel) {
    while (el && el.nodeType === 1) { if (el.matches(sel)) { return el; } el = el.parentNode; }
    return null;
  }

  // The tooltip keeps the host's local time; the text is the viewer's.
  function localize(root) {
    var times = root.querySelectorAll('time[data-at]');
    for (var i = 0; i < times.length; i++) {
      var d = new Date(Number(times[i].getAttribute('data-at')) * 1000);
      times[i].textContent = d.toLocaleString([], { dateStyle: 'medium', timeStyle: 'short' });
    }
  }

  // opts: toast(message, bad), getJson(url), post(url, params), refresh() to reload the list.
  function attach(root, opts) {
    root.addEventListener('click', function (e) {
      var show = closest(e.target, '[data-wake-show]');
      var cancel = closest(e.target, '[data-wake-cancel]');
      var row = closest(e.target, '.ui-wake');
      if (!row || (!show && !cancel)) { return; }

      if (show) {
        var pre = row.querySelector('pre.ui-wake-full');
        if (pre.getAttribute('data-loaded')) {
          pre.hidden = !pre.hidden;
          show.textContent = pre.hidden ? 'Show full prompt' : 'Hide full prompt';
          return;
        }
        opts.getJson('/wakes?job=' + encodeURIComponent(show.getAttribute('data-wake-show'))).then(function (res) {
          if (!res || !res.ok) { opts.toast((res && res.message) || 'Could not load the prompt', true); return; }
          pre.textContent = res.prompt;
          pre.setAttribute('data-loaded', '1');
          pre.hidden = false;
          show.textContent = 'Hide full prompt';
        }).catch(function () { opts.toast('Network error', true); });
        return;
      }

      var when = row.querySelector('time[data-at]').textContent;
      var preview = row.querySelector('.ui-wake-preview').textContent.slice(0, 80);
      if (!window.confirm('Cancel the wake-up at ' + when + ' that sends "' + preview + '"?')) { return; }
      cancel.disabled = true;
      opts.post('/action', { action: 'wake_cancel', job: cancel.getAttribute('data-wake-cancel') }).then(function (res) {
        opts.toast(res.message || (res.ok ? 'Cancelled' : 'Failed'), !res.ok);
        opts.refresh();
      }).catch(function () { cancel.disabled = false; opts.toast('Network error', true); });
    });
  }

  window.UiWakes = { attach: attach, localize: localize };
}());
