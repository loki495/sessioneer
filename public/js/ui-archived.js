/* Sessioneer archived-session page: the read-only extras on top of ui-session.js (details and
   search sheets, Resume). Plain ES5 on purpose (see CLAUDE.md). */
(function () {
  'use strict';

  var boot = window.UiSession;
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
    if (name === 'sess-search') { $('sess-search-input').focus(); }
  };
  // Tapping an image opens the original: this page has no viewer layer.
  api.viewImage = function (url) { window.open(url, '_blank', 'noopener'); };

  // The transcript has no live tail, and a "last message" stop needs the live reader.
  $('sess-load-until-user').hidden = true;
  $('sess-bottom').querySelector('.sess-newdot').hidden = true;

  function resume() {
    var params = { action: 'resume', agent_session_id: id, workdir: detail.cwd };
    if (detail.profile) { params.profile = detail.profile; }
    api.post('/action', params).then(function (res) {
      if (res && res.ok && res.redirect) { window.location.href = res.redirect; return; }
      api.toast((res && res.message) || 'Could not resume', true);
    }).catch(function () { api.toast('Network error', true); });
  }

  // ---- footer: the conversation is dormant, so the way forward is Resume ----
  var note = $('sess-compose-note');
  note.hidden = false;
  note.innerHTML = '<span>Archived, read only.</span> ' + (canResume ? '<button type="button" class="ui-btn ui-btn-primary" id="sess-resume">Resume</button>' : '');
  if (canResume) { $('sess-resume').addEventListener('click', resume); }

  // ---- details sheet ----
  var rows = [['Agent', detail.agent_label || detail.agent || 'claude'], ['Folder', detail.cwd], ['Conversation id', detail.agent_session_id || id]];
  if (detail.profile) { rows.push(['Account', detail.profile]); }
  $('sess-info-body').innerHTML = rows.filter(function (r) { return r[1]; }).map(function (r) {
    return '<div class="ui-kv"><span class="ui-muted">' + esc(r[0]) + '</span><span class="ui-path">' + esc(r[1]) + '</span></div>';
  }).join('');
  $('sess-info-actions').innerHTML = canResume
    ? '<button type="button" class="ui-btn ui-btn-primary" id="sess-info-resume">Resume (headless)</button>'
    : '<p class="ui-muted ui-small">This agent cannot be resumed from here.</p>';
  if (canResume) { $('sess-info-resume').addEventListener('click', resume); }

  // ---- search ----
  var input = $('sess-search-input');
  var results = $('sess-search-results');
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

  function scope() { return document.querySelector('input[name="sess-scope"]:checked').value; }

  function render(data, query) {
    if (!data || !data.ok) { results.innerHTML = '<p class="ui-muted ui-center">' + esc((data && data.message) || 'Search failed') + '</p>'; return; }
    if (scope() === 'session') {
      var matches = data.matches || [];
      results.innerHTML = matches.length ? matches.map(function (m) {
        return '<button type="button" class="sess-hit" data-line="' + m.line + '"><b>' + esc(m.role === 'user' ? 'You' : 'Agent') + '</b>'
          + '<span class="ui-result-snippet">' + highlight(String(m.snippet || ''), query) + '</span></button>';
      }).join('') : '<p class="ui-muted ui-center">No matches</p>';

      return;
    }
    var rowsOut = data.results || [];
    // One link per match: each snippet opens the transcript at its own line, not the session's first hit.
    var linksOut = [];
    rowsOut.forEach(function (r) {
      r.matches.forEach(function (m) {
        var url = r.session_name
          ? '/session?session=' + encodeURIComponent(r.session_name) + '&jump_line=' + encodeURIComponent(m.line)
          : '/archived-session?agent_session_id=' + encodeURIComponent(r.agent_session_id) + '&jump_line=' + encodeURIComponent(m.line);
        linksOut.push('<a class="sess-hit" href="' + url + '"><b>' + esc(r.title) + (r.session_name ? ' · live' : '') + '</b>'
          + '<span class="ui-result-snippet">' + highlight(String(m.snippet || ''), query) + '</span></a>');
      });
    });
    results.innerHTML = linksOut.length ? linksOut.join('') : '<p class="ui-muted ui-center">No matches</p>';
  }

  function run() {
    var query = input.value.trim();
    clearTimeout(timer);
    if (aborter) { aborter.abort(); }
    if (!query) { results.innerHTML = ''; return; }
    timer = setTimeout(function () {
      aborter = typeof AbortController === 'function' ? new AbortController() : null;
      results.innerHTML = '<p class="ui-muted ui-center">Searching…</p>';
      var url = scope() === 'session' ? '/archived_session_search.php?' + api.qs({ agent_session_id: id, q: query }) : '/search_sessions.php?' + api.qs({ q: query });
      api.getJson(url, aborter ? aborter.signal : undefined).then(function (data) { render(data, query); })
        .catch(function (err) { if (!err || err.name !== 'AbortError') { results.innerHTML = '<p class="ui-muted ui-center">Network error</p>'; } });
    }, 300);
  }
  input.addEventListener('input', run);
  Array.prototype.forEach.call(document.querySelectorAll('input[name="sess-scope"]'), function (r) { r.addEventListener('change', run); });

  results.addEventListener('click', function (e) {
    var hit = e.target.closest ? e.target.closest('button.sess-hit') : null;
    if (!hit) { return; }
    $('sess-search').close();
    api.jumpTo(parseInt(hit.getAttribute('data-line'), 10));
  });
}());
