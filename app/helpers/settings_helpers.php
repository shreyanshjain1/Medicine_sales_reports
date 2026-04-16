<?php

function app_settings_table_exists(): bool {
  global $mysqli;
  static $checked = null;
  if ($checked !== null) return $checked;
  $checked = false;
  if (!isset($mysqli) || !($mysqli instanceof mysqli)) return false;
  if ($res = @$mysqli->query("SHOW TABLES LIKE 'app_settings'")) {
    $checked = (bool)$res->fetch_row();
    $res->free();
  }
  return $checked;
}

function get_app_setting(string $key, $default = '') {
  global $mysqli;
  static $cache = [];
  if (!$key) return $default;
  if (array_key_exists($key, $cache)) return $cache[$key];
  if (!app_settings_table_exists()) return $default;
  $stmt = $mysqli->prepare('SELECT setting_value FROM app_settings WHERE setting_key=? LIMIT 1');
  if (!$stmt) return $default;
  $stmt->bind_param('s', $key);
  $stmt->execute();
  $row = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  $cache[$key] = $row['setting_value'] ?? $default;
  return $cache[$key];
}

function set_app_setting(string $key, $value, string $type = 'string', ?int $actorId = null): bool {
  global $mysqli;
  if (!$key || !app_settings_table_exists()) return false;
  $val = is_array($value) ? json_encode($value) : (string)$value;
  $actorId = $actorId ?: (int)(user()['id'] ?? 0);
  $stmt = $mysqli->prepare("INSERT INTO app_settings (setting_key, setting_value, setting_type, updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_type=VALUES(setting_type), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP");
  if (!$stmt) return false;
  $stmt->bind_param('sssi', $key, $val, $type, $actorId);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

function app_name_value(): string {
  $v = trim((string)get_app_setting('app_name', ''));
  return $v !== '' ? $v : APP_NAME;
}

function company_name_value(): string {
  $v = trim((string)get_app_setting('company_name', ''));
  return $v !== '' ? $v : COMPANY_NAME;
}

function app_welcome_text(): string {
  return trim((string)get_app_setting('dashboard_welcome_text', '')); 
}

function app_security_settings(): array {
  return [
    'session_idle_minutes' => max(5, (int)get_app_setting('session_idle_minutes', '30')),
    'session_absolute_minutes' => max(15, (int)get_app_setting('session_absolute_minutes', '480')),
    'allow_setup_page' => (int)get_app_setting('allow_setup_page', defined('ALLOW_SETUP_PAGE') ? (ALLOW_SETUP_PAGE ? '1' : '0') : '0'),
  ];
}

function app_setting_bool(string $key, bool $default=false): bool {
  return (string)get_app_setting($key, $default ? '1' : '0') === '1';
}

function app_mail_summary(): array {
  return [
    'enabled' => defined('MAIL_ENABLED') ? (MAIL_ENABLED ? 'Enabled' : 'Disabled') : 'Unknown',
    'driver' => defined('MAIL_DRIVER') ? (string)MAIL_DRIVER : 'n/a',
    'from_email' => defined('MAIL_FROM_EMAIL') ? (string)MAIL_FROM_EMAIL : 'n/a',
    'from_name' => defined('MAIL_FROM_NAME') ? (string)MAIL_FROM_NAME : 'n/a',
    'reply_to' => defined('MAIL_REPLY_TO') ? (string)MAIL_REPLY_TO : 'n/a',
  ];
}
