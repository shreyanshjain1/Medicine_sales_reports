<?php
// public/event_add.php — add task (DB-backed) with backward-compatible insert
require_once __DIR__ . '/../../init.php';
require_login();
csrf_verify();

/**
 * Get existing columns for a table (cached).
 */
function table_columns(string $table): array {
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
  while ($r = $res->fetch_assoc()) $cols[] = $r['COLUMN_NAME'];
  $stmt->close();
  return $cache[$key] = $cols;
}

/**
 * Insert into $table only using columns that exist.
 * $data is [col => value]. Returns inserted id or 0.
 */
function safe_insert(string $table, array $data): int {
  global $mysqli;
  $existing = array_flip(table_columns($table));
  if (!$existing) return 0;

  $cols = [];
  $vals = [];
  $types = '';

  foreach ($data as $col => $val) {
    if (!isset($existing[$col])) continue;
    $cols[] = $col;
    $vals[] = $val;
    // Best-effort typing: ints for numeric-ish, else string
    if (is_int($val)) $types .= 'i';
    else $types .= 's';
  }

  if (!$cols) return 0;
  $placeholders = implode(',', array_fill(0, count($cols), '?'));
  $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES ({$placeholders})";
  $stmt = $mysqli->prepare($sql);
  if (!$stmt) {
    error_log("safe_insert prepare failed for {$table}: " . $mysqli->error);
    return 0;
  }

  // bind_param requires references
  $bind = [$types];
  for ($i = 0; $i < count($vals); $i++) {
    $bind[] = &$vals[$i];
  }
  @call_user_func_array([$stmt, 'bind_param'], $bind);
  $ok = $stmt->execute();
  if (!$ok) {
    error_log("safe_insert execute failed for {$table}: " . $stmt->error);
    $stmt->close();
    return 0;
  }
  $id = (int)$stmt->insert_id;
  $stmt->close();
  return $id;
}

$title = trim((string)post('title', ''));
$city  = trim((string)post('city', ''));
$doctor_id = (int)(post('doctor_id', '') ?: 0);
$start = trim((string)post('start', ''));
$end   = trim((string)post('end', ''));
$all   = post('all_day') ? 1 : 0;

// Extra task fields (optional)
$purpose = trim((string)post('purpose', ''));
$medicine_name = trim((string)post('medicine_name', ''));
$hospital_name = trim((string)post('hospital_name', ''));
$visit_datetime = trim((string)post('visit_datetime', ''));
$summary = trim((string)post('summary', ''));
$remarks = trim((string)post('remarks', ''));

// Multi-rep attendees (optional)
$attendees = post('attendees', []);
if (!is_array($attendees)) $attendees = [];

// If doctor selected but no title, auto-title
if ($doctor_id && $title === '') {
  $stmt = $mysqli->prepare("SELECT dr_name, hospital_address FROM doctors_masterlist WHERE id=?");
  if ($stmt) {
    $stmt->bind_param('i', $doctor_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($res) {
      $title = 'Visit: ' . (string)$res['dr_name'];
      if ($hospital_name === '' && !empty($res['hospital_address'])) $hospital_name = (string)$res['hospital_address'];
    }
  }
}

if ($title === '') $title = 'Task';
if ($visit_datetime === '' && $start !== '') $visit_datetime = $start;

// Start is required for calendar; if missing just return to dashboard
if ($start === '') {
  header('Location: ' . url('dashboard.php'));
  exit;
}

$uid = (int)(user()['id'] ?? 0);

// Backward-compatible insert: only write columns that exist in the current DB.
$newId = safe_insert('events', [
  'user_id' => $uid,
  'title' => $title,
  'city' => $city,
  'doctor_id' => $doctor_id,
  'purpose' => $purpose,
  'medicine_name' => $medicine_name,
  'hospital_name' => $hospital_name,
  'visit_datetime' => $visit_datetime,
  'summary' => $summary,
  'remarks' => $remarks,
  'start' => $start,
  'end' => ($end === '' ? null : $end),
  'all_day' => $all,
]);

if ($newId <= 0) {
  // As a last fallback, attempt minimal insert that most older schemas have.
  safe_insert('events', [
    'user_id' => $uid,
    'title' => $title,
    'start' => $start,
    'end' => ($end === '' ? null : $end),
    'all_day' => $all,
  ]);
}

// Save attendees (best-effort, never block task creation)
if ($newId > 0 && $attendees) {
  // Create table if not exists (safe on older installs)
  $mysqli->query("CREATE TABLE IF NOT EXISTS event_attendees (
    event_id INT NOT NULL,
    user_id INT NOT NULL,
    added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(event_id,user_id),
    INDEX(user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

  $stmtA = $mysqli->prepare("INSERT IGNORE INTO event_attendees (event_id,user_id) VALUES (?,?)");
  if ($stmtA) {
    foreach ($attendees as $aidRaw) {
      $aid = (int)$aidRaw;
      if ($aid <= 0) continue;
      if ($aid === $uid) continue;
      $stmtA->bind_param('ii', $newId, $aid);
      @$stmtA->execute();
    }
    $stmtA->close();
  }
}

if ($newId > 0 && $attendees) {
  $attendeeIds = array_values(array_unique(array_filter(array_map('intval', $attendees))));
  foreach ($attendeeIds as $aid) {
    if ($aid <= 0 || $aid === $uid) continue;
    notify_user(
      $aid,
      'New task assigned',
      'You were added to the task "' . $title . '".',
      'task_assigned',
      'task',
      $newId,
      url('tasks/task_view.php?id=' . $newId),
      $uid
    );
  }
}

header('Location: ' . url('dashboard.php'));
exit;
