<?php
// The session-flow band for one row: the stored decision (or the live offer's own), its one-tap
// actions, and nothing else. It never decides by itself: a GO or UNKNOWN state draws no band.
$state = (string)($decision['state'] ?? '');
$why = (string)($decision['why'] ?? '');
$offer = is_array($offer) ? $offer : [];
$handoff = is_string($offer['handoff_id'] ?? null) ? $offer['handoff_id'] : '';
$plan = is_string($offer['plan_id'] ?? null) ? $offer['plan_id'] : '';
$resets = is_array($resets ?? null) ? $resets : [];
// A wake resumes this session, so only its own account's reset time can be waited for; a handoff or plan
// starts a fresh session and may wait for any profile.
if ($handoff === '' && $plan === '') {
    $ownAgent = (string)($row['agent'] ?? '');
    $ownProfile = $ownAgent !== '' && $ownAgent !== 'claude' ? 'default' : (string)($row['profile'] ?? '');
    // A row that names no agent or profile (a default account may be stored as none) is not narrowed; the host refuses a mismatch.
    $resets = array_values(array_filter($resets, static fn (array $r): bool => ($ownAgent === '' || $r['agent'] === $ownAgent) && ($ownProfile === '' || $r['profile'] === $ownProfile)));
}
$prompt = is_string($offer['prompt'] ?? null) ? $offer['prompt'] : '';
$receiver = is_array($decision['receiver'] ?? null) ? $decision['receiver'] : [];
$session = (string)($row['name'] ?? '');
// The host refuses a judge outside a close quota call with a known reset, and can only run a Claude
// or Codex receiver, so the band offers it only there.
$judge = $state === 'ROTATE' && !empty($decision['close_call']) && ($decision['trigger'] ?? '') === 'quota'
    && ($decision['reset_at'] ?? null) !== null && in_array($receiver['agent'] ?? null, ['claude', 'codex'], true);
$labels = [
    'start' => 'Start receiver',
    'handoff' => 'Hand off and start',
    'takeover' => 'Take over from transcript',
    'continue' => 'Continue here',
    'wait' => 'Wait for reset',
    'judge' => 'Ask a small model',
    'edit' => 'Edit in form',
];
$acts = [];
if ($state === 'ROTATE') {
    $acts[] = $handoff !== '' ? 'start' : 'handoff';
    $acts[] = 'takeover';
    $acts[] = 'continue';
    if ($judge) {
        $acts[] = 'judge';
    }
    $acts[] = 'edit';
} elseif ($state === 'PARK' || $state === 'WAIT') {
    $acts[] = 'wait';
}
?>
<?php if ($state === 'WARM_TIGHT'): ?>
<span class="ui-chip ui-flow-chip" data-flow-chip><?= $this->e($why !== '' ? $why : 'Context is warm') ?></span>
<?php elseif ($acts !== []): ?>
<div class="ui-flow" data-flow-host
  data-flow-where="<?= $this->e($where) ?>"
  data-flow-state="<?= $this->e($state) ?>"
  data-session="<?= $this->e($session) ?>"
  data-csrf="<?= $this->e($csrfToken) ?>"
  <?php if ($handoff !== ''): ?>data-handoff-id="<?= $this->e($handoff) ?>"
  <?php endif ?>
  <?php if ($plan !== ''): ?>data-plan-id="<?= $this->e($plan) ?>"
  <?php endif ?>
  <?php if ($prompt !== ''): ?>data-prompt="<?= $this->e($prompt) ?>"
  <?php endif ?>
  <?php if (isset($receiver['agent'])): ?>data-receiver-agent="<?= $this->e((string)$receiver['agent']) ?>"
  <?php endif ?>
  <?php if (isset($receiver['profile'])): ?>data-receiver-profile="<?= $this->e((string)$receiver['profile']) ?>"
  <?php endif ?>
  <?php if ((string)($row['agent'] ?? '') !== ''): ?>data-agent="<?= $this->e((string)$row['agent']) ?>"
  <?php endif ?>
  <?php if ((string)($row['profile'] ?? '') !== ''): ?>data-profile="<?= $this->e((string)$row['profile']) ?>"
  <?php endif ?>
  <?php if ((string)($row['workdir'] ?? '') !== ''): ?>data-workdir="<?= $this->e((string)$row['workdir']) ?>"
  <?php endif ?>
  <?php if ((string)($row['agent_session_id'] ?? '') !== ''): ?>data-conversation="<?= $this->e((string)$row['agent_session_id']) ?>"
  <?php endif ?>
>
  <?php if ($why !== ''): ?><p class="ui-flow-why"><?= $this->e($why) ?></p><?php endif ?>
  <div class="ui-flow-actions">
    <?php foreach ($acts as $act): ?>
      <button type="button" class="ui-btn ui-flow-btn" data-flow-act="<?= $act ?>"><?= $this->e($labels[$act]) ?></button>
    <?php endforeach ?>
  </div>
  <?php if (in_array('wait', $acts, true) && $resets !== []): ?>
  <div class="ui-flow-actions ui-flow-resets">
    <?php foreach ($resets as $r): ?>
      <button type="button" class="ui-btn ui-flow-btn" data-flow-wait-reset
        data-agent="<?= $this->e($r['agent']) ?>" data-profile="<?= $this->e($r['profile']) ?>" data-reset-at="<?= (int)$r['reset_at'] ?>">Wait for reset: <?= $this->e($r['label']) ?></button>
    <?php endforeach ?>
  </div>
  <?php endif ?>
</div>
<?php endif ?>
