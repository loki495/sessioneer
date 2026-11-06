<article class="v2-card v2-card-flat" data-archived-row data-agent="<?= $this->e($agentId) ?>" data-search="<?= $this->e(mb_strtolower($title . ' ' . (string)$cwd . ' ' . $agentSessionId)) ?>">
  <div class="v2-card-main">
    <a class="v2-title v2-link-cover" href="/archived-session?agent_session_id=<?= urlencode($agentSessionId) ?>"><?= $this->e($title) ?></a>
    <div class="v2-chips">
      <span class="v2-chip v2-agent-<?= $this->e($agentId) ?>"><?= $this->e($agentLabel) ?></span>
      <?php if ($agentId === 'claude'): ?><span class="v2-chip"><?= $this->e(\App\Views\V2View::profile_label($profile)) ?></span><?php endif ?>
      <span class="v2-chip v2-chip-id" title="Conversation <?= $this->e($agentSessionId) ?>">&hellip;<?= $this->e(substr($agentSessionId, -8)) ?></span>
    </div>
    <?php if (!empty($cwd)): ?><div class="v2-path"><?= $this->e((string)$cwd) ?></div><?php endif ?>
    <div class="v2-meta"><span><?= $this->e($relativeTime) ?></span></div>
  </div>
  <?php if (!empty($cwd) && $agentId !== 'antigravity'): ?>
    <div class="v2-card-actions">
      <button type="button" class="v2-btn" data-act="resume" data-id="<?= $this->e($agentSessionId) ?>" data-workdir="<?= $this->e((string)$cwd) ?>" data-profile="<?= $this->e((string)($profile ?? '')) ?>">Resume</button>
    </div>
  <?php endif ?>
</article>
