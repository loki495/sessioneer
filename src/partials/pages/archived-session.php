<?php
$this->layout('layout', [
    'title' => $found ? (string)($detail['title'] ?? $agentSessionId) : 'Sessioneer',
    'viewportContent' => 'width=device-width, initial-scale=1, minimum-scale=1, maximum-scale=5, viewport-fit=cover',
]);
?>

<header class="select-none sticky top-0 z-20 bg-slate-950/95 backdrop-blur border-b border-slate-800">
  <div class="max-w-2xl lg:max-w-4xl mx-auto px-4 py-2 flex items-center gap-2">
    <a href="/" class="text-sm text-slate-400 hover:underline whitespace-nowrap">&larr; All sessions</a>
    <div class="text-sm font-medium text-slate-200 truncate flex-1 text-center">
      <?= $found ? $this->e((string)($detail['title'] ?? $agentSessionId)) : '' ?>
    </div>
    <span class="text-xs text-slate-500 shrink-0 rounded-full border border-slate-700 px-2 py-0.5">Archived</span>
  </div>
</header>

<div id="page-content" class="max-w-2xl lg:max-w-4xl mx-auto px-4 py-6">

  <?php if (!$found): ?>
    <div class="select-none rounded-lg px-4 py-3 text-sm bg-red-900/50 text-red-200 border border-red-700">
      <p class="font-medium">Session not found.</p>
      <p class="mt-1"><?= $this->e((string)($detail['message'] ?? 'Unknown error')) ?></p>
    </div>
  <?php else: ?>
    <div class="select-none mb-4 rounded-xl border border-slate-800 bg-slate-900/50 px-4 py-3 flex items-start justify-between gap-3">
      <div class="min-w-0 flex-1">
        <div class="text-sm text-slate-200"><?= $this->e((string)($detail['title'] ?? $agentSessionId)) ?></div>
        <?php // Account/profile badge - same Claude-only gate as the live session
              // header/list rows (see SessionRowView::profile_badge_class()'s own
              // docblock). ?>
        <?php if (($detail['agent'] ?? 'claude') === 'claude'): ?>
          <div class="mt-0.5">
            <span class="inline-block text-[10px] leading-none font-medium px-2 py-0.5 rounded-full border <?= \App\Views\SessionRowView::profile_badge_class($detail['profile'] ?? null) ?>"><?= $this->e(\App\Views\SessionRowView::profile_label($detail['profile'] ?? null)) ?></span>
          </div>
        <?php endif ?>
        <?php if (!empty($detail['cwd'])): ?><div class="text-xs text-slate-500 truncate mt-0.5"><?= $this->e((string)$detail['cwd']) ?></div><?php endif ?>
        <div class="text-xs text-slate-400 mt-1">Last active <?= $this->e(\App\Views\SessionRowView::relative_time((int)($detail['last_activity'] ?? 0))) ?></div>
      </div>
      <?php if (!empty($detail['cwd'])): ?>
      <form method="post" action="/" class="shrink-0">
        <input type="hidden" name="action" value="resume">
        <input type="hidden" name="csrf_token" value="<?= $this->e($csrfToken) ?>">
        <input type="hidden" name="agent_session_id" value="<?= $this->e($agentSessionId) ?>">
        <input type="hidden" name="workdir" value="<?= $this->e((string)$detail['cwd']) ?>">
        <button type="submit" class="select-none min-h-[2.75rem] rounded-lg border border-slate-700 bg-slate-800 active:bg-slate-700 text-slate-200 font-medium text-sm px-4 py-2">Unarchive</button>
      </form>
      <?php endif ?>
    </div>

    <div class="select-none mb-4">
      <!-- text-base (16px), not text-sm - iOS Safari auto-zooms the whole
           viewport in on focusing any text input rendered under 16px
           (found live 2026-08-20: this one was missed in the same pass
           that fixed sidebar.php's/index.php's own search inputs).
           appearance-none suppresses the native WebKit search-cancel
           button - see sidebar.php's own copy of this comment for why. -->
      <div class="relative">
        <input type="search" id="session-search-input" placeholder="Search this conversation&hellip;" autocomplete="off"
          class="appearance-none w-full rounded-lg border border-slate-700 bg-slate-800 pl-3 pr-9 py-2 text-base text-slate-200 placeholder:text-slate-500">
        <button type="button" id="session-search-input-clear-btn" aria-label="Clear search" tabindex="-1"
          class="hidden absolute top-1/2 right-1.5 -translate-y-1/2 w-6 h-6 flex items-center justify-center rounded text-slate-500 active:text-slate-300 text-lg leading-none">&times;</button>
      </div>
      <div id="session-search-results" class="mt-2 flex flex-col gap-1.5 text-sm"></div>
    </div>

    <?php if ($jumpLine !== null): ?>
      <!-- See session.php's own jump_line banner for the full reasoning -
           same idea, minus any live-poll follow-up (there is none here). -->
      <div class="select-none mb-4 rounded-lg border border-sky-800/40 bg-sky-950/20 px-3 py-2 flex items-center justify-between gap-2 text-xs text-sky-300">
        <span>Showing a search result</span>
        <a href="/archived_session.php?agent_session_id=<?= urlencode($agentSessionId) ?>" class="text-sky-400 active:text-sky-200 font-medium">Back to latest &rarr;</a>
      </div>
    <?php endif; ?>

    <h2 class="select-none text-sm font-medium text-slate-400 mb-2">History (read-only)</h2>

    <?php if (!$historyOk): ?>
      <div class="select-none rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
        <?= $this->e((string)($history['message'] ?? 'No transcript available for this session.')) ?>
      </div>
    <?php elseif (empty($entries)): ?>
      <div class="select-none rounded-lg px-4 py-3 text-sm bg-slate-900/50 border border-slate-800 text-slate-500">
        Nothing recorded.
      </div>
    <?php else: ?>
      <?php
        // Found live 2026-09-18: this used to hardcode 'Claude Code' below
        // regardless of the session's real agent - now that
        // archived_session_detail() resolves 'agent' (see
        // SessionDetailService.php), match it the same way session.php's
        // own $agentLabel derivation does.
        $agentLabel = match ($detail['agent'] ?? 'claude') {
            'antigravity' => 'Antigravity',
            'opencode' => 'OpenCode',
            'codex' => 'Codex',
            default => 'Claude Code',
        };
      ?>
      <button type="button" id="load-more-btn"
        data-claude-session-id="<?= $this->e($agentSessionId) ?>"
        data-before="<?= $nextBefore !== null ? (int)$nextBefore : '' ?>"
        class="select-none w-full mb-2 rounded-lg border border-slate-800 bg-slate-900/50 active:bg-slate-800 text-xs text-slate-400 px-3 py-2 <?= $hasMore ? '' : 'hidden' ?>">
        Load older messages
      </button>
      <div id="history-list" class="flex flex-col gap-2">
        <?= \App\Views\TranscriptView::render_transcript_entries_html($entries, $agentSessionId, true, is_string($detail['cwd'] ?? null) ? $detail['cwd'] : null, $agentLabel) ?>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</div>

<script>
window.SESSIONEER_ARCHIVED_BOOTSTRAP = <?= json_encode(['agentSessionId' => $agentSessionId, 'jumpLine' => $jumpLine]) ?>;
</script>
<script src="<?= \App\Assets::versioned_url('/js/common.js') ?>"></script>
<script src="<?= \App\Assets::versioned_url('/js/archived-session.js') ?>"></script>
