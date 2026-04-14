<?php
require_once __DIR__ . '/../init.php';
require_manager();

if (!headers_sent()) {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('Expires: 0');
}

// Robust ID
$editId = 0;
if (isset($_GET['id'])) {
  $editId = (int)$_GET['id'];
} else {
  $qs = (string)($_SERVER['QUERY_STRING'] ?? '');
  if ($qs !== '') {
    parse_str($qs, $qarr);
    if (isset($qarr['id'])) $editId = (int)$qarr['id'];
  }
}
if ($editId <= 0) { http_response_code(400); exit('Invalid ID'); }

// Fetch edit user (NO $u variable name!)
$editUser = null;
$sqlUser = "SELECT id, name, email, role, active, district_manager_id
            FROM users
            WHERE id = {$editId}
            LIMIT 1";
$resUser = $mysqli->query($sqlUser);
if (!$resUser) { http_response_code(500); exit('DB error'); }
$row = $resUser->fetch_assoc();
$resUser->free();
if (!$row) { http_response_code(404); exit('Not found'); }

$editUser = [
  'id' => (int)$row['id'],
  'name' => (string)$row['name'],
  'email' => (string)$row['email'],
  'role' => (string)$row['role'],
  'active' => (int)$row['active'],
  'district_manager_id' => ($row['district_manager_id'] === null ? null : (int)$row['district_manager_id']),
];

// District managers list
$districtManagers = [];
if ($res = $mysqli->query("SELECT id,name,email FROM users WHERE role='district_manager' AND active=1 ORDER BY name ASC")) {
  while ($r = $res->fetch_assoc()) $districtManagers[] = $r;
  $res->free();
}

$ok = false;
$errors = [];

// Save
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();

  $newName  = trim((string)post('name', ''));
  $newEmail = trim((string)post('email', ''));
  $newRole  = (string)post('role', 'employee');
  $newPass  = (string)post('password', '');
  $newDmId  = (int)post('district_manager_id', 0);

  if ($newName === '' || $newEmail === '') $errors[] = 'Name and Email required.';

  if (!$errors) {
    $dmFinal = ($newRole === 'employee' && $newDmId > 0) ? $newDmId : 0;

    if ($newPass !== '') {
      $hash = password_hash($newPass, PASSWORD_BCRYPT);

      $stmt = $mysqli->prepare("UPDATE users
        SET name=?,
            email=?,
            role=?,
            district_manager_id = CASE WHEN ?=0 THEN NULL ELSE ? END,
            password_hash=?
        WHERE id=?");
      if (!$stmt) {
        $errors[] = 'Failed to update (prepare).';
      } else {
        $stmt->bind_param('sssiisi', $newName, $newEmail, $newRole, $dmFinal, $dmFinal, $hash, $editId);
        if ($stmt->execute()) $ok = true; else $errors[] = 'Failed to update.';
        $stmt->close();
      }
    } else {
      $stmt = $mysqli->prepare("UPDATE users
        SET name=?,
            email=?,
            role=?,
            district_manager_id = CASE WHEN ?=0 THEN NULL ELSE ? END
        WHERE id=?");
      if (!$stmt) {
        $errors[] = 'Failed to update (prepare).';
      } else {
        $stmt->bind_param('sssiii', $newName, $newEmail, $newRole, $dmFinal, $dmFinal, $editId);
        if ($stmt->execute()) $ok = true; else $errors[] = 'Failed to update.';
        $stmt->close();
      }
    }

    if ($ok) {
      $editUser['name'] = $newName;
      $editUser['email'] = $newEmail;
      $editUser['role'] = $newRole;
      $editUser['district_manager_id'] = ($dmFinal === 0) ? null : (int)$dmFinal;
    }
  }
}

$title = 'Edit User';
include __DIR__ . '/header.php';
?>

<div class="card">
  <div class="flex between center">
    <h2>Edit User</h2>
    <div class="pill" style="font-size:12px;">
      URL id: <?= (int)$editId ?> · DB id: <?= (int)($editUser['id'] ?? 0) ?>
    </div>
  </div>

  <?php if ($ok): ?>
    <div class="alert success">Updated.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert">
      <?php foreach ($errors as $e) echo '<div>'.e($e).'</div>'; ?>
    </div>
  <?php endif; ?>

  <form method="post" class="form"><?php csrf_input(); ?>

    <div class="grid two">
      <label>Name
        <input name="name" value="<?= e($editUser['name']) ?>" required>
      </label>

      <label>Email
        <input type="email" name="email" value="<?= e($editUser['email']) ?>" required>
      </label>
    </div>

    <div class="grid two">
      <label>New Password
        <input name="password" placeholder="Leave blank to keep">
      </label>

      <label>Role
        <select name="role" id="roleSel">
          <option value="employee" <?= ($editUser['role'] === 'employee') ? 'selected' : '' ?>>Employee</option>
          <option value="district_manager" <?= ($editUser['role'] === 'district_manager') ? 'selected' : '' ?>>District Manager</option>
          <option value="manager" <?= ($editUser['role'] === 'manager') ? 'selected' : '' ?>>Manager</option>
        </select>
      </label>
    </div>

    <div class="grid two" id="dmWrap" style="display:none">
      <label>District Manager (for this Employee)
        <select name="district_manager_id" id="dmSel">
          <option value="0">-- None --</option>
          <?php foreach ($districtManagers as $dm): ?>
            <option value="<?= (int)$dm['id'] ?>"
              <?= ((int)($editUser['district_manager_id'] ?? 0) === (int)$dm['id']) ? 'selected' : '' ?>>
              <?= e($dm['name']) ?> (<?= e($dm['email']) ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="muted" style="padding-top:1.6rem">
        Only Employees can be assigned under a District Manager.
      </div>
    </div>

    <script>
      (function(){
        const roleSel = document.getElementById('roleSel');
        const dmWrap = document.getElementById('dmWrap');
        const dmSel  = document.getElementById('dmSel');

        function sync(){
          const isEmp = (roleSel.value === 'employee');
          dmWrap.style.display = isEmp ? '' : 'none';
          if (!isEmp) dmSel.value = '0';
        }
        roleSel.addEventListener('change', sync);
        sync();
      })();
    </script>

    <button class="btn primary">Save</button>
  </form>
</div>

<?php include __DIR__ . '/footer.php'; ?>
