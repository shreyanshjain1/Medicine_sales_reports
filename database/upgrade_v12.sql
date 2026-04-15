-- Fix 13: Approval SLA / aging analytics support

ALTER TABLE reports ADD INDEX idx_reports_status_created_at (status, created_at);
ALTER TABLE reports ADD INDEX idx_reports_status_visit_datetime (status, visit_datetime);
