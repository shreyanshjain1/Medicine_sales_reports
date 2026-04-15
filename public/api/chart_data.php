<?php
require_once __DIR__.'/../../init.php'; require_login(); header('Content-Type: application/json');
$mine = isset($_GET['mine']);
if($mine){
  $uid=user()['id'];
  $res=$mysqli->query("SELECT DATE(visit_datetime) d, COUNT(*) c FROM reports WHERE user_id=$uid GROUP BY d ORDER BY d ASC");
  $labels=[];$data=[]; while($r=$res->fetch_assoc()){ $labels[]=$r['d']; $data[]=(int)$r['c']; }
  echo json_encode(['byDate'=>['labels'=>$labels,'data'=>$data]]); exit;
}
$role = user()['role'] ?? 'employee';

// Employee: only their own charts (legacy behavior)
if ($role === 'employee') { echo json_encode(['error'=>'forbidden']); exit; }

// Manager: all reports
// District manager: scope to own + assigned employees
$where = '1';
if ($role === 'district_manager') {
  $where = reports_scope_where('r');
}
$labels=[];$data=[];
if ($role === 'manager') {
  $res=$mysqli->query('SELECT u.name, COUNT(r.id) c FROM users u LEFT JOIN reports r ON r.user_id=u.id GROUP BY u.id ORDER BY c DESC');
} else {
  // district manager: only show own + assigned employees
  $dmId = (int)user()['id'];
  $res=$mysqli->query("SELECT u.name, COUNT(r.id) c
    FROM users u
    LEFT JOIN reports r ON r.user_id=u.id
    WHERE (u.id={$dmId} OR u.district_manager_id={$dmId})
    GROUP BY u.id
    ORDER BY c DESC, u.name ASC");
}
while($r=$res->fetch_assoc()){ $labels[]=$r['name']; $data[]=(int)$r['c']; }
$byEmployee=['labels'=>$labels,'data'=>$data];
$labels=[];$data=[]; $res=$mysqli->query("SELECT DATE(r.visit_datetime) d, COUNT(*) c FROM reports r WHERE $where GROUP BY d ORDER BY d ASC");
while($r=$res->fetch_assoc()){ $labels[]=$r['d']; $data[]=(int)$r['c']; }
$byDate=['labels'=>$labels,'data'=>$data];
$labels=[];$data=[]; $res=$mysqli->query("SELECT COALESCE(NULLIF(r.status,''),'pending') status, COUNT(*) c FROM reports r WHERE $where GROUP BY status");
while($r=$res->fetch_assoc()){ $labels[]=$r['status']; $data[]=(int)$r['c']; }
$byStatus=['labels'=>$labels,'data'=>$data];
echo json_encode(['byEmployee'=>$byEmployee,'byDate'=>$byDate,'byStatus'=>$byStatus]);
