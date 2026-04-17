<?php
require_once __DIR__ . '/../../init.php';

if (!can_use_dev_tools()) {
  http_response_code(403);
  exit('Developer diagnostics are disabled or not allowed from this environment.');
}
if (!is_manager()) {
  http_response_code(403);
  exit('Manager session required.');
}
$key = trim((string)($_GET['key'] ?? ''));
if (!tool_key_valid($key, (string)DEV_TOOL_KEY)) {
  http_response_code(403);
  exit('Invalid tool key.');
}

$scope = strtolower(trim((string)($_GET['scope'] ?? 'summary')));
if (!in_array($scope, ['summary', 'users'], true)) {
  $scope = 'summary';
}

if (function_exists('log_audit')) {
  log_audit('dev_tool_diagnose', 'diagnose', null, 'Diagnose tool opened: ' . $scope);
}

header('Content-Type: text/plain; charset=utf-8');
echo 'Diagnostics summary' . PHP_EOL;
echo 'Environment: ' . app_env_value() . PHP_EOL;
echo 'Loopback request: ' . (request_ip_is_loopback() ? 'yes' : 'no') . PHP_EOL;
echo 'Scope: ' . $scope . PHP_EOL . PHP_EOL;

$counts = [
  'tables' => 0,
  'users' => 0,
  'reports' => 0,
  'tasks' => 0,
];
if ($res = @$mysqli->query('SHOW TABLES')) {
  while ($res->fetch_row()) { $counts['tables']++; }
  $res->free();
}
foreach (['users', 'reports', 'tasks'] as $table) {
  if ($res = @$mysqli->query('SELECT COUNT(*) AS c FROM `' . $table . '`')) {
    $row = $res->fetch_assoc();
    $counts[$table] = (int)($row['c'] ?? 0);
    $res->free();
  }
}
foreach ($counts as $label => $value) {
  echo strtoupper($label) . ': ' . $value . PHP_EOL;
}

if ($scope === 'users') {
  echo PHP_EOL . 'User snapshot (emails masked):' . PHP_EOL;
  $res = @$mysqli->query('SELECT id,name,email,role,active, last_login_at FROM users ORDER BY id LIMIT 50');
  if ($res) {
    while ($row = $res->fetch_assoc()) {
      $row['email'] = masked_email((string)($row['email'] ?? ''));
      echo json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
    }
    $res->free();
  }
}
