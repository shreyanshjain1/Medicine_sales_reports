<?php
require_once __DIR__ . '/../../init.php';
require_login();
csrf_verify();

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
    $types .= is_int($val) ? 'i' : 's';
  }
  if (!$cols) return 0;
  $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ")";
  $stmt = $mysqli->prepare($sql);
  if (!$stmt) return 0;
  $bind = [$types];
  foreach ($vals as $i => $v) $bind[] = &$vals[$i];
  @call_user_func_array([$stmt, 'bind_param'], $bind);
  $ok = $stmt->execute();
  $id = $ok ? (int)$stmt->insert_id : 0;
  $stmt->close();
  return $id;
}

function recurring_dates(string $pattern, string $start, ?string $until): array {
  if ($pattern === 'none' || $start === '') return [];
  $dates = [];
  $base = strtotime($start);
  if (!$base) return [];
  $untilTs = $until ? strtotime($until . ' 23:59:59') : strtotime('+30 days', $base);
  $cursor = $base;
  $count = 0;
  while ($count < 12) {
    $cursor = match ($pattern) {
      'daily' => strtotime('+1 day', $cursor),
      'weekly' => strtotime('+1 week', $cursor),
      'monthly' => strtotime('+1 month', $cursor),
      default => 0,
    };
    if (!$cursor || $cursor > $untilTs) break;
    $dates[] = date('Y-m-d H:i:s', $cursor);
    $count++;
  }
  return $dates;
}

$title = trim((string)post('title', ''));
$city  = trim((string)post('city', ''));
$doctor_id = (int)(post('doctor_id', '') ?: 0);
$start = trim((string)post('start', ''));
$end   = trim((string)post('end', ''));
$all   = post('all_day') ? 1 : 0;
$purpose = trim((string)post('purpose', ''));
$medicine_name = trim((string)post('medicine_name', ''));
$hospital_name = trim((string)post('hospital_name', ''));
$visit_datetime = trim((string)post('visit_datetime', ''));
$summary = trim((string)post('summary', ''));
$remarks = trim((string)post('remarks', ''));
$attendees = post('attendees', []);
$status = trim((string)post('status', 'planned')) ?: 'planned';
$recurrence_pattern = trim((string)post('recurrence_pattern', 'none')) ?: 'none';
$recurrence_until = trim((string)post('recurrence_until', ''));
if (!is_array($attendees)) $attendees = [];

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
if ($start === '') { header('Location: ' . url('dashboard.php')); exit; }

$uid = (int)(user()['id'] ?? 0);
$payload = [
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
  'status' => $status,
  'recurrence_pattern' => $recurrence_pattern,
  'recurrence_until' => ($recurrence_until === '' ? null : $recurrence_until),
  'start' => $start,
  'end' => ($end === '' ? null : $end),
  'all_day' => $all,
];
$newId = safe_insert('events', $payload);
if ($newId <= 0) {
  safe_insert('events', [
    'user_id' => $uid,
    'title' => $title,
    'start' => $start,
    'end' => ($end === '' ? null : $end),
    'all_day' => $all,
  ]);
}

$mysqli->query("CREATE TABLE IF NOT EXISTS event_attendees (
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY(event_id,user_id),
  INDEX(user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

if ($newId > 0 && $attendees) {
  $stmtA = $mysqli->prepare("INSERT IGNORE INTO event_attendees (event_id,user_id) VALUES (?,?)");
  if ($stmtA) {
    foreach ($attendees as $aidRaw) {
      $aid = (int)$aidRaw;
      if ($aid <= 0 || $aid === $uid) continue;
      $stmtA->bind_param('ii', $newId, $aid);
      @$stmtA->execute();
    }
    $stmtA->close();
  }
}

if ($newId > 0 && $recurrence_pattern !== 'none') {
  $occurrences = recurring_dates($recurrence_pattern, $start, $recurrence_until ?: null);
  foreach ($occurrences as $occStart) {
    $occEnd = $end !== '' ? date('Y-m-d H:i:s', strtotime($occStart) + max(0, strtotime($end) - strtotime($start))) : null;
    $occVisit = $visit_datetime !== '' ? date('Y-m-d H:i:s', strtotime($occStart) + max(0, strtotime($visit_datetime) - strtotime($start))) : $occStart;
    $childId = safe_insert('events', [
      'user_id' => $uid,
      'parent_event_id' => $newId,
      'title' => $title,
      'city' => $city,
      'doctor_id' => $doctor_id,
      'purpose' => $purpose,
      'medicine_name' => $medicine_name,
      'hospital_name' => $hospital_name,
      'visit_datetime' => $occVisit,
      'summary' => $summary,
      'remarks' => $remarks,
      'status' => 'planned',
      'recurrence_pattern' => 'none',
      'start' => $occStart,
      'end' => $occEnd,
      'all_day' => $all,
    ]);
    if ($childId > 0 && $attendees) {
      $stmtA = $mysqli->prepare("INSERT IGNORE INTO event_attendees (event_id,user_id) VALUES (?,?)");
      if ($stmtA) {
        foreach ($attendees as $aidRaw) {
          $aid = (int)$aidRaw;
          if ($aid <= 0 || $aid === $uid) continue;
          $stmtA->bind_param('ii', $childId, $aid);
          @$stmtA->execute();
        }
        $stmtA->close();
      }
    }
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
