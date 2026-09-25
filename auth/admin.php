<?php
/**
 * Filmmaking tools — admin: invite codes and the team list.
 *
 * Editing one person, their access and their codes is person.php; bringing
 * a team over from a CSV is import.php.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/people.php';
require __DIR__ . '/page.php';

fm_error_handler('html');

$me = fm_require_admin();

$db = fm_db();
$error = '';
$notice = '';
$freshCode = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!fm_check_csrf($_POST['csrf'] ?? null)) {
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

        }
    }
}

$invites = $db->query(
    'SELECT i.*, u.username AS creator FROM invite_codes i
       LEFT JOIN users u ON u.id = i.created_by
      ORDER BY i.created_at DESC'
)->fetchAll();

$users = $db->query(
    "SELECT id, username, display_name, email, department, is_admin, is_active,
            password_hash = '' AS pending, created_at, last_login_at
       FROM users ORDER BY lower(display_name), lower(username)"
)->fetchAll();

$csrf = fm_h(fm_csrf_token());

fm_page_head('Admin');
?>
<div class="card wider">
  <div class="eyebrow">Filmmaking tools</div>
  <h1>Team admin</h1>

  <?php if ($error !== ''): ?><div class="msg bad"><?= fm_h($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="msg good"><?= fm_h($notice) ?></div><?php endif; ?>

  <?php if ($freshCode !== ''): ?>
    <div class="msg good">
      New invite code: <code><?= fm_h($freshCode) ?></code><br>
      <span class="hint">People enter this on the sign-up page. It is listed below if you need it again.</span>
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
        <td><code><?= fm_h($i['code']) ?></code></td>
        <td><?= fm_h($i['label']) ?></td>
        <td><?= (int) $i['uses'] ?><?= $i['max_uses'] === null ? '' : ' / ' . (int) $i['max_uses'] ?></td>
        <td><?= $i['expires_at'] === null ? 'never' : fm_h(substr((string) $i['expires_at'], 0, 10)) ?></td>
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

  <div class="topbar" style="margin-top:30px">
    <h2 style="margin:0">Team</h2>
    <div class="row">
      <a class="btn small ghost" href="person.php">Add person</a>
      <a class="btn small" href="import.php">Import from CSV</a>
    </div>
  </div>
  <div class="scroll" style="margin-top:12px">
  <table>
    <tr><th>Name</th><th>Username</th><th>Email</th>
      <?php foreach (fm_tools() as $tool): ?><th><?= fm_h($tool['label']) ?></th><?php endforeach; ?>
      <th>Status</th><th>Last signed in</th></tr>
    <?php foreach ($users as $u): ?>
      <?php $toolRoles = fm_tool_roles((int) $u['id']); ?>
      <tr>
        <td><a href="person.php?id=<?= (int) $u['id'] ?>" style="color:inherit;font-weight:700"><?= fm_h($u['display_name']) ?></a><?php
          if ((int) $u['id'] === (int) $me['id']) { echo ' <span class="hint">(you)</span>'; } ?>
          <?php if ($u['department']): ?><div class="hint" style="margin:0"><?= fm_h($u['department']) ?></div><?php endif; ?></td>
        <td class="small"><?= fm_h($u['username']) ?></td>
        <td class="small"><?= $u['email'] ? fm_h($u['email']) : '<span class="tag bad">none</span>' ?></td>
        <?php foreach (fm_tools() as $tool): ?>
          <td class="small"><?= fm_h(fm_role_label($tool['key'], $toolRoles[$tool['key']] ?? FM_NO_ACCESS)) ?></td>
        <?php endforeach; ?>
        <td><?php
          if (!$u['is_active']) { echo '<span class="tag bad">Off</span>'; }
          elseif ($u['pending']) { echo '<span class="tag">Not set up</span>'; }
          else { echo '<span class="tag good">Active</span>'; }
          if ($u['is_admin']) { echo ' <span class="tag">Admin</span>'; }
        ?></td>
        <td class="small"><?= $u['last_login_at'] === null ? 'never' : fm_h(substr((string) $u['last_login_at'], 0, 16)) ?></td>
      </tr>
    <?php endforeach; ?>
  </table>
  </div>
  <p class="hint" style="margin-top:12px">
    Click a name to edit their details and access, issue a setup or reset
    code, or turn the account off. Turning an account off signs it out
    everywhere and blocks sign-in, but leaves the storyboards it saved.
  </p>
</div>
<?php
fm_page_foot();
