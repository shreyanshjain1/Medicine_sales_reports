CREATE TABLE IF NOT EXISTS users (
  id INT AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(150) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  role ENUM('manager','district_manager','employee') NOT NULL DEFAULT 'employee',
  district_manager_id INT NULL,
  active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_users_active_role (active, role),
  INDEX idx_users_district_manager (district_manager_id),
  CONSTRAINT fk_users_district_manager FOREIGN KEY (district_manager_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS doctors_masterlist (
  id INT AUTO_INCREMENT PRIMARY KEY,
  dr_name VARCHAR(150) NOT NULL,
  email VARCHAR(150) NULL,
  speciality VARCHAR(150) NULL,
  hospital_address VARCHAR(255) NULL,
  place VARCHAR(120) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_doctors_place (place),
  INDEX idx_doctors_name (dr_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  doctor_name VARCHAR(120) NOT NULL,
  doctor_email VARCHAR(150) NULL,
  purpose VARCHAR(200) NULL,
  medicine_name VARCHAR(200) NULL,
  hospital_name VARCHAR(200) NULL,
  visit_datetime DATETIME NOT NULL,
  summary TEXT NULL,
  remarks TEXT NULL,
  signature_path VARCHAR(255) NULL,
  status ENUM('pending','approved','needs_changes') NOT NULL DEFAULT 'pending',
  manager_comment TEXT NULL,
  attachment_path VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_reports_user_id (user_id),
  INDEX idx_reports_visit_dt (visit_datetime),
  INDEX idx_reports_status (status),
  INDEX idx_reports_doctor (doctor_name),
  INDEX idx_reports_medicine (medicine_name),
  INDEX idx_reports_hospital (hospital_name),
  CONSTRAINT fk_reports_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  city VARCHAR(120) DEFAULT NULL,
  doctor_id INT DEFAULT NULL,
  purpose VARCHAR(200) DEFAULT NULL,
  medicine_name VARCHAR(200) DEFAULT NULL,
  hospital_name VARCHAR(200) DEFAULT NULL,
  visit_datetime DATETIME DEFAULT NULL,
  summary TEXT DEFAULT NULL,
  remarks TEXT DEFAULT NULL,
  start DATETIME NOT NULL,
  end DATETIME DEFAULT NULL,
  all_day TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_events_user_id (user_id),
  INDEX idx_events_start (start),
  INDEX idx_events_doctor_id (doctor_id),
  INDEX idx_events_visit_dt (visit_datetime),
  CONSTRAINT fk_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_events_doctor FOREIGN KEY (doctor_id) REFERENCES doctors_masterlist(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS event_attendees (
  event_id INT NOT NULL,
  user_id INT NOT NULL,
  added_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (event_id, user_id),
  INDEX idx_event_attendees_user (user_id),
  CONSTRAINT fk_event_attendees_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE,
  CONSTRAINT fk_event_attendees_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS report_status_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  report_id INT NOT NULL,
  actor_user_id INT NULL,
  old_status VARCHAR(40) NULL,
  new_status VARCHAR(40) NOT NULL,
  comment TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_rsh_report (report_id),
  INDEX idx_rsh_actor (actor_user_id),
  CONSTRAINT fk_rsh_report FOREIGN KEY (report_id) REFERENCES reports(id) ON DELETE CASCADE,
  CONSTRAINT fk_rsh_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  action VARCHAR(120) NOT NULL,
  entity_type VARCHAR(80) NULL,
  entity_id INT NULL,
  details TEXT NULL,
  ip_address VARCHAR(64) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_audit_user (user_id),
  INDEX idx_audit_entity (entity_type, entity_id),
  INDEX idx_audit_created (created_at),
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  ip_address VARCHAR(64) NOT NULL,
  was_successful TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_login_attempts_email_ip (email, ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
