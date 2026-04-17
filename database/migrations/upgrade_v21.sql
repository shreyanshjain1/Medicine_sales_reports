-- PR 37: abuse protection for sensitive auth and sync endpoints
CREATE TABLE IF NOT EXISTS rate_limit_hits (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope VARCHAR(80) NOT NULL,
  identifier VARCHAR(191) NOT NULL,
  ip_address VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rate_limit_scope_identifier (scope, identifier, created_at),
  KEY idx_rate_limit_scope_ip (scope, ip_address, created_at),
  KEY idx_rate_limit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
