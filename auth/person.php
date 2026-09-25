<?php
/**
 * Filmmaking tools — one person: their details, what they can use, and the
 * codes that let them in.
 *
 *   person.php          add someone by hand (they get a setup code)
 *   person.php?id=12    edit an existing account
 *
 * The username is always the part of the email before the @ (first.last), so
 * changing someone's email renames them. Storyboards record their owner by
 * username, so a rename is passed on to each tool (see fm_rename_user()) and
 * their boards follow them.
 *
 * Deleting someone moves their private storyboards to the Slate's trash and
 * keeps their team ones. Turning an account off is the gentler option and
 * keeps everything.
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
        foreach (fm_tools() as $tool) {
            $want = (string) ($_POST['role'][$tool['key']] ?? '');
            if (fm_valid_tool_role($tool['key'], $want)) {
                $roles[$tool['key']] = $want;
            }
        }
        $wantAdmin = !empty($_POST['is_admin']);
        $wantActive = !empty($_POST['is_active']);

        // Any domain: only self-signup is held to allowed_email_domains.
        $emailProblem = fm_email_problem($form['email'], true);

        if ($form['display_name'] === '') {
            $error = 'Give them a name.';
        } elseif ($emailProblem !== '') {
            $error = $emailProblem === 'Enter your email address.' ? 'Enter their email address.' : $emailProblem;
        } elseif (fm_email_taken($form['email'], $isNew ? '' : (string) $target['username'])) {
            $error = 'That email address is already on another account.';
        } elseif (($usernameProblem = fm_username_problem($form['email'], $isNew ? 0 : (int) $target['id'])) !== '') {
            $error = $usernameProblem;
        } elseif ($isNew) {
            [$newId, $err] = fm_create_pending_user(fm_username_for_email($form['email']), $form);
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
                $notice = 'Saved.';

                // A new email means a new username.
                $oldName = (string) $target['username'];
                $newName = fm_username_for_email($form['email']);
                if ($newName !== $oldName) {
                    [$renameError, $problems] = fm_rename_user((int) $target['id'], $newName);
                    if ($renameError !== '') {
                        $error = 'Saved, but the username could not change: ' . $renameError;
                    } else {
                        $notice = 'Saved. Username changed from ' . $oldName . ' to ' . $newName
                            . ($isMe ? ' — sign in with ' . $newName . ' from now on.' : '.');
                        if ($problems) {
                            $error = implode(' ', $problems);
                        }
                    }
                }
                $target = $load((int) $target['id']);
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

    } elseif ($action === 'delete' && !$isNew) {
        $typed = strtolower(trim((string) ($_POST['confirm'] ?? '')));
        if ($isMe) {
            $error = 'You can\'t delete your own account.';
        } elseif ($typed !== strtolower((string) $target['username'])) {
            $error = 'To delete this account, type its username, ' . $target['username'] . ', exactly.';
        } else {
            $problems = fm_delete_user((int) $target['id']);
            if (!$problems) {
                header('Location: admin.php?deleted=1', true, 303);
                exit;
            }
            // Gone from the database, but a tool could not follow. Say so on
            // a page of its own, since there is no account left to show.
            fm_page_head('Account deleted');
            echo '<div class="card"><div class="eyebrow">Team admin</div><h1>Account deleted</h1>'
                . '<div class="msg bad">' . fm_h(implode(' ', $problems)) . '</div>'
                . '<p class="note"><a href="admin.php">Back to Team admin</a></p></div>';
            fm_page_foot();
            exit;
        }

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
        <span>Email</span>
        <input type="email" name="email" id="person-email" value="<?= fm_h($form['email']) ?>" autocapitalize="none"
               spellcheck="false" required>
      </label>
      <label>
        <span>Username (from the email: what they sign in with)</span>
        <input type="text" value="<?= fm_h($isNew ? '' : (string) $target['username']) ?>"
               data-username-from="#person-email"
               placeholder="first.last" readonly tabindex="-1" style="background:var(--gray-5);color:var(--gray-50)">
      </label>
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

    <?php if (!$isMe): ?>
      <h2>Delete account</h2>
      <p class="note" style="margin-top:0">
        Removes the account for good: they can't sign in to any tool, and their
        access and codes are gone. Their <strong>private storyboards</strong> move
        to the Slate's trash on the server, where they can still be recovered by
        hand. Boards they shared with the team stay in the library. To keep
        everything instead, untick <em>Account on</em> above.
      </p>
      <form method="post" style="margin-top:14px">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="id" value="<?= (int) $target['id'] ?>">
        <div class="row" style="align-items:flex-end">
          <label style="flex:1 1 240px;margin-bottom:0">
            <span>Type <?= fm_h((string) $target['username']) ?> to confirm</span>
            <input type="text" name="confirm" autocapitalize="none" autocorrect="off" autocomplete="off" required>
          </label>
          <button class="danger" type="submit">Delete account</button>
        </div>
      </form>
    <?php endif; ?>
  <?php endif; ?>
</div>
<?php
fm_page_foot();
