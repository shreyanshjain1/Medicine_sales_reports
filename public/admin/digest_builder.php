<?php
require_once __DIR__.'/../../init.php';
require_manager();

$title = 'Digest Builder';
$me = user();
$presetMessage = '';
$presetError = '';
$selectedPreset = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  $action = trim((string)post('preset_action', ''));
  if ($action === 'save_preset') {
    $presetName = trim((string)post('preset_name', ''));
    $presetId = (int)post('preset_id', 0);
    if ($presetName === '') {
      $presetError = 'Preset name is required.';
    } else {
      $payload = digest_preset_payload(
        trim((string)post('range', 'week')),
        trim((string)post('date_from', '')),
        trim((string)post('date_to', '')),
        (int)post('employee_id', 0),
        trim((string)post('status', 'all')),
      );
      if (save_digest_preset((int)$me['id'], $presetName, $payload, $presetId > 0 ? $presetId : null)) {
        $presetMessage = $presetId > 0 ? 'Digest preset updated.' : 'Digest preset saved.';
      } else {
        $presetError = 'Unable to save the digest preset.';
      }
    }
  } elseif ($action === 'delete_preset') {
    $presetId = (int)post('preset_id', 0);
    if ($presetId > 0 && delete_digest_preset($presetId, (int)$me['id'])) {
      $presetMessage = 'Digest preset deleted.';
    } else {
      $presetError = 'Unable to delete that digest preset.';
    }
  }
}

$selectedPresetId = (int)getv('preset_id', 0);
if ($selectedPresetId > 0) {
  $selectedPreset = fetch_digest_preset_by_id($selectedPresetId, (int)$me['id']);
  if ($selectedPreset && !empty($selectedPreset['preset'])) {
    foreach (['range','date_from','date_to','employee_id','status'] as $k) {
      if (isset($selectedPreset['preset'][$k]) && !isset($_GET[$k])) {
        $_GET[$k] = (string)$selectedPreset['preset'][$k];
      }
    }
  }
}

$range = trim((string)getv('range', 'week'));
$employee_id = (int)getv('employee_id', 0);
$status = trim((string)getv('status', 'all'));

function digest_default_dates(string $range): array {
  $today = date('Y-m-d');
  switch ($range) {
    case 'today': return [$today, $today];
    case 'month': return [date('Y-m-01'), date('Y-m-t')];
    case 'week':
    default: return [date('Y-m-d', strtotime('monday this week')), date('Y-m-d', strtotime('sunday this week'))];
  }
}
[$defaultFrom, $defaultTo] = digest_default_dates($range);
$date_from = trim((string)getv('date_from', $defaultFrom));
$date_to = trim((string)getv('date_to', $defaultTo));

$scope = reports_scope_where('r');
$where = "WHERE {$scope}";
if ($date_from !== '') $where .= " AND DATE(r.visit_datetime) >= '" . $mysqli->real_escape_string($date_from) . "'";
if ($date_to !== '') $where .= " AND DATE(r.visit_datetime) <= '" . $mysqli->real_escape_string($date_to) . "'";
if ($employee_id > 0) $where .= ' AND r.user_id=' . $employee_id;
if ($status !== 'all' && in_array($status, ['pending','approved','needs_changes'], true)) {
  $where .= " AND COALESCE(NULLIF(r.status,''),'pending')='" . $mysqli->real_escape_string($status) . "'";
}

$summary = $mysqli->query("SELECT COUNT(*) total_reports,
  COUNT(DISTINCT r.user_id) reps_count,
  COUNT(DISTINCT NULLIF(TRIM(r.doctor_name), '')) doctors_count,
  COUNT(DISTINCT NULLIF(TRIM(r.hospital_name), '')) hospitals_count,
  COUNT(DISTINCT NULLIF(TRIM(r.medicine_name), '')) medicines_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='approved' THEN 1 ELSE 0 END) approved_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='pending' THEN 1 ELSE 0 END) pending_count,
  SUM(CASE WHEN COALESCE(NULLIF(r.status,''),'pending')='needs_changes' THEN 1 ELSE 0 END) needs_changes_count
  FROM reports r {$where}");
$summary = $summary ? ($summary->fetch_assoc() ?: []) : [];

$topRep = $mysqli->query("SELECT u.name, COUNT(*) total_reports FROM reports r JOIN users u ON u.id=r.user_id {$where} GROUP BY u.id,u.name ORDER BY total_reports DESC, u.name ASC LIMIT 3");
$topReps = [];
if ($topRep) while ($x = $topRep->fetch_assoc()) $topReps[] = $x;
$topMed = $mysqli->query("SELECT r.medicine_name label, COUNT(*) total_reports FROM reports r {$where} AND NULLIF(TRIM(r.medicine_name), '') IS NOT NULL GROUP BY r.medicine_name ORDER BY total_reports DESC, label ASC LIMIT 3");
$topMeds = [];
if ($topMed) while ($x = $topMed->fetch_assoc()) $topMeds[] = $x;
$pendingRows = $mysqli->query("SELECT r.id, u.name employee, r.doctor_name, r.hospital_name, r.visit_datetime, COALESCE(NULLIF(r.status,''),'pending') status FROM reports r JOIN users u ON u.id=r.user_id {$where} ORDER BY r.visit_datetime DESC LIMIT 8");
$pendingRowsArr = [];
if ($pendingRows) while ($x = $pendingRows->fetch_assoc()) $pendingRowsArr[] = $x;

$subject = 'Manager Digest · ' . APP_NAME . ' · ' . $date_from . ' to ' . $date_to;
$repText = $topReps ? implode("\n", array_map(fn($r) => '- ' . $r['name'] . ': ' . (int)$r['total_reports'] . ' reports', $topReps)) : '- No rep activity';
$medText = $topMeds ? implode("\n", array_map(fn($r) => '- ' . $r['label'] . ': ' . (int)$r['total_reports'] . ' reports', $topMeds)) : '- No medicine activity';
$plain = "Reporting Period: {$date_from} to {$date_to}\n"
  . "Total Reports: " . (int)($summary['total_reports'] ?? 0) . "\n"
  . "Approved: " . (int)($summary['approved_count'] ?? 0) . "\n"
  . "Pending: " . (int)($summary['pending_count'] ?? 0) . "\n"
  . "Needs Changes: " . (int)($summary['needs_changes_count'] ?? 0) . "\n"
  . "Doctors Covered: " . (int)($summary['doctors_count'] ?? 0) . "\n"
  . "Hospitals Covered: " . (int)($summary['hospitals_count'] ?? 0) . "\n"
  . "Medicines Covered: " . (int)($summary['medicines_count'] ?? 0) . "\n\n"
  . "Top Reps\n{$repText}\n\nTop Medicines\n{$medText}\n\n"
  . "Generated from " . APP_NAME . ".";

$html = '<h2>Manager Digest</h2>'
  . '<p><strong>Reporting Period:</strong> ' . e($date_from) . ' to ' . e($date_to) . '</p>'
  . '<ul>'
  . '<li><strong>Total Reports:</strong> ' . (int)($summary['total_reports'] ?? 0) . '</li>'
  . '<li><strong>Approved:</strong> ' . (int)($summary['approved_count'] ?? 0) . '</li>'
  . '<li><strong>Pending:</strong> ' . (int)($summary['pending_count'] ?? 0) . '</li>'
  . '<li><strong>Needs Changes:</strong> ' . (int)($summary['needs_changes_count'] ?? 0) . '</li>'
  . '<li><strong>Doctors Covered:</strong> ' . (int)($summary['doctors_count'] ?? 0) . '</li>'
  . '<li><strong>Hospitals Covered:</strong> ' . (int)($summary['hospitals_count'] ?? 0) . '</li>'
  . '<li><strong>Medicines Covered:</strong> ' . (int)($summary['medicines_count'] ?? 0) . '</li>'
  . '</ul>'
  . '<h3>Top Reps</h3><ul>' . ($topReps ? implode('', array_map(fn($r) => '<li><strong>' . e($r['name']) . '</strong>: ' . (int)$r['total_reports'] . ' reports</li>', $topReps)) : '<li>No rep activity</li>') . '</ul>'
  . '<h3>Top Medicines</h3><ul>' . ($topMeds ? implode('', array_map(fn($r) => '<li><strong>' . e($r['label']) . '</strong>: ' . (int)$r['total_reports'] . ' reports</li>', $topMeds)) : '<li>No medicine activity</li>') . '</ul>'
  . '<p>Generated from <strong>' . e(APP_NAME) . '</strong>.</p>';

$shareQuery = http_build_query(array_filter([
  'range'=>$range ?: null,
  'date_from'=>$date_from ?: null,
  'date_to'=>$date_to ?: null,
  'employee_id'=>$employee_id ?: null,
  'status'=>$status !== 'all' ? $status : null,
], static fn($v) => $v !== null && $v !== ''));

$users = [];
if ($res = $mysqli->query("SELECT id,name FROM users WHERE active=1 ORDER BY name ASC")) while ($x = $res->fetch_assoc()) $users[] = $x;
$presets = fetch_digest_presets_for_user((int)$me['id']);

include __DIR__.'/../header.php';
?>
<div class="page-head">
  <div>
    <h2>Digest Builder</h2>
    <p class="muted">Create a copy-ready summary for email, chat, or weekly management updates.</p>
  </div>
  <div class="actions-inline">
    <a class="btn" href="manager_summary.php?<?= e($shareQuery) ?>">Open Manager Summary</a>
    <a class="btn primary" href="exports.php?<?= e($shareQuery) ?>&download=1">Download Matching CSV</a>
  </div>
</div>

<?php if ($presetMessage): ?><div class="alert success"><?= e($presetMessage) ?></div><?php endif; ?>
<?php if ($presetError): ?><div class="alert danger"><?= e($presetError) ?></div><?php endif; ?>

<div class="grid summary-two-col">
  <div class="card">
    <form class="filters filters-6" method="get">
      <label>Preset Range
        <select name="range">
          <option value="today" <?= $range==='today'?'selected':'' ?>>Today</option>
          <option value="week" <?= $range==='week'?'selected':'' ?>>This Week</option>
          <option value="month" <?= $range==='month'?'selected':'' ?>>This Month</option>
          <option value="custom" <?= $range==='custom'?'selected':'' ?>>Custom</option>
        </select>
      </label>
      <label>Date From<input type="date" name="date_from" value="<?= e($date_from) ?>"></label>
      <label>Date To<input type="date" name="date_to" value="<?= e($date_to) ?>"></label>
      <label>Employee
        <select name="employee_id">
          <option value="0">All</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $employee_id === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Status
        <select name="status">
          <option value="all" <?= $status==='all'?'selected':'' ?>>All</option>
          <option value="pending" <?= $status==='pending'?'selected':'' ?>>Pending</option>
          <option value="approved" <?= $status==='approved'?'selected':'' ?>>Approved</option>
          <option value="needs_changes" <?= $status==='needs_changes'?'selected':'' ?>>Needs Changes</option>
        </select>
      </label>
      <div class="actions-inline">
        <button class="btn primary">Refresh Digest</button>
        <a class="btn" href="<?= url('admin/digest_builder.php') ?>">Reset</a>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="card-head split"><div><h3>Digest Presets</h3><div class="subtle">Save common management filter sets for weekly and monthly digests.</div></div></div>
    <form method="post" class="form crm-form">
      <?php csrf_input(); ?>
      <input type="hidden" name="preset_action" value="save_preset">
      <input type="hidden" name="preset_id" value="<?= $selectedPresetId > 0 ? $selectedPresetId : 0 ?>">
      <input type="hidden" name="range" value="<?= e($range) ?>">
      <input type="hidden" name="date_from" value="<?= e($date_from) ?>">
      <input type="hidden" name="date_to" value="<?= e($date_to) ?>">
      <input type="hidden" name="employee_id" value="<?= (int)$employee_id ?>">
      <input type="hidden" name="status" value="<?= e($status) ?>">
      <label>Preset Name<input type="text" name="preset_name" value="<?= e($selectedPreset['name'] ?? '') ?>" placeholder="Weekly rep digest"></label>
      <div class="form-actions"><button class="btn primary">Save Current Filters as Preset</button></div>
    </form>
    <div class="table-wrap" style="margin-top:1rem;">
      <table class="table">
        <thead><tr><th>Name</th><th>Filters</th><th style="width:190px">Actions</th></tr></thead>
        <tbody>
        <?php if (!$presets): ?>
          <tr><td colspan="3" class="muted">No digest presets saved yet.</td></tr>
        <?php else: foreach ($presets as $preset): $p = $preset['preset']; ?>
          <tr>
            <td><strong><?= e($preset['name']) ?></strong><br><small class="muted">Updated <?= e((string)$preset['updated_at']) ?></small></td>
            <td>
              <small class="muted"><?= e((string)($p['range'] ?? 'custom')) ?> · <?= e((string)($p['date_from'] ?? '')) ?> to <?= e((string)($p['date_to'] ?? '')) ?> · <?= e((string)($p['status'] ?? 'all')) ?></small>
            </td>
            <td>
              <div class="actions-inline">
                <a class="btn" href="<?= url('admin/digest_builder.php?preset_id='.(int)$preset['id']) ?>">Load</a>
                <form method="post" onsubmit="return confirm('Delete this preset?');" style="display:inline;">
                  <?php csrf_input(); ?>
                  <input type="hidden" name="preset_action" value="delete_preset">
                  <input type="hidden" name="preset_id" value="<?= (int)$preset['id'] ?>">
                  <button class="btn danger" type="submit">Delete</button>
                </form>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<div class="summary-grid summary-grid-dashboard summary-grid-tight">
  <div class="card summary-card"><div class="summary-label">Total Reports</div><div class="summary-value"><?= (int)($summary['total_reports'] ?? 0) ?></div></div>
  <div class="card summary-card"><div class="summary-label">Approved</div><div class="summary-value"><?= (int)($summary['approved_count'] ?? 0) ?></div></div>
  <div class="card summary-card"><div class="summary-label">Pending</div><div class="summary-value"><?= (int)($summary['pending_count'] ?? 0) ?></div></div>
  <div class="card summary-card"><div class="summary-label">Coverage</div><div class="summary-value"><?= (int)($summary['doctors_count'] ?? 0) ?>/<?= (int)($summary['hospitals_count'] ?? 0) ?></div></div>
</div>

<div class="grid summary-two-col">
  <div class="card">
    <div class="card-head split"><div><h3>Email Subject</h3><div class="subtle">Use this for manual email sending.</div></div></div>
    <textarea class="digest-box" readonly><?= e($subject) ?></textarea>
    <div class="card-head split"><div><h3>Plain Text Digest</h3><div class="subtle">Copy into email, Slack, Teams, or WhatsApp.</div></div></div>
    <textarea class="digest-box digest-lg" readonly><?= e($plain) ?></textarea>
  </div>
  <div class="card">
    <div class="card-head split"><div><h3>HTML Digest</h3><div class="subtle">Copy into HTML-capable email tools.</div></div></div>
    <textarea class="digest-box digest-lg" readonly><?= e($html) ?></textarea>
  </div>
</div>

<div class="card">
  <div class="card-head split"><div><h3>Included Recent Rows</h3><div class="subtle">Quick reference for what is represented in the digest.</div></div></div>
  <div class="table-wrap">
    <table class="table">
      <thead><tr><th>Employee</th><th>Doctor</th><th>Hospital</th><th>Visit</th><th>Status</th></tr></thead>
      <tbody>
      <?php if (!$pendingRowsArr): ?>
        <tr><td colspan="5" class="muted">No matching rows found.</td></tr>
      <?php else: foreach ($pendingRowsArr as $row): ?>
        <tr>
          <td><?= e($row['employee']) ?></td>
          <td><?= e($row['doctor_name']) ?></td>
          <td><?= e($row['hospital_name']) ?></td>
          <td><?= e($row['visit_datetime']) ?></td>
          <td><span class="badge <?= e($row['status']) ?>"><?= e($row['status']) ?></span></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__.'/../footer.php'; ?>
