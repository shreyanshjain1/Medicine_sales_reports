<?php
require_once __DIR__.'/../init.php'; require_login();
$prefill = (int)($_GET['prefill'] ?? 0);
$doctor_id = (int)($_GET['doctor_id'] ?? 0);
$event_id = (int)($_GET['event_id'] ?? 0);
$pre = ['doctor_name'=>'','doctor_email'=>'','purpose'=>'','medicine_name'=>'','hospital_name'=>'','visit_datetime'=>'','summary'=>'','remarks'=>''];
if ($prefill && $doctor_id) {
  $stmt = $mysqli->prepare("SELECT dr_name, email, hospital_address FROM doctors_masterlist WHERE id=? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param('i', $doctor_id); $stmt->execute();
    if ($r = $stmt->get_result()->fetch_assoc()) {
      $pre['doctor_name'] = $r['dr_name'] ?? '';
      $pre['doctor_email'] = trim((string)($r['email'] ?? '')) ?: 'NA';
      $pre['hospital_name'] = $r['hospital_address'] ?? '';
    }
    $stmt->close();
  }
}
if ($prefill && $event_id) {
  $stmt = $mysqli->prepare("SELECT purpose, medicine_name, hospital_name, visit_datetime, summary, remarks, start FROM events WHERE id=? LIMIT 1");
  if ($stmt) {
    $stmt->bind_param('i',$event_id); $stmt->execute();
    if ($r = $stmt->get_result()->fetch_assoc()) {
      foreach (['purpose','medicine_name','hospital_name','visit_datetime','summary','remarks'] as $field) if (!empty($r[$field])) $pre[$field] = (string)$r[$field];
      if ($pre['visit_datetime'] === '' && !empty($r['start'])) $pre['visit_datetime'] = (string)$r['start'];
    }
    $stmt->close();
  }
}
$errors=[]; $ok=false; $savedId=0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();
  $doctor_name=trim((string)post('doctor_name',''));
  $doctor_email=trim((string)post('doctor_email','')) ?: 'NA';
  $purpose=trim((string)post('purpose',''));
  $medicine_name=trim((string)post('medicine_name',''));
  $hospital_name=trim((string)post('hospital_name',''));
  $visit_datetime=trim((string)post('visit_datetime',''));
  $summary=trim((string)post('summary',''));
  $remarks=trim((string)post('remarks',''));
  $signature_data=trim((string)post('signature_data',''));
  if ($doctor_name === '') $errors[] = 'Doctor name is required.';
  if ($visit_datetime === '') $errors[] = 'Visit date/time is required.';
  $attachment_path = save_uploaded_attachment($_FILES['attachment'] ?? [], (int)user()['id'], $errors);
  $signature_path = save_signature_data($signature_data, (int)user()['id']);
  if (!$errors) {
    $savedId = db_safe_insert('reports', [
      'user_id'=>(int)user()['id'], 'doctor_name'=>$doctor_name, 'doctor_email'=>$doctor_email, 'purpose'=>$purpose,
      'medicine_name'=>$medicine_name, 'hospital_name'=>$hospital_name, 'visit_datetime'=>$visit_datetime,
      'summary'=>$summary, 'remarks'=>$remarks, 'signature_path'=>$signature_path, 'attachment_path'=>$attachment_path,
      'status'=>'pending', 'created_at'=>date('Y-m-d H:i:s')
    ]);
    if ($savedId > 0) {
      log_audit('report_created', 'report', $savedId, 'Report submitted');
      $ok = true;
      $pre = ['doctor_name'=>'','doctor_email'=>'','purpose'=>'','medicine_name'=>'','hospital_name'=>'','visit_datetime'=>'','summary'=>'','remarks'=>''];
    } else {
      $errors[] = 'Failed to save report.';
    }
  }
}
$title = 'Create Report'; include __DIR__.'/header.php';
?>
<div class="crm-hero"><div><h2>Create Meeting Report</h2><div class="subtle">Capture visit details, attachments, and doctor acknowledgement in one record.</div></div><?php if($ok && $savedId): ?><a class="btn primary" href="report_view.php?id=<?= (int)$savedId ?>">Open saved report</a><?php endif; ?></div>
<div class="card">
  <?php if ($ok): ?><div class="alert success">Report submitted successfully.</div><?php endif; ?>
  <?php if ($errors): ?><div class="alert danger"><?php foreach ($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?></div><?php endif; ?>
  <form method="post" class="form" id="reportForm" enctype="multipart/form-data">
    <?php csrf_input(); ?>
    <div class="grid two">
      <label>Doctor Name<input name="doctor_name" value="<?= e($pre['doctor_name']) ?>" required></label>
      <label>Doctor Email<input name="doctor_email" value="<?= e($pre['doctor_email']) ?>" placeholder="NA"></label>
      <label>Purpose<input name="purpose" value="<?= e($pre['purpose']) ?>"></label>
      <label>Medicine<input name="medicine_name" value="<?= e($pre['medicine_name']) ?>"></label>
      <label>Hospital / Clinic<input name="hospital_name" value="<?= e($pre['hospital_name']) ?>"></label>
      <label>Visit Date / Time<input type="datetime-local" name="visit_datetime" value="<?= e(str_replace(' ','T',$pre['visit_datetime'])) ?>" required></label>
    </div>
    <label>Summary<textarea name="summary" rows="3"><?= e($pre['summary']) ?></textarea></label>
    <label>Remarks<textarea name="remarks" rows="3"><?= e($pre['remarks']) ?></textarea></label>
    <label>Attachment (optional)<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"></label>
    <div class="signature-block">
      <div class="sig-header"><h3 style="margin:0">Doctor Signature</h3><button class="btn tiny" id="clearSig">Clear</button></div>
      <canvas id="sigPad" width="600" height="200" class="sig-canvas"></canvas>
      <input type="hidden" name="signature_data" id="signature_data">
    </div>
    <div class="actions-inline" style="margin-top:16px"><button class="btn primary" type="submit">Save Report</button><a class="btn" href="reports.php">Cancel</a></div>
  </form>
</div>
<script>
(function(){
  function initSignaturePad(){
    const canvas = document.getElementById('sigPad'); const form = document.getElementById('reportForm'); const hidden = document.getElementById('signature_data'); const clearB = document.getElementById('clearSig');
    if (!canvas || !form || !hidden) return; canvas.style.touchAction = 'none';
    const usingSignaturePad = !!window.SignaturePad; let pad = null;
    function resizeCanvas(){ const ratio = Math.max(window.devicePixelRatio || 1, 1); const rect = canvas.getBoundingClientRect(); const ctx = canvas.getContext('2d'); canvas.width = Math.round(Math.max(1, rect.width) * ratio); canvas.height = Math.round(Math.max(1, rect.height) * ratio); ctx.setTransform(1,0,0,1,0,0); if (usingSignaturePad) ctx.scale(ratio, ratio); }
    resizeCanvas(); if (usingSignaturePad) pad = new SignaturePad(canvas, { minWidth:1, maxWidth:2 }); else if (window.SimpleSignaturePad) pad = window.SimpleSignaturePad(canvas);
    function capture(){ if (!pad) { hidden.value=''; return; } try { if (pad.isEmpty && pad.isEmpty()) { hidden.value=''; return; } hidden.value = (pad.toDataURL ? pad.toDataURL('image/png') : canvas.toDataURL('image/png')); } catch (e) { hidden.value=''; } }
    clearB?.addEventListener('click', (e)=>{ e.preventDefault(); pad?.clear?.(); hidden.value=''; });
    form.addEventListener('submit', capture); window.addEventListener('resize', resizeCanvas);
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initSignaturePad); else initSignaturePad();
})();
</script>
<?php include __DIR__.'/footer.php'; ?>
