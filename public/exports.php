<?php
require_once __DIR__.'/../init.php';
require_manager();

$status = trim((string)getv('status', 'all'));
$date_from = trim((string)getv('date_from', ''));
$date_to = trim((string)getv('date_to', ''));
$employee_id = (int)getv('employee_id', 0);
$doctor = trim((string)getv('doctor', ''));
$medicine = trim((string)getv('medicine', ''));
$hospital = trim((string)getv('hospital', ''));
$q = trim((string)getv('q', ''));

$where = 'WHERE 1';
if ($status !== 'all' && in_array($status, ['pending','approved','needs_changes'], true)) {
  $where .= " AND COALESCE(NULLIF(r.status,''),'pending')='" . $mysqli->real_escape_string($status) . "'";
}
if ($date_from !== '') $where .= " AND DATE(r.visit_datetime) >= '" . $mysqli->real_escape_string($date_from) . "'";
if ($date_to !== '') $where .= " AND DATE(r.visit_datetime) <= '" . $mysqli->real_escape_string($date_to) . "'";
if ($employee_id > 0) $where .= ' AND r.user_id=' . $employee_id;
if ($doctor !== '') {
  $where .= " AND r.doctor_name LIKE '%" . $mysqli->real_escape_string($doctor) . "%'";
}
if ($medicine !== '') {
  $where .= " AND r.medicine_name LIKE '%" . $mysqli->real_escape_string($medicine) . "%'";
}
if ($hospital !== '') {
  $where .= " AND r.hospital_name LIKE '%" . $mysqli->real_escape_string($hospital) . "%'";
}
if ($q !== '') {
  $like = '%' . $mysqli->real_escape_string($q) . '%';
  $where .= " AND (u.name LIKE '{$like}' OR r.doctor_name LIKE '{$like}' OR r.hospital_name LIKE '{$like}' OR r.medicine_name LIKE '{$like}' OR r.purpose LIKE '{$like}')";
}

$sql = "SELECT r.id,u.name employee,r.doctor_name,r.doctor_email,r.purpose,r.medicine_name,r.hospital_name,r.visit_datetime,COALESCE(NULLIF(r.status,''),'pending') status,r.manager_comment,r.created_at FROM reports r JOIN users u ON u.id=r.user_id {$where} ORDER BY r.visit_datetime DESC, r.id DESC";

$statsSql = "SELECT 
  COUNT(*) total_rows,
  COUNT(DISTINCT r.user_id) reps_count,
  COUNT(DISTINCT NULLIF(TRIM(r.doctor_name), '')) doctors_count,
  COUNT(DISTINCT NULLIF(TRIM(r.hospital_name), '')) hospitals_count,
  COUNT(DISTINCT NULLIF(TRIM(r.medicine_name), '')) medicines_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' THEN 1 ELSE 0 END) pending_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN 1 ELSE 0 END) approved_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='needs_changes' THEN 1 ELSE 0 END) needs_changes_count
  FROM reports r JOIN users u ON u.id=r.user_id {$where}";
$stats = $mysqli->query($statsSql);
$stats = $stats ? ($stats->fetch_assoc() ?: []) : [];

if (isset($_GET['download'])) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="reports_export_' . date('Ymd_His') . '.csv"');
  $out = fopen('php://output', 'w');
  fputcsv($out, ['ID','Employee','Doctor','Email','Purpose','Medicine','Hospital','Visit','Status','Manager Comment','Created At']);
  $res = $mysqli->query($sql);
  while ($res && ($r = $res->fetch_assoc())) {
    fputcsv($out, $r);
  }
  fclose($out);
  exit;
}

$previewSql = $sql . ' LIMIT 0,25';
$previewRows = [];
$previewRes = $mysqli->query($previewSql);
if ($previewRes) while ($x = $previewRes->fetch_assoc()) $previewRows[] = $x;

$users = $mysqli->query('SELECT id,name FROM users ORDER BY name');
$title = 'Export Center';
include __DIR__.'/header.php';
?>
<div class="crm-hero">
  <div>
    <h2>Export Center</h2>
    <div class="subtle">Build cleaner exports using report status, rep, doctor, medicine, hospital, and date filters.</div>
  </div>
  <a class="btn" href="reports.php?<?= e(http_build_query(array_filter([
    'employee_id' => $employee_id ?: null,
    'date_from' => $date_from ?: null,
    'date_to' => $date_to ?: null,
    'status' => $status !== 'all' ? $status : null,
    'doctor' => $doctor ?: null,
    'medicine' => $medicine ?: null,
    'hospital' => $hospital ?: null,
    'q' => $q ?: null,
  ], static fn($v) => $v !== null && $v !== ''))) ?>">Open in Reports</a>
</div>

<div class="kpi-strip compact-gap">
  <div class="metric"><div class="label">Rows</div><div class="value"><?= (int)($stats['total_rows'] ?? 0) ?></div><div class="hint">Current export size</div></div>
  <div class="metric"><div class="label">Reps</div><div class="value"><?= (int)($stats['reps_count'] ?? 0) ?></div><div class="hint">Unique employees</div></div>
  <div class="metric"><div class="label">Doctors</div><div class="value"><?= (int)($stats['doctors_count'] ?? 0) ?></div><div class="hint">Covered doctors</div></div>
  <div class="metric"><div class="label">Hospitals</div><div class="value"><?= (int)($stats['hospitals_count'] ?? 0) ?></div><div class="hint">Covered accounts</div></div>
  <div class="metric"><div class="label">Medicines</div><div class="value"><?= (int)($stats['medicines_count'] ?? 0) ?></div><div class="hint">Unique products</div></div>
</div>

<div class="card">
  <form class="filters filters-6" method="get">
    <label>Employee
      <select name="employee_id">
        <option value="0">All</option>
        <?php while($u = $users->fetch_assoc()): ?>
          <option value="<?= (int)$u['id'] ?>" <?= $employee_id === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endwhile; ?>
      </select>
    </label>
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
    <label class="span-2">Keyword Search<input type="text" name="q" value="<?= e($q) ?>" placeholder="Rep, doctor, hospital, medicine, purpose"></label>
    <div class="actions-inline span-2">
      <button class="btn">Preview Filter</button>
      <button class="btn primary" type="submit" name="download" value="1">Download CSV</button>
      <a class="btn" href="exports.php">Reset</a>
    </div>
  </form>
</div>

<div class="card">
  <div class="card-head split">
    <div>
      <h3>Preview</h3>
      <div class="subtle">Showing the first 25 matching rows before export.</div>
    </div>
    <span class="badge info"><?= (int)count($previewRows) ?> rows shown</span>
  </div>
  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <th>Employee</th>
          <th>Doctor</th>
          <th>Purpose</th>
          <th>Medicine</th>
          <th>Hospital</th>
          <th>Visit</th>
          <th>Status</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$previewRows): ?>
          <tr><td colspan="7" class="muted">No rows match the selected filters.</td></tr>
        <?php else: ?>
          <?php foreach ($previewRows as $r): ?>
            <tr>
              <td><?= e($r['employee'] ?: '—') ?></td>
              <td><strong><?= e($r['doctor_name']) ?></strong><br><small class="muted"><?= e($r['doctor_email']) ?></small></td>
              <td><?= e($r['purpose']) ?></td>
              <td><?= e($r['medicine_name']) ?></td>
              <td><?= e($r['hospital_name']) ?></td>
              <td><?= $r['visit_datetime'] ? e(date('Y-m-d H:i', strtotime($r['visit_datetime']))) : '—' ?></td>
              <td><span class="badge <?= e($r['status'] ?: 'pending') ?>"><?= e($r['status'] ?: 'pending') ?></span></td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__.'/footer.php'; ?>
