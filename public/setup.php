<?php
require_once __DIR__ . '/../init.php';

if (!setup_runtime_allowed()) {
  http_response_code(403);
  exit('Setup is disabled or not allowed from this environment.');
}

$key = trim((string)($_REQUEST['key'] ?? ''));
if (!tool_key_valid($key, (string)SETUP_KEY)) {
  http_response_code(403);
  exit('Invalid setup key.');
}

$schemaFile = __DIR__ . '/../database/schema.sql';
if (!is_file($schemaFile)) {
  http_response_code(500);
  exit('Missing database/schema.sql');
}

$results = [];
$applied = false;
$confirmError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  csrf_verify();
  if (!post('confirm_setup')) {
    $confirmError = 'Please confirm that this environment is intended for setup before continuing.';
  } else {
    $sql = file_get_contents($schemaFile);
    if ($sql === false) {
      http_response_code(500);
      exit('Unable to read database/schema.sql');
    }
    $parts = preg_split('/;\s*(?:?
|$)/', $sql) ?: [];
    foreach ($parts as $statement) {
      $statement = trim((string)$statement);
      if ($statement === '' || str_starts_with($statement, '--')) continue;
      if (@$mysqli->query($statement) === true) {
        $results[] = ['ok' => true, 'sql' => $statement];
      } else {
        $results[] = ['ok' => false, 'sql' => $statement, 'error' => $mysqli->error];
      }
    }
    $applied = true;
    if (function_exists('log_audit')) {
      log_audit('setup_page_run', 'setup', null, 'Applied consolidated schema through setup.php');
    }
  }
}

$env = app_env_value();
$loopback = request_ip_is_loopback();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e(app_name_value()) ?> Setup</title>
  <style>
    body{font-family:Arial,sans-serif;background:#f5f7fb;margin:0;padding:24px;color:#0f172a}
    .card{max-width:1100px;margin:0 auto;background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:24px;box-shadow:0 8px 24px rgba(15,23,42,.06)}
    h1{margin-top:0}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.alert{padding:12px 14px;border-radius:12px;margin:0 0 16px;border:1px solid}.warn{background:#fff7ed;border-color:#fdba74;color:#9a3412}.err{background:#fef2f2;border-color:#fca5a5;color:#991b1b}.ok{background:#ecfdf5;border-color:#86efac;color:#166534}.muted{color:#475569}.btn{display:inline-block;padding:10px 16px;border-radius:10px;border:0;background:#0f172a;color:#fff;cursor:pointer}.check{display:flex;gap:10px;align-items:flex-start;margin:12px 0}.list{padding-left:18px} code{background:#f1f5f9;padding:2px 6px;border-radius:6px}
  </style>
</head>
<body>
  <div class="card">
    <h1><?= e(app_name_value()) ?> — Protected Setup</h1>
    <p class="muted">This page is intended for first-time installation only. It now requires an explicit POST confirmation before any schema changes are applied.</p>

    <div class="grid">
      <div>
        <div class="alert warn">
          <strong>Environment:</strong> <?= e($env) ?><br>
          <strong>Request origin loopback:</strong> <?= $loopback ? 'Yes' : 'No' ?><br>
          <strong>Schema source:</strong> <code>database/schema.sql</code>
        </div>
        <ul class="list">
          <li>Use this only for fresh installation or controlled recovery.</li>
          <li>Disable the setup toggle immediately after use.</li>
          <li>Do not leave the setup key at a default placeholder value.</li>
        </ul>
      </div>
      <div>
        <?php if($confirmError): ?><div class="alert err"><?= e($confirmError) ?></div><?php endif; ?>
        <?php if(!$applied): ?>
          <form method="post">
            <?php csrf_input(); ?>
            <input type="hidden" name="key" value="<?= e($key) ?>">
            <label class="check"><input type="checkbox" name="confirm_setup" value="1"> <span>I understand this will execute the consolidated schema against the configured database.</span></label>
            <button class="btn" type="submit">Run Setup</button>
          </form>
        <?php else: ?>
          <div class="alert ok"><strong>Setup finished.</strong> Review the results below and disable the setup toggle right away.</div>
        <?php endif; ?>
      </div>
    </div>

    <?php if($applied): ?>
      <ul class="list">
        <?php foreach ($results as $row): ?>
          <li class="<?= $row['ok'] ? 'ok' : 'err' ?>"><?= htmlspecialchars(($row['ok'] ? 'OK: ' : 'ERR: ') . ($row['ok'] ? $row['sql'] : ($row['sql'] . ' — ' . ($row['error'] ?? 'unknown error'))), ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
  </div>
</body>
</html>
