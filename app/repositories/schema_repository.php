<?php
if (!function_exists('_col_exists')) {
  function _col_exists(string $table, string $column): bool {
    global $mysqli;
    $stmt = $mysqli->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
    if (!$stmt) return false;
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $ok;
  }
}
if (!function_exists('_try_sql')) {
  function _try_sql(string $sql): void {
    global $mysqli;
    try { @$mysqli->query($sql); } catch (Throwable $e) { }
  }
}
if (!function_exists('ensure_core_schema')) {
  function ensure_core_schema(): void {
    if (!empty($_SESSION['_schema_migrated_v6'])) return;
    $_SESSION['_schema_migrated_v6'] = 1;
    if (_col_exists('reports','id')) {
      if (!_col_exists('reports','doctor_name'))   _try_sql("ALTER TABLE reports ADD COLUMN doctor_name VARCHAR(120) NOT NULL DEFAULT '' AFTER user_id");
      if (!_col_exists('reports','doctor_email'))  _try_sql("ALTER TABLE reports ADD COLUMN doctor_email VARCHAR(150) NULL AFTER doctor_name");
      if (!_col_exists('reports','purpose'))       _try_sql("ALTER TABLE reports ADD COLUMN purpose VARCHAR(200) NULL AFTER doctor_email");
      if (!_col_exists('reports','medicine_name')) _try_sql("ALTER TABLE reports ADD COLUMN medicine_name VARCHAR(200) NULL AFTER purpose");
      if (!_col_exists('reports','hospital_name')) _try_sql("ALTER TABLE reports ADD COLUMN hospital_name VARCHAR(200) NULL AFTER medicine_name");
      if (!_col_exists('reports','visit_datetime'))_try_sql("ALTER TABLE reports ADD COLUMN visit_datetime DATETIME NOT NULL DEFAULT '1970-01-01 00:00:00' AFTER hospital_name");
      if (!_col_exists('reports','summary'))       _try_sql("ALTER TABLE reports ADD COLUMN summary TEXT NULL AFTER visit_datetime");
      if (!_col_exists('reports','remarks'))       _try_sql("ALTER TABLE reports ADD COLUMN remarks TEXT NULL AFTER summary");
      if (!_col_exists('reports','signature_path'))_try_sql("ALTER TABLE reports ADD COLUMN signature_path VARCHAR(255) NULL AFTER remarks");
      if (!_col_exists('reports','status'))        _try_sql("ALTER TABLE reports ADD COLUMN status ENUM('pending','approved','needs_changes') NOT NULL DEFAULT 'pending' AFTER signature_path");
      if (!_col_exists('reports','manager_comment')) _try_sql("ALTER TABLE reports ADD COLUMN manager_comment TEXT NULL AFTER status");
      if (!_col_exists('reports','attachment_path')) _try_sql("ALTER TABLE reports ADD COLUMN attachment_path VARCHAR(255) NULL AFTER manager_comment");
      if (!_col_exists('reports','created_at'))    _try_sql("ALTER TABLE reports ADD COLUMN created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER attachment_path");
      _try_sql("ALTER TABLE reports ADD INDEX idx_reports_user_id (user_id)");
      _try_sql("ALTER TABLE reports ADD INDEX idx_reports_visit_dt (visit_datetime)");
      _try_sql("ALTER TABLE reports ADD INDEX idx_reports_status (status)");
    }
    if (_col_exists('events','id')) {
      if (!_col_exists('events','visit_datetime')) _try_sql("ALTER TABLE events ADD COLUMN visit_datetime DATETIME NULL DEFAULT NULL AFTER hospital_name");
    }
  }
}
if (!function_exists('ensure_performance_schema')) {
  function ensure_performance_schema(): void {
    if (!empty($_SESSION['_performance_schema_v7'])) return;
    $_SESSION['_performance_schema_v7'] = 1;
    _try_sql("CREATE TABLE IF NOT EXISTS performance_targets (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}
