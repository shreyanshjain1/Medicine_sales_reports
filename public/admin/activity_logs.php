<?php
require_once __DIR__.'/../../init.php';
require_login();
require_manager();
$q = trim((string)getv('q', ''));
$action = trim((string)getv('action', 'all'));
$entityType = trim((string)getv('entity_type', 'all'));
$dateFrom = trim((string)getv('date_from', ''));
$dateTo = trim((string)getv('date_to', ''));
$page = max(1, (int)getv('page', 1));
$where = 'WHERE 1';
if ($q !== '') {
  $like = '%' . $mysqli->real_escape_string($q) . '%';
  $where .= " AND (u.name LIKE '{$like}' OR u.email LIKE '{$like}' OR a.action LIKE '{$like}' OR a.details LIKE '{$like}')";
}
if ($action !== 'all') {
  $where .= " AND a.action='" . $mysqli->real_escape_string($action) . "'";
}
if ($entityType !== 'all') {
  $where .= " AND a.entity_type='" . $mysqli->real_escape_string($entityType) . "'";
}
if ($dateFrom !== '') $where .= " AND DATE(a.created_at) >= '" . $mysqli->real_escape_string($dateFrom) . "'";
if ($dateTo !== '') $where .= " AND DATE(a.created_at) <= '" . $mysqli->real_escape_string($dateTo) . "'";
$total = (int)($mysqli->query("SELECT COUNT(*) c FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id {$where}")->fetch_assoc()['c'] ?? 0);
[$page,$pages,$off,$per] = paginate($total, 20, $page);
$rows = [];
$res = $mysqli->query("SELECT a.*, u.name AS actor_name, u.email AS actor_email FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id {$where} ORDER BY a.created_at DESC, a.id DESC LIMIT {$off},{$per}");
if ($res) while ($row = $res->fetch_assoc()) $rows[] = $row;
$actionOptions = [];
if ($res = $mysqli->query("SELECT DISTINCT action FROM audit_logs WHERE action IS NOT NULL AND action<>'' ORDER BY action ASC")) { while ($r = $res->fetch_assoc()) $actionOptions[] = $r['action']; }
$entityOptions = [];
if ($res = $mysqli->query("SELECT DISTINCT entity_type FROM audit_logs WHERE entity_type IS NOT NULL AND entity_type<>'' ORDER BY entity_type ASC")) { while ($r = $res->fetch_assoc()) $entityOptions[] = $r['entity_type']; }
$summary = $mysqli->query("SELECT COUNT(*) total_logs, COUNT(DISTINCT user_id) active_users, SUM(CASE WHEN DATE(created_at)=CURDATE() THEN 1 ELSE 0 END) today_logs FROM audit_logs")->fetch_assoc() ?: [];
$title = 'Activity Logs'; include __DIR__.'/../header.php';
?>
<div class="crm-hero">
  <div>
    <h2>Activity Logs</h2>
    <div class="subtle">Track important user actions, review activity, and report changes across the platform.</div>
  </div>
</div>
<div class="kpi-strip">
  <div class="metric"><div class="label">Total logs</div><div class="value"><?= (int)($summary['total_logs'] ?? 0) ?></div><div class="hint">All recorded actions</div></div>
  <div class="metric"><div class="label">Active users</div><div class="value"><?= (int)($summary['active_users'] ?? 0) ?></div><div class="hint">Users with recorded activity</div></div>
  <div class="metric"><div class="label">Today</div><div class="value"><?= (int)($summary['today_logs'] ?? 0) ?></div><div class="hint">Actions logged today</div></div>
  <div class="metric"><div class="label">Filtered</div><div class="value"><?= (int)$total ?></div><div class="hint">Current search result</div></div>
</div>
<div class="card">
  <form method="get" class="filters">
    <label>Keyword<input type="text" name="q" value="<?= e($q) ?>" placeholder="Search actor, action, or details"></label>
    <label>Action
      <select name="action">
        <option value="all">All actions</option>
        <?php foreach ($actionOptions as $opt): ?>
          <option value="<?= e((string)$opt) ?>" <?= $action === $opt ? 'selected' : '' ?>><?= e(audit_action_label((string)$opt)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Entity
      <select name="entity_type">
        <option value="all">All entities</option>
        <?php foreach ($entityOptions as $opt): ?>
          <option value="<?= e((string)$opt) ?>" <?= $entityType === $opt ? 'selected' : '' ?>><?= e(ucfirst((string)$opt)) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Date from<input type="date" name="date_from" value="<?= e($dateFrom) ?>"></label>
    <label>Date to<input type="date" name="date_to" value="<?= e($dateTo) ?>"></label>
    <div class="filter-actions">
      <button class="btn primary" type="submit">Apply Filters</button>
      <a class="btn" href="<?= url('admin/activity_logs.php') ?>">Reset</a>
    </div>
  </form>
</div>
<div class="card table-card">
  <div class="table-responsive">
    <table class="table data-table">
      <thead>
        <tr>
          <th>Date / Time</th>
          <th>User</th>
          <th>Action</th>
          <th>Entity</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="5" class="muted">No activity matched your filters.</td></tr>
      <?php else: foreach ($rows as $row): ?>
        <tr>
          <td><?= e((string)$row['created_at']) ?></td>
          <td>
            <div><strong><?= e($row['actor_name'] ?: 'System') ?></strong></div>
            <div class="muted"><?= e((string)($row['actor_email'] ?? '')) ?></div>
          </td>
          <td><span class="pill neutral"><?= e(audit_action_label((string)($row['action'] ?? 'activity'))) ?></span></td>
          <td>
            <div><?= e((string)($row['entity_type'] ?: '—')) ?></div>
            <?php if (!empty($row['entity_id'])): ?><div class="muted">#<?= (int)$row['entity_id'] ?></div><?php endif; ?>
          </td>
          <td>
            <div><?= e((string)($row['details'] ?: '—')) ?></div>
            <?php if (($row['entity_type'] ?? '') === 'report' && !empty($row['entity_id'])): ?>
              <div style="margin-top:8px"><a class="btn tiny" href="report_view.php?id=<?= (int)$row['entity_id'] ?>">Open report</a></div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
  <?php if ($pages > 1): ?>
    <div class="pager">
      <?php for($i=1;$i<=$pages;$i++): ?>
        <a class="<?= $i===$page?'active':'' ?>" href="?<?= e(http_build_query(array_merge($_GET,['page'=>$i]))) ?>"><?= (int)$i ?></a>
      <?php endfor; ?>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__.'/../footer.php'; ?>
