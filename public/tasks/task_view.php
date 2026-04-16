<?php
require_once __DIR__ . '/../../init.php'; require_login();
$id = (int)($_GET['id'] ?? 0);
$role = (string)(user()['role'] ?? 'employee');
$uid = (int)(user()['id'] ?? 0);
$hasEA = false;
if ($r = $mysqli->query("SHOW TABLES LIKE 'event_attendees'")) $hasEA = ($r->num_rows > 0);
$where = "e.id=? AND (e.user_id=?";
if ($role === 'manager') $where .= " OR 1=1";
elseif ($role === 'district_manager') $where .= " OR e.user_id IN (SELECT id FROM users WHERE district_manager_id=?)";
if ($hasEA) $where .= " OR EXISTS (SELECT 1 FROM event_attendees ea WHERE ea.event_id=e.id AND ea.user_id=?)";
$where .= ")";
$sql = "SELECT e.*, d.dr_name, d.speciality, d.hospital_address, d.place, d.email, d.contact_no, d.class
        FROM events e
        LEFT JOIN doctors_masterlist d ON d.id=e.doctor_id
        WHERE {$where} LIMIT 1";
$stmt = $mysqli->prepare($sql);
if (!$stmt) { http_response_code(500); exit('DB error'); }
if ($role === 'district_manager' && $hasEA)      $stmt->bind_param('iiii', $id, $uid, $uid, $uid);
elseif ($role === 'district_manager' && !$hasEA) $stmt->bind_param('iii', $id, $uid, $uid);
elseif ($role !== 'manager' && $hasEA)           $stmt->bind_param('iii', $id, $uid, $uid);
else                                              $stmt->bind_param('ii', $id, $uid);
$stmt->execute(); $ev = $stmt->get_result()->fetch_assoc();
if (!$ev) { http_response_code(404); exit('Not found'); }
$title='Task'; include __DIR__.'/../header.php';
$statusOptions = task_status_options();
$recurrenceOptions = task_recurrence_options();
$attNames = [];
if ($hasEA) {
  $stmtA = $mysqli->prepare("SELECT u.name,u.role FROM event_attendees ea JOIN users u ON u.id=ea.user_id WHERE ea.event_id=? ORDER BY u.role ASC, u.name ASC");
  if ($stmtA) {
    $eid = (int)$ev['id'];
    $stmtA->bind_param('i', $eid);
    $stmtA->execute();
    $rsA = $stmtA->get_result();
    while ($rA = $rsA->fetch_assoc()) $attNames[] = $rA['name'] . ' (' . $rA['role'] . ')';
    $stmtA->close();
  }
}
?>
<div class="card">
  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:12px;flex-wrap:wrap">
    <div>
      <h2 class="titlecase">Task Details</h2>
      <div class="muted">Status and recurrence are now tracked as part of the task workflow.</div>
    </div>
    <div>
      <span class="badge <?= e(task_status_badge_class((string)($ev['status'] ?? 'planned'))) ?>"><?= e($statusOptions[$ev['status'] ?? 'planned'] ?? 'Planned') ?></span>
    </div>
  </div>
  <div class="grid two" style="margin-top:14px">
    <div>
      <p><strong>Title:</strong> <?= e($ev['title']) ?></p>
      <p><strong>City:</strong> <?= e($ev['city']) ?></p>
      <p><strong>Visit Date/Time:</strong> <?= e($ev['visit_datetime'] ?? $ev['start']) ?></p>
      <p><strong>Start:</strong> <?= e($ev['start']) ?></p>
      <p><strong>End:</strong> <?= e($ev['end']) ?></p>
      <p><strong>All Day:</strong> <?= $ev['all_day'] ? 'Yes' : 'No' ?></p>
      <p><strong>Repeats:</strong> <?= e($recurrenceOptions[$ev['recurrence_pattern'] ?? 'none'] ?? 'Does not repeat') ?></p>
      <?php if(!empty($ev['recurrence_until'])): ?><p><strong>Repeat Until:</strong> <?= e($ev['recurrence_until']) ?></p><?php endif; ?>
      <?php if(!empty($ev['purpose'])): ?><p><strong>Purpose:</strong> <?= e($ev['purpose']) ?></p><?php endif; ?>
      <?php if(!empty($ev['medicine_name'])): ?><p><strong>Medicine:</strong> <?= e($ev['medicine_name']) ?></p><?php endif; ?>
      <?php if(!empty($ev['hospital_name'])): ?><p><strong>Hospital/Clinic:</strong> <?= e($ev['hospital_name']) ?></p><?php endif; ?>
    </div>
    <div>
      <?php if($ev['doctor_id']): ?>
        <p><strong>Doctor:</strong> <?= e($ev['dr_name']) ?> (<?= e($ev['speciality']) ?>)</p>
        <p><strong>Hospital/Clinic:</strong> <?= e($ev['hospital_address']) ?></p>
        <p><strong>Contact:</strong> <?= e($ev['email']) ?> • <?= e($ev['contact_no']) ?></p>
        <p><strong>Class:</strong> <?= e($ev['class']) ?></p>
      <?php else: ?>
        <p><em>No doctor linked.</em></p>
      <?php endif; ?>
      <?php if (!empty($attNames)): ?><p><strong>Other reps attending:</strong> <?= e(implode(', ', $attNames)) ?></p><?php endif; ?>
      <?php if (!empty($ev['summary'])): ?><p><strong>Summary:</strong><br><?= nl2br(e($ev['summary'])) ?></p><?php endif; ?>
      <?php if (!empty($ev['remarks'])): ?><p><strong>Remarks:</strong><br><?= nl2br(e($ev['remarks'])) ?></p><?php endif; ?>
    </div>
  </div>
  <hr>
  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <a class="btn primary" href="<?= url('reports/report_add.php?prefill=1&event_id='.(int)$ev['id'].'&doctor_id='.(int)$ev['doctor_id']) ?>">Generate Report From Task</a>
    <a class="btn" href="<?= url('tasks/task_edit.php?id='.(int)$ev['id']) ?>">Edit Task</a>
    <form method="post" action="<?= url('tasks/task_delete.php') ?>" style="margin:0" onsubmit="return confirm('Delete this task?');">
      <?php csrf_input(); ?>
      <input type="hidden" name="id" value="<?= (int)$ev['id'] ?>">
      <button class="btn danger" type="submit">Delete Task</button>
    </form>
  </div>
</div>
<?php include __DIR__.'/../footer.php'; ?>
