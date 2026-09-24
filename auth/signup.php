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

fm_error_handler('html');

$next = fm_safe_next($_REQUEST['next'] ?? null);

if (fm_current_user() !== null) {
    header('Location: ' . $next);
    exit;
}

$error = '';
$username = '';
$displayName = '';
$firstRun = fm_user_count() === 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $displayName = trim((string) ($_POST['display_name'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    $code = trim((string) ($_POST['invite'] ?? ''));

    if (fm_throttled('signup')) {
        $error = 'Too many attempts from this connection. Wait a few minutes and try again.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } elseif (strlen($password) < FM_MIN_PASSWORD) {
        $error = 'Use at least ' . FM_MIN_PASSWORD . ' characters for your password.';
    } elseif (!fm_valid_username($username)) {
        $error = 'Usernames are 3–32 characters: letters, numbers, dot, dash or underscore.';
    } else {
        // The first account is authorised by the config file, not the database
        // — there is no admin yet to have issued a code.
        $bootstrap = (string) (fm_config()['bootstrap_code'] ?? '');
        $usedBootstrap = $firstRun && $bootstrap !== '' && hash_equals($bootstrap, $code);
        $accepted = $usedBootstrap || (!$firstRun && fm_redeem_invite($code));

        if (!$accepted) {
            fm_record_attempt('signup', $code, false);
            $error = $firstRun
                ? 'That setup code is not right.'
                : 'That invite code is not valid. Check it with whoever sent it.';
        } else {
            [$ok, $err] = fm_create_user($username, $displayName, $password, $usedBootstrap);
            if ($ok) {
                fm_record_attempt('signup', $code, true);
                fm_attempt_login($username, $password);
                header('Location: ' . $next);
                exit;
            }
            if (!$usedBootstrap) {
                fm_release_invite($code);
            }
            fm_record_attempt('signup', $code, false);
            $error = [
                'username_taken' => 'That username is already taken.',
                'bad_username' => 'Usernames are 3–32 characters: letters, numbers, dot, dash or underscore.',
                'weak_password' => 'Use at least ' . FM_MIN_PASSWORD . ' characters for your password.',
            ][$err] ?? 'Could not create that account.';
        }
    }
}

fm_page_head($firstRun ? 'Set up' : 'Sign up');
?>
<div class="card">
  <div class="eyebrow">Filmmaking tools</div>
  <h1><?= $firstRun ? 'First account' : 'Sign up' ?></h1>

  <?php if ($error !== ''): ?>
    <div class="msg bad"><?= fm_h($error) ?></div>
  <?php endif; ?>

  <?php if ($firstRun): ?>
    <div class="msg good">
      No accounts exist yet, so this one becomes the admin. Use the
      <code>bootstrap_code</code> from the config file. It stops working once
      this account exists.
    </div>
  <?php endif; ?>

  <form method="post" autocomplete="on">
    <input type="hidden" name="next" value="<?= fm_h($next) ?>">
    <label>
      <span><?= $firstRun ? 'Setup code' : 'Invite code' ?></span>
      <input type="text" name="invite" autocapitalize="none" autocorrect="off" required autofocus>
    </label>
    <label>
      <span>Your name</span>
      <input type="text" name="display_name" value="<?= fm_h($displayName) ?>"
             autocomplete="name" placeholder="Camden Goff">
    </label>
    <div class="hint">Shown on the storyboards you save.</div>
    <label>
      <span>Username</span>
      <input type="text" name="username" value="<?= fm_h($username) ?>"
             autocapitalize="none" autocorrect="off" autocomplete="username" required>
    </label>
    <label>
      <span>Password</span>
      <input type="password" name="password" autocomplete="new-password" required>
    </label>
    <div class="hint">At least <?= FM_MIN_PASSWORD ?> characters.</div>
    <label>
      <span>Confirm password</span>
      <input type="password" name="confirm" autocomplete="new-password" required>
    </label>
    <button type="submit">Create account</button>
  </form>

  <div class="note">Already have an account? <a href="login.php?next=<?= rawurlencode($next) ?>">Sign in</a>.</div>
</div>
<?php
fm_page_foot();
