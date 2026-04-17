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
  $actorId = $actorId ?: (int)(function_exists('user') ? (user()['id'] ?? 0) : 0);
  $stmt = $mysqli->prepare("INSERT INTO app_settings (setting_key, setting_value, setting_type, updated_by) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), setting_type=VALUES(setting_type), updated_by=VALUES(updated_by), updated_at=CURRENT_TIMESTAMP");
  if (!$stmt) return false;
  $stmt->bind_param('sssi', $key, $val, $type, $actorId);
  $ok = $stmt->execute();
  $stmt->close();
  return $ok;
}

function app_setting_bool(string $key, bool $default=false): bool {
  return (string)get_app_setting($key, $default ? '1' : '0') === '1';
}

function app_setting_int(string $key, int $default, int $min=0, ?int $max=null): int {
  $v = (int)get_app_setting($key, (string)$default);
  if ($v < $min) $v = $min;
  if ($max !== null && $v > $max) $v = $max;
  return $v;
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

function runtime_session_idle_minutes(): int {
  return app_setting_int('session_idle_minutes', defined('SESSION_IDLE_MINUTES') ? (int)SESSION_IDLE_MINUTES : 30, 5, 1440);
}

function runtime_session_absolute_minutes(): int {
  return app_setting_int('session_absolute_minutes', defined('SESSION_ABSOLUTE_MINUTES') ? (int)SESSION_ABSOLUTE_MINUTES : 480, 15, 10080);
}

function runtime_allow_setup_page(): bool {
  $default = false;
  if (defined('ALLOW_SETUP_PAGE')) $default = (bool)ALLOW_SETUP_PAGE;
  elseif (defined('ALLOW_SETUP')) $default = (bool)ALLOW_SETUP;
  return app_setting_bool('allow_setup_page', $default);
}

function app_security_settings(): array {
  return [
    'session_idle_minutes' => runtime_session_idle_minutes(),
    'session_absolute_minutes' => runtime_session_absolute_minutes(),
    'allow_setup_page' => runtime_allow_setup_page() ? 1 : 0,
  ];
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

function initialize_login_session(array $u): void {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  session_regenerate_id(true);
  $now = time();
  $_SESSION['user'] = [
    'id' => (int)($u['id'] ?? 0),
    'name' => (string)($u['name'] ?? ''),
    'email' => (string)($u['email'] ?? ''),
    'role' => function_exists('role_norm') ? role_norm((string)($u['role'] ?? 'employee')) : (string)($u['role'] ?? 'employee'),
    'wants_email_notifications' => (int)($u['wants_email_notifications'] ?? 1),
  ];
  $_SESSION['auth_started_at'] = $now;
  $_SESSION['last_activity_at'] = $now;
  $_SESSION['session_warning'] = null;
}

function clear_auth_session(): void {
  if (session_status() !== PHP_SESSION_ACTIVE) return;
  $_SESSION = [];
  if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
  }
  session_destroy();
}

function session_warning_data(): array {
  $idleLimit = runtime_session_idle_minutes() * 60;
  $absoluteLimit = runtime_session_absolute_minutes() * 60;
  $startedAt = (int)($_SESSION['auth_started_at'] ?? time());
  $lastActivity = (int)($_SESSION['last_activity_at'] ?? time());
  $now = time();
  $idleRemaining = max(0, $idleLimit - ($now - $lastActivity));
  $absoluteRemaining = max(0, $absoluteLimit - ($now - $startedAt));
  $remaining = min($idleRemaining, $absoluteRemaining);
  return [
    'enabled' => true,
    'idle_minutes' => runtime_session_idle_minutes(),
    'absolute_minutes' => runtime_session_absolute_minutes(),
    'remaining_seconds' => $remaining,
    'warning_seconds' => min(300, max(60, (int)floor($idleLimit / 4))),
  ];
}

function enforce_runtime_session_policy(): ?string {
  if (!isset($_SESSION['user'])) return null;
  $now = time();
  $startedAt = (int)($_SESSION['auth_started_at'] ?? $now);
  $lastActivity = (int)($_SESSION['last_activity_at'] ?? $now);
  $idleLimit = runtime_session_idle_minutes() * 60;
  $absoluteLimit = runtime_session_absolute_minutes() * 60;

  if (($now - $lastActivity) > $idleLimit) {
    clear_auth_session();
    return 'idle';
  }
  if (($now - $startedAt) > $absoluteLimit) {
    clear_auth_session();
    return 'absolute';
  }
  $_SESSION['auth_started_at'] = $startedAt;
  $_SESSION['last_activity_at'] = $now;
  return null;
}

function can_run_setup(): bool {
  return runtime_allow_setup_page();
}
