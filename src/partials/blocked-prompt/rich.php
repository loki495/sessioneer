<?php // overflow-x-auto: belt-and-suspenders for content min-w-0 elsewhere in
      // the ancestor chain can't shrink-to-fit (a long unbroken token with no
      // space to wrap on) - this card scrolls on its own rather than
      // widening past the panel/page around it. ?>
<div class="mt-2 rounded-lg px-3 py-2 text-xs bg-amber-900/40 text-amber-200 border border-amber-700/60 overflow-x-auto"><?= $lastMessageHtml ?><p class="font-medium break-words">Waiting on input: <?= $this->e($blockedReason) ?></p><?= $optionsHtml ?></div>
