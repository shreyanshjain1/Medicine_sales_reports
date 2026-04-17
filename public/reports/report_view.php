<?php
require_once __DIR__ . '/../../init.php';
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}
require_login();

$id = (int)($_GET['id'] ?? $_GET['rid'] ?? $_GET['report'] ?? $_GET['report_id'] ?? 0);
$roleRaw   = (string)(user()['role'] ?? 'employee');
$role      = strtolower(trim($roleRaw));
$meId = (int)(user()['id'] ?? 0);
$isManager  = in_array($role, ['manager', 'admin'], true);
$isDistrict = ($role === 'district_manager');
$debug = ((int)($_GET['debug'] ?? 0) === 1);

$r = null;
$stmt = $mysqli->prepare("SELECT r.*, u.name AS employee, u.email AS employee_email FROM reports r LEFT JOIN users u ON u.id = r.user_id WHERE r.id=? LIMIT 1");
if ($stmt) {
  $stmt->bind_param('i', $id);
  $stmt->execute();
  $r = $stmt->get_result()->fetch_assoc() ?: null;
  $stmt->close();
}
if (!$r) {
  $title = 'Report Not Found'; include __DIR__ . '/../header.php';
  echo '<div class="card"><h2 class="titlecase">Report Not Found</h2><p class="muted">The requested report could not be found.</p><a class="btn" href="'.e(url('reports/reports.php?showall=1')).'">Back to Reports</a></div>';
  include __DIR__ . '/../footer.php'; exit;
}

$ownerId = (int)($r['user_id'] ?? 0);
$canReview = false;
if ($isManager) $canReview = true;
elseif ($isDistrict && $meId > 0 && $ownerId > 0) $canReview = ($ownerId !== $meId) && is_assigned_to_district_manager($ownerId, $meId);
if (!$isManager && !can_view_user_reports($ownerId)) { http_response_code(403); exit('Forbidden'); }

$structuredType = 'general';
$structuredComment = '';
$flashSuccess = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canReview) {
  csrf_verify();
  $status  = $_POST['status'] ?? 'pending';
  $comment = trim($_POST['manager_comment'] ?? '');
  $structuredType = trim((string)($_POST['comment_type'] ?? 'general'));
  $structuredComment = trim((string)($_POST['structured_comment'] ?? ''));
  if (!in_array($status, ['pending', 'approved', 'needs_changes'], true)) $status = 'pending';
  $oldStatus = (string)($r['status'] ?? 'pending');
  $stmt = $mysqli->prepare("UPDATE reports SET status=?, manager_comment=? WHERE id=?");
  if ($stmt) {
    $stmt->bind_param('ssi', $status, $comment, $id);
    $stmt->execute();
    $stmt->close();
  }
  record_report_status($id, $status, $oldStatus, $comment);
  add_report_status_history($id, $oldStatus, $status, $comment !== '' ? $comment : 'Review status updated');
  if ($structuredComment !== '') {
    add_review_comment($id, $structuredType, $structuredComment, (int)(user()['id'] ?? 0));
    log_audit('report_review_comment_added', 'report', $id, 'Structured review comment added');
  }
  log_audit('report_reviewed', 'report', $id, 'Report reviewed and moved from ' . $oldStatus . ' to ' . $status);
  $statusLabelMap = ['pending' => 'Pending', 'approved' => 'Approved', 'needs_changes' => 'Needs changes'];
  $body = 'Your report #' . $id . ' was reviewed. New status: ' . ($statusLabelMap[$status] ?? ucfirst($status)) . '.';
  if ($comment !== '') $body .= ' Comment: ' . $comment;
  notify_user_prefaware($ownerId, 'Report review update', $body, 'report_review', 'report', $id, url('reports/report_view.php?id=' . $id), (int)(user()['id'] ?? 0));
  $r['status'] = $status; $r['manager_comment'] = $comment;
  $flashSuccess = 'Review updated.';
}

$comments = fetch_review_comments($id);
$timeline = [];
$h = $mysqli->prepare("SELECT h.created_at, h.old_status, h.new_status, h.comment, u.name AS actor_name FROM report_status_history h LEFT JOIN users u ON u.id=h.actor_user_id WHERE h.report_id=? ORDER BY h.created_at DESC, h.id DESC");
if ($h) { $h->bind_param('i', $id); $h->execute(); $timeline = $h->get_result()->fetch_all(MYSQLI_ASSOC) ?: []; $h->close(); }
$title = "Report #{$id}";
include __DIR__ . '/../header.php';
$st = (string)($r['status'] ?? 'pending');
if (!in_array($st, ['pending', 'approved', 'needs_changes'], true)) $st = 'pending';
?>
<div class="crm-hero ui-hero">
  <div><h2>Report #<?= (int)$id ?></h2><div class="subtle">Review submission details, status history, and structured manager feedback.</div></div>
  <div class="actions-inline"><button class="btn" onclick="window.print()">Print / Save as PDF</button><a class="btn" href="<?= e(url('reports/reports.php')) ?>">Back to Reports</a></div>
</div>
<div class="summary-grid summary-grid-dashboard">
  <?php ui_stat_card('Status', ucfirst(str_replace('_', ' ', $st)), 'Current review state', $st === 'approved' ? 'success' : ($st === 'needs_changes' ? 'warning' : 'default')); ?>
  <?php ui_stat_card('Structured Comments', count($comments), 'Manager feedback entries'); ?>
  <?php ui_stat_card('Timeline Events', count($timeline), 'Status movement history'); ?>
</div>
<div class="card">
  <?php form_messages([], [], $flashSuccess); ?>
  <div class="grid two">
    <div><strong>Employee:</strong> <?= e($r['employee'] ?? '') ?><br><small><?= e($r['employee_email'] ?? '') ?></small></div>
    <div><strong>Status:</strong> <?= ui_badge($st, $st) ?></div>
    <div><strong>Doctor:</strong> <?= e($r['doctor_name'] ?? '') ?><br><small><?= e($r['doctor_email'] ?? '') ?></small></div>
    <div><strong>Hospital/Clinic:</strong> <?= e($r['hospital_name'] ?? '') ?></div>
    <div><strong>Medicine:</strong> <?= e($r['medicine_name'] ?? '') ?></div>
    <div><strong>Purpose:</strong> <?= e($r['purpose'] ?? '') ?></div>
    <div><strong>Visit Datetime:</strong> <?= e($r['visit_datetime'] ?? '') ?></div>
    <div><strong>Latest Review Note:</strong> <?= e($r['manager_comment'] ?? '—') ?></div>
  </div>
  <hr>
  <p><strong>Summary:</strong><br><?= nl2br(e($r['summary'] ?? '')) ?></p>
  <p><strong>Remarks:</strong><br><?= nl2br(e($r['remarks'] ?? '')) ?></p>
  <?php if (!empty($r['attachment_path'])): ?><p><strong>Attachment:</strong> <a target="_blank" href="<?= e(ATTACH_URL . '/' . basename((string)$r['attachment_path'])) ?>">Download</a></p><?php endif; ?>
  <?php if (!empty($r['signature_path'])): ?><p><strong>Signature:</strong><br><img style="max-width:420px;background:#fff;padding:6px;border-radius:6px" src="<?= e(SIGNATURE_URL . '/' . basename((string)$r['signature_path'])) ?>"></p><?php endif; ?>
</div>

<div class="card">
  <?php ui_section_head('Review Timeline', 'Status history and manager decisions'); ?>
  <?php if (!$timeline): ?>
    <div class="muted">No timeline entries yet.</div>
  <?php else: ?>
    <div class="timeline-list">
      <?php foreach ($timeline as $item): ?>
        <div class="timeline-item">
          <div class="timeline-badge"><?= ui_badge((string)$item['new_status'], (string)$item['new_status']) ?></div>
          <div class="timeline-body">
            <div class="timeline-title"><?= e(($item['actor_name'] ?: 'System') . ' changed status to ' . ($item['new_status'] ?: 'pending')) ?></div>
            <div class="timeline-meta"><?= e((string)$item['created_at']) ?></div>
            <?php if (!empty($item['comment'])): ?><div class="timeline-note"><?= nl2br(e((string)$item['comment'])) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <?php ui_section_head('Structured Review Comments', 'Reusable feedback trail for reps'); ?>
  <?php if (!$comments): ?>
    <div class="muted">No structured comments yet.</div>
  <?php else: ?>
    <div class="comment-list">
      <?php foreach ($comments as $comment): ?>
        <div class="comment-card">
          <div class="comment-head"><?= ui_badge(ucwords(str_replace('_', ' ', (string)$comment['comment_type'])), (string)$comment['comment_type']) ?><span><?= e((string)($comment['actor_name'] ?: 'Manager')) ?> · <?= e((string)$comment['created_at']) ?></span></div>
          <div class="comment-body"><?= nl2br(e((string)$comment['comment_text'])) ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($canReview): ?>
<div class="card">
  <?php ui_section_head('Review Workspace', 'Update status and leave structured feedback'); ?>
  <div class="review-preset-row">
    <button class="btn tiny" type="button" data-comment-template="Doctor meeting details are complete and ready for approval.">Approval ready</button>
    <button class="btn tiny" type="button" data-comment-template="Please expand the summary with doctor reaction, product discussion, and next-step commitment.">Need fuller summary</button>
    <button class="btn tiny" type="button" data-comment-template="Please verify the visit date/time and update any missing hospital or medicine details.">Verify visit details</button>
    <button class="btn tiny" type="button" data-comment-template="Follow up with the rep on next visit date and product interest before closing this report.">Follow-up reminder</button>
  </div>
  <form method="post" class="form crm-form">
    <?= csrf_input(); ?>
    <div class="grid two">
      <?php render_select_input('Status', 'status', ['pending'=>'Pending','approved'=>'Approved','needs_changes'=>'Needs Changes'], $st); ?>
      <?php render_select_input('Structured Comment Type', 'comment_type', ['general'=>'General','approval_reason'=>'Approval Reason','change_request'=>'Change Request','follow_up'=>'Follow Up'], $structuredType); ?>
    </div>
    <?php render_textarea_input($isManager ? 'Manager Comment' : 'District Manager Comment', 'manager_comment', (string)($r['manager_comment'] ?? ''), ['rows'=>3,'placeholder'=>'Visible high-level review note']); ?>
    <?php render_textarea_input('Structured Comment', 'structured_comment', $structuredComment, ['rows'=>4,'id'=>'structured_comment_box','placeholder'=>'Add reusable feedback for the rep']); ?>
    <div class="actions-inline form-actions"><button class="btn primary titlecase">Update Review</button></div>
  </form>
</div>
<?php endif; ?>
<script>
document.querySelectorAll('[data-comment-template]').forEach(function(btn){
  btn.addEventListener('click', function(){
    var box = document.getElementById('structured_comment_box');
    if (box) box.value = btn.getAttribute('data-comment-template') || '';
  });
});
</script>
<?php include __DIR__ . '/../footer.php'; ?>
