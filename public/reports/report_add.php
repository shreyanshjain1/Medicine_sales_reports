<?php
require_once __DIR__.'/../../init.php'; require_login();

$prefill = (int)($_GET['prefill'] ?? 0);
$doctor_id = (int)($_GET['doctor_id'] ?? 0);
$event_id = (int)($_GET['event_id'] ?? 0);

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

if ($prefill && $doctor_id) {
  // Basic prefill from doctors_masterlist (best-effort)
  $stmt = $mysqli->prepare("SELECT dr_name, email, hospital_address, place FROM doctors_masterlist WHERE id=? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param("i", $doctor_id);
    if ($stmt->execute()) {
      $r = $stmt->get_result()->fetch_assoc();
      if ($r) {
        $pre['doctor_name']   = $r['dr_name'] ?? '';
        $pre['doctor_email']  = trim((string)($r['email'] ?? ''));
        if ($pre['doctor_email'] === '') $pre['doctor_email'] = 'NA';
        $pre['hospital_name'] = $r['hospital_address'] ?? '';
      }
    }
    $stmt->close();
  }
}

if ($prefill && $event_id) {
  // Optional prefill from events table if present
  // NOTE: older DBs may not have these columns yet; keep this best-effort and never break the page.
  try {
    $stmt = $mysqli->prepare("SELECT purpose, medicine_name, hospital_name, visit_datetime, summary, remarks, start FROM events WHERE id=? LIMIT 1");
    if ($stmt) {
      $stmt->bind_param("i", $event_id);
      if ($stmt->execute()) {
        $r = $stmt->get_result()->fetch_assoc();
        if ($r) {
          $pre['purpose']        = $r['purpose'] ?? $pre['purpose'];
          $pre['medicine_name']  = $r['medicine_name'] ?? $pre['medicine_name'];
          $pre['hospital_name']  = $r['hospital_name'] ?? $pre['hospital_name'];
          $pre['visit_datetime'] = $r['visit_datetime'] ?? $pre['visit_datetime'];
          $pre['summary']        = $r['summary'] ?? $pre['summary'];
          $pre['remarks']        = $r['remarks'] ?? $pre['remarks'];
          if ($pre['visit_datetime'] === '' && !empty($r['start'])) $pre['visit_datetime'] = (string)$r['start'];
        }
      }
      $stmt->close();
    }
  } catch (Throwable $e) {
    // Fallback: try to at least prefill visit_datetime from start if possible
    try {
      $stmt = $mysqli->prepare("SELECT start FROM events WHERE id=? LIMIT 1");
      if ($stmt) {
        $stmt->bind_param('i', $event_id);
        if ($stmt->execute()) {
          $r = $stmt->get_result()->fetch_assoc();
          if ($r && $pre['visit_datetime'] === '' && !empty($r['start'])) $pre['visit_datetime'] = (string)$r['start'];
        }
        $stmt->close();
      }
    } catch (Throwable $e2) {}
  }
}

$errors = [];
$fieldErrors = [];
$warnings = [];
$duplicates = [];
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();

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

  // Validate (lightweight; offline flow also validates in JS)
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
    // Handle attachment (optional)
    if (!empty($_FILES['attachment']['name']) && is_uploaded_file($_FILES['attachment']['tmp_name'])) {
      $ext = strtolower(pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION));
      $allowed = ['pdf','jpg','jpeg','png'];

      if (!in_array($ext, $allowed, true)) {
        $errors[] = 'Invalid attachment type. Allowed: PDF/JPG/PNG.';
      } else {
        $dir = __DIR__ . '/../uploads/attachments';
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

    // Save signature if present (base64 PNG)
    $signature_path = null;
    if ($signature_data && str_starts_with($signature_data, 'data:image')) {
      $dir = __DIR__ . '/../uploads/signatures';
      if (!is_dir($dir)) @mkdir($dir, 0775, true);

      $sigName = 'sig_' . (int)user()['id'] . '_' . time() . '.png';
      $sigDest = $dir . '/' . $sigName;

      // Decode base64
      $parts = explode(',', $signature_data, 2);
      if (count($parts) === 2) {
        $bin = base64_decode($parts[1]);
        if ($bin !== false && file_put_contents($sigDest, $bin) !== false) {
          $signature_path = 'uploads/signatures/' . $sigName;
        }
      }
    }

    // Insert report (backward-compatible: only writes columns that exist in your DB)
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
      $notifyIds = notification_recipient_ids_for_report_owner($uid);
      if ($notifyIds) {
        notify_many(
          $notifyIds,
          'New report submitted',
          'A new meeting report from ' . ((string)(user()['name'] ?? 'a representative')) . ' is waiting for review.',
          'report_submitted',
          'report',
          $rid,
          url('reports/report_view.php?id=' . $rid),
          $uid
        );
      }
      $ok = true;
    } else {
      // last fallback for very old schemas
      $rid2 = db_safe_insert('reports', [
        'user_id' => $uid,
        'doctor_name' => $doctor_name,
        'doctor_email' => $doctor_email,
        'visit_datetime' => $visit_datetime,
        'created_at' => date('Y-m-d H:i:s'),
      ]);
      if ($rid2 > 0) $ok = true;
      else $errors[] = 'Failed to save report.';
    }
  }
}

$title = 'Add Report';
include __DIR__.'/../header.php';
?>
<div class="card">
  <h2 class="titlecase">Add Meeting Report</h2>

  <?php form_messages($errors, $warnings, $ok ? 'Report submitted.' : ''); ?>

  <?php if ($duplicates): ?>
    <div class="alert warning">
      <strong>Possible duplicate reports found</strong>
      <?php foreach ($duplicates as $dup): ?>
        <div>Report #<?= (int)$dup['id'] ?> · <?= e((string)$dup['doctor_name']) ?> · <?= e((string)$dup['visit_datetime']) ?> · <?= e((string)($dup['status'] ?: 'pending')) ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <form method="post" class="form crm-form" id="reportForm" enctype="multipart/form-data">
    <?php csrf_input(); ?>

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
      <div class="sig-header">
        <h3>Doctor Signature</h3>
        <div class="sig-actions">
          <button class="btn tiny" id="clearSig">Clear</button>
        </div>
      </div>

      <canvas id="sigPad" width="600" height="200" class="sig-canvas"></canvas>
      <input type="hidden" name="signature_data" id="signature_data">
    </div>

    <div class="actions form-actions">
      <button class="btn primary" type="submit">Save Report</button>
    </div>
  </form>
</div>

<script>
(function(){
  function initSignaturePad(){
    const canvas = document.getElementById('sigPad');
    const form   = document.getElementById('reportForm');
    const hidden = document.getElementById('signature_data');
    const clearB = document.getElementById('clearSig');
    if (!canvas || !form || !hidden) return;

    // Prevent tablet zoom/scroll gestures while signing
    canvas.style.touchAction = 'none';
    const stopTouch = (e) => { e.preventDefault(); };
    canvas.addEventListener('touchstart', stopTouch, { passive:false });
    canvas.addEventListener('touchmove',  stopTouch, { passive:false });
    canvas.addEventListener('touchend',   stopTouch, { passive:false });
    canvas.addEventListener('touchcancel',stopTouch, { passive:false });

    // Decide which pad implementation we are using
    const usingSignaturePad = !!window.SignaturePad; // CDN/online
    let pad = null;

    function resizeCanvas(){
      const ratio = Math.max(window.devicePixelRatio || 1, 1);
      const rect  = canvas.getBoundingClientRect();
      const cssW  = Math.max(1, rect.width);
      const cssH  = Math.max(1, rect.height);

      // Set internal pixel size to match CSS size * DPR
      canvas.width  = Math.round(cssW * ratio);
      canvas.height = Math.round(cssH * ratio);

      const ctx = canvas.getContext('2d');
      if (!ctx) return;

      // IMPORTANT:
      // - SignaturePad needs ctx scaled so its points (CSS px) match drawing space
      // - SimpleSignaturePad already maps points into canvas pixels, so DO NOT scale
      ctx.setTransform(1, 0, 0, 1, 0, 0);
      if (usingSignaturePad) {
        ctx.scale(ratio, ratio);
      }
    }

    resizeCanvas();

    // Init pad (SignaturePad online, fallback offline)
    if (usingSignaturePad) {
      pad = new SignaturePad(canvas, { minWidth: 1, maxWidth: 2 });
    } else if (window.SimpleSignaturePad) {
      pad = window.SimpleSignaturePad(canvas);
    }

    // If CDN blocked but fallback loads after, retry once on load
    if (!pad) {
      window.addEventListener('load', () => {
        resizeCanvas();
        if (!pad && window.SimpleSignaturePad) pad = window.SimpleSignaturePad(canvas);
      }, { once:true });
    }

    function capture(){
      if (!pad) { hidden.value = ''; return; }
      try {
        if (pad.isEmpty && pad.isEmpty()) { hidden.value = ''; return; }
        hidden.value = (pad.toDataURL ? pad.toDataURL('image/png') : canvas.toDataURL('image/png'));
      } catch (e) {
        hidden.value = '';
      }
    }

    if (clearB) clearB.addEventListener('click', (e)=>{
      e.preventDefault();
      if (pad && pad.clear) pad.clear();
      hidden.value = '';
    });

    // On rotate/resize: resizing resets the canvas, so clear to avoid offset/zoom issues
    function handleResize(){
      const hadInk = pad && pad.isEmpty && !pad.isEmpty();
      resizeCanvas();
      if (pad && pad.clear && hadInk) {
        pad.clear();
        hidden.value = '';
      }
    }

    window.addEventListener('resize', handleResize);
    window.addEventListener('orientationchange', handleResize);

    form.addEventListener('submit', ()=>capture());

    // Used by offline queue code in app.js
    window.__captureReportSignature = capture;
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initSignaturePad);
  } else {
    initSignaturePad();
  }
})();
</script>


<?php include __DIR__.'/../footer.php'; ?>
