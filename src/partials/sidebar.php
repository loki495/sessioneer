<div id="sidebar-overlay" class="hidden fixed inset-0 bg-black/60 z-30"></div>
<aside id="sidebar"
  class="select-none fixed top-0 right-0 h-full w-72 max-w-[85vw] bg-slate-900 border-l border-slate-800 z-40 translate-x-full transition-transform duration-200 ease-out overflow-y-auto overscroll-contain">
  <div class="flex items-center justify-between px-4 py-3 border-b border-slate-800 sticky top-0 bg-slate-900">
    <span class="text-sm font-medium text-slate-200">Other sessions</span>
    <button type="button" id="sidebar-close-btn" aria-label="Close" class="text-slate-400 active:text-slate-200 px-1 text-lg leading-none">&times;</button>
  </div>
  <?php if ($found): ?>
    <div class="px-4 py-3 border-b border-slate-800 flex flex-col gap-2">
      <span class="block text-xs font-medium text-slate-500">This session</span>
      <?php // The AGENT's own session id (Claude Code's transcript/--resume
            // UUID, SessionService::build_session_entry()'s 'agent_session_id')
            // - not sessioneer's own tmux/app session name ($sessionName,
            // already shown truncated in the header and used in the URL).
            // Null until the agent's first turn assigns one (e.g. a brand
            // new session with no reply yet). ?>
      <?php if (!empty($detail['agent_session_id'])): ?>
        <div class="copy-block flex items-center justify-between gap-2">
          <div class="min-w-0">
            <span class="block text-[11px] text-slate-500">Session ID</span>
            <span class="copy-source block font-mono text-xs text-slate-300 truncate" title="<?= htmlspecialchars((string)$detail['agent_session_id'], ENT_QUOTES) ?>"><?= htmlspecialchars((string)$detail['agent_session_id'], ENT_QUOTES) ?></span>
          </div>
          <button type="button" class="copy-btn select-none shrink-0 text-[11px] text-slate-500 active:text-slate-300">Copy</button>
        </div>
      <?php endif ?>
      <?php if (!empty($detail['workdir'])): ?>
        <div class="copy-block flex items-center justify-between gap-2">
          <div class="min-w-0">
            <span class="block text-[11px] text-slate-500">Working directory</span>
            <span class="copy-source block font-mono text-xs text-slate-300 truncate" title="<?= htmlspecialchars((string)$detail['workdir'], ENT_QUOTES) ?>"><?= htmlspecialchars((string)$detail['workdir'], ENT_QUOTES) ?></span>
          </div>
          <button type="button" class="copy-btn select-none shrink-0 text-[11px] text-slate-500 active:text-slate-300">Copy</button>
        </div>
      <?php endif ?>
    </div>
  <?php endif; ?>
  <?php if ($found): ?>
    <div class="px-4 py-3 border-b border-slate-800">
      <span class="block text-xs font-medium text-slate-500 mb-2">Search</span>
      <!-- text-base (16px), not text-sm - iOS Safari auto-zooms the whole
           viewport in on focusing any text input rendered under 16px, no
           way to opt out of that short of the font size itself.
           appearance-none suppresses the native WebKit search-cancel
           button - Andres wants ONE consistent custom clear button
           everywhere (native ones vary in styling/position by browser and
           textareas never get one at all), not that plus a second,
           differently-styled native one alongside it. -->
      <div class="relative">
        <input type="search" id="session-search-input" placeholder="Search messages&hellip;" autocomplete="off"
          class="appearance-none w-full rounded-lg border border-slate-700 bg-slate-800 pl-2 pr-8 py-1.5 text-base text-slate-200 placeholder:text-slate-500">
        <button type="button" id="session-search-input-clear-btn" aria-label="Clear search" tabindex="-1"
          class="hidden absolute top-1/2 right-1 -translate-y-1/2 w-6 h-6 flex items-center justify-center rounded text-slate-500 active:text-slate-300 text-lg leading-none">&times;</button>
      </div>
      <div class="flex items-center gap-3 mt-1.5 text-xs text-slate-400">
        <label class="flex items-center gap-1">
          <input type="radio" name="session-search-scope" id="session-search-scope-session" value="session" checked class="text-indigo-500 focus:ring-indigo-500">
          This session
        </label>
        <label class="flex items-center gap-1">
          <input type="radio" name="session-search-scope" id="session-search-scope-global" value="global" class="text-indigo-500 focus:ring-indigo-500">
          All sessions
        </label>
      </div>
      <div id="session-search-results" class="mt-2 flex flex-col gap-1.5 text-sm"></div>
    </div>
  <?php endif; ?>
  <div id="sidebar-list" class="flex flex-col gap-2 px-3 py-3 text-sm">
    <div class="px-1 text-slate-500">Loading&hellip;</div>
  </div>
  <div class="px-4 py-3 border-t border-slate-800 flex flex-col gap-2">
    <span class="block text-xs font-medium text-slate-500 mb-1">Settings</span>
    <label class="flex items-center gap-2 text-sm text-slate-300">
      <input type="checkbox" id="confirm-before-answer-toggle" class="rounded border-slate-600 bg-slate-800">
      Confirm before sending prompt answers
    </label>
    <label class="flex items-center gap-2 text-sm text-slate-300">
      <input type="checkbox" id="show-subagent-toggle" class="rounded border-slate-600 bg-slate-800" checked>
      Show subagent calls and outputs
    </label>
    <label class="flex items-center gap-2 text-sm text-slate-300">
      <input type="checkbox" id="show-worker-sessions-toggle" class="rounded border-slate-600 bg-slate-800">
      Show worker sessions
    </label>
  </div>
  <?php if ($found): ?>
    <!-- Bare, no border/padding of its own (unlike the sections below it) -
         render_todo_list_html() returns '' (nothing, zero height) for a
         session that's never called TodoWrite or the Task family (TaskCreate/
         TaskUpdate) or has cleared its list back to empty, and its OWN
         template carries the full border-t/px-4/py-3 section styling when
         there IS something to show - a wrapper with its own border here
         would render as an empty bordered box for the common case of a
         session that never touches either tool at all. -->
    <div id="todo-list-section"><?= \App\Views\TranscriptView::render_todo_list_html($detail) ?></div>
    <div class="px-4 py-3 border-t border-slate-800">
      <button type="button" id="todo-file-link"
        class="w-full text-left text-xs font-medium text-indigo-400 active:text-indigo-300">
        Open todo file
      </button>
      <span id="todo-file-status" class="hidden block mt-1 text-xs text-slate-500"></span>
    </div>
    <div class="px-4 py-3 border-t border-slate-800">
      <div class="flex items-center justify-between mb-2">
        <span class="text-xs font-medium text-slate-500">Uploaded files</span>
        <span id="uploaded-files-total" class="text-xs text-slate-500"></span>
      </div>
      <div id="uploaded-files-list" class="flex flex-col gap-1.5 text-sm mb-2">
        <div class="text-slate-500 text-xs">Loading&hellip;</div>
      </div>
      <button type="button" id="delete-all-uploads-btn" class="hidden w-full rounded-lg border border-red-900/60 bg-red-950/30 active:bg-red-900/40 text-red-300 text-xs font-medium px-3 py-1.5">
        Delete all
      </button>
    </div>
    <div class="px-4 py-3 border-t border-slate-800">
      <span class="block text-xs font-medium text-slate-500 mb-2">Plan/handoff files</span>
      <div id="plan-files-list" class="flex flex-col gap-1.5 text-sm">
        <div class="text-slate-500 text-xs">Loading&hellip;</div>
      </div>
    </div>
    <div class="px-4 py-3 border-t border-slate-800">
      <form method="post" action="/" onsubmit="return confirm('Archive session <?= htmlspecialchars($sessionName, ENT_QUOTES) ?>?');">
        <input type="hidden" name="action" value="kill">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <input type="hidden" name="session" value="<?= htmlspecialchars($sessionName, ENT_QUOTES) ?>">
        <button type="submit"
          class="w-full min-h-[2.75rem] rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">
          Archive
        </button>
      </form>
    </div>
  <?php endif; ?>
</aside>
