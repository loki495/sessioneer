<article class="ui-card ui-card-flat" data-bare-pid="<?= (int)$pid ?>">
  <div class="ui-card-main">
    <div class="ui-title"><?= $this->e($title) ?></div>
    <?php if (!empty($cwd)): ?><div class="ui-path"><?= $this->e((string)$cwd) ?></div><?php endif ?>
    <div class="ui-meta">
      <span>pid <?= (int)$pid ?></span>
      <?php if (!empty($tmuxSession)): ?><span>tmux <?= $this->e((string)$tmuxSession) ?></span><?php endif ?>
      <?php if ($startedAt !== null): ?><span>started <?= $this->e($startedAt) ?></span><?php endif ?>
    </div>
  </div>
  <button type="button" class="ui-btn" data-act="take-over" data-pid="<?= (int)$pid ?>">Take over</button>
  <button type="button" class="ui-btn ui-btn-danger" data-act="kill-bare" data-pid="<?= (int)$pid ?>">Kill</button>
</article>
