-- PR 25: notification preferences + digest presets

ALTER TABLE users ADD COLUMN notify_review_updates TINYINT(1) NOT NULL DEFAULT 1 AFTER wants_email_notifications;
ALTER TABLE users ADD COLUMN notify_task_assignments TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_review_updates;
ALTER TABLE users ADD COLUMN notify_security_alerts TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_task_assignments;
ALTER TABLE users ADD COLUMN notify_digest_emails TINYINT(1) NOT NULL DEFAULT 0 AFTER notify_security_alerts;

CREATE TABLE IF NOT EXISTS manager_digest_presets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL,
  user_id INT NOT NULL,
  preset_payload TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_digest_preset_user (user_id),
  CONSTRAINT fk_digest_preset_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
