/* Sessioneer v2 dashboard. Plain ES5 on purpose (see CLAUDE.md): no build
   step, and mobile Safari is the browser that matters. The server renders
   every card; this file wires tabs, filters, polling and the actions. */
(function () {
  'use strict';

  var app = document.getElementById('v2-app');
  if (!app) { return; }

  var csrf = app.getAttribute('data-csrf');
  var sessionsEl = document.getElementById('v2-sessions');
  var bareEl = document.getElementById('v2-bare');

  // ---- storage (a private-mode Safari throws on access) ----
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
    var el = document.getElementById('v2-toast');
    el.textContent = message;
    el.className = 'v2-toast' + (bad ? ' is-bad' : '');
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
    return postJson('/v2/action', params).then(function (res) {
      if (res.ok && res.redirect) { window.location.href = res.redirect; return res; }
      toast(res.ok ? (res.message || doneMessage || 'Done') : (res.message || 'Failed'), !res.ok);
      if (res.ok) { poll(); }
      return res;
    }).catch(function () { toast('Network error', true); });
  }

  // ---- tabs ----
  function showTab(name) {
    $all('.v2-panel').forEach(function (p) { p.hidden = p.getAttribute('data-panel') !== name; });
    $all('.v2-tab').forEach(function (t) { t.classList.toggle('is-on', t.getAttribute('data-tab') === name); });
    var fab = document.getElementById('v2-new');
    if (fab) { fab.hidden = name !== 'sessions'; }
    window.scrollTo(0, 0);
    if (name === 'archived') { loadArchived(); }
    if (name === 'status') { loadQuota(); }
    if (name === 'search') { document.getElementById('v2-search-input').focus(); }
  }

  // ---- filters + counts ----
  function applyFilters() {
    var visible = 0;
    $all('.v2-card', sessionsEl).forEach(function (card) {
      var status = card.getAttribute('data-status');
      var hide = (state.filter !== 'all' && status !== state.filter)
        || (!state.showWorkers && card.getAttribute('data-kind') === 'worker')
        || (state.hideIdle && status === 'idle' && card.getAttribute('data-starred') !== '1');
      card.hidden = hide;
      if (!hide) { visible++; }
    });
    var hasCards = sessionsEl.querySelector('.v2-card') !== null;
    document.getElementById('v2-filter-empty').hidden = !hasCards || visible > 0;
    $all('.v2-filter').forEach(function (b) { b.classList.toggle('is-on', b.getAttribute('data-filter') === state.filter); });
  }

  function applyCounts(c) {
    state.counts = c;
    ['all:total', 'blocked:blocked', 'working:working', 'idle:idle'].forEach(function (pair) {
      var p = pair.split(':');
      document.getElementById('v2-n-' + p[0]).textContent = c[p[1]];
    });
    var badge = document.getElementById('v2-tab-badge');
    badge.hidden = c.blocked === 0;
    badge.textContent = c.blocked;
    var sub = c.total + (c.total === 1 ? ' session' : ' sessions');
    if (c.blocked) { sub += ' · ' + c.blocked + ' need' + (c.blocked === 1 ? 's' : '') + ' input'; }
    document.getElementById('v2-subtitle').textContent = sub;
    document.title = (c.blocked ? '(' + c.blocked + ') ' : '') + 'Sessioneer';
  }

  // ---- polling ----
  var lastHtml = '';
  var timer = null;
  var inFlight = false;

  function poll() {
    if (inFlight || document.hidden) { return Promise.resolve(); }
    inFlight = true;
    return fetch('/v2/fragment', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        inFlight = false;
        if (!data || !data.ok) { return; }
        applyCounts(data.counts);
        // An open menu or a half-typed field would be destroyed by the swap.
        var key = data.sessions_html + '\u0000' + data.bare_html;
        if (key === lastHtml || sessionsEl.querySelector('.v2-menu[open]')) { return; }
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
    var open = $all('.v2-menu[open]');
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
        var target = el.getAttribute('data-runtime');
        if (window.confirm('Move ' + name + ' to ' + (target === 'headless' ? 'a headless session (no terminal)' : 'a terminal (tmux) session') + '? The conversation continues; the current process is stopped.')) {
          runAction({ action: 'switch_runtime', session: name, runtime: target });
        }
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
        runAction({ action: 'set_push_timer_interval', seconds: document.getElementById('v2-push-interval').value });
        break;
      case 'resume':
        var p = { action: 'resume', agent_session_id: el.getAttribute('data-id'), workdir: el.getAttribute('data-workdir') };
        if (el.getAttribute('data-profile')) { p.profile = el.getAttribute('data-profile'); }
        if (el.getAttribute('data-runtime')) { p.runtime = el.getAttribute('data-runtime'); }
        el.disabled = true;
        runAction(p).then(function () { el.disabled = false; });
        break;
    }
  });

  // ---- search ----
  var searchInput = document.getElementById('v2-search-input');
  var searchResults = document.getElementById('v2-search-results');
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
      searchResults.innerHTML = '<div class="v2-empty"><p class="v2-empty-title">No matches</p></div>';
      return;
    }
    searchResults.innerHTML = results.map(function (r) {
      var first = r.matches[0].line;
      var url = r.session_name
        ? '/session.php?session=' + encodeURIComponent(r.session_name) + '&jump_line=' + encodeURIComponent(first)
        : '/archived_session.php?agent_session_id=' + encodeURIComponent(r.agent_session_id) + '&jump_line=' + encodeURIComponent(first);
      var snippets = r.matches.map(function (m) {
        return '<p class="v2-result-snippet">' + highlight(String(m.snippet || ''), query) + '</p>';
      }).join('');
      var action = r.session_name
        ? '<button type="button" class="v2-btn" data-act="kill-search" data-session-name="' + esc(r.session_name) + '">Archive</button>'
        : (r.cwd ? '<button type="button" class="v2-btn" data-act="resume" data-id="' + esc(r.agent_session_id) + '" data-workdir="' + esc(r.cwd) + '" data-profile="' + esc(r.profile || '') + '">Unarchive</button>' : '');
      return '<article class="v2-card v2-card-flat" data-session-result><div class="v2-card-main">'
        + '<a class="v2-title v2-link-cover" href="' + url + '">' + esc(r.title) + '</a>'
        + (r.session_name ? '<div class="v2-chips"><span class="v2-chip v2-chip-ok">live</span></div>' : '')
        + snippets + '</div>' + (action ? '<div class="v2-card-actions">' + action + '</div>' : '') + '</article>';
    }).join('');
  }

  searchInput.addEventListener('input', function () {
    var query = searchInput.value.trim();
    clearTimeout(searchTimer);
    if (searchAbort) { searchAbort.abort(); }
    if (query === '') { searchResults.innerHTML = ''; return; }
    searchTimer = setTimeout(function () {
      searchAbort = typeof AbortController === 'function' ? new AbortController() : null;
      searchResults.innerHTML = '<p class="v2-muted v2-center">Searching…</p>';
      fetch('/search_sessions.php?q=' + encodeURIComponent(query), { credentials: 'same-origin', signal: searchAbort ? searchAbort.signal : undefined })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) { searchResults.innerHTML = '<p class="v2-muted v2-center">' + esc((data && data.message) || 'Search failed') + '</p>'; return; }
          renderResults(data.results, query);
        })
        .catch(function (err) { if (!err || err.name !== 'AbortError') { searchResults.innerHTML = '<p class="v2-muted v2-center">Network error</p>'; } });
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
  var archivedEl = document.getElementById('v2-archived');
  var archivedFilter = document.getElementById('v2-archived-filter');
  var archivedMore = document.getElementById('v2-archived-more');
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
    fetch('/v2/archived', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) { archivedLoaded = false; archivedEl.innerHTML = '<p class="v2-muted v2-center">' + esc((data && data.message) || 'Failed to load') + '</p>'; return; }
        archivedEl.innerHTML = data.archived_html;
        renderArchivedPage();
      })
      .catch(function () { archivedLoaded = false; archivedEl.innerHTML = '<p class="v2-muted v2-center">Network error</p>'; });
  }

  archivedFilter.addEventListener('input', function () { archivedLimit = ARCHIVED_PAGE; renderArchivedPage(); });
  archivedMore.addEventListener('click', function () { archivedLimit += ARCHIVED_PAGE; renderArchivedPage(); });

  // ---- usage (status tab) ----
  var QUOTA_LABELS = { session: '5-hour', week_all: 'Week', week_fable: 'Week (Fable)' };
  var quotaAt = 0;

  function meter(label, pct) {
    var cls = pct >= 100 ? ' is-full' : (pct >= 80 ? ' is-high' : '');
    return '<div class="v2-meter"><div class="v2-meter-top"><span>' + esc(label) + '</span><b>' + Math.round(pct) + '%</b></div>'
      + '<div class="v2-meter-bar"><span class="' + cls.trim() + '" style="width:' + Math.min(100, Math.max(0, pct)) + '%"></span></div></div>';
  }

  function loadQuota() {
    if (Date.now() - quotaAt < 30000) { return; }
    quotaAt = Date.now();
    var box = document.getElementById('v2-quota');
    fetch('/quota.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        var html = '';
        var agents = (data && data.agents) || {};
        Object.keys(agents).forEach(function (id) {
          var a = agents[id];
          if (!a || !a.ok || !a.quota) { return; }
          var rows = Object.keys(a.quota).filter(function (k) { return a.quota[k] && typeof a.quota[k].pct === 'number'; });
          if (rows.length === 0) { return; }
          html += '<h3 class="v2-subtitle">' + esc(a.label || id) + '</h3>';
          rows.forEach(function (k) { html += meter(QUOTA_LABELS[k] || (a.quota[k].group_name ? a.quota[k].group_name + ' ' + k : k), a.quota[k].pct); });
        });
        box.innerHTML = html || '<span class="v2-muted v2-small">No usage data available.</span>';
      })
      .catch(function () { quotaAt = 0; box.innerHTML = '<span class="v2-muted v2-small">Could not load usage.</span>'; });
  }

  // ---- settings ----
  var settingsDialog = document.getElementById('v2-settings-dialog');
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
  document.getElementById('v2-open-settings').addEventListener('click', function () { settingsDialog.showModal(); });

  // ---- new session ----
  var newDialog = document.getElementById('v2-new-dialog');
  var newForm = document.getElementById('v2-new-form');
  var agentSel = document.getElementById('v2-new-agent');
  var profileSel = document.getElementById('v2-new-profile');
  var profileWrap = document.getElementById('v2-new-profile-wrap');
  var runtimeSel = document.getElementById('v2-new-runtime');
  var runtimeWrap = document.getElementById('v2-new-runtime-wrap');
  var modelSel = document.getElementById('v2-new-model');
  var submitBtn = document.getElementById('v2-new-submit');
  var pathEl = document.getElementById('v2-browser-path');
  var listEl = document.getElementById('v2-browser-list');
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
    runtimeWrap.hidden = agent !== 'claude';
    runtimeSel.disabled = agent !== 'claude';
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
    li.className = 'v2-muted';
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
          var li = document.createElement('li'); li.className = 'v2-muted'; li.textContent = 'No subfolders here.'; listEl.appendChild(li);
        }
        data.dirs.forEach(function (d) { dirRow(d, '', function () { browse(data.path + '/' + d); }); });
      })
      .catch(function () { pathEl.textContent = 'Unavailable'; statusRow('Network error.'); });
  }

  document.getElementById('v2-new-folder').addEventListener('click', function () {
    var name = window.prompt('New folder name:');
    if (!workdir || name === null || name.trim() === '') { return; }
    postJson('/create_folder.php', { path: workdir, name: name.trim() }).then(function (res) {
      if (!res || !res.ok) { toast((res && res.message) || 'Could not create folder', true); return; }
      browse(res.path);
    });
  });

  var newBtn = document.getElementById('v2-new');
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
    if (!runtimeSel.disabled) { p.runtime = runtimeSel.value; }
    if (modelSel.value) {
      p.model = modelSel.value;
      p.model_provider = modelSel.options[modelSel.selectedIndex].getAttribute('data-provider') || '';
    }
    if (!profileSel.disabled && profileSel.value) { p.profile = profileSel.value; }
    var mode = document.getElementById('v2-new-mode');
    if (!mode.disabled && mode.value) { p.starting_mode = mode.value; }
    var tools = document.getElementById('v2-new-tasktools');
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
  function chipCount(id) { return parseInt(document.getElementById('v2-n-' + id).textContent, 10) || 0; }
  applyCounts({ total: chipCount('all'), blocked: chipCount('blocked'), working: chipCount('working'), idle: chipCount('idle') });
  showTab('sessions');
  startPolling();
  poll();
})();
