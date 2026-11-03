<article class="v2-card v2-card-flat" data-bare-pid="<?= (int)$pid ?>">
  <div class="v2-card-main">
    <div class="v2-title"><?= $this->e($title) ?></div>
    <?php if (!empty($cwd)): ?><div class="v2-path"><?= $this->e((string)$cwd) ?></div><?php endif ?>
    <div class="v2-meta">
      <span>pid <?= (int)$pid ?></span>
      <?php if (!empty($tmuxSession)): ?><span>tmux <?= $this->e((string)$tmuxSession) ?></span><?php endif ?>
      <?php if ($startedAt !== null): ?><span>started <?= $this->e($startedAt) ?></span><?php endif ?>
    </div>
  </div>
  <button type="button" class="v2-btn v2-btn-danger" data-act="kill-bare" data-pid="<?= (int)$pid ?>">Kill</button>
</article>
