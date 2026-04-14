<?php
$configFile = __DIR__ . '/config.php';
if (!file_exists($configFile)) {
  http_response_code(500);
  echo 'Missing config.php. Copy config.example.php to config.php and update your database settings.';
  exit;
}
require_once $configFile;

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

define('APP_RUNTIME_STARTED_AT', microtime(true));

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
  http_response_code(500);
  die('DB Connection failed: ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

function app_env(): string { return defined('APP_ENV') ? strtolower((string)APP_ENV) : 'production'; }
function is_dev_env(): bool { return in_array(app_env(), ['local','development','dev','staging'], true); }
function can_run_setup(): bool { return defined('ALLOW_SETUP') && ALLOW_SETUP === true; }
function can_use_dev_tools(): bool { return defined('ALLOW_DEV_TOOLS') && ALLOW_DEV_TOOLS === true && is_dev_env(); }

function _col_exists(string $table, string $column): bool {
  global $mysqli;
  $stmt = $mysqli->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
  if (!$stmt) return false;
  $stmt->bind_param('ss', $table, $column);
  $stmt->execute();
  $ok = (bool)$stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $ok;
}

function _try_sql(string $sql): void {
  global $mysqli;
  try { @$mysqli->query($sql); } catch (Throwable $e) {}
}

function ensure_core_schema(): void {
  if (!empty($_SESSION['_schema_migrated_v8'])) return;
  $_SESSION['_schema_migrated_v8'] = 1;

  _try_sql("CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(120) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id INT NULL,
    details TEXT NULL,
    ip_address VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user (user_id),
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  _try_sql("CREATE TABLE IF NOT EXISTS report_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    actor_user_id INT NULL,
    old_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rsh_report (report_id),
    INDEX idx_rsh_actor (actor_user_id),
    CONSTRAINT fk_rsh_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  _try_sql("CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(64) NOT NULL,
    was_successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_attempts_email_ip (email, ip_address, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  _try_sql("CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector CHAR(16) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_password_reset_selector (selector),
    INDEX idx_password_reset_user (user_id),
    INDEX idx_password_reset_expiry (expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  _try_sql("CREATE TABLE IF NOT EXISTS medicines_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    category VARCHAR(120) NULL,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_medicines_name (name),
    INDEX idx_medicines_active_name (active, name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  _try_sql("CREATE TABLE IF NOT EXISTS hospitals_master (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(200) NOT NULL,
    city VARCHAR(120) NULL,
    address VARCHAR(255) NULL,
    notes TEXT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_hospitals_name (name),
    INDEX idx_hospitals_city_name (city, name),
    INDEX idx_hospitals_active_name (active, name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  if (master_table_ready('doctors_masterlist')) {
    if (!_col_exists('doctors_masterlist','active')) _try_sql("ALTER TABLE doctors_masterlist ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1");
    _try_sql("ALTER TABLE doctors_masterlist ADD INDEX idx_doctors_active_name (active, dr_name)");
  }

  if (_col_exists('reports','id')) {
    if (!_col_exists('reports','doctor_name'))    _try_sql("ALTER TABLE reports ADD COLUMN doctor_name VARCHAR(120) NOT NULL DEFAULT '' AFTER user_id");
    if (!_col_exists('reports','doctor_email'))   _try_sql("ALTER TABLE reports ADD COLUMN doctor_email VARCHAR(150) NULL AFTER doctor_name");
    if (!_col_exists('reports','purpose'))        _try_sql("ALTER TABLE reports ADD COLUMN purpose VARCHAR(200) NULL AFTER doctor_email");
    if (!_col_exists('reports','medicine_name'))  _try_sql("ALTER TABLE reports ADD COLUMN medicine_name VARCHAR(200) NULL AFTER purpose");
    if (!_col_exists('reports','hospital_name'))  _try_sql("ALTER TABLE reports ADD COLUMN hospital_name VARCHAR(200) NULL AFTER medicine_name");
    if (!_col_exists('reports','visit_datetime')) _try_sql("ALTER TABLE reports ADD COLUMN visit_datetime DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER hospital_name");
    if (!_col_exists('reports','summary'))        _try_sql("ALTER TABLE reports ADD COLUMN summary TEXT NULL AFTER visit_datetime");
    if (!_col_exists('reports','remarks'))        _try_sql("ALTER TABLE reports ADD COLUMN remarks TEXT NULL AFTER summary");
    if (!_col_exists('reports','signature_path')) _try_sql("ALTER TABLE reports ADD COLUMN signature_path VARCHAR(255) NULL AFTER remarks");
    if (!_col_exists('reports','status'))         _try_sql("ALTER TABLE reports ADD COLUMN status ENUM('pending','approved','needs_changes') NOT NULL DEFAULT 'pending' AFTER signature_path");
    if (!_col_exists('reports','manager_comment'))_try_sql("ALTER TABLE reports ADD COLUMN manager_comment TEXT NULL AFTER status");
    if (!_col_exists('reports','attachment_path'))_try_sql("ALTER TABLE reports ADD COLUMN attachment_path VARCHAR(255) NULL AFTER manager_comment");
    if (!_col_exists('reports','created_at'))     _try_sql("ALTER TABLE reports ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER attachment_path");
    _try_sql("ALTER TABLE reports ADD INDEX idx_reports_user_id (user_id)");
    _try_sql("ALTER TABLE reports ADD INDEX idx_reports_visit_dt (visit_datetime)");
    _try_sql("ALTER TABLE reports ADD INDEX idx_reports_status (status)");
    _try_sql("ALTER TABLE reports ADD INDEX idx_reports_doctor (doctor_name)");
    _try_sql("ALTER TABLE reports ADD INDEX idx_reports_medicine (medicine_name)");
    _try_sql("ALTER TABLE reports ADD INDEX idx_reports_hospital (hospital_name)");
  }

  if (_col_exists('users','id')) {
    _try_sql("ALTER TABLE users ADD INDEX idx_users_active_role (active, role)");
  }

  if (_col_exists('events','id')) {
    if (!_col_exists('events','visit_datetime')) _try_sql("ALTER TABLE events ADD COLUMN visit_datetime DATETIME NULL DEFAULT NULL AFTER hospital_name");
    _try_sql("ALTER TABLE events ADD INDEX idx_events_visit_dt (visit_datetime)");
  }
}
ensure_core_schema();

function url($path=''){ return rtrim(BASE_URL_EFFECTIVE,'/') . '/' . ltrim($path,'/'); }
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function post($k,$d=null){ return $_POST[$k] ?? $d; }
function getv($k,$d=null){ return $_GET[$k] ?? $d; }
function is_logged_in(){ return isset($_SESSION['user']); }
function user(){ return $_SESSION['user'] ?? null; }
function require_login(){ if(!is_logged_in()){ header('Location: '.url('index.php')); exit; } }
function role_norm(?string $role): string {
  $r = strtolower(trim((string)$role));
  if (in_array($r, ['district manager','district-manager','districtmanager','dm'], true)) return 'district_manager';
  if ($r === 'mgr') return 'manager';
  return $r;
}
function my_role(): string { return role_norm(user()['role'] ?? ''); }
function is_manager(): bool { $r = my_role(); return $r === 'manager' || $r === 'admin'; }
function is_district_manager(): bool { return my_role() === 'district_manager'; }
function is_employee(): bool { return my_role() === 'employee'; }
function require_manager(){ if(!is_logged_in() || !is_manager()){ http_response_code(403); exit('Forbidden'); } }

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
function reports_scope_where(string $alias='r'): string {
  $meId = (int)(user()['id'] ?? 0);
  if ($meId <= 0) return '0';
  $a = preg_replace('/[^a-zA-Z0-9_]/','', $alias);
  if (is_manager()) return '1';
  if (is_district_manager()) return "({$a}.user_id = {$meId} OR {$a}.user_id IN (SELECT id FROM users WHERE district_manager_id = {$meId}))";
  return "{$a}.user_id = {$meId}";
}

function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return hash_hmac('sha256', $_SESSION['csrf'], CSRF_SECRET); }
function csrf_input(){ echo '<input type="hidden" name="_token" value="'.e(csrf_token()).'">'; }
function csrf_verify(){ if($_SERVER['REQUEST_METHOD']==='POST'){ $s=$_POST['_token']??''; $ok=hash_equals(hash_hmac('sha256', $_SESSION['csrf']??'', CSRF_SECRET), $s); if(!$ok){ http_response_code(400); die('Invalid CSRF.'); } } }
function csrf_validate(){ csrf_verify(); }

function paginate($total,$per=12,$page=1){ $pages=max(1,(int)ceil($total/$per)); $page=max(1,min($pages,(int)$page)); $off=($page-1)*$per; return [$page,$pages,$off,$per]; }

function client_ip(): string {
  return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}
function normalize_email(string $email): string {
  return strtolower(trim($email));
}
function password_meets_policy(string $password, array &$errors = []): bool {
  if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
  if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Password must include at least one uppercase letter.';
  if (!preg_match('/[a-z]/', $password)) $errors[] = 'Password must include at least one lowercase letter.';
  if (!preg_match('/\d/', $password)) $errors[] = 'Password must include at least one number.';
  return empty($errors);
}
function record_login_attempt(string $email, bool $success): void {
  global $mysqli;
  $email = normalize_email($email);
  $ip = client_ip();
  $stmt = $mysqli->prepare('INSERT INTO login_attempts (email, ip_address, was_successful) VALUES (?,?,?)');
  if (!$stmt) return;
  $ok = $success ? 1 : 0;
  $stmt->bind_param('ssi', $email, $ip, $ok);
  @$stmt->execute();
  $stmt->close();
}
function login_is_locked(string $email, ?int &$retryAfterSeconds = null): bool {
  global $mysqli;
  $email = normalize_email($email);
  $ip = client_ip();
  $stmt = $mysqli->prepare("SELECT COUNT(*) AS fail_count, MIN(created_at) AS first_fail FROM login_attempts WHERE email=? AND ip_address=? AND was_successful=0 AND created_at >= (NOW() - INTERVAL 15 MINUTE)");
  if (!$stmt) return false;
  $stmt->bind_param('ss', $email, $ip);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: ['fail_count'=>0,'first_fail'=>null];
  $stmt->close();
  $fails = (int)($row['fail_count'] ?? 0);
  if ($fails < 5) return false;
  $retryAfterSeconds = 900;
  if (!empty($row['first_fail'])) {
    $elapsed = max(0, time() - strtotime((string)$row['first_fail']));
    $retryAfterSeconds = max(60, 900 - $elapsed);
  }
  return true;
}
function create_password_reset_token(int $userId): array {
  global $mysqli;
  $selector = bin2hex(random_bytes(8));
  $validator = bin2hex(random_bytes(32));
  $tokenHash = hash('sha256', $validator);
  $stmt = $mysqli->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE user_id=? AND used_at IS NULL');
  if ($stmt) { $stmt->bind_param('i', $userId); @$stmt->execute(); $stmt->close(); }
  $stmt = $mysqli->prepare('INSERT INTO password_reset_tokens (user_id, selector, token_hash, expires_at) VALUES (?,?,?, DATE_ADD(NOW(), INTERVAL 60 MINUTE))');
  if ($stmt) { $stmt->bind_param('iss', $userId, $selector, $tokenHash); @$stmt->execute(); $stmt->close(); }
  return ['selector' => $selector, 'validator' => $validator];
}
function get_password_reset_row(string $selector): ?array {
  global $mysqli;
  $stmt = $mysqli->prepare('SELECT prt.*, u.email, u.name FROM password_reset_tokens prt INNER JOIN users u ON u.id = prt.user_id WHERE prt.selector=? AND prt.used_at IS NULL AND prt.expires_at >= NOW() LIMIT 1');
  if (!$stmt) return null;
  $stmt->bind_param('s', $selector);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();
  return $row;
}
function consume_password_reset_token(string $selector, string $validator): ?array {
  $row = get_password_reset_row($selector);
  if (!$row) return null;
  if (!hash_equals((string)$row['token_hash'], hash('sha256', $validator))) return null;
  return $row;
}
function mark_password_reset_used(int $tokenId): void {
  global $mysqli;
  $stmt = $mysqli->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id=?');
  if (!$stmt) return;
  $stmt->bind_param('i', $tokenId);
  @$stmt->execute();
  $stmt->close();
}

function master_table_ready(string $table): bool {
  global $mysqli;
  $stmt = $mysqli->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
  if (!$stmt) return false;
  $stmt->bind_param('s', $table);
  $stmt->execute();
  $ok = (bool)$stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $ok;
}

function fetch_master_options(string $table, string $labelCol = 'name'): array {
  global $mysqli;
  if (!master_table_ready($table) || !preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $labelCol)) return [];
  $opts = [];
  $sql = "SELECT id, {$labelCol} AS label FROM {$table} WHERE active=1 ORDER BY {$labelCol} ASC";
  if ($res = $mysqli->query($sql)) {
    while ($row = $res->fetch_assoc()) $opts[] = $row;
    $res->free();
  }
  return $opts;
}

function fetch_doctor_master_records(): array {
  global $mysqli;
  if (!master_table_ready('doctors_masterlist')) return [];
  $rows = [];
  $cols = array_flip(db_table_columns('doctors_masterlist'));
  $nameCol = isset($cols['dr_name']) ? 'dr_name' : (isset($cols['doctor_name']) ? 'doctor_name' : null);
  if (!$nameCol) return [];
  $emailCol = isset($cols['email']) ? 'email' : "''";
  $placeCol = isset($cols['place']) ? 'place' : "''";
  $hospitalCol = isset($cols['hospital_address']) ? 'hospital_address' : "''";
  $activeWhere = isset($cols['active']) ? 'WHERE active=1' : '';
  $sql = "SELECT id, {$nameCol} AS doctor_name, {$emailCol} AS email, {$placeCol} AS city, {$hospitalCol} AS hospital_name FROM doctors_masterlist {$activeWhere} ORDER BY {$nameCol} ASC";
  if ($res = $mysqli->query($sql)) {
    while ($row = $res->fetch_assoc()) $rows[] = $row;
    $res->free();
  }
  return $rows;
}

function db_table_columns(string $table): array {
  static $cache = [];
  $key = strtolower($table);
  if (isset($cache[$key])) return $cache[$key];
  global $mysqli;
  $cols = [];
  $stmt = $mysqli->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
  if (!$stmt) return $cache[$key] = [];
  $stmt->bind_param('s', $table);
  $stmt->execute();
  $res = $stmt->get_result();
  while ($r = $res->fetch_assoc()) $cols[] = (string)$r['COLUMN_NAME'];
  $stmt->close();
  return $cache[$key] = $cols;
}
function db_safe_insert(string $table, array $data): int {
  global $mysqli;
  $existing = array_flip(db_table_columns($table));
  if (!$existing) return 0;
  $cols = []; $vals = []; $types = '';
  foreach ($data as $col => $val) {
    if (!isset($existing[$col])) continue;
    $cols[] = $col;
    $vals[] = $val;
    $types .= is_int($val) ? 'i' : 's';
  }
  if (!$cols) return 0;
  $ph = implode(',', array_fill(0, count($cols), '?'));
  $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES ({$ph})";
  $stmt = $mysqli->prepare($sql);
  if (!$stmt) return 0;
  $bind = [$types];
  for ($i=0; $i<count($vals); $i++) $bind[] = &$vals[$i];
  @call_user_func_array([$stmt,'bind_param'], $bind);
  if (!$stmt->execute()) { $stmt->close(); return 0; }
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id;
}

function log_audit(string $action, ?string $entityType = null, ?int $entityId = null, ?string $details = null): void {
  global $mysqli;
  $uid = (int)(user()['id'] ?? 0);
  $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
  $stmt = $mysqli->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)');
  if (!$stmt) return;
  $stmt->bind_param('ississ', $uid, $action, $entityType, $entityId, $details, $ip);
  @$stmt->execute();
  $stmt->close();
}

function normalize_upload_path(?string $path): ?string {
  if (!$path) return null;
  $path = str_replace('\\', '/', trim($path));
  if ($path === '') return null;
  $base = rtrim(str_replace('\\', '/', __DIR__), '/');
  if (str_starts_with($path, $base . '/')) {
    $path = substr($path, strlen($base) + 1);
  }
  return ltrim($path, '/');
}

function save_uploaded_attachment(array $file, int $userId, array &$errors): ?string {
  if (empty($file['name']) || !is_uploaded_file($file['tmp_name'])) return null;
  if ((int)($file['size'] ?? 0) > 8 * 1024 * 1024) {
    $errors[] = 'Attachment is too large. Maximum size is 8 MB.';
    return null;
  }
  $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
  $allowed = ['pdf','jpg','jpeg','png'];
  if (!in_array($ext, $allowed, true)) {
    $errors[] = 'Invalid attachment type. Allowed: PDF, JPG, JPEG, PNG.';
    return null;
  }
  $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
  $mime = $finfo ? (string)finfo_file($finfo, $file['tmp_name']) : '';
  if ($finfo) finfo_close($finfo);
  $allowedMime = ['application/pdf','image/jpeg','image/png'];
  if ($mime && !in_array($mime, $allowedMime, true)) {
    $errors[] = 'Attachment MIME type is not allowed.';
    return null;
  }
  if (!is_dir(ATTACH_DIR)) @mkdir(ATTACH_DIR, 0775, true);
  $name = 'att_' . $userId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $dest = rtrim(ATTACH_DIR, '/\\') . DIRECTORY_SEPARATOR . $name;
  if (!move_uploaded_file($file['tmp_name'], $dest)) {
    $errors[] = 'Failed to upload attachment.';
    return null;
  }
  return 'uploads/attachments/' . $name;
}

function save_signature_data(?string $signatureData, int $userId): ?string {
  $signatureData = trim((string)$signatureData);
  if ($signatureData === '' || !str_starts_with($signatureData, 'data:image')) return null;
  if (!is_dir(SIGNATURE_DIR)) @mkdir(SIGNATURE_DIR, 0775, true);
  $parts = explode(',', $signatureData, 2);
  if (count($parts) !== 2) return null;
  $bin = base64_decode($parts[1]);
  if ($bin === false) return null;
  $name = 'sig_' . $userId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.png';
  $dest = rtrim(SIGNATURE_DIR, '/\\') . DIRECTORY_SEPARATOR . $name;
  if (file_put_contents($dest, $bin) === false) return null;
  return 'uploads/signatures/' . $name;
}
?>
