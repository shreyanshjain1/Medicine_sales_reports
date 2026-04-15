<?php
if (!function_exists('approval_sla_thresholds')) {
  function approval_sla_thresholds(): array { return ['warning_hours'=>24, 'overdue_hours'=>48]; }
}
if (!function_exists('approval_scope_sql')) {
  function approval_scope_sql(string $alias='r'): string { return reports_scope_where($alias); }
}
if (!function_exists('fetch_approval_sla_summary')) {
  function fetch_approval_sla_summary(): array {
    global $mysqli;
    $scope = approval_scope_sql('r');
    $th = approval_sla_thresholds();
    $sql = "SELECT
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' THEN 1 ELSE 0 END) AS pending_total,
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='needs_changes' THEN 1 ELSE 0 END) AS needs_changes_total,
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN 1 ELSE 0 END) AS approved_total,
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) >= {$th['warning_hours']} THEN 1 ELSE 0 END) AS aging_warning,
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) >= {$th['overdue_hours']} THEN 1 ELSE 0 END) AS overdue_total,
      AVG(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) END) AS avg_hours_to_approve
      FROM reports r WHERE {$scope}";
    $row = $mysqli->query($sql)->fetch_assoc() ?: [];
    return [
      'pending_total'=>(int)($row['pending_total'] ?? 0),
      'needs_changes_total'=>(int)($row['needs_changes_total'] ?? 0),
      'approved_total'=>(int)($row['approved_total'] ?? 0),
      'aging_warning'=>(int)($row['aging_warning'] ?? 0),
      'overdue_total'=>(int)($row['overdue_total'] ?? 0),
      'avg_hours_to_approve'=>round((float)($row['avg_hours_to_approve'] ?? 0),1),
    ];
  }
}
if (!function_exists('fetch_approval_aging_buckets')) {
  function fetch_approval_aging_buckets(): array {
    global $mysqli;
    $scope = approval_scope_sql('r');
    $sql = "SELECT
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) < 24 THEN 1 ELSE 0 END) AS lt24,
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) BETWEEN 24 AND 47 THEN 1 ELSE 0 END) AS h24_48,
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) BETWEEN 48 AND 71 THEN 1 ELSE 0 END) AS h48_72,
      SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) >= 72 THEN 1 ELSE 0 END) AS gte72
      FROM reports r WHERE {$scope}";
    $row = $mysqli->query($sql)->fetch_assoc() ?: [];
    return [
      ['label'=>'< 24h','count'=>(int)($row['lt24'] ?? 0),'tone'=>'ok'],
      ['label'=>'24-48h','count'=>(int)($row['h24_48'] ?? 0),'tone'=>'watch'],
      ['label'=>'48-72h','count'=>(int)($row['h48_72'] ?? 0),'tone'=>'risk'],
      ['label'=>'>= 72h','count'=>(int)($row['gte72'] ?? 0),'tone'=>'danger'],
    ];
  }
}
if (!function_exists('fetch_overdue_reports')) {
  function fetch_overdue_reports(int $limit=10): array {
    global $mysqli;
    $scope = approval_scope_sql('r');
    $limit = max(1, min(50, $limit));
    $sql = "SELECT r.id, r.doctor_name, r.medicine_name, r.hospital_name, r.visit_datetime, IFNULL(u.name,'—') AS employee,
      TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) AS age_hours,
      COALESCE(NULLIF(r.status,''),'pending') AS status
      FROM reports r
      LEFT JOIN users u ON u.id=r.user_id
      WHERE {$scope} AND COALESCE(NULLIF(r.status,''),'pending')='pending'
      AND TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) >= 48
      ORDER BY age_hours DESC, IFNULL(r.visit_datetime, r.created_at) DESC LIMIT {$limit}";
    $rows=[]; if($res=$mysqli->query($sql)){ while($row=$res->fetch_assoc()) $rows[]=$row; }
    return $rows;
  }
}
if (!function_exists('fetch_reviewer_backlog')) {
  function fetch_reviewer_backlog(int $limit=10): array {
    global $mysqli;
    $scope = approval_scope_sql('r');
    $sql = "SELECT IFNULL(u.name,'Unassigned') AS employee, COUNT(*) AS pending_count,
      SUM(CASE WHEN TIMESTAMPDIFF(HOUR, IFNULL(r.created_at, NOW()), NOW()) >= 48 THEN 1 ELSE 0 END) AS overdue_count
      FROM reports r
      LEFT JOIN users u ON u.id=r.user_id
      WHERE {$scope} AND COALESCE(NULLIF(r.status,''),'pending') IN ('pending','needs_changes')
      GROUP BY r.user_id, u.name
      ORDER BY overdue_count DESC, pending_count DESC, employee ASC
      LIMIT {$limit}";
    $rows=[]; if($res=$mysqli->query($sql)){ while($row=$res->fetch_assoc()) $rows[]=$row; }
    return $rows;
  }
}
