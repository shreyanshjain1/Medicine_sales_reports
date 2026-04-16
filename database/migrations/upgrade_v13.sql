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
