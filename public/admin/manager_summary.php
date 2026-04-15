<?php
require_once __DIR__.'/../../init.php';
require_manager();

$title = 'Manager Summary';
$range = trim((string)getv('range', 'month'));
$employee_id = (int)getv('employee_id', 0);
$status = trim((string)getv('status', 'all'));
$printMode = isset($_GET['print']);

if (!function_exists('summary_default_dates')) {
  function summary_default_dates(string $range): array {
    $today = date('Y-m-d');
    switch ($range) {
      case 'today':
        return [$today, $today];
      case 'week':
        return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))];
      case 'month':
      default:
        return [date('Y-m-01'), date('Y-m-t')];
    }
  }
}

[$defaultFrom, $defaultTo] = summary_default_dates($range);
$date_from = trim((string)getv('date_from', $defaultFrom));
$date_to = trim((string)getv('date_to', $defaultTo));

$scope = reports_scope_where('r');
$where = "WHERE {$scope}";
if ($date_from !== '') $where .= " AND DATE(r.visit_datetime) >= '" . $mysqli->real_escape_string($date_from) . "'";
if ($date_to !== '') $where .= " AND DATE(r.visit_datetime) <= '" . $mysqli->real_escape_string($date_to) . "'";
if ($employee_id > 0) $where .= ' AND r.user_id=' . $employee_id;
if ($status !== 'all' && in_array($status, ['pending','approved','needs_changes'], true)) {
  $where .= " AND COALESCE(NULLIF(r.status,''),'pending')='" . $mysqli->real_escape_string($status) . "'";
}

$summarySql = "SELECT 
  COUNT(*) total_reports,
  COUNT(DISTINCT r.user_id) reps_count,
  COUNT(DISTINCT NULLIF(TRIM(r.doctor_name), '')) doctors_count,
  COUNT(DISTINCT NULLIF(TRIM(r.hospital_name), '')) hospitals_count,
  COUNT(DISTINCT NULLIF(TRIM(r.medicine_name), '')) medicines_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN 1 ELSE 0 END) approved_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' THEN 1 ELSE 0 END) pending_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='needs_changes' THEN 1 ELSE 0 END) needs_changes_count,
  MIN(DATE(r.visit_datetime)) first_visit,
  MAX(DATE(r.visit_datetime)) last_visit
  FROM reports r {$where}";
$summary = $mysqli->query($summarySql);
$summary = $summary ? ($summary->fetch_assoc() ?: []) : [];

$repRows = [];
$repSql = "SELECT u.name,
  COUNT(*) total_reports,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN 1 ELSE 0 END) approved_count,
  COUNT(DISTINCT NULLIF(TRIM(r.doctor_name), '')) doctors_count,
  COUNT(DISTINCT NULLIF(TRIM(r.hospital_name), '')) hospitals_count,
  COUNT(DISTINCT NULLIF(TRIM(r.medicine_name), '')) medicines_count
  FROM reports r
  JOIN users u ON u.id=r.user_id
  {$where}
  GROUP BY u.id,u.name
  ORDER BY total_reports DESC, approved_count DESC, u.name ASC
  LIMIT 8";
if ($res = $mysqli->query($repSql)) while ($x = $res->fetch_assoc()) $repRows[] = $x;

$medicineRows = [];
$medicineSql = "SELECT r.medicine_name label, COUNT(*) total_reports, COUNT(DISTINCT r.user_id) reps_count
  FROM reports r
  {$where} AND NULLIF(TRIM(r.medicine_name), '') IS NOT NULL
  GROUP BY r.medicine_name
  ORDER BY total_reports DESC, label ASC
  LIMIT 8";
if ($res = $mysqli->query($medicineSql)) while ($x = $res->fetch_assoc()) $medicineRows[] = $x;

$hospitalRows = [];
$hospitalSql = "SELECT r.hospital_name label, COUNT(*) total_reports, COUNT(DISTINCT NULLIF(TRIM(r.doctor_name), '')) doctors_count
  FROM reports r
  {$where} AND NULLIF(TRIM(r.hospital_name), '') IS NOT NULL
  GROUP BY r.hospital_name
  ORDER BY total_reports DESC, label ASC
  LIMIT 8";
if ($res = $mysqli->query($hospitalSql)) while ($x = $res->fetch_assoc()) $hospitalRows[] = $x;

$timelineRows = [];
$timelineSql = "SELECT DATE(r.visit_datetime) day_key,
  COUNT(*) total_reports,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN 1 ELSE 0 END) approved_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' THEN 1 ELSE 0 END) pending_count
  FROM reports r
  {$where}
  GROUP BY DATE(r.visit_datetime)
  ORDER BY day_key DESC
  LIMIT 10";
if ($res = $mysqli->query($timelineSql)) while ($x = $res->fetch_assoc()) $timelineRows[] = $x;

$users = [];
if ($res = $mysqli->query("SELECT id,name FROM users WHERE active=1 ORDER BY name ASC")) while ($x = $res->fetch_assoc()) $users[] = $x;

$shareQuery = http_build_query(array_filter([
  'range' => $range ?: null,
  'date_from' => $date_from ?: null,
  'date_to' => $date_to ?: null,
  'employee_id' => $employee_id ?: null,
  'status' => $status !== 'all' ? $status : null,
], static fn($v) => $v !== null && $v !== ''));

$periodLabel = ($summary['first_visit'] ?? '') && ($summary['last_visit'] ?? '')
  ? e($summary['first_visit']) . ' to ' . e($summary['last_visit'])
  : e($date_from) . ' to ' . e($date_to);

include __DIR__.'/../header.php';
?>
<div class="page-head <?= $printMode ? 'print-hide' : '' ?>">
  <div>
    <h2>Manager Summary</h2>
    <p class="muted">Printable management snapshot for activity, coverage, approvals, and territory focus.</p>
  </div>
  <div class="actions-inline">
    <a class="btn" href="digest_builder.php?<?= e($shareQuery) ?>">Open Digest Builder</a>
    <a class="btn" href="exports.php?<?= e($shareQuery) ?>">Open Export Center</a>
    <a class="btn primary" href="manager_summary.php?<?= e($shareQuery) ?>&print=1" target="_blank">Print View</a>
  </div>
</div>

<?php if (!$printMode): ?>
<div class="card">
  <form class="filters filters-6" method="get">
    <label>Preset Range
      <select name="range">
        <option value="today" <?= $range==='today'?'selected':'' ?>>Today</option>
        <option value="week" <?= $range==='week'?'selected':'' ?>>This Week</option>
        <option value="month" <?= $range==='month'?'selected':'' ?>>This Month</option>
        <option value="custom" <?= $range==='custom'?'selected':'' ?>>Custom</option>
      </select>
    </label>
    <label>Date From<input type="date" name="date_from" value="<?= e($date_from) ?>"></label>
    <label>Date To<input type="date" name="date_to" value="<?= e($date_to) ?>"></label>
    <label>Employee
      <select name="employee_id">
        <option value="0">All</option>
        <?php foreach ($users as $u): ?>
          <option value="<?= (int)$u['id'] ?>" <?= $employee_id === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
        <?php endforeach; ?>
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
    <div class="actions-inline">
      <button class="btn primary">Refresh Summary</button>
      <a class="btn" href="<?= url('admin/manager_summary.php') ?>">Reset</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="print-sheet">
  <div class="card summary-banner">
    <div>
      <div class="summary-label">Reporting Period</div>
      <div class="summary-title"><?= $periodLabel ?></div>
      <div class="muted">Status filter: <strong><?= e($status === 'all' ? 'All' : $status) ?></strong></div>
    </div>
    <div class="summary-chip-row">
      <span class="pill-soft">Generated <?= e(date('Y-m-d H:i')) ?></span>
      <span class="pill-soft">Prepared by <?= e(user()['name'] ?? 'Manager') ?></span>
    </div>
  </div>

  <div class="summary-grid summary-grid-dashboard summary-grid-tight">
    <div class="card summary-card"><div class="summary-label">Total Reports</div><div class="summary-value"><?= (int)($summary['total_reports'] ?? 0) ?></div></div>
    <div class="card summary-card"><div class="summary-label">Approved</div><div class="summary-value"><?= (int)($summary['approved_count'] ?? 0) ?></div></div>
    <div class="card summary-card"><div class="summary-label">Pending</div><div class="summary-value"><?= (int)($summary['pending_count'] ?? 0) ?></div></div>
    <div class="card summary-card"><div class="summary-label">Needs Changes</div><div class="summary-value"><?= (int)($summary['needs_changes_count'] ?? 0) ?></div></div>
    <div class="card summary-card"><div class="summary-label">Doctors Covered</div><div class="summary-value"><?= (int)($summary['doctors_count'] ?? 0) ?></div></div>
    <div class="card summary-card"><div class="summary-label">Hospital Reach</div><div class="summary-value"><?= (int)($summary['hospitals_count'] ?? 0) ?></div></div>
  </div>

  <div class="grid summary-two-col">
    <div class="card">
      <div class="card-head split">
        <div>
          <h3>Rep Scoreboard</h3>
          <div class="subtle">Highest report activity for the selected period.</div>
        </div>
        <span class="badge info"><?= (int)count($repRows) ?> reps</span>
      </div>
      <div class="table-wrap">
        <table class="table">
          <thead><tr><th>Rep</th><th>Reports</th><th>Approved</th><th>Doctors</th><th>Hospitals</th><th>Medicines</th></tr></thead>
          <tbody>
          <?php if (!$repRows): ?>
            <tr><td colspan="6" class="muted">No activity found for this period.</td></tr>
          <?php else: foreach ($repRows as $row): ?>
            <tr>
              <td><strong><?= e($row['name']) ?></strong></td>
              <td><?= (int)$row['total_reports'] ?></td>
              <td><?= (int)$row['approved_count'] ?></td>
              <td><?= (int)$row['doctors_count'] ?></td>
              <td><?= (int)$row['hospitals_count'] ?></td>
              <td><?= (int)$row['medicines_count'] ?></td>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="card summary-stack">
      <div>
        <div class="card-head split">
          <div><h3>Top Medicines</h3><div class="subtle">Most discussed products in the selected window.</div></div>
        </div>
        <div class="mini-kpi-list">
          <?php if (!$medicineRows): ?>
            <div class="mini-kpi"><span>No medicine activity</span><strong>—</strong></div>
          <?php else: foreach ($medicineRows as $row): ?>
            <div class="mini-kpi"><span><?= e($row['label']) ?></span><strong><?= (int)$row['total_reports'] ?></strong></div>
          <?php endforeach; endif; ?>
        </div>
      </div>
      <div>
        <div class="card-head split">
          <div><h3>Top Hospitals</h3><div class="subtle">Highest report concentration by account.</div></div>
        </div>
        <div class="mini-kpi-list">
          <?php if (!$hospitalRows): ?>
            <div class="mini-kpi"><span>No hospital activity</span><strong>—</strong></div>
          <?php else: foreach ($hospitalRows as $row): ?>
            <div class="mini-kpi"><span><?= e($row['label']) ?></span><strong><?= (int)$row['total_reports'] ?></strong></div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <div class="card">
    <div class="card-head split">
      <div>
        <h3>Recent Activity Trend</h3>
        <div class="subtle">Day-by-day totals for manager briefing or email digest use.</div>
      </div>
      <span class="badge info"><?= (int)count($timelineRows) ?> days</span>
    </div>
    <div class="table-wrap">
      <table class="table">
        <thead><tr><th>Date</th><th>Total Reports</th><th>Approved</th><th>Pending</th></tr></thead>
        <tbody>
          <?php if (!$timelineRows): ?>
            <tr><td colspan="4" class="muted">No timeline rows available.</td></tr>
          <?php else: foreach ($timelineRows as $row): ?>
            <tr>
              <td><strong><?= e($row['day_key']) ?></strong></td>
              <td><?= (int)$row['total_reports'] ?></td>
              <td><?= (int)$row['approved_count'] ?></td>
              <td><?= (int)$row['pending_count'] ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__.'/../footer.php'; ?>
