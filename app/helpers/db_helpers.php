<?php
if (!function_exists('db_table_columns')) {
  function db_table_columns(string $table): array {
    static $cache = [];
    $key = strtolower($table);
    if (isset($cache[$key])) return $cache[$key];
    global $mysqli;
    $cols = [];
    $stmt = $mysqli->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    if (!$stmt) return $cache[$key] = [];
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($r = $res->fetch_assoc()) $cols[] = (string)$r['COLUMN_NAME'];
    $stmt->close();
    return $cache[$key] = $cols;
  }
}
if (!function_exists('db_column_exists')) {
  function db_column_exists(string $table, string $column): bool {
    $cols = db_table_columns($table);
    if (!$cols) return false;
    return in_array($column, $cols, true);
  }
}
if (!function_exists('db_safe_insert')) {
  function db_safe_insert(string $table, array $data): int {
    global $mysqli;
    $existing = array_flip(db_table_columns($table));
    if (!$existing) return 0;
    $cols = [];
    $vals = [];
    $types = '';
    foreach ($data as $col => $val) {
      if (!isset($existing[$col])) continue;
      $cols[] = $col;
      $vals[] = $val;
      $types .= is_int($val) ? 'i' : 's';
    }
    if (!$cols) return 0;
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES ({$ph})";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
      error_log("db_safe_insert prepare failed for {$table}: " . $mysqli->error);
      return 0;
    }
    $bind = [$types];
    for ($i=0; $i<count($vals); $i++) $bind[] = &$vals[$i];
    @call_user_func_array([$stmt,'bind_param'], $bind);
    if (!$stmt->execute()) {
      error_log("db_safe_insert execute failed for {$table}: " . $stmt->error);
      $stmt->close();
      return 0;
    }
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
  }
}
