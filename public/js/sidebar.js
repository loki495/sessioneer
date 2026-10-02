// @ts-check
// The slideable sidebar (other sessions' status/prompt, uploaded files,
// plan/handoff files, and the confirm-before-answer/show-subagent
// settings it hosts) - plain global functions/vars, same convention as
// common.js/scroll.js/highlights.js. Own independent
// document.getElementById() lookups and reads window.SESSIONEER_BOOTSTRAP
// directly (same as session.js's own sessionName/csrfToken derivation)
// rather than depending on session.js's locals. Extracted from session.js
// 2026-08-24, fifth cut of the "split session.js into modules" pass.
var sessionName = window.SESSIONEER_BOOTSTRAP.session;
var csrfToken = window.SESSIONEER_BOOTSTRAP.csrfToken;

var appShell = document.getElementById('app-shell');
var sidebarToggleBtn = document.getElementById('sidebar-toggle-btn');
var sidebarCloseBtn = document.getElementById('sidebar-close-btn');
var sidebarOverlay = document.getElementById('sidebar-overlay');
var sidebar = document.getElementById('sidebar');
var sidebarList = document.getElementById('sidebar-list');
var sidebarNotifyDot = document.getElementById('sidebar-notify-dot');

// Only in-flight fetches actually created by session.js's OWN poll cycle
// (startPolling()/stopPolling(), not extracted this pass) get aborted -
// this file just needs an AbortController to exist before its own first
// call (refreshSidebarNotification() below) can run, since that can fire
// from a poll cycle that started before this variable would otherwise be
// created. session.js reassigns this (not with `var`, so it falls through
// to this same global - same pattern already used for pageContent/
// currentDivider) each time polling actually (re)starts.
var pollAbortController = new AbortController();

// Setting: whether answering a plain prompt option asks for confirmation
// first. CONFIRM_BEFORE_ANSWER_KEY/shouldConfirmBeforeAnswer() are shared
// with index.js - see common.js. Shared localStorage key with index.php's
// dashboard rows, which answer prompts too but have no sidebar of their
// own to host the checkbox.
var confirmBeforeAnswerToggle = document.getElementById('confirm-before-answer-toggle');

if (confirmBeforeAnswerToggle) {
  confirmBeforeAnswerToggle.checked = shouldConfirmBeforeAnswer();

  confirmBeforeAnswerToggle.addEventListener('change', function () {
    try {
      window.localStorage.setItem(CONFIRM_BEFORE_ANSWER_KEY, confirmBeforeAnswerToggle.checked ? '1' : '0');
    } catch (e) {}
  });
}

// Setting: whether subagent call/report blocks show in the transcript at
// all - a body-level class + CSS rule (see <style> in <head>) so it
// applies to blocks the poll renders later too, without re-walking the
// DOM. Per-session (keyed by sessionName, same pattern as COMPOSE_DRAFT_KEY
// in session.js) - a global key meant one session's "hide subagent output"
// choice silently applied to every other session too, which read as
// broken when a different session's own output just wasn't showing.
// Regular (non-subagent) tool calls/outputs don't use this any more -
// since 2026-08-08 those are always grouped into a collapsible "N tool
// calls" run instead (see groupToolCalls() in session.js), whose own
// <details> is its own show/hide affordance. The two separate call/output
// toggles this used to be also merged into this one, same date - a
// subagent call and its own report are little enough traffic that
// splitting them wasn't worth the extra checkbox.
var SHOW_SUBAGENT_KEY = 'sessioneer-show-subagent-' + sessionName;

function shouldShowSubagent() {
  try {
    return window.localStorage.getItem(SHOW_SUBAGENT_KEY) !== '0';
  } catch (e) {
    return true;
  }
}

// x-cloak-style: subagent content is hidden by DEFAULT in CSS (see
// session.php's own <style> block) and only revealed once this class is
// added, rather than starting visible and being hidden by a hide-
// subagent class - avoids a flash of real subagent content for anyone
// who's actually turned the toggle off, since this only ever runs after
// first paint (found live 2026-08-08).
function applyShowSubagent(show) {
  document.body.classList.toggle('show-subagent', show);
}

var showSubagentToggle = document.getElementById('show-subagent-toggle');

if (showSubagentToggle) {
  var showSubagent = shouldShowSubagent();
  showSubagentToggle.checked = showSubagent;
  applyShowSubagent(showSubagent);

  showSubagentToggle.addEventListener('change', function () {
    applyShowSubagent(showSubagentToggle.checked);

    try {
      window.localStorage.setItem(SHOW_SUBAGENT_KEY, showSubagentToggle.checked ? '1' : '0');
    } catch (e) {}
  });
}

// Setting: show/hide orchestrator-worker "worker" sessions in this drawer's
// own "other sessions" list - SHOW_WORKER_SESSIONS_KEY/
// shouldShowWorkerSessions() shared with index.js, see common.js. Unlike
// show-subagent (a CSS class flip), this list comes from a fresh
// /sessions_list.php fetch each time it's (re)loaded, so re-running
// loadSidebarList() IS the "apply" step - no separate DOM-filtering needed.
var showWorkerSessionsToggle = document.getElementById('show-worker-sessions-toggle');

if (showWorkerSessionsToggle) {
  showWorkerSessionsToggle.checked = shouldShowWorkerSessions();

  showWorkerSessionsToggle.addEventListener('change', function () {
    try {
      window.localStorage.setItem(SHOW_WORKER_SESSIONS_KEY, showWorkerSessionsToggle.checked ? '1' : '0');
    } catch (e) {}
    loadSidebarList();
  });
}

// Lets the toggle button itself signal severity without opening the
// drawer: amber if another session is blocked (waiting on a prompt) -
// always live, never "seen" since it's still actionable right now; else
// emerald if another session just finished all its work (went idle) and
// that finish hasn't been observed yet (see markOthersSeen()); else no
// dot at all. Persisted per-session state (SIDEBAR_SESSION_STATE_KEY)
// is what lets "just finished" survive across poll cycles until the
// sidebar is actually opened and looked at, and what stops an idle
// session that's simply always been idle from lighting up green on
// first-ever observation (a transition has to be detected, not just a
// state).
var SIDEBAR_SESSION_STATE_KEY = 'sessioneer-sidebar-session-state';

function readSidebarSessionState() {
  try {
    var raw = window.localStorage.getItem(SIDEBAR_SESSION_STATE_KEY);
    return raw ? JSON.parse(raw) : {};
  } catch (e) {
    return {};
  }
}

function writeSidebarSessionState(state) {
  try {
    window.localStorage.setItem(SIDEBAR_SESSION_STATE_KEY, JSON.stringify(state));
  } catch (e) {}
}

function otherSessionState(s) {
  if (s.blocked_reason) {
    return 'blocked';
  }
  if (s.working) {
    return 'working';
  }
  return 'idle';
}

// --- Per-row "done" badge: distinct from the aggregate sidebar-notify-
// dot above, which clears the moment the sidebar is merely opened/
// glanced at. This is Andres's own ask (2026-08-18): a session that just
// finished (working/blocked -> idle) should read "done" instead of
// "idle" in the list, and stay that way until he actually visits THAT
// session's own page - not just sees it listed. Shared across every
// session.php tab via localStorage (same mechanism as
// SIDEBAR_SESSION_STATE_KEY above, just a separate key/clearing rule),
// keyed by session name so any tab can update any other session's
// entry, but only that session's OWN page load ever acknowledges it. ---
var SESSION_DONE_STATE_KEY = 'sessioneer-session-done-state';

function readSessionDoneState() {
  try {
    var raw = window.localStorage.getItem(SESSION_DONE_STATE_KEY);
    return raw ? JSON.parse(raw) : {};
  } catch (e) {
    return {};
  }
}

function writeSessionDoneState(state) {
  try {
    window.localStorage.setItem(SESSION_DONE_STATE_KEY, JSON.stringify(state));
  } catch (e) {}
}

// Visiting this session's own page IS the acknowledgment - forgets
// whatever state it was last observed in (so a stale "done" elsewhere
// doesn't linger) without needing to know its current state yet (that's
// only known once session_detail.php's first poll lands) - the next
// genuine working/blocked -> idle transition, observed by some OTHER
// tab after this, is what re-arms "done" again.
(function acknowledgeThisSessionVisited() {
  var state = readSessionDoneState();
  state[sessionName] = { lastState: null, done: false };
  writeSessionDoneState(state);
})();

// Shared by refreshSidebarNotification() and loadSidebarList() - same
// "recompute from this poll's fresh data" pattern as
// processOtherSessions() above, just writing to its own separate store
// with its own clearing rule (see the block comment above).
function updateSessionDoneState(others) {
  var stored = readSessionDoneState();
  // Starts as a COPY of every existing entry, not an empty object - this
  // page's own self-acknowledged entry (see acknowledgeThisSessionVisited()
  // above) never appears in "others" (that list always excludes
  // sessionName), so rebuilding from others alone would silently wipe it
  // out on this page's very next poll cycle - found live 2026-08-18,
  // confirmed via a real two-tab check (localStorage is shared across
  // every session.php tab on the same origin). Only entries for sessions
  // actually present in this poll's data get overwritten below.
  var next = {};

  for (var name in stored) {
    if (Object.prototype.hasOwnProperty.call(stored, name)) {
      next[name] = stored[name];
    }
  }

  others.forEach(function (s) {
    var state = otherSessionState(s);
    var prev = stored[s.name];
    var done = state === 'idle' && !!(prev && prev.done);

    if (state === 'idle' && prev && prev.lastState && prev.lastState !== 'idle') {
      done = true;
    }

    next[s.name] = { lastState: state, done: done };
  });

  writeSessionDoneState(next);

  return next;
}

function applySidebarNotifyDot(kind) {
  if (!sidebarNotifyDot) {
    return;
  }
  sidebarNotifyDot.classList.toggle('hidden', kind === null);
  sidebarNotifyDot.classList.toggle('bg-amber-400', kind === 'blocked');
  sidebarNotifyDot.classList.toggle('bg-emerald-400', kind === 'finished');
}

// Shared by refreshSidebarNotification() (every poll cycle, markSeen
// false) and loadSidebarList() (sidebar actually opened, markSeen true -
// that's the "look" that clears the green dot for any idle session it
// just displayed).
function processOtherSessions(others, markSeen) {
  var stored = readSidebarSessionState();
  var next = {};
  var anyBlocked = false;
  var anyUnseenFinished = false;

  others.forEach(function (s) {
    var state = otherSessionState(s);
    var prev = stored[s.name];
    var unseen = !!(prev && prev.unseen);

    if (state === 'idle') {
      if (prev && prev.state !== 'idle') {
        unseen = true;
      }
      if (markSeen) {
        unseen = false;
      }
    } else {
      unseen = false;
      if (state === 'blocked') {
        anyBlocked = true;
      }
    }

    next[s.name] = { state: state, unseen: unseen };

    if (state === 'idle' && unseen) {
      anyUnseenFinished = true;
    }
  });

  writeSidebarSessionState(next);
  applySidebarNotifyDot(anyBlocked ? 'blocked' : (anyUnseenFinished ? 'finished' : null));
}

function refreshSidebarNotification() {
  if (!sidebarNotifyDot) {
    return Promise.resolve();
  }

  return fetch('/sidebar_sessions.php?session=' + encodeURIComponent(sessionName), { credentials: 'same-origin', signal: pollAbortController.signal })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data.ok) {
        return;
      }

      var showWorkerSessions = shouldShowWorkerSessions();
      var others = (data.sessions || []).filter(function (s) {
        return s.name !== sessionName && (showWorkerSessions || s.kind !== 'worker');
      });
      processOtherSessions(others, false);
      updateSessionDoneState(others);
    })
    .catch(function () {});
}

// The notification dot only needs to reflect other sessions' state within
// a "glance" freshness, not the transcript's own pollIntervalMs (settable
// as low as 1s). Riding that fast cycle meant every tick also re-ran the
// full, expensive list_all_sessions() scan (a tmux capture-pane per
// tracked session plus a /proc walk) purely to maybe flip a dot for
// sessions this page isn't even showing. Found live 2026-08-11: with
// several sessions/tabs open, that redundant scan on every fast tick was
// visibly hanging the page for a second or two. Its own fixed, much
// slower interval fixes that without losing any responsiveness that
// matters. Lives here rather than in session.js because that's where
// refreshSidebarNotification() itself lives - session.js only starts and
// stops it, from startPolling()/stopPolling().
var SIDEBAR_NOTIFY_POLL_INTERVAL_MS = 15000;
var sidebarNotifyPollTimer = null;
var sidebarNotifyPollingActive = false;

function startSidebarNotifyPolling() {
  if (sidebarNotifyPollingActive) {
    return;
  }

  sidebarNotifyPollingActive = true;

  function cycle() {
    refreshSidebarNotification().finally(function () {
      if (sidebarNotifyPollingActive) {
        sidebarNotifyPollTimer = setTimeout(cycle, SIDEBAR_NOTIFY_POLL_INTERVAL_MS);
      }
    });
  }

  cycle();
}

function stopSidebarNotifyPolling() {
  sidebarNotifyPollingActive = false;

  if (sidebarNotifyPollTimer !== null) {
    clearTimeout(sidebarNotifyPollTimer);
    sidebarNotifyPollTimer = null;
  }
}


// Shared by loadSidebarList() (sidebar just opened, or a prompt was just
// answered from within it - always renders) and refreshSidebarList()
// (the regular poll cycle while the sidebar stays open - dedups against
// lastRenderedSidebarHtml below, the same "skip the rebuild if nothing
// actually changed" rule renderThinkingIndicator() etc. already follow in
// session.js, so an in-progress interaction in one of these cards - e.g.
// typing a free-text reply to another session's prompt - only ever gets
// torn out when something in the list genuinely changed, not every tick).
//
// markSeen is passed straight through to processOtherSessions(): true for
// an explicit open (opening the sidebar IS "looking", clears the green
// dot for anything about to be shown), false for a background poll tick
// (glancing at an unopened... no, an ALREADY-open panel isn't a fresh
// "look" the same way, but the poll tick isn't the moment of intent
// either - kept false to match refreshSidebarNotification()'s own use,
// the state a poll tick reflects is not itself a user action).
//
// Returns 'ok' (rendered), 'unchanged' (data.ok, but the html was
// identical to what's already shown - NOT an error; the caller must not
// treat this the same as 'error', or it would stomp a correct,
// already-rendered list with an error message any time a poll tick
// happens to see the exact same state loadSidebarList()'s own fetch is
// about to land too - found live 2026-09-28 running
// test_sidebar_prompt_answer_browser.php: that exact conflation (both
// were plain `false`) wiped out session C's fully-rendered row right
// after answering B, because the poll-driven refresh had already
// rendered the identical post-answer HTML a moment earlier), or 'error'
// (data.ok was false).
function renderSidebarSessions(data, markSeen) {
  if (!data.ok) {
    return 'error';
  }

  var showWorkerSessions = shouldShowWorkerSessions();
  var others = (data.sessions || []).filter(function (s) {
    return s.name !== sessionName && (showWorkerSessions || s.kind !== 'worker');
  });
  processOtherSessions(others, markSeen);
  var doneState = updateSessionDoneState(others);
  var html = others.length === 0
    ? '<div class="px-1 text-slate-500">No other sessions.</div>'
    : (data.sessions_html || '<div class="px-1 text-slate-500">No other sessions.</div>');

  if (html === lastRenderedSidebarHtml) {
    return 'unchanged';
  }

  lastRenderedSidebarHtml = html;
  sidebarList.innerHTML = html;

  // Apply the per-row "done" badge overlay after rendering:
  // For each session marked done in doneState, find its row wrapper
  // (data-session="<name>") and swap the status dot/text classes to
  // the emerald "done" treatment.
  for (var name in doneState) {
    if (Object.prototype.hasOwnProperty.call(doneState, name) && doneState[name].done) {
      var rowLink = sidebarList.querySelector('[data-session="' + name.replace(/"/g, '&quot;') + '"] [data-session-status]');
      if (rowLink) {
        // Remove all status color classes
        rowLink.classList.remove('text-amber-400', 'text-emerald-400', 'text-slate-400');
        // Apply "done" style (emerald, same as working)
        rowLink.classList.add('text-emerald-400');
        // Change label from idle/working/blocked to "done"
        rowLink.textContent = 'done';
        // Also update the status dot
        var statusDot = rowLink.previousElementSibling;
        if (statusDot && statusDot.classList.contains('rounded-full')) {
          statusDot.classList.remove('bg-amber-400', 'bg-emerald-400', 'bg-slate-600');
          statusDot.classList.add('bg-emerald-400');
        }
      }
    }
  }

  return 'ok';
}

// lastRenderedSidebarHtml starts null (never '') so the very first render -
// including "No other sessions." - always actually applies, matching every
// other lastRendered* dedup guard in this codebase.
var lastRenderedSidebarHtml = null;

function loadSidebarList() {
  sidebarList.innerHTML = '<div class="px-1 text-slate-500">Loading&hellip;</div>';
  lastRenderedSidebarHtml = null;
  fetch('/sidebar_sessions.php?session=' + encodeURIComponent(sessionName))
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (renderSidebarSessions(data, true) === 'error') {
        sidebarList.innerHTML = '<div class="px-1 text-slate-500">Could not load sessions.</div>';
      }
    })
    .catch(function () {
      sidebarList.innerHTML = '<div class="px-1 text-slate-500">Could not load sessions.</div>';
    });
}

// The other-sessions list used to only ever refresh when the sidebar was
// (re)opened, or right after answering a prompt from within it (Andres's
// own ask, 2026-09-28: with the sidebar left open across a real prompt
// answered from it, the session's card stayed on "working" until the panel
// was closed and reopened - a stale display, not a stale SessionStatusStore
// row). Rides the same visibility-gated slot in pollOnce() that
// loadUploadedFiles()/loadPlanFiles() already use - see that function's own
// comment - never the fast per-session-page tab's OWN pollIntervalMs cost of
// a full list_all_sessions() scan on every tick the way
// refreshSidebarNotification()'s comment above warns against, since this
// only ever runs while the panel is actually visible, same gate as those two.
function refreshSidebarList() {
  if (!sidebarList) {
    return Promise.resolve();
  }

  return fetch('/sidebar_sessions.php?session=' + encodeURIComponent(sessionName), { credentials: 'same-origin', signal: pollAbortController.signal })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      renderSidebarSessions(data, false);
    })
    .catch(function () {});
}

// --- uploaded files: the sidebar's own list of whatever's been
// uploaded for THIS session (see upload_file.php/the compose "+"
// button) - name, size, a running total, and per-file/all-at-once
// delete. Refreshed on sidebar open (like the other-sessions list
// above) AND on every regular poll cycle while the sidebar stays open
// (see pollOnce() in session.js) - files can change from an upload
// still in flight or a delete just clicked, and Andres wants to see
// that reflected without having to close/reopen the sidebar. The
// other-sessions list rides the same poll-cycle refresh now too (see
// refreshSidebarList()'s own comment, above loadSidebarList()). ---
var uploadedFilesList = document.getElementById('uploaded-files-list');
var uploadedFilesTotal = document.getElementById('uploaded-files-total');
var deleteAllUploadsBtn = document.getElementById('delete-all-uploads-btn');
var planFilesList = document.getElementById('plan-files-list');
var todoFileLink = document.getElementById('todo-file-link');
var todoFileStatus = document.getElementById('todo-file-status');

// Mirrors PHP's number_format($n, 1) exactly (comma thousands separator,
// dot decimal point, always 1 decimal place) - NOT toLocaleString(),
// which varies by the browser/OS's own locale settings and would drift
// from the server-rendered value on a non-US-formatted device.
function numberFormatOneDecimal(n) {
  var parts = n.toFixed(1).split('.');
  parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  return parts.join('.');
}

// Found live 2026-08-22 (codebase audit): this used to format the KB/MB
// branches with plain toFixed(1), while TranscriptView::
// format_attachment_size() (PHP) used number_format() - identical for a
// small file, but a real drift for anything >= 1000 KB/MB (e.g.
// "1,234.5 MB" server-rendered at page load vs "1234.5 MB" if the same
// attachment instead arrived via a live poll).
function formatFileSize(bytes) {
  if (bytes < 1024) {
    return bytes + ' B';
  }
  if (bytes < 1024 * 1024) {
    return numberFormatOneDecimal(bytes / 1024) + ' KB';
  }
  return numberFormatOneDecimal(bytes / (1024 * 1024)) + ' MB';
}

function uploadedFileRowHtml(f) {
  var name = escapeHtml(f.name);
  var url = '/uploaded_file_view.php?session=' + encodeURIComponent(sessionName) + '&filename=' + encodeURIComponent(f.name);
  return '<div class="flex items-center justify-between gap-2">'
    + '<a href="' + url + '" target="_blank" rel="noopener" class="truncate text-slate-300 active:text-slate-100 hover:underline" title="' + name + '">' + name + '</a>'
    + '<span class="shrink-0 flex items-center gap-2">'
    + '<span class="text-xs text-slate-500">' + formatFileSize(f.size) + '</span>'
    + '<button type="button" class="delete-upload-btn text-slate-500 active:text-red-400 text-base leading-none px-1" data-filename="' + name + '" aria-label="Delete ' + name + '">&times;</button>'
    + '</span>'
    + '</div>';
}

function loadUploadedFiles() {
  if (!uploadedFilesList) {
    return Promise.resolve();
  }

  return fetch('/uploaded_files.php?session=' + encodeURIComponent(sessionName), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data || !data.ok) {
        uploadedFilesList.innerHTML = '<div class="text-slate-500 text-xs">Could not load files.</div>';
        uploadedFilesTotal.textContent = '';
        deleteAllUploadsBtn.classList.add('hidden');
        return;
      }

      var files = data.files || [];

      if (files.length === 0) {
        uploadedFilesList.innerHTML = '<div class="text-slate-500 text-xs">No files uploaded.</div>';
        uploadedFilesTotal.textContent = '';
        deleteAllUploadsBtn.classList.add('hidden');
        return;
      }

      uploadedFilesList.innerHTML = files.map(uploadedFileRowHtml).join('');
      uploadedFilesTotal.textContent = formatFileSize(data.total_size || 0) + ' total';
      deleteAllUploadsBtn.classList.remove('hidden');
    })
    .catch(function () {
      uploadedFilesList.innerHTML = '<div class="text-slate-500 text-xs">Could not load files.</div>';
    });
}

// Sidebar "Plan/handoff files" glance (Andres's own idea, 2026-08-08) -
// a read-only listing of *.md files sitting directly in this session's
// own cwd (README.md/CLAUDE.md excluded server-side - see
// SessionService::list_plan_files()), so ad-hoc plan docs/handoff
// prompts don't go unnoticed once stale. No delete action here on
// purpose - cleanup stays manual.
function planFileRowHtml(f) {
  var name = escapeHtml(f.name);
  var url = '/session_plan_file.php?session=' + encodeURIComponent(sessionName) + '&filename=' + encodeURIComponent(f.name);
  return '<div class="flex items-center justify-between gap-2">'
    + '<a href="' + url + '" target="_blank" rel="noopener" class="truncate text-slate-300 active:text-slate-100 hover:underline" title="' + name + '">' + name + '</a>'
    + '<span class="shrink-0 text-xs text-slate-500">' + escapeHtml(relativeTimeLabel(f.mtime)) + '</span>'
    + '</div>';
}

function loadPlanFiles() {
  if (!planFilesList) {
    return Promise.resolve();
  }

  return fetch('/session_plan_files.php?session=' + encodeURIComponent(sessionName), { credentials: 'same-origin' })
    .then(function (r) { return r.json(); })
    .then(function (data) {
      if (!data || !data.ok) {
        planFilesList.innerHTML = '<div class="text-slate-500 text-xs">Could not load files.</div>';
        return;
      }

      var files = data.files || [];

      if (files.length === 0) {
        planFilesList.innerHTML = '<div class="text-slate-500 text-xs">No plan/handoff files found.</div>';
        return;
      }

      planFilesList.innerHTML = files.map(planFileRowHtml).join('');
    })
    .catch(function () {
      planFilesList.innerHTML = '<div class="text-slate-500 text-xs">Could not load files.</div>';
    });
}

// Sidebar "Open todo file" link (Andres's own ask, 2026-08-25) - fetches
// the session's cwd-level `todo` file and opens it in the shared fullscreen
// text modal (openFullscreenTextModal, common.js) rather than a new tab like
// plan-file content does, since a todo is a quick glance, not a document to
// keep open. Workdir is re-derived server-side (read_todo_file), never
// accepted from this client.
if (todoFileLink) {
  todoFileLink.addEventListener('click', function () {
    function resetStatus(text) {
      if (!todoFileStatus) { return; }
      todoFileStatus.textContent = text || '';
      todoFileStatus.classList.toggle('hidden', !text);
    }

    resetStatus('Loading\u2026');
    todoFileLink.disabled = true;

    fetch('/session_todo_file.php?session=' + encodeURIComponent(sessionName), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        todoFileLink.disabled = false;

        if (!data || !data.ok) {
          resetStatus((data && data.message) || 'Could not load todo file.');
          return;
        }

        resetStatus('');
        var rawText = window.atob(data.data);
        window.openFullscreenTextModal(rawText, renderMarkdown(rawText));
      })
      .catch(function () {
        todoFileLink.disabled = false;
        resetStatus('Could not load todo file.');
      });
  });
}

if (uploadedFilesList) {
  uploadedFilesList.addEventListener('click', function (e) {
    var btn = closestEventTarget(e, '.delete-upload-btn');

    if (!btn) {
      return;
    }

    btn.disabled = true;

    fetch('/delete_uploaded_file.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken, filename: btn.dataset.filename }).toString()
    })
      .then(function (r) { return parseJsonResponse(r, 'delete-uploaded-file'); })
      .then(function (data) {
        if (data && data.ok) {
          loadUploadedFiles();
        } else {
          btn.disabled = false;
          alert((data && data.message) || 'Failed to delete file.');
        }
      })
      .catch(function () {
        btn.disabled = false;
        alert('Network error - file not deleted.');
      });
  });
}

if (deleteAllUploadsBtn) {
  deleteAllUploadsBtn.addEventListener('click', function () {
    if (!confirm('Delete all uploaded files for this session?')) {
      return;
    }

    deleteAllUploadsBtn.disabled = true;

    fetch('/delete_all_uploaded_files.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ session: sessionName, csrf_token: csrfToken }).toString()
    })
      .then(function (r) { return parseJsonResponse(r, 'delete-all-uploaded-files'); })
      .then(function (data) {
        deleteAllUploadsBtn.disabled = false;

        if (data && data.ok) {
          loadUploadedFiles();
        } else {
          alert((data && data.message) || 'Failed to delete files.');
        }
      })
      .catch(function () {
        deleteAllUploadsBtn.disabled = false;
        alert('Network error - files not deleted.');
      });
  });
}

// Matches the lg: breakpoint (1024px) Tailwind classes already use
// throughout this app (e.g. lg:max-w-4xl) - desktop/wide screens get a
// persistent sidebar (see openSidebar() below), not the mobile modal
// drawer.
function isDesktopViewport() {
  return window.matchMedia('(min-width: 1024px)').matches;
}

function openSidebar() {
  // On desktop this is a lightweight always-visible panel, not a modal
  // drawer - no dark backdrop, and the main content underneath stays
  // fully usable (Andres's own choice, 2026-08-18, over keeping the
  // overlay and just pre-opening it). Mobile keeps the existing
  // overlay/click-outside-to-close behavior unchanged.
  if (!isDesktopViewport()) {
    sidebarOverlay.classList.remove('hidden');
  }
  sidebar.classList.remove('translate-x-full');
  // #sidebar is position:fixed (out of #app-shell's own flex flow), so
  // without this the opaque w-72 (18rem) panel simply renders ON TOP of
  // whatever's underneath rather than making room for itself - found
  // live 2026-08-18 that this fully covered the compose bar's Send
  // button at both 1024px and 1280px widths (still 16px clipped even at
  // 1440px). lg:mr-72 (same 18rem as the sidebar's own w-72) shrinks the
  // content column by exactly the sidebar's width instead - lg: prefixed
  // so it's a no-op if this ever ran below the desktop breakpoint.
  if (appShell) {
    appShell.classList.add('lg:mr-72');
  }
  // The sidebar is its own independently-scrollable element (#sidebar
  // has overflow-y-auto), not tied to the main page's scroll position -
  // but without this, it opens wherever its OWN scrollTop last was left
  // (e.g. still scrolled down from a previous open), reading as "opens
  // at the bottom" if the main page also happened to be scrolled far
  // down when it was last closed. Always starts fresh at the top.
  sidebar.scrollTop = 0;
  loadSidebarList();
  loadUploadedFiles();
  loadPlanFiles();
}

function closeSidebar() {
  sidebarOverlay.classList.add('hidden');
  sidebar.classList.add('translate-x-full');

  if (appShell) {
    appShell.classList.remove('lg:mr-72');
  }
}

if (sidebarToggleBtn) {
  sidebarToggleBtn.addEventListener('click', openSidebar);
  sidebarCloseBtn.addEventListener('click', closeSidebar);
  sidebarOverlay.addEventListener('click', closeSidebar);
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      closeSidebar();
    }
  });

  // Open by default on desktop/wide screens (Andres's own ask,
  // 2026-08-18) - checked once at load, not kept in sync with later
  // window resizes (not asked for, and this app is never actually used
  // at a resized browser window in practice - always either a real
  // phone or a real desktop width).
  if (isDesktopViewport()) {
    openSidebar();
  }
}

// --- swipe gestures (touch devices): swipe left anywhere opens the
// sidebar (it slides in from the right, so this matches "pulling" it
// into view), swipe right closes it if it's open, else goes back to
// the dashboard. Ignored for anything that isn't a clearly horizontal
// gesture, so it doesn't fight with normal vertical scrolling. ---
// --- Sidebar blocked-prompt answer handlers: port of index.js's pattern,
// delegated from the whole document to handle forms rendered inside
// sidebar session cards. Same shapes as index.js, but replace
// requestSessionsPollNow() with loadSidebarList() to refresh the sidebar.
// Mirrored handlers for plain-option buttons, free-text replies, and
// multi-question answers (see BlockedPromptView::blocked_prompt_rich_html()
// for the form structure).

// Answer-prompt buttons (option selections) - same confirm/disable/show-
// confirmation pattern as index.js, but the confirmation reads "this
// session" from the wrapper's data attributes.
document.addEventListener('submit', function (e) {
  var form = closestEventTarget(e, 'form[data-confirm-label]');

  if (!form) {
    return;
  }

  // Only intercept forms actually inside the sidebar drawer (#sidebar) -
  // NOT `.closest('[data-session]')` as originally written here: found on
  // review that data-session is also present on .prompt-options-wrapper/
  // .multi-question-wrapper in session.php's own #blocked-prompt-section
  // (blocked-prompt/options.php and multi-question.php are the SAME
  // partials, shared by the dashboard, the sidebar, AND the current
  // session's own inline blocked-prompt display) - a [data-session] guard
  // would have ALSO matched the current session's own blocked-prompt
  // section, double-firing this handler alongside session.js's own
  // blockedSection-scoped one on every answer submitted from THAT session's
  // own page, not just from the sidebar.
  if (!form.closest('#sidebar')) {
    return;
  }

  e.preventDefault();

  if (shouldConfirmBeforeAnswer() && !confirm('Send "' + form.dataset.confirmLabel + '" to this session?')) {
    return;
  }

  var container = form.closest('.prompt-options-wrapper') || form.parentElement;
  var buttons = container ? container.querySelectorAll('button') : [];
  buttons.forEach(function (b) { b.disabled = true; });

  postAnswerPrompt(new FormData(form), 'sidebar-answer-prompt')
    .then(function (data) {
      if (data && data.ok) {
        if (container) {
          container.innerHTML = '<span class="select-none text-xs text-emerald-400">&#10003; Sent - updating&hellip;</span>';
        }
        loadSidebarList();
      } else {
        alert((data && data.message) || 'Failed to send answer.');
        buttons.forEach(function (b) { b.disabled = false; });
      }
    })
    .catch(function () {
      alert('Network error - answer not sent.');
      buttons.forEach(function (b) { b.disabled = false; });
    });
});

// Free-text reply submission for sidebar blocked prompts - reveals the
// textarea via click, then submits via Shift+Enter or the send button.
function submitFreetextReply(replyDiv) {
  var wrapper = replyDiv.closest('.prompt-options-wrapper');
  var textarea = replyDiv.querySelector('.freetext-reply-textarea');
  var sendBtn = replyDiv.querySelector('.freetext-reply-send-btn');
  var text = textarea.value;

  if (text.trim() === '') {
    return;
  }

  textarea.disabled = true;
  sendBtn.disabled = true;

  postAnswerPrompt({
    session: wrapper.dataset.session,
    csrf_token: wrapper.dataset.csrfToken,
    option: replyDiv.dataset.option,
    text: text
  }, 'sidebar-answer-prompt-freetext')
    .then(function (data) {
      if (data && data.ok) {
        wrapper.innerHTML = '<span class="select-none text-xs text-emerald-400">&#10003; Sent - updating&hellip;</span>';
        loadSidebarList();
      } else {
        alert((data && data.message) || 'Failed to send reply.');
        textarea.disabled = false;
        sendBtn.disabled = false;
      }
    })
    .catch(function () {
      alert('Network error - reply not sent.');
      textarea.disabled = false;
      sendBtn.disabled = false;
    });
}

// Multi-question AskUserQuestion answer submission for sidebar.
function submitMultiQuestionAnswers(wrapper) {
  var collected = collectMultiQuestionAnswers(wrapper);

  if (collected === null) {
    alert('Please answer every question before sending.');
    return;
  }

  wrapper.querySelectorAll('button, input').forEach(function (el) { el.disabled = true; });

  postAnswerMultiQuestion(wrapper.dataset.session, wrapper.dataset.csrfToken, collected.answers, 'sidebar-answer-multi-question')
    .then(function (data) {
      if (data && data.ok) {
        wrapper.innerHTML = '<span class="select-none text-xs text-emerald-400">&#10003; Sent - updating&hellip;</span>';
        loadSidebarList();
      } else {
        alert((data && data.message) || 'Failed to send answers.');
        wrapper.querySelectorAll('button, input').forEach(function (el) { el.disabled = false; });
      }
    })
    .catch(function () {
      alert('Network error - answers not sent.');
      wrapper.querySelectorAll('button, input').forEach(function (el) { el.disabled = false; });
    });
}

// Delegated click handlers for reveal/send buttons in sidebar blocked
// prompts. Every one of these (.multi-question-submit-btn/
// .reveal-freetext-btn/.freetext-reply-send-btn) is a plain type="button"
// element (see multi-question.php/options.php) with no default action of
// its own to "absorb" the click - unlike the plain-option case (a real
// <form method="post"> whose OWN submit is what ends up preventDefault()'d
// above), a type="button" click's default bubbles straight past it. Found
// on review (2026-08-31, real headless-browser click-through, not a code
// read): sidebar-row.php wraps each row's ENTIRE card - including these
// buttons - in one `<a data-session>` (unlike row.php's own deliberately
// separate absolute-overlay `<a>`, see that file's own docblock for why),
// so with nothing stopping it, the click's default action walks past this
// no-op button all the way out to that ANCESTOR `<a>` and navigates the
// whole page to that OTHER session's session.php - reveal-freetext and
// multi-question-submit were both silently doing this instead of the
// intended in-place action. e.preventDefault() here (once the #sidebar
// scoping below confirms this click is actually being handled) stops the
// event's default action from firing on ANY node in its path, ancestors
// included, per the DOM spec - the same protection the real <form> submit
// path already got 'for free'.
document.addEventListener('click', function (e) {
  // Multi-question submit button
  var multiQuestionSubmitBtn = closestEventTarget(e, '.multi-question-submit-btn');

  if (multiQuestionSubmitBtn) {
    var multiWrapper = multiQuestionSubmitBtn.closest('.multi-question-wrapper');
    if (multiWrapper && multiWrapper.closest('#sidebar')) {
      e.preventDefault();
      submitMultiQuestionAnswers(multiWrapper);
    }
    return;
  }

  // Free-text reveal button
  var revealBtn = closestEventTarget(e, '.reveal-freetext-btn');

  if (revealBtn) {
    var revealWrapper = revealBtn.closest('.prompt-options-wrapper');
    if (revealWrapper && revealWrapper.closest('#sidebar')) {
      e.preventDefault();
      var replyDiv = revealWrapper.querySelector('.freetext-reply');
      replyDiv.dataset.option = revealBtn.dataset.option;
      replyDiv.classList.toggle('hidden');

      if (!replyDiv.classList.contains('hidden')) {
        replyDiv.querySelector('.freetext-reply-textarea').focus();
      }
    }

    return;
  }

  // Free-text send button
  var sendBtn = closestEventTarget(e, '.freetext-reply-send-btn');

  if (sendBtn) {
    var replyDiv = sendBtn.closest('.freetext-reply');
    if (replyDiv && replyDiv.closest('#sidebar')) {
      e.preventDefault();
      submitFreetextReply(replyDiv);
    }
    return;
  }
});

// Multi-question freetext toggle (show/hide input when "Type something" is selected).
document.addEventListener('change', function (e) {
  var target = e.target;
  var qDiv = target.closest('[data-question-index]');

  if (qDiv && qDiv.closest('#sidebar')) {
    handleMultiQuestionFreetextToggle(target);
  }
});

// Shift+Enter submits a free-text reply in sidebar blocked prompts.
document.addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && e.shiftKey && shiftKeyPhysicallyHeld && eventTargetHasClass(e, 'freetext-reply-textarea')) {
    var replyDiv = closestEventTarget(e, '.freetext-reply');
    if (replyDiv && replyDiv.closest('#sidebar')) {
      e.preventDefault();
      submitFreetextReply(replyDiv);
    }
  }
});

if (sidebarToggleBtn) {
  // Guard logic (scrollable blocks/text selection/textarea) lives in
  // common.js's initHorizontalSwipe() now, shared with archived-session.js's
  // own plain swipe-right-to-dashboard gesture - see its own comment there.
  initHorizontalSwipe({
    onSwipeLeft: function () {
      if (sidebar.classList.contains('translate-x-full')) {
        openSidebar();
      }
    },
    onSwipeRight: function () {
      if (!sidebar.classList.contains('translate-x-full')) {
        closeSidebar();
      } else {
        window.location.href = '/';
      }
    }
  });
}
