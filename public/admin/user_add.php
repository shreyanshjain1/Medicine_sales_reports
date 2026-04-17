<?php
require_once __DIR__.'/../../init.php'; require_manager();
$districtManagers = [];
if ($res = $mysqli->query("SELECT id,name,email FROM users WHERE role='district_manager' AND active=1 ORDER BY name ASC")) {
  while ($r = $res->fetch_assoc()) $districtManagers[] = $r;
}
$errors=[]; $ok=false; $inviteLink='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $name=trim(post('name',''));
  $email=normalize_email(trim(post('email','')));
  $role=post('role','employee');
  $pass=(string)post('password','');
  $dmId = (int)post('district_manager_id', 0);
  $useInvite = (int)post('use_invite', 1) === 1;
  if($name===''||$email==='') $errors[]='Name and email are required.';
  if(!$useInvite && $pass==='') $errors[]='Password is required when invite flow is disabled.';
  if(!$errors && !$useInvite){ $policyErrors=[]; if(!password_meets_policy($pass,$policyErrors)) $errors = array_merge($errors,$policyErrors); }
  if(!$errors){
    $effectivePassword = $useInvite ? ('Invite' . random_int(100000,999999) . '!x') : $pass;
    $hash=password_hash($effectivePassword, PASSWORD_BCRYPT);
    $forceChange = $useInvite ? 1 : 0;
    if ($role === 'employee') {
      $stmt=$mysqli->prepare('INSERT INTO users (name,email,password_hash,role,district_manager_id,force_password_change,invited_at,invite_expires_at) VALUES (?,?,?,?,?,?,NOW(),DATE_ADD(NOW(), INTERVAL 7 DAY))');
      $stmt->bind_param('ssssii',$name,$email,$hash,$role,$dmId,$forceChange);
    } else {
      $stmt=$mysqli->prepare('INSERT INTO users (name,email,password_hash,role,district_manager_id,force_password_change,invited_at,invite_expires_at) VALUES (?,?,?, ?, NULL, ?, NOW(), DATE_ADD(NOW(), INTERVAL 7 DAY))');
      $stmt->bind_param('ssssi',$name,$email,$hash,$role,$forceChange);
    }
    if($stmt && $stmt->execute()) {
      $ok=true;
      $newUserId=(int)$stmt->insert_id;
      log_audit('user_created','user',$newUserId,$useInvite ? 'User invited by manager' : 'User account created by manager');
      if ($useInvite) {
        $token = create_password_reset_token($newUserId, 24*7);
        $inviteLink = $token['url'] ?? '';
      }
    } else {
      $errors[]='Failed to create user.';
    }
  }
}
$title='Add User'; include __DIR__.'/../header.php';
?>
<div class="card"><h2>Add User</h2>
  <?php if($ok): ?><div class="alert success">User created.<?php if($inviteLink): ?><br><strong>Invite Link:</strong> <a href="<?= e($inviteLink) ?>"><?= e($inviteLink) ?></a><?php endif; ?></div><?php endif; ?>
  <?php if($errors): ?><div class="alert"><?php foreach($errors as $e) echo '<div>'.e($e).'</div>'; ?></div><?php endif; ?>
  <form method="post" class="form"><?php csrf_input(); ?>
    <div class="grid two"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label></div>
    <div class="grid two"><label>Manual Password<input type="text" name="password" placeholder="Only needed if invite is disabled"></label>
      <label>Role<select name="role" id="roleSel"><option value="employee">Employee</option><option value="district_manager">District Manager</option><option value="manager">Manager</option></select></label></div>
    <div class="grid two"><label><input type="checkbox" name="use_invite" value="1" checked> Use invite flow and require password setup on first login</label><div class="muted">When enabled, the app will generate a one-time invite link instead of relying on a permanent admin-set password.</div></div>
    <div class="grid two" id="dmWrap" style="display:none"><label>District Manager (for this Employee)
      <select name="district_manager_id" id="dmSel"><option value="0">-- None --</option><?php foreach($districtManagers as $dm): ?><option value="<?= (int)$dm['id'] ?>"><?= e($dm['name']) ?> (<?= e($dm['email']) ?>)</option><?php endforeach; ?></select>
    </label><div class="muted" style="padding-top:1.6rem">Only Employees can be assigned under a District Manager.</div></div>
    <script>(function(){const roleSel=document.getElementById('roleSel');const dmWrap=document.getElementById('dmWrap');const dmSel=document.getElementById('dmSel');function sync(){const isEmp=(roleSel.value==='employee');dmWrap.style.display=isEmp?'':'none';if(!isEmp) dmSel.value='0';}roleSel.addEventListener('change',sync);sync();})();</script>
    <button class="btn primary">Create</button>
  </form>
</div>
<?php include __DIR__.'/../footer.php'; ?>
