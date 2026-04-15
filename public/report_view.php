<?php
require_once __DIR__ . '/../init.php';
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}
require_login();

$id = 0;
if (isset($_GET['id']))             $id = (int)$_GET['id'];
elseif (isset($_GET['rid']))        $id = (int)$_GET['rid'];
elseif (isset($_GET['report']))     $id = (int)$_GET['report'];
elseif (isset($_GET['report_id']))  $id = (int)$_GET['report_id'];

$roleRaw   = (string)(user()['role'] ?? 'employee');
$role      = strtolower(trim($roleRaw));
$meId = (int)(user()['id'] ?? 0);
$isManager  = in_array($role, ['manager', 'admin'], true);
$isDistrict = ($role === 'district_manager');
$debug = ((int)($_GET['debug'] ?? 0) === 1);

$r = null;
$sql_err = '';
$sql_debug = "
SELECT
  r.id                AS report_id,
  r.user_id           AS report_user_id,
  r.doctor_name,
  r.doctor_email,
  r.hospital_name,
  r.medicine_name,
  r.purpose,
  r.visit_datetime,
  COALESCE(NULLIF(r.status,''),'pending') AS status,
  r.summary,
  r.remarks,
  r.manager_comment,
  r.attachment_path,
  r.signature_path,
  r.created_at,
  u.name              AS employee,
  u.email             AS employee_email
FROM reports r
LEFT JOIN users u ON u.id = r.user_id
WHERE r.id = ?
LIMIT 1
";

if ($id > 0) {
  $stmt = $mysqli->prepare($sql_debug);
  if (!$stmt) {
    $sql_err = "Prepare failed: " . $mysqli->error;
  } else {
    $stmt->bind_param('i', $id);
    if (!$stmt->execute()) {
      $sql_err = "Execute failed: " . $stmt->error;
    } else {
      $stmt->store_result();
      if ($stmt->num_rows > 0) {
        $report_id = $report_user_id = 0;
        $doctor_name = $doctor_email = $hospital_name = $medicine_name = $purpose = $visit_datetime = '';
        $status = $summary = $remarks = $manager_comment = $attachment_path = $signature_path = $created_at = '';
        $employee = $employee_email = '';
        $stmt->bind_result(
          $report_id,
          $report_user_id,
          $doctor_name,
          $doctor_email,
          $hospital_name,
          $medicine_name,
          $purpose,
          $visit_datetime,
          $status,
          $summary,
          $remarks,
          $manager_comment,
          $attachment_path,
          $signature_path,
          $created_at,
          $employee,
          $employee_email
        );
        $stmt->fetch();
        $r = [
          'report_id'       => $report_id,
          'report_user_id'  => $report_user_id,
          'doctor_name'     => $doctor_name,
          'doctor_email'    => $doctor_email,
          'hospital_name'   => $hospital_name,
          'medicine_name'   => $medicine_name,
          'purpose'         => $purpose,
          'visit_datetime'  => $visit_datetime,
          'status'          => $status ?: 'pending',
          'summary'         => $summary,
          'remarks'         => $remarks,
          'manager_comment' => $manager_comment,
          'attachment_path' => $attachment_path,
          'signature_path'  => $signature_path,
          'created_at'      => $created_at,
          'employee'        => $employee,
          'employee_email'  => $employee_email,
        ];
      }
    }
    $stmt->close();
  }
}

if (!is_array($r)) {
  $title = 'Report Not Found';
  include __DIR__ . '/header.php'; ?>
  <div class="card">
    <h2 class="titlecase">Report Not Found</h2>
    <?php if ($debug): ?>
      <div class="alert" style="margin:.75rem 0">
        <div><b>ID:</b> <code><?= (int)$id ?></code></div>
        <div><b>DB Error:</b> <code><?= e($sql_err ?: '(none)') ?></code></div>
      </div>
    <?php endif; ?>
    <p class="muted">The report you’re trying to open does not exist or could not be fetched.</p>
    <a class="btn" href="<?= e(url('reports.php?showall=1')) ?>">Back to Reports</a>
  </div>
  <?php include __DIR__ . '/footer.php';
  exit;
}

$ownerId = (int)($r['report_user_id'] ?? 0);
$canReview = false;
if ($isManager) {
  $canReview = true;
} elseif ($isDistrict && $meId > 0 && $ownerId > 0) {
  $canReview = ($ownerId !== $meId) && is_assigned_to_district_manager($ownerId, $meId);
}

if (!$isManager) {
  if (!can_view_user_reports($ownerId)) {
    http_response_code(403);
    $title = 'Forbidden';
    include __DIR__ . '/header.php'; ?>
    <div class="card">
      <h2 class="titlecase">Forbidden</h2>
      <p class="muted">You don’t have access to this report.</p>
    </div>
    <?php include __DIR__ . '/footer.php';
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canReview) {
  csrf_verify();

  $status  = trim((string)($_POST['status'] ?? 'pending'));
  $comment = trim((string)($_POST['manager_comment'] ?? ''));
  $oldStatus = (string)($r['status'] ?? 'pending');
  if (!in_array($status, ['pending', 'approved', 'needs_changes'], true)) {
    $status = 'pending';
  }

  $stmt = $mysqli->prepare("UPDATE reports SET status=?, manager_comment=? WHERE id=?");
  if ($stmt) {
    $stmt->bind_param('ssi', $status, $comment, $id);
    $stmt->execute();
    $stmt->close();
  }

  if ($oldStatus !== $status || $comment !== (string)($r['manager_comment'] ?? '')) {
    record_report_status($id, $oldStatus, $status, $comment);
    log_audit('report_review_updated', 'report', $id, [
      'old_status' => $oldStatus,
      'new_status' => $status,
      'comment' => $comment,
    ]);
  }

  $statusLabelMap = ['pending' => 'Pending', 'approved' => 'Approved', 'needs_changes' => 'Needs changes'];
  $body = 'Your report #' . $id . ' was reviewed. New status: ' . ($statusLabelMap[$status] ?? ucfirst($status)) . '.';
  if ($comment !== '') $body .= ' Comment: ' . $comment;
  notify_user(
    $ownerId,
    'Report review update',
    $body,
    'report_review',
    'report',
    $id,
    url('report_view.php?id=' . $id),
    (int)(user()['id'] ?? 0)
  );

  header('Location: ' . url('report_view.php?id=' . $id));
  exit;
}

$title = "Report #{$id}";
include __DIR__ . '/header.php';
$st = (string)($r['status'] ?? 'pending');
if (!in_array($st, ['pending', 'approved', 'needs_changes'], true)) $st = 'pending';
$timeline = get_report_timeline($id, 20);
$queueSummary = report_review_summary();
$createdDisplay = $r['created_at'] ?: $r['visit_datetime'];
?>

<div class="page-head">
  <div>
    <h2 class="titlecase">Report Review Workspace</h2>
    <div class="subtle">Review report details, update status, and track the full approval timeline.</div>
  </div>
  <div class="actions-inline">
    <a class="btn" href="<?= e(url('reports.php')) ?>">Back to Reports</a>
    <?php if ($canReview): ?><a class="btn" href="<?= e(url('approvals.php')) ?>">Open Queue</a><?php endif; ?>
    <button class="btn primary" onclick="window.print()">Print / Save PDF</button>
  </div>
</div>

<div class="summary-grid summary-grid-dashboard review-summary-grid">
  <div class="card summary-card">
    <div class="summary-label">Current Status</div>
    <div class="summary-value summary-value-sm"><?= e(ucfirst(str_replace('_', ' ', $st))) ?></div>
  </div>
  <div class="card summary-card">
    <div class="summary-label">Pending In Queue</div>
    <div class="summary-value summary-value-sm"><?= (int)$queueSummary['pending'] ?></div>
  </div>
  <div class="card summary-card">
    <div class="summary-label">Needs Changes</div>
    <div class="summary-value summary-value-sm"><?= (int)$queueSummary['needs_changes'] ?></div>
  </div>
  <div class="card summary-card">
    <div class="summary-label">Overdue Pending</div>
    <div class="summary-value summary-value-sm"><?= (int)$queueSummary['overdue_pending'] ?></div>
  </div>
</div>

<div class="review-layout">
  <div class="review-main">
    <div class="card review-card">
      <div class="review-head">
        <div>
          <div class="summary-label">Report ID</div>
          <h3 class="titlecase">#<?= (int)$id ?> · <?= e($r['doctor_name'] ?: 'Doctor Visit') ?></h3>
        </div>
        <span class="badge <?= e($st) ?> review-badge"><?= e(ucfirst(str_replace('_', ' ', $st))) ?></span>
      </div>

      <?php if ($debug): ?>
        <div class="alert">
          <div><b>Role raw:</b> <code><?= e($roleRaw) ?></code></div>
          <div><b>Role norm:</b> <code><?= e($role) ?></code></div>
          <div><b>canReview:</b> <code><?= $canReview ? 'YES' : 'NO' ?></code></div>
          <details style="margin-top:.5rem"><summary>Row</summary><pre><?= e(json_encode($r, JSON_PRETTY_PRINT)) ?></pre></details>
        </div>
      <?php endif; ?>

      <div class="detail-grid detail-grid-2">
        <div class="detail-item"><span>Employee</span><strong><?= e($r['employee'] ?: '—') ?></strong><small><?= e($r['employee_email'] ?: '') ?></small></div>
        <div class="detail-item"><span>Visit Datetime</span><strong><?= e($r['visit_datetime'] ?: '—') ?></strong><small>Submitted: <?= e($createdDisplay ?: '—') ?></small></div>
        <div class="detail-item"><span>Doctor</span><strong><?= e($r['doctor_name'] ?: '—') ?></strong><small><?= e($r['doctor_email'] ?: '') ?></small></div>
        <div class="detail-item"><span>Hospital / Clinic</span><strong><?= e($r['hospital_name'] ?: '—') ?></strong><small>Purpose: <?= e($r['purpose'] ?: '—') ?></small></div>
        <div class="detail-item"><span>Medicine</span><strong><?= e($r['medicine_name'] ?: '—') ?></strong><small>Review flow ready</small></div>
        <div class="detail-item"><span>Manager Comment</span><strong><?= e($r['manager_comment'] !== '' ? $r['manager_comment'] : 'No comment yet') ?></strong><small>Visible to the report owner</small></div>
      </div>

      <div class="content-blocks">
        <div class="content-block">
          <div class="block-label">Summary</div>
          <div class="block-body"><?= nl2br(e($r['summary'] ?: 'No summary provided.')) ?></div>
        </div>
        <div class="content-block">
          <div class="block-label">Remarks</div>
          <div class="block-body"><?= nl2br(e($r['remarks'] ?: 'No additional remarks.')) ?></div>
        </div>
      </div>

      <div class="asset-row">
        <?php if (!empty($r['attachment_path'])): ?>
          <a class="btn" target="_blank" href="<?= e(ATTACH_URL . '/' . basename($r['attachment_path'])) ?>">Download Attachment</a>
        <?php endif; ?>
        <?php if (!empty($r['signature_path'])): ?>
          <div class="signature-preview">
            <div class="summary-label">Signature</div>
            <img src="<?= e(SIGNATURE_URL . '/' . basename($r['signature_path'])) ?>" alt="Signature">
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="review-side">
    <?php if ($canReview): ?>
      <div class="card review-form-card">
        <div class="review-side-head">
          <h3 class="titlecase">Review Decision</h3>
          <div class="subtle">Update the status and leave a clear handoff comment.</div>
        </div>
        <form method="post" class="form">
          <?= csrf_input(); ?>
          <label class="titlecase">Status
            <select name="status">
              <option value="pending" <?= $st === 'pending' ? 'selected' : '' ?>>Pending</option>
              <option value="approved" <?= $st === 'approved' ? 'selected' : '' ?>>Approved</option>
              <option value="needs_changes" <?= $st === 'needs_changes' ? 'selected' : '' ?>>Needs Changes</option>
            </select>
          </label>
          <label class="titlecase"><?= $isManager ? 'Manager Comment' : 'District Manager Comment' ?>
            <textarea name="manager_comment" rows="5" placeholder="Add approval notes or request changes"><?= e($r['manager_comment'] ?? '') ?></textarea>
          </label>
          <div class="review-actions-stack">
            <button class="btn primary block titlecase">Save Review Update</button>
            <small class="muted">This action updates the report status, notifies the report owner, and records the timeline entry.</small>
          </div>
        </form>
      </div>
    <?php endif; ?>

    <div class="card timeline-card">
      <div class="review-side-head">
        <h3 class="titlecase">Approval Timeline</h3>
        <div class="subtle">Status changes and report activity in one place.</div>
      </div>
      <?php if (!$timeline): ?>
        <div class="empty-mini">No timeline activity yet for this report.</div>
      <?php else: ?>
        <div class="timeline-list">
          <?php foreach ($timeline as $item): ?>
            <div class="timeline-item timeline-<?= e($item['kind']) ?>">
              <div class="timeline-dot"></div>
              <div class="timeline-content">
                <div class="timeline-topline">
                  <strong><?= e($item['title']) ?></strong>
                  <span><?= e($item['created_at']) ?></span>
                </div>
                <div class="timeline-meta"><?= e($item['actor']) ?> · <?= e($item['meta']) ?></div>
                <?php if (!empty($item['comment'])): ?>
                  <div class="timeline-note"><?= nl2br(e($item['comment'])) ?></div>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
