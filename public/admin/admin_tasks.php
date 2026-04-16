<?php
require_once __DIR__ . '/../../init.php';
require_login();
require_manager();
$title = 'Tasks';
$q = trim((string)getv('q',''));
$from = trim((string)getv('from',''));
$to   = trim((string)getv('to',''));
$status = trim((string)getv('status',''));
if ($from === '' && $to === '') { $to = date('Y-m-d'); $from = date('Y-m-d', strtotime('-30 days')); }
$fromDT = $from !== '' ? ($from . ' 00:00:00') : null;
$toDT   = $to !== '' ? ($to . ' 23:59:59') : null;
$hasEA = false;
if ($r = $mysqli->query("SHOW TABLES LIKE 'event_attendees'")) $hasEA = ($r->num_rows > 0);
$where = '1'; $params = []; $types = '';
if ($fromDT) { $where .= ' AND e.start >= ?'; $types .= 's'; $params[] = $fromDT; }
if ($toDT)   { $where .= ' AND e.start <= ?'; $types .= 's'; $params[] = $toDT; }
if ($status !== '') { $where .= ' AND COALESCE(e.status,\'planned\') = ?'; $types .= 's'; $params[] = $status; }
if ($q !== '') {
  $where .= " AND (e.title LIKE CONCAT('%',?,'%') OR e.city LIKE CONCAT('%',?,'%') OR e.hospital_name LIKE CONCAT('%',?,'%') OR d.dr_name LIKE CONCAT('%',?,'%') OR u.name LIKE CONCAT('%',?,'%'))";
  $types .= 'sssss'; array_push($params, $q, $q, $q, $q, $q);
}
$sqlCount = "SELECT COUNT(DISTINCT e.id) AS c FROM events e LEFT JOIN users u ON u.id=e.user_id LEFT JOIN doctors_masterlist d ON d.id=e.doctor_id WHERE {$where}";
$stmtC = $mysqli->prepare($sqlCount); if (!$stmtC) { http_response_code(500); exit('DB error'); }
if ($types !== '') { $bind = [$types]; for ($i=0; $i<count($params); $i++) $bind[] = &$params[$i]; @call_user_func_array([$stmtC,'bind_param'], $bind); }
$stmtC->execute(); $total = (int)($stmtC->get_result()->fetch_assoc()['c'] ?? 0); $stmtC->close();
$page = (int)getv('page',1); [$page,$pages,$off,$per] = paginate($total, 20, $page);
$selectAtt=''; $joinAtt=''; $groupBy=' GROUP BY e.id ';
if ($hasEA) {
  $selectAtt = ", GROUP_CONCAT(DISTINCT CONCAT(ua.name,' (',ua.role,')') ORDER BY ua.role ASC, ua.name ASC SEPARATOR ', ') AS attendees";
  $joinAtt = " LEFT JOIN event_attendees ea ON ea.event_id=e.id LEFT JOIN users ua ON ua.id=ea.user_id";
}
$sql = "SELECT e.id, e.title, e.city, e.start, e.end, e.all_day, e.status, e.recurrence_pattern, e.recurrence_until,
          e.purpose, e.medicine_name, e.hospital_name, e.visit_datetime,
          u.name AS owner_name, u.role AS owner_role, d.dr_name, d.speciality {$selectAtt}
        FROM events e
        LEFT JOIN users u ON u.id=e.user_id
        LEFT JOIN doctors_masterlist d ON d.id=e.doctor_id
        {$joinAtt}
        WHERE {$where}
        {$groupBy}
        ORDER BY e.start DESC
        LIMIT {$per} OFFSET {$off}";
$stmt = $mysqli->prepare($sql); if (!$stmt) { http_response_code(500); exit('DB error'); }
if ($types !== '') { $bind = [$types]; for ($i=0; $i<count($params); $i++) $bind[] = &$params[$i]; @call_user_func_array([$stmt,'bind_param'], $bind); }
$stmt->execute(); $res = $stmt->get_result(); $rows=[]; while ($r=$res->fetch_assoc()) $rows[]=$r; $stmt->close();
$statusOptions = task_status_options();
include __DIR__ . '/../header.php';
?>
<div class="card">
  <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div><h2 class="titlecase" style="margin:0">Admin · Tasks</h2><div class="muted">Showing <?= (int)$total ?> task(s)</div></div>
    <form method="get" action="" class="form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <label class="titlecase" style="margin:0">Search<input name="q" value="<?= e($q) ?>" placeholder="Title, city, doctor, hospital, owner" style="min-width:220px"></label>
      <label class="titlecase" style="margin:0">Status
        <select name="status"><option value="">All</option><?php foreach ($statusOptions as $k=>$label): ?><option value="<?= e($k) ?>" <?= $status===$k?'selected':'' ?>><?= e($label) ?></option><?php endforeach; ?></select>
      </label>
      <label class="titlecase" style="margin:0">From<input type="date" name="from" value="<?= e($from) ?>"></label>
      <label class="titlecase" style="margin:0">To<input type="date" name="to" value="<?= e($to) ?>"></label>
      <button class="btn primary" type="submit">Filter</button>
      <a class="btn" href="<?= url('admin/admin_tasks.php') ?>">Reset</a>
    </form>
  </div>
  <hr>
  <div style="overflow:auto">
    <table class="table">
      <thead><tr><th>Date/Time</th><th>Task</th><th>Status</th><th>Doctor</th><th>Owner</th><th>Attendees</th><th style="width:220px">Actions</th></tr></thead>
      <tbody>
      <?php if (empty($rows)): ?>
        <tr><td colspan="7" class="muted">No tasks found.</td></tr>
      <?php else: foreach ($rows as $r): ?>
        <tr>
          <td><div><?= e($r['visit_datetime'] ?: $r['start']) ?></div><?php if (!empty($r['end'])): ?><div class="muted">to <?= e($r['end']) ?></div><?php endif; ?></td>
          <td>
            <div><strong><?= e($r['title']) ?></strong></div>
            <div class="muted"><?= e($r['city']) ?><?php if (($r['recurrence_pattern'] ?? 'none') !== 'none'): ?> · Repeats <?= e($r['recurrence_pattern']) ?><?php endif; ?></div>
          </td>
          <td><span class="badge <?= e(task_status_badge_class((string)($r['status'] ?? 'planned'))) ?>"><?= e($statusOptions[$r['status'] ?? 'planned'] ?? 'Planned') ?></span></td>
          <td><?php if (!empty($r['dr_name'])): ?><div><?= e($r['dr_name']) ?></div><div class="muted"><?= e($r['speciality']) ?></div><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td><div><?= e($r['owner_name']) ?></div><div class="muted"><?= e($r['owner_role']) ?></div></td>
          <td><?php if ($hasEA && !empty($r['attendees'])): ?><div><?= e($r['attendees']) ?></div><?php else: ?><span class="muted">—</span><?php endif; ?></td>
          <td><div style="display:flex;gap:8px;flex-wrap:wrap"><a class="btn tiny" href="<?= url('tasks/task_view.php?id='.(int)$r['id']) ?>">View</a><a class="btn tiny" href="<?= url('tasks/task_edit.php?id='.(int)$r['id']) ?>">Edit</a></div></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../footer.php'; ?>
