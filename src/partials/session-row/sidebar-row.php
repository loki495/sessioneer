<?php
  $agentCardStyle = App\Views\SessionRowView::agent_card_style($agentId ?? 'claude');
  $agentBadgeClass = App\Views\SessionRowView::agent_badge_class($agentId ?? 'claude');
?>
<?php // Stretched-link pattern (see row.php's own comments) - the <a> is
      // position:static (below plain text content) so clicks there fall through
      // to it, but ABOVE interactive children (forms, buttons) which have their
      // own relative wrappers to opt out of that fall-through. ?>
<a href="/session.php?session=<?= urlencode($name) ?>" class="block rounded-xl border px-4 py-3 active:bg-slate-800" style="<?= $agentCardStyle ?>" data-session="<?= $this->e($name) ?>">
  <div class="text-slate-200 truncate"><?= $this->e($title) ?></div>
  <?php if (!empty($agentLabel)): ?>
    <div class="select-none mt-0.5 mb-1.5 flex items-center gap-1.5">
      <span class="inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border <?= $agentBadgeClass ?>"><?= $this->e($agentLabel) ?></span>
      <?php if (($agentId ?? 'claude') === 'claude'): ?>
        <span class="inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border <?= App\Views\SessionRowView::profile_badge_class($profile ?? null) ?>"><?= $this->e(App\Views\SessionRowView::profile_label($profile ?? null)) ?></span>
      <?php endif ?>
      <?php if ($runtime === 'headless'): ?><span class="inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border bg-violet-900/30 text-violet-400 border-violet-700/40">Headless</span><?php endif ?>
    </div>
  <?php endif ?>
  <?php if (!empty($workdir)): ?><div class="select-none text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$workdir) ?></div><?php endif ?>
  <div class="select-none text-xs text-slate-400 mt-1 flex items-center gap-2 flex-wrap">
    <span class="inline-block w-1 h-1 rounded-full <?= $status === 'blocked' ? 'bg-amber-400' : ($status === 'working' ? 'bg-emerald-400' : 'bg-slate-600') ?>"></span>
    <span data-session-status class="<?= $status === 'blocked' ? 'text-amber-400' : ($status === 'working' ? 'text-emerald-400' : 'text-slate-400') ?>"><?= $this->e($status) ?></span>
    <?php if ($runtime !== 'headless' && $attached): ?>
      <span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>
      <span class="text-emerald-400">attached</span>
    <?php endif ?>
    <?php if ($contextUsedPercentage !== null): ?>
      <span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>
      <span<?= $contextUsedPercentage >= 80 ? ' class="text-amber-400"' : '' ?>>ctx <?= (int)round($contextUsedPercentage) ?>%</span>
    <?php endif ?>
    <?php if ($gitWorktree !== null): ?>
      <span class="inline-block w-1 h-1 rounded-full bg-slate-600"></span>
      <span class="truncate max-w-[8rem]" title="<?= $this->e($gitWorktree) ?>"><?= $this->e($gitWorktree) ?></span>
    <?php endif ?>
  </div>
  <div class="relative"><?= $blockedHtml ?></div>
</a>
