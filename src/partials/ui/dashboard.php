<?php $this->layout('ui/layout', ['title' => 'Sessioneer']) ?>
<?php
  $allOk = true;
  foreach ($healthChecks as $check) { if (!($check['ok'] ?? false)) { $allOk = false; break; } }
  $groups = [];
  foreach ($healthChecks as $check) { $groups[(string)($check['section'] ?? 'General')][] = $check; }
?>
<div id="ui-app" data-csrf="<?= $this->e($csrfToken) ?>">

<header class="ui-appbar">
  <div class="ui-appbar-title">
    <h1>Sessioneer</h1>
    <p id="ui-subtitle" class="ui-muted ui-small"></p>
  </div>
  <button type="button" class="ui-icon-btn" id="ui-open-settings" aria-label="Settings">&#9881;</button>
</header>

<main class="ui-main">
  <div id="ui-banners">
    <?php if (!$agentReachable): ?>
      <div class="ui-banner ui-banner-bad" role="alert">
        <strong>Cannot reach the host agent.</strong>
        <span><?= $this->e($agentMessage) ?></span>
        <code>systemctl --user status sessioneer-agent.socket</code>
      </div>
    <?php endif ?>
    <?php if ($hooksMissing): ?>
      <div class="ui-banner ui-banner-warn">
        <strong>App hooks aren't fully installed.</strong>
        <span>Status, blocked prompts and transcripts can be stale until they are.</span>
        <button type="button" class="ui-btn ui-btn-warn" data-act="install-hook">Install hooks</button>
      </div>
    <?php endif ?>
  </div>

  <section class="ui-panel" id="ui-panel-sessions" data-panel="sessions">
    <div class="ui-filters" role="tablist" aria-label="Filter sessions">
      <button type="button" class="ui-filter is-on" data-filter="all">All <b id="ui-n-all"><?= (int)$counts['total'] ?></b></button>
      <button type="button" class="ui-filter" data-filter="blocked">Needs input <b id="ui-n-blocked"><?= (int)$counts['blocked'] ?></b></button>
      <button type="button" class="ui-filter" data-filter="working">Working <b id="ui-n-working"><?= (int)$counts['working'] ?></b></button>
      <button type="button" class="ui-filter" data-filter="idle">Idle <b id="ui-n-idle"><?= (int)$counts['idle'] ?></b></button>
    </div>
    <div id="ui-sessions" class="ui-stack"><?= $sessionsHtml ?></div>
    <p id="ui-filter-empty" class="ui-muted ui-center" hidden>Nothing matches this filter.</p>
    <div id="ui-bare"><?= $bareHtml ?></div>
  </section>

  <section class="ui-panel" id="ui-panel-search" data-panel="search" hidden>
    <label class="ui-search">
      <span class="ui-sr">Search all sessions</span>
      <input type="search" id="ui-search-input" placeholder="Search every session's content&hellip;" autocomplete="off" enterkeyhint="search">
    </label>
    <div id="ui-search-results" class="ui-stack"></div>
  </section>

  <section class="ui-panel" id="ui-panel-archived" data-panel="archived" hidden>
    <label class="ui-search">
      <span class="ui-sr">Filter archived sessions</span>
      <input type="search" id="ui-archived-filter" placeholder="Filter archived by title or folder&hellip;" autocomplete="off">
    </label>
    <div id="ui-archived" class="ui-stack"><p class="ui-muted ui-center">Loading&hellip;</p></div>
    <button type="button" class="ui-btn ui-btn-quiet ui-block" id="ui-archived-more" hidden>Show more</button>
  </section>

  <section class="ui-panel" id="ui-panel-status" data-panel="status" hidden>
    <details class="ui-card ui-card-flat ui-col ui-fold" open>
      <summary class="ui-section-title">Usage</summary>
      <div id="ui-quota" class="ui-quota"><span class="ui-muted ui-small">Loading&hellip;</span></div>
    </details>

    <details class="ui-card ui-card-flat ui-col ui-fold ui-health"<?= $allOk && $healthChecks !== [] ? '' : ' open' ?>>
      <summary class="ui-section-title">Health <span class="ui-chip <?= $allOk ? 'ui-chip-ok' : 'ui-chip-warn' ?>"><?= $healthChecks === [] ? 'unknown' : ($allOk ? 'all OK' : 'needs attention') ?></span></summary>
      <?php foreach ($groups as $section => $checks): ?>
        <?php $groupOk = true; foreach ($checks as $c) { if (!($c['ok'] ?? false)) { $groupOk = false; } } ?>
        <details class="ui-fold-inner"<?= $groupOk ? '' : ' open' ?>>
        <summary class="ui-subtitle"><?= $this->e($section) ?> <span class="ui-chip <?= $groupOk ? 'ui-chip-ok' : 'ui-chip-warn' ?>"><?= $groupOk ? 'OK' : 'check' ?></span></summary>
        <ul class="ui-checks">
          <?php foreach ($checks as $check): ?>
            <li class="<?= ($check['ok'] ?? false) ? 'is-ok' : 'is-bad' ?>">
              <span class="ui-dot <?= ($check['ok'] ?? false) ? 'ui-dot-working' : 'ui-dot-blocked' ?>"></span>
              <span><?= $this->e((string)($check['label'] ?? $check['key'] ?? '')) ?>
                <?php if (!empty($check['detail'])): ?><small class="ui-muted"><?= $this->e((string)$check['detail']) ?></small><?php endif ?>
              </span>
            </li>
          <?php endforeach ?>
        </ul>
        </details>
      <?php endforeach ?>
      <?php if ($healthChecks === []): ?><p class="ui-muted ui-small">No health data (the host agent may be unreachable).</p><?php endif ?>
    </details>

    <div class="ui-card ui-card-flat ui-col">
      <h2 class="ui-section-title">Notifications</h2>
      <?= \App\Views\PushNotifyView::push_notify_button_html($vapidPublicKey ?? '', $csrfToken) ?>
    </div>

    <?php if ($pushTimerSeconds !== null): ?>
      <div class="ui-card ui-card-flat ui-col">
        <h2 class="ui-section-title">Push-check interval</h2>
        <div class="ui-row">
          <select id="ui-push-interval" aria-label="Push-check interval">
            <?php $presets = array_unique(array_merge([5, 10, 15, 30, 60, 120], [$pushTimerSeconds])); sort($presets); ?>
            <?php foreach ($presets as $p): ?><option value="<?= (int)$p ?>"<?= $p === $pushTimerSeconds ? ' selected' : '' ?>><?= (int)$p ?>s</option><?php endforeach ?>
          </select>
          <button type="button" class="ui-btn" data-act="push-interval">Save</button>
        </div>
      </div>
    <?php endif ?>

  </section>
</main>

<?php if ($agentReachable): ?>
  <button type="button" class="ui-fab" id="ui-new" aria-label="New session">+ New</button>
<?php endif ?>

<nav class="ui-tabbar" aria-label="Main">
  <button type="button" class="ui-tab is-on" data-tab="sessions"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg><span>Sessions</span><b id="ui-tab-badge" class="ui-badge" hidden></b></button>
  <button type="button" class="ui-tab" data-tab="search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m20 20-4-4"/></svg><span>Search</span></button>
  <button type="button" class="ui-tab" data-tab="archived"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10h14V9M10 13h4"/></svg><span>Archived</span></button>
  <button type="button" class="ui-tab" data-tab="status"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l3-7 4 14 3-7h4"/></svg><span>Status</span></button>
</nav>

<?php /* Sheets are <dialog>s: focus trap, Esc and backdrop come from the platform. */ ?>
<dialog id="ui-takeover-dialog" class="ui-sheet">
  <div class="ui-sheet-body">
    <div class="ui-sheet-head"><h2>Which conversation?</h2><button type="button" class="ui-icon-btn" data-close aria-label="Close">&times;</button></div>
    <p class="ui-muted ui-small">The process was not identified for certain. Nothing is stopped until you pick one.</p>
    <p id="ui-takeover-none" class="ui-muted" hidden>No past conversations found for this folder.</p>
    <select id="ui-takeover-select" aria-label="Conversation"></select>
    <button type="button" class="ui-btn ui-btn-primary" id="ui-takeover-go">Take over as headless</button>
  </div>
</dialog>

<dialog id="ui-new-dialog" class="ui-sheet">
  <form method="dialog" id="ui-new-form" class="ui-sheet-body">
    <div class="ui-sheet-head"><h2>New session</h2><button type="button" class="ui-icon-btn" data-close aria-label="Close">&times;</button></div>

    <label class="ui-field">Agent
      <select id="ui-new-agent" name="agent">
        <?php foreach ($agentOptions as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $key === 'claude' ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?>
      </select>
    </label>
    <label class="ui-field" id="ui-new-profile-wrap" hidden>Account
      <select id="ui-new-profile" name="profile"><option value="">Default</option></select>
    </label>
    <label class="ui-field">Model
      <select id="ui-new-model" name="model"><option value="">Default</option></select>
    </label>
    <label class="ui-field" data-agent-field>Starting mode
      <select id="ui-new-mode" name="starting_mode">
        <option value="" selected>Manual (default)</option>
        <?php foreach (\App\Views\UiView::MODE_OPTIONS as $modeKey => $modeLabel): ?>
          <?php if ($modeKey === 'manual') { continue; } ?>
          <option value="<?= $this->e($modeKey) ?>"><?= $this->e($modeLabel) ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label class="ui-check" data-agent-field><input type="checkbox" id="ui-new-tasktools"> Enable task tracking tools</label>

    <label class="ui-field" id="ui-new-prompt-wrap">First message (optional)
      <textarea id="ui-new-prompt" name="prompt" rows="3" placeholder="Sent as soon as the session starts"></textarea>
    </label>

    <div class="ui-field">Working directory
      <div class="ui-browser">
        <div class="ui-browser-bar"><code id="ui-browser-path">Loading&hellip;</code><button type="button" class="ui-link-btn" id="ui-new-folder">+ Folder</button></div>
        <ul id="ui-browser-list"></ul>
      </div>
    </div>

    <button type="submit" class="ui-btn ui-btn-primary ui-block" id="ui-new-submit" disabled>Start session here</button>
  </form>
</dialog>

<dialog id="ui-settings-dialog" class="ui-sheet">
  <div class="ui-sheet-body">
    <div class="ui-sheet-head"><h2>Settings</h2><button type="button" class="ui-icon-btn" data-close aria-label="Close">&times;</button></div>
    <label class="ui-field">Theme
      <select id="ui-theme"><option value="system">Match device</option><option value="light">Light</option><option value="dark">Dark</option></select>
    </label>
    <label class="ui-field">Refresh every
      <select id="ui-poll">
        <option value="1000">1 second</option><option value="3000">3 seconds</option><option value="5000">5 seconds</option>
        <option value="10000">10 seconds</option><option value="15000">15 seconds</option>
      </select>
    </label>
    <label class="ui-check"><input type="checkbox" id="ui-show-workers"> Show worker sessions</label>
    <label class="ui-check"><input type="checkbox" id="ui-hide-idle"> Hide idle unstarred</label>
    <button type="button" class="ui-btn ui-btn-warn ui-block" data-act="cleanup">Kill sessions inactive &gt; 12h</button>
  </div>
</dialog>

<div id="ui-toast" class="ui-toast" role="status" aria-live="polite" hidden></div>
</div>

<script src="<?= \App\Assets::versioned_url('/js/ui-sheets.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/ui-quota.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/ui-advise.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/ui.js') ?>"></script>
