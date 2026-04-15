<?php
if (!function_exists('url')) {
  function url($path=''){ return rtrim(BASE_URL_EFFECTIVE,'/') . '/' . ltrim($path,'/'); }
}
if (!function_exists('is_logged_in')) {
  function is_logged_in(){ return isset($_SESSION['user']); }
}
if (!function_exists('user')) {
  function user(){ return $_SESSION['user'] ?? null; }
}
if (!function_exists('require_login')) {
  function require_login(){ if(!is_logged_in()){ header('Location: '.url('index.php')); exit; } }
}
if (!function_exists('require_manager')) {
  function require_manager(){ if(!is_logged_in() || !is_manager()){ http_response_code(403); exit('Forbidden'); } }
}
if (!function_exists('role_norm')) {
  function role_norm(?string $role): string {
    $r = strtolower(trim((string)$role));
    if ($r === 'district manager' || $r === 'district-manager' || $r === 'districtmanager') return 'district_manager';
    if ($r === 'dm') return 'district_manager';
    if ($r === 'mgr') return 'manager';
    return $r;
  }
}
if (!function_exists('my_role')) {
  function my_role(): string { return role_norm(user()['role'] ?? ''); }
}
if (!function_exists('is_manager')) {
  function is_manager(): bool { $r = my_role(); return $r === 'manager' || $r === 'admin'; }
}
if (!function_exists('is_district_manager')) {
  function is_district_manager(): bool { return my_role() === 'district_manager'; }
}
if (!function_exists('is_employee')) {
  function is_employee(): bool { return my_role() === 'employee'; }
}
if (!function_exists('is_assigned_to_district_manager')) {
  function is_assigned_to_district_manager(int $employee_id, int $district_manager_id): bool {
    global $mysqli;
    if ($employee_id <= 0 || $district_manager_id <= 0) return false;
    $stmt = $mysqli->prepare('SELECT 1 FROM users WHERE id=? AND district_manager_id=? LIMIT 1');
    if (!$stmt) return false;
    $stmt->bind_param('ii', $employee_id, $district_manager_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return !!$row;
  }
}
if (!function_exists('can_view_user_reports')) {
  function can_view_user_reports(int $owner_user_id): bool {
    $me = user();
    if (!$me) return false;
    $meId = (int)($me['id'] ?? 0);
    if ($meId <= 0 || $owner_user_id <= 0) return false;
    if (is_manager()) return true;
    if ($meId === $owner_user_id) return true;
    if (is_district_manager()) return is_assigned_to_district_manager($owner_user_id, $meId);
    return false;
  }
}
if (!function_exists('reports_scope_where')) {
  function reports_scope_where(string $alias='r'): string {
    $me = user();
    $meId = (int)($me['id'] ?? 0);
    if ($meId <= 0) return '0';
    if (is_manager()) return '1';
    $a = preg_replace('/[^a-zA-Z0-9_]/','', $alias);
    if (is_district_manager()) return "({$a}.user_id = {$meId} OR {$a}.user_id IN (SELECT id FROM users WHERE district_manager_id = {$meId}))";
    return "{$a}.user_id = {$meId}";
  }
}
if (!function_exists('e')) {
  function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('post')) {
  function post($k,$d=null){ return $_POST[$k] ?? $d; }
}
if (!function_exists('getv')) {
  function getv($k,$d=null){ return $_GET[$k] ?? $d; }
}
if (!function_exists('csrf_token')) {
  function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return hash_hmac('sha256', $_SESSION['csrf'], CSRF_SECRET); }
}
if (!function_exists('csrf_input')) {
  function csrf_input(){ echo '<input type="hidden" name="_token" value="'.e(csrf_token()).'">'; }
}
if (!function_exists('csrf_verify')) {
  function csrf_verify(){ if($_SERVER['REQUEST_METHOD']==='POST'){ $s=$_POST['_token']??''; $ok=hash_equals(hash_hmac('sha256', $_SESSION['csrf']??'', CSRF_SECRET), $s); if(!$ok){ http_response_code(400); die('Invalid CSRF.'); } } }
}
if (!function_exists('csrf_validate')) {
  function csrf_validate() { csrf_verify(); }
}
if (!function_exists('paginate')) {
  function paginate($total,$per=12,$page=1){ $pages=max(1,(int)ceil($total/$per)); $page=max(1,min($pages,(int)$page)); $off=($page-1)*$per; return [$page,$pages,$off,$per]; }
}
