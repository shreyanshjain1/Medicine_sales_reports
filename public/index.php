<?php
require_once __DIR__.'/../init.php';
if(is_logged_in()){ header('Location: '.url('dashboard.php')); exit; }
$error='';
$info='';
if(isset($_GET['reset']) && $_GET['reset']==='success'){
  $info='Password updated successfully. Please sign in with your new password.';
}
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $email=normalize_email(trim(post('email','')));
  $pass=(string)post('password','');
  $retryAfter=0;
  if(login_is_locked($email, $retryAfter)){
    $mins=max(1,(int)ceil($retryAfter/60));
    $error='Too many failed login attempts. Try again in about '.$mins.' minute(s).';
  } else {
    $stmt=$mysqli->prepare('SELECT id,name,email,password_hash,role,active FROM users WHERE email=? LIMIT 1');
    $stmt->bind_param('s',$email);
    $stmt->execute();
    $res=$stmt->get_result();
    if($u=$res->fetch_assoc()){
      if((int)$u['active'] === 1 && password_verify($pass,$u['password_hash'])){
        $_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>role_norm($u['role'])];
        record_login_attempt($email, true);
        log_audit('login_success', 'user', (int)$u['id'], 'Successful sign in');
        header('Location: '.url('dashboard.php')); exit;
      }
    }
    record_login_attempt($email, false);
    $error='Invalid email or password';
  }
}
?><!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css"></head>
<body class="light auth">
  <div class="login-wrap">
    <div class="card login-card">
      <h1 class="logo">Pharmastar</h1>
      <h2 class="subtitle">Reporting</h2>
      <?php if($info): ?><div class="alert success"><?= e($info) ?></div><?php endif; ?>
      <?php if($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="form compact">
        <?php csrf_input(); ?>
        <label>Email<input type="email" name="email" required placeholder="you@pharmastar.ph" autofocus></label>
        <label>Password<input type="password" name="password" required placeholder="••••••••"></label>
        <button class="btn primary block" type="submit">Sign in</button>
      </form>
      <div class="auth-links">
        <a href="<?= url('forgot_password.php') ?>">Forgot password?</a>
      </div>
    </div>
  </div>
</body></html>