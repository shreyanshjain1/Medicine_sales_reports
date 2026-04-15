<?php
require_once __DIR__.'/../init.php'; require_login();
$id=(int)getv('id',0);
$stmt=$mysqli->prepare('SELECT * FROM reports WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$r){ http_response_code(404); exit('Not found'); }
if(!is_manager() && (int)$r['user_id']!== (int)user()['id']){ http_response_code(403); exit('Forbidden'); }
if(($r['status'] ?? 'pending')==='approved' && !is_manager()){ http_response_code(403); exit('Approved reports cannot be edited.'); }
$errors=[]; $warnings=[]; $duplicates=[]; $ok=false;
$doctorMasterRecords = fetch_doctor_master_records();
$medicineOptions = fetch_master_options('medicines_master');
$hospitalOptions = fetch_master_options('hospitals_master');
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $doctor_name=trim((string)post('doctor_name','')); $doctor_email=trim((string)post('doctor_email','')) ?: 'NA';
  $purpose=trim((string)post('purpose','')); $medicine_name=trim((string)post('medicine_name','')); $hospital_name=trim((string)post('hospital_name',''));
  $visit_dt=trim((string)post('visit_datetime','')); $summary=trim((string)post('summary','')); $remarks=trim((string)post('remarks',''));
  $status = (($r['status'] ?? 'pending') === 'approved') ? 'approved' : 'pending';
  $attachment_path = normalize_upload_path($r['attachment_path'] ?? null);
  $newAttachment = save_uploaded_attachment($_FILES['attachment'] ?? [], (int)user()['id'], $errors);
  if ($newAttachment) $attachment_path = $newAttachment;
  if($doctor_name==='' || $visit_dt==='') $errors[]='Doctor name and visit date/time are required.';
  $warnings = report_quality_checks([
    'purpose' => $purpose,
    'medicine_name' => $medicine_name,
    'hospital_name' => $hospital_name,
    'summary' => $summary,
    'remarks' => $remarks,
  ]);
  $duplicates = find_potential_duplicate_reports((int)user()['id'], $doctor_name, $visit_dt, $id);
  if(!$errors){
    $stmt=$mysqli->prepare('UPDATE reports SET doctor_name=?,doctor_email=?,purpose=?,medicine_name=?,hospital_name=?,visit_datetime=?,summary=?,remarks=?,attachment_path=?,status=? WHERE id=?');
    $stmt->bind_param('ssssssssssi',$doctor_name,$doctor_email,$purpose,$medicine_name,$hospital_name,$visit_dt,$summary,$remarks,$attachment_path,$status,$id);
    if($stmt->execute()){
      $ok=true;
      log_audit('report_updated', 'report', $id, 'Report edited');
      add_report_status_history($id, $r['status'] ?? null, $status, 'Report details updated');
      $r=array_merge($r,['doctor_name'=>$doctor_name,'doctor_email'=>$doctor_email,'purpose'=>$purpose,'medicine_name'=>$medicine_name,'hospital_name'=>$hospital_name,'visit_datetime'=>$visit_dt,'summary'=>$summary,'remarks'=>$remarks,'attachment_path'=>$attachment_path,'status'=>$status]);
    } else $errors[]='Failed to update report.';
    $stmt->close();
  }
}
$title='Edit Report #'.$id; include __DIR__.'/header.php';
?>
<div class="crm-hero"><div><h2>Edit Report #<?= (int)$r['id'] ?></h2><div class="subtle">Refine visit details before final approval.</div></div></div>
<div class="card">
  <?php if($ok): ?><div class="alert success">Report updated.</div><?php endif; ?>
  <?php if($errors): ?><div class="alert danger"><?php foreach($errors as $e) echo '<div>'.e($e).'</div>'; ?></div><?php endif; ?>
  <?php if($warnings): ?><div class="alert warning"><strong>Submission quality checks</strong><?php foreach($warnings as $w) echo '<div>'.e($w).'</div>'; ?></div><?php endif; ?>
  <?php if($duplicates): ?><div class="alert warning"><strong>Possible duplicate reports found</strong><?php foreach($duplicates as $dup) echo '<div>Report #'.(int)$dup['id'].' · '.e((string)$dup['doctor_name']).' · '.e((string)$dup['visit_datetime']).' · '.e((string)($dup['status'] ?: 'pending')).'</div>'; ?></div><?php endif; ?>
  <form method="post" class="form" enctype="multipart/form-data"><?php csrf_input(); ?>
    <div class="grid two">
      <label>Load From Doctor Master
        <select id="master_doctor_select">
          <option value="">Choose doctor from master list</option>
          <?php foreach($doctorMasterRecords as $doc): ?>
            <option value="<?= (int)$doc['id'] ?>" data-name="<?= e($doc['doctor_name']) ?>" data-email="<?= e($doc['email']) ?>" data-hospital="<?= e($doc['hospital_name']) ?>"><?= e($doc['doctor_name']) ?><?= !empty($doc['city']) ? ' · '.e($doc['city']) : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Doctor Name<input id="doctor_name_input" name="doctor_name" value="<?= e($r['doctor_name']) ?>" required></label>
      <label>Doctor Email<input id="doctor_email_input" type="email" name="doctor_email" value="<?= e($r['doctor_email']) ?>"></label>
      <label>Purpose<input name="purpose" value="<?= e($r['purpose']) ?>"></label>
      <label>Medicine<input list="medicine_master_list" name="medicine_name" value="<?= e($r['medicine_name']) ?>"></label>
      <label>Hospital/Clinic<input list="hospital_master_list" id="hospital_name_input" name="hospital_name" value="<?= e($r['hospital_name']) ?>"></label>
      <label>Visit Datetime<input type="datetime-local" name="visit_datetime" value="<?= e(str_replace(' ','T',$r['visit_datetime'])) ?>" required></label>
    </div>
    <datalist id="medicine_master_list"><?php foreach($medicineOptions as $opt): ?><option value="<?= e($opt['label']) ?>"></option><?php endforeach; ?></datalist>
    <datalist id="hospital_master_list"><?php foreach($hospitalOptions as $opt): ?><option value="<?= e($opt['label']) ?>"></option><?php endforeach; ?></datalist>
    <label>Summary<textarea name="summary" rows="4"><?= e($r['summary']) ?></textarea></label>
    <label>Remarks<textarea name="remarks" rows="3"><?= e($r['remarks']) ?></textarea></label>
    <p>Current Status: <span class="badge <?= e($r['status'] ?: 'pending') ?>"><?= e($r['status'] ?: 'pending') ?></span></p>
    <?php if(!empty($r['attachment_path'])): ?><p>Attachment: <a target="_blank" href="<?= e(ATTACH_URL.'/'.basename((string)$r['attachment_path'])) ?>">Download current attachment</a></p><?php endif; ?>
    <label>Replace / Add Attachment<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"></label>
    <div class="actions-inline"><button class="btn primary" type="submit">Save Changes</button><a class="btn" href="report_view.php?id=<?= (int)$r['id'] ?>">Back</a></div>
  </form>
</div>
<script>
(function(){
  const pick = document.getElementById('master_doctor_select');
  if (!pick) return;
  pick.addEventListener('change', function(){
    const opt = this.options[this.selectedIndex];
    if (!opt || !opt.value) return;
    const nameEl = document.getElementById('doctor_name_input');
    const emailEl = document.getElementById('doctor_email_input');
    const hospEl = document.getElementById('hospital_name_input');
    if (nameEl) nameEl.value = opt.getAttribute('data-name') || nameEl.value;
    if (emailEl) emailEl.value = (opt.getAttribute('data-email') || emailEl.value || 'NA');
    if (hospEl && !hospEl.value) hospEl.value = opt.getAttribute('data-hospital') || hospEl.value;
  });
})();
</script>
<?php include __DIR__.'/footer.php'; ?>
