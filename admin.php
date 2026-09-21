<?php
/**
 * The Slate — admin: invite codes, team members, password resets.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/page.php';

slate_install_error_handler('html');

$me = slate_current_user();
if ($me === null) {
    header('Location: login.php');
    exit;
}
if (!$me['is_admin']) {
    http_response_code(403);
    slate_page_head('Not allowed');
    echo '<div class="card"><h1>Not allowed</h1>'
        . '<p class="note">This page is for admins.</p>'
        . '<div class="note"><a href="./">Back to storyboards</a></div></div>';
    slate_page_foot();
    exit;
}

$db = slate_db();
$error = '';
$notice = '';
$freshCode = '';
$freshReset = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!slate_check_csrf($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'new_invite') {
            $label = trim((string) ($_POST['label'] ?? ''));
            $maxUses = trim((string) ($_POST['max_uses'] ?? ''));
            $days = trim((string) ($_POST['days'] ?? ''));

            // Readable but not guessable: three short words' worth of entropy.
            $code = strtolower(bin2hex(random_bytes(3)) . '-' . bin2hex(random_bytes(3))
                . '-' . bin2hex(random_bytes(3)));

            $stmt = $db->prepare(
                'INSERT INTO invite_codes (code, label, max_uses, expires_at, created_by)
                 VALUES (?, ?, ?, CASE WHEN ?::text = \'\' THEN NULL
                                       ELSE now() + (?::text || \' days\')::interval END, ?)'
            );
            $stmt->execute([
                $code,
                substr($label, 0, 80),
                $maxUses === '' ? null : max(1, (int) $maxUses),
                $days,
                $days,
                $me['id'],
            ]);
            $freshCode = $code;
            $notice = 'Invite code created. Share it with whoever needs an account.';

        } elseif ($action === 'toggle_invite') {
            $db->prepare('UPDATE invite_codes SET is_active = NOT is_active WHERE id = ?')
                ->execute([(int) ($_POST['id'] ?? 0)]);
            $notice = 'Invite code updated.';

        } elseif ($action === 'delete_invite') {
            $db->prepare('DELETE FROM invite_codes WHERE id = ?')
                ->execute([(int) ($_POST['id'] ?? 0)]);
            $notice = 'Invite code deleted.';

        } elseif ($action === 'toggle_user') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $me['id']) {
                $error = 'You cannot turn off your own account.';
            } else {
                $db->prepare('UPDATE users SET is_active = NOT is_active WHERE id = ?')->execute([$id]);
                // A disabled account should lose access immediately.
                $db->prepare('DELETE FROM sessions WHERE user_id = ? AND (SELECT NOT is_active FROM users WHERE id = ?)')
                    ->execute([$id, $id]);
                $notice = 'Account updated.';
            }

        } elseif ($action === 'toggle_admin') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id === (int) $me['id']) {
                $error = 'You cannot remove your own admin rights.';
            } else {
                $db->prepare('UPDATE users SET is_admin = NOT is_admin WHERE id = ?')->execute([$id]);
                $notice = 'Account updated.';
            }

        } elseif ($action === 'reset_user') {
            $id = (int) ($_POST['id'] ?? 0);
            $stmt = $db->prepare('SELECT username FROM users WHERE id = ?');
            $stmt->execute([$id]);
            $target = $stmt->fetch();
            if (!$target) {
                $error = 'No such account.';
            } else {
                $code = strtolower(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(4)));
                $db->prepare(
                    'INSERT INTO password_resets (user_id, code_hash, expires_at, created_by)
                     VALUES (?, ?, now() + interval \'48 hours\', ?)'
                )->execute([$id, hash('sha256', $code), $me['id']]);
                $freshReset = ['username' => $target['username'], 'code' => $code];
            }
        }
    }
}

$invites = $db->query(
    'SELECT i.*, u.username AS creator FROM invite_codes i
       LEFT JOIN users u ON u.id = i.created_by
      ORDER BY i.created_at DESC'
)->fetchAll();

$users = $db->query(
    'SELECT id, username, display_name, is_admin, is_active, created_at, last_login_at
       FROM users ORDER BY lower(username)'
)->fetchAll();

$csrf = slate_h(slate_csrf_token());

slate_page_head('Admin');
?>
<div class="card wide">
  <div class="topbar">
    <div>
      <div class="eyebrow">The Slate</div>
      <h1>Team admin</h1>
    </div>
    <div class="row">
      <a class="btn ghost small" href="./">Storyboards</a>
      <a class="btn ghost small" href="account.php">Your account</a>
    </div>
  </div>

  <?php if ($error !== ''): ?><div class="msg bad"><?= slate_h($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="msg good"><?= slate_h($notice) ?></div><?php endif; ?>

  <?php if ($freshCode !== ''): ?>
    <div class="msg good">
      New invite code: <code><?= slate_h($freshCode) ?></code><br>
      <span class="hint">People enter this on the sign-up page. It is listed below if you need it again.</span>
    </div>
  <?php endif; ?>

  <?php if ($freshReset !== null): ?>
    <div class="msg good">
      Reset code for <strong><?= slate_h($freshReset['username']) ?></strong>:
      <code><?= slate_h($freshReset['code']) ?></code><br>
      <span class="hint">
        Single use, expires in 48 hours. Pass it to them directly — this host
        cannot send email. <strong>It is not shown again.</strong>
      </span>
    </div>
  <?php endif; ?>

  <h2>Invite codes</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="new_invite">
    <div class="row" style="align-items:flex-end">
      <label style="flex:1 1 200px;margin-bottom:0">
        <span>What it's for</span>
        <input type="text" name="label" placeholder="Filmmaking team, autumn">
      </label>
      <label style="width:110px;margin-bottom:0">
        <span>Max uses</span>
        <input type="number" name="max_uses" min="1" placeholder="any">
      </label>
      <label style="width:110px;margin-bottom:0">
        <span>Expires (days)</span>
        <input type="number" name="days" min="1" placeholder="never">
      </label>
      <button type="submit">Create code</button>
    </div>
  </form>

  <table style="margin-top:18px">
    <tr><th>Code</th><th>For</th><th>Used</th><th>Expires</th><th>Status</th><th></th></tr>
    <?php foreach ($invites as $i): ?>
      <tr>
        <td><code><?= slate_h($i['code']) ?></code></td>
        <td><?= slate_h($i['label']) ?></td>
        <td><?= (int) $i['uses'] ?><?= $i['max_uses'] === null ? '' : ' / ' . (int) $i['max_uses'] ?></td>
        <td><?= $i['expires_at'] === null ? 'never' : slate_h(substr((string) $i['expires_at'], 0, 10)) ?></td>
        <td><?= $i['is_active'] ? 'active' : 'off' ?></td>
        <td>
          <div class="row">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="toggle_invite">
              <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
              <button class="ghost small" type="submit"><?= $i['is_active'] ? 'Turn off' : 'Turn on' ?></button>
            </form>
            <form method="post" onsubmit="return confirm('Delete this invite code?')">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete_invite">
              <input type="hidden" name="id" value="<?= (int) $i['id'] ?>">
              <button class="ghost small" type="submit">Delete</button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$invites): ?>
      <tr><td colspan="6" class="hint">No codes yet. Create one so the team can sign up.</td></tr>
    <?php endif; ?>
  </table>

  <h2>Team</h2>
  <table>
    <tr><th>Username</th><th>Name</th><th>Role</th><th>Status</th><th>Last signed in</th><th></th></tr>
    <?php foreach ($users as $u): ?>
      <tr>
        <td><?= slate_h($u['username']) ?><?= (int) $u['id'] === (int) $me['id'] ? ' (you)' : '' ?></td>
        <td><?= slate_h($u['display_name']) ?></td>
        <td><?= $u['is_admin'] ? 'admin' : 'member' ?></td>
        <td><?= $u['is_active'] ? 'active' : 'off' ?></td>
        <td><?= $u['last_login_at'] === null ? 'never' : slate_h(substr((string) $u['last_login_at'], 0, 16)) ?></td>
        <td>
          <div class="row">
            <form method="post">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="reset_user">
              <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
              <button class="ghost small" type="submit">Reset code</button>
            </form>
            <?php if ((int) $u['id'] !== (int) $me['id']): ?>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="toggle_admin">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="ghost small" type="submit"><?= $u['is_admin'] ? 'Make member' : 'Make admin' ?></button>
              </form>
              <form method="post">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="toggle_user">
                <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                <button class="ghost small" type="submit"><?= $u['is_active'] ? 'Turn off' : 'Turn on' ?></button>
              </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
  </table>
  <p class="hint" style="margin-top:12px">
    Turning an account off signs it out immediately and blocks sign-in, but
    leaves the storyboards it saved in the library.
  </p>
</div>
<?php
slate_page_foot();
