<?php
require_once __DIR__ . '/config.php';
session_start();

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) die('DB Connection failed: ' . $mysqli->connect_error);
$mysqli->set_charset('utf8mb4');

/* ---------------------------------------------------------
   Lightweight, safe schema migrations (prevents blank views)
   ---------------------------------------------------------
   Some older installs have a "reports" table missing the
   newer columns (doctor_name, purpose, etc.). When that
   happens, inserts become partial and report_view shows empty
   fields.

   This migration runs once per session and only ADDs missing
   columns / indexes (best-effort; errors are swallowed to
   avoid HTTP 500).
*/
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
  try { @$mysqli->query($sql); } catch (Throwable $e) { /* swallow */ }
}

function ensure_core_schema(): void {
  if (!empty($_SESSION['_schema_migrated_v6'])) return;
  $_SESSION['_schema_migrated_v6'] = 1;

  // Ensure reports table has required columns
  if (_col_exists('reports','id')) {
    if (!_col_exists('reports','doctor_name'))   _try_sql("ALTER TABLE reports ADD COLUMN doctor_name VARCHAR(120) NOT NULL DEFAULT '' AFTER user_id");
    if (!_col_exists('reports','doctor_email'))  _try_sql("ALTER TABLE reports ADD COLUMN doctor_email VARCHAR(150) NULL AFTER doctor_name");
    if (!_col_exists('reports','purpose'))       _try_sql("ALTER TABLE reports ADD COLUMN purpose VARCHAR(200) NULL AFTER doctor_email");
    if (!_col_exists('reports','medicine_name')) _try_sql("ALTER TABLE reports ADD COLUMN medicine_name VARCHAR(200) NULL AFTER purpose");
    if (!_col_exists('reports','hospital_name')) _try_sql("ALTER TABLE reports ADD COLUMN hospital_name VARCHAR(200) NULL AFTER medicine_name");
    if (!_col_exists('reports','visit_datetime'))_try_sql("ALTER TABLE reports ADD COLUMN visit_datetime DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER hospital_name");
    if (!_col_exists('reports','summary'))       _try_sql("ALTER TABLE reports ADD COLUMN summary TEXT NULL AFTER visit_datetime");
    if (!_col_exists('reports','remarks'))       _try_sql("ALTER TABLE reports ADD COLUMN remarks TEXT NULL AFTER summary");
    if (!_col_exists('reports','signature_path'))_try_sql("ALTER TABLE reports ADD COLUMN signature_path VARCHAR(255) NULL AFTER remarks");
    if (!_col_exists('reports','status'))        _try_sql("ALTER TABLE reports ADD COLUMN status ENUM('pending','approved','needs_changes') NOT NULL DEFAULT 'pending' AFTER signature_path");
    if (!_col_exists('reports','manager_comment')) _try_sql("ALTER TABLE reports ADD COLUMN manager_comment TEXT NULL AFTER status");
    if (!_col_exists('reports','attachment_path')) _try_sql("ALTER TABLE reports ADD COLUMN attachment_path VARCHAR(255) NULL AFTER manager_comment");
    if (!_col_exists('reports','created_at'))    _try_sql("ALTER TABLE reports ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER attachment_path");

    // Helpful indexes (best-effort)
    _try_sql("ALTER TABLE reports ADD INDEX idx_reports_user_id (user_id)");
    _try_sql("ALTER TABLE reports ADD INDEX idx_reports_visit_dt (visit_datetime)");
    _try_sql("ALTER TABLE reports ADD INDEX idx_reports_status (status)");
  }

  // Ensure events table has visit_datetime (used by task->report)
  if (_col_exists('events','id')) {
    if (!_col_exists('events','visit_datetime')) _try_sql("ALTER TABLE events ADD COLUMN visit_datetime DATETIME NULL DEFAULT NULL AFTER hospital_name");
  }
}

// Run safe schema migration early
ensure_core_schema();


function ensure_performance_schema(): void {
  if (!empty($_SESSION['_performance_schema_v7'])) return;
  $_SESSION['_performance_schema_v7'] = 1;

  _try_sql("CREATE TABLE IF NOT EXISTS performance_targets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    target_month CHAR(7) NOT NULL,
    target_reports INT NOT NULL DEFAULT 0,
    target_unique_doctors INT NOT NULL DEFAULT 0,
    target_unique_hospitals INT NOT NULL DEFAULT 0,
    notes VARCHAR(255) NULL,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_target_user_month (user_id, target_month),
    KEY idx_target_month (target_month),
    CONSTRAINT fk_perf_target_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

ensure_performance_schema();

function current_target_month(): string {
  return date('Y-m');
}

function performance_scope_filter(string $userColumn='u.id'): string {
  $me = user();
  $meId = (int)($me['id'] ?? 0);
  if ($meId <= 0) return '0';
  if (is_manager()) return '1';
  if (is_district_manager()) return "({$userColumn} = {$meId} OR {$userColumn} IN (SELECT id FROM users WHERE district_manager_id = {$meId}))";
  return "{$userColumn} = {$meId}";
}

function performance_month_bounds(?string $month=null): array {
  $month = preg_match('/^\d{4}-\d{2}$/', (string)$month) ? $month : current_target_month();
  $start = $month . '-01';
  $end = date('Y-m-d', strtotime($start . ' +1 month'));
  return [$month, $start, $end];
}

function fetch_performance_overview(?string $month=null): array {
  global $mysqli;
  [$month, $start, $end] = performance_month_bounds($month);
  $filter = performance_scope_filter('u.id');
  $sql = "SELECT u.id, u.name, u.role,
    COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END),0) AS report_count,
    COUNT(DISTINCT NULLIF(r.doctor_name,'')) AS doctors_count,
    COUNT(DISTINCT NULLIF(r.hospital_name,'')) AS hospitals_count,
    COUNT(DISTINCT NULLIF(r.medicine_name,'')) AS medicines_count,
    SUM(CASE WHEN r.status='approved' THEN 1 ELSE 0 END) AS approved_count,
    SUM(CASE WHEN r.status='pending' THEN 1 ELSE 0 END) AS pending_count,
    SUM(CASE WHEN r.status='needs_changes' THEN 1 ELSE 0 END) AS needs_changes_count,
    MAX(t.target_reports) AS target_reports,
    MAX(t.target_unique_doctors) AS target_unique_doctors,
    MAX(t.target_unique_hospitals) AS target_unique_hospitals
  FROM users u
  LEFT JOIN reports r ON r.user_id=u.id AND r.visit_datetime >= ? AND r.visit_datetime < ?
  LEFT JOIN performance_targets t ON t.user_id=u.id AND t.target_month=?
  WHERE u.active=1 AND {$filter}
  GROUP BY u.id, u.name, u.role
  ORDER BY report_count DESC, u.name ASC";
  $stmt = $mysqli->prepare($sql);
  if (!$stmt) return ['month'=>$month,'rows'=>[],'summary'=>[]];
  $stmt->bind_param('sss',$start,$end,$month);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();

  $summary = [
    'total_reports'=>0,'total_approved'=>0,'total_pending'=>0,'total_needs_changes'=>0,
    'total_doctors'=>0,'total_hospitals'=>0,'total_medicines'=>0,'target_reports'=>0,
    'achievement_pct'=>0,
  ];
  foreach($rows as $r){
    $summary['total_reports'] += (int)$r['report_count'];
    $summary['total_approved'] += (int)$r['approved_count'];
    $summary['total_pending'] += (int)$r['pending_count'];
    $summary['total_needs_changes'] += (int)$r['needs_changes_count'];
    $summary['total_doctors'] += (int)$r['doctors_count'];
    $summary['total_hospitals'] += (int)$r['hospitals_count'];
    $summary['total_medicines'] += (int)$r['medicines_count'];
    $summary['target_reports'] += (int)$r['target_reports'];
  }
  if ($summary['target_reports'] > 0) {
    $summary['achievement_pct'] = (int)round(($summary['total_reports'] / max(1,$summary['target_reports'])) * 100);
  }
  return ['month'=>$month,'rows'=>$rows,'summary'=>$summary];
}

function upsert_performance_target(int $userId, string $month, int $targetReports, int $targetDoctors, int $targetHospitals, string $notes=''): bool {
  global $mysqli;
  $createdBy = (int)(user()['id'] ?? 0);
  $stmt = $mysqli->prepare("INSERT INTO performance_targets (user_id, target_month, target_reports, target_unique_doctors, target_unique_hospitals, notes, created_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE target_reports=VALUES(target_reports), target_unique_doctors=VALUES(target_unique_doctors), target_unique_hospitals=VALUES(target_unique_hospitals), notes=VALUES(notes), updated_at=CURRENT_TIMESTAMP");
  if (!$stmt) return false;
  $stmt->bind_param('isiiisi', $userId, $month, $targetReports, $targetDoctors, $targetHospitals, $notes, $createdBy);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}


function url($path=''){ return rtrim(BASE_URL_EFFECTIVE,'/') . '/' . ltrim($path,'/'); }
function is_logged_in(){ return isset($_SESSION['user']); }
function user(){ return $_SESSION['user'] ?? null; }
function require_login(){ if(!is_logged_in()){ header('Location: '.url('index.php')); exit; } }
function require_manager(){ if(!is_logged_in() || !is_manager()){ http_response_code(403); exit('Forbidden'); } }

// Role helpers (normalized)
function role_norm(?string $role): string {
  $r = strtolower(trim((string)$role));
  // common variants / legacy values
  if ($r === 'district manager' || $r === 'district-manager' || $r === 'districtmanager') return 'district_manager';
  if ($r === 'dm') return 'district_manager';
  if ($r === 'mgr') return 'manager';
  return $r;
}
function my_role(): string { return role_norm(user()['role'] ?? ''); }

function is_manager(): bool { $r = my_role(); return $r === 'manager' || $r === 'admin'; }
function is_district_manager(): bool { return my_role() === 'district_manager'; }
function is_employee(): bool { return my_role() === 'employee'; }


/**
 * Returns true if $employee_id is assigned to $district_manager_id.
 * Uses users.district_manager_id.
 */
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

/**
 * Access check: can the current user view reports of $owner_user_id?
 * - manager: yes for everyone
 * - district_manager: yes for self + users assigned under them
 * - employee: only self
 */
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

/**
 * SQL snippet for filtering reports (table alias is configurable; default 'r').
 * - manager: no filter (returns '1')
 * - district_manager: own + assigned employees
 * - employee: own only
 */
function reports_scope_where(string $alias='r'): string {
  $me = user();
  $meId = (int)($me['id'] ?? 0);
  if ($meId <= 0) return '0';
  if (is_manager()) return '1';
  if (is_district_manager()) {
    $a = preg_replace('/[^a-zA-Z0-9_]/','', $alias);
    return "({$a}.user_id = {$meId} OR {$a}.user_id IN (SELECT id FROM users WHERE district_manager_id = {$meId}))";
  }
  $a = preg_replace('/[^a-zA-Z0-9_]/','', $alias);
  return "{$a}.user_id = {$meId}";
}

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function post($k,$d=null){ return $_POST[$k] ?? $d; }
function getv($k,$d=null){ return $_GET[$k] ?? $d; }

// CSRF
function csrf_token(){ if(empty($_SESSION['csrf'])) $_SESSION['csrf']=bin2hex(random_bytes(32)); return hash_hmac('sha256', $_SESSION['csrf'], CSRF_SECRET); }
function csrf_input(){ echo '<input type="hidden" name="_token" value="'.e(csrf_token()).'">'; }
function csrf_verify(){ if($_SERVER['REQUEST_METHOD']==='POST'){ $s=$_POST['_token']??''; $ok=hash_equals(hash_hmac('sha256', $_SESSION['csrf']??'', CSRF_SECRET), $s); if(!$ok){ http_response_code(400); die('Invalid CSRF.'); } } }

if (!function_exists('csrf_validate')) {
  function csrf_validate() { csrf_verify(); }
}

function paginate($total,$per=12,$page=1){ $pages=max(1,(int)ceil($total/$per)); $page=max(1,min($pages,(int)$page)); $off=($page-1)*$per; return [$page,$pages,$off,$per]; }

/* ---------------------------
   DB helpers (safe inserts)
   --------------------------- */
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

function db_column_exists(string $table, string $column): bool {
  $cols = db_table_columns($table);
  if (!$cols) return false;
  return in_array($column, $cols, true);
}

/**
 * Insert into $table using only columns that exist.
 * Returns inserted id (int) or 0.
 */
function db_safe_insert(string $table, array $data): int {
  global $mysqli;
  $existing = array_flip(db_table_columns($table));
  if (!$existing) return 0;

  $cols = [];
  $vals = [];
  $types = '';

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
  if (!$stmt) {
    error_log("db_safe_insert prepare failed for {$table}: " . $mysqli->error);
    return 0;
  }

  $bind = [$types];
  for ($i=0; $i<count($vals); $i++) $bind[] = &$vals[$i];
  @call_user_func_array([$stmt,'bind_param'], $bind);

  if (!$stmt->execute()) {
    error_log("db_safe_insert execute failed for {$table}: " . $stmt->error);
    $stmt->close();
    return 0;
  }
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id;
}

/* ---------------------------
   App support helpers
   --------------------------- */
function normalize_email(string $email): string {
  return strtolower(trim($email));
}

function can_run_setup(): bool {
  $allow = defined('ALLOW_SETUP') ? (bool)ALLOW_SETUP : false;
  if (!$allow) return false;
  $key = (string)($_GET['setup_key'] ?? $_POST['setup_key'] ?? '');
  $expected = defined('SETUP_KEY') ? (string)SETUP_KEY : '';
  return $expected === '' || ($key !== '' && hash_equals($expected, $key));
}

function can_use_dev_tools(): bool {
  $allow = defined('ALLOW_DEV_TOOLS') ? (bool)ALLOW_DEV_TOOLS : false;
  if (!$allow) return false;
  if (is_logged_in() && is_manager()) return true;
  $key = (string)($_GET['dev_key'] ?? $_POST['dev_key'] ?? '');
  $expected = defined('DEV_TOOL_KEY') ? (string)DEV_TOOL_KEY : '';
  return $expected !== '' && $key !== '' && hash_equals($expected, $key);
}

function ensure_supporting_schema(): void {
  if (!empty($_SESSION['_supporting_schema_v8'])) return;
  $_SESSION['_supporting_schema_v8'] = 1;
  _try_sql("CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NULL,
    entity_id INT NULL,
    details TEXT NULL,
    ip_address VARCHAR(64) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_audit_action (action),
    KEY idx_audit_entity (entity_type, entity_id),
    KEY idx_audit_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  _try_sql("CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NULL,
    type VARCHAR(80) NOT NULL DEFAULT 'info',
    entity_type VARCHAR(80) NULL,
    entity_id INT NULL,
    action_url VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    actor_user_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_notifications_user (user_id, is_read, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  _try_sql("CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector VARCHAR(32) NOT NULL,
    token_hash VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_reset_selector (selector),
    KEY idx_reset_user (user_id),
    KEY idx_reset_expiry (expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  _try_sql("CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(64) NULL,
    success TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_email_time (email, created_at),
    KEY idx_login_ip_time (ip_address, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  _try_sql("CREATE TABLE IF NOT EXISTS report_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    actor_user_id INT NULL,
    old_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_rsh_report_created (report_id, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  _try_sql("CREATE TABLE IF NOT EXISTS event_attendees (
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    added_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(event_id,user_id),
    KEY idx_event_attendees_user (user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
ensure_supporting_schema();

function log_audit(string $action, ?string $entityType=null, ?int $entityId=null, ?string $details=null): void {
  global $mysqli;
  $uid = (int)(user()['id'] ?? 0);
  $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
  $stmt = $mysqli->prepare('INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)');
  if (!$stmt) return;
  $entityId = $entityId ?: null;
  $stmt->bind_param('ississ', $uid, $action, $entityType, $entityId, $details, $ip);
  @$stmt->execute();
  $stmt->close();
}

function audit_action_label(string $action): string {
  $map = [
    'login_success' => 'Login success',
    'profile_updated' => 'Profile updated',
    'password_changed' => 'Password changed',
    'password_reset_requested' => 'Password reset requested',
    'password_reset_completed' => 'Password reset completed',
    'user_toggled' => 'User status changed',
    'user_password_reset' => 'Temporary password reset',
    'report_created' => 'Report submitted',
    'report_updated' => 'Report updated',
    'report_reviewed' => 'Report reviewed',
  ];
  return $map[$action] ?? ucwords(str_replace('_', ' ', $action));
}

function fetch_doctor_master_records(): array {
  global $mysqli;
  $rows = [];
  $sql = "SELECT id, dr_name AS doctor_name, email, hospital_address AS hospital_name, place AS city FROM doctors_masterlist ORDER BY dr_name ASC";
  if ($res = $mysqli->query($sql)) {
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $res->free();
  }
  return $rows;
}

function fetch_master_options(string $table): array {
  global $mysqli;
  $allowed = ['medicines_master' => 'name', 'hospitals_master' => 'name'];
  if (!isset($allowed[$table])) return [];
  $col = $allowed[$table];
  $rows = [];
  $sql = "SELECT id, {$col} AS label FROM {$table} WHERE active=1 ORDER BY {$col} ASC";
  if ($res = $mysqli->query($sql)) {
    while ($r = $res->fetch_assoc()) $rows[] = $r;
    $res->free();
  }
  return $rows;
}

function normalize_upload_path($path): ?string {
  $path = trim((string)$path);
  if ($path === '') return null;
  $path = str_replace('\\', '/', $path);
  if (str_starts_with($path, ATTACH_DIR)) {
    return 'uploads/attachments/' . basename($path);
  }
  return $path;
}

function save_uploaded_attachment(array $file, int $userId, array &$errors): ?string {
  if (empty($file['name']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
  $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
  $allowed = ['pdf','jpg','jpeg','png'];
  if (!in_array($ext, $allowed, true)) {
    $errors[] = 'Invalid attachment type. Allowed: PDF/JPG/PNG.';
    return null;
  }
  $size = (int)($file['size'] ?? 0);
  if ($size > 5 * 1024 * 1024) {
    $errors[] = 'Attachment must be 5MB or smaller.';
    return null;
  }
  if (!is_dir(ATTACH_DIR)) @mkdir(ATTACH_DIR, 0775, true);
  $name = 'att_' . $userId . '_' . time() . '_' . substr(md5((string)$file['name']),0,8) . '.' . $ext;
  $dest = rtrim(ATTACH_DIR, '/\\') . DIRECTORY_SEPARATOR . $name;
  if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    $errors[] = 'Failed to upload attachment.';
    return null;
  }
  return 'uploads/attachments/' . $name;
}

function notification_recipient_ids_for_report_owner(int $ownerId): array {
  global $mysqli;
  $ids = [];
  if ($res = $mysqli->query("SELECT id FROM users WHERE active=1 AND role IN ('manager','admin')")) {
    while ($r = $res->fetch_assoc()) $ids[] = (int)$r['id'];
    $res->free();
  }
  $stmt = $mysqli->prepare('SELECT district_manager_id FROM users WHERE id=? LIMIT 1');
  if ($stmt) {
    $stmt->bind_param('i', $ownerId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!empty($row['district_manager_id'])) $ids[] = (int)$row['district_manager_id'];
  }
  $ids = array_values(array_unique(array_filter($ids)));
  return array_values(array_filter($ids, fn($x) => $x !== $ownerId));
}

function notify_user(int $userId, string $title, string $body='', string $type='info', ?string $entityType=null, ?int $entityId=null, ?string $actionUrl=null, ?int $actorUserId=null): void {
  global $mysqli;
  $stmt = $mysqli->prepare('INSERT INTO notifications (user_id, title, body, type, entity_type, entity_id, action_url, actor_user_id) VALUES (?,?,?,?,?,?,?,?)');
  if (!$stmt) return;
  $stmt->bind_param('issssisi', $userId, $title, $body, $type, $entityType, $entityId, $actionUrl, $actorUserId);
  @$stmt->execute();
  $stmt->close();
}

function notify_many(array $userIds, string $title, string $body='', string $type='info', ?string $entityType=null, ?int $entityId=null, ?string $actionUrl=null, ?int $actorUserId=null): void {
  foreach (array_values(array_unique(array_filter(array_map('intval',$userIds)))) as $uid) {
    notify_user($uid, $title, $body, $type, $entityType, $entityId, $actionUrl, $actorUserId);
  }
}

function mark_notification_read(int $id): void {
  global $mysqli;
  $uid = (int)(user()['id'] ?? 0);
  $stmt = $mysqli->prepare('UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?');
  if (!$stmt) return;
  $stmt->bind_param('ii', $id, $uid);
  @$stmt->execute();
  $stmt->close();
}

function mark_all_notifications_read(): void {
  global $mysqli;
  $uid = (int)(user()['id'] ?? 0);
  $stmt = $mysqli->prepare('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0');
  if (!$stmt) return;
  $stmt->bind_param('i', $uid);
  @$stmt->execute();
  $stmt->close();
}

function password_meets_policy(string $password, array &$errors=[]): bool {
  $errors = [];
  if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
  if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Add at least one uppercase letter.';
  if (!preg_match('/[a-z]/', $password)) $errors[] = 'Add at least one lowercase letter.';
  if (!preg_match('/\d/', $password)) $errors[] = 'Add at least one number.';
  return !$errors;
}

function create_password_reset_token(int $userId): array {
  global $mysqli;
  $selector = bin2hex(random_bytes(8));
  $validator = bin2hex(random_bytes(16));
  $hash = password_hash($validator, PASSWORD_DEFAULT);
  $expires = date('Y-m-d H:i:s', time() + 3600);
  $stmt = $mysqli->prepare('INSERT INTO password_reset_tokens (user_id, selector, token_hash, expires_at) VALUES (?,?,?,?)');
  if ($stmt) {
    $stmt->bind_param('isss', $userId, $selector, $hash, $expires);
    @$stmt->execute();
    $stmt->close();
  }
  return ['selector'=>$selector, 'validator'=>$validator, 'expires_at'=>$expires];
}

function consume_password_reset_token(string $selector, string $validator): ?array {
  global $mysqli;
  $stmt = $mysqli->prepare('SELECT * FROM password_reset_tokens WHERE selector=? AND used_at IS NULL AND expires_at >= NOW() ORDER BY id DESC LIMIT 1');
  if (!$stmt) return null;
  $stmt->bind_param('s', $selector);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) return null;
  if (!password_verify($validator, (string)$row['token_hash'])) return null;
  return $row;
}

function mark_password_reset_used(int $id): void {
  global $mysqli;
  $stmt = $mysqli->prepare('UPDATE password_reset_tokens SET used_at=NOW() WHERE id=?');
  if (!$stmt) return;
  $stmt->bind_param('i', $id);
  @$stmt->execute();
  $stmt->close();
}

function login_is_locked(string $email, int &$retryAfter=0): bool {
  global $mysqli;
  $retryAfter = 0;
  $email = normalize_email($email);
  if ($email === '') return false;
  $stmt = $mysqli->prepare("SELECT COUNT(*) AS fails, UNIX_TIMESTAMP(MIN(DATE_ADD(created_at, INTERVAL 15 MINUTE))) AS unlock_at FROM login_attempts WHERE email=? AND success=0 AND created_at >= (NOW() - INTERVAL 15 MINUTE)");
  if (!$stmt) return false;
  $stmt->bind_param('s', $email);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $fails = (int)($row['fails'] ?? 0);
  if ($fails < 5) return false;
  $unlockAt = (int)($row['unlock_at'] ?? 0);
  $retryAfter = max(0, $unlockAt - time());
  return true;
}

function record_login_attempt(string $email, bool $success): void {
  global $mysqli;
  $email = normalize_email($email);
  $ip = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
  $flag = $success ? 1 : 0;
  $stmt = $mysqli->prepare('INSERT INTO login_attempts (email, ip_address, success) VALUES (?,?,?)');
  if (!$stmt) return;
  $stmt->bind_param('ssi', $email, $ip, $flag);
  @$stmt->execute();
  $stmt->close();
}

function add_report_status_history(int $reportId, ?string $oldStatus, string $newStatus, ?string $comment=''): void {
  global $mysqli;
  $uid = (int)(user()['id'] ?? 0);
  $stmt = $mysqli->prepare('INSERT INTO report_status_history (report_id, actor_user_id, old_status, new_status, comment) VALUES (?,?,?,?,?)');
  if (!$stmt) return;
  $stmt->bind_param('iisss', $reportId, $uid, $oldStatus, $newStatus, $comment);
  @$stmt->execute();
  $stmt->close();
}

function report_quality_checks(array $data): array {
  $warnings = [];
  $summary = trim((string)($data['summary'] ?? ''));
  $remarks = trim((string)($data['remarks'] ?? ''));
  $purpose = trim((string)($data['purpose'] ?? ''));
  $medicine = trim((string)($data['medicine_name'] ?? ''));
  $hospital = trim((string)($data['hospital_name'] ?? ''));
  if ($purpose === '') $warnings[] = 'Purpose is empty.';
  if ($medicine === '') $warnings[] = 'Medicine is empty.';
  if ($hospital === '') $warnings[] = 'Hospital / clinic is empty.';
  if ($summary !== '' && mb_strlen($summary) < 20) $warnings[] = 'Summary looks very short.';
  if ($remarks !== '' && mb_strlen($remarks) < 8) $warnings[] = 'Remarks look very short.';
  return $warnings;
}

function find_potential_duplicate_reports(int $userId, string $doctorName, string $visitDatetime, int $excludeId=0): array {
  global $mysqli;
  $doctorName = trim($doctorName);
  if ($userId <= 0 || $doctorName === '' || trim($visitDatetime) === '') return [];
  $windowStart = date('Y-m-d H:i:s', strtotime($visitDatetime . ' -2 day'));
  $windowEnd = date('Y-m-d H:i:s', strtotime($visitDatetime . ' +2 day'));
  $sql = 'SELECT id, doctor_name, hospital_name, medicine_name, visit_datetime, status FROM reports WHERE user_id=? AND LOWER(TRIM(doctor_name))=LOWER(TRIM(?)) AND visit_datetime BETWEEN ? AND ?';
  if ($excludeId > 0) $sql .= ' AND id<>?';
  $sql .= ' ORDER BY visit_datetime DESC LIMIT 5';
  $stmt = $mysqli->prepare($sql);
  if (!$stmt) return [];
  if ($excludeId > 0) $stmt->bind_param('isssi', $userId, $doctorName, $windowStart, $windowEnd, $excludeId);
  else $stmt->bind_param('isss', $userId, $doctorName, $windowStart, $windowEnd);
  $stmt->execute();
  $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
  $stmt->close();
  return $rows ?: [];
}

?>