<?php
/**
 * The Slate — your own account.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/page.php';

fm_error_handler('html');

$next = fm_safe_next($_REQUEST['next'] ?? null);

$user = fm_current_user();
if ($user === null) {
    header('Location: ' . fm_auth_url_with_next('login.php', $next));
    exit;
}

$error = '';
$notice = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!fm_check_csrf($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } elseif (($_POST['action'] ?? '') === 'password') {
        $current = (string) ($_POST['current'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        if (!password_verify($current, (string) $user['password_hash'])) {
            $error = 'Your current password is not right.';
        } elseif ($password !== $confirm) {
            $error = 'The two new passwords do not match.';
        } elseif (!fm_set_password((int) $user['id'], $password)) {
            $error = 'Use at least ' . FM_MIN_PASSWORD . ' characters for your new password.';
        } else {
            // Keep this browser signed in, drop every other device.
            fm_end_all_sessions((int) $user['id'], true);
            $notice = 'Password changed. Other devices have been signed out.';
        }
    } elseif (($_POST['action'] ?? '') === 'name') {
        $name = trim((string) ($_POST['display_name'] ?? ''));
        if ($name === '') {
            $error = 'Give a name for the team to see.';
        } else {
            fm_db()->prepare('UPDATE users SET display_name = ? WHERE id = ?')
                ->execute([substr($name, 0, 80), $user['id']]);
            $notice = 'Name updated.';
            $user['display_name'] = $name;
        }
    }
}

$sessions = fm_db()->prepare(
    'SELECT created_at, last_seen_at, ip, user_agent FROM sessions
      WHERE user_id = ? ORDER BY last_seen_at DESC'
);
$sessions->execute([$user['id']]);
$sessions = $sessions->fetchAll();

fm_page_head('Your account');
?>
<div class="card wide">
  <div class="eyebrow">Filmmaking tools</div>
  <h1>Your account</h1>

  <?php if ($error !== ''): ?><div class="msg bad"><?= fm_h($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="msg good"><?= fm_h($notice) ?></div><?php endif; ?>

  <p class="note" style="margin-top:0">
    Signed in as <strong><?= fm_h($user['username']) ?></strong><?php
      if ($user['is_admin']) { echo ' · admin (<a href="admin.php">manage the team</a>)'; }
    ?>
  </p>

  <h2>Display name</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= fm_h(fm_csrf_token()) ?>">
    <input type="hidden" name="action" value="name">
    <label>
      <span>Name the team sees</span>
      <input type="text" name="display_name" value="<?= fm_h($user['display_name']) ?>" required>
    </label>
    <button type="submit">Save name</button>
  </form>

  <h2>Change password</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= fm_h(fm_csrf_token()) ?>">
    <input type="hidden" name="action" value="password">
    <label>
      <span>Current password</span>
      <input type="password" name="current" autocomplete="current-password" required>
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
    <button type="submit">Change password</button>
  </form>

  <h2>Signed-in devices</h2>
  <table>
    <tr><th>Last used</th><th>Since</th><th>Address</th><th>Browser</th></tr>
    <?php foreach ($sessions as $s): ?>
      <tr>
        <td><?= fm_h(substr((string) $s['last_seen_at'], 0, 16)) ?></td>
        <td><?= fm_h(substr((string) $s['created_at'], 0, 10)) ?></td>
        <td><?= fm_h($s['ip']) ?></td>
        <td><?= fm_h(substr((string) $s['user_agent'], 0, 60)) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="hint" style="margin-top:12px">
    Changing your password signs out every device except this one.
  </p>
</div>
<?php
fm_page_foot();
