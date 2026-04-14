<?php
require_once __DIR__.'/../init.php';
require_login();
$activePage = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title??'Dashboard')) ?> · <?= e(APP_NAME) ?></title>
<link rel="manifest" href="manifest.webmanifest">
<meta name="theme-color" content="#0f766e">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="icon" type="image/png" sizes="192x192" href="assets/icons/icon-192.png">
<link rel="icon" type="image/png" sizes="512x512" href="assets/icons/icon-512.png">
<script>
  window.CSRF_TOKEN = "<?= e(csrf_token()) ?>";
  window.BASE_URL = "<?= e(BASE_URL_EFFECTIVE) ?>";
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('sw.js').catch(()=>{}));
  }
</script>
<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/calendar.css">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<script src="https://code.jquery.com/jquery-3.7.1.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" defer></script>
<?php if ($activePage === 'dashboard.php'): ?>
  <link rel="stylesheet" href="https://uicdn.toast.com/calendar/latest/toastui-calendar.min.css">
  <script src="https://uicdn.toast.com/calendar/latest/toastui-calendar.min.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<?php endif; ?>
<script src="assets/app.js" defer></script>
</head>
<body class="light">
<header class="topbar">
  <?php $active = basename($_SERVER['PHP_SELF']); ?>
  <div class="brand-block">
    <div class="brand-mark">CRM</div>
    <div>
      <div class="brand"><?= e(COMPANY_NAME) ?></div>
      <div class="brand-sub"><?= e(APP_NAME) ?></div>
    </div>
  </div>
  <nav class="nav">
    <a class="<?= $active==='dashboard.php'?'active':'' ?>" href="<?= url('dashboard.php') ?>">Dashboard</a>
    <a class="<?= $active==='reports.php'?'active':'' ?>" href="<?= url('reports.php') ?>">Reports</a>
    <a class="<?= $active==='report_add.php'?'active':'' ?>" href="<?= url('report_add.php') ?>">New Report</a>
    <?php if (is_manager() || is_district_manager()): ?>
      <a class="<?= $active==='approvals.php'?'active':'' ?>" href="<?= url('approvals.php') ?>">Approvals</a>
    <?php endif; ?>
    <?php if (is_manager()): ?>
      <a class="<?= $active==='admin_tasks.php'?'active':'' ?>" href="<?= url('admin_tasks.php') ?>">Tasks</a>
      <a class="<?= in_array($active,['admin_users.php','user_add.php','user_edit.php'])?'active':'' ?>" href="<?= url('admin_users.php') ?>">Users</a>
      <a class="<?= in_array($active,['doctors_master.php','hospitals_master.php','medicines_master.php'])?'active':'' ?>" href="<?= url('doctors_master.php') ?>">Masters</a>
      <a class="<?= $active==='exports.php'?'active':'' ?>" href="<?= url('exports.php') ?>">Exports</a>
    <?php endif; ?>
    <a class="<?= $active==='profile.php'?'active':'' ?>" href="<?= url('profile.php') ?>">Profile</a>
    <a href="<?= url('logout.php') ?>" class="danger">Logout</a>
  </nav>
</header>
<main class="shell">
  <aside class="sidebar">
    <div class="card sidebar-card">
      <div class="user-header">
        <div>
          <div class="eyebrow">Signed in as</div>
          <div class="user-name"><?= e(user()['name']) ?></div>
        </div>
        <span class="pill"><?= e(user()['role']) ?></span>
      </div>
      <div class="muted"><?= e(user()['email']) ?></div>
      <div id="clock" class="clock"></div>
      <div class="status-row">
        <span>Offline Queue</span>
        <span class="pill neutral" id="offlineQueueBadge">0</span>
      </div>
      <div class="status-row compact">
        <small id="offlineNetStatus" class="muted"></small>
        <button type="button" class="btn tiny" id="syncNowBtn">Sync</button>
      </div>
      <script>setInterval(()=>{const el=document.getElementById('clock'); if(el) el.textContent=new Date().toLocaleString();},1000);</script>
      <div class="divider"></div>
      <?php
      $cities=[];
      if ($res=$mysqli->query("SELECT DISTINCT place AS city FROM doctors_masterlist WHERE place IS NOT NULL AND place<>'' ORDER BY place ASC")) {
        while($rowCity=$res->fetch_assoc()) $cities[]=$rowCity['city'];
      }
      $repUsers = [];
      $meId = (int)(user()['id'] ?? 0);
      $meRole = strtolower(trim((string)(user()['role'] ?? 'employee')));
      if ($meRole === 'manager' || $meRole === 'admin') {
        if ($repRes = $mysqli->query("SELECT id,name,role FROM users WHERE active=1 ORDER BY role ASC, name ASC")) {
          while ($u = $repRes->fetch_assoc()) { $repUsers[] = $u; }
          $repRes->free();
        }
      } elseif ($meRole === 'district_manager') {
        $stmt = $mysqli->prepare("SELECT id,name,role FROM users WHERE active=1 AND (id=? OR district_manager_id=?) ORDER BY role ASC, name ASC");
        if ($stmt) {
          $stmt->bind_param('ii', $meId, $meId);
          $stmt->execute();
          $repRs = $stmt->get_result();
          while ($u = $repRs->fetch_assoc()) { $repUsers[] = $u; }
          $stmt->close();
        }
      }
      ?>
      <form action="event_add.php" method="post" class="form small" id="quickEventForm">
        <?php csrf_input(); ?>
        <div class="section-title">Quick Task</div>
        <label>Title<input name="title" id="qe_title" placeholder="Optional"></label>
        <label>City
          <select id="qe_city" name="city" required style="width:100%;">
            <option value="">Select City</option>
            <?php foreach($cities as $c): ?><option value="<?= e($c) ?>"><?= e($c) ?></option><?php endforeach; ?>
          </select>
        </label>
        <label>Doctor
          <select id="qe_doctor" name="doctor_id" <?= empty($cities)?'disabled':'' ?> required style="width:100%;">
            <option value="">Select Doctor</option>
          </select>
        </label>
        <label>Start<input type="datetime-local" name="start" required></label>
        <label>End<input type="datetime-local" name="end"></label>
        <label class="chk"><input type="checkbox" name="all_day" value="1"> All Day</label>
        <?php if (!empty($repUsers)): ?>
          <label>Reps attending
            <select name="attendees[]" multiple size="4">
              <?php foreach($repUsers as $u): if ((int)$u['id'] === $meId) continue; ?>
                <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['role']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php endif; ?>
        <button class="btn primary block" type="submit">Add Task</button>
      </form>
      <div class="divider"></div>
      <div class="stack">
        <a class="btn block" href="<?= url('reports.php') ?>">Open Reports</a>
        <a class="btn block primary" href="<?= url('report_add.php') ?>">Create Report</a>
        <?php if (is_manager()): ?>
          <a class="btn block" href="<?= url('doctors_master.php') ?>">Doctors Master</a>
          <a class="btn block" href="<?= url('hospitals_master.php') ?>">Hospitals Master</a>
          <a class="btn block" href="<?= url('medicines_master.php') ?>">Medicines Master</a>
        <?php endif; ?>
      </div>
    </div>
  </aside>
  <section class="content">
