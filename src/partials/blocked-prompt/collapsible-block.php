<?php if ($isExpandable): ?><details class="group rounded border <?= $borderClass ?> bg-slate-950/60"><summary class="block w-full text-left cursor-pointer select-none whitespace-pre-wrap break-all px-2 py-1.5 text-xs <?= $textClass ?>"><span class="group-open:hidden"><?= $prefix ?><?= $this->e($summary) ?></span><span class="hidden group-open:inline">&#9650; Collapse</span></summary><pre class="copy-source whitespace-pre-wrap break-words overflow-auto overscroll-contain max-h-64 px-2 pb-1.5 text-xs <?= $textClass ?>"><?= $this->e($rawText) ?></pre>
<?= $footerHtml ?>
</details><?php else: ?><div class="copy-block rounded border <?= $borderClass ?> bg-slate-950/60 overflow-x-auto px-2 py-1.5 text-xs <?= $textClass ?> flex items-start justify-between gap-2">
<span class="whitespace-pre"><?= $prefix ?><span class="copy-source"><?= $this->e($rawText) ?></span></span>
<button type="button" class="copy-btn select-none shrink-0 text-[11px] text-slate-500 active:text-slate-300">Copy</button>
</div><?php endif ?>
