<?php
require_once __DIR__.'/../init.php';
require_manager();

$status = trim((string)getv('status', 'all'));
$date_from = trim((string)getv('date_from', ''));
$date_to = trim((string)getv('date_to', ''));
$employee_id = (int)getv('employee_id', 0);
$q = trim((string)getv('q', ''));
$where = 'WHERE 1';
if ($status !== 'all' && in_array($status, ['pending','approved','needs_changes'], true)) $where .= " AND COALESCE(NULLIF(r.status,''),'pending')='" . $mysqli->real_escape_string($status) . "'";
if ($date_from !== '') $where .= " AND DATE(r.visit_datetime) >= '" . $mysqli->real_escape_string($date_from) . "'";
if ($date_to !== '') $where .= " AND DATE(r.visit_datetime) <= '" . $mysqli->real_escape_string($date_to) . "'";
if ($employee_id > 0) $where .= ' AND r.user_id=' . $employee_id;
if ($q !== '') { $like = '%' . $mysqli->real_escape_string($q) . '%'; $where .= " AND (u.name LIKE '{$like}' OR r.doctor_name LIKE '{$like}' OR r.hospital_name LIKE '{$like}' OR r.medicine_name LIKE '{$like}')"; }
$sql = "SELECT r.id,u.name employee,r.doctor_name,r.doctor_email,r.purpose,r.medicine_name,r.hospital_name,r.visit_datetime,COALESCE(NULLIF(r.status,''),'pending') status,r.manager_comment,r.created_at FROM reports r JOIN users u ON u.id=r.user_id {$where} ORDER BY r.visit_datetime DESC, r.id DESC";
if(isset($_GET['download'])){
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="reports_export_' . date('Ymd_His') . '.csv"');
  $out=fopen('php://output','w');
  fputcsv($out,['ID','Employee','Doctor','Email','Purpose','Medicine','Hospital','Visit','Status','Manager Comment','Created At']);
  $res=$mysqli->query($sql);
  while($r=$res->fetch_assoc()){ fputcsv($out,$r); }
  fclose($out);
  exit;
}
$users=$mysqli->query('SELECT id,name FROM users ORDER BY name');
$title='Export Center'; include __DIR__.'/header.php';
?>
<div class="crm-hero"><div><h2>Export Center</h2><div class="subtle">Download filtered CSV extracts for reporting and management review.</div></div></div>
<div class="card">
  <form class="filters" method="get">
    <label>Employee
      <select name="employee_id">
        <option value="0">All</option>
        <?php while($u=$users->fetch_assoc()): ?><option value="<?= (int)$u['id'] ?>" <?= $employee_id===(int)$u['id']?'selected':'' ?>><?= e($u['name']) ?></option><?php endwhile; ?>
      </select>
    </label>
    <label>Date From<input type="date" name="date_from" value="<?= e($date_from) ?>"></label>
    <label>Date To<input type="date" name="date_to" value="<?= e($date_to) ?>"></label>
    <label>Status
      <select name="status">
        <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
        <option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option>
        <option value="approved" <?= $status==='approved'?'selected':'' ?>>Approved</option>
        <option value="needs_changes" <?= $status==='needs_changes'?'selected':'' ?>>Needs Changes</option>
      </select>
    </label>
    <label>Search<input type="text" name="q" value="<?= e($q) ?>" placeholder="Rep, doctor, hospital, medicine"></label>
    <div class="actions-inline">
      <button class="btn">Preview Filter</button>
      <button class="btn primary" type="submit" name="download" value="1">Download CSV</button>
      <a class="btn" href="exports.php">Reset</a>
    </div>
  </form>
  <p class="subtle">CSV columns include employee, doctor, product, hospital, visit date, workflow status, and manager comments.</p>
</div>
<?php include __DIR__.'/footer.php'; ?>
