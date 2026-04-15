<?php
require_once __DIR__ . '/../init.php';
if (!can_run_setup()) { http_response_code(403); exit('Setup is disabled. Enable ALLOW_SETUP in config.php temporarily to use this page.'); }
$key = trim((string)($_GET['key'] ?? ''));
if ($key === '' || !hash_equals((string)SETUP_KEY, $key)) { http_response_code(403); exit('Invalid setup key.'); }

$schemaFile = __DIR__ . '/../database/schema.sql';
if (!is_file($schemaFile)) {
  http_response_code(500);
  exit('Missing database/schema.sql');
}

$sql = file_get_contents($schemaFile);
if ($sql === false) {
  http_response_code(500);
  exit('Unable to read database/schema.sql');
}

$statements = array_filter(array_map('trim', preg_split('/;\s*(?:?
|$)/', $sql)));
$results = [];
foreach ($statements as $statement) {
  if ($statement === '' || str_starts_with($statement, '--')) continue;
  if (@$mysqli->query($statement) === true) {
    $results[] = ['ok' => true, 'sql' => $statement];
  } else {
    $results[] = ['ok' => false, 'sql' => $statement, 'error' => $mysqli->error];
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Medicine Sales CRM Setup</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0;padding:24px;color:#0f172a}
    .card{max-width:1100px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;box-shadow:0 8px 24px rgba(15,23,42,.06)}
    h1{margin-top:0}
    ul{padding-left:20px}
    li{margin:8px 0}
    .ok{color:#166534}.err{color:#b91c1c}
    code{background:#f1f5f9;padding:2px 6px;border-radius:6px}
  </style>
</head>
<body>
  <div class="card">
    <h1>Medicine Sales CRM — Setup</h1>
    <p>Applying the consolidated schema from <code>database/schema.sql</code>. Demo users are not auto-seeded.</p>
    <ul>
      <?php foreach ($results as $row): ?>
        <li class="<?= $row['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars(($row['ok'] ? 'OK: ' : 'ERR: ') . ($row['ok'] ? $row['sql'] : ($row['sql'] . ' — ' . ($row['error'] ?? 'unknown error'))), ENT_QUOTES, 'UTF-8') ?></li>
      <?php endforeach; ?>
    </ul>
    <p><strong>Done.</strong> Create your first manager account in the database or through your user flows, then disable <code>ALLOW_SETUP</code> immediately.</p>
  </div>
</body>
</html>
