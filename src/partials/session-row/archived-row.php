<?php
  $agentCardStyle = match ($agentId ?? 'claude') {
    'opencode' => 'background-color: rgba(46, 16, 101, 0.22); border-color: rgba(109, 40, 217, 0.32)',
    'antigravity' => 'background-color: rgba(69, 26, 3, 0.18); border-color: rgba(180, 83, 9, 0.28)',
    default => 'background-color: rgba(15, 23, 42, 0.5); border-color: rgb(30, 41, 59)',
  };
?>
<li class="relative rounded-xl border px-4 py-3 flex items-start justify-between gap-3" style="<?= $agentCardStyle ?>" data-archived-row data-agent="<?= $this->e($agentId ?? 'claude') ?>">
  <a href="/archived_session.php?agent_session_id=<?= urlencode($agentSessionId) ?>" class="absolute inset-0 rounded-xl" aria-label="View archived transcript for <?= $this->e($title) ?>"></a>
  <div class="min-w-0 flex-1">
    <div class="select-none text-sm leading-tight truncate"><?= $this->e($title) ?></div>
    <?php if (!empty($agentLabel)): ?>
      <?php
        $agentBadgeClass = match ($agentId ?? 'claude') {
          'opencode' => 'bg-violet-900/50 text-violet-300 border-violet-700/50',
          'antigravity' => 'bg-amber-900/40 text-amber-300 border-amber-700/40',
          default => 'bg-slate-800 text-slate-400 border-slate-700',
        };
      ?>
      <div class="select-none mt-0.5 mb-1 flex items-center gap-1.5">
        <span class="inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border <?= $agentBadgeClass ?>"><?= $this->e($agentLabel) ?></span>
        <?php if (($agentId ?? 'claude') === 'claude'): ?>
          <span class="inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border <?= App\Views\SessionRowView::profile_badge_class($profile ?? null) ?>"><?= $this->e(App\Views\SessionRowView::profile_label($profile ?? null)) ?></span>
        <?php endif ?>
        <?php if ($runtime === 'headless'): ?><span class="inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border bg-violet-900/30 text-violet-400 border-violet-700/40">Headless</span><?php endif ?>
      </div>
    <?php endif ?>
    <?php if (!empty($cwd)): ?><div class="select-none text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$cwd) ?></div><?php endif ?>
    <div class="select-none text-xs text-slate-400 mt-1"><?= $this->e($relativeTime) ?></div>
  </div>
  <?php if (!empty($cwd)): ?>
  <form method="post" action="/" class="relative">
    <input type="hidden" name="action" value="resume">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
    <input type="hidden" name="agent_session_id" value="<?= $this->e($agentSessionId) ?>">
    <input type="hidden" name="workdir" value="<?= $this->e((string)$cwd) ?>">
    <button type="submit" class="select-none min-h-[2.75rem] shrink-0 rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-200 font-medium text-sm px-4 py-2">Resume</button>
  </form>
  <?php endif ?>
</li>
