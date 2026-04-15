<?php
if (!function_exists('current_target_month')) {
  function current_target_month(): string { return date('Y-m'); }
}
if (!function_exists('performance_scope_filter')) {
  function performance_scope_filter(string $userColumn='u.id'): string {
    $me = user();
    $meId = (int)($me['id'] ?? 0);
    if ($meId <= 0) return '0';
    if (is_manager()) return '1';
    if (is_district_manager()) return "({$userColumn} = {$meId} OR {$userColumn} IN (SELECT id FROM users WHERE district_manager_id = {$meId}))";
    return "{$userColumn} = {$meId}";
  }
}
if (!function_exists('performance_month_bounds')) {
  function performance_month_bounds(?string $month=null): array {
    $month = preg_match('/^\d{4}-\d{2}$/', (string)$month) ? $month : current_target_month();
    $start = $month . '-01';
    $end = date('Y-m-d', strtotime($start . ' +1 month'));
    return [$month, $start, $end];
  }
}
if (!function_exists('fetch_performance_overview')) {
  function fetch_performance_overview(?string $month=null): array {
    global $mysqli;
    [$month, $start, $end] = performance_month_bounds($month);
    $filter = performance_scope_filter('u.id');
    $sql = "SELECT u.id, u.name, u.role,
      COALESCE(SUM(CASE WHEN r.id IS NOT NULL THEN 1 ELSE 0 END),0) AS report_count,
      COUNT(DISTINCT NULLIF(r.doctor_name,'')) AS doctors_count,
      COUNT(DISTINCT NULLIF(r.hospital_name,'')) AS hospitals_count,
      COUNT(DISTINCT NULLIF(r.medicine_name,'')) AS medicines_count,
      SUM(CASE WHEN r.status='approved' THEN 1 ELSE 0 END) AS approved_count,
      SUM(CASE WHEN r.status='pending' THEN 1 ELSE 0 END) AS pending_count,
      SUM(CASE WHEN r.status='needs_changes' THEN 1 ELSE 0 END) AS needs_changes_count,
      MAX(t.target_reports) AS target_reports,
      MAX(t.target_unique_doctors) AS target_unique_doctors,
      MAX(t.target_unique_hospitals) AS target_unique_hospitals
    FROM users u
    LEFT JOIN reports r ON r.user_id=u.id AND r.visit_datetime >= ? AND r.visit_datetime < ?
    LEFT JOIN performance_targets t ON t.user_id=u.id AND t.target_month=?
    WHERE u.active=1 AND {$filter}
    GROUP BY u.id, u.name, u.role
    ORDER BY report_count DESC, u.name ASC";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return ['month'=>$month,'rows'=>[],'summary'=>[]];
    $stmt->bind_param('sss',$start,$end,$month);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    $summary = [
      'total_reports'=>0,'total_approved'=>0,'total_pending'=>0,'total_needs_changes'=>0,
      'total_doctors'=>0,'total_hospitals'=>0,'total_medicines'=>0,'target_reports'=>0,
      'achievement_pct'=>0,
    ];
    foreach($rows as $r){
      $summary['total_reports'] += (int)$r['report_count'];
      $summary['total_approved'] += (int)$r['approved_count'];
      $summary['total_pending'] += (int)$r['pending_count'];
      $summary['total_needs_changes'] += (int)$r['needs_changes_count'];
      $summary['total_doctors'] += (int)$r['doctors_count'];
      $summary['total_hospitals'] += (int)$r['hospitals_count'];
      $summary['total_medicines'] += (int)$r['medicines_count'];
      $summary['target_reports'] += (int)$r['target_reports'];
    }
    if ($summary['target_reports'] > 0) {
      $summary['achievement_pct'] = (int)round(($summary['total_reports'] / max(1,$summary['target_reports'])) * 100);
    }
    return ['month'=>$month,'rows'=>$rows,'summary'=>$summary];
  }
}
if (!function_exists('upsert_performance_target')) {
  function upsert_performance_target(int $userId, string $month, int $targetReports, int $targetDoctors, int $targetHospitals, string $notes=''): bool {
    global $mysqli;
    $createdBy = (int)(user()['id'] ?? 0);
    $stmt = $mysqli->prepare("INSERT INTO performance_targets (user_id, target_month, target_reports, target_unique_doctors, target_unique_hospitals, notes, created_by) VALUES (?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE target_reports=VALUES(target_reports), target_unique_doctors=VALUES(target_unique_doctors), target_unique_hospitals=VALUES(target_unique_hospitals), notes=VALUES(notes), updated_at=CURRENT_TIMESTAMP");
    if (!$stmt) return false;
    $stmt->bind_param('isiiisi', $userId, $month, $targetReports, $targetDoctors, $targetHospitals, $notes, $createdBy);
    $ok = $stmt->execute();
    $stmt->close();
    return $ok;
  }
}
