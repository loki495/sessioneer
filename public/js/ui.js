/* Sessioneer dashboard. Plain ES5 on purpose (see CLAUDE.md): no build
   step, and mobile Safari is the browser that matters. The server renders
   every card; this file wires tabs, filters, polling and the actions. */
(function () {
  'use strict';

  var app = document.getElementById('ui-app');
  if (!app) { return; }

  var csrf = app.getAttribute('data-csrf');
  var sessionsEl = document.getElementById('ui-sessions');
  var bareEl = document.getElementById('ui-bare');

  // ---- storage (a private-mode Safari throws on access) ----
  // Keys keep their pre-rename "v2-" names so saved preferences carry over.
  function load(key, fallback) {
    try { var v = localStorage.getItem(key); return v === null ? fallback : v; } catch (e) { return fallback; }
  }
  function save(key, value) {
    try { localStorage.setItem(key, value); } catch (e) {}
  }

  var state = {
    filter: 'all',
    pollMs: parseInt(load('v2-poll', '3000'), 10) || 3000,
    showWorkers: load('v2-show-workers', '0') === '1',
    hideIdle: load('v2-hide-idle', '0') === '1',
    counts: null
  };

  // ---- helpers ----
  function $(sel, root) { return (root || document).querySelector(sel); }
  function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
  function closest(el, sel) {
    while (el && el.nodeType === 1) {
      if (el.matches(sel)) { return el; }
      el = el.parentNode;
    }
    return null;
  }
  function esc(s) {
    return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  var toastTimer = null;
  function toast(message, bad) {
    var el = document.getElementById('ui-toast');
    el.textContent = message;
    el.className = 'ui-toast' + (bad ? ' is-bad' : '');
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, bad ? 6000 : 3000);
  }

  function postJson(url, params) {
    var body = new URLSearchParams();
    body.append('csrf_token', csrf);
    Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
    return fetch(url, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString()
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false, message: 'Unexpected response (' + r.status + ')' }; });
    });
  }

  // One path for every dashboard action: show the outcome, follow a redirect, refresh the list.
  function runAction(params, doneMessage) {
    return postJson('/action', params).then(function (res) {
      if (res.ok && res.redirect) {
        if (res.notice) { try { sessionStorage.setItem('v2-notice', res.notice); } catch (e) {} }
        window.location.href = res.redirect; return res; }
      toast(res.ok ? (res.message || doneMessage || 'Done') : (res.message || 'Failed'), !res.ok);
      if (res.ok) { poll(); }
      return res;
    }).catch(function () { toast('Network error', true); });
  }

  // ---- tabs ----
  function showTab(name) {
    $all('.ui-panel').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
    $all('.ui-tab').forEach(function (t) { t.classList.toggle('is-on', t.getAttribute('data-tab') === name); });
    var fab = document.getElementById('ui-new');
    if (fab) { fab.hidden = name !== 'sessions'; }
    window.scrollTo(0, 0);
    if (name === 'archived') { loadArchived(); }
    if (name === 'status') { loadQuota(); }
    if (name === 'search') { document.getElementById('ui-search-input').focus(); }
  }

  // ---- filters + counts ----
  function applyFilters() {
    var visible = 0;
    $all('.ui-card', sessionsEl).forEach(function (card) {
      var status = card.getAttribute('data-status');
      var hide = (state.filter !== 'all' && status !== state.filter)
        || (!state.showWorkers && card.getAttribute('data-kind') === 'worker')
        || (state.hideIdle && status === 'idle' && card.getAttribute('data-starred') !== '1');
      card.hidden = hide;
      if (!hide) { visible++; }
    });
    var hasCards = sessionsEl.querySelector('.ui-card') !== null;
    document.getElementById('ui-filter-empty').hidden = !hasCards || visible > 0;
    $all('.ui-filter').forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-filter') === state.filter); });
  }

  function applyCounts(c) {
    state.counts = c;
    ['all:total', 'blocked:blocked', 'working:working', 'idle:idle'].forEach(function (pair) {
      var p = pair.split(':');
      document.getElementById('ui-n-' + p[0]).textContent = c[p[1]];
    });
    var badge = document.getElementById('ui-tab-badge');
    badge.hidden = c.blocked === 0;
    badge.textContent = c.blocked;
    var sub = c.total + (c.total === 1 ? ' session' : ' sessions');
    if (c.blocked) { sub += ' · ' + c.blocked + ' need' + (c.blocked === 1 ? 's' : '') + ' input'; }
    document.getElementById('ui-subtitle').textContent = sub;
    document.title = (c.blocked ? '(' + c.blocked + ') ' : '') + 'Sessioneer';
  }

  // ---- polling ----
  var lastHtml = '';
  var timer = null;
  var inFlight = false;

  function poll() {
    if (inFlight || document.hidden) { return Promise.resolve(); }
    inFlight = true;
    return fetch('/fragment', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        inFlight = false;
        if (!data || !data.ok) { return; }
        applyCounts(data.counts);
        // An open menu or a half-typed field would be destroyed by the swap.
        var key = data.sessions_html + '\u0000' + data.bare_html;
        if (key === lastHtml || sessionsEl.querySelector('.ui-menu[open]')) { return; }
        lastHtml = key;
        sessionsEl.innerHTML = data.sessions_html;
        bareEl.innerHTML = data.bare_html;
        applyFilters();
      })
      .catch(function () { inFlight = false; });
  }

  function startPolling() {
    clearInterval(timer);
    timer = setInterval(poll, state.pollMs);
  }

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { poll(); }
  });

  // ---- click delegation ----
  document.addEventListener('click', function (e) {
    var open = $all('.ui-menu[open]');
    open.forEach(function (m) { if (!m.contains(e.target)) { m.removeAttribute('open'); } });

    var el = closest(e.target, '[data-act]');
    var card = closest(e.target, '[data-session]');
    var name = card ? card.getAttribute('data-session') : null;

    if (closest(e.target, '[data-filter]')) {
      state.filter = closest(e.target, '[data-filter]').getAttribute('data-filter');
      applyFilters();
      return;
    }
    if (closest(e.target, '[data-tab]')) { showTab(closest(e.target, '[data-tab]').getAttribute('data-tab')); return; }
    if (closest(e.target, '[data-close]')) { closest(e.target, 'dialog').close(); return; }
    if (!el) { return; }

    switch (el.getAttribute('data-act')) {
      case 'star':
        var on = card.getAttribute('data-starred') !== '1';
        postJson('/session_star.php', { session: name, starred: on ? '1' : '0' }).then(function (res) {
          if (!res.ok) { toast(res.message || 'Could not star', true); return; }
          card.setAttribute('data-starred', on ? '1' : '0');
          el.classList.toggle('is-on', on);
          el.setAttribute('aria-pressed', on ? 'true' : 'false');
          el.innerHTML = on ? '&#9733;' : '&#9734;';
          lastHtml = '';
          applyFilters();
        });
        break;
      case 'rename':
        var title = window.prompt('Session name (empty clears it):', el.getAttribute('data-title'));
        if (title === null) { break; }
        postJson('/session_rename.php', { session: name, title: title }).then(function (res) {
          toast(res.ok ? 'Renamed' : (res.message || 'Rename failed'), !res.ok);
          if (res.ok) { lastHtml = ''; poll(); }
        });
        break;
      case 'switch':
        if (window.confirm('Take over ' + name + ' as a headless session? The conversation continues; the terminal process is stopped.')) {
          runAction({ action: 'switch_runtime', session: name, runtime: 'headless' });
        }
        break;
      case 'take-over':
        if (!window.confirm('Take over process ' + el.getAttribute('data-pid') + ' as a headless session? The terminal process is stopped.')) { break; }
        el.disabled = true;
        postJson('/action', { action: 'take_over', pid: el.getAttribute('data-pid') }).then(function (res) {
          el.disabled = false;
          if (res.ok && res.needs_choice) { openTakeOverPicker(res); return; }
          if (res.ok && res.redirect) { window.location.href = res.redirect; return; }
          toast(res.ok ? (res.message || 'Done') : (res.message || 'Failed'), !res.ok);
          if (res.ok) { poll(); }
        }).catch(function () { el.disabled = false; toast('Network error', true); });
        break;
      case 'kill':
        if (window.confirm('Kill session ' + name + '?')) { runAction({ action: 'kill', session: name }, 'Session killed'); }
        break;
      case 'kill-bare':
        if (window.confirm('Kill process ' + el.getAttribute('data-pid') + '?')) { runAction({ action: 'kill_bare', pid: el.getAttribute('data-pid') }, 'Process killed'); }
        break;
      case 'answer':
        el.disabled = true;
        postJson('/answer_prompt.php', { session: name, option: el.getAttribute('data-option'), expected_label: el.getAttribute('data-label') }).then(function (res) {
          el.disabled = false;
          toast(res.ok ? 'Answered' : (res.message || 'Could not answer'), !res.ok);
          if (res.ok) { lastHtml = ''; poll(); }
        }).catch(function () { el.disabled = false; toast('Network error', true); });
        break;
      case 'install-hook':
        runAction({ action: 'install_hook' }).then(function (res) { if (res && res.ok) { el.parentNode.hidden = true; } });
        break;
      case 'cleanup':
        if (window.confirm('Kill all tracked sessions inactive for more than 12h?')) { runAction({ action: 'cleanup' }); }
        break;
      case 'push-interval':
        runAction({ action: 'set_push_timer_interval', seconds: document.getElementById('ui-push-interval').value });
        break;
      case 'resume':
        var p = { action: 'resume', agent_session_id: el.getAttribute('data-id'), workdir: el.getAttribute('data-workdir') };
        if (el.getAttribute('data-profile')) { p.profile = el.getAttribute('data-profile'); }
        el.disabled = true;
        runAction(p).then(function () { el.disabled = false; });
        break;
    }
  });

  // ---- take-over picker: nothing was stopped yet; a human picks which conversation the process was running ----
  var takeDialog = document.getElementById('ui-takeover-dialog');
  var takeSel = document.getElementById('ui-takeover-select');
  var takeCtx = null;
  function openTakeOverPicker(res) {
    takeCtx = res;
    while (takeSel.options.length) { takeSel.remove(0); }
    (res.candidates || []).forEach(function (c) {
      var o = document.createElement('option');
      o.value = c.agent_session_id;
      o.textContent = (c.title || c.agent_session_id) + (c.last_activity ? ' - ' + new Date(c.last_activity * 1000).toLocaleString() : '');
      if (c.agent_session_id === res.suggested_agent_session_id) { o.selected = true; }
      takeSel.appendChild(o);
    });
    document.getElementById('ui-takeover-none').hidden = takeSel.options.length > 0;
    document.getElementById('ui-takeover-go').disabled = takeSel.options.length === 0;
    takeDialog.showModal();
  }
  document.getElementById('ui-takeover-go').addEventListener('click', function () {
    takeDialog.close();
    runAction({ action: 'take_over_confirm', pid: takeCtx.pid, workdir: takeCtx.workdir, agent_session_id: takeSel.value });
  });

  // ---- search ----
  var searchInput = document.getElementById('ui-search-input');
  var searchResults = document.getElementById('ui-search-results');
  var searchTimer = null;
  var searchAbort = null;

  function highlight(snippet, query) {
    var lower = snippet.toLowerCase(), q = query.toLowerCase(), out = '', i = 0, at;
    while (q && (at = lower.indexOf(q, i)) !== -1) {
      out += esc(snippet.slice(i, at)) + '<mark>' + esc(snippet.slice(at, at + q.length)) + '</mark>';
      i = at + q.length;
    }
    return out + esc(snippet.slice(i));
  }

  function renderResults(results, query) {
    if (!results || results.length === 0) {
      searchResults.innerHTML = '<div class="ui-empty"><p class="ui-empty-title">No matches</p></div>';
      return;
    }
    searchResults.innerHTML = results.map(function (r) {
      var first = r.matches[0].line;
      var url = r.session_name
        ? '/session?session=' + encodeURIComponent(r.session_name) + '&jump_line=' + encodeURIComponent(first)
        : '/archived-session?agent_session_id=' + encodeURIComponent(r.agent_session_id) + '&jump_line=' + encodeURIComponent(first);
      var snippets = r.matches.map(function (m) {
        return '<p class="ui-result-snippet">' + highlight(String(m.snippet || ''), query) + '</p>';
      }).join('');
      var action = r.session_name
        ? '<button type="button" class="ui-btn" data-act="kill-search" data-session-name="' + esc(r.session_name) + '">Archive</button>'
        : (r.cwd ? '<button type="button" class="ui-btn" data-act="resume" data-id="' + esc(r.agent_session_id) + '" data-workdir="' + esc(r.cwd) + '" data-profile="' + esc(r.profile || '') + '">Unarchive</button>' : '');
      return '<article class="ui-card ui-card-flat" data-session-result><div class="ui-card-main">'
        + '<a class="ui-title ui-link-cover" href="' + url + '">' + esc(r.title) + '</a>'
        + (r.session_name ? '<div class="ui-chips"><span class="ui-chip ui-chip-ok">live</span></div>' : '')
        + snippets + '</div>' + (action ? '<div class="ui-card-actions">' + action + '</div>' : '') + '</article>';
    }).join('');
  }

  searchInput.addEventListener('input', function () {
    var query = searchInput.value.trim();
    clearTimeout(searchTimer);
    if (searchAbort) { searchAbort.abort(); }
    if (query === '') { searchResults.innerHTML = ''; return; }
    searchTimer = setTimeout(function () {
      searchAbort = typeof AbortController === 'function' ? new AbortController() : null;
      searchResults.innerHTML = '<p class="ui-muted ui-center">Searching…</p>';
      fetch('/search_sessions.php?q=' + encodeURIComponent(query), { credentials: 'same-origin', signal: searchAbort ? searchAbort.signal : undefined })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) { searchResults.innerHTML = '<p class="ui-muted ui-center">' + esc((data && data.message) || 'Search failed') + '</p>'; return; }
          renderResults(data.results, query);
        })
        .catch(function (err) { if (!err || err.name !== 'AbortError') { searchResults.innerHTML = '<p class="ui-muted ui-center">Network error</p>'; } });
    }, 300);
  });

  // Archive (kill) from a search hit: it lives only inside the results list.
  searchResults.addEventListener('click', function (e) {
    var b = closest(e.target, '[data-act="kill-search"]');
    if (b && window.confirm('Archive session ' + b.getAttribute('data-session-name') + '?')) {
      runAction({ action: 'kill', session: b.getAttribute('data-session-name') }, 'Archived');
    }
  });

  // ---- archived ----
  var archivedEl = document.getElementById('ui-archived');
  var archivedFilter = document.getElementById('ui-archived-filter');
  var archivedMore = document.getElementById('ui-archived-more');
  var archivedLoaded = false;
  var ARCHIVED_PAGE = 50;
  var archivedLimit = ARCHIVED_PAGE;

  function renderArchivedPage() {
    var q = archivedFilter.value.trim().toLowerCase();
    var shown = 0, matched = 0;
    $all('[data-archived-row]', archivedEl).forEach(function (row) {
      var ok = q === '' || row.getAttribute('data-search').indexOf(q) !== -1;
      if (ok) { matched++; }
      row.hidden = !(ok && shown < archivedLimit);
      if (!row.hidden) { shown++; }
    });
    archivedMore.hidden = matched <= shown;
  }

  function loadArchived() {
    if (archivedLoaded) { return; }
    archivedLoaded = true;
    fetch('/archived', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) { archivedLoaded = false; archivedEl.innerHTML = '<p class="ui-muted ui-center">' + esc((data && data.message) || 'Failed to load') + '</p>'; return; }
        archivedEl.innerHTML = data.archived_html;
        renderArchivedPage();
      })
      .catch(function () { archivedLoaded = false; archivedEl.innerHTML = '<p class="ui-muted ui-center">Network error</p>'; });
  }

  archivedFilter.addEventListener('input', function () { archivedLimit = ARCHIVED_PAGE; renderArchivedPage(); });
  archivedMore.addEventListener('click', function () { archivedLimit += ARCHIVED_PAGE; renderArchivedPage(); });

  // ---- usage (status tab) ----
  var quotaAt = 0;

  function loadQuota() {
    if (Date.now() - quotaAt < 30000) { return; }
    quotaAt = Date.now();
    var box = document.getElementById('ui-quota');
    fetch('/quota.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { box.innerHTML = UiQuota.render(data); })
      .catch(function () { quotaAt = 0; box.innerHTML = '<span class="ui-muted ui-small">Could not load usage.</span>'; });
  }

  // ---- settings ----
  var settingsDialog = document.getElementById('ui-settings-dialog');
  var themeSelect = document.getElementById('v2-theme');
  var pollSelect = document.getElementById('v2-poll');
  var workersBox = document.getElementById('v2-show-workers');
  var idleBox = document.getElementById('v2-hide-idle');

  themeSelect.value = load('v2-theme', 'system');
  pollSelect.value = String(state.pollMs);
  workersBox.checked = state.showWorkers;
  idleBox.checked = state.hideIdle;

  function applyTheme() {
    var pref = themeSelect.value;
    var dark = pref === 'dark' || (pref === 'system' && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.setAttribute('data-theme', dark ? 'dark' : 'light');
  }
  themeSelect.addEventListener('change', function () { save('v2-theme', themeSelect.value); applyTheme(); });
  if (window.matchMedia) {
    var mq = window.matchMedia('(prefers-color-scheme: dark)');
    var onScheme = function () { if (themeSelect.value === 'system') { applyTheme(); } };
    if (mq.addEventListener) { mq.addEventListener('change', onScheme); } else if (mq.addListener) { mq.addListener(onScheme); }
  }
  pollSelect.addEventListener('change', function () { state.pollMs = parseInt(pollSelect.value, 10); save('v2-poll', pollSelect.value); startPolling(); });
  workersBox.addEventListener('change', function () { state.showWorkers = workersBox.checked; save('v2-show-workers', workersBox.checked ? '1' : '0'); applyFilters(); });
  idleBox.addEventListener('change', function () { state.hideIdle = idleBox.checked; save('v2-hide-idle', idleBox.checked ? '1' : '0'); applyFilters(); });
  document.getElementById('ui-open-settings').addEventListener('click', function () { settingsDialog.showModal(); });

  // ---- new session ----
  var newDialog = document.getElementById('ui-new-dialog');
  var newForm = document.getElementById('ui-new-form');
  var agentSel = document.getElementById('ui-new-agent');
  var profileSel = document.getElementById('ui-new-profile');
  var profileWrap = document.getElementById('ui-new-profile-wrap');
  var modelSel = document.getElementById('ui-new-model');
  var submitBtn = document.getElementById('ui-new-submit');
  var pathEl = document.getElementById('ui-browser-path');
  var listEl = document.getElementById('ui-browser-list');
  var workdir = '';
  var browserLoaded = false;

  // Agents with no terminal, modes or task tools; keep in sync with index.js's HEADLESS_ONLY_AGENTS.
  var HEADLESS_ONLY = { ollama: true };
  var MODEL_AGENTS = { claude: true, opencode: true, codex: true, ollama: true };
  var CLAUDE_MODELS = [{ id: 'sonnet', label: 'Sonnet' }, { id: 'fable', label: 'Fable' }, { id: 'opus', label: 'Opus' }, { id: 'haiku', label: 'Haiku' }];
  var modelCache = {};
  var profiles = null;

  function setModels(models, agent) {
    while (modelSel.options.length > 1) { modelSel.remove(1); }
    models.forEach(function (m) {
      var o = document.createElement('option');
      o.value = m.id;
      o.textContent = m.providerID ? m.providerID + '/' + m.id : (m.name || m.label || m.id);
      if (m.providerID) { o.setAttribute('data-provider', m.providerID); }
      modelSel.appendChild(o);
    });
    // Such an agent has no default model; the first catalog row stands in.
    if (HEADLESS_ONLY[agent] && modelSel.options.length > 1) { modelSel.selectedIndex = 1; }
  }

  function onAgentChange() {
    var agent = agentSel.value;
    var headlessOnly = HEADLESS_ONLY[agent] === true;
    $all('[data-agent-field]', newForm).forEach(function (f) {
      f.hidden = headlessOnly;
      $all('select, input', f).forEach(function (c) { c.disabled = headlessOnly; });
    });
    modelSel.options[0].textContent = headlessOnly ? 'Choose a model…' : 'Default';
    setModels(agent === 'claude' ? CLAUDE_MODELS : [], agent);

    if (MODEL_AGENTS[agent]) {
      if (modelCache[agent]) { setModels(modelCache[agent], agent); } else {
        fetch('/session_list_models.php?agent=' + encodeURIComponent(agent), { credentials: 'same-origin' })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (!data || !data.ok || !data.models) { return; }
            modelCache[agent] = data.models;
            if (agentSel.value === agent) { var keep = modelSel.value; setModels(data.models, agent); if (keep) { modelSel.value = keep; } }
          });
      }
    }
    showProfiles();
  }

  function showProfiles() {
    var show = agentSel.value === 'claude' && profiles && profiles.length > 1;
    profileWrap.hidden = !show;
    profileSel.disabled = !show;
    if (!show) { profileSel.value = ''; return; }
    while (profileSel.options.length > 1) { profileSel.remove(1); }
    profiles.forEach(function (p) { var o = document.createElement('option'); o.value = p; o.textContent = p; profileSel.appendChild(o); });
  }

  function loadProfiles() {
    if (profiles !== null) { return; }
    profiles = [];
    fetch('/session_list_claude_profiles.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) { profiles = (data && data.ok && Array.isArray(data.profiles)) ? data.profiles : []; showProfiles(); });
  }

  function statusRow(text) {
    listEl.innerHTML = '';
    var li = document.createElement('li');
    li.className = 'ui-muted';
    li.textContent = text;
    listEl.appendChild(li);
  }

  function dirRow(label, cls, onClick) {
    var li = document.createElement('li');
    var b = document.createElement('button');
    b.type = 'button';
    b.className = cls || '';
    b.textContent = label;
    b.addEventListener('click', onClick);
    li.appendChild(b);
    listEl.appendChild(li);
  }

  function browse(path) {
    workdir = '';
    submitBtn.disabled = true;
    statusRow('Loading…');
    fetch('/browse.php?path=' + encodeURIComponent(path || ''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) { pathEl.textContent = 'Unavailable'; statusRow((data && data.message) || 'Could not load folders.'); return; }
        workdir = data.path;
        pathEl.textContent = data.path;
        submitBtn.disabled = false;
        listEl.innerHTML = '';
        if (data.parent !== null) { dirRow('.. (up)', 'is-up', function () { browse(data.parent); }); }
        if (data.dirs.length === 0) {
          var li = document.createElement('li'); li.className = 'ui-muted'; li.textContent = 'No subfolders here.'; listEl.appendChild(li);
        }
        data.dirs.forEach(function (d) { dirRow(d, '', function () { browse(data.path + '/' + d); }); });
      })
      .catch(function () { pathEl.textContent = 'Unavailable'; statusRow('Network error.'); });
  }

  document.getElementById('ui-new-folder').addEventListener('click', function () {
    var name = window.prompt('New folder name:');
    if (!workdir || name === null || name.trim() === '') { return; }
    postJson('/create_folder.php', { path: workdir, name: name.trim() }).then(function (res) {
      if (!res || !res.ok) { toast((res && res.message) || 'Could not create folder', true); return; }
      browse(res.path);
    });
  });

  var newBtn = document.getElementById('ui-new');
  if (newBtn) {
    newBtn.addEventListener('click', function () {
      newDialog.showModal();
      if (!browserLoaded) { browserLoaded = true; browse(''); onAgentChange(); loadProfiles(); }
    });
  }
  agentSel.addEventListener('change', onAgentChange);

  newForm.addEventListener('submit', function (e) {
    e.preventDefault();
    if (!workdir) { return; }
    var p = { action: 'new', workdir: workdir, agent: agentSel.value };
    if (modelSel.value) {
      p.model = modelSel.value;
      p.model_provider = modelSel.options[modelSel.selectedIndex].getAttribute('data-provider') || '';
    }
    if (!profileSel.disabled && profileSel.value) { p.profile = profileSel.value; }
    var mode = document.getElementById('ui-new-mode');
    if (!mode.disabled && mode.value) { p.starting_mode = mode.value; }
    var promptEl = document.getElementById('ui-new-prompt');
    if (!promptEl.disabled && promptEl.value.trim()) { p.prompt = promptEl.value.trim(); }
    var tools = document.getElementById('ui-new-tasktools');
    if (!tools.disabled && tools.checked) { p.enable_task_tools = '1'; }
    submitBtn.disabled = true;
    runAction(p).then(function (res) {
      submitBtn.disabled = false;
      if (res && res.ok) { newDialog.close(); }
    });
  });

  // ---- boot ----
  // A browser-restored page (iOS PWA resume) can be hours old.
  window.addEventListener('pageshow', function (e) { if (e.persisted) { poll(); } });
  applyFilters();
  function chipCount(id) { return parseInt(document.getElementById('ui-n-' + id).textContent, 10) || 0; }
  applyCounts({ total: chipCount('all'), blocked: chipCount('blocked'), working: chipCount('working'), idle: chipCount('idle') });
  showTab('sessions');
  startPolling();
  poll();
})();
