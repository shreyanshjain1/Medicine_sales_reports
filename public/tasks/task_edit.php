<?php
require_once __DIR__ . '/../../init.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Bad request'); }

$role = (string)(user()['role'] ?? 'employee');
$uid  = (int)(user()['id'] ?? 0);

$hasEA = false;
if ($r = $mysqli->query("SHOW TABLES LIKE 'event_attendees'")) {
  $hasEA = ($r->num_rows > 0);
}

$where = "e.id=? AND (e.user_id=?";
if ($role === 'manager') {
  $where .= " OR 1=1";
} elseif ($role === 'district_manager') {
  $where .= " OR e.user_id IN (SELECT id FROM users WHERE district_manager_id=?)";
}
if ($hasEA) {
  $where .= " OR EXISTS (SELECT 1 FROM event_attendees ea WHERE ea.event_id=e.id AND ea.user_id=?)";
}
$where .= ")";

$stmt = $mysqli->prepare(
  "SELECT e.*, d.dr_name, d.speciality, d.hospital_address, d.place
   FROM events e
   LEFT JOIN doctors_masterlist d ON d.id = e.doctor_id
   WHERE {$where} LIMIT 1");
if (!$stmt) { http_response_code(500); exit('DB error'); }
if ($role === 'district_manager' && $hasEA) {
  $stmt->bind_param('iiii', $id, $uid, $uid, $uid);
} elseif ($role === 'district_manager' && !$hasEA) {
  $stmt->bind_param('iii', $id, $uid, $uid);
} elseif ($role !== 'manager' && $hasEA) {
  $stmt->bind_param('iii', $id, $uid, $uid);
} else {
  $stmt->bind_param('ii', $id, $uid);
}
$stmt->execute();
$ev = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$ev) { http_response_code(404); exit('Not found'); }

$errors = [];
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $title = trim((string)post('title',''));
  $city = trim((string)post('city',''));
  $doctor_id = (int)(post('doctor_id','') ?: 0);
  $start = trim((string)post('start',''));
  $end = trim((string)post('end',''));
  $all = post('all_day') ? 1 : 0;

  $purpose = trim((string)post('purpose',''));
  $medicine_name = trim((string)post('medicine_name',''));
  $hospital_name = trim((string)post('hospital_name',''));
  $visit_datetime = trim((string)post('visit_datetime',''));
  $summary = trim((string)post('summary',''));
  $remarks = trim((string)post('remarks',''));

  $attendees = post('attendees', []);
  if (!is_array($attendees)) $attendees = [];

  if ($city === '') $errors[] = 'City is required.';
  if ($start === '') $errors[] = 'Start date/time is required.';

  if ($doctor_id && $title === '') {
    $st = $mysqli->prepare("SELECT dr_name, hospital_address FROM doctors_masterlist WHERE id=? LIMIT 1");
    if ($st) {
      $st->bind_param('i', $doctor_id);
      $st->execute();
      $dr = $st->get_result()->fetch_assoc();
      $st->close();
      if ($dr) {
        $title = 'Visit: ' . ($dr['dr_name'] ?? 'Doctor');
        if ($hospital_name === '' && !empty($dr['hospital_address'])) $hospital_name = (string)$dr['hospital_address'];
      }
    }
  }

  if ($visit_datetime === '' && $start !== '') $visit_datetime = $start;

  if (!$errors) {
    $stmt = $mysqli->prepare("UPDATE events
      SET title=?, city=?, doctor_id=?, purpose=?, medicine_name=?, hospital_name=?, visit_datetime=?, summary=?, remarks=?, start=?, end=?, all_day=?
      WHERE id=? AND (user_id=?" . ($role==='manager' ? " OR 1=1" : ($role==='district_manager' ? " OR user_id IN (SELECT id FROM users WHERE district_manager_id=?)" : "")) . ")");
    if (!$stmt) {
      $errors[] = 'Failed to update task.';
    } else {
      if ($role === 'district_manager') {
        $stmt->bind_param(
          'ssissssssssiii',
          $title,
          $city,
          $doctor_id,
          $purpose,
          $medicine_name,
          $hospital_name,
          $visit_datetime,
          $summary,
          $remarks,
          $start,
          $end,
          $all,
          $id,
          $uid,
          $uid
        );
      } else {
        $stmt->bind_param(
          'ssissssssssii',
          $title,
          $city,
          $doctor_id,
          $purpose,
          $medicine_name,
          $hospital_name,
          $visit_datetime,
          $summary,
          $remarks,
          $start,
          $end,
          $all,
          $id,
          $uid
        );
      }
      if ($stmt->execute()) {
        // Update attendees (best-effort)
        $mysqli->query("CREATE TABLE IF NOT EXISTS event_attendees ( event_id INT NOT NULL, user_id INT NOT NULL, added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY(event_id,user_id), INDEX(user_id) ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        $eid = (int)$ev['id'];
        $mysqli->query("DELETE FROM event_attendees WHERE event_id=".$eid);
        if (!empty($attendees)) {
          $stmtA = $mysqli->prepare("INSERT IGNORE INTO event_attendees (event_id,user_id) VALUES (?,?)");
          if ($stmtA) {
            foreach ($attendees as $aidRaw) {
              $aid = (int)$aidRaw;
              if ($aid <= 0) continue;
              if ($aid === $uid) continue;
              $stmtA->bind_param('ii', $eid, $aid);
              @$stmtA->execute();
            }
            $stmtA->close();
          }
        }
        header('Location: ' . url('tasks/task_view.php?id=' . $id));
        exit;
      }
      $errors[] = 'Failed to update task.';
      $stmt->close();
    }
  }

  // Refresh event data for redisplay
  $stmt = $mysqli->prepare("SELECT e.*, d.dr_name, d.speciality, d.hospital_address, d.place
   FROM events e
   LEFT JOIN doctors_masterlist d ON d.id = e.doctor_id
   WHERE {$where} LIMIT 1");
  if ($role === 'district_manager' && $hasEA) {
    $stmt->bind_param('iiii', $id, $uid, $uid, $uid);
  } elseif ($role === 'district_manager' && !$hasEA) {
    $stmt->bind_param('iii', $id, $uid, $uid);
  } elseif ($role !== 'manager' && $hasEA) {
    $stmt->bind_param('iii', $id, $uid, $uid);
  } else {
    $stmt->bind_param('ii', $id, $uid);
  }
  $stmt->execute();
  $ev = $stmt->get_result()->fetch_assoc();
  $stmt->close();
}

$titlePage = 'Edit Task';
$title = $titlePage;
include __DIR__ . '/../header.php';

// Cities list for dropdown
$cities = [];
if ($res = $mysqli->query("SELECT DISTINCT place AS city FROM doctors_masterlist WHERE place IS NOT NULL AND place<>'' ORDER BY place ASC")) {
  while($row = $res->fetch_assoc()) $cities[] = (string)$row['city'];
}

// Multi-rep attendees UI (manager / district manager)
$repUsers = [];
$selectedAtt = [];
if ($role === 'manager') {
  if ($r=$mysqli->query("SELECT id,name,role FROM users WHERE active=1 ORDER BY role ASC, name ASC")) {
    while($u=$r->fetch_assoc()) $repUsers[]=$u;
  }
} elseif ($role === 'district_manager') {
  $stmtU = $mysqli->prepare("SELECT id,name,role FROM users WHERE active=1 AND (id=? OR district_manager_id=?) ORDER BY role ASC, name ASC");
  if ($stmtU) {
    $stmtU->bind_param('ii',$uid,$uid);
    $stmtU->execute();
    $rsU=$stmtU->get_result();
    while($u=$rsU->fetch_assoc()) $repUsers[]=$u;
    $stmtU->close();
  }
}
if (!empty($repUsers)) {
  if ($r=$mysqli->query("SHOW TABLES LIKE 'event_attendees'")) {
    if ($r->num_rows>0) {
      $stmtS = $mysqli->prepare("SELECT user_id FROM event_attendees WHERE event_id=?");
      if ($stmtS) {
        $eid=(int)$ev['id'];
        $stmtS->bind_param('i',$eid);
        $stmtS->execute();
        $rsS=$stmtS->get_result();
        while($x=$rsS->fetch_assoc()) $selectedAtt[]=(int)$x['user_id'];
        $stmtS->close();
      }
    }
  }
}
?>

<div class="card">
  <h2 class="titlecase">Edit Task</h2>

  <?php if ($errors): ?>
    <div class="alert danger" style="margin-bottom:12px">
      <?php foreach($errors as $er): ?><div><?= e($er) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form class="form" method="post" action="<?= url('tasks/task_edit.php?id='.(int)$ev['id']) ?>">
    <?php csrf_input(); ?>

    <div class="grid two">
      <label class="titlecase">Title
        <input name="title" value="<?= e($ev['title'] ?? '') ?>" placeholder="(Optional — auto if doctor chosen)">
      </label>

      <label class="titlecase">City
        <select name="city" id="edit_city" required>
          <option value="">Select City</option>
          <?php foreach($cities as $c): ?>
            <option value="<?= e($c) ?>" <?= ($c === ($ev['city'] ?? '')) ? 'selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <label class="titlecase">Doctor
        <select name="doctor_id" id="edit_doctor">
          <option value="">Select Doctor</option>
        </select>
        <small class="muted">Current: <?= e($ev['dr_name'] ?? 'None') ?></small>
      </label>

      <label class="titlecase">Visit Date/Time
        <input type="datetime-local" name="visit_datetime" value="<?= e(str_replace(' ', 'T', (string)($ev['visit_datetime'] ?? $ev['start'] ?? ''))) ?>">
      </label>

      <label class="titlecase">Start
        <input type="datetime-local" name="start" required value="<?= e(str_replace(' ', 'T', (string)($ev['start'] ?? ''))) ?>">
      </label>

      <label class="titlecase">End
        <input type="datetime-local" name="end" value="<?= e(str_replace(' ', 'T', (string)($ev['end'] ?? ''))) ?>">
      </label>

      <label class="chk titlecase" style="align-self:end">
        <input type="checkbox" name="all_day" value="1" <?= !empty($ev['all_day']) ? 'checked' : '' ?>> All Day
      </label>

      <?php if (!empty($repUsers)): ?>
        <label class="titlecase" style="grid-column:1/-1">Reps attending (optional)
          <select name="attendees[]" multiple size="6">
            <?php foreach($repUsers as $u): ?>
              <?php if ((int)$u['id'] === (int)$uid) continue; ?>
              <option value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $selectedAtt, true) ? 'selected' : '' ?>><?= e($u['name']) ?> (<?= e($u['role']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <small class="muted">Hold Ctrl/⌘ to select multiple.</small>
        </label>
      <?php endif; ?>
    </div>

    <div class="grid two" style="margin-top:10px">
      <label class="titlecase">Purpose
        <input name="purpose" value="<?= e($ev['purpose'] ?? '') ?>">
      </label>

      <label class="titlecase">Medicine Name
        <input name="medicine_name" value="<?= e($ev['medicine_name'] ?? '') ?>">
      </label>

      <label class="titlecase">Hospital / Clinic
        <input name="hospital_name" value="<?= e($ev['hospital_name'] ?? '') ?>">
      </label>

      <label class="titlecase">Summary
        <textarea name="summary" rows="3"><?= e($ev['summary'] ?? '') ?></textarea>
      </label>

      <label class="titlecase">Remarks
        <textarea name="remarks" rows="3"><?= e($ev['remarks'] ?? '') ?></textarea>
      </label>
    </div>

    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:14px">
      <button class="btn primary" type="submit">Save Changes</button>
      <a class="btn" href="<?= url('tasks/task_view.php?id='.(int)$ev['id']) ?>">Cancel</a>
    </div>
  </form>
</div>

<script>
(function(){
  const citySel = document.getElementById('edit_city');
  const docSel = document.getElementById('edit_doctor');
  if (!citySel || !docSel) return;

  async function loadDoctors(city){
    docSel.innerHTML = '<option value="">Select Doctor</option>';
    if (!city) return;
    try{
      const r = await fetch('../api/api_doctors.php?city=' + encodeURIComponent(city), {cache:'no-store'});
      const j = await r.json();
      const list = j.doctors || [];
      list.forEach(d=>{
        const o = document.createElement('option');
        o.value = d.id;
        o.textContent = (d.dr_name || 'Doctor') + (d.speciality ? ' — ' + d.speciality : '');
        docSel.appendChild(o);
      });

      const cur = "<?= (int)($ev['doctor_id'] ?? 0) ?>";
      if (cur) docSel.value = cur;
    }catch(e){}
  }

  citySel.addEventListener('change', ()=>loadDoctors(citySel.value.trim()));
  loadDoctors(citySel.value.trim());
})();
</script>

<?php include __DIR__ . '/../footer.php'; ?>
