<?php
/**
 * The Slate — sign in.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/page.php';

slate_install_error_handler('html');

if (slate_current_user() !== null) {
    header('Location: ./');
    exit;
}

$error = '';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = (string) ($_POST['username'] ?? '');
    $password = (string) ($_POST['password'] ?? '');

    [$ok, $err] = slate_attempt_login($username, $password);
    if ($ok) {
        header('Location: ./');
        exit;
    }
    $error = [
        'throttled' => 'Too many attempts. Wait a few minutes and try again.',
        'disabled' => 'That account has been turned off. Ask an admin to re-enable it.',
    ][$err] ?? 'That username and password do not match.';
}

$firstRun = slate_user_count() === 0;

slate_page_head('Sign in');
?>
<div class="card">
  <div class="eyebrow">The Slate</div>
  <h1>Sign in</h1>

  <?php if ($error !== ''): ?>
    <div class="msg bad"><?= slate_h($error) ?></div>
  <?php endif; ?>

  <?php if ($firstRun): ?>
    <div class="msg good">
      No accounts exist yet. <a href="signup.php">Create the first one</a> using the
      setup code from the config file — it becomes the admin.
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <label>
      <span>Username</span>
      <input type="text" name="username" autocapitalize="none" autocorrect="off"
             autocomplete="username" required autofocus>
    </label>
    <label>
      <span>Password</span>
      <input type="password" name="password" autocomplete="current-password" required>
    </label>
    <button type="submit">Sign in</button>
  </form>

  <div class="note">
    Need an account? <a href="signup.php">Sign up with an invite code</a>.<br>
    Forgotten your password? Ask an admin for a reset code, then
    <a href="reset.php">use it here</a>.
  </div>
</div>
<?php
slate_page_foot();
