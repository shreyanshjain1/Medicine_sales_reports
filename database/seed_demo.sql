-- Optional demo seed for Medicine Sales CRM
-- Replace password hashes before using in any real environment.

INSERT INTO users (name, email, password_hash, role, active, wants_email_notifications)
VALUES
  ('Manager Demo', 'manager@example.com', '$2y$10$replace_me_with_real_hash', 'manager', 1, 1),
  ('District Manager Demo', 'dm@example.com', '$2y$10$replace_me_with_real_hash', 'district_manager', 1, 1),
  ('Employee Demo', 'employee@example.com', '$2y$10$replace_me_with_real_hash', 'employee', 1, 1)
ON DUPLICATE KEY UPDATE name = VALUES(name);
