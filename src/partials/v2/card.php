<?php
  $isWorker = $kind === 'worker';
  $readOnly = $runtime !== 'headless';
  $hasFreeText = false;
  foreach ($promptOptions as $opt) {
    if (stripos((string)$opt['label'], 'type something') !== false) { $hasFreeText = true; }
  }
?>
<?php /* Whole-card tap target: the title link stretches over the card (.v2-link-cover::after); anything interactive sits above it. */ ?>
<article class="v2-card v2-status-<?= $this->e($status) ?>" data-session="<?= $this->e($name) ?>" data-status="<?= $this->e($status) ?>" data-kind="<?= $this->e($kind) ?>" data-starred="<?= $starred ? 1 : 0 ?>">
  <div class="v2-card-main">
    <div class="v2-card-head">
      <span class="v2-dot v2-dot-<?= $this->e($status) ?>" role="img" aria-label="<?= $this->e($status === 'blocked' ? 'needs input' : $status) ?>"></span>
      <a class="v2-title v2-link-cover" href="/v2/session?session=<?= urlencode($name) ?>" data-title><?= $this->e($title) ?></a>
      <button type="button" class="v2-icon-btn v2-star<?= $starred ? ' is-on' : '' ?>" data-act="star" aria-pressed="<?= $starred ? 'true' : 'false' ?>" aria-label="<?= $starred ? 'Unstar' : 'Star' ?> session"><?= $starred ? '&#9733;' : '&#9734;' ?></button>
      <details class="v2-menu">
        <summary class="v2-icon-btn" aria-label="Session actions">&#8943;</summary>
        <div class="v2-menu-list">
          <button type="button" data-act="rename" data-title="<?= $this->e($title) ?>">Rename</button>
          <?php if ($readOnly && $agentId === 'claude'): ?>
            <button type="button" data-act="switch" data-runtime="headless">Take over</button>
          <?php endif ?>
          <button type="button" class="v2-danger-text" data-act="kill">Kill session</button>
        </div>
      </details>
    </div>

    <div class="v2-chips">
      <span class="v2-chip v2-agent-<?= $this->e($agentId) ?>"><?= $this->e($agentLabel) ?></span>
      <?php if ($profile !== null): ?><span class="v2-chip<?= $profile !== 'Personal' ? ' v2-chip-accent' : '' ?>"><?= $this->e($profile) ?></span><?php endif ?>
      <?php if ($readOnly): ?><span class="v2-chip v2-chip-warn">Terminal, read only</span><?php endif ?>
      <?php if ($isWorker): ?><span class="v2-chip v2-chip-accent">Worker</span><?php endif ?>
    </div>

    <?php if ($isWorker && $parentSessionId !== null): ?>
      <div class="v2-muted v2-small">spawned by <a class="v2-inline-link" href="/v2/session?session=<?= urlencode((string)$parentSessionId) ?>"><?= $this->e((string)$parentSessionId) ?></a></div>
    <?php endif ?>
    <?php if (!empty($workdir)): ?><div class="v2-path"><?= $this->e((string)$workdir) ?></div><?php endif ?>

    <div class="v2-meta">
      <span><?= $this->e($relativeTime) ?></span>
      <span class="v2-status-text"><?= $this->e($status === 'blocked' ? 'needs input' : $status) ?></span>
      <?php if ($contextPct !== null): ?><span class="<?= $contextPct >= 80 ? 'v2-warn' : '' ?>">ctx <?= $contextPct ?>%</span><?php endif ?>
      <?php if ($worktree !== null): ?><span class="v2-ellipsis"><?= $this->e((string)$worktree) ?></span><?php endif ?>
    </div>

    <?php if ($status === 'working'): ?>
      <div class="v2-thinking" aria-label="Thinking"><i></i><i></i><i></i></div>
    <?php endif ?>

    <?php if ($status === 'blocked'): ?>
      <div class="v2-prompt">
        <p class="v2-prompt-title"><?= $this->e((string)$blockedReason) ?></p>
        <?php if ($promptContext !== ''): ?><pre class="v2-prompt-context"><?= $this->e($promptContext) ?></pre><?php endif ?>
        <?php if ($readOnly || $promptIsFolderTrust || $promptHasQuestions || ($promptOptions === [])): ?>
          <?php /* Folder trust can end the session, multi-question needs the whole form, and an option-less prompt has nothing to tap: open the session. */ ?>
          <a class="v2-btn v2-btn-warn" href="/v2/session?session=<?= urlencode($name) ?>"><?= $readOnly ? 'Open session' : 'Open session to answer' ?></a>
        <?php else: ?>
          <div class="v2-options">
            <?php foreach ($promptOptions as $opt): ?>
              <?php if (stripos((string)$opt['label'], 'type something') !== false): ?>
                <a class="v2-btn v2-btn-quiet" href="/v2/session?session=<?= urlencode($name) ?>"><?= (int)$opt['number'] ?>. <?= $this->e((string)$opt['label']) ?></a>
              <?php else: ?>
                <button type="button" class="v2-btn v2-btn-warn" data-act="answer" data-option="<?= (int)$opt['number'] ?>" data-label="<?= $this->e((string)$opt['label']) ?>"><?= (int)$opt['number'] ?>. <?= $this->e((string)$opt['label']) ?></button>
              <?php endif ?>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>
    <?php elseif ($preview !== ''): ?>
      <p class="v2-preview"><span class="v2-muted"><?= $this->e($previewRole === 'user' ? 'You' : 'Agent') ?>:</span> <?= $this->e($preview) ?></p>
    <?php endif ?>
  </div>
</article>
