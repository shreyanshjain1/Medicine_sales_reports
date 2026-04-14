<?php
require_once __DIR__ . '/../../init.php';
if (!can_use_dev_tools()) { http_response_code(403); exit('Developer diagnostics are disabled.'); }
if (!is_manager()) { http_response_code(403); exit('Manager session required.'); }
$key = trim((string)($_GET['key'] ?? ''));
if ($key === '' || !hash_equals((string)DEV_TOOL_KEY, $key)) { http_response_code(403); exit('Invalid tool key.'); }
header('Content-Type: text/plain; charset=utf-8');
echo "DB OK
";
$res=$mysqli->query('SHOW TABLES'); while($r=$res->fetch_row()){ echo "TABLE: {$r[0]}
"; }
echo "
Users:
";
$res=$mysqli->query('SELECT id,name,email,role,active FROM users ORDER BY id'); while($r=$res->fetch_assoc()){ echo json_encode($r, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)."
"; }
?>
