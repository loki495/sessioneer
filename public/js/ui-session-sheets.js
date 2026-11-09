/* Sessioneer session page, sheets: footer controls, session details (files,
   tasks, actions, view options), in-session search, the image viewer and the
   full-screen text view. Loaded after ui-session.js, whose UiSession.api it extends.
   Plain ES5 (see CLAUDE.md). */
(function () {
  'use strict';

  var api = window.UiSession && window.UiSession.api;
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
    return '<label class="ui-field">' + esc(label) + '<select id="' + id + '">' + inner + '</select>' + (hint ? '<small>' + esc(hint) + '</small>' : '') + '</label>';
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
    $('sess-ctrl-summary').textContent = parts.length ? parts.join(' · ') : 'Controls';
  }

  function statusLine(text, bad) {
    var el = $('sess-ctrl-status');
    if (!el) { return; }
    el.textContent = text || '';
    el.className = 'ui-small ' + (bad ? 'ui-warn' : 'ui-muted');
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
      if (agent === 'codex') { fillCodexEffort(select, $('sess-c-effort'), api.detail().current_effort); }
    }).catch(function () { select.innerHTML = option('', 'Models unavailable', true); });
  }

  function fillCodexEffort(modelSelect, effortSelect, current) {
    var m = (modelSelect._models || []).filter(function (x) { return x.id === modelSelect.value; })[0];
    var efforts = m && m.efforts ? m.efforts : [];
    effortSelect.innerHTML = option('', 'Default effort', !current) + efforts.map(function (e) { return option(e, e.charAt(0).toUpperCase() + e.slice(1), e === current); }).join('');
    effortSelect.disabled = efforts.length === 0;
  }

  function buildControls(d) {
    var body = $('sess-controls-body');
    var agent = d.agent || 'claude';
    var html = '';

    if (agent === 'claude') {
      var model = d.current_model || '';
      html += field('Model', 'sess-c-model', (model ? '' : option('', 'Model unknown', true))
        + Object.keys(MODEL_KEYS).map(function (k) { return option(k, claudeModelLabel(d, k), k === model); }).join(''));
      var modes = MODES;
      if (d.current_mode && !MODES[d.current_mode]) { modes = {}; Object.keys(MODES).forEach(function (k) { modes[k] = MODES[k]; }); modes[d.current_mode] = d.current_mode; }
      html += field('Permission mode', 'sess-c-mode', (d.current_mode ? '' : option('', 'Mode unknown', true))
        + Object.keys(modes).map(function (k) { return option(k, modes[k], k === d.current_mode); }).join(''));
      html += field('Reasoning effort', 'sess-c-effort',
        option('', d.effort_available ? 'Default effort' : 'Unavailable', !d.current_effort)
        + Object.keys(EFFORTS).map(function (k) { return option(k, EFFORTS[k], k === d.current_effort); }).join(''),
        d.effort_message || 'Session-only reasoning effort');
    } else if (agent === 'antigravity') {
      html += field('Model', 'sess-c-agy', (d.current_antigravity_model ? '' : option('', 'Switch model…', true))
        + Object.keys(AGY_MODELS).map(function (k) { return option(k, AGY_MODELS[k], k === d.current_antigravity_model); }).join(''),
        'Changes the account-wide default model for every Antigravity session.');
    } else if (agent === 'codex') {
      html += field('Model', 'sess-c-catalog', option('', 'Loading models…', true));
      html += field('Reasoning effort', 'sess-c-effort', option('', 'Default effort', true));
    } else {
      html += field('Model', 'sess-c-catalog', option('', 'Loading models…', true));
    }

    body.innerHTML = html + '<p id="sess-ctrl-status" class="ui-small ui-muted" role="status"></p>';

    if (agent === 'claude') {
      $('sess-c-model').disabled = !d.current_model;
      $('sess-c-mode').disabled = !d.current_mode;
      $('sess-c-effort').disabled = d.effort_available !== true;
    } else if (agent === 'codex') {
      $('sess-c-catalog').disabled = true;
      $('sess-c-effort').disabled = true;
      fillCatalog('sess-c-catalog', 'codex', d.current_model || '', null);
    } else if (agent !== 'antigravity') {
      $('sess-c-catalog').disabled = true;
      fillCatalog('sess-c-catalog', agent === 'ollama' ? 'ollama' : '', d.current_model || '', d.current_provider || '');
    }
  }

  $('sess-controls-body').addEventListener('change', function (e) {
    var id = e.target.id;
    var d = api.detail();
    if (id === 'sess-c-model') { applyChange('/session_model.php', { session: session, model: e.target.value }, 'model'); }
    else if (id === 'sess-c-mode') { applyChange('/session_mode.php', { session: session, mode: e.target.value }, 'mode'); }
    else if (id === 'sess-c-effort' && (d.agent || 'claude') === 'claude') { applyChange('/session_effort.php', { session: session, effort: e.target.value }, 'effort'); }
    else if (id === 'sess-c-agy') { applyChange('/session_antigravity_model.php', { session: session, model: e.target.value }, 'model'); }
    else if (id === 'sess-c-catalog' || id === 'sess-c-effort') {
      var opt = e.target.options[e.target.selectedIndex];
      var model = $('sess-c-catalog');
      if (id === 'sess-c-catalog' && d.agent === 'codex') { fillCodexEffort(model, $('sess-c-effort'), ''); }
      var params = { session: session, model: model.value };
      var chosen = model.options[model.selectedIndex];
      if (chosen && chosen.getAttribute('data-provider')) { params.model_provider = chosen.getAttribute('data-provider'); }
      if (d.agent === 'codex') { params.effort = $('sess-c-effort').value; }
      if (!params.model) { return; }
      applyChange('/session_model.php', params, 'model');
    }
  });

  // ======================================================================
  // session details sheet
  // ======================================================================

  function kv(label, value, copyable) {
    if (!value) { return ''; }
    return '<div class="sess-kv"><div><small>' + esc(label) + '</small><code>' + esc(value) + '</code></div>'
      + (copyable ? '<button type="button" class="ui-btn" data-info="copy" data-value="' + esc(value) + '">Copy</button>' : '') + '</div>';
  }

  function resumeCommand(d) {
    if (d.resume_command) { return d.resume_command; }

    return '';
  }

  function buildInfo(d) {
    var body = $('sess-info-body');
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

    body.innerHTML = html;

    $('sess-info-head-actions').innerHTML = '<button type="button" class="ui-icon-btn" data-info="sessions" aria-label="Sessions">&#9776;</button>'
      + '<button type="button" class="ui-icon-btn" data-info="star" aria-pressed="' + (d.starred ? 'true' : 'false') + '" aria-label="' + (d.starred ? 'Unstar' : 'Star') + '">' + (d.starred ? '&#9733;' : '&#9734;') + '</button>'
      + '<button type="button" class="ui-btn" data-info="rename">Rename</button>'
      + '<button type="button" class="ui-btn ui-btn-danger" data-info="kill">Kill</button>';

    var actions = '';
    if (d.agent_session_id) {
      actions += '<button type="button" class="ui-btn" data-info="takeover-new">Take over in new session</button>';
    }
    if ((d.agent || 'claude') === 'claude' && d.runtime !== 'headless') {
      actions += '<button type="button" class="ui-btn" data-info="switch" data-runtime="headless">Take over (headless)</button>';
    }
    $('sess-info-actions').innerHTML = actions;

    var todos = d.todos || [];
    $('sess-tasks-sec').hidden = todos.length === 0;
    $('sess-tasks').innerHTML = todos.map(function (t) {
      var done = t.status === 'completed';
      var icon = done ? '&#10003;' : (t.status === 'in_progress' ? '&#9679;' : '&#9675;');
      var label = t.status === 'in_progress' ? (t.activeForm || t.content) : t.content;
      return '<li><span style="margin-right:8px" aria-hidden="true">' + icon + '</span><span' + (done ? ' class="sess-tasks-done"' : '') + '>' + esc(label) + '</span></li>';
    }).join('');
  }

  $('sess-info').addEventListener('click', function (e) {
    var b = closest(e.target, '[data-info]');
    if (!b) { return; }
    var d = api.detail();
    var what = b.getAttribute('data-info');

    if (what === 'copy') { api.copy(b.getAttribute('data-value')); }
    else if (what === 'sessions') { $('sess-info').close(); openSheet('sess-sessions'); }
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
    } else if (what === 'takeover-new') {
      takeOverInNewSession(d, b);
    } else if (what === 'switch') {
      if (!window.confirm('Take this session over as a headless one? The conversation continues; the terminal process is stopped.')) { return; }
      action({ action: 'switch_runtime', session: session, runtime: 'headless' });
    } else if (what === 'kill') {
      if (window.confirm('Kill session ' + session + '?')) { action({ action: 'kill', session: session }, '/'); }
    }
  });

  // Opens the dashboard's New Session form prefilled to continue this conversation. Nothing is launched here:
  // the advisor (no model call) only picks which agent and account have room, else the session's own are used.
  function takeOverInNewSession(d, btn) {
    var agent = d.agent || 'claude';
    var profile = agent === 'claude' && d.profile ? String(d.profile) : '';
    var source = agent + (profile && profile.toLowerCase() !== 'personal' ? ' ' + profile : '') + ' ' + d.agent_session_id;
    btn.disabled = true;
    function go(receiver) {
      var target = receiver && receiver.agent ? receiver.agent : agent;
      var params = { agent: target, workdir: d.workdir || '' };
      var targetProfile = receiver && receiver.agent ? (receiver.profile || '') : profile;
      if (target === 'claude' && targetProfile) { params.profile = targetProfile; }
      // Same agent and account (it has room, or nothing else does): keep the session's own model too.
      var model = d.current_model || d.current_antigravity_model || '';
      if (model && target === agent && String(targetProfile).toLowerCase() === profile.toLowerCase()) { params.model = model; }
      params.prompt = target === 'claude' ? '/takeover ' + source : 'Use the global takeover skill for source ' + source + '.';
      try { window.sessionStorage.setItem('ui-new-prefill', JSON.stringify(params)); } catch (e) { api.toast('Could not open the form', true); btn.disabled = false; return; }
      window.location.href = '/';
    }
    api.post('/action', { action: 'advise', session: session, no_model: '1' }).then(function (res) {
      var a = res && res.ok && res.advice ? res.advice : null;
      go(a && a.receiver ? a.receiver : null);
    }).catch(function () { go(null); });
  }

  // The JSON twin of the classic dashboard action; a redirect from the server wins over `fallback`.
  function action(params, fallback) {
    api.post('/action', params).then(function (res) {
      if (res.ok && (res.redirect || fallback)) { window.location.href = res.redirect || fallback; return; }
      api.toast(res.message || (res.ok ? 'Done' : 'Failed'), !res.ok);
    }).catch(function () { api.toast('Network error', true); });
  }

  // ---- files: todo, plan/handoff, uploads ----
  function b64text(b64) {
    var bin = window.atob(b64);
    try { return decodeURIComponent(window.escape(bin)); } catch (e) { return bin; }
  }

  $('sess-todo-file').addEventListener('click', function () {
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
      var el = $('sess-plan-files');
      if (!res.ok) { el.innerHTML = '<p class="ui-muted ui-small">Could not load plan files.</p>'; return; }
      el.innerHTML = (res.files || []).length
        ? '<h3 class="ui-subtitle">Plan and handoff files</h3>' + res.files.map(function (f) {
          return '<div class="sess-file-row"><a href="/session_plan_file.php?' + api.qs({ session: session, filename: f.name }) + '" target="_blank" rel="noopener">' + esc(f.name) + '</a><span class="ui-muted ui-small">' + esc(api.fileSize(f.size)) + '</span></div>';
        }).join('')
        : '<p class="ui-muted ui-small">No plan or handoff files found.</p>';
    }).catch(function () {});
  }

  function loadUploads() {
    return api.getJson('/uploaded_files.php?' + api.qs({ session: session })).then(function (res) {
      var files = res && res.ok ? (res.files || []) : [];
      $('sess-uploads-clear').hidden = files.length === 0;
      $('sess-upload-total').textContent = files.length ? api.fileSize(res.total_size || 0) : '';
      $('sess-uploads').innerHTML = files.length ? files.map(function (f) {
        return '<div class="sess-file-row"><a href="/uploaded_file_view.php?' + api.qs({ session: session, filename: f.name }) + '" target="_blank" rel="noopener">' + esc(f.name) + '</a>'
          + '<span class="ui-row"><span class="ui-muted ui-small">' + esc(api.fileSize(f.size)) + '</span>'
          + '<button type="button" class="ui-icon-btn" data-del="' + esc(f.name) + '" aria-label="Delete ' + esc(f.name) + '">&times;</button></span></div>';
      }).join('') : '<p class="ui-muted ui-small">No uploaded files.</p>';
    }).catch(function () {});
  }
  api.refreshFiles = function () { if ($('sess-info').open) { loadUploads(); } };

  $('sess-uploads').addEventListener('click', function (e) {
    var b = closest(e.target, '[data-del]');
    if (!b) { return; }
    b.disabled = true;
    api.post('/delete_uploaded_file.php', { session: session, filename: b.getAttribute('data-del') }).then(function (res) {
      if (!res.ok) { api.toast(res.message || 'Could not delete', true); }
      loadUploads();
    });
  });
  $('sess-uploads-clear').addEventListener('click', function () {
    if (!window.confirm('Delete all uploaded files for this session?')) { return; }
    api.post('/delete_all_uploaded_files.php', { session: session }).then(function (res) {
      if (!res.ok) { api.toast(res.message || 'Could not delete', true); }
      loadUploads();
    });
  });

  // ---- view options ----
  function applyViewOptions() {
    document.body.classList.toggle('sess-hide-reasoning', !api.prefs.reasoning);
    document.body.classList.toggle('sess-hide-subagent', !api.prefs.subagent);
  }
  function syncOptions() {
    $('sess-opt-reasoning').checked = api.prefs.reasoning;
    $('sess-opt-subagent').checked = api.prefs.subagent;
    $('sess-opt-confirm').checked = api.prefs.confirm;
    $('sess-opt-poll').value = String(api.prefs.pollMs);
  }
  $('sess-opt-reasoning').addEventListener('change', function () {
    api.prefs.reasoning = this.checked;
    api.save('v2s-reasoning', this.checked ? '1' : '0');
    applyViewOptions();
    // Reasoning is only sent by the host agent when asked for, so the transcript is fetched again.
    api.reload();
  });
  $('sess-opt-subagent').addEventListener('change', function () {
    api.prefs.subagent = this.checked; api.save('v2s-subagent', this.checked ? '1' : '0'); applyViewOptions();
  });
  $('sess-opt-confirm').addEventListener('change', function () {
    api.prefs.confirm = this.checked; api.save('v2s-confirm', this.checked ? '1' : '0');
  });
  $('sess-opt-poll').addEventListener('change', function () {
    api.prefs.pollMs = parseInt(this.value, 10) || 3000; api.save('v2-poll', String(api.prefs.pollMs)); api.schedule();
  });

  // ======================================================================
  // search
  // ======================================================================

  var searchInput = $('sess-search-input');
  var results = $('sess-search-results');
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
    return document.querySelector('input[name="sess-scope"]:checked').value;
  }

  function renderHits(data, query) {
    if (!data || !data.ok) { results.innerHTML = '<p class="ui-muted ui-center">' + esc((data && data.message) || 'Search failed') + '</p>'; return; }
    if (scope() === 'session') {
      var matches = data.matches || [];
      results.innerHTML = matches.length ? matches.map(function (m) {
        return '<button type="button" class="sess-hit" data-line="' + m.line + '"><b>' + esc(m.role === 'user' ? 'You' : 'Agent') + ' · ' + esc(api.relTime(m.timestamp ? new Date(m.timestamp * 1000).toISOString() : '')) + '</b>'
          + '<span class="ui-result-snippet">' + highlight(String(m.snippet || ''), query) + '</span></button>';
      }).join('') : '<p class="ui-muted ui-center">No matches</p>';

      return;
    }
    var rows = data.results || [];
    results.innerHTML = rows.length ? rows.map(function (r) {
      var first = r.matches[0].line;
      var url = r.session_name
        ? '/session?session=' + encodeURIComponent(r.session_name) + '&jump_line=' + encodeURIComponent(first)
        : '/archived-session?agent_session_id=' + encodeURIComponent(r.agent_session_id) + '&jump_line=' + encodeURIComponent(first);
      return '<a class="sess-hit" href="' + url + '"><b>' + esc(r.title) + (r.session_name ? ' · live' : '') + '</b>'
        + r.matches.map(function (m) { return '<span class="ui-result-snippet">' + highlight(String(m.snippet || ''), query) + '</span>'; }).join('') + '</a>';
    }).join('') : '<p class="ui-muted ui-center">No matches</p>';
  }

  function runSearch() {
    var query = searchInput.value.trim();
    clearTimeout(searchTimer);
    if (searchAbort) { searchAbort.abort(); }
    if (!query) { results.innerHTML = ''; return; }
    searchTimer = setTimeout(function () {
      searchAbort = typeof AbortController === 'function' ? new AbortController() : null;
      results.innerHTML = '<p class="ui-muted ui-center">Searching…</p>';
      var url = scope() === 'session' ? '/session_search.php?' + api.qs({ session: session, q: query }) : '/search_sessions.php?' + api.qs({ q: query });
      api.getJson(url, searchAbort ? searchAbort.signal : undefined).then(function (data) { renderHits(data, query); })
        .catch(function (err) { if (!err || err.name !== 'AbortError') { results.innerHTML = '<p class="ui-muted ui-center">Network error</p>'; } });
    }, 300);
  }
  searchInput.addEventListener('input', runSearch);
  Array.prototype.forEach.call(document.querySelectorAll('input[name="sess-scope"]'), function (r) { r.addEventListener('change', runSearch); });
  results.addEventListener('click', function (e) {
    var hit = closest(e.target, 'button[data-line]');
    if (!hit) { return; }
    $('sess-search').close();
    api.jumpTo(parseInt(hit.getAttribute('data-line'), 10));
  });

  // ======================================================================
  // viewers
  // ======================================================================

  var viewer = $('sess-viewer');
  var stage = $('sess-viewer-stage');
  var vimg = $('sess-viewer-img');

  // Covers the footer and every sheet; pinching is the browser's own zoom, tapping toggles fit / natural size.
  function viewImage(src) {
    stage.classList.remove('is-full');
    vimg.src = src;
    $('sess-viewer-open').href = src;
    viewer.hidden = false;
    document.body.style.overflow = 'hidden';
  }
  function closeViewer() {
    viewer.hidden = true;
    vimg.removeAttribute('src');
    document.body.style.overflow = '';
  }
  vimg.addEventListener('click', function () { stage.classList.toggle('is-full'); });
  $('sess-viewer-close').addEventListener('click', closeViewer);

  var fsText = '';
  function fullscreen(title, text) {
    fsText = text;
    $('sess-fullscreen-title').textContent = title || '';
    $('sess-fullscreen-text').textContent = text;
    $('sess-fullscreen').hidden = false;
  }
  $('sess-fullscreen-close').addEventListener('click', function () { $('sess-fullscreen').hidden = true; });
  $('sess-fullscreen-copy').addEventListener('click', function () { api.copy(fsText); });

  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') { return; }
    if (!viewer.hidden) { closeViewer(); } else if (!$('sess-fullscreen').hidden) { $('sess-fullscreen').hidden = true; }
  });

  // ======================================================================
  // sessions popup (replaces the classic sidebar list) and usage popup
  // ======================================================================

  var sessionRows = [];
  function statusOfRow(x) { return x.blocked_reason ? 'blocked' : (x.working ? 'working' : 'idle'); }
  // Mirrors UiView::agent_pill() - the session list's agent pill names the
  // model and reasoning level too, when the agent reports them.
  function rowModel(x) {
    var model = x.current_model || x.current_antigravity_model || '';
    if (!model) { return ''; }
    if ((x.agent || 'claude') === 'claude') {
      var labels = x.model_labels || {};
      return labels[model] || model.charAt(0).toUpperCase() + model.slice(1);
    }
    return model;
  }
  function rowChips(x) {
    var bits = [];
    if (x.agent_label || x.agent) {
      var pill = [x.agent_label || x.agent];
      var model = rowModel(x);
      if (model) { pill.push(model); }
      if (x.current_effort) { pill.push(x.current_effort); }
      bits.push('<span class="ui-chip ui-agent-' + esc(x.agent || 'claude') + '">' + esc(pill.join(' · ')) + '</span>');
    }
    if ((x.agent || 'claude') === 'claude' && x.profile) { bits.push('<span class="ui-chip ui-chip-accent">' + esc(x.profile) + '</span>'); }
    if (x.runtime !== 'headless') { bits.push('<span class="ui-chip ui-chip-warn">Terminal, read only</span>'); }
    if (typeof x.context_used_percentage === 'number') { bits.push('<span class="ui-chip">ctx ' + Math.round(x.context_used_percentage) + '%</span>'); }
    // Same tail the dashboard card shows (UiView card chip): the agent's own conversation id.
    if (x.agent_session_id) {
      var cid = String(x.agent_session_id);
      bits.push('<span class="ui-chip ui-chip-id" title="Conversation ' + esc(cid) + '">&hellip;' + esc(cid.slice(-8)) + '</span>');
    }
    return bits.join('');
  }
  // What the session is waiting on, else the last message.
  function rowPreview(x) {
    if (x.blocked_reason) { return 'Needs input: ' + x.blocked_reason; }
    var m = x.last_message;
    var text = '';
    if (m && Array.isArray(m.blocks)) { m.blocks.forEach(function (b) { if (!text && b.text) { text = String(b.text); } }); }
    return text.replace(/\s+/g, ' ').slice(0, 140);
  }
  function renderSessionRows() {
    var q = $('sess-sessions-filter').value.trim().toLowerCase();
    var order = { blocked: 0, working: 1, idle: 2 };
    // The open session is always pinned on top, outside the filter; the filter applies to the rest.
    var current = sessionRows.filter(function (x) { return x.name === session; });
    var rows = sessionRows.filter(function (x) {
      return x.name !== session && (!q || (String(x.title || '') + ' ' + x.name + ' ' + String(x.workdir || '')).toLowerCase().indexOf(q) !== -1);
    }).sort(function (a, b) {
      return (order[statusOfRow(a)] - order[statusOfRow(b)]) || ((b.starred ? 1 : 0) - (a.starred ? 1 : 0)) || ((b.activity || 0) - (a.activity || 0));
    });
    var rowHtml = function (x) {
      var st = statusOfRow(x);
      var dir = String(x.workdir || '').replace(/\/+$/, '').replace(/^\/home\/[^\/]+(?=\/|$)/, '~');
      return '<div class="sess-srow' + (x.name === session ? ' is-current' : '') + (x.starred ? ' is-starred' : '') + '">'
        + '<a class="sess-srow-main"' + (x.name === session ? ' data-current' : '') + ' href="/session?session=' + encodeURIComponent(x.name) + '"><i class="ui-dot ui-dot-' + st + '" role="img" aria-label="' + (st === 'blocked' ? 'needs input' : st) + '"></i>'
        + '<span class="sess-srow-text"><b>' + esc(x.title || x.name) + '</b>'
        + '<span class="sess-srow-chips">' + (x.name === session ? '<span class="sess-current-pill">Current</span>' : '') + rowChips(x) + '</span>'
        + (dir ? '<small class="ui-path" title="' + esc(String(x.workdir)) + '">' + esc(dir) + '</small>' : '')
        + '<small>' + esc(api.relTime(x.activity ? new Date(x.activity * 1000).toISOString() : '')) + '</small>'
        + (rowPreview(x) ? '<small class="sess-srow-preview">' + esc(rowPreview(x)) + '</small>' : '')
        + (st === 'working' ? '<span class="ui-thinking" aria-label="Thinking"><i></i><i></i><i></i></span>' : '') + '</span></a>'
        + '<button type="button" class="ui-icon-btn" data-star="' + esc(x.name) + '" aria-pressed="' + (x.starred ? 'true' : 'false') + '" aria-label="' + (x.starred ? 'Unstar' : 'Star') + '">' + (x.starred ? '&#9733;' : '&#9734;') + '</button></div>';
    };
    var html = current.map(rowHtml).join('');
    if (current.length && rows.length) { html += '<p class="sess-srow-divider">Other sessions</p>'; }
    html += rows.length ? rows.map(rowHtml).join('') : '<p class="ui-muted ui-center">' + (current.length ? 'No other sessions' : 'No sessions') + '</p>';
    $('sess-sessions-list').innerHTML = html;
  }
  function loadSessions() {
    $('sess-sessions-list').innerHTML = '<p class="ui-muted ui-center">Loading…</p>';
    api.getJson('/sessions_list.php').then(function (data) {
      if (!data || !data.ok) { $('sess-sessions-list').innerHTML = '<p class="ui-muted ui-center">' + esc((data && data.message) || 'Could not load sessions') + '</p>'; return; }
      sessionRows = data.sessions || [];
      renderSessionRows();
    }).catch(function () { $('sess-sessions-list').innerHTML = '<p class="ui-muted ui-center">Network error</p>'; });
  }
  $('sess-sessions-filter').addEventListener('input', renderSessionRows);
  $('sess-sessions-list').addEventListener('click', function (e) {
    if (closest(e.target, '[data-current]')) {
      e.preventDefault();
      $('sess-sessions').close();
      openSheet('sess-info');
      return;
    }
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
    var box = $('sess-quota-body');
    box.innerHTML = '<p class="ui-muted ui-center">Loading…</p>';
    api.getJson('/quota.php?' + api.qs({ session: session })).then(function (data) {
      var d = api.detail();
      var pct = data && data.context && typeof data.context.pct === 'number' ? data.context.pct : d.context_used_percentage;
      var ctx = typeof pct === 'number' && isFinite(pct) ? '<p class="sess-ctx">This session has used <b>' + Math.round(pct) + '%</b> of its context.</p>' : '';
      box.innerHTML = ctx + window.UiQuota.render(data, { first: d.agent || 'claude' });
    }).catch(function () { box.innerHTML = '<p class="ui-muted ui-center">Could not load usage.</p>'; });
  }

  // ======================================================================
  // opening sheets
  // ======================================================================

  function openSheet(id) {
    var dlg = $(id);
    if (id === 'sess-controls') { buildControls(api.detail()); }
    if (id === 'sess-info') { buildInfo(api.detail()); syncOptions(); loadPlanFiles(); loadUploads(); }
    if (id === 'sess-sessions') { $('sess-sessions-filter').value = ''; loadSessions(); }
    if (id === 'sess-quota') { loadQuota(); }
    if (id === 'sess-search') { setTimeout(function () { searchInput.focus(); }, 50); }
    if (!dlg.open) { dlg.showModal(); }
  }

  api.controlsSummary = controlsSummary;
  api.openSheet = openSheet;
  api.fullscreen = fullscreen;
  api.viewImage = viewImage;
  // Header readout: usage windows change slowly, so they are fetched rarely; context rides on every detail poll.
  var miniEl = $('sess-mini');
  var miniQuota = null;
  function renderMini(d) {
    var pct = d.context_used_percentage;
    if (miniQuota && miniQuota.context && typeof miniQuota.context.pct === 'number' && typeof pct !== 'number') { pct = miniQuota.context.pct; }
    var html = miniQuota ? window.UiQuota.mini(miniQuota, d.agent || 'claude', d.profile, pct) : '';
    if (miniEl.innerHTML !== html) { miniEl.innerHTML = html; }
    miniEl.hidden = html === '';
  }
  function loadMini() {
    api.getJson('/quota.php?' + api.qs({ session: session })).then(function (data) {
      miniQuota = data;
      renderMini(api.detail());
    }).catch(function () {});
  }
  api.onDetail = renderMini;
  loadMini();
  setInterval(function () { if (!document.hidden) { loadMini(); } }, 300000);

  controlsSummary(api.detail());
}());
