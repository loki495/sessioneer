<div id="push-notify-control" class="ui-row" data-vapid-key="<?= $this->e($vapidPublicKey) ?>" data-csrf-token="<?= $this->e($csrfToken) ?>">
  <button type="button" id="push-notify-btn" class="ui-btn hidden">
    Enable notifications
  </button>
  <span id="push-notify-status" class="ui-muted ui-small"></span>
</div>
<script src="<?= \App\Assets::versioned_url('/js/push-notify.js') ?>"></script>
