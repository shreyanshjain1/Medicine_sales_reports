<?php
require_once __DIR__.'/../init.php'; require_login();
$id=(int)getv('id',0);
<<<<<<< HEAD
$stmt=$mysqli->prepare('SELECT * FROM reports WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc(); $stmt->close();
if(!$r){ http_response_code(404); exit('Not found'); }
if(!is_manager() && (int)$r['user_id']!== (int)user()['id']){ http_response_code(403); exit('Forbidden'); }
if(($r['status'] ?? 'pending')==='approved' && !is_manager()){ http_response_code(403); exit('Approved reports cannot be edited.'); }
$errors=[]; $ok=false;
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
  if(!$errors){
    $stmt=$mysqli->prepare('UPDATE reports SET doctor_name=?,doctor_email=?,purpose=?,medicine_name=?,hospital_name=?,visit_datetime=?,summary=?,remarks=?,attachment_path=?,status=? WHERE id=?');
    $stmt->bind_param('ssssssssssi',$doctor_name,$doctor_email,$purpose,$medicine_name,$hospital_name,$visit_dt,$summary,$remarks,$attachment_path,$status,$id);
    if($stmt->execute()){
      $ok=true;
      log_audit('report_updated', 'report', $id, 'Report edited');
      $r=array_merge($r,['doctor_name'=>$doctor_name,'doctor_email'=>$doctor_email,'purpose'=>$purpose,'medicine_name'=>$medicine_name,'hospital_name'=>$hospital_name,'visit_datetime'=>$visit_dt,'summary'=>$summary,'remarks'=>$remarks,'attachment_path'=>$attachment_path,'status'=>$status]);
    } else $errors[]='Failed to update report.';
    $stmt->close();
=======
$stmt=$mysqli->prepare('SELECT * FROM reports WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $r=$stmt->get_result()->fetch_assoc();
if(!$r){ http_response_code(404); exit('Not found'); }
if(user()['role']!=='manager' && (int)$r['user_id']!==user()['id']){ http_response_code(403); exit('Forbidden'); }
if($r['status']==='approved' && user()['role']!=='manager'){ http_response_code(403); exit('Approved reports cannot be edited.'); }
$errors=[]; $ok=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $doctor_name=trim(post('doctor_name','')); $doctor_email=trim(post('doctor_email',''));
  $purpose=trim(post('purpose','')); $medicine_name=trim(post('medicine_name','')); $hospital_name=trim(post('hospital_name',''));
  $visit_dt=trim(post('visit_datetime','')); $summary=trim(post('summary','')); $remarks=trim(post('remarks',''));
  $status = $r['status']==='approved' ? 'approved' : 'pending';
  $attachment_path=$r['attachment_path'];
  if(isset($_FILES['attachment']) && $_FILES['attachment']['error']===UPLOAD_ERR_OK){
    if(!is_dir(ATTACH_DIR)) mkdir(ATTACH_DIR,0775,true);
    $ext=pathinfo($_FILES['attachment']['name'], PATHINFO_EXTENSION);
    $fn='att_' . user()['id'] . '_' . time() . '.' . $ext;
    move_uploaded_file($_FILES['attachment']['tmp_name'], ATTACH_DIR . '/' . $fn);
    $attachment_path = ATTACH_DIR . '/' . $fn;
  }
  if($doctor_name==='' || $visit_dt==='') $errors[]='Doctor Name and Visit Date/Time are required.';
  if(!$errors){
    $stmt=$mysqli->prepare('UPDATE reports SET doctor_name=?,doctor_email=?,purpose=?,medicine_name=?,hospital_name=?,visit_datetime=?,summary=?,remarks=?,attachment_path=?,status=? WHERE id=?');
    $stmt->bind_param('ssssssssssi',$doctor_name,$doctor_email,$purpose,$medicine_name,$hospital_name,$visit_dt,$summary,$remarks,$attachment_path,$status,$id);
    if($stmt->execute()){$ok=true; $r=array_merge($r,['doctor_name'=>$doctor_name,'doctor_email'=>$doctor_email,'purpose'=>$purpose,'medicine_name'=>$medicine_name,'hospital_name'=>$hospital_name,'visit_datetime'=>$visit_dt,'summary'=>$summary,'remarks'=>$remarks,'attachment_path'=>$attachment_path,'status'=>$status]);}
    else $errors[]='Failed to update.';
>>>>>>> 37d1d03e21f7806a028237f4c9fce390fa63d02d
  }
}
$title='Edit Report #'.$id; include __DIR__.'/header.php';
?>
<<<<<<< HEAD
<div class="crm-hero"><div><h2>Edit Report #<?= (int)$r['id'] ?></h2><div class="subtle">Refine visit details before final approval.</div></div></div>
<div class="card">
  <?php if($ok): ?><div class="alert success">Report updated.</div><?php endif; ?>
  <?php if($errors): ?><div class="alert danger"><?php foreach($errors as $e) echo '<div>'.e($e).'</div>'; ?></div><?php endif; ?>
=======
<div class="card">
  <h2>Edit Report #<?= (int)$r['id'] ?></h2>
  <?php if($ok): ?><div class="alert success">Updated.</div><?php endif; ?>
  <?php if($errors): ?><div class="alert"><?php foreach($errors as $e) echo '<div>'.e($e).'</div>'; ?></div><?php endif; ?>
>>>>>>> 37d1d03e21f7806a028237f4c9fce390fa63d02d
  <form method="post" class="form" enctype="multipart/form-data"><?php csrf_input(); ?>
    <div class="grid two">
      <label>Doctor Name<input name="doctor_name" value="<?= e($r['doctor_name']) ?>" required></label>
      <label>Doctor Email<input type="email" name="doctor_email" value="<?= e($r['doctor_email']) ?>"></label>
      <label>Purpose<input name="purpose" value="<?= e($r['purpose']) ?>"></label>
      <label>Medicine<input name="medicine_name" value="<?= e($r['medicine_name']) ?>"></label>
      <label>Hospital/Clinic<input name="hospital_name" value="<?= e($r['hospital_name']) ?>"></label>
      <label>Visit Datetime<input type="datetime-local" name="visit_datetime" value="<?= e(str_replace(' ','T',$r['visit_datetime'])) ?>" required></label>
    </div>
    <label>Summary<textarea name="summary" rows="4"><?= e($r['summary']) ?></textarea></label>
    <label>Remarks<textarea name="remarks" rows="3"><?= e($r['remarks']) ?></textarea></label>
<<<<<<< HEAD
    <p>Current Status: <span class="badge <?= e($r['status'] ?: 'pending') ?>"><?= e($r['status'] ?: 'pending') ?></span></p>
    <?php if(!empty($r['attachment_path'])): ?><p>Attachment: <a target="_blank" href="<?= e(ATTACH_URL.'/'.basename((string)$r['attachment_path'])) ?>">Download current attachment</a></p><?php endif; ?>
    <label>Replace / Add Attachment<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"></label>
    <div class="actions-inline"><button class="btn primary" type="submit">Save Changes</button><a class="btn" href="report_view.php?id=<?= (int)$r['id'] ?>">Back</a></div>
  </form>
</div>
<?php include __DIR__.'/footer.php'; ?>
=======
    <p>Current Status: <span class="badge <?= e($r['status']) ?>"><?= e($r['status']) ?></span></p>
    <?php if($r['attachment_path']): ?><p>Attachment: <a target="_blank" href="<?= e(ATTACH_URL.'/'.basename($r['attachment_path'])) ?>">Download</a></p><?php endif; ?>
    <label>Replace/Add Attachment<input type="file" name="attachment" accept=".pdf,.jpg,.jpeg,.png"></label>
    <div class="actions"><button class="btn primary" type="submit">Save Changes</button></div>
  </form>
</div>
<?php include __DIR__.'/footer.php'; ?>
>>>>>>> 37d1d03e21f7806a028237f4c9fce390fa63d02d
