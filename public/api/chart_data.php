<?php
require_once __DIR__.'/../../init.php';
api_require_login();
api_require_method('GET');
api_boot();

$mine = isset($_GET['mine']);
if ($mine) {
  $uid = (int)user()['id'];
  $res = $mysqli->query("SELECT DATE(visit_datetime) d, COUNT(*) c FROM reports WHERE user_id={$uid} GROUP BY d ORDER BY d ASC");
  if (!$res) {
    api_error('Unable to load personal chart data.', 500, [$mysqli->error ?: 'Query failed']);
  }
  $labels=[]; $data=[];
  while($r=$res->fetch_assoc()){ $labels[]=$r['d']; $data[]=(int)$r['c']; }
  api_success(['byDate'=>['labels'=>$labels,'data'=>$data]], 'Personal chart data loaded.');
}

if (is_employee()) {
  api_error('Forbidden.', 403, ['Managers or district managers only.']);
}

$where = '1';
if (is_district_manager()) {
  $where = reports_scope_where('r');
}

$labels=[];$data=[];
if (is_manager()) {
  $res=$mysqli->query('SELECT u.name, COUNT(r.id) c FROM users u LEFT JOIN reports r ON r.user_id=u.id GROUP BY u.id ORDER BY c DESC');
} else {
  $dmId = (int)user()['id'];
  $res=$mysqli->query("SELECT u.name, COUNT(r.id) c
    FROM users u
    LEFT JOIN reports r ON r.user_id=u.id
    WHERE (u.id={$dmId} OR u.district_manager_id={$dmId})
    GROUP BY u.id
    ORDER BY c DESC, u.name ASC");
}
if (!$res) api_error('Unable to load employee chart data.', 500, [$mysqli->error ?: 'Query failed']);
while($r=$res->fetch_assoc()){ $labels[]=$r['name']; $data[]=(int)$r['c']; }
$byEmployee=['labels'=>$labels,'data'=>$data];

$labels=[];$data=[]; $res=$mysqli->query("SELECT DATE(r.visit_datetime) d, COUNT(*) c FROM reports r WHERE $where GROUP BY d ORDER BY d ASC");
if (!$res) api_error('Unable to load timeline chart data.', 500, [$mysqli->error ?: 'Query failed']);
while($r=$res->fetch_assoc()){ $labels[]=$r['d']; $data[]=(int)$r['c']; }
$byDate=['labels'=>$labels,'data'=>$data];

$labels=[];$data=[]; $res=$mysqli->query("SELECT COALESCE(NULLIF(r.status,''),'pending') status, COUNT(*) c FROM reports r WHERE $where GROUP BY status");
if (!$res) api_error('Unable to load status chart data.', 500, [$mysqli->error ?: 'Query failed']);
while($r=$res->fetch_assoc()){ $labels[]=$r['status']; $data[]=(int)$r['c']; }
$byStatus=['labels'=>$labels,'data'=>$data];

api_success(['byEmployee'=>$byEmployee,'byDate'=>$byDate,'byStatus'=>$byStatus], 'Chart data loaded.');
