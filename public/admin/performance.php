<?php
$title = 'Performance';
include __DIR__ . '/../header.php';

$month = getv('month', current_target_month());
if (!preg_match('/^\d{4}-\d{2}$/', (string)$month)) $month = current_target_month();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  if (!is_manager()) {
    http_response_code(403);
    exit('Forbidden');
  }
  $targetUserId = (int)post('user_id', 0);
  $targetReports = max(0, (int)post('target_reports', 0));
  $targetDoctors = max(0, (int)post('target_unique_doctors', 0));
  $targetHospitals = max(0, (int)post('target_unique_hospitals', 0));
  $notes = trim((string)post('notes', ''));
  if ($targetUserId > 0) {
    upsert_performance_target($targetUserId, $month, $targetReports, $targetDoctors, $targetHospitals, $notes);
    header('Location: ' . url('admin/performance.php?month=' . urlencode($month) . '&saved=1'));
    exit;
  }
}

$data = fetch_performance_overview($month);
$rows = $data['rows'];
$summary = $data['summary'];

$targetUsers = [];
if (is_manager()) {
  $res = $mysqli->query("SELECT id,name,role FROM users WHERE active=1 AND role IN ('employee','district_manager') ORDER BY role ASC, name ASC");
  while($res && ($u=$res->fetch_assoc())) $targetUsers[] = $u;
}
?>

<?php if (getv('saved')): ?>
  <div class="alert success">Performance target saved.</div>
<?php endif; ?>

<?php ui_page_hero('Performance & Territory KPIs', 'Track monthly rep output, doctor coverage, hospital reach, and approval health in one place.', '<form method="get" class="form inline-form"><label>Month<input type="month" name="month" value="'.e($month).'"></label><button class="btn primary" type="submit">Apply</button></form>'); ?>

<div class="summary-grid">
  <?php ui_stat_card('Total Reports', (int)$summary['total_reports'], 'Monthly volume'); ?>
  <?php ui_stat_card('Approved', (int)$summary['total_approved'], 'Accepted reports'); ?>
  <?php ui_stat_card('Pending', (int)$summary['total_pending'], 'Awaiting review', 'warning'); ?>
  <?php ui_stat_card('Coverage Doctors', (int)$summary['total_doctors'], 'Unique doctors'); ?>
  <?php ui_stat_card('Coverage Hospitals', (int)$summary['total_hospitals'], 'Unique hospitals'); ?>
  <?php ui_stat_card('Target Achievement', (int)$summary['achievement_pct'].'%', 'Against assigned target', 'success'); ?>
</div>

<div class="grid two performance-layout">
  <div class="card">
    <div class="flex-between">
      <h2 class="titlecase">Rep & Territory Scoreboard</h2>
      <span class="pill-soft"><?= e($month) ?></span>
    </div>
    <div class="table-wrap">
      <table class="table performance-table">
        <thead>
          <tr>
            <th>Rep</th>
            <th>Role</th>
            <th>Reports</th>
            <th>Approved</th>
            <th>Pending</th>
            <th>Doctors</th>
            <th>Hospitals</th>
            <th>Medicines</th>
            <th>Target</th>
            <th>Attainment</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="10" class="muted">No performance data yet for this month.</td></tr>
        <?php else: foreach($rows as $row):
          $target = (int)($row['target_reports'] ?? 0);
          $attainment = $target > 0 ? (int)round(((int)$row['report_count'] / max(1,$target))*100) : 0;
        ?>
          <tr>
            <td><strong><?= e($row['name']) ?></strong></td>
            <td><?= e(str_replace('_',' ',(string)$row['role'])) ?></td>
            <td><?= (int)$row['report_count'] ?></td>
            <td><?= (int)$row['approved_count'] ?></td>
            <td><?= (int)$row['pending_count'] ?></td>
            <td><?= (int)$row['doctors_count'] ?></td>
            <td><?= (int)$row['hospitals_count'] ?></td>
            <td><?= (int)$row['medicines_count'] ?></td>
            <td><?= $target ?></td>
            <td>
              <div class="progress-cell">
                <div class="progress-bar"><span style="width:<?= max(0,min(100,$attainment)) ?>%"></span></div>
                <small><?= $attainment ?>%</small>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="vstack gap-s">
    <div class="card">
      <h2 class="titlecase">KPI Snapshot</h2>
      <div class="mini-kpi-list">
        <div class="mini-kpi"><span>Needs Changes</span><strong><?= (int)$summary['total_needs_changes'] ?></strong></div>
        <div class="mini-kpi"><span>Medicine Reach</span><strong><?= (int)$summary['total_medicines'] ?></strong></div>
        <div class="mini-kpi"><span>Targets Set</span><strong><?= (int)$summary['target_reports'] ?></strong></div>
      </div>
      <p class="muted" style="margin-top:.8rem">Use this page as the monthly manager view for follow-up, coaching, and territory review.</p>
    </div>

    <?php if (is_manager()): ?>
    <div class="card">
      <h2 class="titlecase">Set Monthly Targets</h2>
      <form method="post" class="form">
        <?php csrf_input(); ?>
        <label>Rep / Manager
          <select name="user_id" required>
            <option value="">Select user</option>
            <?php foreach($targetUsers as $u): ?>
              <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['role']) ?>)</option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>Target Reports
          <input type="number" min="0" name="target_reports" value="20">
        </label>
        <label>Target Unique Doctors
          <input type="number" min="0" name="target_unique_doctors" value="10">
        </label>
        <label>Target Unique Hospitals
          <input type="number" min="0" name="target_unique_hospitals" value="5">
        </label>
        <label>Notes
          <textarea name="notes" rows="3" placeholder="Optional coaching or focus notes for the month"></textarea>
        </label>
        <button class="btn primary block" type="submit">Save Monthly Target</button>
      </form>
    </div>
    <?php else: ?>
    <div class="card">
      <h2 class="titlecase">How to Use This</h2>
      <ul class="crm-list">
        <li>Compare your monthly report volume against your territory expectations.</li>
        <li>Watch doctor and hospital coverage, not only raw report count.</li>
        <li>Use pending and needs-changes counts to clean up submissions faster.</li>
      </ul>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../footer.php'; ?>
