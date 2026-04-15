<?php
require_once __DIR__.'/../init.php';
require_login();

$filter = trim((string)($_GET['filter'] ?? 'all'));
$q = trim((string)($_GET['q'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  if (isset($_POST['mark_all_read'])) {
    mark_all_notifications_read();
    header('Location: '.url('notifications.php?filter='.$filter.($q!==''?'&q='.urlencode($q):'')));
    exit;
  }
  if (isset($_POST['mark_read_id'])) {
    mark_notification_read((int)$_POST['mark_read_id']);
    header('Location: '.url('notifications.php?filter='.$filter.($q!==''?'&q='.urlencode($q):'')));
    exit;
  }
}

$where = ["user_id=?"];
$types = 'i';
$params = [(int)user()['id']];

if ($filter === 'unread') {
  $where[] = "is_read=0";
} elseif ($filter === 'read') {
  $where[] = "is_read=1";
}

if ($q !== '') {
  $where[] = "(title LIKE CONCAT('%',?,'%') OR body LIKE CONCAT('%',?,'%'))";
  $types .= 'ss';
  $params[] = $q;
  $params[] = $q;
}

$sql = "SELECT id,title,body,type,entity_type,entity_id,action_url,is_read,created_at FROM notifications WHERE ".implode(' AND ', $where)." ORDER BY is_read ASC, created_at DESC LIMIT 200";
$stmt = $mysqli->prepare($sql);
if ($stmt) {
  $bind = [$types];
  foreach ($params as $k => $v) $bind[] = &$params[$k];
  call_user_func_array([$stmt, 'bind_param'], $bind);
  $stmt->execute();
  $res = $stmt->get_result();
  $rows = [];
  while ($row = $res->fetch_assoc()) $rows[] = $row;
  $stmt->close();
} else {
  $rows = [];
}

$title = 'Notifications';
include __DIR__.'/header.php';
?>
<div class="card">
  <div class="flex between center wrap-gap">
    <div>
      <h2 class="titlecase" style="margin:0">Notifications</h2>
      <p class="muted" style="margin:.35rem 0 0">Approvals, returned reports, and task updates in one place.</p>
    </div>
    <form method="post">
      <?php csrf_input(); ?>
      <button class="btn" type="submit" name="mark_all_read" value="1">Mark all as read</button>
    </form>
  </div>

  <form method="get" class="filters-bar" style="margin-top:1rem">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search notifications">
    <select name="filter">
      <option value="all" <?= $filter==='all'?'selected':'' ?>>All</option>
      <option value="unread" <?= $filter==='unread'?'selected':'' ?>>Unread</option>
      <option value="read" <?= $filter==='read'?'selected':'' ?>>Read</option>
    </select>
    <button class="btn primary" type="submit">Apply</button>
  </form>

  <div class="notification-list" style="margin-top:1rem">
    <?php if (!$rows): ?>
      <div class="empty-state">
        <h3 class="titlecase">No notifications found</h3>
        <p class="muted">You are all caught up for now.</p>
      </div>
    <?php else: ?>
      <?php foreach ($rows as $n): ?>
        <div class="notif-item <?= (int)$n['is_read']===0 ? 'unread' : '' ?>">
          <div class="notif-main">
            <div class="notif-meta">
              <span class="pill"><?= e($n['type']) ?></span>
              <span class="muted"><?= e(date('M d, Y h:i A', strtotime((string)$n['created_at']))) ?></span>
            </div>
            <h3><?= e($n['title']) ?></h3>
            <?php if (!empty($n['body'])): ?>
              <p><?= nl2br(e($n['body'])) ?></p>
            <?php endif; ?>
            <div class="notif-actions">
              <?php if (!empty($n['action_url'])): ?>
                <a class="btn tiny primary" href="<?= e($n['action_url']) ?>">Open</a>
              <?php endif; ?>
              <?php if ((int)$n['is_read']===0): ?>
                <form method="post" style="display:inline">
                  <?php csrf_input(); ?>
                  <input type="hidden" name="mark_read_id" value="<?= (int)$n['id'] ?>">
                  <button class="btn tiny" type="submit">Mark read</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
          <div class="notif-dot <?= (int)$n['is_read']===0 ? 'live' : '' ?>"></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__.'/footer.php'; ?>
