<select id="effort-select" aria-label="Reasoning effort" title="<?= $this->e($message) ?>"<?= $available ? '' : ' disabled' ?> class="select-none min-w-0 flex-1 text-xs font-medium pl-2 pr-6 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-300 disabled:opacity-50">
  <option value=""><?= $available ? 'Default effort' : 'Unavailable' ?></option>
  <?php foreach ($options as $value => $label): ?>
    <option value="<?= $this->e($value) ?>"<?= $value === $effort ? ' selected' : '' ?>><?= $this->e($label) ?></option>
  <?php endforeach; ?>
</select>
