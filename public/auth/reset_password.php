<?php
require_once __DIR__.'/../../init.php';
if(is_logged_in()){ header('Location: '.url('dashboard.php')); exit; }
$title='Reset Password';
$error='';
$selector = trim((string)getv('selector',''));
$validator = trim((string)getv('validator',''));
$row = ($selector!=='' && $validator!=='') ? consume_password_reset_token($selector, $validator) : null;
if(!$row){
  $error = 'This reset link is invalid or has expired.';
}
if($row && $_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $password = (string)post('password','');
  $password2 = (string)post('password2','');
  $policyErrors = [];
  if($password !== $password2){
    $error = 'Passwords do not match.';
  } elseif(!password_meets_policy($password, $policyErrors)){
    $error = implode(' ', $policyErrors);
  } else {
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $mysqli->prepare('UPDATE users SET password_hash=? WHERE id=? LIMIT 1');
    $uid = (int)$row['user_id'];
    $stmt->bind_param('si', $hash, $uid);
    if($stmt->execute()){
      mark_password_reset_used((int)$row['id']);
      $stmt->close();
      log_audit('password_reset_completed', 'user', $uid, 'Password reset completed via token');
      header('Location: '.url('index.php?reset=success')); exit;
    }
    $stmt->close();
    $error = 'Failed to update password.';
  }
}
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="<?= url('assets/style.css') ?>"></head>
<body class="light auth">
  <div class="login-wrap">
    <div class="card login-card wide">
      <h1 class="logo">Reset password</h1>
      <p class="subtitle">Choose a new secure password for your account.</p>
      <?php if($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if($row): ?>
      <form method="post" class="form compact">
        <?php csrf_input(); ?>
        <label>New Password<input type="password" name="password" required placeholder="At least 8 chars, upper/lower/number"></label>
        <label>Confirm Password<input type="password" name="password2" required placeholder="Repeat new password"></label>
        <button class="btn primary block" type="submit">Reset password</button>
      </form>
      <?php endif; ?>
      <div class="auth-links">
        <a href="<?= url('index.php') ?>">Back to sign in</a>
      </div>
    </div>
  </div>
</body></html>