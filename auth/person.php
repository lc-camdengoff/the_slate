<?php
/**
 * Filmmaking tools — one person: their details, what they can use, and the
 * codes that let them in.
 *
 *   person.php          add someone by hand (they get a setup code)
 *   person.php?id=12    edit an existing account
 *
 * The username cannot be changed once an account exists. Storyboards record
 * their owner by username, so renaming would quietly orphan every private
 * board that person has.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/people.php';
require __DIR__ . '/page.php';

fm_error_handler('html');

$me = fm_require_admin();
$db = fm_db();

$load = static function (int $id) use ($db): ?array {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch() ?: null;
};

$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$target = $id > 0 ? $load($id) : null;
if ($id > 0 && $target === null) {
    http_response_code(404);
    fm_page_head('Not found');
    echo '<div class="card"><h1>Not found</h1><p class="note">No such account. '
        . '<a href="admin.php">Back to Team admin</a></p></div>';
    fm_page_foot();
    exit;
}
$isNew = $target === null;
$isMe = !$isNew && (int) $target['id'] === (int) $me['id'];

$error = '';
$notice = '';
$freshCode = null;
$fields = fm_person_fields();

// What the form shows: the account as stored, or what was just typed.
$form = [
    'username' => $isNew ? '' : (string) $target['username'],
    'email' => $isNew ? '' : (string) ($target['email'] ?? ''),
];
foreach ($fields as $k => $_) {
    $form[$k] = $isNew ? '' : (string) ($target[$k] ?? '');
}
$roles = $isNew
    ? array_combine(array_column(fm_tools(), 'key'), array_column(fm_tools(), 'default_role'))
    : fm_tool_roles((int) $target['id']);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!fm_check_csrf($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';

    } elseif ($action === 'save') {
        foreach ($fields as $k => $f) {
            $form[$k] = fm_clean_text((string) ($_POST[$k] ?? ''), $f['max']);
        }
        $form['email'] = strtolower(trim((string) ($_POST['email'] ?? '')));
        if ($isNew) {
            $form['username'] = trim((string) ($_POST['username'] ?? ''));
        }
        foreach (fm_tools() as $tool) {
            $want = (string) ($_POST['role'][$tool['key']] ?? '');
            if (fm_valid_tool_role($tool['key'], $want)) {
                $roles[$tool['key']] = $want;
            }
        }
        $wantAdmin = !empty($_POST['is_admin']);
        $wantActive = !empty($_POST['is_active']);

        $emailProblem = ($isNew || $form['email'] !== '') ? fm_email_problem($form['email']) : '';

        if ($form['display_name'] === '') {
            $error = 'Give them a name.';
        } elseif ($emailProblem !== '') {
            $error = $emailProblem === 'Enter your email address.' ? 'Enter their email address.' : $emailProblem;
        } elseif ($form['email'] !== '' && fm_email_taken($form['email'], $isNew ? '' : (string) $target['username'])) {
            $error = 'That email address is already on another account.';
        } elseif ($isNew && $form['username'] !== '' && !fm_valid_username($form['username'])) {
            $error = 'Usernames are 3–32 characters: letters, numbers, dot, dash or underscore.';
        } elseif ($isNew) {
            $taken = fm_taken_usernames();
            if ($form['username'] !== '' && isset($taken[strtolower($form['username'])])) {
                $error = 'That username is already taken.';
            } else {
                $username = $form['username'] !== ''
                    ? $form['username']
                    : fm_pick_username('', $form['email'], $form['display_name'], $taken);
                [$newId, $err] = fm_create_pending_user($username, $form);
                if ($newId === 0) {
                    $error = $err === 'email_taken'
                        ? 'That email address is already on another account.'
                        : 'That username is already taken.';
                } else {
                    foreach ($roles as $key => $role) {
                        fm_set_tool_role($newId, $key, $role);
                    }
                    if ($wantAdmin) {
                        $stmt = $db->prepare('UPDATE users SET is_admin = ? WHERE id = ?');
                        fm_bind_bool($stmt, 1, true);
                        $stmt->bindValue(2, $newId);
                        $stmt->execute();
                    }
                    $freshCode = fm_issue_code($newId, (int) $me['id'], FM_SETUP_CODE_HOURS);
                    $target = $load($newId);
                    $isNew = false;
                    $notice = 'Account created for ' . $target['display_name'] . '.';
                }
            }
        } else {
            // Nobody locks themselves out from here: your own admin rights and
            // your own account stay on whatever the form says.
            if ($isMe) {
                $wantAdmin = true;
                $wantActive = true;
            }
            $opt = static fn(string $v) => $v === '' ? null : $v;
            $stmt = $db->prepare(
                'UPDATE users SET display_name = ?, email = ?, phone = ?, department = ?, notes = ?,
                                  is_admin = ?, is_active = ?
                  WHERE id = ?'
            );
            $stmt->bindValue(1, $form['display_name']);
            $stmt->bindValue(2, $opt($form['email']));
            $stmt->bindValue(3, $opt($form['phone']));
            $stmt->bindValue(4, $opt($form['department']));
            $stmt->bindValue(5, $opt($form['notes']));
            fm_bind_bool($stmt, 6, $wantAdmin);
            fm_bind_bool($stmt, 7, $wantActive);
            $stmt->bindValue(8, (int) $target['id']);
            try {
                $stmt->execute();
                foreach ($roles as $key => $role) {
                    fm_set_tool_role((int) $target['id'], $key, $role);
                }
                if (!$wantActive) {
                    // A turned-off account loses access now, not at next sign-in.
                    $db->prepare('DELETE FROM sessions WHERE user_id = ?')->execute([(int) $target['id']]);
                }
                $target = $load((int) $target['id']);
                $notice = 'Saved.';
            } catch (PDOException $e) {
                if ($e->getCode() !== '23505') {
                    throw $e;
                }
                $error = 'That email address is already on another account.';
            }
        }

    } elseif ($action === 'code' && !$isNew) {
        $hours = fm_has_password($target) ? FM_RESET_CODE_HOURS : FM_SETUP_CODE_HOURS;
        $freshCode = fm_issue_code((int) $target['id'], (int) $me['id'], $hours);

    } elseif ($action === 'signout' && !$isNew) {
        fm_end_all_sessions((int) $target['id'], $isMe);
        $notice = $isMe ? 'Signed out everywhere except here.' : 'Signed out everywhere.';
    }
}

if (!$isNew) {
    $roles = $error === '' ? fm_tool_roles((int) $target['id'], true) : $roles;
    if ($error === '') {
        $form['username'] = (string) $target['username'];
        $form['email'] = (string) ($target['email'] ?? '');
        foreach ($fields as $k => $_) {
            $form[$k] = (string) ($target[$k] ?? '');
        }
    }
    $stmt = $db->prepare('SELECT count(*) AS n FROM sessions WHERE user_id = ? AND expires_at > now()');
    $stmt->execute([(int) $target['id']]);
    $liveSessions = (int) $stmt->fetch()['n'];
}

$csrf = fm_h(fm_csrf_token());
$pending = !$isNew && !fm_has_password($target);

fm_page_head($isNew ? 'Add person' : (string) $target['display_name']);
?>
<div class="card wide">
  <div class="eyebrow"><a href="admin.php" style="color:inherit">Team admin</a> / <?= $isNew ? 'Add person' : 'Person' ?></div>
  <h1><?= $isNew ? 'Add person' : fm_h((string) $target['display_name']) ?></h1>

  <?php if ($error !== ''): ?><div class="msg bad"><?= fm_h($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="msg good"><?= fm_h($notice) ?></div><?php endif; ?>

  <?php if ($freshCode !== null): ?>
    <div class="msg good">
      <?= $pending ? 'Setup' : 'Reset' ?> code for <strong><?= fm_h((string) $target['username']) ?></strong>:
      <code><?= fm_h($freshCode) ?></code><br>
      Send them to <code><?= fm_h(fm_setup_url((string) $target['username'])) ?></code>
      <div class="hint" style="margin:6px 0 0">
        Works once and expires in <?= $pending ? intdiv(FM_SETUP_CODE_HOURS, 24) . ' days' : FM_RESET_CODE_HOURS . ' hours' ?>.
        This host can't send email, so pass it to them directly.
        <strong>It is not shown again.</strong>
      </div>
    </div>
  <?php endif; ?>

  <?php if (!$isNew): ?>
    <p class="note" style="margin-top:0">
      <strong><?= fm_h((string) $target['username']) ?></strong> ·
      <?php if ($pending): ?><span class="tag">Not set up yet</span>
      <?php elseif (!$target['is_active']): ?><span class="tag bad">Off</span>
      <?php else: ?><span class="tag good">Active</span><?php endif; ?>
      · last signed in <?= $target['last_login_at'] === null ? 'never' : fm_h(substr((string) $target['last_login_at'], 0, 16)) ?>
      · <?= $liveSessions ?> device<?= $liveSessions === 1 ? '' : 's' ?> signed in
    </p>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="id" value="<?= $isNew ? 0 : (int) $target['id'] ?>">

    <h2>Details</h2>
    <div class="grid2">
      <label>
        <span>Name</span>
        <input type="text" name="display_name" value="<?= fm_h($form['display_name']) ?>" maxlength="80" required>
      </label>
      <label>
        <span>Email<?= $isNew ? '' : ' (The Cage needs one)' ?></span>
        <input type="email" name="email" value="<?= fm_h($form['email']) ?>" autocapitalize="none"
               spellcheck="false"<?= $isNew ? ' required' : '' ?>>
      </label>
      <?php if ($isNew): ?>
        <label>
          <span>Username (blank to make one from the email)</span>
          <input type="text" name="username" value="<?= fm_h($form['username']) ?>" autocapitalize="none" autocorrect="off">
        </label>
      <?php endif; ?>
      <label>
        <span>Phone</span>
        <input type="tel" name="phone" value="<?= fm_h($form['phone']) ?>" maxlength="40">
      </label>
      <label>
        <span>Department</span>
        <input type="text" name="department" value="<?= fm_h($form['department']) ?>" maxlength="80">
      </label>
    </div>
    <label>
      <span>Notes (admins only)</span>
      <textarea name="notes" maxlength="1000"><?= fm_h($form['notes']) ?></textarea>
    </label>

    <h2>Access</h2>
    <div class="grid2">
      <?php foreach (fm_tools() as $tool): ?>
        <label>
          <span><?= fm_h($tool['label']) ?></span>
          <select name="role[<?= fm_h($tool['key']) ?>]">
            <?php foreach ([FM_NO_ACCESS => 'No access'] + $tool['roles'] as $role => $label): ?>
              <option value="<?= fm_h((string) $role) ?>"<?= ($roles[$tool['key']] ?? '') === $role ? ' selected' : '' ?>>
                <?= fm_h((string) $label) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
    </div>
    <?php if ($isMe): ?>
      <p class="hint" style="margin-top:0">You can't remove your own admin rights or turn off your own account.</p>
    <?php else: ?>
      <label class="check"><input type="checkbox" name="is_admin" value="1"<?= !$isNew && $target['is_admin'] ? ' checked' : '' ?>>
        <span>Admin: can use this Team admin page</span></label>
      <?php if (!$isNew): ?>
        <label class="check"><input type="checkbox" name="is_active" value="1"<?= $target['is_active'] ? ' checked' : '' ?>>
          <span>Account on (turning it off signs them out everywhere)</span></label>
      <?php endif; ?>
    <?php endif; ?>

    <button type="submit"><?= $isNew ? 'Create and get setup code' : 'Save' ?></button>
  </form>

  <?php if (!$isNew): ?>
    <h2>Sign-in</h2>
    <div class="row">
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="code">
        <input type="hidden" name="id" value="<?= (int) $target['id'] ?>">
        <button class="ghost" type="submit"><?= $pending ? 'New setup code' : 'Password reset code' ?></button>
      </form>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="signout">
        <input type="hidden" name="id" value="<?= (int) $target['id'] ?>">
        <button class="ghost" type="submit">Sign out everywhere</button>
      </form>
    </div>
    <p class="hint" style="margin-top:10px">
      A new code cancels any earlier one that hasn't been used yet.
    </p>
  <?php endif; ?>
</div>
<?php
fm_page_foot();
