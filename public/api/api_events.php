<?php
// public/api_events.php — replace entire file to ensure IDs are sent and strings are safe
require_once __DIR__.'/../../init.php'; require_login();
header('Content-Type: application/json; charset=utf-8');

$uid = (int)user()['id'];
$role = (string)(user()['role'] ?? 'employee');
$isManager = ($role === 'manager');
$isDistrict = ($role === 'district_manager');

$sql = "SELECT e.id,e.title,e.city,e.doctor_id,
               e.start, COALESCE(e.end,e.start) AS end, e.all_day,
               d.dr_name
        FROM events e
        LEFT JOIN doctors_masterlist d ON d.id=e.doctor_id";

$hasEA = false;
if ($r = $mysqli->query("SHOW TABLES LIKE 'event_attendees'")) {
  $hasEA = ($r->num_rows > 0);
}

if (!$isManager) {
  // Include: own tasks, tasks of assigned employees (district manager), and tasks where user is an attendee.
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

$res = $mysqli->query($sql);
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
echo json_encode($out);
