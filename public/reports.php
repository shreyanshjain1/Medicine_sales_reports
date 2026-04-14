<?php
<<<<<<< HEAD
require_once __DIR__.'/../init.php';
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
$page = max(1, (int)getv('page', 1));
$where = 'WHERE ' . reports_scope_where('r');
if ($employee_id) {
  if ($isManager || ($isDistrict && can_view_user_reports($employee_id))) $where .= ' AND r.user_id=' . (int)$employee_id;
}
if ($date_from !== '') $where .= " AND DATE(r.visit_datetime) >= '" . $mysqli->real_escape_string($date_from) . "'";
if ($date_to !== '') $where .= " AND DATE(r.visit_datetime) <= '" . $mysqli->real_escape_string($date_to) . "'";
if ($status !== 'all' && in_array($status, ['pending','approved','needs_changes'], true)) $where .= " AND COALESCE(NULLIF(r.status,''),'pending')='" . $mysqli->real_escape_string($status) . "'";
if ($q !== '') {
  $like = '%' . $mysqli->real_escape_string($q) . '%';
  $where .= " AND (r.doctor_name LIKE '{$like}' OR r.hospital_name LIKE '{$like}' OR r.medicine_name LIKE '{$like}' OR u.name LIKE '{$like}')";
}
$countSql = "SELECT COUNT(*) c FROM reports r LEFT JOIN users u ON u.id = r.user_id {$where}";
$total = (int)($mysqli->query($countSql)->fetch_assoc()['c'] ?? 0);
[$page,$pages,$off,$per] = paginate($total, 12, $page);
$listSql = "SELECT r.*, u.name AS employee FROM reports r LEFT JOIN users u ON u.id=r.user_id {$where} ORDER BY IFNULL(r.visit_datetime, r.created_at) DESC, r.id DESC LIMIT {$off},{$per}";
$rows=[]; $res=$mysqli->query($listSql); if($res) while($x=$res->fetch_assoc()) $rows[]=$x;
$statsRes = $mysqli->query("SELECT 
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' THEN 1 ELSE 0 END) pending_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN 1 ELSE 0 END) approved_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='needs_changes' THEN 1 ELSE 0 END) needs_changes_count
  FROM reports r LEFT JOIN users u ON u.id=r.user_id {$where}");
$stats = $statsRes ? ($statsRes->fetch_assoc() ?: []) : [];
if ($isManager) $usersRes = $mysqli->query("SELECT id,name FROM users ORDER BY name");
elseif ($isDistrict) { $dmId=(int)user()['id']; $usersRes=$mysqli->query("SELECT id,name FROM users WHERE id={$dmId} OR district_manager_id={$dmId} ORDER BY name"); }
else $usersRes = null;
$title='Reports'; include __DIR__.'/header.php';
?>
<div class="crm-hero">
  <div>
    <h2>Reports</h2>
    <div class="subtle">Filter, review, and manage field submissions in one CRM-style workspace.</div>
  </div>
  <a class="btn primary" href="report_add.php">Create Report</a>
</div>
<div class="kpi-strip">
  <div class="metric"><div class="label">Total results</div><div class="value"><?= (int)$total ?></div><div class="hint">Current filtered dataset</div></div>
  <div class="metric"><div class="label">Pending</div><div class="value"><?= (int)($stats['pending_count'] ?? 0) ?></div><div class="hint">Awaiting review</div></div>
  <div class="metric"><div class="label">Approved</div><div class="value"><?= (int)($stats['approved_count'] ?? 0) ?></div><div class="hint">Manager-approved</div></div>
  <div class="metric"><div class="label">Needs changes</div><div class="value"><?= (int)($stats['needs_changes_count'] ?? 0) ?></div><div class="hint">Returned to rep</div></div>
</div>
<div class="card">
  <form class="filters" method="get">
    <?php if($isManager || $isDistrict): ?>
    <label>Employee
      <select name="employee_id">
        <option value="0">All</option>
        <?php if($usersRes) while($u=$usersRes->fetch_assoc()): ?><option value="<?= (int)$u['id'] ?>" <?= $employee_id===(int)$u['id']?'selected':'' ?>><?= e($u['name']) ?></option><?php endwhile; ?>
      </select>
    </label>
    <?php endif; ?>
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
    <label>Search<input name="q" value="<?= e($q) ?>" placeholder="Rep, doctor, hospital, medicine"></label>
    <div class="actions-inline">
      <button class="btn primary">Apply</button>
      <a class="btn" href="reports.php">Reset</a>
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
=======
require_once __DIR__.'/../init.php'; 
require_login();

/* ---- role normalize (FIX) ---- */
$roleRaw = (string)(user()['role'] ?? 'employee');
$role    = strtolower(trim($roleRaw));

$isManager  = in_array($role, ['manager','admin'], true);
$isDistrict = ($role === 'district_manager');

$employee_id = (int)($_GET['employee_id'] ?? 0);
$range       = $_GET['range']  ?? 'all';
$q           = trim($_GET['q'] ?? '');
$status      = $_GET['status'] ?? 'all';
$page        = max(1, (int)($_GET['page'] ?? 1));
$per         = 12;
$showAll     = $isManager && ((int)($_GET['showall'] ?? 0) === 1);
$debug       = ((int)($_GET['debug'] ?? 0) === 1);

function sqlesc($s){ global $mysqli; return "'".$mysqli->real_escape_string($s)."'"; }
function like_esc($s){ global $mysqli; return "'%".$mysqli->real_escape_string($s)."%'"; }

/* WHERE */
$where = "WHERE " . reports_scope_where('r');

// Optional filter by employee_id
// - manager: any user
// - district_manager: only within their scope
if (!$showAll && $employee_id) {
  if ($isManager) {
    $where .= " AND r.user_id = " . (int)$employee_id;
  } elseif ($isDistrict && can_view_user_reports((int)$employee_id)) {
    $where .= " AND r.user_id = " . (int)$employee_id;
  }
}

if (!$showAll) {
  if ($range==='today')  $where .= " AND DATE(r.visit_datetime)=CURDATE()";
  if ($range==='week')   $where .= " AND YEARWEEK(r.visit_datetime,1)=YEARWEEK(CURDATE(),1)";
  if ($range==='month')  $where .= " AND YEAR(r.visit_datetime)=YEAR(CURDATE()) AND MONTH(r.visit_datetime)=MONTH(CURDATE())";
}

if (!$showAll && $status!=='all') {
  $where .= " AND COALESCE(NULLIF(r.status,''),'pending') = " . sqlesc($status);
}

if (!$showAll && $q!=='') {
  $where .= " AND (r.doctor_name  LIKE " . like_esc($q) .
           " OR  r.hospital_name LIKE " . like_esc($q) .
           " OR  r.medicine_name LIKE " . like_esc($q) . ")";
}

/* COUNT + pagination */
$sqlCount = "SELECT COUNT(*) c FROM reports r $where";
$cntRes   = $mysqli->query($sqlCount);
$total    = (int)($cntRes ? $cntRes->fetch_assoc()['c'] : 0);

$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$off   = ($page-1) * $per;
$lim   = $per;

/* LIST SQL */
$sqlList = "SELECT r.*, u.name AS employee
            FROM reports r
            LEFT JOIN users u ON u.id = r.user_id
            $where
            ORDER BY IFNULL(r.visit_datetime, r.created_at) DESC, r.id DESC
            LIMIT $off,$lim";

/* MATERIALIZE rows into array */
$rows = [];
$res1 = $mysqli->query($sqlList);
$err1 = $mysqli->error;
if ($res1) { while($x=$res1->fetch_assoc()) $rows[] = $x; }

/* Optional preview data for debug */
$preview = array_slice($rows, 0, 5);

/* employees list */
if ($isManager) {
  $usersRes = $mysqli->query("SELECT id,name FROM users ORDER BY name");
} elseif ($isDistrict) {
  $dmId = (int)user()['id'];
  $usersRes = $mysqli->query("SELECT id,name FROM users WHERE id={$dmId} OR district_manager_id={$dmId} ORDER BY name");
} else {
  $usersRes = null;
}

$title='Reports'; 
include __DIR__.'/header.php';
?>
<div class="card">
  <h2 class="titlecase">Reports</h2>

  <?php if($debug): ?>
    <div class="alert" style="margin:.6rem 0">
      <div><b>ROLE RAW:</b> <code><?= e($roleRaw) ?></code></div>
      <div><b>ROLE NORM:</b> <code><?= e($role) ?></code></div>
      <div><b>isManager:</b> <code><?= $isManager ? 'YES' : 'NO' ?></code></div>
      <div><b>isDistrict:</b> <code><?= $isDistrict ? 'YES' : 'NO' ?></code></div>
      <div><b>WHERE:</b> <code><?= e($where) ?></code></div>
      <div><b>COUNT SQL:</b> <code><?= e($sqlCount) ?></code></div>
      <div><b>LIST SQL:</b> <code><?= e($sqlList) ?></code></div>
      <div><b>DB Error:</b> <code><?= e($err1 ?: '(none)') ?></code></div>
      <div><b>Total Matches:</b> <code><?= (int)$total ?></code>,
           <b>Rows Materialized:</b> <code><?= count($rows) ?></code></div>
      <?php if(!empty($preview)): ?>
        <details style="margin-top:.4rem"><summary>Preview First 5 Rows</summary>
          <pre><?= e(json_encode($preview, JSON_PRETTY_PRINT)) ?></pre>
        </details>
      <?php endif; ?>
      <div style="margin-top:.4rem">
        <a class="btn" href="reports.php">Reset</a>
        <a class="btn" href="?<?= http_build_query(['showall'=>1,'debug'=>1]) ?>">Show All + Debug</a>
      </div>
    </div>
  <?php endif; ?>

  <form class="filters" method="get">
    <?php if($isManager || $isDistrict): ?>
      <label>Employee
        <select name="employee_id">
          <option value="0">All</option>
          <?php if($usersRes) while($u=$usersRes->fetch_assoc()): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $employee_id==(int)$u['id']?'selected':'' ?>><?= e($u['name']) ?></option>
          <?php endwhile; ?>
        </select>
      </label>
    <?php endif; ?>
    <label>Range
      <select name="range">
        <option value="all"   <?= $range==='all'?'selected':'' ?>>All</option>
        <option value="today" <?= $range==='today'?'selected':'' ?>>Today</option>
        <option value="week"  <?= $range==='week'?'selected':'' ?>>This Week</option>
        <option value="month" <?= $range==='month'?'selected':'' ?>>This Month</option>
      </select>
    </label>
    <label>Status
      <select name="status">
        <option value="all"           <?= $status==='all'?'selected':'' ?>>All</option>
        <option value="pending"       <?= $status==='pending'?'selected':'' ?>>Pending</option>
        <option value="approved"      <?= $status==='approved'?'selected':'' ?>>Approved</option>
        <option value="needs_changes" <?= $status==='needs_changes'?'selected':'' ?>>Needs Changes</option>
      </select>
    </label>
    <label>Search
      <input name="q" value="<?= e($q) ?>" placeholder="Doctor, Hospital, Medicine">
    </label>
    <button class="btn">Apply</button>
    <a class="btn" href="reports.php">Reset</a>
    <?php if($isManager): ?>
      <a class="btn" href="exports.php?download=1">Export CSV</a>
      <?php $next = $_GET; $next['showall'] = $showAll ? 0 : 1; ?>
      <a class="btn" href="?<?= http_build_query($next) ?>"><?= $showAll ? 'Hide All' : 'Show All' ?></a>
      <?php $nd = $_GET; $nd['debug'] = 1; ?>
      <a class="btn" href="?<?= http_build_query($nd) ?>">Debug</a>
    <?php endif; ?>
  </form>

  <div class="table-wrap">
    <table class="table">
      <thead>
        <tr>
          <?php if($isManager || $isDistrict): ?><th>Employee</th><?php endif; ?>
          <th>Doctor</th><th>Purpose</th><th>Medicine</th><th>Hospital</th><th>Visit</th><th>Status</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php $colspan = ($isManager || $isDistrict) ? 8 : 7; ?>
        <?php if($err1): ?>
          <tr><td colspan="<?= $colspan ?>" class="muted">Query error: <?= e($err1) ?></td></tr>
        <?php elseif(!count($rows)): ?>
          <tr><td colspan="<?= $colspan ?>" class="muted">No reports found.</td></tr>
        <?php else: foreach($rows as $r): ?>
          <tr>
            <?php if($isManager || $isDistrict): ?><td><?= e($r['employee'] ?: '—') ?></td><?php endif; ?>
            <td><strong><?= e($r['doctor_name']) ?></strong><br><small class="muted"><?= e($r['doctor_email']) ?></small></td>
            <td><?= e($r['purpose']) ?></td>
            <td><?= e($r['medicine_name']) ?></td>
            <td><?= e($r['hospital_name']) ?></td>
            <td><?= $r['visit_datetime'] ? e(date('Y-m-d H:i', strtotime($r['visit_datetime']))) : '—' ?></td>
            <td><?php $st = $r['status'] ?: 'pending'; ?><span class="badge <?= e($st) ?>"><?= e($st) ?></span></td>
            <td>
              <a class="btn tiny" href="report_view.php?id=<?= (int)$r['id'] ?>">View</a>
              <?php if(!$isManager && ($r['status']??'')!=='approved'): ?>
                <a class="btn tiny" href="report_edit.php?id=<?= (int)$r['id'] ?>">Edit</a>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if($pages>1): ?>
    <div class="pagination">
      <?php for($i=1;$i<=$pages;$i++):
        $qp = $_GET; $qp['page']=$i; ?>
        <a class="page <?= $i===$page?'active':'' ?>" href="?<?= http_build_query($qp) ?>"><?= $i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
>>>>>>> 37d1d03e21f7806a028237f4c9fce390fa63d02d
</div>
<?php include __DIR__.'/footer.php'; ?>
