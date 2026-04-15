CREATE TABLE IF NOT EXISTS performance_targets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  target_month CHAR(7) NOT NULL,
  target_reports INT NOT NULL DEFAULT 0,
  target_unique_doctors INT NOT NULL DEFAULT 0,
  target_unique_hospitals INT NOT NULL DEFAULT 0,
  notes VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_target_user_month (user_id, target_month),
  KEY idx_target_month (target_month),
  CONSTRAINT fk_perf_target_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
