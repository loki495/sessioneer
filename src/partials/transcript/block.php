<?php
// break-words (not break-all, used elsewhere for compact collapsed summary lines) - this is
// prose, so only a genuinely too-long token (a long constant name, URL, hash, ...) should ever
// break mid-word; normal short words shouldn't. Found live: a message containing
// "FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE" (no spaces, 51 chars) widened the whole
// page horizontally without this.
?>
<?php if ($kind === 'text'): ?><div class="copy-block" data-line="<?= (int)$line ?>"><div class="markdown-body text-sm lg:text-base text-slate-100"><?= $markdownHtml ?></div><span class="copy-source sr-only"><?= $this->e($text) ?></span><button type="button" class="copy-btn select-none text-[11px] text-slate-500 active:text-slate-300 mt-0.5">Copy</button></div>
<?php elseif ($kind === 'plan'): ?><div class="copy-block rounded border border-amber-800/40 bg-amber-950/20 px-3 py-2" data-line="<?= (int)$line ?>"><p class="copy-source whitespace-pre-wrap break-words text-sm lg:text-base text-amber-100"><?= $this->e($text) ?></p><button type="button" class="copy-btn select-none text-[11px] text-amber-700 active:text-amber-500 mt-1">Copy</button></div>
<?php elseif ($kind === 'tool_use'): ?><div class="tool-use-block<?= $subagentClass ?>" data-line="<?= (int)$line ?>"><?php if (!empty($description)): ?><p class="text-sm lg:text-base text-sky-200 mb-1"><?= $this->e($description) ?></p><?php endif ?><?= $collapsibleHtml ?></div>
<?php elseif ($kind === 'tool_result'): ?><div class="tool-detail<?= $subagentClass ?>" data-line="<?= (int)$line ?>"><?= $collapsibleHtml ?></div><?= $imageHtml ?><?= $attachmentsHtml ?>
<?php elseif ($kind === 'task_notification'): ?><div class="tool-detail<?= $subagentClass ?>" data-line="<?= (int)$line ?>"><?php if (!empty($description)): ?><p class="text-sm lg:text-base text-fuchsia-200 mb-1"><?= $this->e($description) ?></p><?php endif ?><?= $collapsibleHtml ?></div>
<?php elseif ($kind === 'question'): ?><div class="question-block" data-line="<?= (int)$line ?>"><?= $questionHtml ?></div>
<?php elseif ($kind === 'image' && $imageHtml !== ''): ?><?= $imageHtml ?>
<?php elseif ($text !== ''): ?><div class="copy-block" data-line="<?= (int)$line ?>"><p class="copy-source break-words text-xs text-slate-600"><?= $this->e($text) ?></p><button type="button" class="copy-btn select-none text-[11px] text-slate-700 active:text-slate-500">Copy</button></div>
<?php endif ?>
