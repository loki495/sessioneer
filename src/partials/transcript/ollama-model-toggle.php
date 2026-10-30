<?php /* Renders the session's current model as a real option rather than
     OpenCode's empty "Loading models…" placeholder: the model is stored on
     the session (session_status.model), so the control is correct before -
     and without - the catalog fetch in public/js/ollama.js, which then
     replaces these options with the server's full list. */ ?>
<select id="ollama-model-select" aria-label="Model" data-current-model="<?= $this->e((string)($detail['current_model'] ?? '')) ?>" class="select-none min-w-0 flex-1 text-xs font-medium pl-2 pr-6 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-300">
  <?php if (($detail['current_model'] ?? '') !== ''): ?>
    <option value="<?= $this->e((string)$detail['current_model']) ?>" selected><?= $this->e((string)$detail['current_model']) ?></option>
  <?php else: ?>
    <option value="" selected>Model unknown</option>
  <?php endif ?>
</select>
