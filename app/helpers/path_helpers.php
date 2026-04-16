<?php

if (!function_exists('base_url_trimmed')) {
  function base_url_trimmed(): string {
    return rtrim((string)BASE_URL_EFFECTIVE, '/');
  }
}

if (!function_exists('url')) {
  function url(string $path = ''): string {
    $base = base_url_trimmed();
    $path = ltrim($path, '/');
    return $path === '' ? $base : $base . '/' . $path;
  }
}

if (!function_exists('route_url')) {
  function route_url(string $path = '', array $query = []): string {
    $uri = url($path);
    if ($query) {
      $qs = http_build_query($query);
      if ($qs !== '') $uri .= (str_contains($uri, '?') ? '&' : '?') . $qs;
    }
    return $uri;
  }
}

if (!function_exists('asset_url')) {
  function asset_url(string $path = ''): string {
    return url('assets/' . ltrim($path, '/'));
  }
}

if (!function_exists('api_url')) {
  function api_url(string $path = '', array $query = []): string {
    return route_url('api/' . ltrim($path, '/'), $query);
  }
}

if (!function_exists('attach_url_for')) {
  function attach_url_for(?string $storedPath): string {
    $file = basename((string)$storedPath);
    return $file === '' ? '' : rtrim((string)ATTACH_URL, '/') . '/' . $file;
  }
}

if (!function_exists('signature_url_for')) {
  function signature_url_for(?string $storedPath): string {
    $file = basename((string)$storedPath);
    return $file === '' ? '' : rtrim((string)SIGNATURE_URL, '/') . '/' . $file;
  }
}

if (!function_exists('redirect_to')) {
  function redirect_to(string $path = '', array $query = []): void {
    header('Location: ' . route_url($path, $query));
    exit;
  }
}

if (!function_exists('current_script_name')) {
  function current_script_name(): string {
    return basename((string)($_SERVER['PHP_SELF'] ?? ''));
  }
}
