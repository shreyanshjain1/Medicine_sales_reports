<?php
require_once __DIR__.'/../../init.php';
require_login();
if (!is_manager() && !is_district_manager()) { http_response_code(403); exit('Forbidden'); }
$title='Approval SLA';
$sla = fetch_approval_sla_summary();
$buckets = fetch_approval_aging_buckets();
$overdue = fetch_overdue_reports(12);
$backlog = fetch_reviewer_backlog(12);
include __DIR__.'/../header.php';
?>
<div class="crm-hero">
  <div>
    <h2>Approval SLA & Aging</h2>
    <div class="subtle">Track what is overdue, who has backlog, and which approvals need attention first.</div>
  </div>
  <div class="actions-inline">
    <a class="btn" href="<?= route_url('admin/approvals.php', ['status'=>'pending']) ?>">Open Pending Queue</a>
    <a class="btn primary" href="<?= route_url('admin/approvals.php', ['status'=>'needs_changes']) ?>">Needs Changes</a>
  </div>
</div>
<div class="summary-grid summary-grid-dashboard">
  <div class="card summary-card"><div class="summary-label">Pending</div><div class="summary-value"><?= (int)$sla['pending_total'] ?></div></div>
  <div class="card summary-card"><div class="summary-label">Overdue</div><div class="summary-value danger-text"><?= (int)$sla['overdue_total'] ?></div></div>
  <div class="card summary-card"><div class="summary-label">24h+ Aging</div><div class="summary-value warning-text"><?= (int)$sla['aging_warning'] ?></div></div>
  <div class="card summary-card"><div class="summary-label">Avg Hours To Approve</div><div class="summary-value"><?= e((string)$sla['avg_hours_to_approve']) ?></div></div>
</div>
<div class="grid two">
  <div class="card">
    <div class="flex-between"><h2 class="titlecase">Pending Aging Buckets</h2><span class="pill neutral">Live</span></div>
    <div class="sla-buckets">
      <?php foreach($buckets as $b): ?>
        <div class="sla-bucket <?= e($b['tone']) ?>">
          <div class="sla-label"><?= e($b['label']) ?></div>
          <div class="sla-count"><?= (int)$b['count'] ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="card">
    <div class="flex-between"><h2 class="titlecase">Rep Backlog Snapshot</h2><span class="muted small">Pending + returned work</span></div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Rep</th><th>Pending</th><th>Overdue</th></tr></thead>
        <tbody>
        <?php if(!$backlog): ?>
          <tr><td colspan="3" class="muted">No backlog data available.</td></tr>
        <?php else: foreach($backlog as $row): ?>
          <tr>
            <td><?= e($row['employee']) ?></td>
            <td><?= (int)$row['pending_count'] ?></td>
            <td><span class="pill <?= ((int)$row['overdue_count']>0)?'danger':'neutral' ?>"><?= (int)$row['overdue_count'] ?></span></td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<div class="card">
  <div class="flex-between"><h2 class="titlecase">Overdue Approval Follow-ups</h2><span class="muted small">Oldest pending items first</span></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Rep</th><th>Doctor</th><th>Medicine</th><th>Hospital</th><th>Visit</th><th>Age</th><th>Action</th></tr></thead>
      <tbody>
      <?php if(!$overdue): ?>
        <tr><td colspan="7" class="muted">No overdue approvals right now.</td></tr>
      <?php else: foreach($overdue as $row): ?>
        <tr>
          <td><?= e($row['employee']) ?></td>
          <td><?= e($row['doctor_name']) ?></td>
          <td><?= e($row['medicine_name']) ?></td>
          <td><?= e($row['hospital_name']) ?></td>
          <td><?= e((string)$row['visit_datetime']) ?></td>
          <td><span class="pill danger"><?= (int)$row['age_hours'] ?>h</span></td>
          <td><a class="btn tiny primary" href="<?= route_url('reports/report_view.php', ['id'=>(int)$row['id']]) ?>">Review</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__.'/../footer.php'; ?>
