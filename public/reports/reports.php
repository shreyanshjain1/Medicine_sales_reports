<?php
require_once __DIR__.'/../../init.php';
require_login();
$roleRaw = (string)(user()['role'] ?? 'employee');
$role = strtolower(trim($roleRaw));
$isManager = in_array($role, ['manager','admin'], true);
$isDistrict = ($role === 'district_manager');
$employee_id = (int)getv('employee_id', 0);
$date_from = trim((string)getv('date_from', ''));
$date_to = trim((string)getv('date_to', ''));
$q = trim((string)getv('q', ''));
$status = trim((string)getv('status', 'all'));
$doctor = trim((string)getv('doctor', ''));
$medicine = trim((string)getv('medicine', ''));
$hospital = trim((string)getv('hospital', ''));
$page = max(1, (int)getv('page', 1));

$where = 'WHERE ' . reports_scope_where('r');
if ($employee_id) {
  if ($isManager || ($isDistrict && can_view_user_reports($employee_id))) $where .= ' AND r.user_id=' . (int)$employee_id;
}
if ($date_from !== '') $where .= " AND DATE(r.visit_datetime) >= '" . $mysqli->real_escape_string($date_from) . "'";
if ($date_to !== '') $where .= " AND DATE(r.visit_datetime) <= '" . $mysqli->real_escape_string($date_to) . "'";
if ($status !== 'all' && in_array($status, ['pending','approved','needs_changes'], true)) $where .= " AND COALESCE(NULLIF(r.status,''),'pending')='" . $mysqli->real_escape_string($status) . "'";
if ($doctor !== '') $where .= " AND r.doctor_name LIKE '%" . $mysqli->real_escape_string($doctor) . "%'";
if ($medicine !== '') $where .= " AND r.medicine_name LIKE '%" . $mysqli->real_escape_string($medicine) . "%'";
if ($hospital !== '') $where .= " AND r.hospital_name LIKE '%" . $mysqli->real_escape_string($hospital) . "%'";
if ($q !== '') {
  $like = '%' . $mysqli->real_escape_string($q) . '%';
  $where .= " AND (r.doctor_name LIKE '{$like}' OR r.hospital_name LIKE '{$like}' OR r.medicine_name LIKE '{$like}' OR u.name LIKE '{$like}' OR r.purpose LIKE '{$like}')";
}

$countSql = "SELECT COUNT(*) c FROM reports r LEFT JOIN users u ON u.id = r.user_id {$where}";
$total = (int)($mysqli->query($countSql)->fetch_assoc()['c'] ?? 0);
[$page,$pages,$off,$per] = paginate($total, 12, $page);
$listSql = "SELECT r.*, u.name AS employee FROM reports r LEFT JOIN users u ON u.id=r.user_id {$where} ORDER BY IFNULL(r.visit_datetime, r.created_at) DESC, r.id DESC LIMIT {$off},{$per}";
$rows=[]; $res=$mysqli->query($listSql); if($res) while($x=$res->fetch_assoc()) $rows[]=$x;
$statsRes = $mysqli->query("SELECT 
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' THEN 1 ELSE 0 END) pending_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN 1 ELSE 0 END) approved_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='needs_changes' THEN 1 ELSE 0 END) needs_changes_count,
  COUNT(DISTINCT NULLIF(TRIM(r.doctor_name), '')) doctors_count,
  COUNT(DISTINCT NULLIF(TRIM(r.hospital_name), '')) hospitals_count,
  COUNT(DISTINCT NULLIF(TRIM(r.medicine_name), '')) medicines_count
  FROM reports r LEFT JOIN users u ON u.id=r.user_id {$where}");
$stats = $statsRes ? ($statsRes->fetch_assoc() ?: []) : [];
if ($isManager) $usersRes = $mysqli->query("SELECT id,name FROM users ORDER BY name");
elseif ($isDistrict) { $dmId=(int)user()['id']; $usersRes=$mysqli->query("SELECT id,name FROM users WHERE id={$dmId} OR district_manager_id={$dmId} ORDER BY name"); }
else $usersRes = null;
$title='Reports'; include __DIR__.'/../header.php';
?>
<div class="crm-hero">
  <div>
    <h2>Reports</h2>
    <div class="subtle">Filter, review, and manage field submissions in one CRM-style workspace.</div>
  </div>
  <div class="actions-inline">
    <?php if($isManager || $isDistrict): ?><a class="btn" href="<?= url('admin/approvals.php') ?>">Open Approval Queue</a><?php endif; ?>
    <a class="btn primary" href="<?= url('reports/report_add.php') ?>">Create Report</a>
  </div>
</div>
<div class="kpi-strip">
  <div class="metric"><div class="label">Total results</div><div class="value"><?= (int)$total ?></div><div class="hint">Current filtered dataset</div></div>
  <div class="metric"><div class="label">Pending</div><div class="value"><?= (int)($stats['pending_count'] ?? 0) ?></div><div class="hint">Awaiting review</div></div>
  <div class="metric"><div class="label">Doctors</div><div class="value"><?= (int)($stats['doctors_count'] ?? 0) ?></div><div class="hint">Unique doctors</div></div>
  <div class="metric"><div class="label">Hospitals</div><div class="value"><?= (int)($stats['hospitals_count'] ?? 0) ?></div><div class="hint">Covered accounts</div></div>
  <div class="metric"><div class="label">Medicines</div><div class="value"><?= (int)($stats['medicines_count'] ?? 0) ?></div><div class="hint">Products mentioned</div></div>
  <div class="metric"><div class="label">Needs changes</div><div class="value"><?= (int)($stats['needs_changes_count'] ?? 0) ?></div><div class="hint">Returned to reps</div></div>
</div>
<div class="card">
  <form class="filters filters-6" method="get">
    <?php if($isManager || $isDistrict): ?>
    <label>Employee
      <select name="employee_id">
        <option value="0">All</option>
        <?php if($usersRes) while($u=$usersRes->fetch_assoc()): ?><option value="<?= (int)$u['id'] ?>" <?= $employee_id===(int)$u['id']?'selected':'' ?>><?= e($u['name']) ?></option><?php endwhile; ?>
      </select>
    </label>
    <?php endif; ?>
    <label>Status
      <select name="status">
        <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
        <option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option>
        <option value="approved" <?= $status==='approved'?'selected':'' ?>>Approved</option>
        <option value="needs_changes" <?= $status==='needs_changes'?'selected':'' ?>>Needs Changes</option>
      </select>
    </label>
    <label>Date From<input type="date" name="date_from" value="<?= e($date_from) ?>"></label>
    <label>Date To<input type="date" name="date_to" value="<?= e($date_to) ?>"></label>
    <label>Doctor<input type="text" name="doctor" value="<?= e($doctor) ?>" placeholder="Doctor name"></label>
    <label>Medicine<input type="text" name="medicine" value="<?= e($medicine) ?>" placeholder="Medicine name"></label>
    <label>Hospital<input type="text" name="hospital" value="<?= e($hospital) ?>" placeholder="Hospital / clinic"></label>
    <label class="span-2">Keyword Search<input name="q" value="<?= e($q) ?>" placeholder="Rep, doctor, hospital, medicine, purpose"></label>
    <div class="actions-inline span-2">
      <button class="btn primary">Apply</button>
      <a class="btn" href="<?= url('reports/reports.php') ?>">Reset</a>
      <?php if($isManager): ?><a class="btn" href="exports.php?<?= e(http_build_query($_GET)) ?>">Export Filtered CSV</a><?php endif; ?>
    </div>
  </form>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><?php if($isManager || $isDistrict): ?><th>Employee</th><?php endif; ?><th>Doctor</th><th>Purpose</th><th>Medicine</th><th>Hospital</th><th>Visit</th><th>Status</th><th>Actions</th></tr></thead>
      <tbody>
      <?php $colspan = ($isManager || $isDistrict) ? 8 : 7; ?>
      <?php if(!count($rows)): ?><tr><td colspan="<?= $colspan ?>" class="muted">No reports found.</td></tr><?php else: foreach($rows as $r): ?>
        <tr>
          <?php if($isManager || $isDistrict): ?><td><?= e($r['employee'] ?: '—') ?></td><?php endif; ?>
          <td><strong><?= e($r['doctor_name']) ?></strong><br><small class="muted"><?= e($r['doctor_email']) ?></small></td>
          <td><?= e($r['purpose']) ?></td>
          <td><?= e($r['medicine_name']) ?></td>
          <td><?= e($r['hospital_name']) ?></td>
          <td><?= $r['visit_datetime'] ? e(date('Y-m-d H:i', strtotime($r['visit_datetime']))) : '—' ?></td>
          <td><span class="badge <?= e($r['status'] ?: 'pending') ?>"><?= e($r['status'] ?: 'pending') ?></span></td>
          <td><div class="actions-inline"><a class="btn tiny" href="report_view.php?id=<?= (int)$r['id'] ?>">View</a><?php if(!is_manager() && ($r['status']??'')!=='approved'): ?><a class="btn tiny" href="report_edit.php?id=<?= (int)$r['id'] ?>">Edit</a><?php endif; ?></div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if($pages>1): ?><div class="pagination"><?php for($i=1;$i<=$pages;$i++): $qp=$_GET; $qp['page']=$i; ?><a class="page <?= $i===$page?'active':'' ?>" href="?<?= http_build_query($qp) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
</div>
<?php include __DIR__.'/../footer.php'; ?>
