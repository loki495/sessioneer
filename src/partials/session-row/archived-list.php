<div>
  <div id="archived-agent-filters" class="flex gap-1.5 mb-3 flex-wrap">
    <button type="button" data-agent-filter="all" class="archived-agent-filter-btn select-none text-xs font-medium px-3 py-1 rounded-full border bg-indigo-600 text-white border-indigo-500" data-selected="1">All</button>
    <button type="button" data-agent-filter="claude" class="archived-agent-filter-btn select-none text-xs font-medium px-3 py-1 rounded-full border bg-slate-800 text-slate-400 border-slate-700">Claude Code</button>
    <button type="button" data-agent-filter="antigravity" class="archived-agent-filter-btn select-none text-xs font-medium px-3 py-1 rounded-full border bg-slate-800 text-slate-400 border-slate-700">Antigravity</button>
    <button type="button" data-agent-filter="opencode" class="archived-agent-filter-btn select-none text-xs font-medium px-3 py-1 rounded-full border bg-slate-800 text-slate-400 border-slate-700">OpenCode</button>
    <button type="button" data-agent-filter="codex" class="archived-agent-filter-btn select-none text-xs font-medium px-3 py-1 rounded-full border bg-slate-800 text-slate-400 border-slate-700">Codex</button>
  </div>
  <?php /* text-base (16px), not text-sm - iOS Safari auto-zooms the whole
       viewport in on focusing any text input rendered under 16px. */ ?>
  <div class="relative mb-3">
    <input type="text" id="archived-search" placeholder="Filter by title, folder, or name&hellip;" class="select-none w-full rounded-lg border border-slate-700 bg-slate-800 pl-3 pr-9 py-2 text-base text-slate-200 placeholder-slate-500" autocomplete="off">
    <button type="button" id="archived-search-clear-btn" aria-label="Clear filter" tabindex="-1"
      class="hidden absolute top-1/2 right-1.5 -translate-y-1/2 w-6 h-6 flex items-center justify-center rounded text-slate-500 active:text-slate-300 text-lg leading-none">&times;</button>
  </div>
  <ul id="archived-rows" class="flex flex-col gap-3"><?= $rowsHtml ?></ul>
  <p id="archived-no-matches" class="hidden select-none text-center text-xs text-slate-500 py-4">No sessions match your search.</p>
  <button type="button" id="archived-load-more-btn" class="hidden select-none w-full mt-3 rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-sm font-medium px-4 py-2">Load more</button>
</div>
