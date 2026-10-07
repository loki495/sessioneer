<details class="tool-call-entry rounded-lg border border-slate-800 bg-slate-900/50 px-3 py-2 lg:max-w-[75%] lg:self-start"><summary class="select-none cursor-pointer truncate text-xs font-medium text-slate-400"><?php if ($summaryDescription !== null): ?><span class="tool-call-description text-slate-300"><?= $this->e($summaryDescription) ?></span><span class="block truncate"><?= $this->e($summaryLabel) ?></span><?php else: ?><?= $this->e($summaryLabel) ?><?php endif ?></summary>
<div class="mt-2 flex flex-col gap-1.5">
<?php if ($timestamp !== ''): ?><div class="select-none text-xs text-slate-500"><?= $this->e($timestamp) ?></div><?php endif ?>
<?= $callHtml ?>
<div class="tool-call-result-slot"><?= $resultHtml ?></div>
</div>
</details>
