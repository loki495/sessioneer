<?php
$title = $found ? (string)($detail['title'] ?? $detail['name'] ?? $sessionName) : 'Sessioneer';
$this->layout('v2/layout', ['title' => $title]);
?>
<?php $this->start('head') ?>
<link rel="stylesheet" href="<?= \App\Assets::versioned_url('/css/v2-session.css') ?>">
<?php $this->stop() ?>

<?php if (!$found): ?>
<div class="v2s-missing">
  <a class="v2-pill-link" href="/v2">&larr; Sessions</a>
  <div class="v2-banner v2-banner-bad" role="alert">
    <strong>Session not found.</strong>
    <span><?= $this->e((string)($detail['message'] ?? 'Unknown error')) ?></span>
  </div>
</div>
<?php return; endif ?>

<?php
// JSON_HEX_*: transcript text can contain </script>, which must not end this tag.
$bootstrap = json_encode([
    'session' => $sessionName,
    'csrfToken' => $csrfToken,
    'detail' => $detail,
    'jumpLine' => $jumpLine,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
?>
<div id="v2s" class="v2s">
  <header class="v2s-bar">
    <a class="v2-icon-btn v2s-back" href="/v2" aria-label="All sessions">&#8249;<i class="v2-badge v2s-back-badge" id="v2s-back-badge" hidden></i></a>
    <button type="button" class="v2s-titlebtn" data-open="v2s-info" aria-label="Session details">
      <span class="v2s-title" id="v2s-title"><?= $this->e($title) ?></span>
      <span class="v2s-sub" id="v2s-sub"></span>
    </button>
    <button type="button" class="v2s-mini" id="v2s-mini" data-open="v2s-quota" aria-label="Usage and context" hidden></button>
    <button type="button" class="v2-icon-btn" data-open="v2s-sessions" aria-label="Sessions">&#9776;</button>
    <button type="button" class="v2-icon-btn" data-open="v2s-quota" aria-label="Usage and context">&#9201;</button>
    <button type="button" class="v2-icon-btn" data-open="v2s-search" aria-label="Search this session">&#9906;</button>
    <button type="button" class="v2-icon-btn" data-open="v2s-info" aria-label="Session menu">&#8943;</button>
  </header>

  <main id="v2s-scroll" class="v2s-scroll">
    <div class="v2s-col">
      <div id="v2s-jump" class="v2s-notice" hidden>
        <span>Showing a search result</span>
        <a href="/v2/session?session=<?= urlencode($sessionName) ?>" class="v2-inline-link">Back to latest &rarr;</a>
      </div>
      <div id="v2s-older" class="v2s-older" hidden>
        <button type="button" class="v2-btn" id="v2s-load-older">Load older</button>
        <button type="button" class="v2-btn" id="v2s-load-until-user">Back to last message</button>
      </div>
      <div id="v2s-list" class="v2s-list" aria-live="off"></div>
      <p id="v2s-empty" class="v2-muted v2-center" hidden>Nothing recorded yet.</p>
      <div id="v2s-thinking"></div>
      <div id="v2s-error"></div>
      <div id="v2s-prompt"></div>
    </div>
  </main>

  <footer class="v2s-footer" id="v2s-footer">
    <div class="v2s-col">
      <div class="v2s-bar2">
        <button type="button" class="v2s-navbtn" id="v2s-prev" aria-label="Previous message">&#9650;</button>
        <button type="button" class="v2s-navbtn" id="v2s-next" aria-label="Next message">&#9660;</button>
        <button type="button" class="v2s-navbtn" id="v2s-bottom" aria-label="Jump to latest">&#10515;<i class="v2s-newdot" id="v2s-newdot" hidden></i></button>
        <button type="button" class="v2s-ctrlbtn" id="v2s-ctrl-open" data-open="v2s-controls"><span id="v2s-ctrl-summary">Controls</span> &#9662;</button>
      </div>
      <div id="v2s-compose" class="v2s-compose">
        <div id="v2s-attached" class="v2s-attached" hidden></div>
        <div class="v2s-composerow">
          <button type="button" class="v2-icon-btn v2s-attach" id="v2s-attach-btn" aria-label="Attach file">+</button>
          <input type="file" id="v2s-file" multiple hidden>
          <textarea id="v2s-text" rows="1" placeholder="Message" enterkeyhint="send" aria-label="Message"></textarea>
          <button type="button" class="v2-btn v2-btn-danger v2s-stop" id="v2s-stop" hidden>Stop</button>
          <button type="button" class="v2-btn v2-btn-primary v2s-send" id="v2s-send" disabled>Send</button>
        </div>
        <p id="v2s-compose-status" class="v2s-status" role="status" hidden></p>
      </div>
      <p id="v2s-compose-note" class="v2-muted v2-small v2s-note" hidden></p>
    </div>
  </footer>
</div>

<?php /* ---- sheets ---- */ ?>
<dialog class="v2-sheet" id="v2s-controls"><div class="v2-sheet-body">
  <div class="v2-sheet-head"><h2>Session controls</h2><button type="button" class="v2-icon-btn" data-close aria-label="Close">&times;</button></div>
  <div id="v2s-controls-body" class="v2-col"></div>
</div></dialog>

<dialog class="v2-sheet" id="v2s-sessions"><div class="v2-sheet-body">
  <div class="v2-sheet-head"><h2>Sessions</h2><button type="button" class="v2-icon-btn" data-close aria-label="Close">&times;</button></div>
  <input type="search" id="v2s-sessions-filter" placeholder="Filter sessions" autocomplete="off">
  <div id="v2s-sessions-list" class="v2-col"></div>
  <a class="v2-btn" href="/v2">All sessions dashboard</a>
</div></dialog>

<dialog class="v2-sheet" id="v2s-quota"><div class="v2-sheet-body">
  <div class="v2-sheet-head"><h2>Usage</h2><button type="button" class="v2-icon-btn" data-close aria-label="Close">&times;</button></div>
  <div id="v2s-quota-body" class="v2-quota"></div>
</div></dialog>

<dialog class="v2-sheet" id="v2s-search"><div class="v2-sheet-body">
  <div class="v2-sheet-head"><h2>Search</h2><button type="button" class="v2-icon-btn" data-close aria-label="Close">&times;</button></div>
  <input type="search" id="v2s-search-input" placeholder="Search messages" autocomplete="off" enterkeyhint="search">
  <div class="v2-row">
    <label class="v2-check"><input type="radio" name="v2s-scope" value="session" checked> This session</label>
    <label class="v2-check"><input type="radio" name="v2s-scope" value="global"> All sessions</label>
  </div>
  <div id="v2s-search-results" class="v2-col"></div>
</div></dialog>

<dialog class="v2-sheet" id="v2s-info"><div class="v2-sheet-body">
  <div class="v2-sheet-head"><h2>Session</h2><button type="button" class="v2-icon-btn" data-close aria-label="Close">&times;</button></div>
  <section class="v2s-sec"><h3 class="v2-subtitle">Details</h3><div id="v2s-info-body" class="v2-col"></div></section>
  <section class="v2s-sec"><h3 class="v2-subtitle">Actions</h3>
    <div id="v2s-info-actions" class="v2s-row-actions"></div>
    <a class="v2-btn" id="v2s-classic" href="/session.php?session=<?= urlencode($sessionName) ?>" data-classic-link>Open in classic</a>
  </section>
  <section class="v2s-sec" id="v2s-tasks-sec" hidden><h3 class="v2-subtitle">Tasks</h3><ul id="v2s-tasks" class="v2-checks"></ul></section>
  <section class="v2s-sec"><h3 class="v2-subtitle">Files</h3>
    <button type="button" class="v2-btn" id="v2s-todo-file">Open todo file</button>
    <div id="v2s-plan-files" class="v2-col"></div>
    <div class="v2-row"><h3 class="v2-subtitle" style="flex:1">Uploaded files</h3><span id="v2s-upload-total" class="v2-muted v2-small"></span></div>
    <div id="v2s-uploads" class="v2-col"></div>
    <button type="button" class="v2-btn v2-btn-danger" id="v2s-uploads-clear" hidden>Delete all uploads</button>
  </section>
  <section class="v2s-sec"><h3 class="v2-subtitle">View</h3>
    <label class="v2-check"><input type="checkbox" id="v2s-opt-reasoning"> Show reasoning text</label>
    <label class="v2-check"><input type="checkbox" id="v2s-opt-subagent" checked> Show subagent calls and outputs</label>
    <label class="v2-check"><input type="checkbox" id="v2s-opt-confirm"> Confirm before sending prompt answers</label>
    <label class="v2-field">Poll interval
      <select id="v2s-opt-poll"><option value="1000">1s</option><option value="3000">3s</option><option value="5000">5s</option><option value="10000">10s</option><option value="15000">15s</option></select>
    </label>
  </section>
</div></dialog>

<div class="v2s-viewer" id="v2s-viewer" hidden>
  <div class="v2s-viewer-bar">
    <button type="button" class="v2-btn" id="v2s-viewer-close">Close</button>
    <a class="v2-btn" id="v2s-viewer-open" target="_blank" rel="noopener">Open original</a>
  </div>
  <div class="v2s-viewer-stage" id="v2s-viewer-stage"><img id="v2s-viewer-img" alt=""></div>
</div>

<div class="v2s-fullscreen" id="v2s-fullscreen" hidden>
  <div class="v2s-viewer-bar">
    <button type="button" class="v2-btn" id="v2s-fullscreen-close">Close</button>
    <button type="button" class="v2-btn" id="v2s-fullscreen-copy">Copy</button>
    <span class="v2s-fullscreen-title v2-muted v2-small" id="v2s-fullscreen-title"></span>
  </div>
  <pre id="v2s-fullscreen-text" class="v2s-fullscreen-text"></pre>
</div>

<div class="v2-toast" id="v2-toast" role="status" hidden></div>

<script>window.V2S = <?= $bootstrap ?>;</script>
<script src="<?= \App\Assets::versioned_url('/js/markdown.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/v2-sheets.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/v2-quota.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/v2-session.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/v2-session-sheets.js') ?>"></script>
