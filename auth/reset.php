<?php
/**
 * The Slate — redeem a password reset code.
 *
 * There is no outbound mail on this host, so resets are not emailed links: an
 * admin generates a one-time code on the admin page and passes it to the
 * person directly. The code is single-use and expires.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/page.php';

fm_error_handler('html');

$error = '';
$done = false;
$username = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $username = trim((string) ($_POST['username'] ?? ''));
    $code = trim((string) ($_POST['code'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');

    if (fm_throttled('reset', $username)) {
        $error = 'Too many attempts. Wait a few minutes and try again.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } elseif (strlen($password) < FM_MIN_PASSWORD) {
        $error = 'Use at least ' . FM_MIN_PASSWORD . ' characters for your password.';
    } else {
        $user = fm_find_user($username);
        $matched = null;

        if ($user) {
            $stmt = fm_db()->prepare(
                'SELECT * FROM password_resets
                  WHERE user_id = ? AND used_at IS NULL AND expires_at > now()
                  ORDER BY created_at DESC'
            );
            $stmt->execute([$user['id']]);
            foreach ($stmt->fetchAll() as $row) {
                if (hash_equals((string) $row['code_hash'], hash('sha256', $code))) {
                    $matched = $row;
                    break;
                }
            }
        }

        if ($matched === null) {
            fm_record_attempt('reset', $username, false);
            $error = 'That reset code is not valid for this account, or it has expired.';
        } else {
            fm_set_password((int) $user['id'], $password);
            fm_db()->prepare('UPDATE password_resets SET used_at = now() WHERE id = ?')
                ->execute([$matched['id']]);
            // Anyone holding an old session for this account loses it.
            fm_end_all_sessions((int) $user['id']);
            fm_record_attempt('reset', $username, true);
            $done = true;
        }
    }
}

fm_page_head('Reset password');
?>
<div class="card">
  <div class="eyebrow">Filmmaking tools</div>
  <h1>Reset password</h1>

  <?php if ($done): ?>
    <div class="msg good">
      Password changed. Any other devices signed in as this account have been
      signed out.
    </div>
    <a class="btn" href="login.php">Sign in</a>
  <?php else: ?>
    <?php if ($error !== ''): ?>
      <div class="msg bad"><?= fm_h($error) ?></div>
    <?php endif; ?>

    <div class="msg">
      Ask an admin to generate a reset code for you. Codes are single use and
      expire after 48 hours.
    </div>

    <form method="post">
      <label>
        <span>Username</span>
        <input type="text" name="username" value="<?= fm_h($username) ?>"
               autocapitalize="none" autocorrect="off" required autofocus>
      </label>
      <label>
        <span>Reset code</span>
        <input type="text" name="code" autocapitalize="none" autocorrect="off" required>
      </label>
      <label>
        <span>New password</span>
        <input type="password" name="password" autocomplete="new-password" required>
      </label>
      <div class="hint">At least <?= FM_MIN_PASSWORD ?> characters.</div>
      <label>
        <span>Confirm new password</span>
        <input type="password" name="confirm" autocomplete="new-password" required>
      </label>
      <button type="submit">Set new password</button>
    </form>

    <div class="note"><a href="login.php">Back to sign in</a></div>
  <?php endif; ?>
</div>
<?php
fm_page_foot();
