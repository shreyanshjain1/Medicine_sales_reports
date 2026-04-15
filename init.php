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
   Workflow / review helpers
   --------------------------- */
function ensure_workflow_schema(): void {
  if (!empty($_SESSION['_workflow_schema_v9'])) return;
  $_SESSION['_workflow_schema_v9'] = 1;

  _try_sql("CREATE TABLE IF NOT EXISTS report_status_history (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    report_id INT NOT NULL,
    actor_user_id INT NULL,
    old_status VARCHAR(40) NULL,
    new_status VARCHAR(40) NOT NULL,
    comment TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_rsh_report (report_id),
    INDEX idx_rsh_created (created_at)
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
    INDEX idx_audit_entity (entity_type, entity_id),
    INDEX idx_audit_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  _try_sql("CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    body TEXT NULL,
    type VARCHAR(80) NULL,
    entity_type VARCHAR(80) NULL,
    entity_id INT NULL,
    action_url VARCHAR(255) NULL,
    is_read TINYINT(1) NOT NULL DEFAULT 0,
    created_by INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_notifications_user (user_id),
    INDEX idx_notifications_read (is_read),
    INDEX idx_notifications_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
ensure_workflow_schema();

function request_ip_address(): string {
  return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
}

if (!function_exists('log_audit')) {
  function log_audit(string $action, ?string $entityType=null, ?int $entityId=null, $details=null, ?int $userId=null): bool {
    global $mysqli;
    if (!db_column_exists('audit_logs', 'action')) return false;
    $uid = $userId ?? (int)(user()['id'] ?? 0);
    $detailsText = is_string($details) ? $details : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $mysqli->prepare("INSERT INTO audit_logs (user_id, action, entity_type, entity_id, details, ip_address) VALUES (?,?,?,?,?,?)");
    if (!$stmt) return false;
    $ip = request_ip_address();
    $stmt->bind_param('ississ', $uid, $action, $entityType, $entityId, $detailsText, $ip);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
  }
}

if (!function_exists('record_report_status')) {
  function record_report_status(int $reportId, ?string $oldStatus, string $newStatus, string $comment='', ?int $actorUserId=null): bool {
    global $mysqli;
    if (!db_column_exists('report_status_history', 'report_id')) return false;
    $uid = $actorUserId ?? (int)(user()['id'] ?? 0);
    $stmt = $mysqli->prepare("INSERT INTO report_status_history (report_id, actor_user_id, old_status, new_status, comment) VALUES (?,?,?,?,?)");
    if (!$stmt) return false;
    $stmt->bind_param('iisss', $reportId, $uid, $oldStatus, $newStatus, $comment);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
  }
}

if (!function_exists('notify_user')) {
  function notify_user(int $userId, string $title, string $body='', ?string $type=null, ?string $entityType=null, ?int $entityId=null, ?string $actionUrl=null, ?int $createdBy=null): bool {
    global $mysqli;
    if ($userId <= 0 || !db_column_exists('notifications', 'user_id')) return false;
    $by = $createdBy ?? (int)(user()['id'] ?? 0);
    $stmt = $mysqli->prepare("INSERT INTO notifications (user_id, title, body, type, entity_type, entity_id, action_url, created_by) VALUES (?,?,?,?,?,?,?,?)");
    if (!$stmt) return false;
    $stmt->bind_param('issssisi', $userId, $title, $body, $type, $entityType, $entityId, $actionUrl, $by);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
  }
}

if (!function_exists('notifications_unread_count')) {
  function notifications_unread_count(?int $userId=null): int {
    global $mysqli;
    $uid = $userId ?? (int)(user()['id'] ?? 0);
    if ($uid <= 0 || !db_column_exists('notifications', 'user_id')) return 0;
    $stmt = $mysqli->prepare("SELECT COUNT(*) c FROM notifications WHERE user_id=? AND is_read=0");
    if (!$stmt) return 0;
    $stmt->bind_param('i', $uid);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return (int)($row['c'] ?? 0);
  }
}

if (!function_exists('mark_notification_read')) {
  function mark_notification_read(int $notificationId, ?int $userId=null): bool {
    global $mysqli;
    $uid = $userId ?? (int)(user()['id'] ?? 0);
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read=1 WHERE id=? AND user_id=?");
    if (!$stmt) return false;
    $stmt->bind_param('ii', $notificationId, $uid);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
  }
}

if (!function_exists('mark_all_notifications_read')) {
  function mark_all_notifications_read(?int $userId=null): bool {
    global $mysqli;
    $uid = $userId ?? (int)(user()['id'] ?? 0);
    $stmt = $mysqli->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0");
    if (!$stmt) return false;
    $stmt->bind_param('i', $uid);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
  }
}

if (!function_exists('get_report_timeline')) {
  function get_report_timeline(int $reportId, int $limit=50): array {
    global $mysqli;
    $reportId = max(0, $reportId);
    if ($reportId <= 0) return [];
    $limit = max(1, min(200, $limit));
    $items = [];

    if (db_column_exists('report_status_history', 'report_id')) {
      $stmt = $mysqli->prepare("SELECT h.id, h.old_status, h.new_status, h.comment, h.created_at, u.name AS actor_name
        FROM report_status_history h
        LEFT JOIN users u ON u.id = h.actor_user_id
        WHERE h.report_id=?
        ORDER BY h.created_at DESC, h.id DESC
        LIMIT {$limit}");
      if ($stmt) {
        $stmt->bind_param('i', $reportId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
          $items[] = [
            'kind' => 'status',
            'icon' => 'status',
            'title' => 'Status changed to ' . ucfirst(str_replace('_', ' ', (string)$row['new_status'])),
            'actor' => (string)($row['actor_name'] ?: 'System'),
            'meta' => (($row['old_status'] ?? '') ? ('From ' . ucfirst(str_replace('_', ' ', (string)$row['old_status'])) . ' → ') : '') . ucfirst(str_replace('_', ' ', (string)$row['new_status'])),
            'comment' => (string)($row['comment'] ?? ''),
            'created_at' => (string)$row['created_at'],
          ];
        }
        $stmt->close();
      }
    }

    if (db_column_exists('audit_logs', 'action')) {
      $stmt = $mysqli->prepare("SELECT a.id, a.action, a.details, a.created_at, u.name AS actor_name
        FROM audit_logs a
        LEFT JOIN users u ON u.id = a.user_id
        WHERE a.entity_type='report' AND a.entity_id=?
        ORDER BY a.created_at DESC, a.id DESC
        LIMIT {$limit}");
      if ($stmt) {
        $stmt->bind_param('i', $reportId);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
          $title = ucwords(str_replace(['_', '-'], ' ', (string)$row['action']));
          $items[] = [
            'kind' => 'audit',
            'icon' => 'audit',
            'title' => $title,
            'actor' => (string)($row['actor_name'] ?: 'System'),
            'meta' => 'Audit activity',
            'comment' => (string)($row['details'] ?? ''),
            'created_at' => (string)$row['created_at'],
          ];
        }
        $stmt->close();
      }
    }

    usort($items, function($a, $b) {
      return strcmp((string)$b['created_at'], (string)$a['created_at']);
    });
    return array_slice($items, 0, $limit);
  }
}

if (!function_exists('report_review_summary')) {
  function report_review_summary(?int $reportId=null): array {
    global $mysqli;
    $summary = [
      'pending' => 0,
      'approved' => 0,
      'needs_changes' => 0,
      'overdue_pending' => 0,
    ];
    $where = " WHERE " . reports_scope_where('r');
    if ($reportId !== null) {
      $rid = (int)$reportId;
      $where .= " AND r.id = {$rid}";
    }
    $sql = "SELECT
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' THEN 1 ELSE 0 END) AS pending_count,
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN 1 ELSE 0 END) AS approved_count,
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='needs_changes' THEN 1 ELSE 0 END) AS needs_changes_count,
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND DATE(COALESCE(r.visit_datetime, r.created_at)) <= DATE_SUB(CURDATE(), INTERVAL 3 DAY) THEN 1 ELSE 0 END) AS overdue_pending_count
      FROM reports r {$where}";
    $row = $mysqli->query($sql);
    if ($row) {
      $data = $row->fetch_assoc() ?: [];
      $summary['pending'] = (int)($data['pending_count'] ?? 0);
      $summary['approved'] = (int)($data['approved_count'] ?? 0);
      $summary['needs_changes'] = (int)($data['needs_changes_count'] ?? 0);
      $summary['overdue_pending'] = (int)($data['overdue_pending_count'] ?? 0);
    }
    return $summary;
  }
}

