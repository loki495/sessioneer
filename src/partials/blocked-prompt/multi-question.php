<div class="select-none multi-question-wrapper mt-2" data-session="<?= $this->e($sessionName) ?>" data-csrf-token="<?= $this->e($csrfToken) ?>">
  <?php foreach ($questions as $qIndex => $q): ?>
    <?php
      $options = is_array($q['options'] ?? null) ? $q['options'] : [];
      // OpenCode names this field `multiple`; Claude/Codex hook payloads
      // use `multiSelect`. Both describe the same checkbox answer shape.
      $isMulti = ($q['multiSelect'] ?? $q['multiple'] ?? false) === true;
      // OpenCode defaults custom answers on when the key is omitted, while
      // an explicit false must suppress the free-text affordance.
      $allowsCustom = ($q['custom'] ?? true) === true;
      $inputType = $isMulti ? 'checkbox' : 'radio';
      $inputName = 'q' . (int)$qIndex . ($isMulti ? '[]' : '');
      $freetextValue = count($options) + 1;
    ?>
    <div class="mb-3 last:mb-0" data-question-index="<?= (int)$qIndex ?>" data-multi="<?= $isMulti ? '1' : '0' ?>">
      <p class="text-amber-200 text-sm font-medium mb-1.5"><?= $this->e((string)($q['question'] ?? '')) ?></p>
      <div class="flex flex-col gap-1.5">
        <?php foreach ($options as $optIndex => $opt): ?>
          <label class="flex items-center gap-2 text-sm text-amber-100">
            <input type="<?= $inputType ?>" name="<?= $inputName ?>" value="<?= (int)($optIndex + 1) ?>" class="accent-indigo-600">
            <span class="break-words"><?= $this->e((string)($opt['label'] ?? '')) ?></span>
          </label>
        <?php endforeach ?>
        <?php if (!$isMulti && $allowsCustom): ?>
          <label class="flex items-center gap-2 text-sm text-amber-100">
            <input type="radio" name="<?= $inputName ?>" value="<?= $freetextValue ?>" class="freetext-toggle accent-indigo-600">
            <span>Type something&hellip;</span>
          </label>
          <!-- text-base (16px), not text-sm - iOS Safari auto-zooms the
               whole viewport in on focusing any text input rendered under
               16px, no way to opt out of that short of the font size
               itself (see sidebar.php's own copy of this comment).
               A <textarea>, not a plain <input type="text"> (changed
               2026-08-24, Andres's own call after a live-risk check) - lets
               the fullscreen-expand editor (common.js's
               openFullscreenEditModal()) offer real multi-line typing room
               here too. Any embedded newline is still stripped back to a
               space before ever being sent (collectMultiQuestionAnswers()
               in common.js) - confirmed the live AskUserQuestion tab UI's
               inline-editable option field is driven by `tmux send-keys -l`
               followed by one final literal Enter (PromptParser::
               build_multi_question_key_sequence()), so a raw newline byte
               landing mid-string there would almost certainly be read as
               an early Enter, submitting partial text and desyncing every
               answer after it - never actually sent this way, "textarea for
               comfortable typing" and "single-line answer on the wire" both
               hold at once. -->
          <div class="relative mt-1">
            <textarea class="freetext-input hidden w-full resize-none rounded-lg bg-slate-800 border border-slate-700 text-base text-slate-100 px-2 py-1.5" rows="2" placeholder="Type your answer&hellip;"></textarea>
            <button type="button" class="expand-edit-fullscreen-btn hidden absolute top-1 right-1 w-6 h-6 flex items-center justify-center rounded text-slate-500 active:text-slate-300 text-base leading-none" aria-label="Expand to full screen" tabindex="-1">&#10530;</button>
          </div>
        <?php endif ?>
      </div>
    </div>
  <?php endforeach ?>
  <button type="button" class="multi-question-submit-btn mt-1 rounded-lg bg-indigo-600 active:bg-indigo-700 text-white text-xs font-medium px-3 py-2">Send answers</button>
</div>
