<?php
require_once __DIR__.'/../../init.php'; require_login();

$prefill = (int)($_GET['prefill'] ?? 0);
$doctor_id = (int)($_GET['doctor_id'] ?? 0);
$event_id = (int)($_GET['event_id'] ?? 0);
$draft_id = (int)($_GET['draft_id'] ?? 0);
$draftLoaded = null;

$pre = [
  'doctor_name'   => '',
  'doctor_email'  => '',
  'purpose'       => '',
  'medicine_name' => '',
  'hospital_name' => '',
  'visit_datetime'=> '',
  'summary'       => '',
  'remarks'       => '',
];

if ($draft_id > 0) {
  $draftLoaded = fetch_report_draft($draft_id, (int)user()['id']);
  if ($draftLoaded) {
    foreach ($pre as $k => $v) {
      $pre[$k] = (string)($draftLoaded[$k] ?? $v);
    }
    if (!empty($pre['visit_datetime'])) $pre['visit_datetime'] = str_replace(' ', 'T', substr($pre['visit_datetime'], 0, 16));
  }
}

if ($prefill && $doctor_id) {
  $stmt = $mysqli->prepare("SELECT dr_name, email, hospital_address, place FROM doctors_masterlist WHERE id=? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("i", $doctor_id);
    if ($stmt->execute()) {
      $r = $stmt->get_result()->fetch_assoc();
      if ($r) {
        if ($pre['doctor_name'] === '') $pre['doctor_name'] = $r['dr_name'] ?? '';
        if ($pre['doctor_email'] === '') $pre['doctor_email'] = trim((string)($r['email'] ?? '')) ?: 'NA';
        if ($pre['hospital_name'] === '') $pre['hospital_name'] = $r['hospital_address'] ?? '';
      }
    }
    $stmt->close();
  }
}

if ($prefill && $event_id) {
  try {
    $stmt = $mysqli->prepare("SELECT purpose, medicine_name, hospital_name, visit_datetime, summary, remarks, start FROM events WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("i", $event_id);
      if ($stmt->execute()) {
        $r = $stmt->get_result()->fetch_assoc();
        if ($r) {
          foreach (['purpose','medicine_name','hospital_name','summary','remarks'] as $k) {
            if ($pre[$k] === '' && !empty($r[$k])) $pre[$k] = (string)$r[$k];
          }
          if ($pre['visit_datetime'] === '' && !empty($r['visit_datetime'])) $pre['visit_datetime'] = str_replace(' ', 'T', substr((string)$r['visit_datetime'], 0, 16));
          if ($pre['visit_datetime'] === '' && !empty($r['start'])) $pre['visit_datetime'] = str_replace(' ', 'T', substr((string)$r['start'], 0, 16));
        }
      }
      $stmt->close();
    }
  } catch (Throwable $e) {}
}

$errors = [];
$fieldErrors = [];
$warnings = [];
$duplicates = [];
$ok = false;
$draftSaved = false;
$draftRows = fetch_user_report_drafts((int)user()['id'], 6);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();
  $intent = (string)($_POST['submit_intent'] ?? 'submit');
  $draft_id = (int)($_POST['draft_id'] ?? 0);

  $doctor_name    = trim($_POST['doctor_name'] ?? '');
  $doctor_email   = trim($_POST['doctor_email'] ?? '');
  if ($doctor_email === '') $doctor_email = 'NA';
  $purpose        = trim($_POST['purpose'] ?? '');
  $medicine_name  = trim($_POST['medicine_name'] ?? '');
  $hospital_name  = trim($_POST['hospital_name'] ?? '');
  $visit_datetime = trim($_POST['visit_datetime'] ?? '');
  $summary        = trim($_POST['summary'] ?? '');
  $remarks        = trim($_POST['remarks'] ?? '');
  $signature_data = trim($_POST['signature_data'] ?? '');

  $pre = compact('doctor_name','doctor_email','purpose','medicine_name','hospital_name','visit_datetime','summary','remarks');

  if ($intent === 'save_draft') {
    $savedId = save_report_draft((int)user()['id'], report_draft_payload_from_request($_POST), $draft_id > 0 ? $draft_id : 0, null);
    if ($savedId > 0) {
      $draftSaved = true;
      $draft_id = $savedId;
      $draftRows = fetch_user_report_drafts((int)user()['id'], 6);
      log_audit('report_draft_saved', 'report_draft', $savedId, 'Report draft saved');
    } else {
      $errors[] = 'Failed to save draft.';
    }
  } else {
    if ($doctor_name === '') { $errors[] = 'Doctor Name is required.'; $fieldErrors['doctor_name'] = 'Doctor name is required.'; }
    if ($visit_datetime === '') { $errors[] = 'Visit Date/Time is required.'; $fieldErrors['visit_datetime'] = 'Visit date/time is required.'; }

    $warnings = report_quality_checks([
      'purpose' => $purpose,
      'medicine_name' => $medicine_name,
      'hospital_name' => $hospital_name,
      'summary' => $summary,
      'remarks' => $remarks,
    ]);
    $duplicates = find_potential_duplicate_reports((int)user()['id'], $doctor_name, $visit_datetime);

    $attachment_path = null;
    if (!$errors) {
      if (!empty($_FILES['attachment']['name']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
        $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
        $allowed = ['pdf','jpg','jpeg','png'];
        if (!in_array($ext, $allowed, true)) {
          $errors[] = 'Invalid attachment type. Allowed: PDF/JPG/PNG.';
        } else {
          $dir = __DIR__ . '/../../uploads/attachments';
          if (!is_dir($dir)) @mkdir($dir, 0775, true);
          $fname = 'att_' . (int)user()['id'] . '_' . time() . '.' . $ext;
          $dest = $dir . '/' . $fname;
          if (move_uploaded_file($_FILES['attachment']['tmp_name'], $dest)) {
            $attachment_path = 'uploads/attachments/' . $fname;
          } else {
            $errors[] = 'Failed to upload attachment.';
          }
        }
      }

      $signature_path = null;
      if ($signature_data && str_starts_with($signature_data, 'data:image')) {
        $dir = __DIR__ . '/../../uploads/signatures';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $sigName = 'sig_' . (int)user()['id'] . '_' . time() . '.png';
        $sigDest = $dir . '/' . $sigName;
        $parts = explode(',', $signature_data, 2);
        if (count($parts) === 2) {
          $bin = base64_decode($parts[1]);
          if ($bin !== false && file_put_contents($sigDest, $bin) !== false) {
            $signature_path = 'uploads/signatures/' . $sigName;
          }
        }
      }

      $uid = (int)user()['id'];
      $rid = db_safe_insert('reports', [
        'user_id' => $uid,
        'doctor_name' => $doctor_name,
        'doctor_email' => $doctor_email,
        'purpose' => $purpose,
        'medicine_name' => $medicine_name,
        'hospital_name' => $hospital_name,
        'visit_datetime' => $visit_datetime,
        'summary' => $summary,
        'remarks' => $remarks,
        'signature_path' => $signature_path,
        'attachment_path' => $attachment_path,
        'status' => 'pending',
        'created_at' => date('Y-m-d H:i:s'),
      ]);

      if ($rid > 0) {
        log_audit('report_created', 'report', $rid, 'Report submitted');
        add_report_status_history($rid, null, 'pending', 'Initial submission');
        if ($draft_id > 0) delete_report_draft($draft_id, $uid);
        $notifyIds = notification_recipient_ids_for_report_owner($uid);
        if ($notifyIds) {
          notify_many($notifyIds, 'New report submitted', 'A new meeting report from ' . ((string)(user()['name'] ?? 'a representative')) . ' is waiting for review.', 'report_submitted', 'report', $rid, url('reports/report_view.php?id=' . $rid), $uid);
        }
        header('Location: ' . url('reports/report_view.php?id=' . $rid));
        exit;
      } else {
        $errors[] = 'Failed to save report.';
      }
    }
  }
}

$title = 'Add Report';
include __DIR__.'/../header.php';
?>
<div class="crm-hero ui-hero">
  <div><h2>Add Meeting Report</h2><div class="subtle">Create a full submission or save work as a draft for later.</div></div>
  <div class="actions-inline"><a class="btn" href="<?= e(url('reports/reports.php')) ?>">Back to Reports</a></div>
</div>
<div class="summary-grid summary-grid-dashboard">
  <?php ui_stat_card('My Drafts', count($draftRows), 'Saved personal drafts'); ?>
  <?php ui_stat_card('Mode', $draftLoaded ? 'Draft Resume' : 'New Report', $draftLoaded ? 'Editing draft #'.(int)$draftLoaded['id'] : 'Fresh submission'); ?>
</div>
<div class="card">
  <?php form_messages($errors, $warnings, $draftSaved ? 'Draft saved.' : ''); ?>
  <?php if ($duplicates): ?>
    <div class="alert warning">
      <strong>Possible duplicate reports found</strong>
      <?php foreach ($duplicates as $dup): ?>
        <div>Report #<?= (int)$dup['id'] ?> · <?= e((string)$dup['doctor_name']) ?> · <?= e((string)$dup['visit_datetime']) ?> · <?= e((string)($dup['status'] ?: 'pending')) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($draftRows): ?>
  <div class="draft-strip">
    <div class="section-title">Recent Drafts</div>
    <div class="draft-chip-wrap">
      <?php foreach ($draftRows as $draft): ?>
        <a class="draft-chip" href="<?= e(url('reports/report_add.php?draft_id='.(int)$draft['id'])) ?>">
          <strong><?= e($draft['doctor_name'] ?: 'Untitled Draft') ?></strong>
          <span><?= e(!empty($draft['updated_at']) ? date('M d, Y H:i', strtotime((string)$draft['updated_at'])) : 'Recently updated') ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <form method="post" class="form crm-form" id="reportForm" enctype="multipart/form-data">
    <?php csrf_input(); ?>
    <input type="hidden" name="draft_id" value="<?= (int)$draft_id ?>">

    <div class="grid two">
      <?php render_text_input('Doctor Name', 'doctor_name', (string)$pre['doctor_name'], ['required'=>true], $fieldErrors); ?>
      <?php render_text_input('Doctor Email', 'doctor_email', (string)$pre['doctor_email'], ['placeholder'=>'NA','type'=>'email'], $fieldErrors); ?>
      <?php render_text_input('Purpose', 'purpose', (string)$pre['purpose'], ['placeholder'=>'Purpose of visit'], $fieldErrors); ?>
      <?php render_text_input('Medicine', 'medicine_name', (string)$pre['medicine_name'], ['placeholder'=>'Medicine discussed'], $fieldErrors); ?>
      <?php render_text_input('Hospital / Clinic', 'hospital_name', (string)$pre['hospital_name'], ['placeholder'=>'Hospital or clinic'], $fieldErrors); ?>
      <?php render_text_input('Visit Date / Time', 'visit_datetime', (string)$pre['visit_datetime'], ['type'=>'datetime-local','required'=>true], $fieldErrors); ?>
    </div>

    <?php render_textarea_input('Summary', 'summary', (string)$pre['summary'], ['rows'=>3,'placeholder'=>'Key discussion points'], $fieldErrors); ?>
    <?php render_textarea_input('Remarks', 'remarks', (string)$pre['remarks'], ['rows'=>3,'placeholder'=>'Follow-ups, commitments, or notes'], $fieldErrors); ?>

    <label class="form-field"><span class="form-label">Attachment (optional)</span>
      <input class="form-control" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png">
      <small class="field-hint">Allowed: PDF, JPG, JPEG, PNG</small>
    </label>

    <div class="signature-block form-panel">
      <div class="sig-header"><h3>Doctor Signature</h3><button class="btn tiny" type="button" id="clearSig">Clear</button></div>
      <canvas id="sigPad" width="800" height="220" class="signature-pad"></canvas>
      <input type="hidden" name="signature_data" id="signature_data">
      <small class="field-hint">Optional: capture a quick signature before final submission.</small>
    </div>

    <div class="form-actions actions-inline">
      <button class="btn" type="submit" name="submit_intent" value="save_draft">Save Draft</button>
      <button class="btn primary" type="submit" name="submit_intent" value="submit">Submit Report</button>
    </div>
  </form>
</div>
<script>
(function(){
  const form = document.getElementById('reportForm');
  const draftKey = 'medsales_report_draft_add';
  const fields = ['doctor_name','doctor_email','purpose','medicine_name','hospital_name','visit_datetime','summary','remarks'];
  if (form) {
    if (!form.querySelector('[name="draft_id"]').value) {
      try {
        const saved = JSON.parse(localStorage.getItem(draftKey) || '{}');
        fields.forEach(name => {
          const el = form.querySelector('[name="'+name+'"]');
          if (el && !el.value && typeof saved[name] === 'string') el.value = saved[name];
        });
      } catch(e){}
    }
    const saveLocal = () => {
      const payload = {};
      fields.forEach(name => {
        const el = form.querySelector('[name="'+name+'"]');
        if (el) payload[name] = el.value || '';
      });
      try { localStorage.setItem(draftKey, JSON.stringify(payload)); } catch(e){}
    };
    fields.forEach(name => {
      const el = form.querySelector('[name="'+name+'"]');
      if (el) el.addEventListener('input', saveLocal);
    });
    form.addEventListener('submit', function(){
      const intent = document.activeElement && document.activeElement.value;
      if (intent === 'submit') {
        try { localStorage.removeItem(draftKey); } catch(e){}
      } else {
        saveLocal();
      }
    });
  }

  const canvas = document.getElementById('sigPad');
  const hidden = document.getElementById('signature_data');
  const clearBtn = document.getElementById('clearSig');
  if (canvas && hidden) {
    const ctx = canvas.getContext('2d');
    let drawing = false;
    const point = (ev) => {
      const rect = canvas.getBoundingClientRect();
      const t = ev.touches ? ev.touches[0] : ev;
      return { x: t.clientX - rect.left, y: t.clientY - rect.top };
    };
    const start = (ev) => { drawing = true; const p = point(ev); ctx.beginPath(); ctx.moveTo(p.x,p.y); ev.preventDefault(); };
    const move = (ev) => { if (!drawing) return; const p = point(ev); ctx.lineWidth = 2; ctx.lineCap='round'; ctx.strokeStyle='#111827'; ctx.lineTo(p.x,p.y); ctx.stroke(); hidden.value = canvas.toDataURL('image/png'); ev.preventDefault(); };
    const end = () => { drawing = false; };
    canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start, {passive:false}); canvas.addEventListener('touchmove', move, {passive:false}); window.addEventListener('touchend', end);
    if (clearBtn) clearBtn.addEventListener('click', function(){ ctx.clearRect(0,0,canvas.width,canvas.height); hidden.value=''; });
  }
})();
</script>
<?php include __DIR__.'/../footer.php'; ?>
