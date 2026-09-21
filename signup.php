<?php
/**
 * The Slate — create an account.
 *
 * Gated by a shared invite code from the admin page. While the users table is
 * empty the bootstrap code from the config file is accepted instead, and that
 * first account becomes the admin.
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
$username = '';
$displayName = '';
$firstRun = slate_user_count() === 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $displayName = trim((string) ($_POST['display_name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    $code = trim((string) ($_POST['invite'] ?? ''));

    if (slate_throttled('signup')) {
        $error = 'Too many attempts from this connection. Wait a few minutes and try again.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } elseif (strlen($password) < SLATE_MIN_PASSWORD) {
        $error = 'Use at least ' . SLATE_MIN_PASSWORD . ' characters for your password.';
    } elseif (!slate_valid_username($username)) {
        $error = 'Usernames are 3–32 characters: letters, numbers, dot, dash or underscore.';
    } else {
        // The first account is authorised by the config file, not the database
        // — there is no admin yet to have issued a code.
        $bootstrap = (string) (slate_config()['bootstrap_code'] ?? '');
        $usedBootstrap = $firstRun && $bootstrap !== '' && hash_equals($bootstrap, $code);
        $accepted = $usedBootstrap || (!$firstRun && slate_redeem_invite($code));

        if (!$accepted) {
            slate_record_attempt('signup', $code, false);
            $error = $firstRun
                ? 'That setup code is not right.'
                : 'That invite code is not valid. Check it with whoever sent it.';
        } else {
            [$ok, $err] = slate_create_user($username, $displayName, $password, $usedBootstrap);
            if ($ok) {
                slate_record_attempt('signup', $code, true);
                slate_attempt_login($username, $password);
                header('Location: ./');
                exit;
            }
            if (!$usedBootstrap) {
                slate_release_invite($code);
            }
            slate_record_attempt('signup', $code, false);
            $error = [
                'username_taken' => 'That username is already taken.',
                'bad_username' => 'Usernames are 3–32 characters: letters, numbers, dot, dash or underscore.',
                'weak_password' => 'Use at least ' . SLATE_MIN_PASSWORD . ' characters for your password.',
            ][$err] ?? 'Could not create that account.';
        }
    }
}

slate_page_head($firstRun ? 'Set up' : 'Sign up');
?>
<div class="card">
  <div class="eyebrow">The Slate</div>
  <h1><?= $firstRun ? 'First account' : 'Sign up' ?></h1>

  <?php if ($error !== ''): ?>
    <div class="msg bad"><?= slate_h($error) ?></div>
  <?php endif; ?>

  <?php if ($firstRun): ?>
    <div class="msg good">
      No accounts exist yet, so this one becomes the admin. Use the
      <code>bootstrap_code</code> from the config file. It stops working once
      this account exists.
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <label>
      <span><?= $firstRun ? 'Setup code' : 'Invite code' ?></span>
      <input type="text" name="invite" autocapitalize="none" autocorrect="off" required autofocus>
    </label>
    <label>
      <span>Your name</span>
      <input type="text" name="display_name" value="<?= slate_h($displayName) ?>"
             autocomplete="name" placeholder="Camden Goff">
    </label>
    <div class="hint">Shown on the storyboards you save.</div>
    <label>
      <span>Username</span>
      <input type="text" name="username" value="<?= slate_h($username) ?>"
             autocapitalize="none" autocorrect="off" autocomplete="username" required>
    </label>
    <label>
      <span>Password</span>
      <input type="password" name="password" autocomplete="new-password" required>
    </label>
    <div class="hint">At least <?= SLATE_MIN_PASSWORD ?> characters.</div>
    <label>
      <span>Confirm password</span>
      <input type="password" name="confirm" autocomplete="new-password" required>
    </label>
    <button type="submit">Create account</button>
  </form>

  <div class="note">Already have an account? <a href="login.php">Sign in</a>.</div>
</div>
<?php
slate_page_foot();
