<article class="ui-card ui-card-flat" data-archived-row data-agent="<?= $this->e($agentId) ?>" data-search="<?= $this->e(mb_strtolower($title . ' ' . (string)$cwd . ' ' . $agentSessionId)) ?>">
  <div class="ui-card-main">
    <a class="ui-title ui-link-cover" href="/archived-session?agent_session_id=<?= urlencode($agentSessionId) ?>"><?= $this->e($title) ?></a>
    <div class="ui-chips">
      <span class="ui-chip ui-agent-<?= $this->e($agentId) ?>"><?= $this->e($agentLabel) ?></span>
      <?php if ($agentId === 'claude'): ?><span class="ui-chip"><?= $this->e(\App\Views\UiView::profile_label($profile)) ?></span><?php endif ?>
      <span class="ui-chip ui-chip-id" title="Conversation <?= $this->e($agentSessionId) ?>">&hellip;<?= $this->e(substr($agentSessionId, -8)) ?></span>
    </div>
    <?php if (!empty($cwd)): ?><div class="ui-path"><?= $this->e((string)$cwd) ?></div><?php endif ?>
    <div class="ui-meta"><span><?= $this->e($relativeTime) ?></span></div>
  </div>
  <?php if (!empty($cwd) && $agentId !== 'antigravity'): ?>
    <div class="ui-card-actions">
      <button type="button" class="ui-btn" data-act="resume" data-id="<?= $this->e($agentSessionId) ?>" data-workdir="<?= $this->e((string)$cwd) ?>" data-profile="<?= $this->e((string)($profile ?? '')) ?>">Resume</button>
    </div>
  <?php endif ?>
</article>
