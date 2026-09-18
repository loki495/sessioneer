// @ts-check
(function () {
  var footer = document.getElementById('quota-footer');
  var toggleBtn = document.getElementById('quota-toggle-btn');
  var toggleIcon = document.getElementById('quota-toggle-icon');
  var el = document.getElementById('quota-info');
  var sessionName = (footer && footer.dataset.session) ? footer.dataset.session : '';
  var STORAGE_KEY = 'sessioneer-quota-collapsed';

  if (!footer || !toggleBtn || !toggleIcon || !el) {
    return;
  }

  function escapeHtml(text) {
    var div = document.createElement('div');
    div.textContent = text || '';
    return div.innerHTML;
  }

  function applyCollapsed(collapsed) {
    el.classList.toggle('hidden', collapsed);
    toggleIcon.innerHTML = collapsed ? '&#9656;' : '&#9662;';
  }

  // Collapsed by default - only expanded if the user has explicitly
  // expanded it before (stored '0'). Private browsing / storage
  // disabled just falls back to the same collapsed default, no
  // persistence either way.
  var storedCollapsed = true;
  try {
    storedCollapsed = window.localStorage.getItem(STORAGE_KEY) !== '0';
  } catch (e) {}
  applyCollapsed(storedCollapsed);

  toggleBtn.addEventListener('click', function () {
    var collapsed = !el.classList.contains('hidden');
    applyCollapsed(collapsed);
    try {
      window.localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0');
    } catch (e) {}
  });

  function pctColorClass(pct) {
    if (pct >= 90) return 'text-red-400';
    if (pct >= 70) return 'text-amber-400';
    return 'text-slate-300';
  }

  function label(key, bar) {
    if (bar && bar.group_name) return bar.group_name;
    if (key === 'context') return 'Context';
    if (key === 'session') return 'Session';
    if (key === 'week_all') return 'Week';
    if (key === 'month_all') return 'Monthly';
    if (key === 'gemini-weekly') return 'Gemini';
    if (key === '3p-weekly') return 'Claude & GPT';
    return key.replace(/^week_/, '').replace(/_/g, ' ').replace(/-/g, ' ').replace(/\b\w/g, function (c) { return c.toUpperCase(); }) + ' (week)';
  }

  // No leading zeros by construction (Math.floor results are used bare).
  function formatDuration(seconds, kind) {
    if (seconds <= 0) return 'now';

    if (kind === 'session') {
      var h = Math.floor(seconds / 3600);
      var m = Math.floor((seconds % 3600) / 60);
      return h > 0 ? (h + 'h ' + m + 'm') : (m + 'm');
    }

    var d = Math.floor(seconds / 86400);
    var wh = Math.floor((seconds % 86400) / 3600);
    return d > 0 ? (d + 'd ' + wh + 'h') : (wh + 'h');
  }

  // The relative duration alone ("1h 53m") doesn't say WHEN that actually
  // is on the clock - this appends the absolute local time next to it, only
  // adding the date when the reset isn't today (the common case for the
  // 5-hour session bucket), so the compact mobile footer doesn't carry a
  // redundant date on every single line.
  function formatAbsolute(unixSeconds) {
    var d = new Date(unixSeconds * 1000);
    var now = new Date();
    var timeStr = d.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    var sameDay = d.getFullYear() === now.getFullYear() && d.getMonth() === now.getMonth() && d.getDate() === now.getDate();

    if (sameDay) return timeStr;

    return d.toLocaleDateString([], { month: 'short', day: 'numeric' }) + ' ' + timeStr;
  }

  // Mirrors App\Views\SessionRowView::relative_time() so "Captured ..."
  // reads the same relative-time style as the rest of the app,
  // instead of a raw ISO timestamp.
  function relativeTimeAgo(isoTimestamp) {
    var ms = Date.parse(isoTimestamp);
    if (isNaN(ms)) return isoTimestamp;

    var diff = Math.floor(Date.now() / 1000) - Math.floor(ms / 1000);
    if (diff < 60) return 'just now';
    if (diff < 3600) return Math.floor(diff / 60) + ' min ago';

    if (diff < 86400) {
      var h = Math.floor(diff / 3600);
      return h + ' hr' + (h > 1 ? 's' : '') + ' ago';
    }

    var d = Math.floor(diff / 86400);
    return d + ' day' + (d > 1 ? 's' : '') + ' ago';
  }

  function renderBucketText(bar, kind) {
    if (!bar || typeof bar.pct !== 'number') return null;
    var nowSeconds = Math.floor(Date.now() / 1000);
    var text = bar.pct + '%';
    var duration = null;
    var absolute = null;

    if (typeof bar.resets_at === 'number') {
      duration = formatDuration(bar.resets_at - nowSeconds, kind);
      text += ' · ' + duration;
      absolute = formatAbsolute(bar.resets_at);
    }

    return { pct: bar.pct, text: text, duration: duration, absolute: absolute };
  }

  function dashboardBucketText(info) {
    return info.pct + '%' + (info.duration ? ' (' + info.duration + ')' : '');
  }

  function tokenText(value) {
    return typeof value === 'number' ? Math.round(value).toLocaleString() : '0';
  }

  function showUnavailable(data) {
    el.title = '';
    el.innerHTML = '';
    var line = document.createElement('span');
    line.className = 'text-slate-600';
    line.textContent = 'Quota unavailable' + (data && data.message ? ': ' + data.message : '');
    el.appendChild(line);
  }

  function renderDashboardTable(data) {
    var agents = data.agents || {};
    var claude = agents.claude;
    var ag = agents.antigravity;
    var oc = agents.opencode;
    var codex = agents.codex;

    var hasClaude = claude && claude.quota;
    var hasAg = ag && ag.quota;
    var hasOc = oc && oc.quota;
    var hasCodex = codex && codex.quota;

    if (!hasClaude && !hasAg && !hasOc && !hasCodex) {
      showUnavailable(data);
      return;
    }

    var container = document.createElement('div');
    container.className = 'w-full overflow-x-auto';
    container.style.maxWidth = '100%';
    container.style.overflowX = 'auto';

    var table = document.createElement('table');
    table.className = 'w-full text-xs text-left border-collapse';
    table.style.minWidth = '34rem';

    var thead = document.createElement('thead');
    thead.innerHTML = '<tr class="text-slate-500 border-b border-slate-800">'
      + '<th class="py-1 pr-3 font-medium">Agent</th>'
      + '<th class="py-1 px-2 font-medium">5hr</th>'
      + '<th class="py-1 px-2 font-medium">Weekly</th>'
      + '<th class="py-1 pl-2 font-medium">Monthly</th>'
      + '</tr>';
    table.appendChild(thead);

    var tbody = document.createElement('tbody');
    tbody.className = 'divide-y divide-slate-800/60 font-medium';

    // Claude Code row(s) - one per distinct configured account (see
    // QuotaService::claude_profiles_to_scan_keyed()). Falls back to the
    // single `agents.claude` entry under the 'personal' key when
    // `claude_profiles` is absent (an older cached response shape), and
    // collapses to exactly today's single "Claude Code" row/label when
    // only one account is configured - no visible change for that,
    // still-common, single-account case.
    var claudeProfiles = data.claude_profiles || (claude ? { personal: claude } : {});
    var claudeProfileKeys = Object.keys(claudeProfiles);
    var multiProfile = claudeProfileKeys.length > 1;

    claudeProfileKeys.forEach(function (profileKey) {
      var entry = claudeProfiles[profileKey];
      var rowLabel = (!multiProfile || profileKey === 'personal') ? 'Claude Code' : 'Claude Code (' + profileKey + ')';

      var trClaude = document.createElement('tr');
      var tdC1 = document.createElement('td');
      tdC1.className = 'py-1.5 pr-3 text-slate-300 whitespace-nowrap font-medium';
      tdC1.textContent = rowLabel;
      trClaude.appendChild(tdC1);

      var tdC2 = document.createElement('td');
      tdC2.className = 'py-1.5 px-2 whitespace-nowrap';
      if (entry && entry.quota && entry.quota.session) {
        var sInfo = renderBucketText(entry.quota.session, 'session');
        tdC2.innerHTML = '<span class="' + pctColorClass(sInfo.pct) + '">' + escapeHtml(dashboardBucketText(sInfo)) + '</span>';
      } else {
        tdC2.innerHTML = '<span class="text-slate-600 font-normal">' + (entry && entry.message ? 'No data' : '—') + '</span>';
      }
      trClaude.appendChild(tdC2);

      var tdC3 = document.createElement('td');
      tdC3.className = 'py-1.5 px-2 whitespace-nowrap';
      if (entry && entry.quota && entry.quota.week_all) {
        var wInfo = renderBucketText(entry.quota.week_all, 'week');
        tdC3.innerHTML = '<span class="' + pctColorClass(wInfo.pct) + '">' + escapeHtml(dashboardBucketText(wInfo)) + '</span>';
      } else {
        tdC3.innerHTML = '<span class="text-slate-600 font-normal">' + (entry && entry.message ? 'No data' : '—') + '</span>';
      }
      trClaude.appendChild(tdC3);
      var tdC4 = document.createElement('td');
      tdC4.className = 'py-1.5 pl-2 whitespace-nowrap text-slate-600';
      tdC4.textContent = '—';
      trClaude.appendChild(tdC4);
      tbody.appendChild(trClaude);
    });

    // Antigravity row
    var trAg = document.createElement('tr');
    var agLabel = (ag && ag.label) ? ag.label : 'Antigravity';
    var tdA1 = document.createElement('td');
    tdA1.className = 'py-1.5 pr-3 text-slate-300 whitespace-nowrap font-medium';
    tdA1.textContent = agLabel;
    trAg.appendChild(tdA1);

    var tdA2 = document.createElement('td');
    tdA2.className = 'py-1.5 px-2 text-slate-600 whitespace-nowrap font-normal';
    tdA2.textContent = '—';
    trAg.appendChild(tdA2);

    var tdA3 = document.createElement('td');
    tdA3.className = 'py-1.5 px-2 whitespace-nowrap';
    if (ag && ag.quota && (ag.quota['gemini-weekly'] || ag.quota['3p-weekly'])) {
      var agItems = [];
      if (ag.quota['gemini-weekly']) {
        var gInfo = renderBucketText(ag.quota['gemini-weekly'], 'week');
        agItems.push('<div><span class="text-slate-400 font-normal">Gemini: </span><span class="' + pctColorClass(gInfo.pct) + '">' + escapeHtml(dashboardBucketText(gInfo)) + '</span></div>');
      }
      if (ag.quota['3p-weekly']) {
        var pInfo = renderBucketText(ag.quota['3p-weekly'], 'week');
        agItems.push('<div class="mt-0.5"><span class="text-slate-400 font-normal">Claude & GPT: </span><span class="' + pctColorClass(pInfo.pct) + '">' + escapeHtml(dashboardBucketText(pInfo)) + '</span></div>');
      }
      tdA3.innerHTML = agItems.join('');
    } else {
      tdA3.innerHTML = '<span class="text-slate-600 font-normal">' + (ag && ag.message ? 'No data' : '—') + '</span>';
    }
    trAg.appendChild(tdA3);
    var tdA4 = document.createElement('td');
    tdA4.className = 'py-1.5 pl-2 whitespace-nowrap text-slate-600';
    tdA4.textContent = '—';
    trAg.appendChild(tdA4);
    tbody.appendChild(trAg);

    // Codex exposes the same primary/secondary account windows as its
    // native app-server client: normally 5-hour and weekly.
    var trCodex = document.createElement('tr');
    var tdX1 = document.createElement('td');
    tdX1.className = 'py-1.5 pr-3 text-cyan-300 whitespace-nowrap font-medium';
    tdX1.textContent = (codex && codex.label) ? codex.label : 'Codex';
    trCodex.appendChild(tdX1);
    var tdX2 = document.createElement('td');
    tdX2.className = 'py-1.5 px-2 whitespace-nowrap';
    if (codex && codex.quota && codex.quota.session) {
      var xSession = renderBucketText(codex.quota.session, 'session');
      tdX2.innerHTML = '<span class="' + pctColorClass(xSession.pct) + '">' + escapeHtml(dashboardBucketText(xSession)) + '</span>';
    } else {
      tdX2.innerHTML = '<span class="text-slate-600 font-normal">No data</span>';
    }
    trCodex.appendChild(tdX2);
    var tdX3 = document.createElement('td');
    tdX3.className = 'py-1.5 px-2 whitespace-nowrap';
    if (codex && codex.quota && codex.quota.week_all) {
      var xWeek = renderBucketText(codex.quota.week_all, 'week');
      tdX3.innerHTML = '<span class="' + pctColorClass(xWeek.pct) + '">' + escapeHtml(dashboardBucketText(xWeek)) + '</span>';
    } else {
      tdX3.innerHTML = '<span class="text-slate-600 font-normal">—</span>';
    }
    trCodex.appendChild(tdX3);
    var tdX4 = document.createElement('td');
    tdX4.className = 'py-1.5 pl-2 whitespace-nowrap text-slate-600';
    tdX4.textContent = '—';
    trCodex.appendChild(tdX4);
    tbody.appendChild(trCodex);

    // OpenCode: the opencode-go account-wide rolling/weekly/monthly windows
    // (how much of the real quota is left + when it resets) go in the three
    // columns like the Claude/Codex rows, with a compact cumulative-usage
    // summary line below (local opencode.db cost/token/session totals).
    var trOc = document.createElement('tr');
    var ocLabel = (oc && oc.label) ? oc.label : 'OpenCode';
    var tdO1 = document.createElement('td');
    tdO1.className = 'py-1.5 pr-3 text-slate-300 whitespace-nowrap font-medium';
    tdO1.textContent = ocLabel;
    trOc.appendChild(tdO1);

    var ocQ = (oc && oc.quota) ? oc.quota : null;
    var ocNoData = (oc && oc.message) ? 'No data' : '—';

    var tdO2 = document.createElement('td');
    tdO2.className = 'py-1.5 px-2 whitespace-nowrap';
    if (ocQ && ocQ.session && typeof ocQ.session.pct === 'number') {
      var ocSess = renderBucketText(ocQ.session, 'session');
      tdO2.innerHTML = '<span class="' + pctColorClass(ocSess.pct) + '">' + escapeHtml(dashboardBucketText(ocSess)) + '</span>';
    } else {
      tdO2.innerHTML = '<span class="text-slate-600 font-normal">' + escapeHtml(ocNoData) + '</span>';
    }
    trOc.appendChild(tdO2);

    var tdO3 = document.createElement('td');
    tdO3.className = 'py-1.5 px-2 whitespace-nowrap';
    if (ocQ && ocQ.week_all && typeof ocQ.week_all.pct === 'number') {
      var ocWeek = renderBucketText(ocQ.week_all, 'week');
      tdO3.innerHTML = '<span class="' + pctColorClass(ocWeek.pct) + '">' + escapeHtml(dashboardBucketText(ocWeek)) + '</span>';
    } else {
      tdO3.innerHTML = '<span class="text-slate-600 font-normal">' + escapeHtml(ocNoData) + '</span>';
    }
    trOc.appendChild(tdO3);

    var tdO4 = document.createElement('td');
    tdO4.className = 'py-1.5 pl-2 whitespace-nowrap';
    if (ocQ && ocQ.month_all && typeof ocQ.month_all.pct === 'number') {
      var ocMonth = renderBucketText(ocQ.month_all, 'week');
      tdO4.innerHTML = '<span class="' + pctColorClass(ocMonth.pct) + '">' + escapeHtml(dashboardBucketText(ocMonth)) + '</span>';
    } else {
      tdO4.innerHTML = '<span class="text-slate-600 font-normal">' + escapeHtml(ocNoData) + '</span>';
    }
    trOc.appendChild(tdO4);
    tbody.appendChild(trOc);

    // Cumulative-usage summary line - only shown when there's at least one
    // local total to report.
    if (ocQ && (typeof ocQ.cost === 'number' || typeof ocQ.tokens_input === 'number' || typeof ocQ.session_count === 'number')) {
      var trOcCum = document.createElement('tr');
      var tdCum = document.createElement('td');
      tdCum.colSpan = 4;
      tdCum.className = 'py-1.5 px-2 whitespace-nowrap text-xs text-slate-500';
      var cumParts = [];
      if (typeof ocQ.cost === 'number') cumParts.push('Cost $' + ocQ.cost.toFixed(2));
      if (typeof ocQ.tokens_input === 'number') cumParts.push('In ' + tokenText(ocQ.tokens_input));
      if (typeof ocQ.tokens_output === 'number') cumParts.push('Out ' + tokenText(ocQ.tokens_output));
      if (typeof ocQ.session_count === 'number') cumParts.push(ocQ.session_count + ' sessions');
      tdCum.textContent = cumParts.join(' · ');
      trOcCum.appendChild(tdCum);
      tbody.appendChild(trOcCum);
    }

    table.appendChild(tbody);
    container.appendChild(table);

    // Append optional per-session context line when present
    if (data.context && typeof data.context.pct === 'number') {
      var contextLine = document.createElement('div');
      contextLine.className = 'mt-2 pt-2 border-t border-slate-800/60 ' + pctColorClass(data.context.pct);
      contextLine.textContent = 'This session: ctx ' + data.context.pct + '%';
      container.appendChild(contextLine);
    }

    var claudeProfileCapturedAt = null;
    claudeProfileKeys.some(function (profileKey) {
      var entry = claudeProfiles[profileKey];
      if (entry && entry.quota && entry.quota.captured_at) {
        claudeProfileCapturedAt = entry.quota.captured_at;
        return true;
      }
      return false;
    });

    var capturedAt = claudeProfileCapturedAt || (ag && ag.quota && ag.quota.captured_at) || (codex && codex.quota && codex.quota.captured_at) || (oc && oc.quota && oc.quota.captured_at);
    el.title = capturedAt ? 'Captured ' + relativeTimeAgo(capturedAt) : '';
    el.innerHTML = '';
    el.appendChild(container);
  }

  function render(data) {
    if (!data || (!data.quota && !data.agents)) {
      showUnavailable(data);
      return;
    }

    if (data.agents) {
      renderDashboardTable(data);
      return;
    }

    showUnavailable(data);
  }

  var loading = false;

  function load() {
    if (loading) return; // a slow request is still out there - don't pile another on top of it
    loading = true;

    var url = '/quota.php' + (sessionName !== '' ? ('?session=' + encodeURIComponent(sessionName)) : '');

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(render)
      .catch(function () {
        showUnavailable(null);
      })
      .finally(function () {
        loading = false;
      });
  }

  load();
  setInterval(load, 60000);
})();
