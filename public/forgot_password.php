<?php
require_once __DIR__.'/../init.php';
if(is_logged_in()){ header('Location: '.url('dashboard.php')); exit; }
$title='Forgot Password';
$error=''; $ok=''; $resetLink='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $email = normalize_email((string)post('email',''));
  if($email===''){
    $error='Email is required.';
  } else {
    $stmt = $mysqli->prepare('SELECT id,name,email,active FROM users WHERE email=? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if($u && (int)$u['active']===1){
      $token = create_password_reset_token((int)$u['id']);
      $resetLink = url('reset_password.php?selector=' . urlencode($token['selector']) . '&validator=' . urlencode($token['validator']));
      log_audit('password_reset_requested', 'user', (int)$u['id'], 'Password reset requested');
    }
    $ok='If the account exists and is active, a password reset link has been generated.';
  }
}
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css"></head>
<body class="light auth">
  <div class="login-wrap">
    <div class="card login-card wide">
      <h1 class="logo">Forgot password</h1>
      <p class="subtitle">Generate a secure reset link for your account.</p>
      <?php if($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if($ok): ?><div class="alert success"><?= e($ok) ?></div><?php endif; ?>
      <form method="post" class="form compact">
        <?php csrf_input(); ?>
        <label>Email<input type="email" name="email" required placeholder="you@pharmastar.ph" autofocus></label>
        <button class="btn primary block" type="submit">Generate reset link</button>
      </form>
      <?php if($resetLink): ?>
        <div class="inline-note">
          <strong>Reset link</strong><br>
          <a href="<?= e($resetLink) ?>"><?= e($resetLink) ?></a>
        </div>
      <?php endif; ?>
      <div class="auth-links">
        <a href="<?= url('index.php') ?>">Back to sign in</a>
      </div>
    </div>
  </div>
</body></html>