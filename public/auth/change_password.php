<?php
require_once __DIR__.'/../../init.php'; require_login();
$title='Change Password';
$ok=''; $err='';
$required = must_force_password_change() || getv('required','') === '1';
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $current = (string)post('current_password','');
  $password = (string)post('password','');
  $password2 = (string)post('password2','');
  $stmt = $mysqli->prepare('SELECT password_hash FROM users WHERE id=? LIMIT 1');
  $uid = (int)(user()['id'] ?? 0);
  $stmt->bind_param('i', $uid);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $policyErrors = [];
  if(!$required && (!$row || !password_verify($current, (string)$row['password_hash']))){
    $err = 'Current password is incorrect.';
  } elseif($password !== $password2){
    $err = 'New passwords do not match.';
  } elseif(!password_meets_policy($password, $policyErrors)){
    $err = implode(' ', $policyErrors);
  } else {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $mysqli->prepare('UPDATE users SET password_hash=?, force_password_change=0, password_changed_at=NOW(), first_login_at=COALESCE(first_login_at, NOW()) WHERE id=? LIMIT 1');
    $stmt->bind_param('si', $hash, $uid);
    if($stmt->execute()){
      $_SESSION['user']['force_password_change'] = 0;
      $ok = 'Password updated successfully.';
      log_audit('password_changed', 'user', $uid, $required ? 'Password set during required first login flow' : 'Password changed from profile security screen');
    } else {
      $err = 'Failed to update password.';
    }
    $stmt->close();
  }
}
include __DIR__.'/../header.php'; ?>
<div class="card">
  <div class="section-head">
    <div>
      <div class="eyebrow">Account Security</div>
      <h2><?= $required ? 'Set Your Password' : 'Change Password' ?></h2>
      <?php if($required): ?><div class="subtle">Your account requires a password update before you can continue.</div><?php endif; ?>
    </div>
  </div>
  <?php if($ok): ?><div class="alert success"><?= e($ok) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form max-640">
    <?php csrf_input(); ?>
    <?php if(!$required): ?><label>Current Password<input type="password" name="current_password" required></label><?php endif; ?>
    <label>New Password<input type="password" name="password" required placeholder="At least 8 chars, upper/lower/number"></label>
    <label>Confirm New Password<input type="password" name="password2" required></label>
    <button class="btn primary" type="submit"><?= $required ? 'Set password and continue' : 'Update password' ?></button>
  </form>
</div>
<?php include __DIR__.'/../footer.php'; ?>
