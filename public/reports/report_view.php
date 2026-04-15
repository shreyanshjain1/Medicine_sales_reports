<?php
require_once __DIR__ . '/../../init.php';
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
  include __DIR__ . '/../header.php'; ?>
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
    <a class="btn" href="<?= e(url('reports/reports.php?showall=1')) ?>">Back to Reports</a>
  </div>
  <?php include __DIR__ . '/../footer.php';
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
    include __DIR__ . '/../header.php'; ?>
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
    <?php include __DIR__ . '/../footer.php';
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

  $oldStatus = (string)($r['status'] ?? 'pending');
  $stmt = $mysqli->prepare("UPDATE reports SET status=?, manager_comment=? WHERE id=?");
  if ($stmt) {
    $stmt->bind_param('ssi', $status, $comment, $id);
    $stmt->execute();
    $stmt->close();
  }

  record_report_status($id, $status, $oldStatus, $comment);
  log_audit('report_reviewed', 'report', $id, 'Report reviewed and moved from ' . $oldStatus . ' to ' . $status);

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
    url('reports/report_view.php?id=' . $id),
    (int)(user()['id'] ?? 0)
  );

  header('Location: ' . url('reports/report_view.php?id=' . $id));
  exit;
}

/* -------------------------------------------------------
   Render
-------------------------------------------------------- */
$title = "Report #{$id}";
include __DIR__ . '/../header.php';

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

<?php include __DIR__ . '/../footer.php'; ?>
