<?php
require_once __DIR__.'/../../init.php'; require_login();
$id=(int)getv('id',0);
$stmt=$mysqli->prepare('SELECT * FROM reports WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$r){ http_response_code(404); exit('Not found'); }
if(!is_manager() && (int)$r['user_id']!== (int)user()['id']){ http_response_code(403); exit('Forbidden'); }
if(($r['status'] ?? 'pending')==='approved' && !is_manager()){ http_response_code(403); exit('Approved reports cannot be edited.'); }
$errors=[]; $fieldErrors=[]; $warnings=[]; $duplicates=[]; $ok=false;
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
  if($doctor_name===''){ $errors[]='Doctor name is required.'; $fieldErrors['doctor_name']='Doctor name is required.'; }
  if($visit_dt===''){ $errors[]='Visit date/time is required.'; $fieldErrors['visit_datetime']='Visit date/time is required.'; }
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
$title='Edit Report #'.$id; include __DIR__.'/../header.php';
?>
<div class="crm-hero"><div><h2>Edit Report #<?= (int)$r['id'] ?></h2><div class="subtle">Refine visit details before final approval.</div></div></div>
<div class="card">
  <?php form_messages($errors, $warnings, $ok ? 'Report updated.' : ''); ?>
  <?php if($duplicates): ?><div class="alert warning"><strong>Possible duplicate reports found</strong><?php foreach($duplicates as $dup) echo '<div>Report #'.(int)$dup['id'].' · '.e((string)$dup['doctor_name']).' · '.e((string)$dup['visit_datetime']).' · '.e((string)($dup['status'] ?: 'pending')).'</div>'; ?></div><?php endif; ?>
  <form method="post" class="form crm-form" enctype="multipart/form-data"><?php csrf_input(); ?>
    <div class="grid two">
      <label class="form-field"><span class="form-label">Load From Doctor Master</span>
        <select class="form-control" id="master_doctor_select">
          <option value="">Choose doctor from master list</option>
          <?php foreach($doctorMasterRecords as $doc): ?>
            <option value="<?= (int)$doc['id'] ?>" data-name="<?= e($doc['doctor_name']) ?>" data-email="<?= e($doc['email']) ?>" data-hospital="<?= e($doc['hospital_name']) ?>"><?= e($doc['doctor_name']) ?><?= !empty($doc['city']) ? ' · '.e($doc['city']) : '' ?></option>
          <?php endforeach; ?>
        </select>
        <small class="field-hint">Choose a saved doctor to autofill name, email, and hospital.</small>
      </label>
      <?php render_text_input('Doctor Name', 'doctor_name', (string)$r['doctor_name'], ['required'=>true,'id'=>'doctor_name_input'], $fieldErrors); ?>
      <?php render_text_input('Doctor Email', 'doctor_email', (string)$r['doctor_email'], ['type'=>'email','id'=>'doctor_email_input'], $fieldErrors); ?>
      <?php render_text_input('Purpose', 'purpose', (string)$r['purpose'], ['placeholder'=>'Purpose of visit'], $fieldErrors); ?>
      <?php render_text_input('Medicine', 'medicine_name', (string)$r['medicine_name'], ['list'=>'medicine_master_list','placeholder'=>'Medicine discussed'], $fieldErrors); ?>
      <?php render_text_input('Hospital / Clinic', 'hospital_name', (string)$r['hospital_name'], ['list'=>'hospital_master_list','id'=>'hospital_name_input','placeholder'=>'Hospital or clinic'], $fieldErrors); ?>
      <?php render_text_input('Visit Datetime', 'visit_datetime', (string)str_replace(' ','T',$r['visit_datetime']), ['type'=>'datetime-local','required'=>true], $fieldErrors); ?>
    </div>
    <datalist id="medicine_master_list"><?php foreach($medicineOptions as $opt): ?><option value="<?= e($opt['label']) ?>"></option><?php endforeach; ?></datalist>
    <datalist id="hospital_master_list"><?php foreach($hospitalOptions as $opt): ?><option value="<?= e($opt['label']) ?>"></option><?php endforeach; ?></datalist>
    <?php render_textarea_input('Summary', 'summary', (string)$r['summary'], ['rows'=>4,'placeholder'=>'Key discussion points'], $fieldErrors); ?>
    <?php render_textarea_input('Remarks', 'remarks', (string)$r['remarks'], ['rows'=>3,'placeholder'=>'Follow-ups, commitments, or notes'], $fieldErrors); ?>
    <p>Current Status: <span class="badge <?= e($r['status'] ?: 'pending') ?>"><?= e($r['status'] ?: 'pending') ?></span></p>
    <?php if(!empty($r['attachment_path'])): ?><p>Attachment: <a target="_blank" href="<?= e(ATTACH_URL.'/'.basename((string)$r['attachment_path'])) ?>">Download current attachment</a></p><?php endif; ?>
    <label class="form-field"><span class="form-label">Replace / Add Attachment</span><input class="form-control" type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"><small class="field-hint">Allowed: PDF, JPG, JPEG, PNG</small></label>
    <div class="actions-inline form-actions"><button class="btn primary" type="submit">Save Changes</button><a class="btn" href="report_view.php?id=<?= (int)$r['id'] ?>">Back</a></div>
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
<?php include __DIR__.'/../footer.php'; ?>
