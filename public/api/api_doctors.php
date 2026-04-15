<?php
require_once __DIR__ . '/../../init.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

/**
 * Auto-map "best" column names even if they have spaces / different cases.
 * Works for: Dr Name, Speciality, Hospital / Clinic Address, Place, e mail id, Contact No, Class ( A/B/C)
 */
function map_doctor_columns(mysqli $db): array {
  $cols = [];
  $res = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'doctors_masterlist'");
  $all = [];
  while ($r = $res->fetch_assoc()) $all[] = $r['COLUMN_NAME'];

  $find = function(array $cands) use ($all) {
    foreach ($cands as $c) {
      foreach ($all as $have) {
        if (strcasecmp($have, $c) === 0) return $have;            // exact (case-insensitive)
        if (preg_replace('/\s+/', '', strtolower($have)) === preg_replace('/\s+/', '', strtolower($c))) return $have; // ignore spaces
      }
    }
    // try fuzzy contains
    foreach ($all as $have) {
      $h = strtolower($have);
      foreach ($cands as $c) {
        $t = strtolower($c);
        if (strpos($h, $t) !== false) return $have;
      }
    }
    return null;
  };

  $cols['id']        = $find(['id','ID']);
  $cols['name']      = $find(['dr_name','doctor_name','Dr Name','Name','Doctor']);
  $cols['spec']      = $find(['speciality','specialty','Speciality','Specialty']);
  $cols['addr']      = $find(['hospital_address','Hospital / Clinic Address','Hospital Address','Clinic Address','Address']);
  $cols['place']     = $find(['place','city','City','Location','Area','Municipality','Province']);
  $cols['email']     = $find(['email','e mail id','E-mail','Email Address']);
  $cols['contact']   = $find(['contact_no','Contact No','Mobile','Phone']);
  $cols['class']     = $find(['class','Class ( A/B/C )','Tier','Segment']);

  // minimally require name + place
  if (!$cols['name'] || !$cols['place']) {
    http_response_code(500);
    echo json_encode(['error'=>'doctors_masterlist missing required columns (name/place). Found columns: '.$cols['name'].'/'.$cols['place']]);
    exit;
  }
  return $cols;
}

$cols = map_doctor_columns($mysqli);
$T = 'doctors_masterlist';
$cn = fn($k) => '`'.$cols[$k].'`'; // backtick-quote to allow spaces

$mode = $_GET['mode'] ?? '';

if ($mode === 'cities') {
  // DISTINCT city list
  $sql = "SELECT DISTINCT TRIM({$cn('place')}) AS city
          FROM `$T`
          WHERE {$cn('place')} IS NOT NULL AND TRIM({$cn('place')}) <> ''
          ORDER BY city ASC";
  $res = $mysqli->query($sql);
  $cities = [];
  while ($r = $res->fetch_assoc()) $cities[] = $r['city'];
  echo json_encode(['cities'=>$cities]); exit;
}

$city = trim($_GET['city'] ?? '');
if ($city !== '') {
  // Robust matching: exact (case-insensitive) OR LIKE
  $sql = "SELECT
            ".($cols['id']   ? $cn('id').' AS id,' : 'NULL AS id,')."
            {$cn('name')} AS dr_name,
            ".($cols['spec'] ? $cn('spec').' AS speciality,' : "'' AS speciality,")."
            ".($cols['addr'] ? $cn('addr').' AS hospital_address,' : "'' AS hospital_address,")."
            {$cn('place')} AS place,
            ".($cols['email'] ? $cn('email').' AS email,' : "'' AS email,")."
            ".($cols['contact'] ? $cn('contact').' AS contact_no,' : "'' AS contact_no,")."
            ".($cols['class'] ? $cn('class').' AS class' : "'' AS class")."
          FROM `$T`
          WHERE TRIM(LOWER({$cn('place')})) = LOWER(?)
             OR LOWER({$cn('place')}) LIKE CONCAT('%', LOWER(?), '%')
          ORDER BY {$cn('name')} ASC
          LIMIT 500";
  $stmt = $mysqli->prepare($sql);
  $stmt->bind_param('ss', $city, $city);
  $stmt->execute();
  $res = $stmt->get_result();
  $doctors = [];
  while ($r = $res->fetch_assoc()) {
    // If no numeric id column exists, synthesize a stable ID
    if (!isset($r['id']) || $r['id']===null) {
      $r['id'] = crc32(($r['dr_name']??'').'/'.($r['place']??''));
    }
    $doctors[] = $r;
  }
  echo json_encode(['doctors'=>$doctors]); exit;
}

http_response_code(400);
echo json_encode(['error'=>'bad request']);
