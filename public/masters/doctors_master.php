<?php
require_once __DIR__.'/../../init.php';
require_manager();
$title = 'Doctors Master';
$cols = array_flip(db_table_columns('doctors_masterlist'));
if (!$cols || (!isset($cols['dr_name']) && !isset($cols['doctor_name']))) {
  http_response_code(500);
  exit('doctors_masterlist table is missing a usable doctor name column.');
}
$nameCol = isset($cols['dr_name']) ? 'dr_name' : 'doctor_name';
$emailCol = isset($cols['email']) ? 'email' : null;
$placeCol = isset($cols['place']) ? 'place' : null;
$hospitalCol = isset($cols['hospital_address']) ? 'hospital_address' : null;
if (!isset($cols['active'])) { @ $mysqli->query("ALTER TABLE doctors_masterlist ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1"); $cols['active']=true; }
$q = trim((string)getv('q',''));
$editId = (int)getv('edit', 0);
$errors = [];
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();
  $action = (string)post('action','save');
  if ($action === 'delete') {
    $id = (int)post('id',0);
    if ($id > 0) {
      $stmt = $mysqli->prepare('UPDATE doctors_masterlist SET active=0 WHERE id=? LIMIT 1');
      if ($stmt) { $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close(); log_audit('doctor_master_archived','doctor_master',$id,'Doctor archived from master list'); $ok='Doctor archived.'; }
    }
  } else {
    $id = (int)post('id',0);
    $name = trim((string)post('doctor_name',''));
    $email = trim((string)post('email',''));
    $city = trim((string)post('city',''));
    $hospital = trim((string)post('hospital_name',''));
    if ($name === '') $errors[] = 'Doctor name is required.';
    if (!$errors) {
      if ($id > 0) {
        $parts = ["{$nameCol}=?", 'active=1']; $vals = [$name]; $types='s';
        if ($emailCol) { $parts[] = "{$emailCol}=?"; $vals[] = $email; $types.='s'; }
        if ($placeCol) { $parts[] = "{$placeCol}=?"; $vals[] = $city; $types.='s'; }
        if ($hospitalCol) { $parts[] = "{$hospitalCol}=?"; $vals[] = $hospital; $types.='s'; }
        $types.='i'; $vals[] = $id;
        $sql = 'UPDATE doctors_masterlist SET '.implode(',', $parts).' WHERE id=? LIMIT 1';
        $stmt = $mysqli->prepare($sql);
        if ($stmt) { $bind = [$types]; foreach ($vals as $k=>$v) $bind[]=&$vals[$k]; @call_user_func_array([$stmt,'bind_param'],$bind); $stmt->execute(); $stmt->close(); log_audit('doctor_master_updated','doctor_master',$id,'Doctor updated'); $ok='Doctor updated.'; }
      } else {
        $fields = [$nameCol]; $ph=['?']; $vals=[$name]; $types='s';
        foreach ([[$emailCol,$email],[$placeCol,$city],[$hospitalCol,$hospital]] as [$col,$val]) { if ($col) { $fields[]=$col; $ph[]='?'; $vals[]=$val; $types.='s'; } }
        if (isset($cols['active'])) { $fields[]='active'; $ph[]='1'; }
        $sql = 'INSERT INTO doctors_masterlist ('.implode(',',$fields).') VALUES ('.implode(',',$ph).')';
        $stmt = $mysqli->prepare($sql);
        if ($stmt) { $bind = [$types]; foreach ($vals as $k=>$v) $bind[]=&$vals[$k]; @call_user_func_array([$stmt,'bind_param'],$bind); $stmt->execute(); $newId=(int)$stmt->insert_id; $stmt->close(); log_audit('doctor_master_created','doctor_master',$newId,'Doctor added to master list'); $ok='Doctor added.'; }
      }
      $editId = 0;
    }
  }
}
$edit = ['id'=>0,'doctor_name'=>'','email'=>'','city'=>'','hospital_name'=>''];
if ($editId > 0) {
  $sql = "SELECT id, {$nameCol} AS doctor_name, ".($emailCol?"{$emailCol}":"''")." AS email, ".($placeCol?"{$placeCol}":"''")." AS city, ".($hospitalCol?"{$hospitalCol}":"''")." AS hospital_name FROM doctors_masterlist WHERE id=".(int)$editId." LIMIT 1";
  if ($res = $mysqli->query($sql)) { if ($row = $res->fetch_assoc()) $edit = $row; $res->free(); }
}
$where = isset($cols['active']) ? 'WHERE active=1' : 'WHERE 1';
if ($q !== '') {
  $safe = $mysqli->real_escape_string('%'.$q.'%');
  $where .= " AND ({$nameCol} LIKE '{$safe}'";
  if ($emailCol) $where .= " OR {$emailCol} LIKE '{$safe}'";
  if ($placeCol) $where .= " OR {$placeCol} LIKE '{$safe}'";
  if ($hospitalCol) $where .= " OR {$hospitalCol} LIKE '{$safe}'";
  $where .= ')';
}
$sql = "SELECT id, {$nameCol} AS doctor_name, ".($emailCol?"{$emailCol}":"''")." AS email, ".($placeCol?"{$placeCol}":"''")." AS city, ".($hospitalCol?"{$hospitalCol}":"''")." AS hospital_name FROM doctors_masterlist {$where} ORDER BY {$nameCol} ASC LIMIT 300";
$rows = [];
if ($res = $mysqli->query($sql)) { while ($row = $res->fetch_assoc()) $rows[] = $row; $res->free(); }
include __DIR__.'/../header.php';
?>
<div class="crm-hero"><div><h2>Doctors Master</h2><div class="subtle">Maintain the physician directory used across tasks and reports.</div></div></div>
<div class="grid two-panels masters-grid">
  <div class="card">
    <div class="section-head"><h3><?= $edit['id'] ? 'Edit doctor' : 'Add doctor' ?></h3><span class="pill neutral">Master Data</span></div>
    <?php if($ok): ?><div class="alert success"><?= e($ok) ?></div><?php endif; ?>
    <?php if($errors): ?><div class="alert danger"><?php foreach($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?></div><?php endif; ?>
    <form method="post" class="form">
      <?php csrf_input(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <label>Doctor Name<input type="text" name="doctor_name" value="<?= e($edit['doctor_name']) ?>" required></label>
      <label>Email<input type="email" name="email" value="<?= e($edit['email']) ?>"></label>
      <label>City<input type="text" name="city" value="<?= e($edit['city']) ?>"></label>
      <label>Hospital / Clinic<input type="text" name="hospital_name" value="<?= e($edit['hospital_name']) ?>"></label>
      <div class="actions-inline">
        <button class="btn primary" type="submit"><?= $edit['id'] ? 'Save Changes' : 'Add Doctor' ?></button>
        <?php if($edit['id']): ?><a class="btn" href="<?= url('masters/doctors_master.php') ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
  <div class="card">
    <form method="get" class="toolbar compact-toolbar">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search doctor, email, city, hospital">
      <button class="btn" type="submit">Filter</button>
      <?php if($q !== ''): ?><a class="btn" href="<?= url('masters/doctors_master.php') ?>">Reset</a><?php endif; ?>
    </form>
    <div class="table-responsive"><table>
      <thead><tr><th>Doctor</th><th>City</th><th>Hospital</th><th>Email</th><th style="width:160px">Actions</th></tr></thead>
      <tbody>
        <?php if(!$rows): ?><tr><td colspan="5" class="muted">No doctors found.</td></tr><?php endif; ?>
        <?php foreach($rows as $row): ?>
        <tr>
          <td><strong><?= e($row['doctor_name']) ?></strong></td>
          <td><?= e($row['city']) ?></td>
          <td><?= e($row['hospital_name']) ?></td>
          <td><?= e($row['email']) ?></td>
          <td>
            <div class="table-actions">
              <a class="btn tiny" href="doctors_master.php?edit=<?= (int)$row['id'] ?>">Edit</a>
              <form method="post" onsubmit="return confirm('Archive this doctor?');">
                <?php csrf_input(); ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                <button class="btn tiny danger" type="submit">Archive</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php include __DIR__.'/../footer.php'; ?>
