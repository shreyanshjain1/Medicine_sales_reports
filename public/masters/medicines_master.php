<?php
require_once __DIR__.'/../../init.php';
require_manager();
$title = 'Medicines Master';
$errors=[]; $warnings=[]; $fieldErrors=[]; $ok=''; $q=trim((string)getv('q','')); $editId=(int)getv('edit',0); $duplicateRows=[];
$importSummary = ['added'=>0,'duplicate_skipped'=>0,'blank_skipped'=>0,'samples'=>[]];
$edit=['id'=>0,'name'=>'','category'=>'','notes'=>''];
function medicines_master_rows(string $where): array {
  global $mysqli; $rows=[]; if($res=$mysqli->query("SELECT * FROM medicines_master {$where} ORDER BY name ASC LIMIT 500")){ while($row=$res->fetch_assoc()) $rows[]=$row; $res->free(); } return $rows;
}
function medicines_master_count(string $where): int {
  global $mysqli; if($res=$mysqli->query("SELECT COUNT(*) c FROM medicines_master {$where}")){ $row=$res->fetch_assoc(); $res->free(); return (int)($row['c'] ?? 0); } return 0;
}
$where='WHERE active=1'; if($q!==''){ $safe=$mysqli->real_escape_string('%'.$q.'%'); $where .= " AND (name LIKE '{$safe}' OR category LIKE '{$safe}' OR notes LIKE '{$safe}')"; }
if(getv('template','')==='csv'){ csv_download('medicines-master-template.csv',['Name','Category','Notes'],[['Amoxicillin','Antibiotic','500mg capsule'],['Losartan','Maintenance','50mg tablet']]); }
if(getv('export','')==='csv'){ $rows=medicines_master_rows($where); $csv=[]; foreach($rows as $row) $csv[]=[(string)$row['name'],(string)$row['category'],(string)$row['notes']]; csv_download('medicines-master.csv',['Name','Category','Notes'],$csv); }
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_validate();
  $action=(string)post('action','save');
  if($action==='delete'){
    $id=(int)post('id',0); $stmt=$mysqli->prepare('UPDATE medicines_master SET active=0 WHERE id=? LIMIT 1'); if($stmt){ $stmt->bind_param('i',$id); $stmt->execute(); $stmt->close(); log_audit('medicine_master_archived','medicine_master',$id,'Medicine archived'); $ok='Medicine archived.'; }
  } elseif($action==='import_csv') {
    $importRows = csv_rows_from_upload('import_file');
    if(!$importRows) $errors[]='Please upload a valid CSV file with at least one data row.';
    else {
      foreach($importRows as $row){
        $name=trim((string)($row['name'] ?? $row['medicine'] ?? $row['medicine name'] ?? ''));
        $category=trim((string)($row['category'] ?? ''));
        $notes=trim((string)($row['notes'] ?? ''));
        $sample = $name !== '' ? $name : '[blank row]';
        if($name===''){ $importSummary['blank_skipped']++; if(count($importSummary['samples']) < 5) $importSummary['samples'][]=$sample; continue; }
        if(master_find_possible_duplicates('medicines_master','name',$name,0,['category'=>$category])){ $importSummary['duplicate_skipped']++; if(count($importSummary['samples']) < 5) $importSummary['samples'][]=$sample; continue; }
        $stmt=$mysqli->prepare('INSERT INTO medicines_master (name,category,notes,active) VALUES (?,?,?,1)'); if($stmt){ $stmt->bind_param('sss',$name,$category,$notes); if($stmt->execute()) $importSummary['added']++; $stmt->close(); }
      }
      $ok = "CSV import finished: {$importSummary['added']} added, {$importSummary['duplicate_skipped']} duplicate-skipped, {$importSummary['blank_skipped']} blank-skipped.";
      if($importSummary['samples']) $warnings[] = 'Sample skipped rows: '.implode(', ', $importSummary['samples']);
    }
  } else {
    $id=(int)post('id',0); $forceSave=(int)post('force_save',0)===1; $name=trim((string)post('name','')); $category=trim((string)post('category','')); $notes=trim((string)post('notes',''));
    $edit=['id'=>$id,'name'=>$name,'category'=>$category,'notes'=>$notes];
    if($name==='') { $errors[]='Medicine name is required.'; $fieldErrors['name']='Medicine name is required.'; }
    $duplicateRows = master_find_possible_duplicates('medicines_master','name',$name,$id,['category'=>$category]);
    if(!$errors && $duplicateRows && !$forceSave) $warnings[]='Possible duplicate medicines were found. Review them below before saving, or click Save Anyway only if this should remain a separate medicine record.';
    if(!$errors && (!$duplicateRows || $forceSave)){ if($id>0){ $stmt=$mysqli->prepare('UPDATE medicines_master SET name=?,category=?,notes=?,active=1 WHERE id=? LIMIT 1'); if($stmt){ $stmt->bind_param('sssi',$name,$category,$notes,$id); $stmt->execute(); $stmt->close(); log_audit('medicine_master_updated','medicine_master',$id,'Medicine updated'); $ok='Medicine updated.'; } } else { $stmt=$mysqli->prepare('INSERT INTO medicines_master (name,category,notes,active) VALUES (?,?,?,1)'); if($stmt){ $stmt->bind_param('sss',$name,$category,$notes); $stmt->execute(); $new=(int)$stmt->insert_id; $stmt->close(); log_audit('medicine_master_created','medicine_master',$new,'Medicine created'); $ok='Medicine added.'; } } $editId=0; $edit=['id'=>0,'name'=>'','category'=>'','notes'=>'']; $duplicateRows=[]; }
  }
}
if($editId>0 && !$edit['id']){ $stmt=$mysqli->prepare('SELECT * FROM medicines_master WHERE id=? LIMIT 1'); if($stmt){ $stmt->bind_param('i',$editId); $stmt->execute(); $edit=$stmt->get_result()->fetch_assoc() ?: $edit; $stmt->close(); } }
$totalActive = medicines_master_count('WHERE active=1');
$rows=medicines_master_rows($where);
$filteredCount=count($rows);
$searchState = $q !== '' ? 'Search: "'.$q.'"' : 'Showing all active medicines';
include __DIR__.'/../header.php';
?>
<div class="crm-hero"><div><h2>Medicines Master</h2><div class="subtle">Standardize product naming for cleaner reporting, exports, and dashboard trends.</div></div></div>
<div class="summary-strip summary-strip--masters"><div class="summary-chip"><span class="label">Active medicines</span><strong><?= (int)$totalActive ?></strong></div><div class="summary-chip"><span class="label">Filtered rows</span><strong><?= (int)$filteredCount ?></strong></div><div class="summary-chip summary-chip--wide"><span class="label">Current view</span><strong><?= e($searchState) ?></strong></div></div>
<div class="master-tools toolbar-stack"><div class="card subtle-card"><div class="section-head"><h3>Bulk tools</h3><span class="pill neutral">CSV</span></div><div class="master-tools"><form method="post" enctype="multipart/form-data"><?php csrf_input(); ?><input type="hidden" name="action" value="import_csv"><input class="file-input" type="file" name="import_file" accept=".csv" required><button class="btn" type="submit">Import CSV</button><span class="mini-note">Headers: name, category, notes</span></form><div class="master-tools-actions"><form method="get" action="<?= url('masters/medicines_master.php') ?>"><input type="hidden" name="q" value="<?= e($q) ?>"><input type="hidden" name="export" value="csv"><button class="btn success" type="submit">Export CSV</button></form><form method="get" action="<?= url('masters/medicines_master.php') ?>"><input type="hidden" name="template" value="csv"><button class="btn" type="submit">Download Template</button></form></div></div><div class="mini-note master-help">Import will skip blank rows and likely duplicates. Use the template if you want a quick CSV starter.</div></div></div>
<div class="grid two-panels masters-grid">
  <div class="card">
    <div class="section-head"><h3><?= $edit['id'] ? 'Edit medicine' : 'Add medicine' ?></h3><span class="pill neutral">Master Data</span></div>
    <?php form_messages($errors, $warnings, $ok); ?>
    <?php if($duplicateRows): ?><div class="alert warning form-alert duplicate-warning-box"><strong>Possible duplicates found</strong><div class="mini-note">Use <strong>Save Anyway</strong> only when the medicine really should remain separate.</div><ul class="duplicate-list"><?php foreach($duplicateRows as $dup): ?><li><?= e(($dup['name'] ?? '').' • '.($dup['category'] ?? '')) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
    <form method="post" class="form crm-form"><?php csrf_input(); ?>
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="force_save" value="<?= $duplicateRows ? '1' : '0' ?>">
      <?php render_text_input('Name', 'name', (string)$edit['name'], ['required'=>true], $fieldErrors); ?>
      <?php render_text_input('Category', 'category', (string)$edit['category'], ['placeholder'=>'Example: Antibiotic'], $fieldErrors); ?>
      <?php render_textarea_input('Notes', 'notes', (string)$edit['notes'], ['rows'=>3], $fieldErrors); ?>
      <div class="actions-inline form-actions"><button class="btn primary" type="submit"><?= $duplicateRows ? 'Save Anyway' : ($edit['id'] ? 'Save Changes' : 'Add Medicine') ?></button><?php if($edit['id']): ?><a class="btn" href="<?= url('masters/medicines_master.php') ?>">Cancel</a><?php endif; ?></div>
    </form>
  </div>
  <div class="card">
    <form method="get" class="toolbar compact-toolbar"><input type="text" name="q" value="<?= e($q) ?>" placeholder="Search medicine, category"><button class="btn" type="submit">Filter</button><?php if($q !== ''): ?><a class="btn" href="<?= url('masters/medicines_master.php') ?>">Reset</a><?php endif; ?></form>
    <?php if(!$rows): ?>
      <div class="empty-state empty-state--masters"><h4>No medicines found</h4><p><?= $q !== '' ? 'Try a different search or reset the filter.' : 'Start by adding a medicine manually or importing a CSV template.' ?></p></div>
    <?php else: ?>
      <div class="table-responsive"><table><thead><tr><th>Name</th><th>Category</th><th>Notes</th><th style="width:160px">Actions</th></tr></thead><tbody>
      <?php foreach($rows as $row): ?><tr><td><strong><?= e($row['name']) ?></strong></td><td><?= e($row['category']) ?></td><td class="muted"><?= e($row['notes']) ?></td><td><div class="table-actions"><a class="btn tiny" href="<?= url('masters/medicines_master.php?edit='.(int)$row['id']) ?>">Edit</a><form method="post" onsubmit="return confirm('Archive this medicine?');"><?php csrf_input(); ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn tiny danger" type="submit">Archive</button></form></div></td></tr><?php endforeach; ?>
      </tbody></table></div>
    <?php endif; ?>
  </div>
</div>
<?php include __DIR__.'/../footer.php'; ?>
