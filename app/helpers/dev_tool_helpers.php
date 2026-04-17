<?php

if (!function_exists('app_env_value')) {
  function app_env_value(): string {
    return strtolower(trim((string)(defined('APP_ENV') ? APP_ENV : 'production')));
  }
}

if (!function_exists('request_ip_is_loopback')) {
  function request_ip_is_loopback(): bool {
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    return in_array($ip, ['127.0.0.1', '::1'], true);
  }
}

if (!function_exists('secret_is_configured')) {
  function secret_is_configured(string $value): bool {
    $v = trim($value);
    if ($v === '') return false;
    return stripos($v, 'change-this-') !== 0;
  }
}

if (!function_exists('dev_tool_runtime_allowed')) {
  function dev_tool_runtime_allowed(): bool {
    if (!defined('ALLOW_DEV_TOOLS') || !ALLOW_DEV_TOOLS) return false;
    if (!defined('DEV_TOOL_KEY') || !secret_is_configured((string)DEV_TOOL_KEY)) return false;
    if (app_env_value() !== 'production') return true;
    return request_ip_is_loopback();
  }
}

if (!function_exists('setup_runtime_allowed')) {
  function setup_runtime_allowed(): bool {
    if (!function_exists('can_run_setup') || !can_run_setup()) return false;
    if (!defined('SETUP_KEY') || !secret_is_configured((string)SETUP_KEY)) return false;
    if (app_env_value() !== 'production') return true;
    return request_ip_is_loopback();
  }
}

if (!function_exists('can_use_dev_tools')) {
  function can_use_dev_tools(): bool {
    return dev_tool_runtime_allowed();
  }
}

if (!function_exists('tool_key_valid')) {
  function tool_key_valid(string $provided, string $expected): bool {
    $provided = trim($provided);
    if ($provided === '' || !secret_is_configured($expected)) return false;
    return hash_equals($expected, $provided);
  }
}

if (!function_exists('masked_email')) {
  function masked_email(string $email): string {
    $email = trim($email);
    if ($email === '' || strpos($email, '@') === false) return $email;
    [$local, $domain] = explode('@', $email, 2);
    $local = strlen($local) <= 2 ? substr($local, 0, 1) . '*' : substr($local, 0, 2) . str_repeat('*', max(1, strlen($local) - 2));
    return $local . '@' . $domain;
  }
}

if (!function_exists('dev_tool_reason_lines')) {
  function dev_tool_reason_lines(string $type): array {
    return [
      'type' => $type,
      'environment' => app_env_value(),
      'loopback_only' => request_ip_is_loopback() ? 'yes' : 'no',
      'allow_setup' => defined('ALLOW_SETUP') && ALLOW_SETUP ? 'enabled' : 'disabled',
      'allow_dev_tools' => defined('ALLOW_DEV_TOOLS') && ALLOW_DEV_TOOLS ? 'enabled' : 'disabled',
    ];
  }
}
