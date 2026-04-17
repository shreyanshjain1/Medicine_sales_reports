<?php
require_once __DIR__.'/../../init.php';
api_require_login();
api_require_method('GET');
api_boot();

$uid = (int)user()['id'];
$role = my_role();
$isManager = is_manager();
$isDistrict = is_district_manager();

$sql = "SELECT e.id,e.title,e.city,e.doctor_id,
               e.start, COALESCE(e.end,e.start) AS end, e.all_day,
               d.dr_name
        FROM events e
        LEFT JOIN doctors_masterlist d ON d.id=e.doctor_id";


$from = api_get_string($_GET, 'from', false, 25, 'from');
$to = api_get_string($_GET, 'to', false, 25, 'to');
$dateWhere = '';
if ($from !== '') {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    api_json_error('Validation failed.', 422, ['from must be in YYYY-MM-DD format.']);
  }
  $dateWhere .= " AND DATE(e.start) >= '".$mysqli->real_escape_string($from)."'";
}
if ($to !== '') {
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    api_json_error('Validation failed.', 422, ['to must be in YYYY-MM-DD format.']);
  }
  $dateWhere .= " AND DATE(e.start) <= '".$mysqli->real_escape_string($to)."'";
}

$hasEA = false;
if ($r = $mysqli->query("SHOW TABLES LIKE 'event_attendees'")) {
  $hasEA = ($r->num_rows > 0);
}

if (!$isManager) {
  if ($isDistrict) {
    $sql .= " WHERE (e.user_id={$uid} OR e.user_id IN (SELECT id FROM users WHERE district_manager_id={$uid})";
    if ($hasEA) $sql .= " OR EXISTS (SELECT 1 FROM event_attendees ea WHERE ea.event_id=e.id AND ea.user_id={$uid})";
    $sql .= ")";
  } else {
    $sql .= " WHERE (e.user_id={$uid}";
    if ($hasEA) $sql .= " OR EXISTS (SELECT 1 FROM event_attendees ea WHERE ea.event_id=e.id AND ea.user_id={$uid})";
    $sql .= ")";
  }
}

$sql .= $dateWhere;

$res = $mysqli->query($sql);
if (!$res) {
  api_json_error('Unable to load events.', 500, [$mysqli->error ?: 'Query failed']);
}

$out = [];
while($r = $res->fetch_assoc()){
  $title = $r['title'] ?: 'Task';
  if ($r['dr_name'] && stripos($title, $r['dr_name'])===false) $title .= ' — '.$r['dr_name'];
  $out[] = [
    'id'      => (int)$r['id'],
    'title'   => $title,
    'start'   => (string)$r['start'],
    'end'     => (string)$r['end'],
    'allDay'  => (bool)$r['all_day'],
  ];
}
api_json_success(['events' => $out], 'Events loaded.');
