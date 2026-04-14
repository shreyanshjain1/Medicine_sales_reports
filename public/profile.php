<?php
require_once __DIR__.'/../init.php'; require_login();
$u=user(); $ok=false; $err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $name=trim(post('name','')); $email=normalize_email(trim(post('email','')));
  if($name===''||$email==='') $err='Name and email are required.';
  else{
    $stmt=$mysqli->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
    $stmt->bind_param('si', $email, $u['id']);
    $stmt->execute();
    $dupe=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if($dupe){
      $err='That email is already in use by another account.';
    } else {
      $stmt=$mysqli->prepare('UPDATE users SET name=?, email=? WHERE id=?');
      $stmt->bind_param('ssi',$name,$email,$u['id']);
      if($stmt->execute()){
        $_SESSION['user']['name']=$name; $_SESSION['user']['email']=$email; $ok=true;
        log_audit('profile_updated', 'user', (int)$u['id'], 'Profile details updated');
      } else $err='Update failed.';
      $stmt->close();
    }
  }
}
$title='Profile'; include __DIR__.'/header.php';
?>
<div class="card"><h2>My Profile</h2>
  <?php if($ok): ?><div class="alert success">Updated.</div><?php endif; ?>
  <?php if($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form max-640"><?php csrf_input(); ?>
    <div class="grid two"><label>Name<input name="name" value="<?= e(user()['name']) ?>" required></label><label>Email<input type="email" name="email" value="<?= e(user()['email']) ?>" required></label></div>
    <div class="inline-note">
      Need to update your password? <a href="<?= url('change_password.php') ?>">Open the security screen</a>.
    </div>
    <button class="btn primary">Save</button>
  </form>
</div>
<?php include __DIR__.'/footer.php'; ?>