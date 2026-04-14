<?php
require_once __DIR__ . '/../init.php';
require_login();
$id = (int)(getv('id', getv('rid', getv('report', getv('report_id', 0)))));
$role = my_role();
$meId = (int)(user()['id'] ?? 0);
$stmt = $mysqli->prepare("SELECT r.*, u.name AS employee, u.email AS employee_email FROM reports r LEFT JOIN users u ON u.id=r.user_id WHERE r.id=? LIMIT 1");
$stmt->bind_param('i', $id); $stmt->execute(); $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
if (!$r) { http_response_code(404); exit('Report not found'); }
$ownerId = (int)($r['user_id'] ?? 0);
$canReview = false;
if (is_manager()) $canReview = true;
elseif (is_district_manager() && $ownerId > 0) $canReview = ($ownerId !== $meId) && is_assigned_to_district_manager($ownerId, $meId);
if (!is_manager() && !can_view_user_reports($ownerId)) { http_response_code(403); exit('Forbidden'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canReview) {
  csrf_verify();
  $newStatus = trim((string)post('status','pending'));
  $comment = trim((string)post('manager_comment',''));
  if (!in_array($newStatus, ['pending','approved','needs_changes'], true)) $newStatus = 'pending';
  $oldStatus = (string)($r['status'] ?: 'pending');
  $stmt = $mysqli->prepare("UPDATE reports SET status=?, manager_comment=? WHERE id=?");
  $stmt->bind_param('ssi', $newStatus, $comment, $id);
  if ($stmt->execute()) {
    $stmt->close();
    $hs = $mysqli->prepare('INSERT INTO report_status_history (report_id, actor_user_id, old_status, new_status, comment) VALUES (?,?,?,?,?)');
    if ($hs) { $hs->bind_param('iisss', $id, $meId, $oldStatus, $newStatus, $comment); @$hs->execute(); $hs->close(); }
    log_audit('report_reviewed', 'report', $id, 'Status changed from ' . $oldStatus . ' to ' . $newStatus);
  }
  header('Location: ' . url('report_view.php?id=' . $id)); exit;
}
$history=[]; $hasHistory=$mysqli->query("SHOW TABLES LIKE 'report_status_history'"); if($hasHistory && $hasHistory->num_rows>0){ $hr=$mysqli->query("SELECT h.*, u.name AS actor_name FROM report_status_history h LEFT JOIN users u ON u.id=h.actor_user_id WHERE h.report_id=".(int)$id." ORDER BY h.created_at DESC, h.id DESC"); if($hr) while($x=$hr->fetch_assoc()) $history[]=$x; }
$title = 'Report #' . $id; include __DIR__ . '/header.php';
$st = (string)($r['status'] ?: 'pending'); if (!in_array($st, ['pending','approved','needs_changes'], true)) $st='pending';
?>
<div class="crm-hero"><div><h2>Report #<?= (int)$id ?></h2><div class="subtle">Review full visit details, attachments, and approval history.</div></div><button class="btn" onclick="window.print()">Print / Save PDF</button></div>
<div class="card">
  <div class="detail-grid">
    <div class="detail-card"><div class="eyebrow">Submitted by</div><div><strong><?= e($r['employee'] ?? '') ?></strong></div><div class="muted"><?= e($r['employee_email'] ?? '') ?></div></div>
    <div class="detail-card"><div class="eyebrow">Workflow status</div><div><span class="badge <?= e($st) ?>"><?= e($st) ?></span></div><div class="muted">Current review state</div></div>
    <div class="detail-card"><div class="eyebrow">Doctor</div><div><strong><?= e($r['doctor_name'] ?? '') ?></strong></div><div class="muted"><?= e($r['doctor_email'] ?? '') ?></div></div>
    <div class="detail-card"><div class="eyebrow">Hospital / Clinic</div><div><strong><?= e($r['hospital_name'] ?? '') ?></strong></div><div class="muted">Visit date: <?= e((string)($r['visit_datetime'] ?? '')) ?></div></div>
    <div class="detail-card"><div class="eyebrow">Medicine</div><div><strong><?= e($r['medicine_name'] ?? '') ?></strong></div></div>
    <div class="detail-card"><div class="eyebrow">Purpose</div><div><strong><?= e($r['purpose'] ?? '') ?></strong></div></div>
  </div>
  <div class="divider"></div>
  <div class="detail-grid">
    <div class="detail-card"><div class="eyebrow">Summary</div><div><?= nl2br(e($r['summary'] ?? '')) ?></div></div>
    <div class="detail-card"><div class="eyebrow">Remarks</div><div><?= nl2br(e($r['remarks'] ?? '')) ?></div></div>
  </div>
  <?php if (!empty($r['attachment_path'])): ?><p><strong>Attachment:</strong> <a target="_blank" href="<?= e(ATTACH_URL . '/' . basename((string)$r['attachment_path'])) ?>">Download attachment</a></p><?php endif; ?>
  <?php if (!empty($r['signature_path'])): ?><p><strong>Signature:</strong><br><img style="max-width:420px;background:#fff;padding:6px;border-radius:10px;border:1px solid #e6eaf2" src="<?= e(SIGNATURE_URL . '/' . basename((string)$r['signature_path'])) ?>"></p><?php endif; ?>
  <?php if ($canReview): ?>
    <div class="divider"></div>
    <form method="post" class="form">
      <?php csrf_input(); ?>
      <div class="grid two">
        <label>Status
          <select name="status">
            <option value="pending" <?= $st==='pending'?'selected':'' ?>>Pending</option>
            <option value="approved" <?= $st==='approved'?'selected':'' ?>>Approved</option>
            <option value="needs_changes" <?= $st==='needs_changes'?'selected':'' ?>>Needs Changes</option>
          </select>
        </label>
        <label><?= is_manager() ? 'Manager Comment' : 'District Manager Comment' ?><textarea name="manager_comment" rows="4"><?= e($r['manager_comment'] ?? '') ?></textarea></label>
      </div>
      <button class="btn primary">Update Review</button>
    </form>
  <?php endif; ?>
  <?php if ($history): ?>
    <div class="divider"></div>
    <h3 style="margin:0 0 12px">Approval History</h3>
    <div class="history-list">
      <?php foreach($history as $item): ?>
        <div class="history-item">
          <div><strong><?= e($item['actor_name'] ?: 'System') ?></strong> changed <span class="badge <?= e($item['new_status']) ?>"><?= e($item['new_status']) ?></span></div>
          <div class="muted">From <?= e($item['old_status'] ?: '—') ?> · <?= e((string)$item['created_at']) ?></div>
          <?php if (!empty($item['comment'])): ?><div style="margin-top:8px"><?= nl2br(e($item['comment'])) ?></div><?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/footer.php'; ?>
