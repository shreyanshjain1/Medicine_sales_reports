<?php
require_once __DIR__.'/../init.php';
if(is_logged_in()){ header('Location: '.url('dashboard.php')); exit; }
$error='';
<<<<<<< HEAD

function login_attempts_recent(string $email, string $ip): int {
  global $mysqli;
  $stmt = $mysqli->prepare('SELECT COUNT(*) c FROM login_attempts WHERE email=? AND ip_address=? AND was_successful=0 AND created_at >= (NOW() - INTERVAL 15 MINUTE)');
  if (!$stmt) return 0;
  $stmt->bind_param('ss', $email, $ip);
  $stmt->execute();
  $c = (int)($stmt->get_result()->fetch_assoc()['c'] ?? 0);
  $stmt->close();
  return $c;
}
function log_login_attempt(string $email, string $ip, bool $success): void {
  global $mysqli;
  $stmt = $mysqli->prepare('INSERT INTO login_attempts (email, ip_address, was_successful) VALUES (?,?,?)');
  if (!$stmt) return;
  $s = $success ? 1 : 0;
  $stmt->bind_param('ssi', $email, $ip, $s);
  @$stmt->execute();
  $stmt->close();
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $email=trim((string)post('email',''));
  $pass=(string)post('password','');
  $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 0, 64);
  if (login_attempts_recent($email, $ip) >= 5) {
    $error='Too many failed attempts. Please wait 15 minutes and try again.';
  } else {
    $stmt=$mysqli->prepare('SELECT id,name,email,password_hash,role,active FROM users WHERE email=? LIMIT 1');
    $stmt->bind_param('s',$email);
    $stmt->execute();
    $res=$stmt->get_result();
    if($u=$res->fetch_assoc()){
      if((int)$u['active'] === 1 && password_verify($pass,$u['password_hash'])){
        $_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>role_norm($u['role'])];
        log_login_attempt($email, $ip, true);
        log_audit('login_success', 'user', (int)$u['id'], 'User signed in');
        header('Location: '.url('dashboard.php')); exit;
      }
    }
    log_login_attempt($email, $ip, false);
    $error='Invalid email or password';
  }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login · <?= e(APP_NAME) ?></title>
<link rel="stylesheet" href="assets/style.css">
</head>
<body class="light auth">
  <div class="login-wrap">
    <div class="card login-card">
      <div class="eyebrow">Business reporting workspace</div>
      <h1 class="logo"><?= e(COMPANY_NAME) ?></h1>
      <h2 class="subtitle"><?= e(APP_NAME) ?></h2>
      <?php if($error): ?><div class="alert danger"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="form compact">
        <?php csrf_input(); ?>
        <label>Email<input type="email" name="email" required placeholder="you@company.com" autofocus></label>
        <label>Password<input type="password" name="password" required placeholder="••••••••"></label>
        <button class="btn primary block" type="submit">Sign in</button>
      </form>
      <p class="subtle" style="margin-top:14px">Use the secured setup script only during first-time installation.</p>
    </div>
  </div>
</body>
</html>
=======
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $email=trim(post('email','')); $pass=(string)post('password','');
  $stmt=$mysqli->prepare('SELECT id,name,email,password_hash,role,active FROM users WHERE email=?');
  $stmt->bind_param('s',$email); $stmt->execute(); $res=$stmt->get_result();
  if($u=$res->fetch_assoc()){
    if($u['active'] && password_verify($pass,$u['password_hash'])){
      $_SESSION['user']=['id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>role_norm($u['role'])];
      header('Location: '.url('dashboard.php')); exit;
    }
  }
  $error='Invalid email or password';
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
      <?php if($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>
      <form method="post" class="form compact">
        <?php csrf_input(); ?>
        <label>Email<input type="email" name="email" required placeholder="you@pharmastar.ph" autofocus></label>
        <label>Password<input type="password" name="password" required placeholder="••••••••"></label>
        <button class="btn primary block" type="submit">Sign in</button>
      </form>
      
    </div>
  </div>
</body></html>
>>>>>>> 37d1d03e21f7806a028237f4c9fce390fa63d02d
