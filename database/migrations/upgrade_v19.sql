ALTER TABLE users
  ADD COLUMN force_password_change TINYINT(1) NOT NULL DEFAULT 0 AFTER wants_email_notifications,
  ADD COLUMN invited_at DATETIME NULL DEFAULT NULL AFTER force_password_change,
  ADD COLUMN invite_expires_at DATETIME NULL DEFAULT NULL AFTER invited_at,
  ADD COLUMN password_changed_at DATETIME NULL DEFAULT NULL AFTER invite_expires_at,
  ADD COLUMN first_login_at DATETIME NULL DEFAULT NULL AFTER password_changed_at;
