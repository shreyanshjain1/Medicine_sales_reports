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
$errors = []; $warnings = []; $fieldErrors = []; $ok = ''; $duplicateRows = [];
$edit = ['id'=>0,'doctor_name'=>'','email'=>'','city'=>'','hospital_name'=>''];

function doctors_master_rows(string $whereSql): array {
  global $mysqli, $nameCol, $emailCol, $placeCol, $hospitalCol;
  $sql = "SELECT id, {$nameCol} AS doctor_name, ".($emailCol?"{$emailCol}":"''")." AS email, ".($placeCol?"{$placeCol}":"''")." AS city, ".($hospitalCol?"{$hospitalCol}":"''")." AS hospital_name FROM doctors_masterlist {$whereSql} ORDER BY {$nameCol} ASC LIMIT 500";
  $rows = [];
  if ($res = $mysqli->query($sql)) { while ($row = $res->fetch_assoc()) $rows[] = $row; $res->free(); }
  return $rows;
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

if (getv('export','') === 'csv') {
  $rows = doctors_master_rows($where);
  $csv = [];
  foreach ($rows as $row) $csv[] = [$row['doctor_name'], $row['email'], $row['city'], $row['hospital_name']];
  csv_download('doctors-master.csv', ['Doctor Name','Email','City','Hospital / Clinic'], $csv);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();
  $action = (string)post('action','save');
  if ($action === 'delete') {
    $id = (int)post('id',0);
    if ($id > 0) {
      $stmt = $mysqli->prepare('UPDATE doctors_masterlist SET active=0 WHERE id=? LIMIT 1');
      if ($stmt) { $stmt->bind_param('i', $id); $stmt->execute(); $stmt->close(); log_audit('doctor_master_archived','doctor_master',$id,'Doctor archived from master list'); $ok='Doctor archived.'; }
    }
  } elseif ($action === 'import_csv') {
    $importRows = csv_rows_from_upload('import_file');
    if (!$importRows) {
      $errors[] = 'Please upload a valid CSV file with at least one data row.';
    } else {
      $created = 0; $skipped = 0;
      foreach ($importRows as $row) {
        $name = trim((string)($row['doctor name'] ?? $row['doctor_name'] ?? $row['name'] ?? ''));
        $email = trim((string)($row['email'] ?? ''));
        $city = trim((string)($row['city'] ?? $row['place'] ?? ''));
        $hospital = trim((string)($row['hospital / clinic'] ?? $row['hospital_name'] ?? $row['hospital'] ?? ''));
        if ($name === '') { $skipped++; continue; }
        $dupes = master_find_possible_duplicates('doctors_masterlist', $nameCol, $name, 0, array_filter([$emailCol=>$email, $placeCol=>$city, $hospitalCol=>$hospital]));
        if ($dupes) { $skipped++; continue; }
        $fields = [$nameCol]; $ph=['?']; $vals=[$name]; $types='s';
        foreach ([[$emailCol,$email],[$placeCol,$city],[$hospitalCol,$hospital]] as [$col,$val]) { if ($col) { $fields[]=$col; $ph[]='?'; $vals[]=$val; $types.='s'; } }
        if (isset($cols['active'])) { $fields[]='active'; $ph[]='1'; }
        $sql = 'INSERT INTO doctors_masterlist ('.implode(',',$fields).') VALUES ('.implode(',',$ph).')';
        $stmt = $mysqli->prepare($sql);
        if ($stmt) { $bind = [$types]; foreach ($vals as $k=>$v) $bind[]=&$vals[$k]; @call_user_func_array([$stmt,'bind_param'],$bind); if($stmt->execute()) $created++; $stmt->close(); }
      }
      $ok = "CSV import finished: {$created} added, {$skipped} skipped as blank or possible duplicates.";
    }
  } else {
    $id = (int)post('id',0);
    $forceSave = (int)post('force_save',0) === 1;
    $name = trim((string)post('doctor_name',''));
    $email = trim((string)post('email',''));
    $city = trim((string)post('city',''));
    $hospital = trim((string)post('hospital_name',''));
    $edit = ['id'=>$id,'doctor_name'=>$name,'email'=>$email,'city'=>$city,'hospital_name'=>$hospital];
    if ($name === '') { $errors[] = 'Doctor name is required.'; $fieldErrors['doctor_name'] = 'Doctor name is required.'; }
    $duplicateRows = master_find_possible_duplicates('doctors_masterlist', $nameCol, $name, $id, array_filter([$emailCol=>$email, $placeCol=>$city, $hospitalCol=>$hospital]));
    if (!$errors && $duplicateRows && !$forceSave) {
      $warnings[] = 'Possible duplicate doctors were found. Review them below before saving, or click Save Anyway.';
    }
    if (!$errors && (!$duplicateRows || $forceSave)) {
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
      $edit = ['id'=>0,'doctor_name'=>'','email'=>'','city'=>'','hospital_name'=>''];
      $duplicateRows = [];
    }
  }
}

if ($editId > 0 && !$edit['id']) {
  $sql = "SELECT id, {$nameCol} AS doctor_name, ".($emailCol?"{$emailCol}":"''")." AS email, ".($placeCol?"{$placeCol}":"''")." AS city, ".($hospitalCol?"{$hospitalCol}":"''")." AS hospital_name FROM doctors_masterlist WHERE id=".(int)$editId." LIMIT 1";
  if ($res = $mysqli->query($sql)) { if ($row = $res->fetch_assoc()) $edit = $row; $res->free(); }
}

$rows = doctors_master_rows($where);
include __DIR__.'/../header.php';
?>
<div class="crm-hero"><div><h2>Doctors Master</h2><div class="subtle">Maintain the physician directory used across tasks and reports.</div></div></div>
<div class="master-tools toolbar-stack">
  <div class="card subtle-card">
    <div class="section-head"><h3>Bulk tools</h3><span class="pill neutral">CSV</span></div>
    <div class="master-tools">
      <form method="post" enctype="multipart/form-data">
        <?php csrf_input(); ?>
        <input type="hidden" name="action" value="import_csv">
        <input class="file-input" type="file" name="import_file" accept=".csv" required>
        <button class="btn" type="submit">Import CSV</button>
        <span class="mini-note">Headers: doctor name, email, city, hospital / clinic</span>
      </form>
      <form method="get" action="<?= url('masters/doctors_master.php') ?>">
        <input type="hidden" name="q" value="<?= e($q) ?>">
        <input type="hidden" name="export" value="csv">
        <button class="btn success" type="submit">Export CSV</button>
      </form>
    </div>
  </div>
</div>
<div class="grid two-panels masters-grid">
  <div class="card">
    <div class="section-head"><h3><?= $edit['id'] ? 'Edit doctor' : 'Add doctor' ?></h3><span class="pill neutral">Master Data</span></div>
    <?php form_messages($errors, $warnings, $ok); ?>
    <?php if($duplicateRows): ?>
      <div class="alert warning form-alert"><strong>Possible duplicates found:</strong><ul class="duplicate-list"><?php foreach($duplicateRows as $dup): ?><li><?= e(($dup[$nameCol] ?? $dup['doctor_name'] ?? 'Unknown').' • '.($dup[$placeCol] ?? $dup['city'] ?? '').' • '.($dup[$hospitalCol] ?? $dup['hospital_name'] ?? '')) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>
    <form method="post" class="form crm-form">
      <?php csrf_input(); ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <input type="hidden" name="force_save" value="<?= $duplicateRows ? '1' : '0' ?>">
      <?php render_text_input('Doctor Name', 'doctor_name', (string)$edit['doctor_name'], ['type'=>'text','required'=>true], $fieldErrors); ?>
      <?php render_text_input('Email', 'email', (string)$edit['email'], ['type'=>'email'], $fieldErrors); ?>
      <?php render_text_input('City', 'city', (string)$edit['city'], [], $fieldErrors); ?>
      <?php render_text_input('Hospital / Clinic', 'hospital_name', (string)$edit['hospital_name'], [], $fieldErrors); ?>
      <div class="actions-inline form-actions">
        <button class="btn primary" type="submit"><?= $duplicateRows ? 'Save Anyway' : ($edit['id'] ? 'Save Changes' : 'Add Doctor') ?></button>
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
          <td><div class="table-actions"><a class="btn tiny" href="<?= url('masters/doctors_master.php?edit='.(int)$row['id']) ?>">Edit</a><form method="post" onsubmit="return confirm('Archive this doctor?');"><?php csrf_input(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn tiny danger" type="submit">Archive</button></form></div></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>
</div>
<?php include __DIR__.'/../footer.php'; ?>