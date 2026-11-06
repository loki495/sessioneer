// Continue-here / start-a-new-one buttons for an idle headless Claude or Codex session.
// Mounts on any [data-advise-host]; plain ES5.
(function () {
  function esc(s) {
    return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
  }
  function k(n) { return n >= 1000 ? Math.round(n / 1000) + 'k' : String(n); }
  function age(sec) { return sec >= 3600 ? Math.round(sec / 3600) + 'h' : Math.max(1, Math.round(sec / 60)) + 'm'; }

  function post(host, params) {
    var csrfEl = document.querySelector('[data-csrf]');
    var body = new URLSearchParams();
    body.append('csrf_token', csrfEl ? csrfEl.getAttribute('data-csrf') : '');
    Object.keys(params).forEach(function (key) { body.append(key, params[key]); });
    return fetch('/action', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: 'Unexpected response (' + r.status + ')' }; });
    });
  }

  function render(host, res) {
    var panel = host.querySelector('.ui-advise-panel');
    if (!panel) {
      panel = document.createElement('div');
      panel.className = 'ui-advise-panel';
      host.appendChild(panel);
    }
    if (!res || !res.ok || !res.advice) {
      panel.innerHTML = '<p class="ui-small ui-warn">' + esc(res && res.message ? res.message : 'No advice available') + '</p>';
      return;
    }
    var a = res.advice;
    var html = '<p class="ui-small">' + esc(a.reason) + '</p>';
    if (a.verdict === 'unsupported') { panel.innerHTML = html; return; }

    var cold = a.cache_cold;
    var cost = a.cost;
    var meta = 'Context ' + k(a.context_tokens) + ' tokens, cache ' + (cold ? 'cold' : 'warm') + ' (' + age(a.cache_age_seconds) + ')';
    var off = res.status !== 'idle' || a.quota_blocked;
    var note = res.status !== 'idle' ? 'The session is ' + res.status + '.' : (a.quota_blocked ? 'The account quota is nearly full.' : '');
    html += '<p class="ui-muted ui-small">' + esc(meta) + '</p><div class="ui-advise-btns">';
    [
      ['resume', 'Continue here', cost.resume_rewrite_tokens > 0
        ? 'Re-writes ~' + k(cost.resume_rewrite_tokens) + ' tokens now, then re-reads ' + k(cost.resume_reread_per_turn) + ' each turn'
        : 'Cache is warm: no re-write, re-reads ' + k(cost.resume_reread_per_turn) + ' each turn'],
      ['fresh', 'Start a new one', 'Writes a ~' + k(cost.fresh_handoff_tokens) + ' handoff, re-reads ' + k(cost.fresh_reread_per_turn) + ' each turn; archives this one']
    ].forEach(function (b) {
      var rec = a.verdict === b[0];
      html += '<button type="button" class="ui-btn ui-advise-btn' + (rec ? ' ui-btn-primary' : '') + '" data-advise-choice="' + b[0] + '"' + (off ? ' disabled' : '') + '>'
        + '<strong>' + b[1] + (rec ? ' (recommended)' : '') + '</strong><span class="ui-small">' + esc(b[2]) + '</span></button>';
    });
    html += '</div>' + (note ? '<p class="ui-small ui-warn">' + esc(note) + '</p>' : '');
    panel.innerHTML = html;
  }

  document.addEventListener('click', function (e) {
    var open = e.target.closest ? e.target.closest('[data-advise-open]') : null;
    var pick = e.target.closest ? e.target.closest('[data-advise-choice]') : null;
    var host = (open || pick) ? (open || pick).closest('[data-advise-host]') : null;
    if (!host) { return; }
    var session = host.getAttribute('data-session');

    if (open) {
      open.disabled = true;
      post(host, { action: 'advise', session: session }).then(function (res) {
        open.hidden = true;
        render(host, res);
      }).catch(function () { open.disabled = false; render(host, { ok: false, message: 'Network error' }); });
      return;
    }

    var choice = pick.getAttribute('data-advise-choice');
    if (choice === 'fresh' && !window.confirm('Start a new session from a handoff and archive this one?')) { return; }
    Array.prototype.forEach.call(host.querySelectorAll('[data-advise-choice]'), function (b) { b.disabled = true; });
    post(host, { action: 'advise_act', session: session, choice: choice }).then(function (res) {
      if (res && res.ok && res.redirect) { window.location.href = res.redirect; return; }
      var panel = host.querySelector('.ui-advise-panel');
      panel.innerHTML = '<p class="ui-small ' + (res && res.ok ? '' : 'ui-warn') + '">' + esc(res && res.message ? res.message : 'Done') + '</p>';
    }).catch(function () {
      host.querySelector('.ui-advise-panel').innerHTML = '<p class="ui-small ui-warn">Network error</p>';
    });
  });
})();
