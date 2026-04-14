<?php
require_once __DIR__.'/../init.php';
require_manager();
$title = 'Hospitals Master';
$errors = []; $ok=''; $q = trim((string)getv('q','')); $editId=(int)getv('edit',0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_validate();
  $action = (string)post('action','save');
  if ($action === 'delete') {
    $id=(int)post('id',0);
    $stmt=$mysqli->prepare('UPDATE hospitals_master SET active=0 WHERE id=? LIMIT 1');
    if($stmt){ $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close(); log_audit('hospital_master_archived','hospital_master',$id,'Hospital archived'); $ok='Hospital archived.'; }
  } else {
    $id=(int)post('id',0); $name=trim((string)post('name','')); $city=trim((string)post('city','')); $address=trim((string)post('address','')); $notes=trim((string)post('notes',''));
    if($name==='') $errors[]='Hospital or clinic name is required.';
    if(!$errors){
      if($id>0){ $stmt=$mysqli->prepare('UPDATE hospitals_master SET name=?,city=?,address=?,notes=?,active=1 WHERE id=? LIMIT 1'); if($stmt){ $stmt->bind_param('ssssi',$name,$city,$address,$notes,$id); $stmt->execute(); $stmt->close(); log_audit('hospital_master_updated','hospital_master',$id,'Hospital updated'); $ok='Hospital updated.'; } }
      else { $stmt=$mysqli->prepare('INSERT INTO hospitals_master (name,city,address,notes,active) VALUES (?,?,?,?,1)'); if($stmt){ $stmt->bind_param('ssss',$name,$city,$address,$notes); $stmt->execute(); $new=(int)$stmt->insert_id; $stmt->close(); log_audit('hospital_master_created','hospital_master',$new,'Hospital created'); $ok='Hospital added.'; } }
      $editId=0;
    }
  }
}
$edit=['id'=>0,'name'=>'','city'=>'','address'=>'','notes'=>''];
if($editId>0){ $stmt=$mysqli->prepare('SELECT * FROM hospitals_master WHERE id=? LIMIT 1'); if($stmt){ $stmt->bind_param('i',$editId); $stmt->execute(); $edit=$stmt->get_result()->fetch_assoc() ?: $edit; $stmt->close(); } }
$where='WHERE active=1'; if($q!==''){ $safe=$mysqli->real_escape_string('%'.$q.'%'); $where .= " AND (name LIKE '{$safe}' OR city LIKE '{$safe}' OR address LIKE '{$safe}')"; }
$rows=[]; if($res=$mysqli->query("SELECT * FROM hospitals_master {$where} ORDER BY name ASC LIMIT 300")){ while($row=$res->fetch_assoc()) $rows[]=$row; $res->free(); }
include __DIR__.'/header.php';
?>
<div class="crm-hero"><div><h2>Hospitals & Clinics Master</h2><div class="subtle">Create a clean location directory for reports, filters, and coverage tracking.</div></div></div>
<div class="grid two-panels masters-grid">
  <div class="card">
    <div class="section-head"><h3><?= $edit['id'] ? 'Edit hospital / clinic' : 'Add hospital / clinic' ?></h3><span class="pill neutral">Master Data</span></div>
    <?php if($ok): ?><div class="alert success"><?= e($ok) ?></div><?php endif; ?>
    <?php if($errors): ?><div class="alert danger"><?php foreach($errors as $e): ?><div><?= e($e) ?></div><?php endforeach; ?></div><?php endif; ?>
    <form method="post" class="form"><?php csrf_input(); ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <label>Name<input name="name" value="<?= e($edit['name']) ?>" required></label>
      <label>City<input name="city" value="<?= e($edit['city']) ?>"></label>
      <label>Address<input name="address" value="<?= e($edit['address']) ?>"></label>
      <label>Notes<textarea name="notes" rows="3"><?= e($edit['notes']) ?></textarea></label>
      <div class="actions-inline"><button class="btn primary" type="submit"><?= $edit['id'] ? 'Save Changes' : 'Add Hospital' ?></button><?php if($edit['id']): ?><a class="btn" href="hospitals_master.php">Cancel</a><?php endif; ?></div>
    </form>
  </div>
  <div class="card">
    <form method="get" class="toolbar compact-toolbar"><input type="text" name="q" value="<?= e($q) ?>" placeholder="Search hospital, city, address"><button class="btn" type="submit">Filter</button><?php if($q !== ''): ?><a class="btn" href="hospitals_master.php">Reset</a><?php endif; ?></form>
    <div class="table-responsive"><table><thead><tr><th>Name</th><th>City</th><th>Address</th><th style="width:160px">Actions</th></tr></thead><tbody>
    <?php if(!$rows): ?><tr><td colspan="4" class="muted">No hospitals or clinics found.</td></tr><?php endif; ?>
    <?php foreach($rows as $row): ?><tr><td><strong><?= e($row['name']) ?></strong></td><td><?= e($row['city']) ?></td><td><?= e($row['address']) ?></td><td><div class="table-actions"><a class="btn tiny" href="hospitals_master.php?edit=<?= (int)$row['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Archive this hospital?');"><?php csrf_input(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn tiny danger" type="submit">Archive</button></form></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php include __DIR__.'/footer.php'; ?>