<?php
$this->layout('layout', [
    'title' => 'Sessioneer',
    'viewportContent' => 'width=device-width, initial-scale=1, viewport-fit=cover',
    'fixedShell' => true,
]);
?>
<?php $this->start('head-extra') ?>
<link rel="manifest" href="data:application/manifest+json,%7B%22name%22%3A%22Claude%20Sessions%22%2C%22display%22%3A%22standalone%22%7D">
<?php $this->stop() ?>

<!-- #app-shell: same full-height flex column as session.php (see its own
     comment) - body no longer scrolls, #page-content is the sole scrolling
     container, and #dashboard-footer is a normal flex item instead of
     position:fixed, fixing the same iOS Safari detach-mid-scroll bug that
     hit session.php's compose-bar. -->
<div id="app-shell" class="flex flex-col h-full min-h-0">
<header class="select-none px-4 pt-6 pb-2">
  <div class="max-w-2xl mx-auto flex items-start justify-between gap-2">
    <div class="min-w-0">
      <h1 class="text-xl font-semibold tracking-tight">Sessioneer</h1>
      <p id="session-count-text" class="text-sm text-slate-400 mt-1"><?= \App\Views\SessionRowView::session_count_label_html(count($sessions)) ?></p>
      <label class="select-none mt-1 flex items-center gap-1.5 text-xs text-slate-500">
        <input type="checkbox" id="show-worker-sessions-toggle" class="rounded border-slate-600 bg-slate-800">
        Show worker sessions
      </label>
    </div>
    <select id="poll-interval-select" aria-label="Polling interval"
      class="shrink-0 text-xs font-medium pl-1.5 pr-5 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-400">
      <option value="1000">1s</option>
      <option value="3000" selected>3s</option>
      <option value="5000">5s</option>
      <option value="10000">10s</option>
      <option value="15000">15s</option>
    </select>
  </div>
</header>

<div id="page-content" class="flex-1 min-h-0 overflow-y-auto overscroll-contain">
<div class="max-w-2xl mx-auto px-4 py-6">

  <?php if ($agentReachable): ?>
    <div class="select-none mb-4">
      <!-- text-base (16px), not text-sm - iOS Safari auto-zooms the whole
           viewport in on focusing any text input rendered under 16px, no
           way to opt out of that short of the font size itself.
           appearance-none suppresses the native WebKit search-cancel
           button - see sidebar.php's own copy of this comment for why. -->
      <div class="relative">
        <input type="search" id="dashboard-search-input" placeholder="Search all sessions&hellip;" autocomplete="off"
          data-csrf-token="<?= $this->e($csrfToken) ?>"
          class="appearance-none w-full rounded-lg border border-slate-700 bg-slate-800 pl-3 pr-9 py-2 text-base text-slate-200 placeholder:text-slate-500">
        <button type="button" id="dashboard-search-input-clear-btn" aria-label="Clear search" tabindex="-1"
          class="hidden absolute top-1/2 right-1.5 -translate-y-1/2 w-6 h-6 flex items-center justify-center rounded text-slate-500 active:text-slate-300 text-lg leading-none">&times;</button>
      </div>
      <div id="dashboard-search-results" class="mt-2 flex flex-col gap-2"></div>
    </div>
  <?php endif; ?>

  <?php if (!$agentReachable): ?>
    <div class="select-none mb-4 rounded-lg px-4 py-3 text-sm bg-red-900/50 text-red-200 border border-red-700">
      <p class="font-medium">Cannot reach the host agent.</p>
      <p class="mt-1"><?= $this->e((string)($listResult['message'] ?? 'Unknown error')) ?></p>
      <p class="mt-1 text-red-300">Check on the host: <code>systemctl --user status sessioneer-agent.socket</code></p>
    </div>
  <?php endif; ?>

  <?php if ($flashMsg !== null && $flashMsg !== ''): ?>
    <div class="select-none mb-4 rounded-lg px-4 py-3 text-sm <?= $flashOk ? 'bg-emerald-900/50 text-emerald-200 border border-emerald-700' : 'bg-red-900/50 text-red-200 border border-red-700' ?>">
      <?= $this->e($flashMsg) ?>
    </div>
  <?php endif; ?>

  <?php if ($agentReachable && $hookCheckOk && !$hookInstalled): ?>
    <div class="select-none mb-4 rounded-lg px-4 py-3 text-sm bg-amber-900/40 text-amber-200 border border-amber-700/60">
      <p class="font-medium">App hooks aren't fully installed.</p>
      <p class="mt-1 text-amber-300/90">Some things won't work correctly until every hook is installed: a session's transcript can go stale after <code>/clear</code> or <code>/compact</code>, blocked-prompt previews can be cut short, and a session's working/mode/blocked status may not show up at all.</p>
      <form method="post" action="/" class="mt-2">
        <input type="hidden" name="action" value="install_hook">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
        <button type="submit" class="rounded-lg border border-amber-700/60 bg-amber-900/40 active:bg-amber-800/60 text-amber-100 text-xs font-medium px-3 py-2">
          Install hooks
        </button>
      </form>
    </div>
  <?php elseif ($agentReachable && !$hookCheckOk): ?>
    <div class="select-none mb-4 rounded-lg px-4 py-3 text-sm bg-amber-900/40 text-amber-200 border border-amber-700/60">
      <p class="font-medium">Could not check the app hooks.</p>
      <p class="mt-1 text-amber-300/90"><?= $this->e((string)($hookResult['message'] ?? 'Unknown error')) ?></p>
    </div>
  <?php endif; ?>

  <?= \App\Views\HealthBoxView::health_box_html($healthChecks, $pushTimerIntervalSeconds, $csrfToken) ?>

  <details id="new-session-details" class="select-none mb-3 rounded-xl border border-slate-800 bg-slate-900/50">
    <summary id="new-session-summary" class="min-h-[3rem] flex items-center justify-center rounded-xl bg-indigo-600 active:bg-indigo-700 font-medium text-base px-4 py-3 cursor-pointer list-none [&::-webkit-details-marker]:hidden">
      + New Session
    </summary>
    <form method="post" action="/" class="px-4 pt-4 pb-4 flex flex-col gap-3" id="new-session-form">
      <input type="hidden" name="action" value="new">
      <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
      <input type="hidden" name="workdir" id="workdir_value">
      <div class="text-sm text-slate-300">Working directory</div>
      <div class="rounded-lg border border-slate-700 bg-slate-800 overflow-hidden">
        <div class="flex items-center justify-between gap-2 px-3 py-2 border-b border-slate-700">
          <div id="browser_path" class="text-xs font-mono text-slate-400 truncate">Loading&hellip;</div>
          <button type="button" id="new-folder-btn" data-csrf-token="<?= $this->e($csrfToken) ?>"
            class="shrink-0 text-xs font-medium text-indigo-400 active:text-indigo-300">
            + Folder
          </button>
        </div>
        <ul id="browser_list" class="max-h-56 overflow-y-auto overscroll-contain divide-y divide-slate-700/60 text-sm"></ul>
      </div>
      <!-- Andres's own ask, 2026-08-23: --allowedTools naming the
           TaskCreate/TaskGet/TaskList/TaskUpdate family (Claude Code's
           TodoWrite replacement, left out by default on newer models -
           see CLAUDE.md's "Claude Code tools/hooks questions" note) -
           opt-in and off by default, matching Anthropic's own opt-in
           framing for these tools. Confirmed live: --allowedTools is
           additive (Bash/Read/Write/Edit stay available too), unlike
           --tools which would replace the whole set. The sidebar's Tasks
           widget reads this family back out via TranscriptService::
           find_current_task_list(), cascaded alongside the older TodoWrite
           reader in SessionDetailService::session_detail() - see that method's
           own docblock. -->
      <!-- AgentAdapter picker (docs/antigravity-adapter-plan.md Phase 2) -
           Claude Code stays the pre-selected default so an untouched
           dropdown behaves exactly as before this existed. Antigravity
           sessions currently only get as far as spawning - status
           tracking needs the hook scripts Phase 3 adds. -->
      <label class="flex items-center gap-2 text-sm text-slate-300">
        Agent
        <select id="new-session-agent" name="agent" class="rounded-lg border border-slate-700 bg-slate-800 text-sm text-slate-200 px-2 py-1.5">
          <?php foreach (\App\Views\PageView::AGENT_OPTIONS as $agentKey => $agentLabel): ?>
            <option value="<?= $this->e($agentKey) ?>"<?= $agentKey === 'claude' ? ' selected' : '' ?>><?= $this->e($agentLabel) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <!-- Claude account picker (Dibs plan #230/#234) - which
           host-agent/config/agents.php profile (e.g. a separate work
           account) this session spawns under. Claude-only (agent picker
           above must be 'claude'); hidden entirely for every other agent
           AND when fewer than 2 profiles are configured at all, same "an
           untouched form behaves exactly as before this existed" rule the
           other pickers already follow - see index.js's own
           onAgentChange(). Options are populated client-side (live off
           agents.php via /session_list_claude_profiles.php), never
           server-rendered - this container has no direct filesystem
           access to that host-agent-side config file. -->
      <label id="new-session-profile-label" class="hidden items-center gap-2 text-sm text-slate-300">
        Account
        <select id="new-session-profile" name="profile" class="rounded-lg border border-slate-700 bg-slate-800 text-sm text-slate-200 px-2 py-1.5">
          <option value="">Default</option>
        </select>
      </label>
      <label class="flex items-center gap-2 text-sm text-slate-300">
        Model
        <select id="new-session-model" name="model" class="rounded-lg border border-slate-700 bg-slate-800 text-sm text-slate-200 px-2 py-1.5">
          <option value="">Default</option>
        </select>
        <input type="hidden" id="new-session-model-provider" name="model_provider" value="">
      </label>
      <label class="flex items-center gap-2 text-sm text-slate-300">
        <input type="checkbox" name="enable_task_tools" value="1" class="rounded border-slate-600 bg-slate-800">
        Enable task tracking tools
      </label>
      <!-- Andres's own ask, 2026-08-23, from the CLI-flags research pass in
           the todo file: --permission-mode only sets the mode a NEW process
           starts in (it has no effect on an already-running session - that's
           still exclusively SessionService::set_mode()'s Shift+Tab dance,
           driven by the #mode-select on session.php itself), so this is a
           separate "start directly in this mode" choice, not a replacement
           for anything. Default option carries an empty value, deliberately
           NOT "manual" - an empty starting_mode means create_agent_session()
           omits --permission-mode entirely, so the untouched-dropdown case
           is byte-for-byte the same command line as before this existed,
           not a newly-added explicit --permission-mode default/manual. The
           other options reuse TranscriptView::MODE_OPTIONS - this app's own
           mode vocabulary, translated to Claude Code's real enum value
           host-agent-side (see create_agent_session()'s own docblock). -->
      <label class="flex items-center gap-2 text-sm text-slate-300">
        Starting mode
        <select name="starting_mode" class="rounded-lg border border-slate-700 bg-slate-800 text-sm text-slate-200 px-2 py-1.5">
          <option value="" selected>Manual (default)</option>
          <?php foreach (\App\Views\TranscriptView::MODE_OPTIONS as $modeKey => $modeLabel): ?>
            <?php if ($modeKey === 'manual'): continue; endif ?>
            <option value="<?= $this->e($modeKey) ?>"><?= $this->e($modeLabel) ?></option>
          <?php endforeach ?>
        </select>
      </label>
      <button type="submit" id="new-session-submit" disabled
        class="min-h-[3rem] rounded-lg bg-indigo-600 active:bg-indigo-700 disabled:opacity-50 disabled:active:bg-indigo-600 font-medium text-base px-4 py-3">
        Start Session Here
      </button>
    </form>
  </details>

  <form method="post" action="/" class="select-none mb-6" onsubmit="return confirm('Kill all tracked sessions inactive for more than 12h?');">
    <input type="hidden" name="action" value="cleanup">
    <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
    <button type="submit"
      class="w-full min-h-[3rem] rounded-lg bg-amber-700 active:bg-amber-800 font-medium text-base px-4 py-3">
      Kill inactive &gt;12h
    </button>
  </form>

  <?php if ($agentReachable): ?>
    <div id="sessions-container"><?= \App\Views\SessionRowView::sessions_list_html($sessions, $csrfToken) ?></div>
  <?php endif; ?>

  <?php if ($agentReachable): ?>
    <div id="bare-container"><?= \App\Views\SessionRowView::bare_processes_html($bare, $csrfToken) ?></div>
  <?php endif; ?>

  <?php if ($agentReachable): ?>
    <div class="select-none mt-8">
      <button type="button" id="show-archived-btn" class="w-full text-left rounded-xl border border-slate-800 bg-slate-900/50 active:bg-slate-800 px-4 py-3 text-sm font-medium text-slate-300">
        Show archived sessions
      </button>
      <div id="archived-container" class="hidden mt-3"></div>
    </div>
  <?php endif; ?>

</div>
</div>

<!-- A normal (non-fixed) flex item, last child of #app-shell - same fix as
     session.php's #compose-bar (see its own comment): position:fixed was
     visually detaching mid-scroll on iOS Safari (Andres reported the
     footer jumping to the middle of the screen mid-scroll on mobile), so
     this stops relying on fixed positioning for it entirely. No
     backdrop-blur, kept from the original fixed-positioning mitigation -
     a plain (still translucent, bg-slate-950/90) background reads fine on
     its own regardless. -->
<div id="dashboard-footer" class="flex-none bg-slate-950/90 border-t border-slate-800 px-4 py-3">
  <div class="max-w-2xl mx-auto">
    <div class="flex items-start gap-3">
      <?= \App\Views\QuotaFooterView::quota_footer_html() ?>
    </div>
    <?= \App\Views\PushNotifyView::push_notify_button_html($vapidPublicKey, $csrfToken) ?>
  </div>
</div>
</div>

<script>
window.SESSIONEER_BOOTSTRAP = <?= json_encode(['agentReachable' => $agentReachable]) ?>;
</script>
<script src="<?= \App\Assets::versioned_url('/js/common.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/index.js') ?>"></script>
