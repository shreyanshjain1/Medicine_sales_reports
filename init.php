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
   Workflow, security, mail helpers
   --------------------------- */
function app_env(): string { return defined('APP_ENV') ? strtolower((string)APP_ENV) : 'production'; }
function normalize_email(string $email): string { return strtolower(trim($email)); }
function client_ip(): string {
  foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
    if (!empty($_SERVER[$k])) return trim(explode(',', (string)$_SERVER[$k])[0]);
  }
  return '0.0.0.0';
}
function password_meets_policy(string $password, array &$errors=[]): bool {
  $errors = [];
  if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
  if (!preg_match('/[A-Z]/', $password)) $errors[] = 'Add at least one uppercase letter.';
  if (!preg_match('/[a-z]/', $password)) $errors[] = 'Add at least one lowercase letter.';
  if (!preg_match('/\d/', $password)) $errors[] = 'Add at least one number.';
  return !$errors;
}
function allow_setup_mode(): bool { return defined('ALLOW_SETUP') && ALLOW_SETUP; }
function allow_dev_tools(): bool { return defined('ALLOW_DEV_TOOLS') && ALLOW_DEV_TOOLS; }

function ensure_workflow_schema(): void {
  if (!empty($_SESSION['_workflow_schema_v10'])) return;
  $_SESSION['_workflow_schema_v10'] = 1;

  _try_sql("CREATE TABLE IF NOT EXISTS report_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    actor_user_id INT NULL,
    old_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rsh_report (report_id),
    INDEX idx_rsh_actor (actor_user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

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

  _try_sql("CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(200) NOT NULL,
    body TEXT NULL,
    type VARCHAR(80) NOT NULL DEFAULT 'general',
    entity_type VARCHAR(80) NULL,
    entity_id INT NULL,
    action_url VARCHAR(255) NULL,
    actor_user_id INT NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_read (is_read),
    INDEX idx_notifications_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  _try_sql("CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL,
    ip_address VARCHAR(64) NOT NULL,
    was_successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_login_email_created (email, created_at),
    INDEX idx_login_ip_created (ip_address, created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  _try_sql("CREATE TABLE IF NOT EXISTS password_resets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    selector CHAR(16) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_selector (selector),
    INDEX idx_reset_user (user_id),
    INDEX idx_reset_expiry (expires_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  _try_sql("CREATE TABLE IF NOT EXISTS app_mail_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    recipient_email VARCHAR(190) NOT NULL,
    subject_line VARCHAR(255) NOT NULL,
    mail_driver VARCHAR(20) NOT NULL DEFAULT 'log',
    status VARCHAR(20) NOT NULL DEFAULT 'queued',
    error_message TEXT NULL,
    related_entity_type VARCHAR(80) NULL,
    related_entity_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_mail_recipient (recipient_email),
    INDEX idx_mail_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  if (_col_exists('users','id') && !_col_exists('users','wants_email_notifications')) {
    _try_sql("ALTER TABLE users ADD COLUMN wants_email_notifications TINYINT(1) NOT NULL DEFAULT 1 AFTER active");
  }
}
ensure_workflow_schema();

function log_audit(string $action, ?string $entityType=null, ?int $entityId=null, string $details=''): void {
  global $mysqli;
  $uid = (int)(user()['id'] ?? 0);
  $ip = client_ip();
  $stmt = $mysqli->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)");
  if (!$stmt) return;
  $u = $uid > 0 ? $uid : null;
  $eid = ($entityId ?? 0) > 0 ? (int)$entityId : null;
  $stmt->bind_param('ississ', $u, $action, $entityType, $eid, $details, $ip);
  @$stmt->execute();
  $stmt->close();
}

function record_report_status(int $reportId, string $newStatus, ?string $oldStatus=null, string $comment=''): void {
  global $mysqli;
  if ($reportId <= 0 || $newStatus === '') return;
  $actorId = (int)(user()['id'] ?? 0);
  $stmt = $mysqli->prepare("INSERT INTO report_status_history (report_id, actor_user_id, old_status, new_status, comment) VALUES (?,?,?,?,?)");
  if (!$stmt) return;
  $actor = $actorId > 0 ? $actorId : null;
  $stmt->bind_param('iisss', $reportId, $actor, $oldStatus, $newStatus, $comment);
  @$stmt->execute();
  $stmt->close();
}

function fetch_report_timeline(int $reportId): array {
  global $mysqli;
  $items = [];
  if ($reportId <= 0) return $items;
  if ($stmt = $mysqli->prepare("SELECT 'status' AS source, created_at, new_status AS label, comment AS details FROM report_status_history WHERE report_id=?")) {
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $items[] = $row;
    $stmt->close();
  }
  if ($stmt = $mysqli->prepare("SELECT 'audit' AS source, created_at, action AS label, details FROM audit_logs WHERE entity_type='report' AND entity_id=?")) {
    $stmt->bind_param('i', $reportId);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) $items[] = $row;
    $stmt->close();
  }
  usort($items, static fn($a,$b) => strcmp((string)$b['created_at'], (string)$a['created_at']));
  return $items;
}

function fetch_queue_summary(): array {
  global $mysqli;
  $where = reports_scope_where('r');
  $sql = "SELECT SUM(CASE WHEN status='pending' THEN 1 ELSE 0 END) pending_count,
                 SUM(CASE WHEN status='approved' THEN 1 ELSE 0 END) approved_count,
                 SUM(CASE WHEN status='needs_changes' THEN 1 ELSE 0 END) needs_changes_count
          FROM reports r WHERE {$where}";
  $row = $mysqli->query($sql)?->fetch_assoc() ?: [];
  return [
    'pending_count' => (int)($row['pending_count'] ?? 0),
    'approved_count' => (int)($row['approved_count'] ?? 0),
    'needs_changes_count' => (int)($row['needs_changes_count'] ?? 0),
  ];
}

function wants_email_notifications(int $userId): bool {
  global $mysqli;
  if ($userId <= 0) return false;
  if (!db_column_exists('users', 'wants_email_notifications')) return true;
  $stmt = $mysqli->prepare('SELECT wants_email_notifications FROM users WHERE id=? LIMIT 1');
  if (!$stmt) return true;
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return !isset($row['wants_email_notifications']) || (int)$row['wants_email_notifications'] === 1;
}

function get_user_brief(int $userId): ?array {
  global $mysqli;
  $stmt = $mysqli->prepare('SELECT id,name,email,active FROM users WHERE id=? LIMIT 1');
  if (!$stmt) return null;
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return $row ?: null;
}

function mail_driver(): string { return defined('MAIL_DRIVER') ? strtolower((string)MAIL_DRIVER) : 'log'; }
function mail_enabled(): bool { return defined('MAIL_ENABLED') ? (bool)MAIL_ENABLED : false; }
function mail_from_email(): string { return defined('MAIL_FROM_EMAIL') ? (string)MAIL_FROM_EMAIL : 'no-reply@example.com'; }
function mail_from_name(): string { return defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : APP_NAME; }
function mail_reply_to(): string { return defined('MAIL_REPLY_TO') ? (string)MAIL_REPLY_TO : mail_from_email(); }

function log_mail_attempt(string $recipient, string $subject, string $driver, string $status, string $error='', ?string $entityType=null, ?int $entityId=null): void {
  global $mysqli;
  $stmt = $mysqli->prepare("INSERT INTO app_mail_log (recipient_email, subject_line, mail_driver, status, error_message, related_entity_type, related_entity_id) VALUES (?,?,?,?,?,?,?)");
  if (!$stmt) return;
  $stmt->bind_param('ssssssi', $recipient, $subject, $driver, $status, $error, $entityType, $entityId);
  @$stmt->execute();
  $stmt->close();
}

function app_mail_headers(): string {
  $headers = [];
  $headers[] = 'MIME-Version: 1.0';
  $headers[] = 'Content-type: text/html; charset=UTF-8';
  $headers[] = 'From: ' . mail_from_name() . ' <' . mail_from_email() . '>';
  $headers[] = 'Reply-To: ' . mail_reply_to();
  return implode("\r\n", $headers);
}

function smtp_expect($fp, array $codes): bool {
  $line = '';
  while (!feof($fp)) {
    $chunk = fgets($fp, 515);
    if ($chunk === false) break;
    $line = $chunk;
    if (strlen($chunk) >= 4 && $chunk[3] === ' ') break;
  }
  $code = (int)substr($line, 0, 3);
  return in_array($code, $codes, true);
}

function smtp_send_mail(string $to, string $subject, string $html, string $text=''): array {
  $host = defined('SMTP_HOST') ? (string)SMTP_HOST : '';
  $port = defined('SMTP_PORT') ? (int)SMTP_PORT : 25;
  $secure = defined('SMTP_SECURE') ? strtolower((string)SMTP_SECURE) : '';
  $username = defined('SMTP_USERNAME') ? (string)SMTP_USERNAME : '';
  $password = defined('SMTP_PASSWORD') ? (string)SMTP_PASSWORD : '';
  $timeout = defined('SMTP_TIMEOUT') ? (int)SMTP_TIMEOUT : 15;
  if ($host === '') return [false, 'SMTP host is not configured'];

  $remote = ($secure === 'ssl' ? 'ssl://' : '') . $host;
  $fp = @fsockopen($remote, $port, $errno, $errstr, $timeout);
  if (!$fp) return [false, $errstr ?: ('SMTP connect failed: ' . $errno)];
  stream_set_timeout($fp, $timeout);
  if (!smtp_expect($fp, [220])) { fclose($fp); return [false, 'SMTP greeting failed']; }

  fwrite($fp, "EHLO " . preg_replace('/^https?:\/\//','', parse_url(BASE_URL_EFFECTIVE, PHP_URL_HOST) ?: 'localhost') . "\r\n");
  if (!smtp_expect($fp, [250])) { fclose($fp); return [false, 'SMTP EHLO failed']; }

  if ($secure === 'tls') {
    fwrite($fp, "STARTTLS\r\n");
    if (!smtp_expect($fp, [220])) { fclose($fp); return [false, 'SMTP STARTTLS failed']; }
    if (!@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) { fclose($fp); return [false, 'SMTP TLS negotiation failed']; }
    fwrite($fp, "EHLO localhost\r\n");
    if (!smtp_expect($fp, [250])) { fclose($fp); return [false, 'SMTP EHLO after TLS failed']; }
  }

  if ($username !== '') {
    fwrite($fp, "AUTH LOGIN\r\n");
    if (!smtp_expect($fp, [334])) { fclose($fp); return [false, 'SMTP AUTH LOGIN failed']; }
    fwrite($fp, base64_encode($username) . "\r\n");
    if (!smtp_expect($fp, [334])) { fclose($fp); return [false, 'SMTP username rejected']; }
    fwrite($fp, base64_encode($password) . "\r\n");
    if (!smtp_expect($fp, [235])) { fclose($fp); return [false, 'SMTP password rejected']; }
  }

  $boundary = 'b_' . bin2hex(random_bytes(8));
  $from = mail_from_email();
  $plain = $text !== '' ? $text : trim(strip_tags(str_replace(['<br>','<br/>','<br />'], "\n", $html)));
  $data = "From: " . mail_from_name() . " <{$from}>\r\n";
  $data .= "To: <{$to}>\r\n";
  $data .= "Subject: {$subject}\r\n";
  $data .= "MIME-Version: 1.0\r\n";
  $data .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n\r\n";
  $data .= "--{$boundary}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$plain}\r\n";
  $data .= "--{$boundary}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n";
  $data .= "--{$boundary}--\r\n";

  fwrite($fp, "MAIL FROM:<{$from}>\r\n");
  if (!smtp_expect($fp, [250])) { fclose($fp); return [false, 'SMTP MAIL FROM failed']; }
  fwrite($fp, "RCPT TO:<{$to}>\r\n");
  if (!smtp_expect($fp, [250, 251])) { fclose($fp); return [false, 'SMTP RCPT TO failed']; }
  fwrite($fp, "DATA\r\n");
  if (!smtp_expect($fp, [354])) { fclose($fp); return [false, 'SMTP DATA failed']; }
  fwrite($fp, $data . "\r\n.\r\n");
  if (!smtp_expect($fp, [250])) { fclose($fp); return [false, 'SMTP message rejected']; }
  fwrite($fp, "QUIT\r\n");
  fclose($fp);
  return [true, 'sent'];
}

function send_app_mail(string $to, string $subject, string $html, string $text='', ?string $entityType=null, ?int $entityId=null): bool {
  $driver = mail_driver();
  if (!$to) return false;
  if (!mail_enabled() && $driver !== 'log') {
    log_mail_attempt($to, $subject, $driver, 'skipped', 'Mail delivery is disabled', $entityType, $entityId);
    return false;
  }
  if ($driver === 'log') {
    log_mail_attempt($to, $subject, $driver, 'logged', '', $entityType, $entityId);
    return true;
  }
  if ($driver === 'mail') {
    $ok = @mail($to, $subject, $html, app_mail_headers());
    log_mail_attempt($to, $subject, $driver, $ok ? 'sent' : 'failed', $ok ? '' : 'PHP mail() returned false', $entityType, $entityId);
    return $ok;
  }
  if ($driver === 'smtp') {
    [$ok, $err] = smtp_send_mail($to, $subject, $html, $text);
    log_mail_attempt($to, $subject, $driver, $ok ? 'sent' : 'failed', $err, $entityType, $entityId);
    return $ok;
  }
  log_mail_attempt($to, $subject, $driver, 'failed', 'Unknown mail driver', $entityType, $entityId);
  return false;
}

function notification_email_subject(string $title): string { return APP_NAME . ' · ' . $title; }
function notification_email_html(string $title, string $body, ?string $actionUrl=null): string {
  $html = '<div style="font-family:Arial,sans-serif;line-height:1.5;color:#111">';
  $html .= '<h2 style="margin:0 0 12px">' . e($title) . '</h2>';
  $html .= '<p style="margin:0 0 12px">' . nl2br(e($body)) . '</p>';
  if ($actionUrl) $html .= '<p style="margin:16px 0"><a href="' . e($actionUrl) . '" style="display:inline-block;padding:10px 14px;background:#0f766e;color:#fff;text-decoration:none;border-radius:8px">Open in app</a></p>';
  $html .= '<p style="margin-top:20px;color:#666;font-size:12px">This is an automated message from ' . e(APP_NAME) . '.</p></div>';
  return $html;
}

function notify_user(int $userId, string $title, string $body='', string $type='general', ?string $entityType=null, ?int $entityId=null, ?string $actionUrl=null, ?int $actorUserId=null): bool {
  global $mysqli;
  if ($userId <= 0) return false;
  $stmt = $mysqli->prepare("INSERT INTO notifications (user_id,title,body,type,entity_type,entity_id,action_url,actor_user_id,is_read) VALUES (?,?,?,?,?,?,?,?,0)");
  if ($stmt) {
    $stmt->bind_param('issssisi', $userId, $title, $body, $type, $entityType, $entityId, $actionUrl, $actorUserId);
    @$stmt->execute();
    $stmt->close();
  }
  $user = get_user_brief($userId);
  if ($user && (int)($user['active'] ?? 0) === 1 && !empty($user['email']) && wants_email_notifications($userId)) {
    send_app_mail((string)$user['email'], notification_email_subject($title), notification_email_html($title, $body, $actionUrl), $body, $entityType, $entityId);
  }
  return true;
}

function unread_notification_count(int $userId=0): int {
  global $mysqli;
  $userId = $userId > 0 ? $userId : (int)(user()['id'] ?? 0);
  if ($userId <= 0) return 0;
  $stmt = $mysqli->prepare('SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0');
  if (!$stmt) return 0;
  $stmt->bind_param('i', $userId);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  return (int)($row['c'] ?? 0);
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

function create_password_reset_token(int $userId, int $minutes=60): array {
  global $mysqli;
  $selector = bin2hex(random_bytes(8));
  $validator = bin2hex(random_bytes(32));
  $hash = hash('sha256', $validator);
  $expires = date('Y-m-d H:i:s', time() + max(5, $minutes) * 60);
  if ($stmt = $mysqli->prepare('INSERT INTO password_resets (user_id, selector, token_hash, expires_at) VALUES (?,?,?,?)')) {
    $stmt->bind_param('isss', $userId, $selector, $hash, $expires);
    @$stmt->execute();
    $stmt->close();
  }
  return ['selector'=>$selector, 'validator'=>$validator, 'expires_at'=>$expires];
}
function consume_password_reset_token(string $selector, string $validator): ?array {
  global $mysqli;
  $hash = hash('sha256', $validator);
  $stmt = $mysqli->prepare('SELECT id,user_id,selector,expires_at,used_at FROM password_resets WHERE selector=? AND token_hash=? LIMIT 1');
  if (!$stmt) return null;
  $stmt->bind_param('ss', $selector, $hash);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if (!$row) return null;
  if (!empty($row['used_at'])) return null;
  if (strtotime((string)$row['expires_at']) < time()) return null;
  return $row;
}
function mark_password_reset_used(int $id): void {
  global $mysqli;
  $stmt = $mysqli->prepare('UPDATE password_resets SET used_at=NOW() WHERE id=?');
  if (!$stmt) return;
  $stmt->bind_param('i', $id);
  @$stmt->execute();
  $stmt->close();
}

function record_login_attempt(string $email, bool $wasSuccessful): void {
  global $mysqli;
  $ip = client_ip();
  $flag = $wasSuccessful ? 1 : 0;
  $stmt = $mysqli->prepare('INSERT INTO login_attempts (email, ip_address, was_successful) VALUES (?,?,?)');
  if (!$stmt) return;
  $stmt->bind_param('ssi', $email, $ip, $flag);
  @$stmt->execute();
  $stmt->close();
}
function login_is_locked(string $email, int &$retryAfter=0, int $limit=5, int $windowMinutes=15): bool {
  global $mysqli;
  $ip = client_ip();
  $stmt = $mysqli->prepare('SELECT COUNT(*) c, MIN(created_at) first_at FROM login_attempts WHERE was_successful=0 AND created_at >= (NOW() - INTERVAL ? MINUTE) AND (email=? OR ip_address=?)');
  if (!$stmt) return false;
  $stmt->bind_param('iss', $windowMinutes, $email, $ip);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $count = (int)($row['c'] ?? 0);
  if ($count < $limit) return false;
  $firstAt = strtotime((string)($row['first_at'] ?? 'now'));
  $retryAfter = max(0, ($firstAt + ($windowMinutes * 60)) - time());
  return true;
}

function fetch_doctor_master_records(): array {
  global $mysqli;
  $rows = [];
  $hasDoctorsMaster = db_column_exists('doctors_master', 'doctor_name');
  if ($hasDoctorsMaster) {
    $sql = "SELECT id, doctor_name, email, hospital_name, city FROM doctors_master WHERE active=1 ORDER BY doctor_name ASC LIMIT 1000";
  } else {
    $sql = "SELECT id, dr_name AS doctor_name, email, hospital_address AS hospital_name, place AS city FROM doctors_masterlist ORDER BY dr_name ASC LIMIT 1000";
  }
  if ($res = $mysqli->query($sql)) {
    while ($row = $res->fetch_assoc()) $rows[] = $row;
  }
  return $rows;
}
function fetch_master_options(string $table): array {
  global $mysqli;
  $rows = [];
  $labelCol = $table === 'medicines_master' ? 'name' : 'name';
  if ($res = $mysqli->query("SELECT id, {$labelCol} AS label FROM {$table} WHERE active=1 ORDER BY {$labelCol} ASC LIMIT 1000")) {
    while ($row = $res->fetch_assoc()) $rows[] = $row;
  }
  return $rows;
}

function normalize_upload_path(?string $path): ?string {
  $path = trim((string)$path);
  if ($path === '') return null;
  return str_replace('\\', '/', $path);
}
function save_uploaded_attachment(array $file, int $userId, array &$errors=[]): ?string {
  if (empty($file['name']) || empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) return null;
  $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
  $allowed = ['pdf','jpg','jpeg','png'];
  if (!in_array($ext, $allowed, true)) {
    $errors[] = 'Invalid attachment type. Allowed: PDF/JPG/PNG.';
    return null;
  }
  if (!is_dir(ATTACH_DIR)) @mkdir(ATTACH_DIR, 0775, true);
  $fname = 'att_' . $userId . '_' . time() . '_' . substr(bin2hex(random_bytes(4)),0,8) . '.' . $ext;
  $dest = rtrim(ATTACH_DIR, '/\\') . DIRECTORY_SEPARATOR . $fname;
  if (!@move_uploaded_file($file['tmp_name'], $dest)) {
    $errors[] = 'Failed to upload attachment.';
    return null;
  }
  return 'uploads/attachments/' . $fname;
}

?>