<div id="quota-footer" class="select-none min-w-0 flex-1" style="min-width:0;flex:1 1 auto" data-session="<?= $this->e($sessionName) ?>">
  <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
    <button type="button" id="quota-toggle-btn" aria-controls="quota-info" aria-expanded="true" title="Toggle quota details" class="flex min-w-0 items-center gap-1 text-left text-xs text-slate-500 active:text-slate-300<?= $sessionName !== '' ? ' relative w-full sm:w-auto' : '' ?>">
      <span id="quota-toggle-icon"<?= $sessionName !== '' ? ' class="absolute left-0 sm:static"' : '' ?> aria-hidden="true">&#9662;</span>
      <span id="quota-toggle-label"<?= $sessionName !== '' ? ' class="hidden sm:inline"' : '' ?>>Quota</span>
      <?php if ($sessionName !== ''): ?>
        <span id="quota-toggle-summary" class="flex min-w-0 flex-1 items-start justify-between gap-2 text-center sm:hidden">
          <?php foreach (['session' => '5h', 'week_all' => 'wk', 'context' => 'ctx'] as $bucket => $label): ?>
            <span data-quota-summary-part="<?= $bucket ?>" class="flex min-w-0 flex-1 flex-col items-center">
              <span data-quota-summary-value class="font-medium text-slate-300"><?= $label ?> &mdash;</span>
              <span data-quota-summary-reset class="hidden whitespace-nowrap text-[9px] text-slate-500"></span>
            </span>
          <?php endforeach; ?>
        </span>
      <?php endif; ?>
    </button>
    <?= $extraHtml ?>
  </div>
  <div id="quota-info" class="flex flex-col gap-0.5 min-w-0 text-sm font-medium max-h-40 overflow-y-auto overscroll-contain pr-1" aria-live="polite">
    <span class="text-slate-500">Loading quota&hellip;</span>
  </div>
</div>
<script src="<?= \App\Assets::versioned_url('/js/quota-footer.js') ?>"></script>
