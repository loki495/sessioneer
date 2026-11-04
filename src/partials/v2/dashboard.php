<?php $this->layout('v2/layout', ['title' => 'Sessioneer']) ?>
<?php
  $allOk = true;
  foreach ($healthChecks as $check) { if (!($check['ok'] ?? false)) { $allOk = false; break; } }
  $groups = [];
  foreach ($healthChecks as $check) { $groups[(string)($check['section'] ?? 'General')][] = $check; }
?>
<div id="v2-app" data-csrf="<?= $this->e($csrfToken) ?>">

<header class="v2-appbar">
  <div class="v2-appbar-title">
    <h1>Sessioneer</h1>
    <p id="v2-subtitle" class="v2-muted v2-small"></p>
  </div>
  <a class="v2-pill-link" href="/" data-classic-link>Classic</a>
  <button type="button" class="v2-icon-btn" id="v2-open-settings" aria-label="Settings">&#9881;</button>
</header>

<main class="v2-main">
  <div id="v2-banners">
    <?php if (!$agentReachable): ?>
      <div class="v2-banner v2-banner-bad" role="alert">
        <strong>Cannot reach the host agent.</strong>
        <span><?= $this->e($agentMessage) ?></span>
        <code>systemctl --user status sessioneer-agent.socket</code>
      </div>
    <?php endif ?>
    <?php if ($hooksMissing): ?>
      <div class="v2-banner v2-banner-warn">
        <strong>App hooks aren't fully installed.</strong>
        <span>Status, blocked prompts and transcripts can be stale until they are.</span>
        <button type="button" class="v2-btn v2-btn-warn" data-act="install-hook">Install hooks</button>
      </div>
    <?php endif ?>
  </div>

  <section class="v2-panel" id="v2-panel-sessions" data-panel="sessions">
    <div class="v2-filters" role="tablist" aria-label="Filter sessions">
      <button type="button" class="v2-filter is-on" data-filter="all">All <b id="v2-n-all"><?= (int)$counts['total'] ?></b></button>
      <button type="button" class="v2-filter" data-filter="blocked">Needs input <b id="v2-n-blocked"><?= (int)$counts['blocked'] ?></b></button>
      <button type="button" class="v2-filter" data-filter="working">Working <b id="v2-n-working"><?= (int)$counts['working'] ?></b></button>
      <button type="button" class="v2-filter" data-filter="idle">Idle <b id="v2-n-idle"><?= (int)$counts['idle'] ?></b></button>
    </div>
    <div id="v2-sessions" class="v2-stack"><?= $sessionsHtml ?></div>
    <p id="v2-filter-empty" class="v2-muted v2-center" hidden>Nothing matches this filter.</p>
    <div id="v2-bare"><?= $bareHtml ?></div>
  </section>

  <section class="v2-panel" id="v2-panel-search" data-panel="search" hidden>
    <label class="v2-search">
      <span class="v2-sr">Search all sessions</span>
      <input type="search" id="v2-search-input" placeholder="Search every session's content&hellip;" autocomplete="off" enterkeyhint="search">
    </label>
    <div id="v2-search-results" class="v2-stack"></div>
  </section>

  <section class="v2-panel" id="v2-panel-archived" data-panel="archived" hidden>
    <label class="v2-search">
      <span class="v2-sr">Filter archived sessions</span>
      <input type="search" id="v2-archived-filter" placeholder="Filter archived by title or folder&hellip;" autocomplete="off">
    </label>
    <div id="v2-archived" class="v2-stack"><p class="v2-muted v2-center">Loading&hellip;</p></div>
    <button type="button" class="v2-btn v2-btn-quiet v2-block" id="v2-archived-more" hidden>Show more</button>
  </section>

  <section class="v2-panel" id="v2-panel-status" data-panel="status" hidden>
    <div class="v2-card v2-card-flat v2-col">
      <h2 class="v2-section-title">Usage</h2>
      <div id="v2-quota" class="v2-quota"><span class="v2-muted v2-small">Loading&hellip;</span></div>
    </div>

    <details class="v2-card v2-card-flat v2-col v2-health"<?= $allOk && $healthChecks !== [] ? '' : ' open' ?>>
      <summary class="v2-section-title">Health <span class="v2-chip <?= $allOk ? 'v2-chip-ok' : 'v2-chip-warn' ?>"><?= $healthChecks === [] ? 'unknown' : ($allOk ? 'all OK' : 'needs attention') ?></span></summary>
      <?php foreach ($groups as $section => $checks): ?>
        <h3 class="v2-subtitle"><?= $this->e($section) ?></h3>
        <ul class="v2-checks">
          <?php foreach ($checks as $check): ?>
            <li class="<?= ($check['ok'] ?? false) ? 'is-ok' : 'is-bad' ?>">
              <span class="v2-dot <?= ($check['ok'] ?? false) ? 'v2-dot-working' : 'v2-dot-blocked' ?>"></span>
              <span><?= $this->e((string)($check['label'] ?? $check['key'] ?? '')) ?>
                <?php if (!empty($check['detail'])): ?><small class="v2-muted"><?= $this->e((string)$check['detail']) ?></small><?php endif ?>
              </span>
            </li>
          <?php endforeach ?>
        </ul>
      <?php endforeach ?>
      <?php if ($healthChecks === []): ?><p class="v2-muted v2-small">No health data (the host agent may be unreachable).</p><?php endif ?>
    </details>

    <?php if ($pushTimerSeconds !== null): ?>
      <div class="v2-card v2-card-flat v2-col">
        <h2 class="v2-section-title">Push-check interval</h2>
        <div class="v2-row">
          <select id="v2-push-interval" aria-label="Push-check interval">
            <?php $presets = array_unique(array_merge([5, 10, 15, 30, 60, 120], [$pushTimerSeconds])); sort($presets); ?>
            <?php foreach ($presets as $p): ?><option value="<?= (int)$p ?>"<?= $p === $pushTimerSeconds ? ' selected' : '' ?>><?= (int)$p ?>s</option><?php endforeach ?>
          </select>
          <button type="button" class="v2-btn" data-act="push-interval">Save</button>
        </div>
        <p class="v2-muted v2-small">Browser push notifications are still enabled from the classic dashboard.</p>
      </div>
    <?php endif ?>

    <a class="v2-btn v2-btn-quiet v2-block" href="/" data-classic-link>&larr; Back to classic</a>
  </section>
</main>

<?php if ($agentReachable): ?>
  <button type="button" class="v2-fab" id="v2-new" aria-label="New session">+ New</button>
<?php endif ?>

<nav class="v2-tabbar" aria-label="Main">
  <button type="button" class="v2-tab is-on" data-tab="sessions"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h10"/></svg><span>Sessions</span><b id="v2-tab-badge" class="v2-badge" hidden></b></button>
  <button type="button" class="v2-tab" data-tab="search"><svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="11" cy="11" r="6"/><path d="m20 20-4-4"/></svg><span>Search</span></button>
  <button type="button" class="v2-tab" data-tab="archived"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="4" width="18" height="5" rx="1"/><path d="M5 9v10h14V9M10 13h4"/></svg><span>Archived</span></button>
  <button type="button" class="v2-tab" data-tab="status"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 12h4l3-7 4 14 3-7h4"/></svg><span>Status</span></button>
</nav>

<?php /* Sheets are <dialog>s: focus trap, Esc and backdrop come from the platform. */ ?>
<dialog id="v2-new-dialog" class="v2-sheet">
  <form method="dialog" id="v2-new-form" class="v2-sheet-body">
    <div class="v2-sheet-head"><h2>New session</h2><button type="button" class="v2-icon-btn" data-close aria-label="Close">&times;</button></div>

    <label class="v2-field">Agent
      <select id="v2-new-agent" name="agent">
        <?php foreach ($agentOptions as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $key === 'claude' ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?>
      </select>
    </label>
    <label class="v2-field" id="v2-new-profile-wrap" hidden>Account
      <select id="v2-new-profile" name="profile"><option value="">Default</option></select>
    </label>
    <label class="v2-field" id="v2-new-runtime-wrap">Runs in
      <select id="v2-new-runtime" name="runtime">
        <option value="headless" selected>Headless (no terminal)</option>
        <option value="tmux">Terminal (tmux)</option>
      </select>
    </label>
    <label class="v2-field">Model
      <select id="v2-new-model" name="model"><option value="">Default</option></select>
    </label>
    <label class="v2-field" data-agent-field>Starting mode
      <select id="v2-new-mode" name="starting_mode">
        <option value="" selected>Manual (default)</option>
        <?php foreach (\App\Views\TranscriptView::MODE_OPTIONS as $modeKey => $modeLabel): ?>
          <?php if ($modeKey === 'manual') { continue; } ?>
          <option value="<?= $this->e($modeKey) ?>"><?= $this->e($modeLabel) ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label class="v2-check" data-agent-field><input type="checkbox" id="v2-new-tasktools"> Enable task tracking tools</label>

    <label class="v2-field" id="v2-new-prompt-wrap">First message (optional)
      <textarea id="v2-new-prompt" name="prompt" rows="3" placeholder="Sent as soon as the session starts"></textarea>
    </label>

    <div class="v2-field">Working directory
      <div class="v2-browser">
        <div class="v2-browser-bar"><code id="v2-browser-path">Loading&hellip;</code><button type="button" class="v2-link-btn" id="v2-new-folder">+ Folder</button></div>
        <ul id="v2-browser-list"></ul>
      </div>
    </div>

    <button type="submit" class="v2-btn v2-btn-primary v2-block" id="v2-new-submit" disabled>Start session here</button>
  </form>
</dialog>

<dialog id="v2-settings-dialog" class="v2-sheet">
  <div class="v2-sheet-body">
    <div class="v2-sheet-head"><h2>Settings</h2><button type="button" class="v2-icon-btn" data-close aria-label="Close">&times;</button></div>
    <label class="v2-field">Theme
      <select id="v2-theme"><option value="system">Match device</option><option value="light">Light</option><option value="dark">Dark</option></select>
    </label>
    <label class="v2-field">Refresh every
      <select id="v2-poll">
        <option value="1000">1 second</option><option value="3000">3 seconds</option><option value="5000">5 seconds</option>
        <option value="10000">10 seconds</option><option value="15000">15 seconds</option>
      </select>
    </label>
    <label class="v2-check"><input type="checkbox" id="v2-show-workers"> Show worker sessions</label>
    <label class="v2-check"><input type="checkbox" id="v2-hide-idle"> Hide idle unstarred</label>
    <button type="button" class="v2-btn v2-btn-warn v2-block" data-act="cleanup">Kill sessions inactive &gt; 12h</button>
    <a class="v2-btn v2-btn-quiet v2-block" href="/" data-classic-link>&larr; Back to classic</a>
  </div>
</dialog>

<div id="v2-toast" class="v2-toast" role="status" aria-live="polite" hidden></div>
</div>

<script src="<?= \App\Assets::versioned_url('/js/v2-quota.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/v2.js') ?>"></script>
