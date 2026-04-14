<?php
require_once __DIR__ . '/../init.php';
<<<<<<< HEAD
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
=======
if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}
require_login();

/* -------------------------------------------------------
   Safe helpers (prevents blank page if helper missing)
-------------------------------------------------------- */
if (!function_exists('e')) {
  function e($v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
  }
}

/* -------------------------------------------------------
   CSRF fallback (fixes manager blank page if csrf_* missing)
-------------------------------------------------------- */
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() === PHP_SESSION_NONE) session_start();
    if (empty($_SESSION['_csrf'])) {
      $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['_csrf'];
  }
}
if (!function_exists('csrf_input')) {
  function csrf_input(): string {
    return '<input type="hidden" name="csrf" value="' . htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8') . '">';
  }
}
if (!function_exists('csrf_verify')) {
  function csrf_verify(): void {
    if (session_status() === PHP_SESSION_NONE) session_start();
    $post = (string)($_POST['csrf'] ?? '');
    $sess = (string)($_SESSION['_csrf'] ?? '');
    if ($post === '' || $sess === '' || !hash_equals($sess, $post)) {
      http_response_code(419);
      echo "CSRF verification failed.";
      exit;
    }
  }
}

/* -------------------------------------------------------
   Inputs / role
-------------------------------------------------------- */
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

/* -------------------------------------------------------
   Fetch report (mysqlnd-safe: bind_result)
-------------------------------------------------------- */
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
        $status = $summary = $remarks = $manager_comment = $attachment_path = $signature_path = '';
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
          'employee'        => $employee,
          'employee_email'  => $employee_email,
        ];
      }
    }
    $stmt->close();
  }
}

/* -------------------------------------------------------
   Not found / Query error
-------------------------------------------------------- */
if (!is_array($r)) {
  $title = 'Report Not Found';
  include __DIR__ . '/header.php'; ?>
  <div class="card">
    <h2 class="titlecase">Report Not Found</h2>

    <?php if ($debug): ?>
      <div class="alert" style="margin:.75rem 0">
        <div><b>ID:</b> <code><?= (int)$id ?></code></div>
        <div><b>ROLE RAW:</b> <code><?= e($roleRaw) ?></code></div>
        <div><b>ROLE NORM:</b> <code><?= e($role) ?></code></div>
        <div><b>isManager:</b> <code><?= $isManager ? 'YES' : 'NO' ?></code></div>
        <div><b>isDistrict:</b> <code><?= $isDistrict ? 'YES' : 'NO' ?></code></div>
        <div><b>DB Error:</b> <code><?= e($sql_err ?: '(none)') ?></code></div>
        <div style="margin-top:.4rem"><b>SQL:</b> <code><?= e($sql_debug) ?></code></div>
      </div>
    <?php endif; ?>

    <p class="muted">The report you’re trying to open (ID: <?= (int)$id ?>) doesn’t exist (or could not be fetched).</p>
    <a class="btn" href="<?= e(url('reports.php?showall=1')) ?>">Back to Reports</a>
  </div>
  <?php include __DIR__ . '/footer.php';
  exit;
}

/* -------------------------------------------------------
   Access control
   Manager/Admin can view ANY report always.
   Others must be within scope.
-------------------------------------------------------- */
$ownerId = (int)($r['report_user_id'] ?? 0);

// Review permission:
// - Manager/Admin: can review any report
// - District Manager: can review reports of employees assigned to them (not their own)
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
      <?php if ($debug): ?>
        <div class="alert" style="margin-top:.75rem">
          <div><b>ownerId:</b> <code><?= (int)$ownerId ?></code></div>
          <div><b>ROLE RAW:</b> <code><?= e($roleRaw) ?></code></div>
          <div><b>ROLE NORM:</b> <code><?= e($role) ?></code></div>
          <div><b>isManager:</b> <code><?= $isManager ? 'YES' : 'NO' ?></code></div>
        </div>
      <?php endif; ?>
    </div>
    <?php include __DIR__ . '/footer.php';
    exit;
  }
}

/* -------------------------------------------------------
   Review update (Manager + District Manager)
-------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canReview) {
  csrf_verify();

  $status  = $_POST['status'] ?? 'pending';
  $comment = trim($_POST['manager_comment'] ?? '');

  if (!in_array($status, ['pending', 'approved', 'needs_changes'], true)) {
    $status = 'pending';
  }

  $stmt = $mysqli->prepare("UPDATE reports SET status=?, manager_comment=? WHERE id=?");
  if ($stmt) {
    $stmt->bind_param('ssi', $status, $comment, $id);
    $stmt->execute();
    $stmt->close();
  }

  header('Location: ' . url('report_view.php?id=' . $id));
  exit;
}

/* -------------------------------------------------------
   Render
-------------------------------------------------------- */
$title = "Report #{$id}";
include __DIR__ . '/header.php';

$st = (string)($r['status'] ?? 'pending');
if (!in_array($st, ['pending', 'approved', 'needs_changes'], true)) $st = 'pending';
?>

<div class="card">
  <div class="flex between center">
    <h2 class="titlecase">Report #<?= (int)$id ?></h2>
    <button class="btn" onclick="window.print()">Print / Save as PDF</button>
  </div>

  <?php if ($debug): ?>
    <div class="alert">
      <div><b>Role raw:</b> <code><?= e($roleRaw) ?></code></div>
      <div><b>Role norm:</b> <code><?= e($role) ?></code></div>
      <div><b>isManager:</b> <code><?= $isManager ? 'YES' : 'NO' ?></code></div>
      <div><b>isDistrict:</b> <code><?= $isDistrict ? 'YES' : 'NO' ?></code></div>
      <div><b>canReview:</b> <code><?= $canReview ? 'YES' : 'NO' ?></code></div>
      <details style="margin-top:.5rem">
        <summary>Row</summary>
        <pre><?= e(json_encode($r, JSON_PRETTY_PRINT)) ?></pre>
      </details>
    </div>
  <?php endif; ?>

  <p><strong>Employee:</strong> <?= e($r['employee'] ?? '') ?> (<?= e($r['employee_email'] ?? '') ?>)</p>

  <div class="grid two">
    <div>
      <strong>Doctor:</strong> <?= e($r['doctor_name'] ?? '') ?><br>
      <small><?= e($r['doctor_email'] ?? '') ?></small>
    </div>
    <div><strong>Hospital/Clinic:</strong> <?= e($r['hospital_name'] ?? '') ?></div>
    <div><strong>Medicine:</strong> <?= e($r['medicine_name'] ?? '') ?></div>
    <div><strong>Purpose:</strong> <?= e($r['purpose'] ?? '') ?></div>
    <div><strong>Visit Datetime:</strong> <?= e($r['visit_datetime'] ?? '') ?></div>
    <div><strong>Status:</strong> <span class="badge <?= e($st) ?>"><?= e($st) ?></span></div>
  </div>

  <hr>

  <p><strong>Summary:</strong><br><?= nl2br(e($r['summary'] ?? '')) ?></p>
  <p><strong>Remarks:</strong><br><?= nl2br(e($r['remarks'] ?? '')) ?></p>

  <?php if (!empty($r['attachment_path'])): ?>
    <p><strong>Attachment:</strong>
      <a target="_blank" href="<?= e(ATTACH_URL . '/' . basename($r['attachment_path'])) ?>">Download</a>
    </p>
  <?php endif; ?>

  <?php if (!empty($r['signature_path'])): ?>
    <p><strong>Signature:</strong><br>
      <img style="max-width:420px;background:#fff;padding:6px;border-radius:6px"
           src="<?= e(SIGNATURE_URL . '/' . basename($r['signature_path'])) ?>">
    </p>
  <?php endif; ?>

  <?php if ($canReview): ?>
    <hr>
    <form method="post" class="form">
      <?= csrf_input(); ?>
      <div class="grid two">
        <label class="titlecase">Status
          <select name="status">
            <option value="pending"       <?= $st === 'pending' ? 'selected' : '' ?>>Pending</option>
            <option value="approved"      <?= $st === 'approved' ? 'selected' : '' ?>>Approved</option>
            <option value="needs_changes" <?= $st === 'needs_changes' ? 'selected' : '' ?>>Needs Changes</option>
          </select>
        </label>

        <label class="titlecase"><?= $isManager ? 'Manager Comment' : 'District Manager Comment' ?>
          <textarea name="manager_comment" rows="3"><?= e($r['manager_comment'] ?? '') ?></textarea>
        </label>
      </div>
      <button class="btn primary titlecase">Update</button>
    </form>
  <?php endif; ?>
</div>

>>>>>>> 37d1d03e21f7806a028237f4c9fce390fa63d02d
<?php include __DIR__ . '/footer.php'; ?>
