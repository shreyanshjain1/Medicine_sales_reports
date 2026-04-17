<?php

if (!function_exists('ensure_app_directories')) {
  function ensure_app_directories(): void {
    foreach ([
      __DIR__ . '/../../uploads',
      __DIR__ . '/../../uploads/attachments',
      __DIR__ . '/../../uploads/signatures',
      __DIR__ . '/../../storage',
      __DIR__ . '/../../storage/logs',
    ] as $dir) {
      if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
      }
    }
  }
}

if (!function_exists('app_boot_runtime')) {
  function app_boot_runtime(): void {
    ensure_app_directories();

    if (function_exists('ensure_core_schema')) {
      ensure_core_schema();
    }
    if (function_exists('ensure_performance_schema')) {
      ensure_performance_schema();
    }
    if (function_exists('ensure_settings_schema')) {
      ensure_settings_schema();
    }

    if (isset($_SESSION['user']) && function_exists('enforce_runtime_session_policy')) {
      enforce_runtime_session_policy();
    }
  }
}
