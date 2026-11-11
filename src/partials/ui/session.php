<?php
$archived = !empty($archived);
$title = $found ? (string)($detail['title'] ?? $detail['name'] ?? $sessionName) : 'Sessioneer';
$this->layout('ui/layout', ['title' => $title]);
?>
<?php $this->start('head') ?>
<link rel="stylesheet" href="<?= \App\Assets::versioned_url('/css/ui-session.css') ?>">
<?php $this->stop() ?>

<?php if (!$found): ?>
<div class="sess-missing">
  <a class="ui-pill-link" href="/">&larr; Sessions</a>
  <div class="ui-banner ui-banner-bad" role="alert">
    <strong>Session not found.</strong>
    <span><?= $this->e((string)($detail['message'] ?? 'Unknown error')) ?></span>
  </div>
</div>
<?php return; endif ?>

<?php
// JSON_HEX_*: transcript text can contain </script>, which must not end this tag.
$bootstrap = json_encode([
    'session' => $sessionName,
    'archived' => $archived,
    'csrfToken' => $csrfToken,
    'detail' => $detail,
    'jumpLine' => $jumpLine,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE);
?>
<div id="sess" class="sess">
  <header class="sess-bar">
    <a class="ui-icon-btn sess-back" href="/" aria-label="All sessions">&#8249;<i class="ui-badge sess-back-badge" id="sess-back-badge" hidden></i></a>
    <button type="button" class="sess-titlebtn" data-open="sess-info" aria-label="Session details">
      <span class="sess-title" id="sess-title"><?= $this->e($title) ?></span>
      <span class="sess-sub" id="sess-sub"></span>
    </button>
    <?php if (!$archived): ?>
    <button type="button" class="sess-mini" id="sess-mini" data-open="sess-quota" aria-label="Usage and context" hidden></button>
    <button type="button" class="ui-icon-btn" data-open="sess-sessions" aria-label="Sessions">&#9776;</button>
    <button type="button" class="ui-icon-btn" data-open="sess-quota" aria-label="Usage and context">&#9201;</button>
    <?php endif ?>
    <button type="button" class="ui-icon-btn" data-open="sess-search" aria-label="Search this session">&#9906;</button>
    <button type="button" class="ui-icon-btn" data-open="sess-info" aria-label="Session menu">&#8943;</button>
  </header>

  <main id="sess-scroll" class="sess-scroll">
    <div class="sess-col">
      <div id="sess-jump" class="sess-notice" hidden>
        <span>Showing a search result</span>
        <a href="<?= $archived ? '/archived-session?agent_session_id=' : '/session?session=' ?><?= urlencode($sessionName) ?>" class="ui-inline-link">Back to latest &rarr;</a>
      </div>
      <div id="sess-older" class="sess-older" hidden>
        <button type="button" class="ui-btn" id="sess-load-older">Load older</button>
        <button type="button" class="ui-btn" id="sess-load-until-user">Back to last message</button>
      </div>
      <div id="sess-list" class="sess-list" aria-live="off"></div>
      <?php if ($archived): ?>
      <div id="sess-newer" class="sess-older" hidden>
        <button type="button" class="ui-btn" id="sess-load-newer">Load newer</button>
      </div>
      <?php endif ?>
      <p id="sess-empty" class="ui-muted ui-center" hidden>Nothing recorded yet.</p>
      <div id="sess-thinking"></div>
      <div id="sess-error"></div>
      <div id="sess-prompt"></div>
      <?php if (!$archived && ($flowSessionHtml ?? '') !== ''): ?><?= $flowSessionHtml ?><?php endif ?>
    </div>
  </main>

  <footer class="sess-footer" id="sess-footer">
    <div class="sess-col">
      <div class="sess-bar2">
        <button type="button" class="sess-navbtn" id="sess-prev" aria-label="Previous message">&#9650;</button>
        <button type="button" class="sess-navbtn" id="sess-next" aria-label="Next message">&#9660;</button>
        <button type="button" class="sess-navbtn" id="sess-bottom" aria-label="Jump to latest">&#10515;<i class="sess-newdot" id="sess-newdot" hidden></i></button>
        <?php if (!$archived): ?>
        <button type="button" class="sess-ctrlbtn" id="sess-ctrl-open" data-open="sess-controls"><span id="sess-ctrl-summary">Controls</span> &#9662;</button>
        <?php endif ?>
      </div>
      <div id="sess-compose" class="sess-compose">
        <div id="sess-attached" class="sess-attached" hidden></div>
        <div class="sess-composerow">
          <button type="button" class="ui-icon-btn sess-attach" id="sess-attach-btn" aria-label="Attach file">+</button>
          <input type="file" id="sess-file" multiple hidden>
          <textarea id="sess-text" rows="1" placeholder="Message" enterkeyhint="enter" aria-label="Message"></textarea>
          <button type="button" class="ui-btn ui-btn-danger sess-stop" id="sess-stop" hidden>Stop</button>
          <button type="button" class="ui-btn ui-btn-primary sess-send" id="sess-send" disabled>Send</button>
        </div>
        <p id="sess-compose-status" class="sess-status" role="status" hidden></p>
      </div>
      <p id="sess-compose-note" class="ui-muted ui-small sess-note" hidden></p>
    </div>
  </footer>
</div>

<?php /* ---- sheets ---- */ ?>
<?php if ($archived): ?>
<?php /* the dialogs ui-archived.js drives */ ?>
<dialog class="ui-sheet" id="sess-search"><div class="ui-sheet-body">
  <div class="ui-sheet-head"><h2>Search</h2><button type="button" class="ui-icon-btn" data-close aria-label="Close">&times;</button></div>
  <input type="search" id="sess-search-input" placeholder="Search messages" autocomplete="off" enterkeyhint="search">
  <div class="ui-row">
    <label class="ui-check"><input type="radio" name="sess-scope" value="session" checked> This conversation</label>
    <label class="ui-check"><input type="radio" name="sess-scope" value="global"> All sessions</label>
  </div>
  <div id="sess-search-results" class="ui-col"></div>
</div></dialog>

<dialog class="ui-sheet" id="sess-info"><div class="ui-sheet-body">
  <div class="ui-sheet-head"><h2>Archived session</h2><button type="button" class="ui-icon-btn" data-close aria-label="Close">&times;</button></div>
  <section class="sess-sec"><h3 class="ui-subtitle">Details</h3><div id="sess-info-body" class="ui-col"></div></section>
  <section class="sess-sec"><h3 class="ui-subtitle">Actions</h3><div id="sess-info-actions" class="sess-row-actions"></div></section>
</div></dialog>
<?php else: ?>
<dialog class="ui-sheet" id="sess-controls"><div class="ui-sheet-body">
  <div class="ui-sheet-head"><h2>Session controls</h2><button type="button" class="ui-icon-btn" data-close aria-label="Close">&times;</button></div>
  <div id="sess-controls-body" class="ui-col"></div>
</div></dialog>

<dialog class="ui-sheet" id="sess-sessions"><div class="ui-sheet-body">
  <div class="ui-sheet-head"><h2>Sessions</h2><button type="button" class="ui-icon-btn" data-close aria-label="Close">&times;</button></div>
  <input type="search" id="sess-sessions-filter" placeholder="Filter sessions" autocomplete="off">
  <div class="ui-row sess-sessions-selects">
    <select id="sess-sessions-agent" aria-label="Filter by agent"><option value="">All agents</option></select>
    <select id="sess-sessions-profile" aria-label="Filter by profile"><option value="">All profiles</option></select>
  </div>
  <div id="sess-sessions-list" class="ui-col"></div>
  <a class="ui-btn" href="/">All sessions dashboard</a>
</div></dialog>

<dialog class="ui-sheet" id="sess-quota"><div class="ui-sheet-body">
  <div class="ui-sheet-head"><h2>Usage</h2><button type="button" class="ui-icon-btn" data-close aria-label="Close">&times;</button></div>
  <div id="sess-quota-body" class="ui-quota"></div>
</div></dialog>

<dialog class="ui-sheet" id="sess-search"><div class="ui-sheet-body">
  <div class="ui-sheet-head"><h2>Search</h2><button type="button" class="ui-icon-btn" data-close aria-label="Close">&times;</button></div>
  <input type="search" id="sess-search-input" placeholder="Search messages" autocomplete="off" enterkeyhint="search">
  <div class="ui-row">
    <label class="ui-check"><input type="radio" name="sess-scope" value="session" checked> This session</label>
    <label class="ui-check"><input type="radio" name="sess-scope" value="global"> All sessions</label>
  </div>
  <div id="sess-search-results" class="ui-col"></div>
</div></dialog>

<dialog class="ui-sheet" id="sess-info"><div class="ui-sheet-body">
  <div class="ui-sheet-head"><h2>Session</h2><div class="sess-head-actions"><span id="sess-info-head-actions" class="sess-head-actions"></span><button type="button" class="ui-icon-btn" data-close aria-label="Close">&times;</button></div></div>
  <?php if (($flowPopupHtml ?? '') !== ''): ?><section class="sess-sec"><?= $flowPopupHtml ?></section><?php endif ?>
  <section class="sess-sec"><h3 class="ui-subtitle">Details</h3><div id="sess-info-body" class="ui-col"></div></section>
  <section class="sess-sec"><h3 class="ui-subtitle">Actions</h3>
    <div id="sess-info-actions" class="sess-row-actions"></div>
  </section>
  <section class="sess-sec" id="sess-tasks-sec" hidden><h3 class="ui-subtitle">Tasks</h3><ul id="sess-tasks" class="ui-checks"></ul></section>
  <section class="sess-sec"><h3 class="ui-subtitle">Files</h3>
    <button type="button" class="ui-btn" id="sess-todo-file">Open todo file</button>
    <div id="sess-plan-files" class="ui-col"></div>
    <div class="ui-row"><h3 class="ui-subtitle" style="flex:1">Uploaded files</h3><span id="sess-upload-total" class="ui-muted ui-small"></span></div>
    <div id="sess-uploads" class="ui-col"></div>
    <button type="button" class="ui-btn ui-btn-danger" id="sess-uploads-clear" hidden>Delete all uploads</button>
  </section>
  <section class="sess-sec"><h3 class="ui-subtitle">View</h3>
    <label class="ui-check"><input type="checkbox" id="sess-opt-reasoning"> Show reasoning text</label>
    <label class="ui-check"><input type="checkbox" id="sess-opt-subagent" checked> Show subagent calls and outputs</label>
    <label class="ui-check"><input type="checkbox" id="sess-opt-confirm"> Confirm before sending prompt answers</label>
    <label class="ui-field">Poll interval
      <select id="sess-opt-poll"><option value="1000">1s</option><option value="3000">3s</option><option value="5000">5s</option><option value="10000">10s</option><option value="15000">15s</option></select>
    </label>
  </section>
</div></dialog>

<?php endif ?>

<div class="sess-viewer" id="sess-viewer" hidden>
  <div class="sess-viewer-bar">
    <button type="button" class="ui-btn" id="sess-viewer-close">Close</button>
    <a class="ui-btn" id="sess-viewer-open" target="_blank" rel="noopener">Open original</a>
  </div>
  <div class="sess-viewer-stage" id="sess-viewer-stage"><img id="sess-viewer-img" alt=""></div>
</div>

<div class="sess-fullscreen" id="sess-fullscreen" hidden>
  <div class="sess-viewer-bar">
    <button type="button" class="ui-btn" id="sess-fullscreen-close">Close</button>
    <button type="button" class="ui-btn" id="sess-fullscreen-copy">Copy</button>
    <span class="sess-fullscreen-title ui-muted ui-small" id="sess-fullscreen-title"></span>
  </div>
  <pre id="sess-fullscreen-text" class="sess-fullscreen-text"></pre>
</div>

<div class="ui-toast" id="ui-toast" role="status" hidden></div>

<script>window.UiSession = <?= $bootstrap ?>;</script>
<script src="<?= \App\Assets::versioned_url('/js/markdown.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/ui-sheets.js') ?>"></script>
<?php if ($archived): ?>
<script src="<?= \App\Assets::versioned_url('/js/ui-session.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/ui-archived.js') ?>"></script>
<?php else: ?>
<script src="<?= \App\Assets::versioned_url('/js/ui-quota.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/ui-flow.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/ui-session.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/ui-session-sheets.js') ?>"></script>
<?php endif ?>
