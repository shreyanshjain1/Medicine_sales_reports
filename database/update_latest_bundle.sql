-- Bundled incremental updates for existing installs
-- Apply only to existing databases after taking a backup.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;

-- >>> BEGIN upgrade_v13.sql
-- PR 24: report drafts + structured review comments
CREATE TABLE IF NOT EXISTS report_drafts (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  source_report_id INT NULL,
  doctor_name VARCHAR(120) NULL,
  doctor_email VARCHAR(150) NULL,
  purpose VARCHAR(200) NULL,
  medicine_name VARCHAR(200) NULL,
  hospital_name VARCHAR(200) NULL,
  visit_datetime DATETIME NULL,
  summary TEXT NULL,
  remarks TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_report_drafts_user (user_id),
  KEY idx_report_drafts_source (source_report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_review_comments (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  actor_user_id INT NULL,
  comment_type VARCHAR(40) NOT NULL DEFAULT 'general',
  comment_text TEXT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_rrc_report (report_id),
  KEY idx_rrc_actor (actor_user_id),
  KEY idx_rrc_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE report_drafts
  ADD CONSTRAINT fk_report_drafts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;

ALTER TABLE report_drafts
  ADD CONSTRAINT fk_report_drafts_source FOREIGN KEY (source_report_id) REFERENCES reports(id) ON DELETE SET NULL;

ALTER TABLE report_review_comments
  ADD CONSTRAINT fk_rrc_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE;

ALTER TABLE report_review_comments
  ADD CONSTRAINT fk_rrc_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL;
-- <<< END upgrade_v13.sql

-- >>> BEGIN upgrade_v14.sql
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
-- <<< END upgrade_v14.sql

-- >>> BEGIN upgrade_v15.sql
ALTER TABLE doctors_masterlist ADD INDEX idx_doctors_name_active (active, dr_name(100));
ALTER TABLE hospitals_master ADD INDEX idx_hospitals_name_active (active, name(100));
ALTER TABLE medicines_master ADD INDEX idx_medicines_name_active (active, name(100));
-- <<< END upgrade_v15.sql

-- >>> BEGIN upgrade_v16.sql
ALTER TABLE events
  ADD COLUMN parent_event_id INT NULL AFTER user_id,
  ADD COLUMN status ENUM('planned','in_progress','completed','cancelled','overdue') NOT NULL DEFAULT 'planned' AFTER remarks,
  ADD COLUMN recurrence_pattern ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none' AFTER status,
  ADD COLUMN recurrence_until DATE NULL AFTER recurrence_pattern,
  ADD COLUMN recurrence_count INT NOT NULL DEFAULT 0 AFTER recurrence_until;

ALTER TABLE events ADD INDEX idx_events_status (status);
ALTER TABLE events ADD INDEX idx_events_parent (parent_event_id);
-- <<< END upgrade_v16.sql

-- >>> BEGIN upgrade_v17.sql
ALTER TABLE users
  ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL DEFAULT NULL AFTER wants_email_notifications,
  ADD COLUMN IF NOT EXISTS last_login_ip VARCHAR(64) NULL DEFAULT NULL AFTER last_login_at;
-- <<< END upgrade_v17.sql

-- >>> BEGIN upgrade_v18.sql
CREATE TABLE IF NOT EXISTS app_settings (
  id INT AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(120) NOT NULL,
  setting_value TEXT NULL,
  setting_type VARCHAR(40) NOT NULL DEFAULT 'string',
  is_public TINYINT(1) NOT NULL DEFAULT 0,
  updated_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_app_settings_key (setting_key),
  KEY idx_app_settings_public (is_public),
  CONSTRAINT fk_app_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- <<< END upgrade_v18.sql

-- >>> BEGIN upgrade_v19.sql
ALTER TABLE users
  ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER wants_email_notifications,
  ADD COLUMN invited_at DATETIME NULL DEFAULT NULL AFTER force_password_change,
  ADD COLUMN invite_expires_at DATETIME NULL DEFAULT NULL AFTER invited_at,
  ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL AFTER invite_expires_at,
  ADD COLUMN first_login_at DATETIME NULL DEFAULT NULL AFTER password_changed_at;
-- <<< END upgrade_v19.sql

-- >>> BEGIN upgrade_v20.sql
-- PR 35 notification preference enforcement audit
-- No schema change required. This marker migration exists to document the preference-enforcement pass.
-- <<< END upgrade_v20.sql

-- >>> BEGIN upgrade_v21.sql
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
-- <<< END upgrade_v21.sql

SET FOREIGN_KEY_CHECKS=1;
