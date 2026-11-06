<div id="push-notify-control" class="v2-row" data-vapid-key="<?= $this->e($vapidPublicKey) ?>" data-csrf-token="<?= $this->e($csrfToken) ?>">
  <button type="button" id="push-notify-btn" class="v2-btn hidden">
    Enable notifications
  </button>
  <span id="push-notify-status" class="v2-muted v2-small"></span>
</div>
<script src="<?= \App\Assets::versioned_url('/js/push-notify.js') ?>"></script>
