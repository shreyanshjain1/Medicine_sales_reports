<?php
require_once __DIR__ . '/config.php';
session_start();

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) die('DB Connection failed: ' . $mysqli->connect_error);
$mysqli->set_charset('utf8mb4');

require_once __DIR__ . '/app/components/ui_components.php';


if (!defined('APP_SCHEMA_VERSION')) define('APP_SCHEMA_VERSION', 'v14');

function ensure_app_directories(): void {
  foreach ([
    __DIR__ . '/uploads',
    __DIR__ . '/uploads/attachments',
    __DIR__ . '/uploads/signatures',
    __DIR__ . '/storage',
    __DIR__ . '/storage/logs',
  ] as $dir) {
    if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
  }
}

ensure_app_directories();


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


function approval_sla_thresholds(): array {
  return ['warning_hours'=>24, 'overdue_hours'=>48];
}

function approval_scope_sql(string $alias='r'): string {
  return reports_scope_where($alias);
}

function fetch_approval_sla_summary(): array {
  global $mysqli;
  $scope = approval_scope_sql('r');
  $th = approval_sla_thresholds();
  $sql = "SELECT
    SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' THEN 1 ELSE 0 END) AS pending_total,
    SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='needs_changes' THEN 1 ELSE 0 END) AS needs_changes_total,
    SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN 1 ELSE 0 END) AS approved_total,
    SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) >= {$th['warning_hours']} THEN 1 ELSE 0 END) AS aging_warning,
    SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) >= {$th['overdue_hours']} THEN 1 ELSE 0 END) AS overdue_total,
    AVG(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) END) AS avg_hours_to_approve
    FROM reports r WHERE {$scope}";
  $row = $mysqli->query($sql)->fetch_assoc() ?: [];
  return [
    'pending_total'=>(int)($row['pending_total'] ?? 0),
    'needs_changes_total'=>(int)($row['needs_changes_total'] ?? 0),
    'approved_total'=>(int)($row['approved_total'] ?? 0),
    'aging_warning'=>(int)($row['aging_warning'] ?? 0),
    'overdue_total'=>(int)($row['overdue_total'] ?? 0),
    'avg_hours_to_approve'=>round((float)($row['avg_hours_to_approve'] ?? 0),1),
  ];
}

function fetch_approval_aging_buckets(): array {
  global $mysqli;
  $scope = approval_scope_sql('r');
  $sql = "SELECT
    SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) < 24 THEN 1 ELSE 0 END) AS lt24,
    SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) BETWEEN 24 AND 47 THEN 1 ELSE 0 END) AS h24_48,
    SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) BETWEEN 48 AND 71 THEN 1 ELSE 0 END) AS h48_72,
    SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) >= 72 THEN 1 ELSE 0 END) AS gte72
    FROM reports r WHERE {$scope}";
  $row = $mysqli->query($sql)->fetch_assoc() ?: [];
  return [
    ['label'=>'< 24h','count'=>(int)($row['lt24'] ?? 0),'tone'=>'ok'],
    ['label'=>'24-48h','count'=>(int)($row['h24_48'] ?? 0),'tone'=>'watch'],
    ['label'=>'48-72h','count'=>(int)($row['h48_72'] ?? 0),'tone'=>'risk'],
    ['label'=>'>= 72h','count'=>(int)($row['gte72'] ?? 0),'tone'=>'danger'],
  ];
}

function fetch_overdue_reports(int $limit=10): array {
  global $mysqli;
  $scope = approval_scope_sql('r');
  $limit = max(1, min(50, $limit));
  $sql = "SELECT r.id, r.doctor_name, r.medicine_name, r.hospital_name, r.visit_datetime, IFNULL(u.name,'—') AS employee,
    TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) AS age_hours,
    COALESCE(NULLIF(r.status,''),'pending') AS status
    FROM reports r
    LEFT JOIN users u ON u.id=r.user_id
    WHERE {$scope} AND COALESCE(NULLIF(r.status,''),'pending')='pending'
    AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) >= 48
    ORDER BY age_hours DESC, IFNULL(r.visit_datetime, r.created_at) DESC LIMIT {$limit}";
  $rows=[]; if($res=$mysqli->query($sql)){ while($row=$res->fetch_assoc()) $rows[]=$row; }
  return $rows;
}

function fetch_reviewer_backlog(int $limit=10): array {
  global $mysqli;
  $scope = approval_scope_sql('r');
  $sql = "SELECT IFNULL(u.name,'Unassigned') AS employee, COUNT(*) AS pending_count,
    SUM(CASE WHEN TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) >= 48 THEN 1 ELSE 0 END) AS overdue_count
    FROM reports r
    LEFT JOIN users u ON u.id=r.user_id
    WHERE {$scope} AND COALESCE(NULLIF(r.status,''),'pending') IN ('pending','needs_changes')
    GROUP BY r.user_id, u.name
    ORDER BY overdue_count DESC, pending_count DESC, employee ASC
    LIMIT {$limit}";
  $rows=[]; if($res=$mysqli->query($sql)){ while($row=$res->fetch_assoc()) $rows[]=$row; }
  return $rows;
}

?>