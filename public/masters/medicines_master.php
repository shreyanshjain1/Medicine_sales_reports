<?php
require_once __DIR__.'/../../init.php';
require_manager();
$title = 'Medicines Master';
$errors=[]; $fieldErrors=[]; $ok=''; $q=trim((string)getv('q','')); $editId=(int)getv('edit',0);
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_validate();
  $action=(string)post('action','save');
  if($action==='delete'){
    $id=(int)post('id',0); $stmt=$mysqli->prepare('UPDATE medicines_master SET active=0 WHERE id=? LIMIT 1'); if($stmt){ $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close(); log_audit('medicine_master_archived','medicine_master',$id,'Medicine archived'); $ok='Medicine archived.'; }
  } else {
    $id=(int)post('id',0); $name=trim((string)post('name','')); $category=trim((string)post('category','')); $notes=trim((string)post('notes',''));
    if($name==='') { $errors[]='Medicine name is required.'; $fieldErrors['name']='Medicine name is required.'; }
    if(!$errors){ if($id>0){ $stmt=$mysqli->prepare('UPDATE medicines_master SET name=?,category=?,notes=?,active=1 WHERE id=? LIMIT 1'); if($stmt){ $stmt->bind_param('sssi',$name,$category,$notes,$id); $stmt->execute(); $stmt->close(); log_audit('medicine_master_updated','medicine_master',$id,'Medicine updated'); $ok='Medicine updated.'; } } else { $stmt=$mysqli->prepare('INSERT INTO medicines_master (name,category,notes,active) VALUES (?,?,?,1)'); if($stmt){ $stmt->bind_param('sss',$name,$category,$notes); $stmt->execute(); $new=(int)$stmt->insert_id; $stmt->close(); log_audit('medicine_master_created','medicine_master',$new,'Medicine created'); $ok='Medicine added.'; } } $editId=0; }
  }
}
$edit=['id'=>0,'name'=>'','category'=>'','notes'=>''];
if($editId>0){ $stmt=$mysqli->prepare('SELECT * FROM medicines_master WHERE id=? LIMIT 1'); if($stmt){ $stmt->bind_param('i',$editId); $stmt->execute(); $edit=$stmt->get_result()->fetch_assoc() ?: $edit; $stmt->close(); } }
$where='WHERE active=1'; if($q!==''){ $safe=$mysqli->real_escape_string('%'.$q.'%'); $where .= " AND (name LIKE '{$safe}' OR category LIKE '{$safe}' OR notes LIKE '{$safe}')"; }
$rows=[]; if($res=$mysqli->query("SELECT * FROM medicines_master {$where} ORDER BY name ASC LIMIT 300")){ while($row=$res->fetch_assoc()) $rows[]=$row; $res->free(); }
include __DIR__.'/../header.php';
?>
<div class="crm-hero"><div><h2>Medicines Master</h2><div class="subtle">Standardize product naming for cleaner reporting, exports, and dashboard trends.</div></div></div>
<div class="grid two-panels masters-grid">
  <div class="card">
    <div class="section-head"><h3><?= $edit['id'] ? 'Edit medicine' : 'Add medicine' ?></h3><span class="pill neutral">Master Data</span></div>
    <?php form_messages($errors, [], $ok); ?>
    <form method="post" class="form crm-form"><?php csrf_input(); ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
      <?php render_text_input('Name', 'name', (string)$edit['name'], ['required'=>true], $fieldErrors); ?>
      <?php render_text_input('Category', 'category', (string)$edit['category'], ['placeholder'=>'Example: Antibiotic'], $fieldErrors); ?>
      <?php render_textarea_input('Notes', 'notes', (string)$edit['notes'], ['rows'=>3], $fieldErrors); ?>
      <div class="actions-inline form-actions"><button class="btn primary" type="submit"><?= $edit['id'] ? 'Save Changes' : 'Add Medicine' ?></button><?php if($edit['id']): ?><a class="btn" href="<?= url('masters/medicines_master.php') ?>">Cancel</a><?php endif; ?></div>
    </form>
  </div>
  <div class="card">
    <form method="get" class="toolbar compact-toolbar"><input type="text" name="q" value="<?= e($q) ?>" placeholder="Search medicine, category"><button class="btn" type="submit">Filter</button><?php if($q !== ''): ?><a class="btn" href="<?= url('masters/medicines_master.php') ?>">Reset</a><?php endif; ?></form>
    <div class="table-responsive"><table><thead><tr><th>Name</th><th>Category</th><th>Notes</th><th style="width:160px">Actions</th></tr></thead><tbody>
    <?php if(!$rows): ?><tr><td colspan="4" class="muted">No medicines found.</td></tr><?php endif; ?>
    <?php foreach($rows as $row): ?><tr><td><strong><?= e($row['name']) ?></strong></td><td><?= e($row['category']) ?></td><td class="muted"><?= e($row['notes']) ?></td><td><div class="table-actions"><a class="btn tiny" href="medicines_master.php?edit=<?= (int)$row['id'] ?>">Edit</a><form method="post" onsubmit="return confirm('Archive this medicine?');"><?php csrf_input(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn tiny danger" type="submit">Archive</button></form></div></td></tr><?php endforeach; ?>
    </tbody></table></div>
  </div>
</div>
<?php include __DIR__.'/../footer.php'; ?>