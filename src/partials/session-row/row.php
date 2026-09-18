<?php
  $agentCardStyle = App\Views\SessionRowView::agent_card_style($agentId ?? 'claude');
?>
<?php // Worker sessions (see the orchestrator-worker skill's session-tagging
      // convention) render `hidden` by DEFAULT, server-side - not toggled on
      // by CSS/JS after the fact - so there's no flash of a worker row
      // before the show-worker-sessions preference (default: hidden) has a
      // chance to run. Only JS-removing this class (see
      // show-worker-sessions-toggle in sidebar.js) ever reveals one. ?>
<li class="relative rounded-xl border px-4 py-3 flex items-start justify-between gap-3 active:bg-slate-800<?= $kind === 'worker' ? ' hidden' : '' ?>" data-kind="<?= $this->e($kind) ?>" style="<?= $agentCardStyle ?>">
  <?php // Stretched-link pattern: a plain <a> absolutely covering the whole
        // card, painted below (position:static, no stacking context of its
        // own) plain text content, so a click there falls through to it -
        // but ABOVE anything that's actually interactive, which needs its
        // own "relative" wrapper to opt out of that fall-through (found
        // live: giving the WHOLE content column "relative" instead lifted
        // the plain text too, silently swallowing every click on the card
        // and breaking navigation entirely - only the specific interactive
        // bits below get their own wrapper). Never nests a <button>/<form>
        // inside an <a> itself (invalid HTML, unpredictable across
        // browsers - notably mobile Safari, which this app already has to
        // special-case elsewhere) the way wrapping the whole card's markup
        // in one <a> would have. ?>
  <a href="/session.php?session=<?= urlencode($name) ?>" class="absolute inset-0 rounded-xl" aria-label="Open transcript for <?= $this->e($title) ?>"></a>
  <div class="min-w-0 flex-1">
    <div class="select-none text-sm leading-tight truncate"><?= $this->e($title) ?></div>
    <?php if (!empty($agentLabel)): ?>
      <?php
        $agentBadgeClass = App\Views\SessionRowView::agent_badge_class($agentId ?? 'claude');
      ?>
      <div class="select-none mt-0.5 mb-1.5 flex items-center gap-1.5">
        <span class="inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border <?= $agentBadgeClass ?>"><?= $this->e($agentLabel) ?></span>
        <?php // Profile/account badge is Claude-only for now - other agents have no
              // config-dir profile concept yet (see Config::claude_profile_config()'s
              // own docblock), so build_session_entry() always resolves their
              // 'profile' to null and a badge here would just be noise. ?>
        <?php if (($agentId ?? 'claude') === 'claude'): ?>
          <span class="inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border <?= App\Views\SessionRowView::profile_badge_class($profile ?? null) ?>"><?= $this->e(App\Views\SessionRowView::profile_label($profile ?? null)) ?></span>
        <?php endif ?>
        <?php if ($runtime === 'headless'): ?><span class="inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border bg-violet-900/30 text-violet-400 border-violet-700/40">Headless</span><?php endif ?>
        <?php if ($kind === 'worker'): ?><span class="inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border bg-sky-900/30 text-sky-400 border-sky-700/40">Worker</span><?php endif ?>
      </div>
    <?php endif ?>
    <?php if ($kind === 'worker' && $parentSessionId !== null): ?>
      <div class="relative select-none text-xs text-slate-500 mt-0.5">
        spawned by <a href="/session.php?session=<?= urlencode($parentSessionId) ?>" class="underline decoration-dotted text-slate-400"><?= $this->e($parentSessionId) ?></a>
      </div>
    <?php endif ?>
    <?php if ($hasExplicitTitle): ?><div class="select-none font-mono text-xs text-slate-500 truncate mt-0.5"><?= $this->e($name) ?></div><?php endif ?>
    <?php if (!empty($workdir)): ?><div class="select-none text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$workdir) ?></div><?php endif ?>
    <div class="select-none text-xs text-slate-400 mt-1 flex items-center gap-2 flex-wrap">
      <span><?= $this->e($relativeTime) ?></span>
      <span class="inline-block w-1 h-1 rounded-full <?= $status === 'blocked' ? 'bg-amber-400' : ($status === 'working' ? 'bg-emerald-400' : 'bg-slate-600') ?>"></span>
      <span class="<?= $status === 'blocked' ? 'text-amber-400' : ($status === 'working' ? 'text-emerald-400' : 'text-slate-400') ?>"><?= $this->e($status) ?></span>
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
    <div class="relative mt-1">
      <button type="button" class="select-none show-recent-btn rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-300 text-xs font-medium px-3 py-1.5" data-session="<?= $this->e($name) ?>" data-loaded="0">Show last 3 messages</button>
      <div class="recent-messages hidden mt-1 flex flex-col gap-1 max-h-64 overflow-y-auto overscroll-contain"></div>
    </div>
    <div class="relative"><?= $blockedHtml ?></div>
  </div>
  <form method="post" action="/" class="relative" onsubmit="return confirm('Kill session <?= $this->e($name) ?>?');">
    <input type="hidden" name="action" value="kill">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
    <input type="hidden" name="session" value="<?= $this->e($name) ?>">
    <button type="submit" class="select-none min-h-[2.75rem] shrink-0 rounded-lg bg-red-900/70 active:bg-red-800 text-red-100 font-medium text-sm px-4 py-2">Kill</button>
  </form>
</li>
