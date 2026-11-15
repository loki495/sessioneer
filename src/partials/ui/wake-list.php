<?php if ($rowsHtml === ''): ?>
<p class="ui-muted ui-small ui-wake-empty">No wake-ups scheduled.</p>
<?php else: ?>
<?= $rowsHtml ?>
<?php if ($truncated): ?><p class="ui-muted ui-small ui-wake-truncated">Only the first 200 queued jobs were checked.</p><?php endif ?>
<?php endif ?>
