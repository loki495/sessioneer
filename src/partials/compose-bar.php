<?php

use App\Views\PushNotifyView;
use App\Views\QuotaFooterView;
use App\Views\TranscriptView;

if ($found): ?>
  <?php
    $composeBlocked = !empty($detail['blocked_reason']);
    $composeReadOnly = ($detail['writable'] ?? true) === false;
  ?>
  <?php /* A normal (non-fixed) flex item, last child of #app-shell (see
       session.php) - position:fixed here used to visually detach mid-
       scroll on iOS Safari (a known-buggy pairing
       with the page-level scroll that used to drive this whole page), so
       the fix was to stop relying on fixed positioning for this element
       at all rather than patch around it. */ ?>
  <div id="compose-bar" class="select-none flex-none bg-slate-950/95 border-t border-slate-800 px-4 py-3">
    <div class="max-w-2xl lg:max-w-4xl mx-auto">
      <div id="compose-input-row" class="<?= ($composeBlocked || $composeReadOnly) ? 'hidden' : '' ?>">
        <div id="compose-attachments-preview" class="hidden flex flex-wrap gap-2 mb-2"></div>
        <div class="flex items-stretch gap-2">
          <button type="button" id="compose-attach-btn" aria-label="Attach file"
            class="min-h-[2.75rem] w-11 shrink-0 rounded-lg bg-slate-800 border border-slate-700 active:bg-slate-700 disabled:opacity-50 text-slate-300 text-xl leading-none">
            +
          </button>
          <input type="file" id="compose-file-input" class="hidden" multiple>
          <div class="relative flex-1">
            <textarea id="compose-textarea" rows="1" placeholder="Message&hellip;"
              class="w-full resize-none rounded-lg bg-slate-800 border border-slate-700 text-base text-slate-100 pl-3 pr-14 py-2 max-h-32 overflow-y-auto overscroll-contain focus:outline-none focus:border-slate-500"></textarea>
            <?php /* Sits left of the clear button (both top-1, right-8/right-1)
                 rather than replacing it - expanding to full screen while
                 typing is offered in addition to clearing, not instead of it.
                 Always visible (unlike the clear button, which only shows
                 once there's text) - expanding an empty compose box is
                 harmless, and gating it the same way would just be an extra
                 state to track for no real benefit. */ ?>
            <button type="button" id="compose-textarea-expand-btn" class="expand-edit-fullscreen-btn absolute top-1 right-8 w-6 h-6 flex items-center justify-center rounded text-slate-500 active:text-slate-300 text-base leading-none" aria-label="Expand to full screen" tabindex="-1">&#10530;</button>
            <button type="button" id="compose-textarea-clear-btn" aria-label="Clear message" tabindex="-1"
              class="hidden absolute top-1 right-1 w-6 h-6 flex items-center justify-center rounded text-slate-500 active:text-slate-300 text-lg leading-none">&times;</button>
          </div>
          <button type="button" id="compose-send-btn" disabled
            class="min-h-[2.75rem] shrink-0 rounded-lg bg-indigo-600 active:bg-indigo-700 disabled:opacity-50 disabled:active:bg-indigo-600 font-medium text-sm px-4 py-2">
            Send
          </button>
        </div>
        <div id="compose-upload-status" class="hidden text-xs text-slate-500 mt-1"></div>
        <div id="compose-status" class="hidden text-xs text-red-400 mt-1"></div>
      </div>
      <div id="compose-blocked-note" class="<?= $composeBlocked ? '' : 'hidden' ?> text-xs text-slate-500 py-2">
        <?= !empty($detail['prompt_external'])
            ? 'Open Codex Remote to answer this prompt and continue.'
            : 'Answer the prompt above to continue.' ?>
      </div>
      <div id="compose-read-only-note" class="<?= $composeReadOnly ? '' : 'hidden' ?> rounded-lg border border-amber-800/50 bg-amber-950/25 px-3 py-2 text-xs text-amber-300">
        <?= $this->e((string)($detail['read_only_reason'] ?? 'This Codex session is currently read-only.')) ?>
      </div>
      <?php
        // Antigravity has no mode toggle (no Shift+Tab-cycled permission
        // modes to mirror) and a differently-scoped model toggle - see
        // TranscriptView::render_antigravity_model_toggle_html()'s own
        // docblock (always a global-default switch, never session-only).
        // OpenCode likewise has no mode toggle and its model list is the
        // serve's dynamic /config/providers set, populated client-side.
        // Ollama has no permission mode, no effort and no meaningful Claude
        // subscription quota, so it gets only the model switch below.
        $agent = $detail['agent'] ?? 'claude';
        $agentExtras = $agent === 'codex'
            ? TranscriptView::render_codex_model_toggle_html($detail)
            : ($agent === 'antigravity'
            ? TranscriptView::render_antigravity_model_toggle_html($detail)
            : ($agent === 'opencode'
                ? TranscriptView::render_opencode_model_toggle_html($detail)
                 : '<div id="claude-session-controls" class="flex min-w-0 w-full items-center justify-between gap-2 sm:w-auto">'
                    . TranscriptView::render_model_toggle_html($detail)
                    . TranscriptView::render_mode_toggle_html($detail)
                    . TranscriptView::render_effort_toggle_html($detail)
                    . '</div>'));
      ?>
      <div class="mt-2">
        <?php if ($agent === 'ollama'): ?>
          <div class="flex min-w-0 w-full items-center gap-2">
            <?php include __DIR__ . '/transcript/ollama-model-toggle.php'; ?>
          </div>
          <?= PushNotifyView::push_notify_button_html($vapidPublicKey, $csrfToken) ?>
        <?php else: ?>
          <?= QuotaFooterView::quota_footer_html($agentExtras, $sessionName) ?>
          <?php if ($agent === 'claude'): ?>
            <div id="effort-setting-error" role="status" class="hidden mt-1 text-xs text-red-400"></div>
          <?php endif; ?>
          <?= PushNotifyView::push_notify_button_html($vapidPublicKey, $csrfToken) ?>
        <?php endif ?>
      </div>
    </div>
  </div>
<?php endif; ?>
