<?php
/**
 * The Slate — create an account.
 *
 * Gated by a shared invite code from the admin page. While the users table is
 * empty the bootstrap code from the config file is accepted instead, and that
 * first account becomes the admin.
 *
 * An email address is collected as well, because the gear room (The Cage)
 * refuses a sign-in from an account without one: it is how a pickup notice or
 * an overdue chase reaches anyone, and a username cannot receive either. It is
 * NOT verified — nothing is sent to it — so the invite code is what keeps a
 * mistyped or borrowed address from mattering. See email.php.
 *
 * There is no username box: the username is the part of the email before the
 * @ (first.last), the same rule the admin pages and the import follow.
 *
 * Someone an admin already added (imported from Cheqroom, say) has an account
 * waiting with no password. Normally they set it up with their personal setup
 * code on reset.php. If the admin ticked "works for people already added" on
 * an invite code, they can instead use that shared code here with the same
 * email, and the waiting account — with the access the admin gave it — becomes
 * theirs. That is weaker than a personal code, since anyone holding the shared
 * code who knows a waiting teammate's address could take it, which is why it
 * is a choice per code and not the default.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/email.php';
require __DIR__ . '/people.php';
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
$email = '';
$firstRun = fm_user_count() === 0;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $displayName = trim((string) ($_POST['display_name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $confirm = (string) ($_POST['confirm'] ?? '');
    $code = trim((string) ($_POST['invite'] ?? ''));

    /* The email is checked here, with the rest of the form, rather than after
       the invite is redeemed — redeeming spends a use, and a typo'd address
       should not cost one. Same reasoning as fm_release_invite() below. */
    $emailProblem = fm_email_problem($email);
    $username = fm_username_for_email($email);
    $waiting = $emailProblem === '' ? fm_find_user($email) : null;

    if (fm_throttled('signup')) {
        $error = 'Too many attempts from this connection. Wait a few minutes and try again.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } elseif (strlen($password) < FM_MIN_PASSWORD) {
        $error = 'Use at least ' . FM_MIN_PASSWORD . ' characters for your password.';
    } elseif ($emailProblem !== '') {
        $error = $emailProblem;
    } elseif ($waiting !== null && fm_has_password($waiting)) {
        $error = 'That email already has an account. Sign in with ' . $waiting['username']
            . ', or ask an admin for a reset code if you have forgotten the password.';
    } elseif ($waiting !== null) {
        // An admin already made this account. Claim it rather than make another.
        if (!$waiting['is_active']) {
            $error = 'The account for that email has been turned off. Ask an admin.';
        } elseif (!$firstRun && fm_redeem_invite($code, true)) {
            fm_set_password((int) $waiting['id'], $password);
            if ($displayName !== '') {
                fm_db()->prepare('UPDATE users SET display_name = ? WHERE id = ?')
                    ->execute([substr($displayName, 0, 80), $waiting['id']]);
            }
            // Their personal setup code, if they had one, is now spent too.
            fm_db()->prepare('UPDATE password_resets SET used_at = now() WHERE user_id = ? AND used_at IS NULL')
                ->execute([$waiting['id']]);
            fm_record_attempt('signup', $code, true);
            error_log('slate: waiting account ' . $waiting['username'] . ' claimed with an invite code');
            fm_attempt_login((string) $waiting['username'], $password);
            header('Location: ' . $next);
            exit;
        } else {
            fm_record_attempt('signup', $code, false);
            $error = 'An admin already made an account for that email. Use the setup code '
                . 'they gave you (Sign in → Set up your account), or ask them for one.';
        }
    } elseif (($usernameProblem = fm_username_problem($email)) !== '') {
        // Most likely an admin already made this person an account, or an
        // older account took the name before usernames came from emails.
        $error = $usernameProblem . ' Ask an admin to sort it out.';
    } elseif (fm_email_taken($email)) {
        /* Checked before the account is created rather than after, so a
           duplicate address fails cleanly instead of leaving a half-made
           account with no way to reach its owner. The unique index is still
           the thing that decides; this only makes the common case tidy. */
        $error = 'That email address is already on another account.';
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
                /* Separate statement because fm_create_user() predates the
                   column. A failure here leaves a usable Slate account that
                   can't sign in to the gear room, so it is reported rather
                   than swallowed. The column itself is created by
                   fm_migrate_columns() in db.php on the first request after a
                   deploy. */
                $stored = fm_set_email($username, $email);
                if ($stored !== '') {
                    error_log("slate: account $username created but email not stored: $stored");
                }
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
                'username_taken' => 'The username ' . $username . ' already belongs to another account.',
                'bad_username' => 'A username can\'t be made from that email address.',
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
      <span>Email</span>
      <input type="email" name="email" value="<?= fm_h($email) ?>"
             autocapitalize="none" autocorrect="off" spellcheck="false"
             autocomplete="email" placeholder="camden.goff@life.church" required>
    </label>
    <div class="hint">Where the gear room sends pickup and overdue reminders. The part
      before the @ is your username: camden.goff@life.church signs in as camden.goff.
      If an admin already added you, use the same email and your account is waiting.</div>
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
