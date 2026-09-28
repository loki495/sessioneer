<header id="session-header" class="select-none sticky top-0 z-20 bg-slate-950/95 backdrop-blur border-b border-slate-800">
  <div class="max-w-2xl lg:max-w-4xl mx-auto px-4 py-2 grid grid-cols-[auto_1fr_auto] items-center gap-2">
    <a href="/" class="text-sm text-slate-400 hover:underline whitespace-nowrap" aria-label="All sessions" title="All sessions">&larr;</a>
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
        <?php // Same pill/placement as SessionRowView's own headless badge
              // (sidebar-row.php, row.php) - kept literally identical so it
              // reads as the same signal everywhere it shows up. ?>
        <?php if ($found && ($detail['runtime'] ?? null) === 'headless'): ?>
          <span class="shrink-0 inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border bg-violet-900/30 text-violet-400 border-violet-700/40">Headless</span>
        <?php endif ?>
      </div>
      <?php // The workdir itself is NOT shown here (removed 2026-09-28,
            // Andres's own ask) - the sidebar's own "This session" block
            // already shows it, with a copy button the header never had. ?>
      <?php $gitBranch = $found && !empty($detail['git_branch']) ? (string)$detail['git_branch'] : ''; ?>
      <div id="header-branch" class="text-[11px] text-slate-500 truncate<?= $gitBranch === '' ? ' hidden' : '' ?>"><?= htmlspecialchars($gitBranch, ENT_QUOTES) ?></div>
    </div>
    <div class="flex items-center gap-1 justify-self-end">
      <button type="button" id="sidebar-toggle-btn" aria-label="Show other sessions"
        class="relative text-slate-400 active:text-slate-200 -mr-2 px-2 py-1 text-lg leading-none">
        &#9776;
        <span id="sidebar-notify-dot" class="hidden absolute top-0.5 right-1 w-2 h-2 rounded-full"></span>
      </button>
    </div>
  </div>
</header>
