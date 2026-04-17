<?php
if (!function_exists('url')) { function url($path=''){ return rtrim(BASE_URL_EFFECTIVE,'/') . '/' . ltrim($path,'/'); } }
if (!function_exists('is_logged_in')) { function is_logged_in(){ return isset($_SESSION['user']); } }
if (!function_exists('user')) { function user(){ return $_SESSION['user'] ?? null; } }
if (!function_exists('role_norm')) {
  function role_norm(?string $role): string {
    $r = strtolower(trim((string)$role));
    if ($r === 'district manager' || $r === 'district-manager' || $r === 'districtmanager') return 'district_manager';
    if ($r === 'dm') return 'district_manager';
    if ($r === 'mgr') return 'manager';
    return $r;
  }
}
if (!function_exists('my_role')) { function my_role(): string { return role_norm(user()['role'] ?? ''); } }
if (!function_exists('is_manager')) { function is_manager(): bool { $r = my_role(); return $r === 'manager' || $r === 'admin'; } }
if (!function_exists('is_district_manager')) { function is_district_manager(): bool { return my_role() === 'district_manager'; } }
if (!function_exists('is_employee')) { function is_employee(): bool { return my_role() === 'employee'; } }
if (!function_exists('e')) { function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); } }
if (!function_exists('post')) { function post($k,$d=null){ return $_POST[$k] ?? $d; } }
if (!function_exists('getv')) { function getv($k,$d=null){ return $_GET[$k] ?? $d; } }
if (!function_exists('normalize_email')) { function normalize_email(string $email): string { return strtolower(trim($email)); } }
if (!function_exists('client_ip')) { function client_ip(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),0,64); } }
if (!function_exists('csrf_token')) { function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return hash_hmac('sha256', $_SESSION['csrf'], CSRF_SECRET); } }
if (!function_exists('csrf_input')) { function csrf_input(){ echo '<input type="hidden" name="_token" value="'.e(csrf_token()).'">'; } }
if (!function_exists('csrf_verify')) { function csrf_verify(){ if($_SERVER['REQUEST_METHOD']==='POST'){ $s=$_POST['_token']??''; $ok=hash_equals(hash_hmac('sha256', $_SESSION['csrf']??'', CSRF_SECRET), $s); if(!$ok){ http_response_code(400); die('Invalid CSRF.'); } } } }
if (!function_exists('csrf_validate')) { function csrf_validate() { csrf_verify(); } }
if (!function_exists('paginate')) { function paginate($total,$per=12,$page=1){ $pages=max(1,(int)ceil($total/$per)); $page=max(1,min($pages,(int)$page)); $off=($page-1)*$per; return [$page,$pages,$off,$per]; } }
if (!function_exists('is_assigned_to_district_manager')) {
  function is_assigned_to_district_manager(int $employee_id, int $district_manager_id): bool {
    global $mysqli;
    if ($employee_id <= 0 || $district_manager_id <= 0) return false;
    $stmt = $mysqli->prepare('SELECT 1 FROM users WHERE id=? AND district_manager_id=? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ii', $employee_id, $district_manager_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return !!$row;
  }
}
if (!function_exists('reports_scope_where')) {
  function reports_scope_where(string $alias='r'): string {
    $me = user(); $meId = (int)($me['id'] ?? 0);
    if ($meId <= 0) return '0';
    if (is_manager()) return '1';
    $a = preg_replace('/[^a-zA-Z0-9_]/','', $alias);
    if (is_district_manager()) return "({$a}.user_id = {$meId} OR {$a}.user_id IN (SELECT id FROM users WHERE district_manager_id = {$meId}))";
    return "{$a}.user_id = {$meId}";
  }
}
if (!function_exists('must_force_password_change')) { function must_force_password_change(): bool { return (int)($_SESSION['user']['force_password_change'] ?? 0) === 1; } }
if (!function_exists('app_login_user')) {
  function app_login_user(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user']=[
      'id'=>(int)$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>role_norm($u['role'] ?? ''),
      'wants_email_notifications'=>(int)($u['wants_email_notifications'] ?? 1),
      'force_password_change'=>(int)($u['force_password_change'] ?? 0),
    ];
    $_SESSION['auth_started_at']=time();
    $_SESSION['last_activity_at']=time();
  }
}
if (!function_exists('require_login')) {
  function require_login(){
    if(!is_logged_in()){ header('Location: '.url('index.php')); exit; }
    $current = basename((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (must_force_password_change() && !in_array($current, ['change_password.php','logout.php'], true)) {
      header('Location: '.url('auth/change_password.php?required=1')); exit;
    }
  }
}
if (!function_exists('require_manager')) { function require_manager(){ if(!is_logged_in() || !is_manager()){ http_response_code(403); exit('Forbidden'); } } }
if (!function_exists('login_is_locked')) {
  function login_is_locked(string $email, ?int &$retryAfter = null): bool {
    global $mysqli; $retryAfter = 0; $email = normalize_email($email); if ($email==='') return false; $ip = client_ip();
    $stmt = $mysqli->prepare("SELECT COUNT(*) AS fail_count, UNIX_TIMESTAMP(MIN(DATE_ADD(created_at, INTERVAL 15 MINUTE))) AS retry_until FROM login_attempts WHERE email=? AND ip_address=? AND was_successful=0 AND created_at >= (NOW() - INTERVAL 15 MINUTE)");
    if(!$stmt) return false; $stmt->bind_param('ss',$email,$ip); $stmt->execute(); $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    $count=(int)($row['fail_count']??0); if($count < 5) return false; $retryAfter=max(0, ((int)($row['retry_until']??time())) - time()); return true;
  }
}
if (!function_exists('record_login_attempt')) {
  function record_login_attempt(string $email, bool $success): void {
    global $mysqli; $email=normalize_email($email); if($email==='') return; $ip=client_ip(); $s=$success?1:0;
    $stmt=$mysqli->prepare('INSERT INTO login_attempts (email, ip_address, was_successful) VALUES (?,?,?)');
    if($stmt){ $stmt->bind_param('ssi',$email,$ip,$s); @$stmt->execute(); $stmt->close(); }
  }
}
if (!function_exists('password_meets_policy')) {
  function password_meets_policy(string $password, array &$errors = []): bool {
    $errors=[];
    if(strlen($password)<8) $errors[]='Password must be at least 8 characters.';
    if(!preg_match('/[A-Z]/',$password)) $errors[]='Include at least one uppercase letter.';
    if(!preg_match('/[a-z]/',$password)) $errors[]='Include at least one lowercase letter.';
    if(!preg_match('/[0-9]/',$password)) $errors[]='Include at least one number.';
    return !$errors;
  }
}
if (!function_exists('log_audit')) {
  function log_audit(string $action, ?string $entityType=null, ?int $entityId=null, ?string $details=null): void {
    global $mysqli; $uid=(int)(user()['id'] ?? 0); $ip=client_ip();
    $stmt=$mysqli->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)');
    if($stmt){ $uidVal = $uid > 0 ? $uid : null; @$stmt->bind_param('ississ',$uidVal,$action,$entityType,$entityId,$details,$ip); @$stmt->execute(); $stmt->close(); }
  }
}
if (!function_exists('create_password_reset_token')) {
  function create_password_reset_token(int $userId, int $ttlHours = 24): ?array {
    global $mysqli;
    if($userId<=0) return null;
    $selector=bin2hex(random_bytes(8));
    $validator=bin2hex(random_bytes(32));
    $hash=hash('sha256',$validator);
    $expires=(new DateTime('+'.$ttlHours.' hours'))->format('Y-m-d H:i:s');
    $stmt=$mysqli->prepare('INSERT INTO password_reset_tokens (user_id, selector, token_hash, expires_at) VALUES (?,?,?,?)');
    if(!$stmt) return null;
    $stmt->bind_param('isss',$userId,$selector,$hash,$expires);
    if(!$stmt->execute()){ $stmt->close(); return null; }
    $id=(int)$stmt->insert_id; $stmt->close();
    return ['id'=>$id,'selector'=>$selector,'validator'=>$validator,'url'=>url('auth/reset_password.php?selector='.$selector.'&validator='.$validator)];
  }
}
if (!function_exists('consume_password_reset_token')) {
  function consume_password_reset_token(string $selector, string $validator): ?array {
    global $mysqli;
    $stmt=$mysqli->prepare('SELECT * FROM password_reset_tokens WHERE selector=? AND used_at IS NULL AND expires_at > NOW() LIMIT 1');
    if(!$stmt) return null;
    $stmt->bind_param('s',$selector);
    $stmt->execute();
    $row=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if(!$row) return null;
    if(!hash_equals((string)$row['token_hash'], hash('sha256',$validator))) return null;
    return $row;
  }
}
if (!function_exists('mark_password_reset_used')) {
  function mark_password_reset_used(int $id): void {
    global $mysqli;
    $stmt=$mysqli->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=? LIMIT 1');
    if($stmt){ $stmt->bind_param('i',$id); @$stmt->execute(); $stmt->close(); }
  }
}
