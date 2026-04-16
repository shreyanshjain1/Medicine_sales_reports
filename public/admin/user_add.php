<?php
require_once __DIR__.'/../../init.php'; require_manager();
$districtManagers = [];
if ($res = $mysqli->query("SELECT id,name,email FROM users WHERE role='district_manager' AND active=1 ORDER BY name ASC")) {
  while ($r = $res->fetch_assoc()) $districtManagers[] = $r;
}
$errors=[]; $ok=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $name=trim(post('name','')); $email=trim(post('email','')); $role=post('role','employee'); $pass=post('password','');
  $dmId = (int)post('district_manager_id', 0);
  if($name===''||$email===''||$pass==='') $errors[]='All fields are required.';
  if(!$errors){
    $hash=password_hash($pass, PASSWORD_BCRYPT);
    if ($role === 'employee') {
      $stmt=$mysqli->prepare('INSERT INTO users (name,email,password_hash,role,district_manager_id) VALUES (?,?,?,?,?)');
      $stmt->bind_param('ssssi',$name,$email,$hash,$role,$dmId);
    } else {
      $stmt=$mysqli->prepare('INSERT INTO users (name,email,password_hash,role,district_manager_id) VALUES (?,?,?, ?, NULL)');
      $stmt->bind_param('ssss',$name,$email,$hash,$role);
    }
    if($stmt->execute()) { $ok=true; $newUserId=(int)$stmt->insert_id; log_audit('user_created','user',$newUserId,'User account created by manager'); } else $errors[]='Failed to create user.';
  }
}
$title='Add User'; include __DIR__.'/../header.php';
?>
<div class="card"><h2>Add User</h2>
  <?php if($ok): ?><div class="alert success">User created.</div><?php endif; ?>
  <?php if($errors): ?><div class="alert"><?php foreach($errors as $e) echo '<div>'.e($e).'</div>'; ?></div><?php endif; ?>
  <form method="post" class="form"><?php csrf_input(); ?>
    <div class="grid two"><label>Name<input name="name" required></label><label>Email<input type="email" name="email" required></label></div>
    <div class="grid two"><label>Password<input type="text" name="password" required></label>
      <label>Role
        <select name="role" id="roleSel">
          <option value="employee">Employee</option>
          <option value="district_manager">District Manager</option>
          <option value="manager">Manager</option>
        </select>
      </label>
    </div>

    <div class="grid two" id="dmWrap" style="display:none">
      <label>District Manager (for this Employee)
        <select name="district_manager_id" id="dmSel">
          <option value="0">-- None --</option>
          <?php foreach($districtManagers as $dm): ?>
            <option value="<?= (int)$dm['id'] ?>"><?= e($dm['name']) ?> (<?= e($dm['email']) ?>)</option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="muted" style="padding-top:1.6rem">
        Only Employees can be assigned under a District Manager.
      </div>
    </div>

    <script>
      (function(){
        const roleSel = document.getElementById('roleSel');
        const dmWrap = document.getElementById('dmWrap');
        const dmSel = document.getElementById('dmSel');
        function sync(){
          const isEmp = (roleSel.value === 'employee');
          dmWrap.style.display = isEmp ? '' : 'none';
          if (!isEmp) dmSel.value = '0';
        }
        roleSel.addEventListener('change', sync);
        sync();
      })();
    </script>
    <button class="btn primary">Create</button>
  </form>
</div>
<?php include __DIR__.'/../footer.php'; ?>