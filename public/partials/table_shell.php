<?php
$tableTitle = $tableTitle ?? '';
$tableMeta = $tableMeta ?? '';
$tableActionsHtml = $tableActionsHtml ?? '';
$tableHtml = $tableHtml ?? '';
$tableEmptyMessage = $tableEmptyMessage ?? 'No records found.';
$tableEmptyActionHtml = $tableEmptyActionHtml ?? '';
$tableHasRows = (bool)($tableHasRows ?? false);
?>
<div class="card ui-table-shell">
  <?php if ($tableTitle !== '' || $tableMeta !== '' || $tableActionsHtml !== ''): ?>
    <?php ui_section_head($tableTitle, $tableMeta, $tableActionsHtml); ?>
  <?php endif; ?>
  <?php if ($tableHasRows): ?>
    <?= $tableHtml ?>
  <?php else: ?>
    <?php ui_empty_state($tableEmptyMessage, $tableEmptyActionHtml, '∅'); ?>
  <?php endif; ?>
</div>
