<?php
require_once __DIR__.'/../../init.php';
if(is_logged_in()){ header('Location: '.url('dashboard.php')); exit; }
$title='Forgot Password';
$error=''; $ok=''; $resetLink=''; $mailNotice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $email = normalize_email((string)post('email',''));
  $ipRetryAfter = 0;
  $emailRetryAfter = 0;
  if (abuse_rate_limit_check('forgot_password_ip', client_ip_address(), 8, 900, $ipRetryAfter) || ($email !== '' && abuse_rate_limit_check('forgot_password_email', abuse_identifier_for_email($email), 3, 1800, $emailRetryAfter))) {
    $wait = max($ipRetryAfter, $emailRetryAfter);
    $mins = max(1, (int)ceil($wait / 60));
    $ok='If the account exists and is active, a password reset link has been generated.';
    $mailNotice='Too many reset requests were received. Please wait about ' . $mins . ' minute(s) before trying again.';
  } elseif($email===''){
    $error='Email is required.';
  } else {
    abuse_rate_limit_hit('forgot_password_ip', client_ip_address());
    abuse_rate_limit_hit('forgot_password_email', abuse_identifier_for_email($email));
    $stmt = $mysqli->prepare('SELECT id,name,email,active FROM users WHERE email=? LIMIT 1');
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $u = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if($u && (int)$u['active']===1){
      $token = create_password_reset_token((int)$u['id']);
      $resetLink = url('auth/reset_password.php?selector=' . urlencode($token['selector']) . '&validator=' . urlencode($token['validator']));
      $subject = app_name_value() . ' · Reset your password';
      $body = 'A password reset was requested for your account. Use the secure link below to set a new password. This link expires at ' . date('M d, Y h:i A', strtotime($token['expires_at'])) . '.';
      $html = notification_email_html('Reset your password', $body, $resetLink);
      $sent = send_app_mail((string)$u['email'], $subject, $html, $body, 'user', (int)$u['id']);
      $mailNotice = $sent ? 'A reset email was sent to the account email.' : 'Email delivery is not active yet, so the reset link is shown below for testing.';
      log_audit('password_reset_requested', 'user', (int)$u['id'], 'Password reset requested');
    }
    $ok='If the account exists and is active, a password reset link has been generated.';
  }
}
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title) ?> · <?= e(app_name_value()) ?></title>
<link rel="stylesheet" href="<?= url('assets/style.css') ?>"></head>
<body class="light auth">
  <div class="login-wrap">
    <div class="card login-card wide">
      <h1 class="logo">Forgot password</h1>
      <p class="subtitle">Generate a secure reset link for your account.</p>
      <?php if($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
      <?php if($ok): ?><div class="alert success"><?= e($ok) ?></div><?php endif; ?>
      <?php if($mailNotice): ?><div class="inline-note"><?= e($mailNotice) ?></div><?php endif; ?>
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