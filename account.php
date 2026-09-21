<?php
/**
 * The Slate — your own account.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/page.php';

slate_install_error_handler('html');

$user = slate_current_user();
if ($user === null) {
    header('Location: login.php');
    exit;
}

$error = '';
$notice = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!slate_check_csrf($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } elseif (($_POST['action'] ?? '') === 'password') {
        $current = (string) ($_POST['current'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        $confirm = (string) ($_POST['confirm'] ?? '');

        if (!password_verify($current, (string) $user['password_hash'])) {
            $error = 'Your current password is not right.';
        } elseif ($password !== $confirm) {
            $error = 'The two new passwords do not match.';
        } elseif (!slate_set_password((int) $user['id'], $password)) {
            $error = 'Use at least ' . SLATE_MIN_PASSWORD . ' characters for your new password.';
        } else {
            // Keep this browser signed in, drop every other device.
            slate_end_all_sessions((int) $user['id'], true);
            $notice = 'Password changed. Other devices have been signed out.';
        }
    } elseif (($_POST['action'] ?? '') === 'name') {
        $name = trim((string) ($_POST['display_name'] ?? ''));
        if ($name === '') {
            $error = 'Give a name for the team to see.';
        } else {
            slate_db()->prepare('UPDATE users SET display_name = ? WHERE id = ?')
                ->execute([substr($name, 0, 80), $user['id']]);
            $notice = 'Name updated.';
            $user['display_name'] = $name;
        }
    }
}

$sessions = slate_db()->prepare(
    'SELECT created_at, last_seen_at, ip, user_agent FROM sessions
      WHERE user_id = ? ORDER BY last_seen_at DESC'
);
$sessions->execute([$user['id']]);
$sessions = $sessions->fetchAll();

slate_page_head('Your account');
?>
<div class="card wide">
  <div class="topbar">
    <div>
      <div class="eyebrow">The Slate</div>
      <h1>Your account</h1>
    </div>
    <div class="row">
      <a class="btn ghost small" href="./">Back to storyboards</a>
      <form method="post" action="logout.php">
        <input type="hidden" name="csrf" value="<?= slate_h(slate_csrf_token()) ?>">
        <button class="ghost small" type="submit">Sign out</button>
      </form>
    </div>
  </div>

  <?php if ($error !== ''): ?><div class="msg bad"><?= slate_h($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="msg good"><?= slate_h($notice) ?></div><?php endif; ?>

  <p class="note" style="margin-top:0">
    Signed in as <strong><?= slate_h($user['username']) ?></strong><?php
      if ($user['is_admin']) { echo ' · admin (<a href="admin.php">manage the team</a>)'; }
    ?>
  </p>

  <h2>Display name</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= slate_h(slate_csrf_token()) ?>">
    <input type="hidden" name="action" value="name">
    <label>
      <span>Name the team sees</span>
      <input type="text" name="display_name" value="<?= slate_h($user['display_name']) ?>" required>
    </label>
    <button type="submit">Save name</button>
  </form>

  <h2>Change password</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= slate_h(slate_csrf_token()) ?>">
    <input type="hidden" name="action" value="password">
    <label>
      <span>Current password</span>
      <input type="password" name="current" autocomplete="current-password" required>
    </label>
    <label>
      <span>New password</span>
      <input type="password" name="password" autocomplete="new-password" required>
    </label>
    <div class="hint">At least <?= SLATE_MIN_PASSWORD ?> characters.</div>
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
        <td><?= slate_h(substr((string) $s['last_seen_at'], 0, 16)) ?></td>
        <td><?= slate_h(substr((string) $s['created_at'], 0, 10)) ?></td>
        <td><?= slate_h($s['ip']) ?></td>
        <td><?= slate_h(substr((string) $s['user_agent'], 0, 60)) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="hint" style="margin-top:12px">
    Changing your password signs out every device except this one.
  </p>
</div>
<?php
slate_page_foot();
