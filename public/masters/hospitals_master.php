<?php
require_once __DIR__.'/../../init.php';
require_manager();
$title = 'Hospitals Master';
$errors = []; $warnings=[]; $fieldErrors=[]; $ok=''; $q = trim((string)getv('q','')); $editId=(int)getv('edit',0);
$edit=['id'=>0,'name'=>'','city'=>'','address'=>'','notes'=>''];
function hospitals_master_rows(string $where): array {
  global $mysqli; $rows=[]; if($res=$mysqli->query("SELECT * FROM hospitals_master {$where} ORDER BY name ASC LIMIT 500")){ while($row=$res->fetch_assoc()) $rows[]=$row; $res->free(); } return $rows;
}
$where='WHERE active=1'; if($q!==''){ $safe=$mysqli->real_escape_string('%'.$q.'%'); $where .= " AND (name LIKE '{$safe}' OR city LIKE '{$safe}' OR address LIKE '{$safe}')"; }
if(getv('export','')==='csv'){ $rows=hospitals_master_rows($where); $csv=[]; foreach($rows as $row) $csv[]=[(string)$row['name'],(string)$row['city'],(string)$row['address'],(string)$row['notes']]; csv_download('hospitals-master.csv',['Name','City','Address','Notes'],$csv); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();
  $action = (string)post('action','save');
  if ($action === 'delete') {
    $id=(int)post('id',0); $stmt=$mysqli->prepare('UPDATE hospitals_master SET active=0 WHERE id=? LIMIT 1');
    if($stmt){ $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close(); log_audit('hospital_master_archived','hospital_master',$id,'Hospital archived'); $ok='Hospital archived.'; }
  } elseif ($action === 'import_csv') {
    $importRows = csv_rows_from_upload('import_file');
    if(!$importRows) $errors[]='Please upload a valid CSV file with at least one data row.';
    else {
      $created=0; $skipped=0;
      foreach($importRows as $row){
        $name=trim((string)($row['name'] ?? $row['hospital'] ?? $row['hospital / clinic'] ?? ''));
        $city=trim((string)($row['city'] ?? ''));
        $address=trim((string)($row['address'] ?? ''));
        $notes=trim((string)($row['notes'] ?? ''));
        if($name===''){ $skipped++; continue; }
        if(master_find_possible_duplicates('hospitals_master','name',$name,0,['city'=>$city,'address'=>$address])){ $skipped++; continue; }
        $stmt=$mysqli->prepare('INSERT INTO hospitals_master (name,city,address,notes,active) VALUES (?,?,?,?,1)'); if($stmt){ $stmt->bind_param('ssss',$name,$city,$address,$notes); if($stmt->execute()) $created++; $stmt->close(); }
      }
      $ok = "CSV import finished: {$created} added, {$skipped} skipped as blank or possible duplicates.";
    }
  } else {
    $id=(int)post('id',0); $forceSave=(int)post('force_save',0)===1; $name=trim((string)post('name','')); $city=trim((string)post('city','')); $address=trim((string)post('address','')); $notes=trim((string)post('notes',''));
    $edit=['id'=>$id,'name'=>$name,'city'=>$city,'address'=>$address,'notes'=>$notes];
    if($name==='') { $errors[]='Hospital or clinic name is required.'; $fieldErrors['name']='Hospital or clinic name is required.'; }
    $duplicateRows = master_find_possible_duplicates('hospitals_master','name',$name,$id,['city'=>$city,'address'=>$address]);
    if(!$errors && $duplicateRows && !$forceSave) $warnings[]='Possible duplicate hospitals or clinics were found. Review them below before saving, or click Save Anyway.';
    if(!$errors && (!$duplicateRows || $forceSave)){
      if($id>0){ $stmt=$mysqli->prepare('UPDATE hospitals_master SET name=?,city=?,address=?,notes=?,active=1 WHERE id=? LIMIT 1'); if($stmt){ $stmt->bind_param('ssssi',$name,$city,$address,$notes,$id); $stmt->execute(); $stmt->close(); log_audit('hospital_master_updated','hospital_master',$id,'Hospital updated'); $ok='Hospital updated.'; } }
      else { $stmt=$mysqli->prepare('INSERT INTO hospitals_master (name,city,address,notes,active) VALUES (?,?,?,?,1)'); if($stmt){ $stmt->bind_param('ssss',$name,$city,$address,$notes); $stmt->execute(); $new=(int)$stmt->insert_id; $stmt->close(); log_audit('hospital_master_created','hospital_master',$new,'Hospital created'); $ok='Hospital added.'; } }
      $editId=0; $edit=['id'=>0,'name'=>'','city'=>'','address'=>'','notes'=>'']; $duplicateRows=[];
    }
  }
}
$duplicateRows = $duplicateRows ?? [];
if($editId>0 && !$edit['id']){ $stmt=$mysqli->prepare('SELECT * FROM hospitals_master WHERE id=? LIMIT 1'); if($stmt){ $stmt->bind_param('i',$editId); $stmt->execute(); $edit=$stmt->get_result()->fetch_assoc() ?: $edit; $stmt->close(); } }
$rows=hospitals_master_rows($where);
include __DIR__.'/../header.php';
?>
<div class="crm-hero"><div><h2>Hospitals & Clinics Master</h2><div class="subtle">Create a clean location directory for reports, filters, and coverage tracking.</div></div></div>
<div class="master-tools toolbar-stack"><div class="card subtle-card"><div class="section-head"><h3>Bulk tools</h3><span class="pill neutral">CSV</span></div><div class="master-tools"><form method="post" enctype="multipart/form-data"><?php csrf_input(); ?><input type="hidden" name="action" value="import_csv"><input class="file-input" type="file" name="import_file" accept=".csv" required><button class="btn" type="submit">Import CSV</button><span class="mini-note">Headers: name, city, address, notes</span></form><form method="get" action="<?= url('masters/hospitals_master.php') ?>"><input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="export" value="csv"><button class="btn success" type="submit">Export CSV</button></form></div></div></div>
<div class="grid two-panels masters-grid">
  <div class="card">
    <div class="section-head"><h3><?= $edit['id'] ? 'Edit hospital / clinic' : 'Add hospital / clinic' ?></h3><span class="pill neutral">Master Data</span></div>
    <?php form_messages($errors, $warnings, $ok); ?>
    <?php if($duplicateRows): ?><div class="alert warning form-alert"><strong>Possible duplicates found:</strong><ul class="duplicate-list"><?php foreach($duplicateRows as $dup): ?><li><?= e(($dup['name'] ?? '').' • '.($dup['city'] ?? '').' • '.($dup['address'] ?? '')) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="form crm-form"><?php csrf_input(); ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="force_save" value="<?= $duplicateRows ? '1' : '0' ?>">
      <?php render_text_input('Name', 'name', (string)$edit['name'], ['required'=>true], $fieldErrors); ?>
      <?php render_text_input('City', 'city', (string)$edit['city'], [], $fieldErrors); ?>
      <?php render_text_input('Address', 'address', (string)$edit['address'], [], $fieldErrors); ?>
      <?php render_textarea_input('Notes', 'notes', (string)$edit['notes'], ['rows'=>3], $fieldErrors); ?>
      <div class="actions-inline form-actions"><button class="btn primary" type="submit"><?= $duplicateRows ? 'Save Anyway' : ($edit['id'] ? 'Save Changes' : 'Add Hospital') ?></button><?php if($edit['id']): ?><a class="btn" href="<?= url('masters/hospitals_master.php') ?>">Cancel</a><?php endif; ?></div>
    </form>
  </div>
  <div class="card">
    <form method="get" class="toolbar compact-toolbar"><input type="text" name="q" value="<?= e($q) ?>" placeholder="Search hospital, city, address"><button class="btn" type="submit">Filter</button><?php if($q !== ''): ?><a class="btn" href="<?= url('masters/hospitals_master.php') ?>">Reset</a><?php endif; ?></form>
    <div class="table-responsive"><table><thead><tr><th>Name</th><th>City</th><th>Address</th><th style="width:160px">Actions</th></tr></thead><tbody>
    <?php if(!$rows): ?><tr><td colspan="4" class="muted">No hospitals or clinics found.</td></tr><?php endif; ?>
    <?php foreach($rows as $row): ?><tr><td><strong><?= e($row['name']) ?></strong></td><td><?= e($row['city']) ?></td><td><?= e($row['address']) ?></td><td><div class="table-actions"><a class="btn tiny" href="<?= url('masters/hospitals_master.php?edit='.(int)$row['id']) ?>">Edit</a><form method="post" onsubmit="return confirm('Archive this hospital?');"><?php csrf_input(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn tiny danger" type="submit">Archive</button></form></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php include __DIR__.'/../footer.php'; ?>