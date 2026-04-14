<?php
require_once __DIR__.'/../init.php'; require_login();
$u=user(); $ok=false; $err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $name=trim(post('name','')); $email=trim(post('email','')); $pass=post('password',''); $pass2=post('password2','');
  if($name===''||$email==='') $err='Name/Email required.';
  elseif($pass!=='' && $pass!==$pass2) $err='Passwords do not match.';
  else{
    if($pass!==''){ $hash=password_hash($pass, PASSWORD_BCRYPT); $stmt=$mysqli->prepare('UPDATE users SET name=?, email=?, password_hash=? WHERE id=?'); $stmt->bind_param('sssi',$name,$email,$hash,$u['id']); }
    else { $stmt=$mysqli->prepare('UPDATE users SET name=?, email=? WHERE id=?'); $stmt->bind_param('ssi',$name,$email,$u['id']); }
    if($stmt->execute()){ $_SESSION['user']['name']=$name; $_SESSION['user']['email']=$email; $ok=true; } else $err='Update failed.';
  }
}
$title='Profile'; include __DIR__.'/header.php';
?>
<div class="card"><h2>My Profile</h2>
  <?php if($ok): ?><div class="alert success">Updated.</div><?php endif; ?>
  <?php if($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form"><?php csrf_input(); ?>
    <div class="grid two"><label>Name<input name="name" value="<?= e(user()['name']) ?>" required></label><label>Email<input type="email" name="email" value="<?= e(user()['email']) ?>" required></label></div>
    <div class="grid two"><label>New Password<input type="password" name="password"></label><label>Confirm Password<input type="password" name="password2"></label></div>
    <button class="btn primary">Save</button>
  </form>
</div>
<?php include __DIR__.'/footer.php'; ?>