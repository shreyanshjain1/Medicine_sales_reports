<?php
require_once __DIR__ . '/../../init.php';
api_require_login();
api_require_method('GET');
api_boot();

/**
 * Auto-map "best" column names even if they have spaces / different cases.
 */
function map_doctor_columns(mysqli $db): array {
  $res = $db->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'doctors_masterlist'");
  if (!$res) {
    api_json_error('Unable to inspect doctors master columns.', 500, [$db->error ?: 'COLUMN lookup failed']);
  }

  $all = [];
  while ($r = $res->fetch_assoc()) $all[] = $r['COLUMN_NAME'];

  $find = function(array $cands) use ($all) {
    foreach ($cands as $c) {
      foreach ($all as $have) {
        if (strcasecmp($have, $c) === 0) return $have;
        if (preg_replace('/\s+/', '', strtolower($have)) === preg_replace('/\s+/', '', strtolower($c))) return $have;
      }
    }
    foreach ($all as $have) {
      $h = strtolower($have);
      foreach ($cands as $c) {
        if (strpos($h, strtolower($c)) !== false) return $have;
      }
    }
    return null;
  };

  $cols = [];
  $cols['id']      = $find(['id','ID']);
  $cols['name']    = $find(['dr_name','doctor_name','Dr Name','Name','Doctor']);
  $cols['spec']    = $find(['speciality','specialty','Speciality','Specialty']);
  $cols['addr']    = $find(['hospital_address','Hospital / Clinic Address','Hospital Address','Clinic Address','Address']);
  $cols['place']   = $find(['place','city','City','Location','Area','Municipality','Province']);
  $cols['email']   = $find(['email','e mail id','E-mail','Email Address']);
  $cols['contact'] = $find(['contact_no','Contact No','Mobile','Phone']);
  $cols['class']   = $find(['class','Class ( A/B/C )','Tier','Segment']);

  if (!$cols['name'] || !$cols['place']) {
    api_json_error('Doctors masterlist is missing required columns.', 500, [
      'Expected mapped name/place columns.',
      'Detected name=' . (string)$cols['name'] . ', place=' . (string)$cols['place'],
    ]);
  }
  return $cols;
}

$cols = map_doctor_columns($mysqli);
$T = 'doctors_masterlist';
$cn = fn($k) => '`'.$cols[$k].'`';
$mode = api_get_string($_GET, 'mode', false, 20, 'mode');

if ($mode === 'cities') {
  $sql = "SELECT DISTINCT TRIM({$cn('place')}) AS city
          FROM `$T`
          WHERE {$cn('place')} IS NOT NULL AND TRIM({$cn('place')}) <> ''
          ORDER BY city ASC";
  $res = api_db_query_or_fail($mysqli, $sql, 'Unable to load city list.');
  $cities = [];
  while ($r = $res->fetch_assoc()) $cities[] = $r['city'];
  api_json_success(['cities' => $cities], 'Cities loaded.');
}

$city = api_get_string($_GET, 'city', false, 120, 'city');
if ($city !== '') {
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
  if (!$stmt) {
    api_json_error('Unable to prepare doctors lookup query.', 500, [$mysqli->error ?: 'Prepare failed']);
  }
  $stmt->bind_param('ss', $city, $city);
  $stmt->execute();
  $res = $stmt->get_result();
  $doctors = [];
  while ($r = $res->fetch_assoc()) {
    if (!isset($r['id']) || $r['id'] === null) {
      $r['id'] = crc32(($r['dr_name'] ?? '').'/'.($r['place'] ?? ''));
    }
    $doctors[] = $r;
  }
  $stmt->close();
  api_json_success(['doctors' => $doctors, 'city' => $city], 'Doctors loaded.');
}

api_json_error('Bad request.', 400, ['Pass mode=cities or city=<name>.']);
