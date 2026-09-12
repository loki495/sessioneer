// @ts-check
// #dashboard-footer no longer needs a live-tracked #page-content bottom-
// padding clearance (watchFixedFooterHeight() in common.js) - now that
// it's a normal flex item in #app-shell (see index.php's own comment)
// rather than position:fixed, it takes real flex space and doesn't
// overlay content, so there's nothing to leave room for.

// Reassigned once the live-poll IIFE further down actually defines
// pollOnce() (a no-op stub until then, and permanently if the agent is
// unreachable - see there) - lets the answer-prompt/freetext handlers
// above it in this file trigger an immediate re-sync right after a
// successful send, instead of leaving the user looking at a stale
// confirmation note for however long is left on the regular interval
// (up to 15s, if they've picked a slower one).
var requestSessionsPollNow = function () {};

// Answer-prompt buttons (see BlockedPromptView::blocked_prompt_rich_html())
// use data-confirm-label the same way session.php's do - one delegated
// listener here instead of inline onsubmit, since these forms are
// rendered per-row and their count varies with how many sessions are
// currently blocked. AJAX, not a real form submission - answering a
// prompt shouldn't reload the whole dashboard. The dashboard's own live
// poll (sessions_fragment.php, see the IIFE further down) picks up the
// session's new state and replaces this row entirely - a successful
// answer shows a brief confirmation note and immediately requests a poll
// (requestSessionsPollNow()) rather than waiting for the next scheduled
// tick.
// shouldConfirmBeforeAnswer()/parseJsonResponse()/postAnswerPrompt()/
// postAnswerMultiQuestion()/collectMultiQuestionAnswers()/
// handleMultiQuestionFreetextToggle() are shared with session.js - see
// common.js (loaded before this file).

document.addEventListener('submit', function (e) {
  var form = closestEventTarget(e, 'form[data-confirm-label]');

  if (!form) {
    return;
  }

  e.preventDefault();

  if (shouldConfirmBeforeAnswer() && !confirm('Send "' + form.dataset.confirmLabel + '" to this session?')) {
    return;
  }

  var container = form.closest('.prompt-options-wrapper') || form.parentElement;
  var buttons = container ? container.querySelectorAll('button') : [];
  buttons.forEach(function (b) { b.disabled = true; });

  postAnswerPrompt(new FormData(form), 'dashboard-answer-prompt')
    .then(function (data) {
      if (data && data.ok) {
        if (container) {
          container.innerHTML = '<span class="select-none text-xs text-emerald-400">&#10003; Sent - updating&hellip;</span>';
        }
        requestSessionsPollNow();
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

// --- free-text reply (the "Type something." option) - see session.php's
// matching handler; skips the confirm() dialog since revealing the
// textarea is already a deliberate step. A successful send swaps the
// same way the plain-option case above does, requesting an immediate
// poll rather than waiting for the next scheduled tick.
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
  }, 'dashboard-answer-prompt-freetext')
    .then(function (data) {
      if (data && data.ok) {
        wrapper.innerHTML = '<span class="select-none text-xs text-emerald-400">&#10003; Sent - updating&hellip;</span>';
        requestSessionsPollNow();
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

// --- multi-question AskUserQuestion "answer everything at once" form - see
// BlockedPromptView::blocked_multi_question_html() (PHP) and session.js's
// matching handler for the full reasoning/contract. collectMultiQuestionAnswers()/
// postAnswerMultiQuestion() (common.js) are shared with session.js's own
// copy of this function; this dashboard variant just swaps the whole card
// for a confirmation note on success (same as the plain-option/freetext
// handlers above), rather than session.js's own local pending-state
// tracking - the next fragment poll fully re-renders this row regardless. ---
function submitMultiQuestionAnswers(wrapper) {
  var collected = collectMultiQuestionAnswers(wrapper);

  if (collected === null) {
    alert('Please answer every question before sending.');
    return;
  }

  wrapper.querySelectorAll('button, input').forEach(function (el) { el.disabled = true; });

  postAnswerMultiQuestion(wrapper.dataset.session, wrapper.dataset.csrfToken, collected.answers, 'dashboard-answer-multi-question')
    .then(function (data) {
      if (data && data.ok) {
        wrapper.innerHTML = '<span class="select-none text-xs text-emerald-400">&#10003; Sent - updating&hellip;</span>';
        requestSessionsPollNow();
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

// handleMultiQuestionFreetextToggle() (common.js) is shared with session.js's
// own delegated listener - only the container (document here, vs
// #blocked-prompt-section there) differs.
document.addEventListener('change', function (e) {
  handleMultiQuestionFreetextToggle(e.target);
});

document.addEventListener('click', function (e) {
  var multiQuestionSubmitBtn = closestEventTarget(e, '.multi-question-submit-btn');

  if (multiQuestionSubmitBtn) {
    submitMultiQuestionAnswers(multiQuestionSubmitBtn.closest('.multi-question-wrapper'));
    return;
  }

  var revealBtn = closestEventTarget(e, '.reveal-freetext-btn');

  if (revealBtn) {
    var replyDiv = revealBtn.closest('.prompt-options-wrapper').querySelector('.freetext-reply');
    replyDiv.dataset.option = revealBtn.dataset.option;
    replyDiv.classList.toggle('hidden');

    if (!replyDiv.classList.contains('hidden')) {
      replyDiv.querySelector('.freetext-reply-textarea').focus();
    }

    return;
  }

  var sendBtn = closestEventTarget(e, '.freetext-reply-send-btn');

  if (sendBtn) {
    submitFreetextReply(sendBtn.closest('.freetext-reply'));
    return;
  }
});

// Plain Enter inserts a newline everywhere text gets typed in this app -
// only Shift+Enter submits (same convention as session.php's own compose
// box/freetext reply). Used to be the opposite here specifically (plain
// Enter submitted) - changed 2026-08-08 per Andres's own explicit call
// for one consistent rule app-wide, while separately chasing a mobile
// Shift+Enter false-positive (see shiftKeyPhysicallyHeld's own doc
// comment in common.js) that this dashboard reply box was never
// actually vulnerable to itself (it didn't require shiftKey at all
// before), but the inconsistency was the actual thing being flagged.
document.addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && e.shiftKey && shiftKeyPhysicallyHeld && eventTargetHasClass(e, 'freetext-reply-textarea')) {
    e.preventDefault();
    submitFreetextReply(closestEventTarget(e, '.freetext-reply'));
  }
});

// --- "Take over" a bare (untracked) process - unify-claude-sessions
// plan's phase 6. The confirm() dialog lives inline on the form's own
// onsubmit (see bare-process-row.php, same pattern as the Kill form
// right next to it) - by the time this listener runs, that's already
// been accepted. Two outcomes from the server: a confident match (pid
// already killed and a new session already resumed server-side - just
// redirect), or needs_choice (nothing killed yet - show a picker built
// from the candidates already in the response, no second fetch). ---
(function () {
  function actionButtonsWrapper(row) {
    return row.querySelector('.take-over-form').closest('.flex.flex-col');
  }

  function restoreRow(row) {
    var picker = row.querySelector('.take-over-picker');
    picker.classList.add('hidden');
    picker.innerHTML = '';
    actionButtonsWrapper(row).classList.remove('hidden');
  }

  function renderPicker(row, data) {
    actionButtonsWrapper(row).classList.add('hidden');

    var picker = row.querySelector('.take-over-picker');
    var options = (data.candidates || []).map(function (c) {
      var when = c.last_activity ? new Date(c.last_activity * 1000).toLocaleString() : '';
      var selected = c.agent_session_id === data.suggested_agent_session_id ? ' selected' : '';
      return '<option value="' + escapeHtml(c.agent_session_id) + '"' + selected + '>'
        + escapeHtml(c.title || c.agent_session_id) + ' - ' + escapeHtml(when) + '</option>';
    }).join('');

    if (options === '') {
      picker.innerHTML = '<div class="text-xs text-slate-500">No past conversations found for this working directory.</div>'
        + '<button type="button" class="take-over-cancel-btn mt-2 select-none rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5">Cancel</button>';
      picker.classList.remove('hidden');
      return;
    }

    picker.innerHTML = '<div class="text-xs text-slate-400 mb-1">Which conversation should this pid resume?</div>'
      + '<select class="take-over-select w-full rounded-lg border border-slate-700 bg-slate-800 px-2 py-1.5 text-xs text-slate-200">' + options + '</select>'
      + '<div class="mt-2 flex gap-2">'
      + '<button type="button" class="take-over-confirm-btn select-none rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-200 text-xs font-medium px-3 py-1.5">Resume</button>'
      + '<button type="button" class="take-over-cancel-btn select-none rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5">Cancel</button>'
      + '</div>';
    picker.classList.remove('hidden');
    picker.dataset.workdir = data.workdir;
  }

  document.addEventListener('submit', function (e) {
    var form = /** @type {HTMLFormElement|null} */ (closestEventTarget(e, '.take-over-form'));

    if (!form) {
      return;
    }

    e.preventDefault();

    var row = form.closest('[data-bare-row]');
    var btn = form.querySelector('button');
    btn.disabled = true;

    var takeOverBody = new URLSearchParams();
    new FormData(form).forEach(function (value, key) {
      takeOverBody.append(key, String(value));
    });

    fetch('/take_over_bare.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: takeOverBody.toString()
    })
      .then(function (r) { return parseJsonResponse(r, 'take-over-bare'); })
      .then(function (data) {
        if (data && data.ok && data.name) {
          window.location.href = '/session.php?session=' + encodeURIComponent(data.name);
          return;
        }

        if (data && data.ok && data.needs_choice) {
          renderPicker(row, data);
          return;
        }

        alert((data && data.message) || 'Failed to take over this process.');
        btn.disabled = false;
      })
      .catch(function () {
        alert('Network error - take-over not started.');
        btn.disabled = false;
      });
  });

  document.addEventListener('click', function (e) {
    var cancelBtn = closestEventTarget(e, '.take-over-cancel-btn');

    if (cancelBtn) {
      var cancelRow = cancelBtn.closest('[data-bare-row]');
      restoreRow(cancelRow);
      cancelRow.querySelector('.take-over-form button').disabled = false;
      return;
    }

    var confirmBtn = closestEventTarget(e, '.take-over-confirm-btn');

    if (!confirmBtn) {
      return;
    }

    var confirmRow = confirmBtn.closest('[data-bare-row]');
    var picker = confirmRow.querySelector('.take-over-picker');
    var select = picker.querySelector('.take-over-select');
    confirmBtn.disabled = true;

    fetch('/take_over_bare_confirm.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({
        pid: confirmRow.dataset.pid,
        csrf_token: confirmRow.dataset.csrfToken,
        workdir: picker.dataset.workdir,
        agent_session_id: select.value
      }).toString()
    })
      .then(function (r) { return parseJsonResponse(r, 'take-over-bare-confirm'); })
      .then(function (data) {
        if (data && data.ok && data.name) {
          window.location.href = '/session.php?session=' + encodeURIComponent(data.name);
          return;
        }

        alert((data && data.message) || 'Failed to resume the chosen session.');
        confirmBtn.disabled = false;
      })
      .catch(function () {
        alert('Network error - take-over not completed.');
        confirmBtn.disabled = false;
      });
  });
})();

// "Identify" a bare (untracked) process's row - Andres's own ask
// (2026-09-11), after the archived-list/resume bug turned up a case where
// a live bare process's own session had no way to tell whether it
// duplicated something already in the archived list. Two-step, both
// lazy-loaded on first click and cached after that (same pattern as
// "Show last 3 messages" below): first /bare_process_detail.php resolves
// pid -> agent_session_id (a real statusline-marker match, or a
// best-guess heuristic - see BareProcessService::resolve_bare_process_
// detail()'s own docblock), then - only once an id comes back -
// /archived_session_history_fragment.php?limit=3 renders its last few
// messages, reusing the exact same endpoint an archived row's own
// history uses rather than a second, parallel rendering path.
(function () {
  document.addEventListener('click', function (e) {
    var btn = closestEventTarget(e, '.bare-identify-btn');

    if (!btn) {
      return;
    }

    var row = btn.closest('[data-bare-row]');
    var container = row.querySelector('.bare-detail');

    if (btn.dataset.loaded === '1') {
      container.classList.toggle('hidden');
      btn.textContent = container.classList.contains('hidden') ? 'Identify' : 'Hide';
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Identifying…';

    fetch('/bare_process_detail.php?pid=' + encodeURIComponent(row.dataset.pid), { credentials: 'same-origin' })
      .then(function (r) { return parseJsonResponse(r, 'bare-process-detail'); })
      .then(function (data) {
        btn.disabled = false;

        if (!data || !data.ok) {
          btn.textContent = (data && data.message) || 'Failed to identify this process.';
          return;
        }

        if (!data.agent_session_id) {
          container.innerHTML = '<div class="text-xs text-slate-500">Couldn\'t identify a past conversation for this process\'s working directory.</div>';
          container.classList.remove('hidden');
          btn.dataset.loaded = '1';
          btn.textContent = 'Hide';
          return;
        }

        var header = document.createElement('div');
        if (data.confidence === 'guess') {
          header.innerHTML = '<div class="mb-1 inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border bg-amber-900/30 text-amber-400 border-amber-700/40">Best guess - not confirmed</div>';
        }
        var titleEl = document.createElement('div');
        titleEl.className = 'text-sm text-slate-300 truncate';
        titleEl.textContent = data.title || data.agent_session_id;
        header.appendChild(titleEl);

        container.innerHTML = '';
        container.appendChild(header);
        container.classList.remove('hidden');
        btn.dataset.loaded = '1';
        btn.textContent = 'Hide';

        fetch('/archived_session_history_fragment.php?agent_session_id=' + encodeURIComponent(data.agent_session_id) + '&limit=3', { credentials: 'same-origin' })
          .then(function (r) { return parseJsonResponse(r, 'bare-process-detail-history'); })
          .then(function (historyData) {
            if (historyData && historyData.ok && historyData.html) {
              var messages = document.createElement('div');
              messages.className = 'mt-2 space-y-2';
              messages.innerHTML = historyData.html;
              container.appendChild(messages);
            }
          })
          .catch(function () {});
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Network error - try again';
      });
  });
})();

// "Show last 3 messages" toggle, one per session row. Lazy-loaded on
// first click (via session_history.php, the same endpoint session.php's
// "load more" uses) and cached in the DOM after that - toggling again
// just shows/hides rather than re-fetching.
(function () {
  var ROLE_LABELS = { user: 'User', assistant: 'Assistant', system: 'System' };

  // escapeHtml() is shared with session.js - see common.js.

  // Mirrors TranscriptView::entry_color_kind()/entry_color_classes() - see
  // there for why this isn't just entry.role (a tool_result entry carries
  // role="user" under the hood, same as a real typed message).
  //
  // dashboardEntryColorKind/dashboardEntryColorClasses, not the bare
  // entryColorKind/entryColorClasses names session.js uses for its own,
  // materially different versions (a full 7-kind classifier there vs this
  // dashboard's simplified 3-kind subset) - same-named-but-differently-
  // behaving functions in two separate files was a real risk for a
  // contributor assuming "the color function" is shared (found via the
  // 2026-08-23 readability audit). No actual runtime collision either way
  // (each file is its own IIFE), but the misleading-name risk is real on
  // its own.
  function dashboardEntryColorKind(entry) {
    var blocks = entry.blocks || [];
    var hasText = blocks.some(function (b) { return b.kind === 'text'; });

    if (!hasText && blocks.length > 0) {
      return 'tool';
    }

    if (entry.role === 'assistant' || entry.role === 'user') {
      return entry.role;
    }

    return 'system';
  }

  function dashboardEntryColorClasses(kind) {
    switch (kind) {
      case 'user':
        return { border: 'border-indigo-800/60', bg: 'bg-indigo-950/40', label: 'text-indigo-300' };
      case 'assistant':
        return { border: 'border-emerald-800/60', bg: 'bg-emerald-950/40', label: 'text-emerald-300' };
      case 'tool':
        return { border: 'border-sky-800/60', bg: 'bg-sky-950/40', label: 'text-sky-300' };
      default:
        return { border: 'border-slate-800', bg: 'bg-slate-900/50', label: 'text-slate-400' };
    }
  }

  // Only ever called with an assistant/text entry now (see the fetch
  // handler below, which filters to that before rendering anything) - the
  // color/role machinery still runs generically for consistency with
  // session.php, it just always resolves to the same "assistant" look here.
  function renderRecentEntry(entry) {
    var roleLabel = ROLE_LABELS[entry.role] || (entry.role ? escapeHtml(entry.role) : 'System');
    var textBlock = (entry.blocks || []).find(function (b) { return b.kind === 'text'; });
    var text = textBlock ? textBlock.text : '';
    var colors = dashboardEntryColorClasses(dashboardEntryColorKind(entry));

    var p = document.createElement('p');
    p.className = 'text-xs text-slate-400 whitespace-pre-wrap break-words rounded border ' + colors.border + ' ' + colors.bg + ' px-1.5 py-1';
    p.innerHTML = '<span class="select-none font-medium ' + colors.label + '">' + roleLabel + ':</span> ' + escapeHtml(text);
    return p;
  }

  document.addEventListener('click', function (e) {
    var btn = closestEventTarget(e, '.show-recent-btn');

    if (!btn) {
      return;
    }

    var container = btn.nextElementSibling;

    if (btn.dataset.loaded === '1') {
      container.classList.toggle('hidden');
      btn.textContent = container.classList.contains('hidden') ? 'Show last 3 messages' : 'Hide recent messages';
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Loading…';

    // Fetches a bigger page than the 3 actually shown - only agent text
    // replies count (no tool_use/tool_result/user messages), and those
    // can easily be outnumbered within the last handful of raw entries by
    // a run of tool calls, so a plain limit=3 off the raw transcript could
    // come back with zero real replies to show.
    fetch('/session_history.php?session=' + encodeURIComponent(btn.dataset.session) + '&limit=20', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;

        if (!data || !data.ok || !data.entries || data.entries.length === 0) {
          btn.textContent = (data && data.message) || 'No messages to show.';
          return;
        }

        var agentReplies = data.entries.filter(function (entry) {
          return entry.role === 'assistant' && (entry.blocks || []).some(function (b) { return b.kind === 'text'; });
        }).slice(-3);

        if (agentReplies.length === 0) {
          btn.textContent = 'No agent replies to show.';
          return;
        }

        container.innerHTML = '';
        agentReplies.forEach(function (entry) { container.appendChild(renderRecentEntry(entry)); });
        container.classList.remove('hidden');
        btn.dataset.loaded = '1';
        btn.textContent = 'Hide recent messages';
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Network error - try again';
      });
  });
})();

(function () {
  var details = document.getElementById('new-session-details');
  var summary = document.getElementById('new-session-summary');
  var pathEl = document.getElementById('browser_path');
  var listEl = document.getElementById('browser_list');
  var hiddenInput = document.getElementById('workdir_value');
  var submitBtn = document.getElementById('new-session-submit');
  var newFolderBtn = document.getElementById('new-folder-btn');
  var loaded = false;

  function setStatusRow(text) {
    listEl.innerHTML = '';
    var li = document.createElement('li');
    li.className = 'px-3 py-2 text-slate-500';
    li.textContent = text;
    listEl.appendChild(li);
  }

  function renderRow(label, muted, onClick) {
    var li = document.createElement('li');
    var btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'w-full text-left px-3 py-2 active:bg-slate-700 truncate ' + (muted ? 'text-slate-400' : 'text-slate-100');
    btn.textContent = label;
    btn.addEventListener('click', onClick);
    li.appendChild(btn);
    listEl.appendChild(li);
  }

  function load(path) {
    hiddenInput.value = '';
    submitBtn.disabled = true;
    setStatusRow('Loading…');

    fetch('/browse.php?path=' + encodeURIComponent(path || ''), { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          pathEl.textContent = 'Unavailable';
          setStatusRow((data && data.message) || 'Could not load folders.');
          return;
        }

        hiddenInput.value = data.path;
        pathEl.textContent = data.path;
        pathEl.title = data.path;
        submitBtn.disabled = false;
        listEl.innerHTML = '';

        if (data.parent !== null) {
          renderRow('.. (up)', true, function () { load(data.parent); });
        }

        if (data.dirs.length === 0) {
          var li = document.createElement('li');
          li.className = 'px-3 py-2 text-slate-500';
          li.textContent = 'No subfolders here.';
          listEl.appendChild(li);
        }

        data.dirs.forEach(function (dir) {
          renderRow(dir, false, function () { load(data.path + '/' + dir); });
        });
      })
      .catch(function () {
        pathEl.textContent = 'Unavailable';
        setStatusRow('Network error.');
      });
  }

  // hiddenInput.value doubles as "the directory currently shown in the
  // browser" - load() sets it to data.path on every successful fetch, so
  // there's no separate variable to keep in sync with it.
  newFolderBtn.addEventListener('click', function () {
    var currentPath = hiddenInput.value;
    if (!currentPath) { return; }

    var name = window.prompt('New folder name:');
    if (name === null) { return; }
    name = name.trim();
    if (name === '') { return; }

    newFolderBtn.disabled = true;

    fetch('/create_folder.php', {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams({ path: currentPath, name: name, csrf_token: newFolderBtn.dataset.csrfToken }).toString()
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        newFolderBtn.disabled = false;

        if (!data || !data.ok) {
          window.alert((data && data.message) || 'Could not create folder.');
          return;
        }

        load(data.path);
      })
      .catch(function () {
        newFolderBtn.disabled = false;
        window.alert('Network error.');
      });
  });

  details.addEventListener('toggle', function () {
    summary.textContent = details.open ? '− Cancel' : '+ New Session';
    summary.classList.toggle('bg-indigo-600', !details.open);
    summary.classList.toggle('active:bg-indigo-700', !details.open);
    summary.classList.toggle('bg-red-900/70', details.open);
    summary.classList.toggle('active:bg-red-800', details.open);

    if (details.open && !loaded) {
      loaded = true;
      load('');
    }
  });
})();

// --- New-session model dropdown: dynamically populated based on selected agent ---
(function () {
  var agentSelect = document.getElementById('new-session-agent');
  var modelSelect = document.getElementById('new-session-model');
  var modelProviderInput = document.getElementById('new-session-model-provider');

  if (!agentSelect || !modelSelect) { return; }

  var CLAUDE_MODELS = [
    { id: '', label: 'Default' },
    { id: 'sonnet', label: 'Sonnet' },
    { id: 'fable', label: 'Fable' },
    { id: 'opus', label: 'Opus' },
    { id: 'haiku', label: 'Haiku' }
  ];

  var ocModelsCache = null;
  var codexModelsCache = null;

  function clearModels() {
    while (modelSelect.options.length > 1) {
      modelSelect.remove(1);
    }
    modelSelect.options[0].selected = true;
    modelProviderInput.value = '';
  }

  function populateModels(models) {
    clearModels();
    models.forEach(function (m) {
      var opt = document.createElement('option');
      opt.value = m.id;
      opt.textContent = m.label || m.id;
      if (m.providerID) {
        opt.setAttribute('data-provider', m.providerID);
      }
      modelSelect.appendChild(opt);
    });
  }

  function loadClaudeModels() {
    populateModels(CLAUDE_MODELS);
  }

  function loadOpenCodeModels() {
    if (ocModelsCache) {
      populateModels(ocModelsCache);
      return;
    }
    clearModels();
    modelSelect.options[0].textContent = 'Loading models…';
    modelSelect.disabled = true;

    fetch('/session_list_models.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        modelSelect.disabled = false;
        modelSelect.options[0].textContent = 'Default';

        if (!data || !data.ok || !data.models) { return; }

        ocModelsCache = data.models.map(function (m) {
          return {
            id: m.id,
            label: (m.providerID ? m.providerID + '/' : '') + (m.id || m.name || ''),
            providerID: m.providerID
          };
        });
        populateModels(ocModelsCache);
      })
      .catch(function () {
        modelSelect.disabled = false;
        modelSelect.options[0].textContent = 'Default';
      });
  }

  function loadCodexModels() {
    if (codexModelsCache) {
      populateModels(codexModelsCache);
      return;
    }
    clearModels();
    modelSelect.options[0].textContent = 'Loading models…';
    modelSelect.disabled = true;
    fetch('/session_list_models.php?agent=codex', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        modelSelect.disabled = false;
        modelSelect.options[0].textContent = 'Default';
        if (!data || !data.ok || !data.models) { return; }
        codexModelsCache = data.models.map(function (m) {
          return { id: m.id, label: m.name || m.id };
        });
        populateModels(codexModelsCache);
      })
      .catch(function () {
        modelSelect.disabled = false;
        modelSelect.options[0].textContent = 'Default';
      });
  }

  function onAgentChange() {
    var agent = agentSelect.value;
    clearModels();

    if (agent === 'opencode') {
      loadOpenCodeModels();
    } else if (agent === 'codex') {
      loadCodexModels();
    } else if (agent === 'claude') {
      loadClaudeModels();
    } else {
      // Antigravity: no model selection available
      modelSelect.options[0].textContent = 'Default';
    }
  }

  modelSelect.addEventListener('change', function () {
    var opt = modelSelect.options[modelSelect.selectedIndex];
    modelProviderInput.value = opt ? (opt.getAttribute('data-provider') || '') : '';
  });

  agentSelect.addEventListener('change', onAgentChange);

  // Initialize on load
  onAgentChange();
})();

// --- visibility-gated live polling: keeps the session list, bare-process
// list, and header count in sync without a manual refresh - same pattern
// as session.php's own poll (stopped while the tab isn't visible, so a
// backgrounded tab doesn't keep hitting the socket for nobody), sharing
// its localStorage key so a chosen interval carries over between pages. ---
(function () {
  // Whether the host agent was reachable at page-render time (real,
  // render-specific state, not something this static file can know) - set
  // by the small inline bootstrap-data <script> tag index.php renders
  // right before this file is loaded.
  var agentReachable = window.SESSIONEER_BOOTSTRAP.agentReachable;
  var sessionsContainer = document.getElementById('sessions-container');
  var bareContainer = document.getElementById('bare-container');
  var countText = document.getElementById('session-count-text');

  if (!agentReachable || !sessionsContainer) {
    return; // nothing to keep live - the "cannot reach host agent" banner is SSR-only
  }

  // Setting: show/hide orchestrator-worker "worker" sessions - see
  // SHOW_WORKER_SESSIONS_KEY/shouldShowWorkerSessions() in common.js. Rows
  // render `hidden` by default server-side (row.php), so this only ever
  // REMOVES that class when the toggle is on - never adds it back, since a
  // fresh poll's HTML already comes back correctly hidden-by-default and
  // there's nothing here to un-hide on the "off" path.
  function applyShowWorkerSessions() {
    if (!shouldShowWorkerSessions()) {
      return;
    }
    var workerRows = sessionsContainer.querySelectorAll('[data-kind="worker"]');
    for (var i = 0; i < workerRows.length; i++) {
      workerRows[i].classList.remove('hidden');
    }
  }

  var showWorkerSessionsToggle = document.getElementById('show-worker-sessions-toggle');

  if (showWorkerSessionsToggle) {
    showWorkerSessionsToggle.checked = shouldShowWorkerSessions();
    applyShowWorkerSessions();

    showWorkerSessionsToggle.addEventListener('change', function () {
      try {
        window.localStorage.setItem(SHOW_WORKER_SESSIONS_KEY, showWorkerSessionsToggle.checked ? '1' : '0');
      } catch (e) {}
      // Toggling OFF needs the already-rendered rows re-hidden too, not just
      // future polls - a fresh re-render is the simplest correct way to get
      // back to the server-rendered default-hidden state without hand-
      // tracking which rows this function itself un-hid.
      sessionsContainer.innerHTML = lastSessionsHtml;
      applyShowWorkerSessions();
    });
  }

  // POLL_INTERVAL_STORAGE_KEY/POLL_INTERVAL_ALLOWED_MS are shared with
  // session.js - see common.js.
  var pollIntervalMs = (function () {
    try {
      var stored = parseInt(window.localStorage.getItem(POLL_INTERVAL_STORAGE_KEY), 10);
      return POLL_INTERVAL_ALLOWED_MS.indexOf(stored) !== -1 ? stored : 3000;
    } catch (e) {
      return 3000;
    }
  })();

  var pollTimer = null;
  var pollingActive = false;
  var pollAbortController = new AbortController();

  // Skip-if-unchanged, same reasoning as session.php's renderBlockedSection():
  // the common case is a poll landing on data identical to what's already
  // shown, and replacing innerHTML unconditionally would collapse any
  // mid-interaction state (an expanded "Show last 3 messages" panel, a
  // focused free-text reply box) on every single cycle for no reason.
  var lastSessionsHtml = sessionsContainer.innerHTML;
  var lastBareHtml = bareContainer ? bareContainer.innerHTML : null;

  function pollOnce() {
    return fetch('/sessions_fragment.php', { credentials: 'same-origin', signal: pollAbortController.signal })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (!data || !data.ok) {
          return; // agent unreachable this cycle - leave the last-good state on screen rather than blanking it
        }

        if (data.sessions_html !== lastSessionsHtml) {
          sessionsContainer.innerHTML = data.sessions_html;
          lastSessionsHtml = data.sessions_html;
        }

        if (bareContainer && data.bare_html !== lastBareHtml) {
          bareContainer.innerHTML = data.bare_html;
          lastBareHtml = data.bare_html;
        }

        if (countText && typeof data.session_count_html === 'string') {
          countText.innerHTML = data.session_count_html;
        }
      })
      .catch(function () {});
  }

  // Lets the answer-prompt/freetext-reply handlers (defined earlier in
  // this file, before pollOnce exists) trigger an immediate sync right
  // after a successful send - see requestSessionsPollNow's own comment.
  requestSessionsPollNow = pollOnce;

  function startPolling() {
    if (pollingActive) {
      return;
    }

    pollingActive = true;
    pollAbortController = new AbortController();

    function cycle() {
      pollOnce().finally(function () {
        if (pollingActive) {
          pollTimer = setTimeout(cycle, pollIntervalMs);
        }
      });
    }

    cycle();
  }

  function stopPolling() {
    if (!pollingActive) {
      return;
    }

    pollingActive = false;

    if (pollTimer !== null) {
      clearTimeout(pollTimer);
      pollTimer = null;
    }

    pollAbortController.abort();
  }

  window.addEventListener('pagehide', function () {
    pollAbortController.abort();
  });

  var pollIntervalSelect = document.getElementById('poll-interval-select');

  if (pollIntervalSelect) {
    pollIntervalSelect.value = String(pollIntervalMs);

    pollIntervalSelect.addEventListener('change', function () {
      var chosen = parseInt(pollIntervalSelect.value, 10);

      if (POLL_INTERVAL_ALLOWED_MS.indexOf(chosen) === -1) {
        return;
      }

      pollIntervalMs = chosen;

      try {
        window.localStorage.setItem(POLL_INTERVAL_STORAGE_KEY, String(chosen));
      } catch (e) {}

      var wasPolling = pollTimer !== null;
      stopPolling();

      if (wasPolling) {
        startPolling();
      }
    });
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'visible') {
      startPolling();
    } else {
      stopPolling();
    }
  });

  if (document.visibilityState === 'visible') {
    startPolling();
  }
})();

// Archived-sessions toggle - deliberately NOT part of the poll IIFE above:
// fetched once, lazily, only when Andres actually opens it (see
// DashboardController::archivedFragment()'s own doc comment for why a full
// ~/.claude/projects scan has no business running on a timer). The whole
// list is still fetched and rendered into the DOM in that one request (the
// scan itself is the expensive part, and it has to walk every transcript
// to sort by last_activity anyway - see SessionService::
// list_archived_sessions()) - what's paginated is only how many rows are
// REVEALED at once (found live 2026-08-22: at 100+ archived sessions and
// growing daily, showing everything at once made the list unwieldy to
// scroll through, even though a client-side filter over all of them was
// still instant). Search deliberately ignores pagination entirely and
// matches across every already-fetched row (same "trivial for a client-
// side filter" reasoning as before pagination existed) - a query should
// never miss a session just because it wasn't in the currently-revealed
// page, and "Load more" would be a confusing, extra step to take mid-search.
(function () {
  var btn = document.getElementById('show-archived-btn');
  var container = document.getElementById('archived-container');

  if (!btn || !container) {
    return;
  }

  // Matches session_history.php's "Load older messages" page size closely
  // enough (50) while staying smaller - an archived row is a much simpler
  // card than a transcript block, but the point (never dump the whole
  // list into the DOM by default) is the same one that button already
  // established elsewhere in this app.
  var PAGE_SIZE = 25;
  var loaded = false;
  var revealedCount = 0;
  var selectedAgentFilter = 'all';

  function applyPagination() {
    var loadMoreBtn = document.getElementById('archived-load-more-btn');
    var rows = container.querySelectorAll('[data-archived-row]');

    rows.forEach(function (row, i) {
      row.classList.toggle('hidden', i >= revealedCount);
    });

    if (!loadMoreBtn) {
      return;
    }

    var remaining = rows.length - revealedCount;
    loadMoreBtn.classList.toggle('hidden', remaining <= 0);
    loadMoreBtn.textContent = 'Load more (' + remaining + ' more)';
  }

  function updateAgentFilterButtons() {
    var filterBtns = container.querySelectorAll('[data-agent-filter]');
    filterBtns.forEach(function (b) {
      var isSelected = b.getAttribute('data-agent-filter') === selectedAgentFilter;
      b.setAttribute('data-selected', isSelected ? '1' : '0');
      if (isSelected) {
        var agent = b.getAttribute('data-agent-filter');
        if (agent === 'opencode') {
          b.className = 'archived-agent-filter-btn select-none text-xs font-medium px-3 py-1 rounded-full border bg-violet-900/50 text-violet-300 border-violet-700/50';
        } else if (agent === 'codex') {
          b.className = 'archived-agent-filter-btn select-none text-xs font-medium px-3 py-1 rounded-full border bg-cyan-900/50 text-cyan-300 border-cyan-700/50';
        } else if (agent === 'antigravity') {
          b.className = 'archived-agent-filter-btn select-none text-xs font-medium px-3 py-1 rounded-full border bg-amber-900/40 text-amber-300 border-amber-700/40';
        } else if (agent === 'claude') {
          b.className = 'archived-agent-filter-btn select-none text-xs font-medium px-3 py-1 rounded-full border bg-slate-700 text-slate-200 border-slate-600';
        } else {
          b.className = 'archived-agent-filter-btn select-none text-xs font-medium px-3 py-1 rounded-full border bg-indigo-600 text-white border-indigo-500';
        }
      } else {
        b.className = 'archived-agent-filter-btn select-none text-xs font-medium px-3 py-1 rounded-full border bg-slate-800 text-slate-400 border-slate-700';
      }
    });
  }

  function filterArchivedRows() {
    var searchInput = document.getElementById('archived-search');
    var noMatches = document.getElementById('archived-no-matches');
    var loadMoreBtn = document.getElementById('archived-load-more-btn');
    var rows = container.querySelectorAll('[data-archived-row]');

    if (!searchInput) {
      return;
    }

    var query = searchInput.value.toLowerCase();
    var hasAgentFilter = selectedAgentFilter !== 'all';
    var hasQuery = query !== '';

    // No filters active → revert to paginated view
    if (!hasAgentFilter && !hasQuery) {
      if (noMatches) {
        noMatches.classList.add('hidden');
      }

      applyPagination();

      return;
    }

    var anyVisible = false;

    rows.forEach(function (row) {
      var textMatch = !hasQuery || row.textContent.toLowerCase().indexOf(query) !== -1;
      var agentMatch = !hasAgentFilter || row.getAttribute('data-agent') === selectedAgentFilter;
      var matches = textMatch && agentMatch;
      row.classList.toggle('hidden', !matches);

      if (matches) {
        anyVisible = true;
      }
    });

    if (noMatches) {
      noMatches.classList.toggle('hidden', anyVisible || rows.length === 0);
    }

    if (loadMoreBtn) {
      loadMoreBtn.classList.add('hidden');
    }
  }

  btn.addEventListener('click', function () {
    if (loaded) {
      container.classList.toggle('hidden');
      btn.textContent = container.classList.contains('hidden') ? 'Show archived sessions' : 'Hide archived sessions';

      return;
    }

    btn.disabled = true;
    btn.textContent = 'Loading…';

    fetch('/archived_sessions_fragment.php', { credentials: 'same-origin' })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        btn.disabled = false;

        if (!data || !data.ok) {
          btn.textContent = (data && data.message) || 'Failed to load archived sessions';

          return;
        }

        container.innerHTML = data.archived_html;
        container.classList.remove('hidden');
        loaded = true;
        btn.textContent = 'Hide archived sessions';

        revealedCount = PAGE_SIZE;
        applyPagination();

        var loadMoreBtn = document.getElementById('archived-load-more-btn');

        if (loadMoreBtn) {
          loadMoreBtn.addEventListener('click', function () {
            revealedCount += PAGE_SIZE;
            applyPagination();
          });
        }

        var searchInput = document.getElementById('archived-search');

        if (searchInput) {
          searchInput.addEventListener('input', filterArchivedRows);
          wireClearButton(searchInput, document.getElementById('archived-search-clear-btn'));
        }

        var filterBtns = container.querySelectorAll('[data-agent-filter]');
        filterBtns.forEach(function (b) {
          b.addEventListener('click', function () {
            selectedAgentFilter = b.getAttribute('data-agent-filter');
            updateAgentFilterButtons();
            filterArchivedRows();
          });
        });
        updateAgentFilterButtons();
      })
      .catch(function () {
        btn.disabled = false;
        btn.textContent = 'Network error - try again';
      });
  });
})();

// --- dashboard-wide content search: unlike the archived-list filter above
// (a plain client-side substring match against each row's own rendered
// text - title/name/workdir only), this greps every known transcript's
// REAL message content, live and archived alike, server-side (see
// SessionService::search_transcripts()'s own doc comment). Debounced for
// the same reason session.js's own per-session search box is - every
// keystroke round-tripping to a host-agent grep over ~160 real transcripts
// would be real, wasted work. Clicking a result is a full navigation to
// that session with a jump_line param (session.php for a live result -
// session_name is non-null - or archived_session.php otherwise), reusing
// the exact same SSR "load the page ending at this line" path an ordinary
// page load already takes. ---
(function () {
  var input = document.getElementById('dashboard-search-input');
  var results = document.getElementById('dashboard-search-results');

  if (!input || !results) {
    return;
  }

  var debounceTimer = null;
  var abortController = null;

  // Delegated (results.innerHTML is fully replaced on every render, so a
  // per-form listener would need re-attaching each time) - same
  // data-confirm-label + closest('form[...]') pattern session.js's own
  // blocked-prompt answer form uses, rather than an inline onsubmit with
  // hand-escaped JS string interpolation (r.session_name/r.title are
  // search-result data, not something to trust into a JS string literal).
  results.addEventListener('submit', function (e) {
    var form = closestEventTarget(e, 'form[data-confirm-label]');

    if (form && !confirm('Archive session ' + form.dataset.confirmLabel + '?')) {
      e.preventDefault();
    }
  });

  function renderResults(sessionResults, query) {
    if (!sessionResults || sessionResults.length === 0) {
      results.innerHTML = '<div class="text-xs text-slate-500 px-1">No matches.</div>';
      return;
    }

    var csrfToken = input.dataset.csrfToken;

    results.innerHTML = sessionResults.map(function (r) {
      var url = r.session_name
        ? '/session.php?session=' + encodeURIComponent(r.session_name) + '&jump_line=' + encodeURIComponent(r.matches[0].line)
        : '/archived_session.php?agent_session_id=' + encodeURIComponent(r.agent_session_id) + '&jump_line=' + encodeURIComponent(r.matches[0].line);

      var matchesHtml = r.matches.map(function (m) {
        var roleLabel = m.role === 'user' ? 'You' : (m.role === 'assistant' ? 'Claude' : (m.kind === 'tool_use' ? 'Tool call' : 'Tool output'));
        var timeHtml = typeof m.timestamp === 'number' ? '<span class="text-slate-600"> &middot; ' + escapeHtml(relativeTimeLabel(m.timestamp)) + '</span>' : '';
        return '<div class="text-xs text-slate-400 mt-1"><span class="text-slate-500">' + escapeHtml(roleLabel) + ':</span>' + timeHtml + ' ' + highlightSnippet(m.snippet, query) + '</div>';
      }).join('');

      // Archive (kill, per Andres's own call - there's no separate
      // "archived" state to move a live session into, its own transcript
      // just naturally becomes archived once nothing's tracking it live
      // anymore) for a live result; Unarchive (resume) for an already-
      // archived one - only shown when a real workdir is known, same
      // "no cwd, no resume form" gate archived-row.php already uses.
      var actionHtml = r.session_name
        ? '<form method="post" action="/" class="shrink-0" data-confirm-label="' + escapeHtml(r.session_name) + '">'
          + '<input type="hidden" name="action" value="kill">'
          + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken) + '">'
          + '<input type="hidden" name="session" value="' + escapeHtml(r.session_name) + '">'
          + '<button type="submit" class="select-none rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5">Archive</button>'
          + '</form>'
        : (r.cwd
          ? '<form method="post" action="/" class="shrink-0">'
            + '<input type="hidden" name="action" value="resume">'
            + '<input type="hidden" name="csrf_token" value="' + escapeHtml(csrfToken) + '">'
            + '<input type="hidden" name="agent_session_id" value="' + escapeHtml(r.agent_session_id) + '">'
            + '<input type="hidden" name="workdir" value="' + escapeHtml(r.cwd) + '">'
            + '<button type="submit" class="select-none rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5">Unarchive</button>'
            + '</form>'
          : '');

      return '<div class="rounded-xl border border-slate-800 bg-slate-900/50 px-3 py-2 flex items-start gap-2">'
        + '<a href="' + url + '" class="min-w-0 flex-1 active:opacity-80">'
        + '<div class="flex items-center gap-1.5 text-sm font-medium text-slate-200 truncate">'
        + escapeHtml(r.title)
        + (r.session_name ? ' <span class="shrink-0 text-[10px] font-normal text-emerald-400 border border-emerald-800/60 rounded-full px-1.5 py-0.5">live</span>' : '')
        + '</div>'
        + matchesHtml
        + '</a>'
        + actionHtml
        + '</div>';
    }).join('');
  }

  wireClearButton(input, document.getElementById('dashboard-search-input-clear-btn'));

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

      fetch('/search_sessions.php?q=' + encodeURIComponent(query), { credentials: 'same-origin', signal: abortController.signal })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || !data.ok) {
            results.innerHTML = '<div class="text-xs text-red-400 px-1">' + escapeHtml((data && data.message) || 'Search failed.') + '</div>';
            return;
          }

          renderResults(data.results, query);
        })
        .catch(function (e) {
          if (e && e.name === 'AbortError') {
            return;
          }

          results.innerHTML = '<div class="text-xs text-red-400 px-1">Network error - search failed.</div>';
        });
    }, 400);
  });
})();

// --- Pull-to-refresh (mobile). A downward drag from the top of the page's
// scroll container triggers a reload of the dashboard - the explicit
// "refresh" gesture a standalone PWA doesn't get from the browser (there's
// no native pull-to-refresh in an installed PWA, and #page-content has
// overscroll-contain to keep the mobile Safari bounce from hijacking it).
// A full reload, not just a re-poll, so the SSR-only boxes (health, quota,
// push-timer state) come back fresh too. #app-shell is a full-height flex
// column with no body scroll; #page-content is the scrolling container. ---
(function () {
  var content = document.getElementById('page-content');
  var shell = document.getElementById('app-shell') || content;
  if (!content) {
    return;
  }

  var THRESHOLD = 64;
  var startY = 0;
  var pullDist = 0;
  var pulling = false;
  var indicator = null;
  var maxPull = 120;

  function getIndicator() {
    if (indicator) {
      return indicator;
    }

    indicator = document.createElement('div');
    indicator.setAttribute('aria-hidden', 'true');
    indicator.style.cssText =
      'position:fixed;top:0;left:0;right:0;min-height:64px;box-sizing:border-box;' +
      'display:flex;align-items:flex-end;justify-content:center;text-align:center;padding:24px 10px 12px;' +
      'pointer-events:none;z-index:60;font-size:12px;font-weight:500;' +
      'color:#c7d2fe;opacity:0;transition:opacity .15s ease;' +
      'background:rgba(79,70,229,.4);border-bottom:1px solid rgba(129,140,248,.7)';
    document.body.appendChild(indicator);
    return indicator;
  }

  content.addEventListener('touchstart', function (e) {
    if (content.scrollTop <= 0) {
      startY = e.touches[0].clientY;
      pulling = true;
      pullDist = 0;
    } else {
      pulling = false;
    }
  }, { passive: true });

  content.addEventListener('touchmove', function (e) {
    if (!pulling) {
      return;
    }

    pullDist = e.touches[0].clientY - startY;

    if (pullDist <= 0 || content.scrollTop > 0) {
      shell.style.transition = 'transform .2s ease-out';
      shell.style.transform = 'translate3d(0, 0, 0)';
      if (indicator) {
        indicator.style.opacity = '0';
      }
      return;
    }

    var resistedDist = Math.min(maxPull, pullDist * 0.55);
    shell.style.transition = 'none';
    shell.style.transform = 'translate3d(0, ' + resistedDist + 'px, 0)';
    var el = getIndicator();
    el.style.opacity = String(Math.min(1, resistedDist / (THRESHOLD * 0.55)));
    el.textContent = pullDist >= THRESHOLD ? 'Release to refresh' : 'Pull to refresh';

    if (e.cancelable) {
      e.preventDefault();
    }
  }, { passive: false });

  function endPull() {
    if (pulling && pullDist >= THRESHOLD) {
      window.location.reload();
      return;
    }

    shell.style.transition = 'transform .2s ease-out';
    shell.style.transform = 'translate3d(0, 0, 0)';
    if (indicator) {
      indicator.style.opacity = '0';
    }

    pulling = false;
    pullDist = 0;
  }

  content.addEventListener('touchend', endPull, { passive: true });
  content.addEventListener('touchcancel', endPull, { passive: true });
})();
