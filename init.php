<?php
require_once __DIR__ . '/config.php';
session_start();

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) die('DB Connection failed: ' . $mysqli->connect_error);
$mysqli->set_charset('utf8mb4');

if (!defined('APP_SCHEMA_VERSION')) define('APP_SCHEMA_VERSION', 'v16');

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

require_once __DIR__ . '/app/helpers/app_helpers.php';
require_once __DIR__ . '/app/helpers/db_helpers.php';
require_once __DIR__ . '/app/repositories/schema_repository.php';
require_once __DIR__ . '/app/services/performance_service.php';
require_once __DIR__ . '/app/services/approval_service.php';

ensure_core_schema();
ensure_performance_schema();
