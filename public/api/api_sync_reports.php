<?php
require_once __DIR__.'/../../init.php';
api_require_login();
api_require_method('POST');
api_boot();

$raw = file_get_contents('php://input');
$payload = json_decode($raw, true);
if (!is_array($payload)) api_error('Invalid JSON payload.', 400, ['Body must be valid JSON.']);

$token = (string)($payload['_token'] ?? '');
if ($token === '' || !hash_equals(csrf_token(), $token)) api_error('Invalid CSRF token.', 403, ['CSRF token mismatch.']);

$items = $payload['items'] ?? [];
if (!is_array($items)) api_error('Invalid items.', 400, ['items must be an array.']);

$uid = (int)user()['id'];
$mysqli->query("CREATE TABLE IF NOT EXISTS report_client_map (
  client_uuid VARCHAR(64) PRIMARY KEY,
  report_id INT NOT NULL,
  user_id INT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX(user_id),
  INDEX(report_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$results = [];
$summary = ['created' => 0, 'already_synced' => 0, 'failed' => 0];
foreach ($items as $it) {
  if (!is_array($it)) { $results[]=['success'=>false,'client_id'=>null,'message'=>'Invalid item']; $summary['failed']++; continue; }

  $client_id = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($it['client_id'] ?? ''));
  if ($client_id === '') { $results[]=['success'=>false,'client_id'=>null,'message'=>'Missing client_id']; $summary['failed']++; continue; }

  $stmt = $mysqli->prepare("SELECT report_id FROM report_client_map WHERE client_uuid=? AND user_id=? LIMIT 1");
  if (!$stmt) {
    $results[]=['success'=>false,'client_id'=>$client_id,'message'=>'Idempotency lookup failed.'];
    $summary['failed']++;
    continue;
  }
  $stmt->bind_param('si', $client_id, $uid);
  $stmt->execute();
  $existing = $stmt->get_result()->fetch_assoc();
  $stmt->close();
  if ($existing) {
    $results[] = ['success'=>true,'client_id'=>$client_id,'report_id'=>(int)$existing['report_id'],'already'=>true,'message'=>'Already synced.'];
    $summary['already_synced']++;
    continue;
  }

  $doctor_name   = trim((string)($it['doctor_name'] ?? ''));
  $doctor_email  = trim((string)($it['doctor_email'] ?? ''));
  $purpose       = trim((string)($it['purpose'] ?? ''));
  $medicine_name = trim((string)($it['medicine_name'] ?? ''));
  $hospital_name = trim((string)($it['hospital_name'] ?? ''));
  $visit_dt      = trim((string)($it['visit_datetime'] ?? ''));
  $summaryText   = trim((string)($it['summary'] ?? ''));
  $remarks       = trim((string)($it['remarks'] ?? ''));

  if ($doctor_name === '' || $visit_dt === '') {
    $results[] = ['success'=>false,'client_id'=>$client_id,'message'=>'Doctor Name and Visit Date/Time are required.'];
    $summary['failed']++;
    continue;
  }

  if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $visit_dt)) {
    $visit_dt = str_replace('T',' ', $visit_dt).':00';
  }

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

  $attachment_path = null;
  $att = $it['attachment'] ?? null;
  if (is_array($att) && !empty($att['data']) && !empty($att['name'])) {
    $name = (string)$att['name'];
    $data = (string)$att['data'];
    $mime = (string)($att['mime'] ?? '');

    if (strpos($data, 'base64,') !== false) $data = explode('base64,', $data, 2)[1];
    $bin = base64_decode($data, true);

    if ($bin !== false) {
      if (!is_dir(ATTACH_DIR)) @mkdir(ATTACH_DIR, 0775, true);
      $ext = pathinfo($name, PATHINFO_EXTENSION);
      if ($ext === '') {
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

  $rid = db_safe_insert('reports', [
    'user_id' => $uid,
    'doctor_name' => $doctor_name,
    'doctor_email' => $doctor_email,
    'purpose' => $purpose,
    'medicine_name' => $medicine_name,
    'hospital_name' => $hospital_name,
    'visit_datetime' => $visit_dt,
    'summary' => $summaryText,
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
    $results[] = ['success'=>false,'client_id'=>$client_id,'message'=>'DB insert failed.'];
    $summary['failed']++;
    continue;
  }

  if (function_exists('log_report_status_history')) {
    log_report_status_history((int)$rid, 'pending', null, 'Offline sync submission created.');
  }
  if (function_exists('audit_log')) {
    audit_log('report_synced_offline', 'report', (int)$rid, 'Offline report synced from device outbox.');
  }

  $stmt2 = $mysqli->prepare("INSERT INTO report_client_map (client_uuid, report_id, user_id) VALUES (?,?,?)");
  if ($stmt2) {
    $stmt2->bind_param('sii', $client_id, $rid, $uid);
    $stmt2->execute();
    $stmt2->close();
  }

  $results[] = ['success'=>true,'client_id'=>$client_id,'report_id'=>$rid,'already'=>false,'message'=>'Report synced.'];
  $summary['created']++;
}

api_success(['results'=>$results, 'summary'=>$summary], 'Offline sync processed.');
