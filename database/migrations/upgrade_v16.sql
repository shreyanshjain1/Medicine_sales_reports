ALTER TABLE events
  ADD COLUMN parent_event_id INT NULL AFTER user_id,
  ADD COLUMN status ENUM('planned','in_progress','completed','cancelled','overdue') NOT NULL DEFAULT 'planned' AFTER remarks,
  ADD COLUMN recurrence_pattern ENUM('none','daily','weekly','monthly') NOT NULL DEFAULT 'none' AFTER status,
  ADD COLUMN recurrence_until DATE NULL AFTER recurrence_pattern,
  ADD COLUMN recurrence_count INT NOT NULL DEFAULT 0 AFTER recurrence_until;

ALTER TABLE events ADD INDEX idx_events_status (status);
ALTER TABLE events ADD INDEX idx_events_parent (parent_event_id);
