<?php
require_once __DIR__.'/../init.php'; require_manager();
<<<<<<< HEAD
$flash=''; $tempPassword='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $action = trim((string)post('action',''));
  $id = (int)post('id',0);
  if($id > 0 && $id !== (int)user()['id']){
    if($action==='toggle'){
      $stmt=$mysqli->prepare('UPDATE users SET active=1-active WHERE id=?'); $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close();
      log_audit('user_toggled', 'user', $id, 'Account active flag toggled');
      $flash='User status updated.';
    } elseif($action==='reset_password'){
      $tempPassword = 'Temp' . random_int(100000,999999) . '!';
      $hash = password_hash($tempPassword, PASSWORD_BCRYPT);
      $stmt=$mysqli->prepare('UPDATE users SET password_hash=? WHERE id=?'); $stmt->bind_param('si',$hash,$id); $stmt->execute(); $stmt->close();
      log_audit('user_password_reset', 'user', $id, 'Temporary password issued by manager');
      $flash='Temporary password generated. Share it securely with the user.';
    }
  }
}
$users=$mysqli->query("SELECT u.id,u.name,u.email,u.role,u.active,u.created_at, dm.name AS district_manager FROM users u LEFT JOIN users dm ON dm.id = u.district_manager_id ORDER BY FIELD(u.role,'manager','district_manager','employee'), u.name ASC");
$title='Users'; include __DIR__.'/header.php';
?>
<div class="crm-hero"><div><h2>User Management</h2><div class="subtle">Administer rep access, role assignments, and temporary password resets.</div></div><a class="btn primary" href="user_add.php">Add User</a></div>
<div class="card">
  <?php if($flash): ?><div class="alert success"><?= e($flash) ?><?php if($tempPassword): ?><br><strong>Temporary Password:</strong> <?= e($tempPassword) ?><?php endif; ?></div><?php endif; ?>
  <div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>District Manager</th><th>Status</th><th>Created</th><th>Actions</th></tr></thead><tbody>
    <?php while($u=$users->fetch_assoc()): ?>
      <tr>
        <td><?= e($u['name']) ?></td>
        <td><?= e($u['email']) ?></td>
        <td><?= e($u['role']) ?></td>
        <td><?= e(($u['role']==='employee') ? ($u['district_manager'] ?: '—') : '—') ?></td>
        <td><span class="badge <?= $u['active']?'approved':'needs_changes' ?>"><?= $u['active']?'Active':'Disabled' ?></span></td>
        <td><?= e((string)$u['created_at']) ?></td>
        <td>
          <div class="actions-inline">
            <a class="btn tiny" href="user_edit.php?id=<?= (int)$u['id'] ?>">Edit</a>
            <?php if((int)$u['id'] !== (int)user()['id']): ?>
              <form method="post" style="margin:0"><?php csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="toggle"><button class="btn tiny" type="submit">Toggle</button></form>
              <form method="post" style="margin:0" onsubmit="return confirm('Generate a new temporary password for this user?');"><?php csrf_input(); ?><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><input type="hidden" name="action" value="reset_password"><button class="btn tiny danger" type="submit">Reset Password</button></form>
            <?php endif; ?>
          </div>
        </td>
=======
$action = getv('action','');
if($action==='toggle' && isset($_GET['id'])){
  $id=(int)$_GET['id']; $mysqli->query("UPDATE users SET active=1-active WHERE id=$id AND id<>".(int)user()['id']);
  header('Location: '.url('admin_users.php')); exit;
}
$users=$mysqli->query("SELECT u.id,u.name,u.email,u.role,u.active,u.created_at, dm.name AS district_manager
  FROM users u
  LEFT JOIN users dm ON dm.id = u.district_manager_id
  ORDER BY FIELD(u.role,'manager','district_manager','employee'), u.name ASC");
$title='Users'; include __DIR__.'/header.php';
?>
<div class="card">
  <div class="flex-between"><h2>Users</h2><a class="btn primary" href="user_add.php">Add User</a></div>
  <div class="table-wrap"><table class="table"><thead><tr><th>Name</th><th>Email</th><th>Role</th><th>District</th><th>Status</th><th>Actions</th></tr></thead><tbody>
    <?php while($u=$users->fetch_assoc()): ?>
      <tr>
        <td><?= e($u['name']) ?></td><td><?= e($u['email']) ?></td><td><?= e($u['role']) ?></td>
        <td><?= e(($u['role']==='employee') ? ($u['district_manager'] ?: '—') : '—') ?></td>
        <td><?= $u['active']?'Active':'Disabled' ?></td>
        <td><a class="btn tiny" href="user_edit.php?id=<?= (int)$u['id'] ?>">Edit</a> <?php if($u['id']!==user()['id']): ?><a class="btn tiny" href="?action=toggle&id=<?= (int)$u['id'] ?>" data-confirm="Toggle this user?">Toggle</a><?php endif; ?></td>
>>>>>>> 37d1d03e21f7806a028237f4c9fce390fa63d02d
      </tr>
    <?php endwhile; ?>
  </tbody></table></div>
</div>
<<<<<<< HEAD
<?php include __DIR__.'/footer.php'; ?>
=======
<?php include __DIR__.'/footer.php'; ?>
>>>>>>> 37d1d03e21f7806a028237f4c9fce390fa63d02d
