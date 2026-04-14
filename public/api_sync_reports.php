<?php
require_once __DIR__.'/../init.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

function json_fail($msg, $code=400){
  http_response_code($code);
  echo json_encode(['ok'=>false,'error'=>$msg], JSON_UNESCAPED_SLASHES);
  exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) json_fail('Invalid JSON payload.');

$token = (string)($payload['_token'] ?? '');
if ($token === '' || !hash_equals(csrf_token(), $token)) json_fail('Invalid CSRF token.');

$items = $payload['items'] ?? [];
if (!is_array($items)) json_fail('Invalid items.');

$uid = (int)user()['id'];

/* Create idempotency map table (no ALTER needed) */
$mysqli->query("CREATE TABLE IF NOT EXISTS report_client_map (
  client_uuid VARCHAR(64) PRIMARY KEY,
  report_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(user_id),
  INDEX(report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$results = [];
foreach ($items as $it) {
  if (!is_array($it)) { $results[]=['ok'=>false,'error'=>'Invalid item']; continue; }

  $client_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($it['client_id'] ?? ''));
  if ($client_id === '') { $results[]=['ok'=>false,'error'=>'Missing client_id']; continue; }

  // Already synced?
  $stmt = $mysqli->prepare("SELECT report_id FROM report_client_map WHERE client_uuid=? AND user_id=? LIMIT 1");
  $stmt->bind_param('si', $client_id, $uid);
  $stmt->execute();
  $existing = $stmt->get_result()->fetch_assoc();
  if ($existing) {
    $results[] = ['ok'=>true,'client_id'=>$client_id,'report_id'=>(int)$existing['report_id'],'already'=>true];
    continue;
  }

  $doctor_name   = trim((string)($it['doctor_name'] ?? ''));
  $doctor_email  = trim((string)($it['doctor_email'] ?? ''));
  $purpose       = trim((string)($it['purpose'] ?? ''));
  $medicine_name = trim((string)($it['medicine_name'] ?? ''));
  $hospital_name = trim((string)($it['hospital_name'] ?? ''));
  $visit_dt      = trim((string)($it['visit_datetime'] ?? ''));
  $summary       = trim((string)($it['summary'] ?? ''));
  $remarks       = trim((string)($it['remarks'] ?? ''));

  if ($doctor_name === '' || $visit_dt === '') {
    $results[] = ['ok'=>false,'client_id'=>$client_id,'error'=>'Doctor Name and Visit Date/Time are required.'];
    continue;
  }

  // Normalize datetime-local format to MySQL DATETIME (best-effort)
  if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $visit_dt)) {
    $visit_dt = str_replace('T',' ', $visit_dt).':00';
  }

  // Signature (store relative path like online flow)
  $signature_path = null;
  $sig_data = (string)($it['signature_data'] ?? '');
  if ($sig_data && preg_match('/^data:image\/(png|jpeg);base64,/', $sig_data)) {
    $ext = (strpos($sig_data,'image/png') !== false) ? 'png' : 'jpg';
    $b64 = explode(',', $sig_data, 2)[1] ?? '';
    $bin = base64_decode($b64, true);
    if ($bin !== false) {
      if (!is_dir(SIGNATURE_DIR)) @mkdir(SIGNATURE_DIR, 0775, true);
      $filename = 'sig_'.$uid.'_'.time().'_'.substr(md5($client_id),0,8).'.'.$ext;
      file_put_contents(SIGNATURE_DIR . '/' . $filename, $bin);
      $signature_path = 'uploads/signatures/' . $filename;
    }
  }

  // Attachment (base64) (store relative path like online flow)
  $attachment_path = null;
  $att = $it['attachment'] ?? null;
  if (is_array($att) && !empty($att['data']) && !empty($att['name'])) {
    $name = (string)$att['name'];
    $data = (string)$att['data'];
    $mime = (string)($att['mime'] ?? '');

    // data can be raw base64 OR data:...;base64,...
    if (strpos($data, 'base64,') !== false) $data = explode('base64,', $data, 2)[1];
    $bin = base64_decode($data, true);

    if ($bin !== false) {
      if (!is_dir(ATTACH_DIR)) @mkdir(ATTACH_DIR, 0775, true);
      $ext = pathinfo($name, PATHINFO_EXTENSION);
      if ($ext === '') {
        // derive from mime
        if ($mime === 'application/pdf') $ext = 'pdf';
        else if ($mime === 'image/png') $ext = 'png';
        else if ($mime === 'image/jpeg') $ext = 'jpg';
        else $ext = 'bin';
      }
      $filename = 'att_'.$uid.'_'.time().'_'.substr(md5($client_id),0,8).'.'.$ext;
      file_put_contents(ATTACH_DIR . '/' . $filename, $bin);
      $attachment_path = 'uploads/attachments/' . $filename;
    }
  }

  // Insert report (backward-compatible)
  $rid = db_safe_insert('reports', [
    'user_id' => $uid,
    'doctor_name' => $doctor_name,
    'doctor_email' => $doctor_email,
    'purpose' => $purpose,
    'medicine_name' => $medicine_name,
    'hospital_name' => $hospital_name,
    'visit_datetime' => $visit_dt,
    'summary' => $summary,
    'remarks' => $remarks,
    'signature_path' => $signature_path,
    'attachment_path' => $attachment_path,
    'status' => 'pending',
    'created_at' => date('Y-m-d H:i:s'),
  ]);

  if ($rid <= 0) {
    $rid = db_safe_insert('reports', [
      'user_id' => $uid,
      'doctor_name' => $doctor_name,
      'doctor_email' => $doctor_email,
      'visit_datetime' => $visit_dt,
      'created_at' => date('Y-m-d H:i:s'),
    ]);
  }

  if ($rid <= 0) {
    $results[] = ['ok'=>false,'client_id'=>$client_id,'error'=>'DB insert failed.'];
    continue;
  }

  // Save map for idempotency
  $stmt2 = $mysqli->prepare("INSERT INTO report_client_map (client_uuid, report_id, user_id) VALUES (?,?,?)");
  $stmt2->bind_param('sii', $client_id, $rid, $uid);
  $stmt2->execute();

  $results[] = ['ok'=>true,'client_id'=>$client_id,'report_id'=>$rid,'already'=>false];
}

echo json_encode(['ok'=>true,'results'=>$results], JSON_UNESCAPED_SLASHES);
