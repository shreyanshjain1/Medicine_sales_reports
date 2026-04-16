<?php
require_once __DIR__.'/../../init.php';
require_manager();
$title = 'System Settings';
$flash=''; $error='';
$security = app_security_settings();
$mail = app_mail_summary();

if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $company = trim((string)post('company_name',''));
  $app = trim((string)post('app_name',''));
  $welcome = trim((string)post('dashboard_welcome_text',''));
  $idle = max(5, (int)post('session_idle_minutes','30'));
  $abs = max(15, (int)post('session_absolute_minutes','480'));
  $allowSetup = post('allow_setup_page') ? '1' : '0';

  $ok = true;
  $ok = $ok && set_app_setting('company_name', $company);
  $ok = $ok && set_app_setting('app_name', $app);
  $ok = $ok && set_app_setting('dashboard_welcome_text', $welcome);
  $ok = $ok && set_app_setting('session_idle_minutes', (string)$idle, 'int');
  $ok = $ok && set_app_setting('session_absolute_minutes', (string)$abs, 'int');
  $ok = $ok && set_app_setting('allow_setup_page', $allowSetup, 'bool');

  if($ok){
    if(function_exists('log_audit')) log_audit('settings_updated', 'app_settings', null, 'Updated branding/security settings');
    $flash='System settings updated successfully.';
    $security = app_security_settings();
  } else {
    $error='Could not save one or more settings.';
  }
}

$companyVal = get_app_setting('company_name', COMPANY_NAME);
$appVal = get_app_setting('app_name', APP_NAME);
$welcomeVal = get_app_setting('dashboard_welcome_text', '');
require_once __DIR__.'/../header.php';
?>
<div class="page-head">
  <div>
    <h1>System Settings</h1>
    <p class="muted">Centralize branding, app behavior defaults, and admin-facing operational settings.</p>
  </div>
</div>

<?php if($flash): ?><div class="alert success"><?= e($flash) ?></div><?php endif; ?>
<?php if($error): ?><div class="alert"><?= e($error) ?></div><?php endif; ?>

<div class="grid cols-2 settings-grid">
  <div class="card">
    <h3>Branding</h3>
    <form method="post" class="form">
      <?php csrf_input(); ?>
      <label>Company Name
        <input type="text" name="company_name" value="<?= e($companyVal) ?>" placeholder="<?= e(COMPANY_NAME) ?>">
      </label>
      <label>Application Name
        <input type="text" name="app_name" value="<?= e($appVal) ?>" placeholder="<?= e(APP_NAME) ?>">
      </label>
      <label>Dashboard Welcome Text
        <textarea name="dashboard_welcome_text" rows="4" placeholder="Optional message shown on dashboards and summary views."><?= e($welcomeVal) ?></textarea>
      </label>
      <div class="actions"><button class="btn primary" type="submit">Save Branding</button></div>
    </form>
  </div>

  <div class="card">
    <h3>Security Defaults</h3>
    <form method="post" class="form">
      <?php csrf_input(); ?>
      <label>Idle Session Timeout (minutes)
        <input type="number" min="5" step="1" name="session_idle_minutes" value="<?= (int)$security['session_idle_minutes'] ?>">
      </label>
      <label>Absolute Session Lifetime (minutes)
        <input type="number" min="15" step="1" name="session_absolute_minutes" value="<?= (int)$security['session_absolute_minutes'] ?>">
      </label>
      <label class="chk">
        <input type="checkbox" name="allow_setup_page" value="1" <?= (int)$security['allow_setup_page'] ? 'checked' : '' ?>> Allow setup page toggle in app settings
      </label>
      <div class="inline-note">These values are stored centrally for operations visibility and future runtime integration. Current hardcoded config values still apply where direct constants are used.</div>
      <div class="actions"><button class="btn primary" type="submit">Save Security Settings</button></div>
    </form>
  </div>
</div>

<div class="grid cols-2 settings-grid">
  <div class="card">
    <h3>Mail Configuration Snapshot</h3>
    <table class="table compact">
      <tr><th>Enabled</th><td><?= e($mail['enabled']) ?></td></tr>
      <tr><th>Driver</th><td><?= e($mail['driver']) ?></td></tr>
      <tr><th>From Email</th><td><?= e($mail['from_email']) ?></td></tr>
      <tr><th>From Name</th><td><?= e($mail['from_name']) ?></td></tr>
      <tr><th>Reply-To</th><td><?= e($mail['reply_to']) ?></td></tr>
    </table>
    <div class="inline-note">Mail transport is still controlled in <code>config.php</code>. This page gives managers and admins a central read-only snapshot.</div>
  </div>

  <div class="card">
    <h3>Repository Hygiene</h3>
    <ul class="list clean">
      <li>Issue templates added for bugs and feature requests</li>
      <li>Pull request template added</li>
      <li>CONTRIBUTING.md added for collaborator guidance</li>
      <li>CODEOWNERS added for future ownership rules</li>
    </ul>
    <div class="inline-note">This PR also improves the GitHub repo itself so contributors have a cleaner path for changes and review.</div>
  </div>
</div>

<?php require_once __DIR__.'/../footer.php'; ?>
