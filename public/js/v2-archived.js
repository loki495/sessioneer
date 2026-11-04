/* Sessioneer v2 archived-session page: the read-only extras on top of v2-session.js (details and
   search sheets, Resume). Plain ES5 on purpose (see CLAUDE.md). */
(function () {
  'use strict';

  var boot = window.V2S;
  var api = boot && boot.api;
  if (!api || !boot.archived) { return; }

  var esc = api.esc;
  var detail = api.detail();
  var id = api.session;
  var canResume = !!detail.cwd && detail.agent !== 'antigravity';

  function $(x) { return document.getElementById(x); }

  api.openSheet = function (name) {
    var dlg = $(name);
    if (dlg && !dlg.open) { dlg.showModal(); }
    if (name === 'v2s-search') { $('v2s-search-input').focus(); }
  };
  // Tapping an image opens the original: this page has no viewer layer.
  api.viewImage = function (url) { window.open(url, '_blank', 'noopener'); };

  // The transcript has no live tail, and a "last message" stop needs the live reader.
  $('v2s-load-until-user').hidden = true;
  $('v2s-bottom').querySelector('.v2s-newdot').hidden = true;

  function resume() {
    var params = { action: 'resume', agent_session_id: id, workdir: detail.cwd };
    if (detail.profile) { params.profile = detail.profile; }
    api.post('/v2/action', params).then(function (res) {
      if (res && res.ok && res.redirect) { window.location.href = res.redirect; return; }
      api.toast((res && res.message) || 'Could not resume', true);
    }).catch(function () { api.toast('Network error', true); });
  }

  // ---- footer: the conversation is dormant, so the way forward is Resume ----
  var note = $('v2s-compose-note');
  note.hidden = false;
  note.innerHTML = '<span>Archived, read only.</span> ' + (canResume ? '<button type="button" class="v2-btn v2-btn-primary" id="v2s-resume">Resume</button>' : '');
  if (canResume) { $('v2s-resume').addEventListener('click', resume); }

  // ---- details sheet ----
  var rows = [['Agent', detail.agent_label || detail.agent || 'claude'], ['Folder', detail.cwd], ['Conversation id', detail.agent_session_id || id]];
  if (detail.profile) { rows.push(['Account', detail.profile]); }
  $('v2s-info-body').innerHTML = rows.filter(function (r) { return r[1]; }).map(function (r) {
    return '<div class="v2-kv"><span class="v2-muted">' + esc(r[0]) + '</span><span class="v2-path">' + esc(r[1]) + '</span></div>';
  }).join('');
  $('v2s-info-actions').innerHTML = canResume
    ? '<button type="button" class="v2-btn v2-btn-primary" id="v2s-info-resume">Resume (headless)</button>'
    : '<p class="v2-muted v2-small">This agent cannot be resumed from here.</p>';
  if (canResume) { $('v2s-info-resume').addEventListener('click', resume); }

  // ---- search ----
  var input = $('v2s-search-input');
  var results = $('v2s-search-results');
  var timer = null;
  var aborter = null;

  function highlight(snippet, query) {
    var lower = snippet.toLowerCase();
    var q = query.toLowerCase();
    var out = '';
    var i = 0;
    var at;
    while (q && (at = lower.indexOf(q, i)) !== -1) {
      out += esc(snippet.slice(i, at)) + '<mark>' + esc(snippet.slice(at, at + q.length)) + '</mark>';
      i = at + q.length;
    }

    return out + esc(snippet.slice(i));
  }

  function scope() { return document.querySelector('input[name="v2s-scope"]:checked').value; }

  function render(data, query) {
    if (!data || !data.ok) { results.innerHTML = '<p class="v2-muted v2-center">' + esc((data && data.message) || 'Search failed') + '</p>'; return; }
    if (scope() === 'session') {
      var matches = data.matches || [];
      results.innerHTML = matches.length ? matches.map(function (m) {
        return '<button type="button" class="v2s-hit" data-line="' + m.line + '"><b>' + esc(m.role === 'user' ? 'You' : 'Agent') + '</b>'
          + '<span class="v2-result-snippet">' + highlight(String(m.snippet || ''), query) + '</span></button>';
      }).join('') : '<p class="v2-muted v2-center">No matches</p>';

      return;
    }
    var rowsOut = data.results || [];
    results.innerHTML = rowsOut.length ? rowsOut.map(function (r) {
      var first = r.matches[0].line;
      var url = r.session_name
        ? '/v2/session?session=' + encodeURIComponent(r.session_name) + '&jump_line=' + encodeURIComponent(first)
        : '/v2/archived-session?agent_session_id=' + encodeURIComponent(r.agent_session_id) + '&jump_line=' + encodeURIComponent(first);

      return '<a class="v2s-hit" href="' + url + '"><b>' + esc(r.title) + (r.session_name ? ' · live' : '') + '</b>'
        + r.matches.map(function (m) { return '<span class="v2-result-snippet">' + highlight(String(m.snippet || ''), query) + '</span>'; }).join('') + '</a>';
    }).join('') : '<p class="v2-muted v2-center">No matches</p>';
  }

  function run() {
    var query = input.value.trim();
    clearTimeout(timer);
    if (aborter) { aborter.abort(); }
    if (!query) { results.innerHTML = ''; return; }
    timer = setTimeout(function () {
      aborter = typeof AbortController === 'function' ? new AbortController() : null;
      results.innerHTML = '<p class="v2-muted v2-center">Searching…</p>';
      var url = scope() === 'session' ? '/archived_session_search.php?' + api.qs({ agent_session_id: id, q: query }) : '/search_sessions.php?' + api.qs({ q: query });
      api.getJson(url, aborter ? aborter.signal : undefined).then(function (data) { render(data, query); })
        .catch(function (err) { if (!err || err.name !== 'AbortError') { results.innerHTML = '<p class="v2-muted v2-center">Network error</p>'; } });
    }, 300);
  }
  input.addEventListener('input', run);
  Array.prototype.forEach.call(document.querySelectorAll('input[name="v2s-scope"]'), function (r) { r.addEventListener('change', run); });

  results.addEventListener('click', function (e) {
    var hit = e.target.closest ? e.target.closest('button.v2s-hit') : null;
    if (!hit) { return; }
    $('v2s-search').close();
    api.jumpTo(parseInt(hit.getAttribute('data-line'), 10));
  });
}());
