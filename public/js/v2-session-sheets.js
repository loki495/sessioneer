/* Sessioneer v2 session page, sheets: footer controls, session details (files,
   tasks, actions, view options), in-session search, the image viewer and the
   full-screen text view. Loaded after v2-session.js, whose V2S.api it extends.
   Plain ES5 (see CLAUDE.md). */
(function () {
  'use strict';

  var api = window.V2S && window.V2S.api;
  if (!api) { return; }

  var esc = api.esc;
  var session = api.session;

  function $(id) { return document.getElementById(id); }
  function closest(el, sel) {
    while (el && el.nodeType === 1) { if (el.matches(sel)) { return el; } el = el.parentNode; }
    return null;
  }
  function option(value, label, selected) {
    return '<option value="' + esc(value) + '"' + (selected ? ' selected' : '') + '>' + esc(label) + '</option>';
  }
  function field(label, id, inner, hint) {
    return '<label class="v2-field">' + esc(label) + '<select id="' + id + '">' + inner + '</select>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>';
  }

  // ======================================================================
  // controls (model / mode / effort), per agent
  // ======================================================================

  var MODEL_KEYS = { 'default': 'Default', sonnet: 'Sonnet', fable: 'Fable', opus: 'Opus', haiku: 'Haiku' };
  var MODES = { manual: 'Manual', 'accept edits': 'Accept Edits', plan: 'Plan', auto: 'Auto' };
  var EFFORTS = { low: 'Low', medium: 'Medium', high: 'High', xhigh: 'Xhigh', max: 'Max' };
  var AGY_MODELS = {
    'gemini-3.7-flash': 'Gemini 3.7 Flash', 'gemini-3.6-flash': 'Gemini 3.6 Flash', 'gemini-3.5-flash': 'Gemini 3.5 Flash',
    'gemini-3.1-pro': 'Gemini 3.1 Pro', 'claude-sonnet-4.6-thinking': 'Claude Sonnet 4.6 (Thinking)',
    'claude-opus-4.6-thinking': 'Claude Opus 4.6 (Thinking)', 'gpt-oss-120b-medium': 'GPT-OSS 120B (Medium)'
  };

  function claudeModelLabel(d, key) {
    var labels = d.model_labels || {};
    return labels[key] || MODEL_KEYS[key] || key;
  }

  function controlsSummary(d) {
    var parts = [];
    if (d.agent === 'claude' || !d.agent) {
      if (d.current_model) { parts.push(claudeModelLabel(d, d.current_model)); }
      if (d.current_mode) { parts.push(MODES[d.current_mode] || d.current_mode); }
      if (d.current_effort) { parts.push(EFFORTS[d.current_effort] || d.current_effort); }
    } else if (d.agent === 'antigravity') {
      parts.push(AGY_MODELS[d.current_antigravity_model] || d.current_antigravity_model || 'Model');
    } else {
      if (d.current_model) { parts.push((d.current_provider ? d.current_provider + '/' : '') + d.current_model); }
      if (d.agent === 'codex' && d.current_effort) { parts.push(d.current_effort); }
    }
    $('v2s-ctrl-summary').textContent = parts.length ? parts.join(' · ') : 'Controls';
  }

  function statusLine(text, bad) {
    var el = $('v2s-ctrl-status');
    if (!el) { return; }
    el.textContent = text || '';
    el.className = 'v2-small ' + (bad ? 'v2-warn' : 'v2-muted');
  }

  // Applies one change; the next poll shows what the session really ended up with.
  function applyChange(url, params, label) {
    statusLine('Applying ' + label + '…');
    return api.post(url, params).then(function (res) {
      statusLine(res && res.ok ? '' : ((res && res.message) || 'Could not change ' + label), !(res && res.ok));
      setTimeout(api.pollNow, 300);
      return res;
    }).catch(function () { statusLine('Network error', true); });
  }

  function fillCatalog(selectId, agent, current, provider) {
    var select = $(selectId);
    api.getJson('/session_list_models.php' + (agent ? '?agent=' + agent : '')).then(function (data) {
      if (!data || !data.ok || !data.models || !data.models.length) { select.innerHTML = option('', 'Models unavailable', true); return; }
      select.innerHTML = data.models.map(function (m) {
        var label = (m.providerID ? m.providerID + '/' : '') + (m.name || m.id);
        var sel = m.id === current && (!provider || m.providerID === provider);
        return '<option value="' + esc(m.id) + '" data-provider="' + esc(m.providerID || '') + '"' + (sel ? ' selected' : '') + '>' + esc(label) + '</option>';
      }).join('');
      if (!current) { select.insertAdjacentHTML('afterbegin', option('', 'Default model', true)); select.selectedIndex = 0; }
      select.disabled = false;
      select._models = data.models;
      if (agent === 'codex') { fillCodexEffort(select, $('v2s-c-effort'), api.detail().current_effort); }
    }).catch(function () { select.innerHTML = option('', 'Models unavailable', true); });
  }

  function fillCodexEffort(modelSelect, effortSelect, current) {
    var m = (modelSelect._models || []).filter(function (x) { return x.id === modelSelect.value; })[0];
    var efforts = m && m.efforts ? m.efforts : [];
    effortSelect.innerHTML = option('', 'Default effort', !current) + efforts.map(function (e) { return option(e, e.charAt(0).toUpperCase() + e.slice(1), e === current); }).join('');
    effortSelect.disabled = efforts.length === 0;
  }

  function buildControls(d) {
    var body = $('v2s-controls-body');
    var agent = d.agent || 'claude';
    var html = '';

    if (agent === 'claude') {
      var model = d.current_model || '';
      html += field('Model', 'v2s-c-model', (model ? '' : option('', 'Model unknown', true))
        + Object.keys(MODEL_KEYS).map(function (k) { return option(k, claudeModelLabel(d, k), k === model); }).join(''));
      var modes = MODES;
      if (d.current_mode && !MODES[d.current_mode]) { modes = {}; Object.keys(MODES).forEach(function (k) { modes[k] = MODES[k]; }); modes[d.current_mode] = d.current_mode; }
      html += field('Permission mode', 'v2s-c-mode', (d.current_mode ? '' : option('', 'Mode unknown', true))
        + Object.keys(modes).map(function (k) { return option(k, modes[k], k === d.current_mode); }).join(''));
      html += field('Reasoning effort', 'v2s-c-effort',
        option('', d.effort_available ? 'Default effort' : 'Unavailable', !d.current_effort)
        + Object.keys(EFFORTS).map(function (k) { return option(k, EFFORTS[k], k === d.current_effort); }).join(''),
        d.effort_message || 'Session-only reasoning effort');
    } else if (agent === 'antigravity') {
      html += field('Model', 'v2s-c-agy', (d.current_antigravity_model ? '' : option('', 'Switch model…', true))
        + Object.keys(AGY_MODELS).map(function (k) { return option(k, AGY_MODELS[k], k === d.current_antigravity_model); }).join(''),
        'Changes the account-wide default model for every Antigravity session.');
    } else if (agent === 'codex') {
      html += field('Model', 'v2s-c-catalog', option('', 'Loading models…', true));
      html += field('Reasoning effort', 'v2s-c-effort', option('', 'Default effort', true));
    } else {
      html += field('Model', 'v2s-c-catalog', option('', 'Loading models…', true));
    }

    body.innerHTML = html + '<p id="v2s-ctrl-status" class="v2-small v2-muted" role="status"></p>';

    if (agent === 'claude') {
      $('v2s-c-model').disabled = !d.current_model;
      $('v2s-c-mode').disabled = !d.current_mode;
      $('v2s-c-effort').disabled = d.effort_available !== true;
    } else if (agent === 'codex') {
      $('v2s-c-catalog').disabled = true;
      $('v2s-c-effort').disabled = true;
      fillCatalog('v2s-c-catalog', 'codex', d.current_model || '', null);
    } else if (agent !== 'antigravity') {
      $('v2s-c-catalog').disabled = true;
      fillCatalog('v2s-c-catalog', agent === 'ollama' ? 'ollama' : '', d.current_model || '', d.current_provider || '');
    }
  }

  $('v2s-controls-body').addEventListener('change', function (e) {
    var id = e.target.id;
    var d = api.detail();
    if (id === 'v2s-c-model') { applyChange('/session_model.php', { session: session, model: e.target.value }, 'model'); }
    else if (id === 'v2s-c-mode') { applyChange('/session_mode.php', { session: session, mode: e.target.value }, 'mode'); }
    else if (id === 'v2s-c-effort' && (d.agent || 'claude') === 'claude') { applyChange('/session_effort.php', { session: session, effort: e.target.value }, 'effort'); }
    else if (id === 'v2s-c-agy') { applyChange('/session_antigravity_model.php', { session: session, model: e.target.value }, 'model'); }
    else if (id === 'v2s-c-catalog' || id === 'v2s-c-effort') {
      var opt = e.target.options[e.target.selectedIndex];
      var model = $('v2s-c-catalog');
      if (id === 'v2s-c-catalog' && d.agent === 'codex') { fillCodexEffort(model, $('v2s-c-effort'), ''); }
      var params = { session: session, model: model.value };
      var chosen = model.options[model.selectedIndex];
      if (chosen && chosen.getAttribute('data-provider')) { params.model_provider = chosen.getAttribute('data-provider'); }
      if (d.agent === 'codex') { params.effort = $('v2s-c-effort').value; }
      if (!params.model) { return; }
      applyChange('/session_model.php', params, 'model');
    }
  });

  // ======================================================================
  // session details sheet
  // ======================================================================

  function kv(label, value, copyable) {
    if (!value) { return ''; }
    return '<div class="v2s-kv"><div><small>' + esc(label) + '</small><code>' + esc(value) + '</code></div>'
      + (copyable ? '<button type="button" class="v2-btn" data-info="copy" data-value="' + esc(value) + '">Copy</button>' : '') + '</div>';
  }

  function resumeCommand(d) {
    if (d.resume_command) { return d.resume_command; }

    return '';
  }

  function buildInfo(d) {
    var body = $('v2s-info-body');
    var html = '';
    html += kv('Session name', d.title || d.name, true);
    html += kv('Session', session, true);
    html += kv('Session ID', d.agent_session_id, true);
    html += kv('Working directory', d.workdir, true);
    html += kv('Resume in a terminal', resumeCommand(d), true);
    html += kv('Branch', d.git_branch, false);
    html += kv('Worktree', d.git_worktree, false);
    html += kv('Account', d.agent === 'claude' ? d.profile : '', false);
    html += kv('Runtime', d.runtime, false);
    var pct = d.context_used_percentage;
    html += kv('Context used', typeof pct === 'number' && isFinite(pct) ? Math.round(pct) + '%' : '', false);

    html += '<div class="v2s-row-actions">'
      + '<button type="button" class="v2-btn" data-info="star">' + (d.starred ? '&#9733; Unstar' : '&#9734; Star') + '</button>'
      + '<button type="button" class="v2-btn" data-info="rename">Rename</button>';
    if ((d.agent || 'claude') === 'claude') {
      var target = d.runtime === 'headless' ? 'tmux' : 'headless';
      html += '<button type="button" class="v2-btn" data-info="switch" data-runtime="' + target + '">Move to ' + (target === 'headless' ? 'headless' : 'terminal') + '</button>';
    }
    html += '<button type="button" class="v2-btn v2-btn-danger" data-info="kill">Kill session</button></div>';
    body.innerHTML = html;

    var todos = d.todos || [];
    $('v2s-tasks-sec').hidden = todos.length === 0;
    $('v2s-tasks').innerHTML = todos.map(function (t) {
      var done = t.status === 'completed';
      var icon = done ? '&#10003;' : (t.status === 'in_progress' ? '&#9679;' : '&#9675;');
      var label = t.status === 'in_progress' ? (t.activeForm || t.content) : t.content;
      return '<li><span style="margin-right:8px" aria-hidden="true">' + icon + '</span><span' + (done ? ' class="v2s-tasks-done"' : '') + '>' + esc(label) + '</span></li>';
    }).join('');
  }

  $('v2s-info-body').addEventListener('click', function (e) {
    var b = closest(e.target, '[data-info]');
    if (!b) { return; }
    var d = api.detail();
    var what = b.getAttribute('data-info');

    if (what === 'copy') { api.copy(b.getAttribute('data-value')); }
    else if (what === 'star') {
      var on = !d.starred;
      api.post('/session_star.php', { session: session, starred: on ? '1' : '0' }).then(function (res) {
        if (!res.ok) { api.toast(res.message || 'Could not star', true); return; }
        d.starred = on; buildInfo(d);
      });
    } else if (what === 'rename') {
      var title = window.prompt('Session name (empty clears it):', d.title || '');
      if (title === null) { return; }
      api.post('/session_rename.php', { session: session, title: title }).then(function (res) {
        api.toast(res.ok ? 'Renamed' : (res.message || 'Rename failed'), !res.ok);
        if (res.ok) { api.pollNow(); }
      });
    } else if (what === 'switch') {
      var target = b.getAttribute('data-runtime');
      if (!window.confirm('Move this session to ' + (target === 'headless' ? 'a headless session (no terminal)' : 'a terminal (tmux) session') + '? The conversation continues; the current process is stopped.')) { return; }
      action({ action: 'switch_runtime', session: session, runtime: target });
    } else if (what === 'kill') {
      if (window.confirm('Kill session ' + session + '?')) { action({ action: 'kill', session: session }, '/v2'); }
    }
  });

  // The JSON twin of the classic dashboard action; a redirect from the server wins over `fallback`.
  function action(params, fallback) {
    api.post('/v2/action', params).then(function (res) {
      if (res.ok && (res.redirect || fallback)) { window.location.href = res.redirect || fallback; return; }
      api.toast(res.message || (res.ok ? 'Done' : 'Failed'), !res.ok);
    }).catch(function () { api.toast('Network error', true); });
  }

  // ---- files: todo, plan/handoff, uploads ----
  function b64text(b64) {
    var bin = window.atob(b64);
    try { return decodeURIComponent(window.escape(bin)); } catch (e) { return bin; }
  }

  $('v2s-todo-file').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    api.getJson('/session_todo_file.php?' + api.qs({ session: session })).then(function (res) {
      btn.disabled = false;
      if (!res.ok) { api.toast(res.message || 'Could not load the todo file', true); return; }
      fullscreen('todo', b64text(res.data));
    }).catch(function () { btn.disabled = false; api.toast('Network error', true); });
  });

  function loadPlanFiles() {
    return api.getJson('/session_plan_files.php?' + api.qs({ session: session })).then(function (res) {
      var el = $('v2s-plan-files');
      if (!res.ok) { el.innerHTML = '<p class="v2-muted v2-small">Could not load plan files.</p>'; return; }
      el.innerHTML = (res.files || []).length
        ? '<h3 class="v2-subtitle">Plan and handoff files</h3>' + res.files.map(function (f) {
          return '<div class="v2s-file-row"><a href="/session_plan_file.php?' + api.qs({ session: session, filename: f.name }) + '" target="_blank" rel="noopener">' + esc(f.name) + '</a><span class="v2-muted v2-small">' + esc(api.fileSize(f.size)) + '</span></div>';
        }).join('')
        : '<p class="v2-muted v2-small">No plan or handoff files found.</p>';
    }).catch(function () {});
  }

  function loadUploads() {
    return api.getJson('/uploaded_files.php?' + api.qs({ session: session })).then(function (res) {
      var files = res && res.ok ? (res.files || []) : [];
      $('v2s-uploads-clear').hidden = files.length === 0;
      $('v2s-upload-total').textContent = files.length ? api.fileSize(res.total_size || 0) : '';
      $('v2s-uploads').innerHTML = files.length ? files.map(function (f) {
        return '<div class="v2s-file-row"><a href="/uploaded_file_view.php?' + api.qs({ session: session, filename: f.name }) + '" target="_blank" rel="noopener">' + esc(f.name) + '</a>'
          + '<span class="v2-row"><span class="v2-muted v2-small">' + esc(api.fileSize(f.size)) + '</span>'
          + '<button type="button" class="v2-icon-btn" data-del="' + esc(f.name) + '" aria-label="Delete ' + esc(f.name) + '">&times;</button></span></div>';
      }).join('') : '<p class="v2-muted v2-small">No uploaded files.</p>';
    }).catch(function () {});
  }
  api.refreshFiles = function () { if ($('v2s-info').open) { loadUploads(); } };

  $('v2s-uploads').addEventListener('click', function (e) {
    var b = closest(e.target, '[data-del]');
    if (!b) { return; }
    b.disabled = true;
    api.post('/delete_uploaded_file.php', { session: session, filename: b.getAttribute('data-del') }).then(function (res) {
      if (!res.ok) { api.toast(res.message || 'Could not delete', true); }
      loadUploads();
    });
  });
  $('v2s-uploads-clear').addEventListener('click', function () {
    if (!window.confirm('Delete all uploaded files for this session?')) { return; }
    api.post('/delete_all_uploaded_files.php', { session: session }).then(function (res) {
      if (!res.ok) { api.toast(res.message || 'Could not delete', true); }
      loadUploads();
    });
  });

  // ---- view options ----
  function applyViewOptions() {
    document.body.classList.toggle('v2s-hide-reasoning', !api.prefs.reasoning);
    document.body.classList.toggle('v2s-hide-subagent', !api.prefs.subagent);
  }
  function syncOptions() {
    $('v2s-opt-reasoning').checked = api.prefs.reasoning;
    $('v2s-opt-subagent').checked = api.prefs.subagent;
    $('v2s-opt-confirm').checked = api.prefs.confirm;
    $('v2s-opt-poll').value = String(api.prefs.pollMs);
  }
  $('v2s-opt-reasoning').addEventListener('change', function () {
    api.prefs.reasoning = this.checked;
    api.save('v2s-reasoning', this.checked ? '1' : '0');
    applyViewOptions();
    // Reasoning is only sent by the host agent when asked for, so the transcript is fetched again.
    api.reload();
  });
  $('v2s-opt-subagent').addEventListener('change', function () {
    api.prefs.subagent = this.checked; api.save('v2s-subagent', this.checked ? '1' : '0'); applyViewOptions();
  });
  $('v2s-opt-confirm').addEventListener('change', function () {
    api.prefs.confirm = this.checked; api.save('v2s-confirm', this.checked ? '1' : '0');
  });
  $('v2s-opt-poll').addEventListener('change', function () {
    api.prefs.pollMs = parseInt(this.value, 10) || 3000; api.save('v2-poll', String(api.prefs.pollMs)); api.schedule();
  });

  // ======================================================================
  // search
  // ======================================================================

  var searchInput = $('v2s-search-input');
  var results = $('v2s-search-results');
  var searchTimer = null;
  var searchAbort = null;

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

  function scope() {
    return document.querySelector('input[name="v2s-scope"]:checked').value;
  }

  function renderHits(data, query) {
    if (!data || !data.ok) { results.innerHTML = '<p class="v2-muted v2-center">' + esc((data && data.message) || 'Search failed') + '</p>'; return; }
    if (scope() === 'session') {
      var matches = data.matches || [];
      results.innerHTML = matches.length ? matches.map(function (m) {
        return '<button type="button" class="v2s-hit" data-line="' + m.line + '"><b>' + esc(m.role === 'user' ? 'You' : 'Agent') + ' · ' + esc(api.relTime(m.timestamp ? new Date(m.timestamp * 1000).toISOString() : '')) + '</b>'
          + '<span class="v2-result-snippet">' + highlight(String(m.snippet || ''), query) + '</span></button>';
      }).join('') : '<p class="v2-muted v2-center">No matches</p>';

      return;
    }
    var rows = data.results || [];
    results.innerHTML = rows.length ? rows.map(function (r) {
      var first = r.matches[0].line;
      var url = r.session_name
        ? '/v2/session?session=' + encodeURIComponent(r.session_name) + '&jump_line=' + encodeURIComponent(first)
        : '/archived_session.php?agent_session_id=' + encodeURIComponent(r.agent_session_id) + '&jump_line=' + encodeURIComponent(first);
      return '<a class="v2s-hit" href="' + url + '"><b>' + esc(r.title) + (r.session_name ? ' · live' : '') + '</b>'
        + r.matches.map(function (m) { return '<span class="v2-result-snippet">' + highlight(String(m.snippet || ''), query) + '</span>'; }).join('') + '</a>';
    }).join('') : '<p class="v2-muted v2-center">No matches</p>';
  }

  function runSearch() {
    var query = searchInput.value.trim();
    clearTimeout(searchTimer);
    if (searchAbort) { searchAbort.abort(); }
    if (!query) { results.innerHTML = ''; return; }
    searchTimer = setTimeout(function () {
      searchAbort = typeof AbortController === 'function' ? new AbortController() : null;
      results.innerHTML = '<p class="v2-muted v2-center">Searching…</p>';
      var url = scope() === 'session' ? '/session_search.php?' + api.qs({ session: session, q: query }) : '/search_sessions.php?' + api.qs({ q: query });
      api.getJson(url, searchAbort ? searchAbort.signal : undefined).then(function (data) { renderHits(data, query); })
        .catch(function (err) { if (!err || err.name !== 'AbortError') { results.innerHTML = '<p class="v2-muted v2-center">Network error</p>'; } });
    }, 300);
  }
  searchInput.addEventListener('input', runSearch);
  Array.prototype.forEach.call(document.querySelectorAll('input[name="v2s-scope"]'), function (r) { r.addEventListener('change', runSearch); });
  results.addEventListener('click', function (e) {
    var hit = closest(e.target, 'button[data-line]');
    if (!hit) { return; }
    $('v2s-search').close();
    api.jumpTo(parseInt(hit.getAttribute('data-line'), 10));
  });

  // ======================================================================
  // viewers
  // ======================================================================

  var viewer = $('v2s-viewer');
  var stage = $('v2s-viewer-stage');
  var vimg = $('v2s-viewer-img');

  // Covers the footer and every sheet; pinching is the browser's own zoom, tapping toggles fit / natural size.
  function viewImage(src) {
    stage.classList.remove('is-full');
    vimg.src = src;
    $('v2s-viewer-open').href = src;
    viewer.hidden = false;
    document.body.style.overflow = 'hidden';
  }
  function closeViewer() {
    viewer.hidden = true;
    vimg.removeAttribute('src');
    document.body.style.overflow = '';
  }
  vimg.addEventListener('click', function () { stage.classList.toggle('is-full'); });
  $('v2s-viewer-close').addEventListener('click', closeViewer);

  var fsText = '';
  function fullscreen(title, text) {
    fsText = text;
    $('v2s-fullscreen-title').textContent = title || '';
    $('v2s-fullscreen-text').textContent = text;
    $('v2s-fullscreen').hidden = false;
  }
  $('v2s-fullscreen-close').addEventListener('click', function () { $('v2s-fullscreen').hidden = true; });
  $('v2s-fullscreen-copy').addEventListener('click', function () { api.copy(fsText); });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    if (!viewer.hidden) { closeViewer(); } else if (!$('v2s-fullscreen').hidden) { $('v2s-fullscreen').hidden = true; }
  });

  // ======================================================================
  // sessions popup (replaces the classic sidebar list) and usage popup
  // ======================================================================

  var sessionRows = [];
  function statusOfRow(x) { return x.blocked_reason ? 'blocked' : (x.working ? 'working' : 'idle'); }
  function renderSessionRows() {
    var q = $('v2s-sessions-filter').value.trim().toLowerCase();
    var order = { blocked: 0, working: 1, idle: 2 };
    var rows = sessionRows.filter(function (x) {
      return !q || (String(x.title || '') + ' ' + x.name + ' ' + String(x.workdir || '')).toLowerCase().indexOf(q) !== -1;
    }).sort(function (a, b) {
      return (order[statusOfRow(a)] - order[statusOfRow(b)]) || ((b.starred ? 1 : 0) - (a.starred ? 1 : 0)) || ((b.activity || 0) - (a.activity || 0));
    });
    $('v2s-sessions-list').innerHTML = rows.length ? rows.map(function (x) {
      var st = statusOfRow(x);
      var folder = String(x.workdir || '').replace(/\/+$/, '').split('/').pop();
      return '<div class="v2s-srow' + (x.name === session ? ' is-current' : '') + (x.starred ? ' is-starred' : '') + '">'
        + '<a class="v2s-srow-main" href="/v2/session?session=' + encodeURIComponent(x.name) + '"><i class="v2-dot v2-dot-' + st + '" role="img" aria-label="' + (st === 'blocked' ? 'needs input' : st) + '"></i>'
        + '<span class="v2s-srow-text"><b>' + esc(x.title || x.name) + '</b><small>' + esc(folder) + '</small></span></a>'
        + '<button type="button" class="v2-icon-btn" data-star="' + esc(x.name) + '" aria-pressed="' + (x.starred ? 'true' : 'false') + '" aria-label="' + (x.starred ? 'Unstar' : 'Star') + '">' + (x.starred ? '&#9733;' : '&#9734;') + '</button></div>';
    }).join('') : '<p class="v2-muted v2-center">No sessions</p>';
  }
  function loadSessions() {
    $('v2s-sessions-list').innerHTML = '<p class="v2-muted v2-center">Loading…</p>';
    api.getJson('/sessions_list.php').then(function (data) {
      if (!data || !data.ok) { $('v2s-sessions-list').innerHTML = '<p class="v2-muted v2-center">' + esc((data && data.message) || 'Could not load sessions') + '</p>'; return; }
      sessionRows = data.sessions || [];
      renderSessionRows();
    }).catch(function () { $('v2s-sessions-list').innerHTML = '<p class="v2-muted v2-center">Network error</p>'; });
  }
  $('v2s-sessions-filter').addEventListener('input', renderSessionRows);
  $('v2s-sessions-list').addEventListener('click', function (e) {
    var b = closest(e.target, '[data-star]');
    if (!b) { return; }
    var name = b.getAttribute('data-star');
    var row = sessionRows.filter(function (x) { return x.name === name; })[0];
    if (!row) { return; }
    var on = !row.starred;
    api.post('/session_star.php', { session: name, starred: on ? '1' : '0' }).then(function (res) {
      if (!res || !res.ok) { api.toast((res && res.message) || 'Could not star', true); return; }
      row.starred = on;
      if (name === session) { api.detail().starred = on; }
      renderSessionRows();
    }).catch(function () { api.toast('Network error', true); });
  });

  function loadQuota() {
    var box = $('v2s-quota-body');
    box.innerHTML = '<p class="v2-muted v2-center">Loading…</p>';
    api.getJson('/quota.php?' + api.qs({ session: session })).then(function (data) {
      var d = api.detail();
      var pct = data && data.context && typeof data.context.pct === 'number' ? data.context.pct : d.context_used_percentage;
      var ctx = typeof pct === 'number' && isFinite(pct) ? '<p class="v2s-ctx">This session has used <b>' + Math.round(pct) + '%</b> of its context.</p>' : '';
      box.innerHTML = ctx + window.V2Quota.render(data, { first: d.agent || 'claude' });
    }).catch(function () { box.innerHTML = '<p class="v2-muted v2-center">Could not load usage.</p>'; });
  }

  // ======================================================================
  // opening sheets
  // ======================================================================

  function openSheet(id) {
    var dlg = $(id);
    if (id === 'v2s-controls') { buildControls(api.detail()); }
    if (id === 'v2s-info') { buildInfo(api.detail()); syncOptions(); loadPlanFiles(); loadUploads(); }
    if (id === 'v2s-sessions') { $('v2s-sessions-filter').value = ''; loadSessions(); }
    if (id === 'v2s-quota') { loadQuota(); }
    if (id === 'v2s-search') { setTimeout(function () { searchInput.focus(); }, 50); }
    if (!dlg.open) { dlg.showModal(); }
  }

  api.controlsSummary = controlsSummary;
  api.openSheet = openSheet;
  api.fullscreen = fullscreen;
  api.viewImage = viewImage;
  api.onDetail = function () {};

  controlsSummary(api.detail());
}());
