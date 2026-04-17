<?php
require_once __DIR__.'/../../init.php'; require_login();
$u=user(); $ok=false; $err=''; $errors=[]; $fieldErrors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $name=trim(post('name','')); $email=normalize_email(trim(post('email','')));
  $prefs = [
    'wants_email_notifications' => isset($_POST['wants_email_notifications']) ? 1 : 0,
    'notify_review_updates' => isset($_POST['notify_review_updates']) ? 1 : 0,
    'notify_task_assignments' => isset($_POST['notify_task_assignments']) ? 1 : 0,
    'notify_security_alerts' => isset($_POST['notify_security_alerts']) ? 1 : 0,
    'notify_digest_emails' => isset($_POST['notify_digest_emails']) ? 1 : 0,
  ];
  if($name===''){ $err='Please correct the highlighted fields.'; $errors[]='Name is required.'; $fieldErrors['name']='Name is required.'; }
  if($email===''){ $err='Please correct the highlighted fields.'; $errors[]='Email is required.'; $fieldErrors['email']='Email is required.'; }
  if(!$errors){
    $stmt=$mysqli->prepare('SELECT id FROM users WHERE email=? AND id<>? LIMIT 1');
    $stmt->bind_param('si', $email, $u['id']);
    $stmt->execute();
    $dupe=$stmt->get_result()->fetch_assoc();
    $stmt->close();
    if($dupe){
      $err='Please correct the highlighted fields.';
      $errors[]='That email is already in use by another account.';
      $fieldErrors['email']='That email is already in use by another account.';
    } else {
      $stmt=$mysqli->prepare('UPDATE users SET name=?, email=? WHERE id=?');
      $stmt->bind_param('ssi',$name,$email,$u['id']);
      if($stmt->execute() && save_user_notification_preferences((int)$u['id'], $prefs)){
        $_SESSION['user']['name']=$name; $_SESSION['user']['email']=$email; $ok=true;
        log_audit('profile_updated', 'user', (int)$u['id'], 'Profile details and notification preferences updated');
      } else $err='Update failed.';
      $stmt->close();
    }
  }
}
refresh_session_user_preferences((int)$u['id']);
$title='Profile'; include __DIR__.'/../header.php';
?>
<div class="card"><h2>My Profile</h2>
  <?php form_messages($errors, [], $ok ? 'Updated.' : ''); ?>
  <?php if($err && !$errors): ?><div class="alert danger form-alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form crm-form max-640"><?php csrf_input(); ?>
    <div class="grid two"><?php render_text_input('Name', 'name', (string)user()['name'], ['required'=>true], $fieldErrors); ?><?php render_text_input('Email', 'email', (string)user()['email'], ['type'=>'email','required'=>true], $fieldErrors); ?></div>

    <div class="section-header" style="margin-top:1rem;"><h3>Email Preferences</h3><p class="subtle">Choose which workflow emails should reach you. Security alerts stay enabled for critical account-recovery flows unless you explicitly turn off security emails.</p></div>
    <?php render_checkbox_input('Enable email notifications for this account', 'wants_email_notifications', user_pref_enabled(user(), 'wants_email_notifications', 1)); ?>
    <?php render_checkbox_input('Report review updates and returned reports', 'notify_review_updates', user_pref_enabled(user(), 'notify_review_updates', 1)); ?>
    <?php render_checkbox_input('Task assignments and visit scheduling alerts', 'notify_task_assignments', user_pref_enabled(user(), 'notify_task_assignments', 1)); ?>
    <?php render_checkbox_input('Security alerts and password reset emails', 'notify_security_alerts', user_pref_enabled(user(), 'notify_security_alerts', 1)); ?>
    <?php if (is_manager() || is_district_manager()): ?>
      <?php render_checkbox_input('Digest and management summary emails', 'notify_digest_emails', user_pref_enabled(user(), 'notify_digest_emails', 0)); ?>
    <?php endif; ?>

    <div class="inline-note form-inline-note">
      Need to update your password? <a href="<?= url('auth/change_password.php') ?>">Open the security screen</a>.
    </div>
    <div class="form-actions"><button class="btn primary">Save</button></div>
  </form>
</div>
<?php include __DIR__.'/../footer.php'; ?>
