<div class="ui-wake" data-wake-job="<?= $job ?>" data-wake-state="<?= $this->e($state) ?>">
  <time data-at="<?= $at ?>" title="<?= $this->e($atLocal) ?>"><?= $this->e($atLocal) ?></time>
  <span class="ui-chip ui-wake-state ui-wake-state-<?= $this->e($state) ?>"><?= $this->e($state) ?></span>
  <?php if (!$compact && $sessionHref === null): ?><span class="ui-wake-session"><?= $this->e($sessionLabel) ?></span><?php elseif (!$compact): ?><a class="ui-inline-link ui-wake-session" href="<?= $this->e($sessionHref) ?>"><?= $this->e($title ?? $sessionLabel) ?></a><?php if ($title !== null && $title !== $sessionLabel): ?> <span class="ui-muted ui-small ui-wake-name"><?= $this->e($sessionLabel) ?></span><?php endif ?><?php endif ?>
  <p class="ui-wake-preview"><?= $this->e($preview) ?></p>
  <button type="button" class="ui-btn ui-btn-quiet" data-wake-show="<?= $job ?>">Show full prompt</button>
  <?php if ($state !== 'running'): ?><button type="button" class="ui-btn ui-btn-danger" data-wake-cancel="<?= $job ?>">Cancel</button><?php endif ?>
  <pre class="ui-wake-full" hidden></pre>
</div>
