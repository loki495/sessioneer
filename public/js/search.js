// @ts-check
// Sidebar search: server-side full-text search, either scoped to this
// session's ENTIRE transcript (see SessionController::search()'s own doc
// comment for why this can't just filter the sidebar's own rendered DOM
// the way index.js's archived-list filter does) or, via the scope radio,
// the same dashboard-wide search index.js's own search box uses
// (SessionService::search_transcripts(), live AND archived sessions
// alike) - debounced, since every keystroke would otherwise round-trip to
// the host-agent's own grep for nothing. Clicking a result is a full page
// navigation (jump_line), not an in-place fetch: simplest way to reuse
// the exact same SSR "load the page ending at this line" path an
// ordinary page load already takes - including for a global result
// pointing at a DIFFERENT (or archived) session entirely, so the right
// page loads with the right window of history already there rather than
// needing to fetch anything further client-side.
//
// Plain global functions/vars, same convention as common.js/scroll.js/
// highlights.js/sidebar.js - fully self-contained, no dependency on (or
// from) any other extracted module. Reads window.SESSIONEER_BOOTSTRAP.session
// directly (same as sidebar.js's own sessionName derivation) rather than
// depending on session.js's local. Extracted from session.js.
var sessionSearchInput = document.getElementById('session-search-input');
var sessionSearchResults = document.getElementById('session-search-results');
var sessionSearchScopeGlobalRadio = document.getElementById('session-search-scope-global');
var sessionSearchDebounceTimer = null;
var sessionSearchAbortController = null;

function isGlobalSearchScope() {
  return !!(sessionSearchScopeGlobalRadio && sessionSearchScopeGlobalRadio.checked);
}

function renderSessionSearchResults(matches, query) {
  if (!matches || matches.length === 0) {
    sessionSearchResults.innerHTML = '<div class="text-xs text-slate-500">No matches.</div>';
    return;
  }

  sessionSearchResults.innerHTML = matches.map(function (m) {
    var roleLabel = m.role === 'user' ? 'You' : (m.role === 'assistant' ? 'Claude' : (m.kind === 'tool_use' ? 'Tool call' : 'Tool output'));
    var timeHtml = typeof m.timestamp === 'number' ? '<span class="text-slate-600"> &middot; ' + escapeHtml(relativeTimeLabel(m.timestamp)) + '</span>' : '';
    return '<button type="button" class="session-search-result-btn w-full text-left rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 px-2 py-1.5" data-line="' + m.line + '">'
      + '<div class="text-[11px] text-slate-500 mb-0.5">' + escapeHtml(roleLabel) + timeHtml + '</div>'
      + '<div class="text-xs text-slate-300 break-words">' + highlightSnippet(m.snippet, query) + '</div>'
      + '</button>';
  }).join('');
}

// Global mode's own results shape (one entry per matching session, each
// with its own matches array) mirrors index.js's dashboard-wide search
// exactly - includes cwd (global results need to show
// it, since without a per-session sidebar it's the only way to tell
// sessions with the same title apart) and a "live" badge, but not that
// search's Archive/Unarchive action forms - this is a quick jump-to-
// result box, not the dashboard's own management UI.
function renderGlobalSearchResults(results, query) {
  if (!results || results.length === 0) {
    sessionSearchResults.innerHTML = '<div class="text-xs text-slate-500">No matches.</div>';
    return;
  }

  sessionSearchResults.innerHTML = results.map(function (r) {
    var url = r.session_name
      ? '/session.php?session=' + encodeURIComponent(r.session_name) + '&jump_line=' + encodeURIComponent(r.matches[0].line)
      : '/archived_session.php?agent_session_id=' + encodeURIComponent(r.agent_session_id) + '&jump_line=' + encodeURIComponent(r.matches[0].line);
    var cwdHtml = r.cwd ? '<div class="text-[11px] text-slate-600 truncate mt-0.5">' + escapeHtml(r.cwd) + '</div>' : '';
    var matchesHtml = r.matches.map(function (m) {
      var roleLabel = m.role === 'user' ? 'You' : (m.role === 'assistant' ? 'Claude' : (m.kind === 'tool_use' ? 'Tool call' : 'Tool output'));
      var timeHtml = typeof m.timestamp === 'number' ? '<span class="text-slate-600"> &middot; ' + escapeHtml(relativeTimeLabel(m.timestamp)) + '</span>' : '';
      return '<div class="text-xs text-slate-400 mt-0.5 break-words"><span class="text-slate-500">' + escapeHtml(roleLabel) + ':</span>' + timeHtml + ' ' + highlightSnippet(m.snippet, query) + '</div>';
    }).join('');

    return '<a href="' + url + '" class="block w-full text-left rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 px-2 py-1.5">'
      + '<div class="flex items-center gap-1.5 text-xs font-medium text-slate-200 truncate">'
      + escapeHtml(r.title)
      + (r.session_name ? ' <span class="shrink-0 text-[10px] text-emerald-400 border border-emerald-800/60 rounded-full px-1 py-0.5">live</span>' : '')
      + '</div>'
      + cwdHtml
      + matchesHtml
      + '</a>';
  }).join('');
}

function runSessionSearch() {
  var query = sessionSearchInput.value.trim();

  clearTimeout(sessionSearchDebounceTimer);

  if (sessionSearchAbortController) {
    sessionSearchAbortController.abort();
  }

  if (query === '') {
    sessionSearchResults.innerHTML = '';
    return;
  }

  var global = isGlobalSearchScope();

  sessionSearchDebounceTimer = setTimeout(function () {
    sessionSearchAbortController = new AbortController();

    var url = global
      ? '/search_sessions.php?q=' + encodeURIComponent(query)
      : '/session_search.php?session=' + encodeURIComponent(window.SESSIONEER_BOOTSTRAP.session) + '&q=' + encodeURIComponent(query);

    fetch(url, { credentials: 'same-origin', signal: sessionSearchAbortController.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          sessionSearchResults.innerHTML = '<div class="text-xs text-red-400">' + escapeHtml((data && data.message) || 'Search failed.') + '</div>';
          return;
        }

        if (global) {
          renderGlobalSearchResults(data.results, query);
        } else {
          renderSessionSearchResults(data.matches, query);
        }
      })
      .catch(function (e) {
        if (e && e.name === 'AbortError') {
          return;
        }

        sessionSearchResults.innerHTML = '<div class="text-xs text-red-400">Network error - search failed.</div>';
      });
  }, 400);
}

if (sessionSearchInput && sessionSearchResults) {
  sessionSearchInput.addEventListener('input', runSessionSearch);
  wireClearButton(sessionSearchInput, document.getElementById('session-search-input-clear-btn'));

  // Flipping the scope radio re-runs immediately (no debounce wait, and
  // whatever the previous mode's request had in flight is aborted by
  // runSessionSearch() itself) against whatever's already typed -
  // switching modes IS the deliberate action here, same as typing a
  // fresh keystroke.
  document.querySelectorAll('input[name="session-search-scope"]').forEach(function (radio) {
    radio.addEventListener('change', function () {
      if (sessionSearchInput.value.trim() !== '') {
        runSessionSearch();
      }
    });
  });

  sessionSearchResults.addEventListener('click', function (e) {
    var resultBtn = closestEventTarget(e, '.session-search-result-btn');

    if (resultBtn) {
      window.location.href = '/session.php?session=' + encodeURIComponent(window.SESSIONEER_BOOTSTRAP.session) + '&jump_line=' + encodeURIComponent(resultBtn.dataset.line);
    }
  });
}
