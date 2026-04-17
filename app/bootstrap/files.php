<?php

if (!function_exists('app_require_if_exists')) {
  function app_require_if_exists(string $relativePath): void {
    $full = __DIR__ . '/../../' . ltrim($relativePath, '/');
    if (is_file($full)) {
      require_once $full;
    }
  }
}

if (!function_exists('app_bootstrap_files')) {
  function app_bootstrap_files(): void {
    foreach ([
      'app/helpers/path_helpers.php',
      'app/helpers/app_helpers.php',
      'app/helpers/db_helpers.php',
      'app/helpers/api_helpers.php',
      'app/helpers/api_validation_helpers.php',
      'app/helpers/notification_policy_helpers.php',
      'app/helpers/settings_helpers.php',
      'app/helpers/dev_tool_helpers.php',
      'app/helpers/abuse_protection_helpers.php',
      'app/components/ui_components.php',
      'app/components/form_components.php',
      'app/components/overlay_components.php',
      'app/repositories/schema_repository.php',
      'app/services/performance_service.php',
      'app/services/approval_service.php',
    ] as $file) {
      app_require_if_exists($file);
    }
  }
}
