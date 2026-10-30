<select id="mode-select" aria-label="Permission mode"<?= $mode === null ? ' disabled' : '' ?> class="select-none min-w-0 flex-1 text-xs font-medium pl-2 pr-6 py-1 rounded-full border border-slate-700 bg-slate-800 text-slate-300 disabled:opacity-50">
  <?php if ($mode === null): ?><option value="" selected>Mode unknown</option><?php endif ?>
  <?php foreach ($options as $key => $label): ?><option value="<?= $this->e($key) ?>"<?= $key === $mode ? ' selected' : '' ?>><?= $this->e($label) ?></option><?php endforeach ?>
</select>
