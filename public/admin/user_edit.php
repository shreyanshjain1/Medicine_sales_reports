<?php
require_once __DIR__ . '/../../init.php'; require_manager();
$editId=(int)getv('id',0); if($editId<=0){ http_response_code(400); exit('Invalid ID'); }
$resUser=$mysqli->query("SELECT id,name,email,role,active,district_manager_id,force_password_change FROM users WHERE id={$editId} LIMIT 1"); if(!$resUser){ http_response_code(500); exit('DB error'); }
$row=$resUser->fetch_assoc(); if(!$row){ http_response_code(404); exit('Not found'); }
$editUser=$row;
$districtManagers=[]; if ($res = $mysqli->query("SELECT id,name,email FROM users WHERE role='district_manager' AND active=1 ORDER BY name ASC")) { while ($r = $res->fetch_assoc()) $districtManagers[] = $r; }
$ok=false; $errors=[];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $newName=trim((string)post('name','')); $newEmail=normalize_email(trim((string)post('email',''))); $newRole=(string)post('role','employee'); $newPass=(string)post('password',''); $newDmId=(int)post('district_manager_id',0); $forceChange=(int)post('force_password_change',0);
  if ($newName === '' || $newEmail === '') $errors[]='Name and Email required.';
  $dmFinal = ($newRole === 'employee' && $newDmId > 0) ? $newDmId : 0;
  if (!$errors && $newPass !== '') { $policyErrors=[]; if(!password_meets_policy($newPass,$policyErrors)) $errors=array_merge($errors,$policyErrors); }
  if(!$errors){
    if($newPass!==''){
      $hash=password_hash($newPass, PASSWORD_BCRYPT);
      $stmt=$mysqli->prepare("UPDATE users SET name=?, email=?, role=?, district_manager_id=CASE WHEN ?=0 THEN NULL ELSE ? END, password_hash=?, force_password_change=? WHERE id=?");
      $stmt->bind_param('sssiisii',$newName,$newEmail,$newRole,$dmFinal,$dmFinal,$hash,$forceChange,$editId);
    } else {
      $stmt=$mysqli->prepare("UPDATE users SET name=?, email=?, role=?, district_manager_id=CASE WHEN ?=0 THEN NULL ELSE ? END, force_password_change=? WHERE id=?");
      $stmt->bind_param('sssiiii',$newName,$newEmail,$newRole,$dmFinal,$dmFinal,$forceChange,$editId);
    }
    if($stmt && $stmt->execute()){
      $ok=true; log_audit('user_updated','user',$editId,'User account updated by manager');
      $editUser=['id'=>$editId,'name'=>$newName,'email'=>$newEmail,'role'=>$newRole,'district_manager_id'=>$dmFinal?:null,'force_password_change'=>$forceChange,'active'=>$editUser['active']];
    } else { $errors[]='Failed to update.'; }
  }
}
$title='Edit User'; include __DIR__ . '/../header.php'; ?>
<div class="card"><h2>Edit User</h2>
<?php if ($ok): ?><div class="alert success">Updated.</div><?php endif; ?>
<?php if ($errors): ?><div class="alert"><?php foreach ($errors as $e) echo '<div>'.e($e).'</div>'; ?></div><?php endif; ?>
<form method="post" class="form"><?php csrf_input(); ?>
<div class="grid two"><label>Name<input name="name" value="<?= e($editUser['name']) ?>" required></label><label>Email<input type="email" name="email" value="<?= e($editUser['email']) ?>" required></label></div>
<div class="grid two"><label>New Password<input name="password" placeholder="Leave blank to keep"></label><label>Role<select name="role" id="roleSel"><option value="employee" <?= ($editUser['role'] === 'employee') ? 'selected' : '' ?>>Employee</option><option value="district_manager" <?= ($editUser['role'] === 'district_manager') ? 'selected' : '' ?>>District Manager</option><option value="manager" <?= ($editUser['role'] === 'manager') ? 'selected' : '' ?>>Manager</option></select></label></div>
<div class="grid two"><label><input type="checkbox" name="force_password_change" value="1" <?= !empty($editUser['force_password_change']) ? 'checked' : '' ?>> Require password change at next sign in</label><div class="muted" style="padding-top:1.6rem">Useful after manual password changes or when converting a user to invite-style onboarding.</div></div>
<div class="grid two" id="dmWrap" style="display:none"><label>District Manager (for this Employee)<select name="district_manager_id" id="dmSel"><option value="0">-- None --</option><?php foreach ($districtManagers as $dm): ?><option value="<?= (int)$dm['id'] ?>" <?= ((int)($editUser['district_manager_id'] ?? 0) === (int)$dm['id']) ? 'selected' : '' ?>><?= e($dm['name']) ?> (<?= e($dm['email']) ?>)</option><?php endforeach; ?></select></label><div class="muted" style="padding-top:1.6rem">Only Employees can be assigned under a District Manager.</div></div>
<script>(function(){const roleSel=document.getElementById('roleSel');const dmWrap=document.getElementById('dmWrap');const dmSel=document.getElementById('dmSel');function sync(){const isEmp=(roleSel.value==='employee');dmWrap.style.display=isEmp?'':'none';if(!isEmp) dmSel.value='0';}roleSel.addEventListener('change',sync);sync();})();</script>
<button class="btn primary">Save</button></form></div>
<?php include __DIR__ . '/../footer.php'; ?>
