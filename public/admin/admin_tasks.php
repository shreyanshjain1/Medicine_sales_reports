<?php
require_once __DIR__ . '/../../init.php';
require_login();
require_manager();

$title = 'Tasks';

// Filters
$q = trim((string)getv('q',''));
$from = trim((string)getv('from',''));
$to   = trim((string)getv('to',''));

// Default range: last 30 days
if ($from === '' && $to === '') {
  $to = date('Y-m-d');
  $from = date('Y-m-d', strtotime('-30 days'));
}

// Normalize range to DATETIME boundaries
$fromDT = $from !== '' ? ($from . ' 00:00:00') : null;
$toDT   = $to !== '' ? ($to . ' 23:59:59') : null;

$hasEA = false;
if ($r = $mysqli->query("SHOW TABLES LIKE 'event_attendees'")) {
  $hasEA = ($r->num_rows > 0);
}

$where = '1';
$params = [];
$types = '';

if ($fromDT) { $where .= ' AND e.start >= ?'; $types .= 's'; $params[] = $fromDT; }
if ($toDT)   { $where .= ' AND e.start <= ?'; $types .= 's'; $params[] = $toDT; }

if ($q !== '') {
  $where .= " AND (e.title LIKE CONCAT('%',?,'%')
               OR e.city LIKE CONCAT('%',?,'%')
               OR e.hospital_name LIKE CONCAT('%',?,'%')
               OR d.dr_name LIKE CONCAT('%',?,'%')
               OR u.name LIKE CONCAT('%',?,'%'))";
  $types .= 'sssss';
  $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q; $params[] = $q;
}

// Count
$sqlCount = "SELECT COUNT(DISTINCT e.id) AS c
             FROM events e
             LEFT JOIN users u ON u.id=e.user_id
             LEFT JOIN doctors_masterlist d ON d.id=e.doctor_id
             WHERE {$where}";
$stmtC = $mysqli->prepare($sqlCount);
if (!$stmtC) { http_response_code(500); exit('DB error'); }
if ($types !== '') {
  $bind = [$types];
  for ($i=0; $i<count($params); $i++) $bind[] = &$params[$i];
  @call_user_func_array([$stmtC,'bind_param'], $bind);
}
$stmtC->execute();
$total = (int)($stmtC->get_result()->fetch_assoc()['c'] ?? 0);
$stmtC->close();

$page = (int)getv('page',1);
[$page,$pages,$off,$per] = paginate($total, 20, $page);

// Data
$selectAtt = '';
$joinAtt = '';
$groupBy = ' GROUP BY e.id ';
if ($hasEA) {
  $selectAtt = ",
    GROUP_CONCAT(DISTINCT CONCAT(ua.name,' (',ua.role,')') ORDER BY ua.role ASC, ua.name ASC SEPARATOR ', ') AS attendees";
  $joinAtt = "
    LEFT JOIN event_attendees ea ON ea.event_id=e.id
    LEFT JOIN users ua ON ua.id=ea.user_id";
}

$sql = "SELECT
          e.id, e.title, e.city, e.start, e.end, e.all_day,
          e.purpose, e.medicine_name, e.hospital_name, e.visit_datetime,
          u.name AS owner_name, u.role AS owner_role,
          d.dr_name, d.speciality
          {$selectAtt}
        FROM events e
        LEFT JOIN users u ON u.id=e.user_id
        LEFT JOIN doctors_masterlist d ON d.id=e.doctor_id
        {$joinAtt}
        WHERE {$where}
        {$groupBy}
        ORDER BY e.start DESC
        LIMIT {$per} OFFSET {$off}";

$stmt = $mysqli->prepare($sql);
if (!$stmt) { http_response_code(500); exit('DB error'); }
if ($types !== '') {
  $bind = [$types];
  for ($i=0; $i<count($params); $i++) $bind[] = &$params[$i];
  @call_user_func_array([$stmt,'bind_param'], $bind);
}
$stmt->execute();
$res = $stmt->get_result();
$rows = [];
while ($r = $res->fetch_assoc()) $rows[] = $r;
$stmt->close();

include __DIR__ . '/../header.php';
?>

<div class="card">
  <div style="display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap">
    <div>
      <h2 class="titlecase" style="margin:0">Admin · Tasks</h2>
      <div class="muted">Showing <?= (int)$total ?> task(s)</div>
    </div>

    <form method="get" action="" class="form" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end">
      <label class="titlecase" style="margin:0">
        Search
        <input name="q" value="<?= e($q) ?>" placeholder="Title, city, doctor, hospital, owner" style="min-width:240px">
      </label>
      <label class="titlecase" style="margin:0">
        From
        <input type="date" name="from" value="<?= e($from) ?>">
      </label>
      <label class="titlecase" style="margin:0">
        To
        <input type="date" name="to" value="<?= e($to) ?>">
      </label>
      <button class="btn primary" type="submit">Filter</button>
      <a class="btn" href="<?= url('admin/admin_tasks.php') ?>">Reset</a>
    </form>
  </div>

  <hr>

  <div style="overflow:auto">
    <table class="table">
      <thead>
        <tr>
          <th>Date/Time</th>
          <th>Title</th>
          <th>Doctor</th>
          <th>City</th>
          <th>Owner</th>
          <th>Attendees</th>
          <th style="width:220px">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?>
          <tr><td colspan="7" class="muted">No tasks found.</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td>
                <div><?= e($r['visit_datetime'] ?: $r['start']) ?></div>
                <?php if (!empty($r['end'])): ?><div class="muted">to <?= e($r['end']) ?></div><?php endif; ?>
              </td>
              <td>
                <div><strong><?= e($r['title']) ?></strong></div>
                <?php if (!empty($r['purpose']) || !empty($r['medicine_name'])): ?>
                  <div class="muted">
                    <?php if (!empty($r['purpose'])): ?><?= e($r['purpose']) ?><?php endif; ?>
                    <?php if (!empty($r['purpose']) && !empty($r['medicine_name'])): ?> · <?php endif; ?>
                    <?php if (!empty($r['medicine_name'])): ?><?= e($r['medicine_name']) ?><?php endif; ?>
                  </div>
                <?php endif; ?>
              </td>
              <td>
                <?php if (!empty($r['dr_name'])): ?>
                  <div><?= e($r['dr_name']) ?></div>
                  <?php if (!empty($r['speciality'])): ?><div class="muted"><?= e($r['speciality']) ?></div><?php endif; ?>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td><?= e($r['city']) ?></td>
              <td>
                <div><?= e($r['owner_name']) ?></div>
                <div class="muted"><?= e($r['owner_role']) ?></div>
              </td>
              <td>
                <?php if ($hasEA && !empty($r['attendees'])): ?>
                  <div><?= e($r['attendees']) ?></div>
                <?php else: ?>
                  <span class="muted">—</span>
                <?php endif; ?>
              </td>
              <td>
                <div style="display:flex;gap:8px;flex-wrap:wrap">
                  <a class="btn tiny" href="<?= url('tasks/task_view.php?id='.(int)$r['id']) ?>">View</a>
                  <a class="btn tiny" href="<?= url('tasks/task_edit.php?id='.(int)$r['id']) ?>">Edit</a>
                  <form method="post" action="<?= url('tasks/task_delete.php') ?>" style="margin:0" onsubmit="return confirm('Delete this task?');">
                    <?php csrf_input(); ?>
                    <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                    <button class="btn tiny danger" type="submit">Delete</button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
    <div style="display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;margin-top:12px">
      <?php for ($p=1; $p<=$pages; $p++): ?>
        <?php
          $qs = $_GET;
          $qs['page'] = $p;
          $href = 'admin_tasks.php?' . http_build_query($qs);
        ?>
        <a class="btn tiny <?= $p===$page?'primary':'' ?>" href="<?= e($href) ?>"><?= (int)$p ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
