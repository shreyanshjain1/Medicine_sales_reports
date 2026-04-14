<?php
require_once __DIR__ . '/../init.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method not allowed');
}

csrf_verify();

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
  http_response_code(400);
  exit('Bad request');
}

$role = (string)(user()['role'] ?? 'employee');
$uid  = (int)(user()['id'] ?? 0);

// Owner can delete; manager can delete all
$stmt = $mysqli->prepare("DELETE FROM events WHERE id=? AND (user_id=? OR ?='manager')");
if (!$stmt) {
  http_response_code(500);
  exit('DB error');
}
$stmt->bind_param('iis', $id, $uid, $role);
$stmt->execute();
$stmt->close();

header('Location: ' . url('dashboard.php'));
exit;
