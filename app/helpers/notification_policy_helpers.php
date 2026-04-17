<?php
if (!function_exists("notification_pref_column_for_type")) {
  function notification_pref_column_for_type(string $eventType): ?string {
    $map = [
      'report_review' => 'notify_review_updates',
      'report_submitted' => 'notify_review_updates',
      'task_assigned' => 'notify_task_assignments',
      'digest_email' => 'notify_digest_emails',
      'security_alert' => 'notify_security_alerts',
      'password_reset' => 'notify_security_alerts',
    ];
    return $map[$eventType] ?? null;
  }
}
if (!function_exists("notification_event_requires_email")) {
  function notification_event_requires_email(string $eventType): bool {
    return in_array($eventType, ['security_alert','password_reset'], true);
  }
}
if (!function_exists("notification_user_pref_enabled")) {
  function notification_user_pref_enabled(int $userId, string $eventType, bool $default=true): bool {
    global $mysqli;
    if ($userId <= 0) return $default;
    $col = notification_pref_column_for_type($eventType);
    if ($col === null) return $default;
    $sql = "SELECT wants_email_notifications, {$col} AS pref_col FROM users WHERE id=? LIMIT 1";
    $stmt = $mysqli->prepare($sql);
    if (!$stmt) return $default;
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$row) return $default;
    $global = (int)($row['wants_email_notifications'] ?? 1) === 1;
    $specific = (int)($row['pref_col'] ?? ($default ? 1 : 0)) === 1;
    if (notification_event_requires_email($eventType)) return $specific;
    return $global && $specific;
  }
}
if (!function_exists("notify_user_prefaware")) {
  function notify_user_prefaware(int $userId, string $title, string $body, string $eventType, string $entityType='', int $entityId=0, string $actionUrl='', int $actorUserId=0): bool {
    if (!function_exists('notify_user')) return false;
    return notify_user($userId, $title, $body, $eventType, $entityType, $entityId, $actionUrl, $actorUserId);
  }
}
if (!function_exists("notify_many_prefaware")) {
  function notify_many_prefaware(array $userIds, string $title, string $body, string $eventType, string $entityType='', int $entityId=0, string $actionUrl='', int $actorUserId=0): int {
    $count = 0;
    foreach (array_unique(array_map('intval', $userIds)) as $uid) {
      if ($uid > 0 && notify_user_prefaware($uid, $title, $body, $eventType, $entityType, $entityId, $actionUrl, $actorUserId)) $count++;
    }
    return $count;
  }
}
