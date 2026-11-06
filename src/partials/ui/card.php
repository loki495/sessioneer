<?php
  $isWorker = $kind === 'worker';
  $readOnly = $runtime !== 'headless';
  $hasFreeText = false;
  foreach ($promptOptions as $opt) {
    if (stripos((string)$opt['label'], 'type something') !== false) { $hasFreeText = true; }
  }
?>
<?php /* Whole-card tap target: the title link stretches over the card (.ui-link-cover::after); anything interactive sits above it. */ ?>
<article class="ui-card ui-status-<?= $this->e($status) ?>" data-session="<?= $this->e($name) ?>" data-status="<?= $this->e($status) ?>" data-kind="<?= $this->e($kind) ?>" data-starred="<?= $starred ? 1 : 0 ?>">
  <div class="ui-card-main">
    <div class="ui-card-head">
      <span class="ui-dot ui-dot-<?= $this->e($status) ?>" role="img" aria-label="<?= $this->e($status === 'blocked' ? 'needs input' : $status) ?>"></span>
      <a class="ui-title ui-link-cover" href="/session?session=<?= urlencode($name) ?>" data-title><?= $this->e($title) ?></a>
      <button type="button" class="ui-icon-btn ui-star<?= $starred ? ' is-on' : '' ?>" data-act="star" aria-pressed="<?= $starred ? 'true' : 'false' ?>" aria-label="<?= $starred ? 'Unstar' : 'Star' ?> session"><?= $starred ? '&#9733;' : '&#9734;' ?></button>
      <details class="ui-menu">
        <summary class="ui-icon-btn" aria-label="Session actions">&#8943;</summary>
        <div class="ui-menu-list">
          <button type="button" data-act="rename" data-title="<?= $this->e($title) ?>">Rename</button>
          <?php if ($readOnly && $agentId === 'claude'): ?>
            <button type="button" data-act="switch" data-runtime="headless">Take over</button>
          <?php endif ?>
          <button type="button" class="ui-danger-text" data-act="kill">Kill session</button>
        </div>
      </details>
    </div>

    <div class="ui-chips">
      <span class="ui-chip ui-agent-<?= $this->e($agentId) ?>"><?= $this->e($agentLabel) ?></span>
      <?php if ($profile !== null): ?><span class="ui-chip<?= $profile !== 'Personal' ? ' ui-chip-accent' : '' ?>"><?= $this->e($profile) ?></span><?php endif ?>
      <?php if ($readOnly): ?><span class="ui-chip ui-chip-warn">Terminal, read only</span><?php endif ?>
      <?php if ($isWorker): ?><span class="ui-chip ui-chip-accent">Worker</span><?php endif ?>
      <?php if (!empty($conversationId)): ?><span class="ui-chip ui-chip-id" title="Conversation <?= $this->e((string)$conversationId) ?>">&hellip;<?= $this->e(substr((string)$conversationId, -8)) ?></span><?php endif ?>
    </div>

    <?php if ($isWorker && $parentSessionId !== null): ?>
      <div class="ui-muted ui-small">spawned by <a class="ui-inline-link" href="/session?session=<?= urlencode((string)$parentSessionId) ?>"><?= $this->e((string)$parentSessionId) ?></a></div>
    <?php endif ?>
    <?php if (!empty($workdir)): ?><div class="ui-path"><?= $this->e((string)$workdir) ?></div><?php endif ?>

    <div class="ui-meta">
      <span><?= $this->e($relativeTime) ?></span>
      <span class="ui-status-text"><?= $this->e($status === 'blocked' ? 'needs input' : $status) ?></span>
      <?php if ($contextPct !== null): ?><span class="<?= $contextPct >= 80 ? 'ui-warn' : '' ?>">ctx <?= $contextPct ?>%</span><?php endif ?>
      <?php if ($worktree !== null): ?><span class="ui-ellipsis"><?= $this->e((string)$worktree) ?></span><?php endif ?>
    </div>

    <?php if ($status === 'working'): ?>
      <div class="ui-thinking" aria-label="Thinking"><i></i><i></i><i></i></div>
    <?php endif ?>

    <?php if ($status === 'idle' && $runtime === 'headless' && in_array($agentId, ['claude', 'codex'], true)): ?>
      <div class="ui-advise" data-advise-host data-session="<?= $this->e($name) ?>">
        <button type="button" class="ui-btn ui-btn-quiet" data-advise-open>Continue or start a new one?</button>
      </div>
    <?php endif ?>

    <?php if ($status === 'blocked'): ?>
      <div class="ui-prompt">
        <p class="ui-prompt-title"><?= $this->e((string)$blockedReason) ?></p>
        <?php if ($promptContext !== ''): ?><pre class="ui-prompt-context"><?= $this->e($promptContext) ?></pre><?php endif ?>
        <?php if ($readOnly || $promptIsFolderTrust || $promptHasQuestions || ($promptOptions === [])): ?>
          <?php /* Folder trust can end the session, multi-question needs the whole form, and an option-less prompt has nothing to tap: open the session. */ ?>
          <a class="ui-btn ui-btn-warn" href="/session?session=<?= urlencode($name) ?>"><?= $readOnly ? 'Open session' : 'Open session to answer' ?></a>
        <?php else: ?>
          <div class="ui-options">
            <?php foreach ($promptOptions as $opt): ?>
              <?php if (stripos((string)$opt['label'], 'type something') !== false): ?>
                <a class="ui-btn ui-btn-quiet" href="/session?session=<?= urlencode($name) ?>"><?= (int)$opt['number'] ?>. <?= $this->e((string)$opt['label']) ?></a>
              <?php else: ?>
                <button type="button" class="ui-btn ui-btn-warn" data-act="answer" data-option="<?= (int)$opt['number'] ?>" data-label="<?= $this->e((string)$opt['label']) ?>"><?= (int)$opt['number'] ?>. <?= $this->e((string)$opt['label']) ?></button>
              <?php endif ?>
            <?php endforeach ?>
          </div>
        <?php endif ?>
      </div>
    <?php elseif ($preview !== ''): ?>
      <p class="ui-preview"><span class="ui-muted"><?= $this->e($previewRole === 'user' ? 'You' : 'Agent') ?>:</span> <?= $this->e($preview) ?></p>
    <?php endif ?>
  </div>
</article>
