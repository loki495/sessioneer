<?php if ($isImage): ?>
<div>
  <img src="<?= $this->e($url) ?>" loading="lazy" alt="<?= $this->e($filename) ?>" class="transcript-image rounded border border-slate-800 cursor-pointer w-24 h-24 object-cover">
  <a href="<?= $this->e($url) ?>" target="_blank" rel="noopener" class="block mt-0.5 max-w-24 truncate text-[11px] text-slate-500 active:text-slate-300"><?= $this->e($filename) ?></a>
</div>
<?php else: ?>
<?php // download (not target="_blank") - target="_blank" on a
      // Content-Disposition: attachment response opens a real but
      // permanently blank tab (it's a download, not a page load), and
      // refreshing that blank tab re-triggers the same non-page. download
      // saves the file with no navigation at all, so the session page
      // itself never moves. ?>
<a href="<?= $this->e($url) ?>" download="<?= $this->e($filename) ?>" class="flex items-center gap-1.5 rounded border border-slate-800 bg-slate-950/60 px-2 py-1.5 text-xs text-sky-300 active:text-sky-200">
  <span aria-hidden="true">&#8681;</span>
  <span class="truncate max-w-[12rem]"><?= $this->e($filename) ?></span>
  <span class="shrink-0 text-slate-500"><?= $this->e($sizeLabel) ?></span>
</a>
<?php endif ?>
