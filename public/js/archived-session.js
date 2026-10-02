// @ts-check
// The archived (dormant) session read-only view's only interactive piece -
// "Load older messages". Deliberately much smaller than session.js: no
// compose bar, no live polling, no mode toggle - a dormant session never
// changes, so there's nothing else here that needs to stay "live". Unlike
// session.php's own load-more (which fetches raw JSON entries and renders
// them client-side via session.js's own renderEntry(), since a live view
// needs that same renderer for polled-in new messages too), this fetches
// pre-rendered HTML straight from the server (see SessionController::
// archivedHistoryFragment()) - no client-side rendering logic to duplicate
// at all.
(function () {
  // Swipe right anywhere navigates back to the dashboard - this page has no
  // sidebar to open/close (session.php's own version of this gesture, in
  // sidebar.js, also handles that), so it's a plain one-way navigation.
  // Registered unconditionally, before the load-more guard below, since it
  // has nothing to do with whether the transcript itself loaded (found live
  // 2026-09-30: this page previously had no swipe-gesture JS at all and
  // relied entirely on the browser/OS's own native edge-swipe-back gesture,
  // which isn't available in every context - a home-screen-installed iOS
  // PWA running in standalone display mode has no browser chrome to swipe
  // from, so swipe-right did nothing there).
  initHorizontalSwipe({
    onSwipeRight: function () {
      window.location.href = '/';
    }
  });

  var btn = document.getElementById('load-more-btn');
  var list = document.getElementById('history-list');

  if (!btn || !list) {
    return;
  }

  btn.addEventListener('click', function () {
    var agentSessionId = btn.dataset.agentSessionId;
    var before = btn.dataset.before;

    btn.disabled = true;
    btn.textContent = 'Loading…';

    var url = '/archived_session_history_fragment.php?agent_session_id=' + encodeURIComponent(agentSessionId) + '&limit=30'
      + (before ? '&before=' + encodeURIComponent(before) : '');

    fetch(url, { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          btn.textContent = (data && data.message) || 'Could not load more.';
          return;
        }

        var wrapper = document.createElement('div');
        wrapper.innerHTML = data.html;

        while (wrapper.firstChild) {
          list.insertBefore(wrapper.firstChild, list.firstChild);
        }

        if (data.has_more && data.next_before !== null) {
          btn.dataset.before = data.next_before;
          btn.disabled = false;
          btn.textContent = 'Load older messages';
        } else {
          btn.classList.add('hidden');
        }
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Network error - try again';
      });
  });
})();

// --- landing on a search result (see the search box below and
// SessionController::showArchived()'s own jump_line handling) - the
// page's own initial history load already ends exactly at jumpLine (a
// full navigation, not an in-place fetch), so this only has to find and
// scroll to it. No fade-on-view machinery here (unlike session.js's own
// markNewContent()/newEntryObserver) - a dormant transcript never gets
// new content to distinguish "new" from, so a plain persistent ring is
// enough; Tailwind's CDN build (already loaded globally - see layout.php)
// makes these arbitrary utility classes work with no CSS of our own to
// author or keep in sync. ---
(function () {
  var bootstrap = window.SESSIONEER_ARCHIVED_BOOTSTRAP || {};
  var list = document.getElementById('history-list');

  if (!list || !bootstrap.jumpLine) {
    return;
  }

  var jumpTarget = list.querySelector('[data-line="' + bootstrap.jumpLine + '"]');

  if (jumpTarget) {
    // Reveal it first - a jump target hidden inside a collapsed tool-call
    // entry <details> gets a meaningless zeroed-out rect while closed, landing
    // the scroll on the wrong spot (see openAncestorDetails() in
    // common.js for the full explanation; found live 2026-08-20).
    openAncestorDetails(jumpTarget);

    // A plain window.scrollTo() computed from the element's own rect, not
    // scrollIntoView() - see session.js's own jump-scroll comment for why
    // (found live 2026-08-09: scrollIntoView() silently no-op'd in at
    // least one real headless-Chrome automation context).
    var jumpTargetRect = jumpTarget.getBoundingClientRect();
    var jumpScrollTop = window.scrollY + jumpTargetRect.top - (window.innerHeight / 2) + (jumpTargetRect.height / 2);
    window.scrollTo({ top: Math.max(0, jumpScrollTop), behavior: 'auto' });
    jumpTarget.classList.add('ring-2', 'ring-sky-500', 'ring-offset-2', 'ring-offset-slate-950', 'rounded-lg');
  }
})();

// --- search this (read-only) transcript - same server-side full-text
// search as session.js's own sidebar box (see SessionController::
// archivedSearch()'s doc comment), just keyed by agent_session_id
// instead of a live tmux name, and with no sidebar to live in here - this
// view has none. ---
(function () {
  var bootstrap = window.SESSIONEER_ARCHIVED_BOOTSTRAP || {};
  var input = document.getElementById('session-search-input');
  var results = document.getElementById('session-search-results');

  if (!input || !results || !bootstrap.agentSessionId) {
    return;
  }

  var debounceTimer = null;
  var abortController = null;

  function renderResults(matches, query) {
    if (!matches || matches.length === 0) {
      results.innerHTML = '<div class="text-xs text-slate-500">No matches.</div>';
      return;
    }

    results.innerHTML = matches.map(function (m) {
      var roleLabel = m.role === 'user' ? 'User' : (m.role === 'assistant' ? 'Claude Code' : (m.kind === 'tool_use' ? 'Tool call' : 'Tool output'));
      var timeHtml = typeof m.timestamp === 'number' ? '<span class="text-slate-600"> &middot; ' + escapeHtml(relativeTimeLabel(m.timestamp)) + '</span>' : '';
      return '<a href="/archived_session.php?agent_session_id=' + encodeURIComponent(bootstrap.agentSessionId) + '&jump_line=' + encodeURIComponent(m.line) + '"'
        + ' class="block rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 px-2 py-1.5">'
        + '<div class="text-[11px] text-slate-500 mb-0.5">' + escapeHtml(roleLabel) + timeHtml + '</div>'
        + '<div class="text-xs text-slate-300 break-words">' + highlightSnippet(m.snippet, query) + '</div>'
        + '</a>';
    }).join('');
  }

  wireClearButton(input, document.getElementById('session-search-input-clear-btn'));

  input.addEventListener('input', function () {
    var query = input.value.trim();

    clearTimeout(debounceTimer);

    if (abortController) {
      abortController.abort();
    }

    if (query === '') {
      results.innerHTML = '';
      return;
    }

    debounceTimer = setTimeout(function () {
      abortController = new AbortController();

      fetch('/archived_session_search.php?agent_session_id=' + encodeURIComponent(bootstrap.agentSessionId) + '&q=' + encodeURIComponent(query), {
        credentials: 'same-origin',
        signal: abortController.signal
      })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) {
            results.innerHTML = '<div class="text-xs text-red-400">' + escapeHtml((data && data.message) || 'Search failed.') + '</div>';
            return;
          }

          renderResults(data.matches, query);
        })
        .catch(function (e) {
          if (e && e.name === 'AbortError') {
            return;
          }

          results.innerHTML = '<div class="text-xs text-red-400">Network error - search failed.</div>';
        });
    }, 400);
  });
})();
