<?php
require_once __DIR__ . '/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($mysqli->connect_error) {
  die('DB Connection failed: ' . $mysqli->connect_error);
}
$mysqli->set_charset('utf8mb4');

if (!defined('APP_SCHEMA_VERSION')) {
  define('APP_SCHEMA_VERSION', 'v30');
}

require_once __DIR__ . '/app/bootstrap/files.php';
require_once __DIR__ . '/app/bootstrap/runtime.php';

app_bootstrap_files();
app_boot_runtime();

