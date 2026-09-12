<li class="select-none rounded-xl border border-slate-800/60 bg-slate-900/30 px-4 py-3 flex items-start justify-between gap-3" data-bare-row data-pid="<?= $pid ?>" data-csrf-token="<?= $this->e($csrfToken) ?>">
  <div class="min-w-0 flex-1">
    <?php if (!empty($title)): ?><div class="text-sm truncate text-slate-300"><?= $this->e((string)$title) ?></div><?php endif ?>
    <div class="font-mono text-xs text-slate-500 truncate mt-0.5">pid <?= $pid ?><?= $tmuxSession !== null ? ' · tmux: ' . $this->e($tmuxSession) : ' · no tmux (plain process)' ?></div>
    <?php if (!empty($cwd)): ?><div class="text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$cwd) ?></div><?php endif ?>
    <div class="text-xs text-slate-500 mt-1"><?= $startedAt !== null ? $this->e($startedAt) : 'start time unknown' ?></div>
    <div class="take-over-picker hidden mt-2"></div>
    <div class="bare-detail hidden mt-2"></div>
  </div>
  <div class="flex flex-col gap-2 shrink-0">
    <?php if (!empty($cwd)): ?>
    <button type="button" class="bare-identify-btn select-none min-h-[2.75rem] w-full rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-200 font-medium text-sm px-4 py-2">Identify</button>
    <form method="post" action="/take_over_bare.php" class="take-over-form" onsubmit="return confirm('Take over pid <?= $pid ?><?= $tmuxSession !== null ? ' (tmux session ' . $this->e($tmuxSession) . ')' : '' ?>? This process was not started by this tool and will be closed.');">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
      <input type="hidden" name="pid" value="<?= $pid ?>">
      <button type="submit" class="select-none min-h-[2.75rem] w-full rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-200 font-medium text-sm px-4 py-2">Take over</button>
    </form>
    <?php endif ?>
    <form method="post" action="/" onsubmit="return confirm('Kill pid <?= $pid ?><?= $tmuxSession !== null ? ' (tmux session ' . $this->e($tmuxSession) . ')' : '' ?>? This process was not started by this tool.');">
      <input type="hidden" name="action" value="kill_bare">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
      <input type="hidden" name="pid" value="<?= $pid ?>">
      <button type="submit" class="select-none min-h-[2.75rem] w-full rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">Kill</button>
    </form>
  </div>
</li>
