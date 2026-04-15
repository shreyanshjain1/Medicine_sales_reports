<?php
$filterAttrs = $filterAttrs ?? ['method' => 'get', 'class' => 'filters'];
$filterContent = $filterContent ?? '';
$filterResetUrl = $filterResetUrl ?? '';
$filterPrimaryLabel = $filterPrimaryLabel ?? 'Apply';
$filterSecondaryHtml = $filterSecondaryHtml ?? '';
?>
<form<?= ui_attr_string($filterAttrs) ?>>
  <?= $filterContent ?>
  <div class="actions-inline ui-filter-actions<?= !empty($filterActionClass) ? ' ' . e($filterActionClass) : '' ?>">
    <button class="btn primary" type="submit"><?= e($filterPrimaryLabel) ?></button>
    <?php if ($filterResetUrl !== ''): ?>
      <a class="btn" href="<?= e($filterResetUrl) ?>">Reset</a>
    <?php endif; ?>
    <?= $filterSecondaryHtml ?>
  </div>
</form>
