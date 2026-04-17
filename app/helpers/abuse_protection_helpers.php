<?php

if (!function_exists('client_ip_address')) {
  function client_ip_address(): string {
    $candidates = [
      $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
      $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
      $_SERVER['REMOTE_ADDR'] ?? null,
    ];
    foreach ($candidates as $raw) {
      if (!$raw) continue;
      $value = trim(explode(',', (string)$raw)[0]);
      if ($value !== '') return substr($value, 0, 64);
    }
    return 'unknown';
  }
}

if (!function_exists('abuse_bootstrap_table')) {
  function abuse_bootstrap_table(): void {
    static $ready = false;
    if ($ready) return;
    $ready = true;
    global $mysqli;
    if (!isset($mysqli) || !($mysqli instanceof mysqli)) return;
    @$mysqli->query("CREATE TABLE IF NOT EXISTS rate_limit_hits (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      scope VARCHAR(80) NOT NULL,
      identifier VARCHAR(191) NOT NULL,
      ip_address VARCHAR(64) NULL,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_rate_limit_scope_identifier (scope, identifier, created_at),
      KEY idx_rate_limit_scope_ip (scope, ip_address, created_at),
      KEY idx_rate_limit_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}

if (!function_exists('abuse_rate_limit_check')) {
  function abuse_rate_limit_check(string $scope, string $identifier, int $maxAttempts, int $windowSeconds, int &$retryAfter = 0): bool {
    abuse_bootstrap_table();
    global $mysqli;
    $retryAfter = 0;
    if ($scope === '' || $identifier === '' || $maxAttempts < 1 || $windowSeconds < 1) return false;
    $sql = "SELECT COUNT(*) AS hits, UNIX_TIMESTAMP(MIN(created_at)) AS oldest_ts
            FROM rate_limit_hits
            WHERE scope=? AND identifier=? AND created_at >= (NOW() - INTERVAL ? SECOND)";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return false;
    $stmt->bind_param('ssi', $scope, $identifier, $windowSeconds);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc() ?: [];
    $stmt->close();
    $hits = (int)($row['hits'] ?? 0);
    if ($hits < $maxAttempts) return false;
    $oldest = (int)($row['oldest_ts'] ?? time());
    $retryAfter = max(1, ($oldest + $windowSeconds) - time());
    return true;
  }
}

if (!function_exists('abuse_rate_limit_hit')) {
  function abuse_rate_limit_hit(string $scope, string $identifier): void {
    abuse_bootstrap_table();
    global $mysqli;
    if ($scope === '' || $identifier === '') return;
    $ip = client_ip_address();
    $stmt = $mysqli->prepare('INSERT INTO rate_limit_hits (scope, identifier, ip_address) VALUES (?,?,?)');
    if (!$stmt) return;
    $stmt->bind_param('sss', $scope, $identifier, $ip);
    $stmt->execute();
    $stmt->close();
  }
}

if (!function_exists('abuse_identifier_for_email')) {
  function abuse_identifier_for_email(string $email): string {
    return strtolower(trim($email));
  }
}

if (!function_exists('abuse_identifier_for_user')) {
  function abuse_identifier_for_user(int $userId): string {
    return 'user:' . max(0, $userId) . '|ip:' . client_ip_address();
  }
}
