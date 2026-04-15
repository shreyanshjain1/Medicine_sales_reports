<?php
require_once __DIR__.'/../../init.php'; require_login();
$u=user(); $ok=false; $err=''; $errors=[]; $fieldErrors=[];
if($_SERVER['REQUEST_METHOD']==='POST'){
  csrf_verify();
  $name=trim(post('name','')); $email=normalize_email(trim(post('email','')));
  $wantsEmail = isset($_POST['wants_email_notifications']) ? 1 : 0;
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
      $stmt=$mysqli->prepare('UPDATE users SET name=?, email=?, wants_email_notifications=? WHERE id=?');
      $stmt->bind_param('ssii',$name,$email,$wantsEmail,$u['id']);
      if($stmt->execute()){
        $_SESSION['user']['name']=$name; $_SESSION['user']['email']=$email; $_SESSION['user']['wants_email_notifications']=$wantsEmail; $ok=true;
        log_audit('profile_updated', 'user', (int)$u['id'], 'Profile details updated');
      } else $err='Update failed.';
      $stmt->close();
    }
  }
}
$title='Profile'; include __DIR__.'/../header.php';
?>
<div class="card"><h2>My Profile</h2>
  <?php form_messages($errors, [], $ok ? 'Updated.' : ''); ?>
  <?php if($err && !$errors): ?><div class="alert danger form-alert"><?= e($err) ?></div><?php endif; ?>
  <form method="post" class="form crm-form max-640"><?php csrf_input(); ?>
    <div class="grid two"><?php render_text_input('Name', 'name', (string)user()['name'], ['required'=>true], $fieldErrors); ?><?php render_text_input('Email', 'email', (string)user()['email'], ['type'=>'email','required'=>true], $fieldErrors); ?></div>
    <?php render_checkbox_input('Email me about report reviews, new tasks, and account recovery', 'wants_email_notifications', !isset(user()['wants_email_notifications']) || (int)(user()['wants_email_notifications'] ?? 1)===1); ?>
    <div class="inline-note form-inline-note">
      Need to update your password? <a href="<?= url('auth/change_password.php') ?>">Open the security screen</a>.
    </div>
    <div class="form-actions"><button class="btn primary">Save</button></div>
  </form>
</div>
<?php include __DIR__.'/../footer.php'; ?>