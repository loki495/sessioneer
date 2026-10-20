<li class="relative select-none rounded-xl border border-slate-800/60 bg-slate-900/30 px-4 py-3 flex items-start justify-between gap-3" data-headless-row data-session="<?= $this->e($id) ?>">
  <div class="min-w-0 flex-1">
    <a href="/session.php?session=<?= urlencode($id) ?>" class="absolute inset-0 rounded-xl" aria-label="Open transcript for <?= $this->e($title) ?>"></a>
    <div class="text-sm truncate text-slate-300" data-session-title><?= $this->e($title) ?></div>
    <?php if (!empty($directory)): ?><div class="font-mono text-xs text-slate-500 truncate mt-0.5"><?= $this->e($directory) ?></div><?php endif ?>
    <div class="text-xs text-slate-500 mt-1"><?= $relativeTime !== null ? $this->e($relativeTime) : 'no time' ?></div>
    <?php if ($status !== 'idle' || $blocked): ?>
      <div class="text-xs font-medium mt-1 <?= $blocked ? 'text-amber-400' : ($status === 'working' ? 'text-emerald-400' : 'text-slate-400') ?>"><?= $blocked ? 'blocked' : ($status === 'working' ? 'working' : 'idle') ?></div>
    <?php endif ?>
  </div>
  <div class="relative flex flex-col gap-2 shrink-0">
    <button type="button" data-rename-session="<?= $this->e($id) ?>" data-title="<?= $this->e($title) ?>" data-csrf="<?= $this->e($csrfToken) ?>" class="select-none w-full min-h-[2.25rem] rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs px-3 py-1.5">Rename</button>
    <form method="post" action="/" onsubmit="return confirm('Kill headless session <?= $this->e($id) ?>?');">
      <input type="hidden" name="action" value="kill">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
      <input type="hidden" name="session" value="<?= $this->e($id) ?>">
      <button type="submit" class="select-none min-h-[2.75rem] w-full rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">Kill</button>
    </form>
  </div>
</li>
