<?php
// The session-flow band for one row: the stored decision (or the live offer's own), its one-tap
// actions, and nothing else. It never decides by itself: a GO or UNKNOWN state draws no band.
$state = (string)($decision['state'] ?? '');
$why = (string)($decision['why'] ?? '');
$offer = is_array($offer) ? $offer : [];
$handoff = is_string($offer['handoff_id'] ?? null) ? $offer['handoff_id'] : '';
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
</div>
<?php endif ?>
