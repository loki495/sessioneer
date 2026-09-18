<header id="session-header" class="select-none sticky top-0 z-20 bg-slate-950/95 backdrop-blur border-b border-slate-800">
  <div class="max-w-2xl lg:max-w-4xl mx-auto px-4 py-2 grid grid-cols-[auto_1fr_auto] items-center gap-2">
    <a href="/" class="text-sm text-slate-400 hover:underline whitespace-nowrap">&larr; All sessions</a>
    <div class="min-w-0 text-center">
      <div class="flex items-center justify-center gap-1.5 min-w-0">
        <div id="header-title" class="min-w-0 text-sm font-medium text-slate-200 truncate cursor-pointer"
          title="<?= $found ? htmlspecialchars((string)($detail['title'] ?? $detail['name']), ENT_QUOTES) : '' ?>">
          <?= $found ? htmlspecialchars((string)($detail['title'] ?? $detail['name']), ENT_QUOTES) : '' ?>
        </div>
        <?php // Account/profile badge - Claude-only for now, same gate as the
              // session-list rows (see SessionRowView::profile_badge_class()'s
              // own docblock: other agents have no config-dir profile concept
              // yet, so their 'profile' always resolves to null). Inline with
              // the title (Andres's own ask, 2026-09-18), not stacked below it. ?>
        <?php if ($found && ($detail['agent'] ?? 'claude') === 'claude'): ?>
          <span class="shrink-0 inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border <?= App\Views\SessionRowView::profile_badge_class($detail['profile'] ?? null) ?>"><?= htmlspecialchars(App\Views\SessionRowView::profile_label($detail['profile'] ?? null), ENT_QUOTES) ?></span>
        <?php endif ?>
      </div>
      <?php if ($found && !empty($detail['workdir'])): ?>
        <div id="header-cwd" class="text-[11px] text-slate-500 truncate cursor-pointer" title="<?= htmlspecialchars((string)$detail['workdir'], ENT_QUOTES) ?>"><?= htmlspecialchars((string)$detail['workdir'], ENT_QUOTES) ?></div>
      <?php endif ?>
    </div>
    <div class="flex items-center gap-1 justify-self-end">
      <select id="poll-interval-select" aria-label="Polling interval"
        class="text-xs font-medium pl-1.5 pr-5 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-400">
        <option value="1000">1s</option>
        <option value="3000" selected>3s</option>
        <option value="5000">5s</option>
        <option value="10000">10s</option>
        <option value="15000">15s</option>
      </select>
      <button type="button" id="sidebar-toggle-btn" aria-label="Show other sessions"
        class="relative text-slate-400 active:text-slate-200 -mr-2 px-2 py-1 text-lg leading-none">
        &#9776;
        <span id="sidebar-notify-dot" class="hidden absolute top-0.5 right-1 w-2 h-2 rounded-full"></span>
      </button>
    </div>
  </div>
</header>
