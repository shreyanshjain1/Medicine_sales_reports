<?php
require_once __DIR__.'/../init.php';
require_login();
if (!is_manager() && !is_district_manager()) { http_response_code(403); exit('Forbidden'); }

$status = trim((string)getv('status', 'pending'));
$dateFrom = trim((string)getv('date_from', ''));
$dateTo = trim((string)getv('date_to', ''));
$q = trim((string)getv('q', ''));
$page = max(1, (int)getv('page', 1));
[$page,$pages,$off,$per] = paginate(0, 15, $page);

$where = 'WHERE ' . reports_scope_where('r');
if ($status !== 'all') {
  $allowed = ['pending','approved','needs_changes'];
  if (!in_array($status, $allowed, true)) $status = 'pending';
  $where .= " AND COALESCE(NULLIF(r.status,''),'pending')='" . $mysqli->real_escape_string($status) . "'";
}
if ($dateFrom !== '') $where .= " AND DATE(r.visit_datetime) >= '" . $mysqli->real_escape_string($dateFrom) . "'";
if ($dateTo !== '') $where .= " AND DATE(r.visit_datetime) <= '" . $mysqli->real_escape_string($dateTo) . "'";
if ($q !== '') {
  $like = '%' . $mysqli->real_escape_string($q) . '%';
  $where .= " AND (u.name LIKE '{$like}' OR r.doctor_name LIKE '{$like}' OR r.medicine_name LIKE '{$like}' OR r.hospital_name LIKE '{$like}')";
}
$countSql = "SELECT COUNT(*) c FROM reports r LEFT JOIN users u ON u.id=r.user_id {$where}";
$total = (int)($mysqli->query($countSql)->fetch_assoc()['c'] ?? 0);
[$page,$pages,$off,$per] = paginate($total, 15, $page);
$sql = "SELECT r.id, r.doctor_name, r.medicine_name, r.hospital_name, r.visit_datetime, COALESCE(NULLIF(r.status,''),'pending') AS status, u.name AS employee
        FROM reports r
        LEFT JOIN users u ON u.id=r.user_id
        {$where}
        ORDER BY CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' THEN 0 WHEN COALESCE(NULLIF(r.status,''),'pending')='needs_changes' THEN 1 ELSE 2 END,
                 IFNULL(r.visit_datetime, r.created_at) DESC, r.id DESC
        LIMIT {$off},{$per}";
$rows = [];
$res = $mysqli->query($sql);
if ($res) while($row = $res->fetch_assoc()) $rows[] = $row;
$slaSummary = fetch_approval_sla_summary();
$title='Approvals'; include __DIR__.'/header.php';
?>
<div class="crm-hero">
  <div>
    <h2>Approval Queue</h2>
    <div class="subtle">Fast review view for pending and returned reports.</div>
  </div>
  <div class="actions-inline"><div class="pill neutral"><?= (int)$total ?> result<?= $total===1?'':'s' ?></div><a class="btn" href="approval_sla.php">SLA View</a></div>
</div>
<div class="summary-grid summary-grid-dashboard approvals-summary-grid">
  <div class="card summary-card"><div class="summary-label">Pending</div><div class="summary-value"><?= (int)$slaSummary['pending_total'] ?></div></div>
  <div class="card summary-card"><div class="summary-label">Needs Changes</div><div class="summary-value"><?= (int)$slaSummary['needs_changes_total'] ?></div></div>
  <div class="card summary-card"><div class="summary-label">24h+ Aging</div><div class="summary-value warning-text"><?= (int)$slaSummary['aging_warning'] ?></div></div>
  <div class="card summary-card"><div class="summary-label">Overdue</div><div class="summary-value danger-text"><?= (int)$slaSummary['overdue_total'] ?></div></div>
</div>
<div class="card">
  <form class="filters" method="get">
    <label>Status
      <select name="status">
        <option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option>
        <option value="needs_changes" <?= $status==='needs_changes'?'selected':'' ?>>Needs Changes</option>
        <option value="approved" <?= $status==='approved'?'selected':'' ?>>Approved</option>
        <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
      </select>
    </label>
    <label>Date From<input type="date" name="date_from" value="<?= e($dateFrom) ?>"></label>
    <label>Date To<input type="date" name="date_to" value="<?= e($dateTo) ?>"></label>
    <label>Search<input type="text" name="q" value="<?= e($q) ?>" placeholder="Rep, doctor, hospital, medicine"></label>
    <div class="actions-inline">
      <button class="btn primary">Apply</button>
      <a class="btn" href="approvals.php">Reset</a>
    </div>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Employee</th><th>Doctor</th><th>Medicine</th><th>Hospital</th><th>Visit</th><th>Status</th><th>Action</th></tr></thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="7" class="muted">No reports found for this queue.</td></tr>
      <?php else: foreach($rows as $row): ?>
        <tr>
          <td><?= e($row['employee'] ?? '—') ?></td>
          <td><?= e($row['doctor_name']) ?></td>
          <td><?= e($row['medicine_name']) ?></td>
          <td><?= e($row['hospital_name']) ?></td>
          <td><?= e((string)$row['visit_datetime']) ?></td>
          <td><span class="badge <?= e($row['status']) ?>"><?= e($row['status']) ?></span></td>
          <td><a class="btn tiny primary" href="report_view.php?id=<?= (int)$row['id'] ?>">Review</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if($pages>1): ?><div class="pagination"><?php for($i=1;$i<=$pages;$i++): $qp=$_GET; $qp['page']=$i; ?><a class="page <?= $i===$page?'active':'' ?>" href="?<?= http_build_query($qp) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
</div>
<?php include __DIR__.'/footer.php'; ?>
