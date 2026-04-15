<?php
require_once __DIR__.'/../init.php';
require_login();

// Used for conditional asset loading (important for offline mode)
$activePage = basename($_SERVER['PHP_SELF'] ?? '');
?>
<!doctype html><html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
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
    window.addEventListener('load', () => {
      navigator.serviceWorker.register('sw.js').catch(()=>{});
    });
  }
</script>

<link rel="stylesheet" href="assets/style.css">
<link rel="stylesheet" href="assets/calendar.css">

<!-- ✅ Select2 (single searchable dropdown for touch devices) -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://code.jquery.com/jquery-3.7.1.min.js" defer></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js" defer></script>

<?php // Heavy third-party libs are loaded ONLY on pages that need them so offline navigation stays snappy. ?>
<?php if ($activePage === 'dashboard.php'): ?>
  <link rel="stylesheet" href="https://uicdn.toast.com/calendar/latest/toastui-calendar.min.css" />
  <script src="https://uicdn.toast.com/calendar/latest/toastui-calendar.min.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
<?php endif; ?>

<script src="assets/app.js" defer></script>
</head>
<body class="light">

<header class="topbar glass">
  <?php $active = basename($_SERVER['PHP_SELF']); ?>
  <div class="brand titlecase"><?= e(COMPANY_NAME) ?> · Reporting</div>
  <nav class="nav titlecase">
    <a class="<?= $active==='dashboard.php'?'active':'' ?>" href="<?= url('dashboard.php') ?>">Dashboard</a>
    <a class="<?= $active==='reports.php'?'active':'' ?>" href="<?= url('reports.php') ?>">Reports</a>
    <a class="<?= $active==='report_add.php'?'active':'' ?>" href="<?= url('report_add.php') ?>">Add Report</a>
    <?php if (is_manager()): ?>
      <a class="<?= $active==='admin_tasks.php'?'active':'' ?>" href="<?= url('admin_tasks.php') ?>">Tasks</a>
      <a class="<?= in_array($active,['admin_users.php','user_add.php','user_edit.php'])?'active':'' ?>" href="<?= url('admin_users.php') ?>">Users</a>
      <a class="<?= $active==='exports.php'?'active':'' ?>" href="<?= url('exports.php') ?>">Export</a>
    <?php endif; ?>
    <?php if (is_manager() || is_district_manager()): ?>
      <a class="<?= $active==='performance.php'?'active':'' ?>" href="<?= url('performance.php') ?>">Performance</a>
    <?php endif; ?>
    <a class="<?= $active==='profile.php'?'active':'' ?>" href="<?= url('profile.php') ?>">Profile</a>
    <a href="<?= url('logout.php') ?>" class="danger">Logout</a>
  </nav>
</header>

<main class="container container-wide">
<aside class="sidebar">
  <div class="card usercard">
    <div class="user-name titlecase">
      <?= e(user()['name']) ?> <small class="pill"><?= e(user()['role']) ?></small>
    </div>
    <div class="muted"><?= e(user()['email']) ?></div>
    <div id="clock" class="clock"></div>

    <div class="muted" style="margin-top:6px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
      <span>Offline Queue:</span>
      <small class="pill" id="offlineQueueBadge">0</small>
      <button type="button" class="btn tiny" id="syncNowBtn" style="padding:.25rem .5rem;">Sync</button>
      <small id="offlineNetStatus" class="muted"></small>
    </div>

    <script>setInterval(()=>{clock.textContent=new Date().toLocaleString();},1000);</script>
    <hr>

    <?php
      // Server-side preload so City works even if JS is blocked
      $cities=[]; 
      if ($res=$mysqli->query("SELECT DISTINCT place AS city FROM doctors_masterlist WHERE place IS NOT NULL AND place<>'' ORDER BY place ASC")) {
        while($rowCity=$res->fetch_assoc()) $cities[]=$rowCity['city'];
      }

      // Reps list for multi-attendee tasks
      $repUsers = [];
      $meId = (int)(user()['id'] ?? 0);
      $meRole = strtolower(trim((string)(user()['role'] ?? 'employee')));

      // IMPORTANT: avoid using variable name "$r" here because it can collide
      // with page-level variables (e.g., report_view.php uses $r for the report array).
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
          // mysqlnd may be missing on some hosts; support both get_result and bind_result
          if (method_exists($stmt, 'get_result')) {
            $repRs = $stmt->get_result();
            while ($u = $repRs->fetch_assoc()) { $repUsers[] = $u; }
          } else {
            $uid = 0; $uname = $urole = '';
            $stmt->bind_result($uid, $uname, $urole);
            while ($stmt->fetch()) {
              $repUsers[] = ['id'=>$uid,'name'=>$uname,'role'=>$urole];
            }
          }
          $stmt->close();
        }
      }
    ?>

    <form action="event_add.php" method="post" class="form small" id="quickEventForm">
      <?php csrf_input(); ?>
      <h3 class="titlecase">Quick Task</h3>

      <label class="titlecase">Title
        <input name="title" id="qe_title" placeholder="(Optional — auto if doctor chosen)">
      </label>

      <!-- ✅ City is now searchable + dropdown (Select2) -->
      <label class="titlecase">City
        <select id="qe_city" name="city" required style="width:100%;">
          <option value="">Select City</option>
          <?php foreach($cities as $c): ?>
            <option value="<?= e($c) ?>"><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </label>

      <!-- ✅ Doctor is now searchable + dropdown (Select2) -->
      <label class="titlecase">Doctor
        <select id="qe_doctor" name="doctor_id" <?= empty($cities)?'disabled':'' ?> required style="width:100%;">
          <option value="">Select Doctor</option>
        </select>
      </label>

      <label class="titlecase">Start
        <input type="datetime-local" name="start" required>
      </label>
      <label class="titlecase">End
        <input type="datetime-local" name="end">
      </label>

      <label class="chk titlecase">
        <input type="checkbox" name="all_day" value="1"> All Day
      </label>

      <?php if (!empty($repUsers)): ?>
        <label class="titlecase">Reps attending (optional)
          <select name="attendees[]" multiple size="5">
            <?php foreach($repUsers as $u): ?>
              <?php if ((int)$u['id'] === $meId) continue; ?>
              <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?> (<?= e($u['role']) ?>)</option>
            <?php endforeach; ?>
          </select>
          <small class="muted">Hold Ctrl/⌘ to select multiple.</small>
        </label>
      <?php endif; ?>

      <button class="btn primary block titlecase" type="submit">Add Task</button>
    </form>

    <hr>
    <div class="quick-links">
      <a class="btn block titlecase" href="<?= url('reports.php') ?>">View Reports</a>
      <a class="btn block primary titlecase" href="<?= url('report_add.php') ?>">Add New Report</a>
      <?php if (is_manager() || is_district_manager()): ?>
        <a class="btn block titlecase" href="<?= url('performance.php') ?>">Performance KPIs</a>
      <?php endif; ?>
    </div>
  </div>
</aside>

<section class="content">
