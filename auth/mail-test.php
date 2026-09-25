<?php
/**
 * Filmmaking tools — admin: is email working?
 *
 * Shows what the config says about sending (never the password) and sends a
 * test message, reporting exactly which step failed when one does. Linked
 * from Team admin.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/people.php';
require __DIR__ . '/page.php';
require __DIR__ . '/mailer.php';

fm_error_handler('html');

$me = fm_require_admin();
$config = fm_config();
$error = '';
$notice = '';
$to = trim((string) ($_POST['to'] ?? ($me['email'] ?? '')));

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!fm_check_csrf($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } elseif (!fm_mail_enabled()) {
        $error = 'Email is not set up yet: add mail_from (and the smtp_ settings) to the config file first.';
    } elseif (!fm_mail_address_ok($to)) {
        $error = 'Enter an address to send the test to.';
    } else {
        @set_time_limit(60);
        $problem = fm_send_mail(
            $to,
            'Test email from the Filmmaking tools',
            "This is a test from Team admin.\n\nIf you are reading it, email from the tools works.\n",
            fm_mail_html('Email works', ['This is a test from Team admin.', 'If you are reading it, email from the tools works.'])
        );
        if ($problem === '') {
            $notice = 'Sent to ' . $to . '. If it doesn’t arrive in a few minutes, check spam or quarantine: the server handed it over, so anything from here is on the receiving side.';
        } else {
            $error = 'Not sent. ' . $problem;
        }
    }
}

$host = trim((string) ($config['smtp_host'] ?? ''));
$port = (int) ($config['smtp_port'] ?? 465);

fm_page_head('Email');
?>
<div class="card wide">
  <div class="eyebrow"><a href="admin.php" style="color:inherit">Team admin</a> / Email</div>
  <h1>Email</h1>

  <?php if ($error !== ''): ?><div class="msg bad"><?= fm_h($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="msg good"><?= fm_h($notice) ?></div><?php endif; ?>

  <p class="note" style="margin-top:0">
    The tools email people when a storyboard is shared with them or someone
    asks for access to one of theirs. Each person can turn that off on their
    Account page.
  </p>

  <table>
    <tr><th>Setting</th><th>Now</th></tr>
    <tr><td>Sending as (mail_from)</td><td><?= fm_mail_enabled() ? fm_h(fm_mail_from()) : '<span class="tag bad">not set — email is off</span>' ?></td></tr>
    <tr><td>Name shown (mail_from_name)</td><td><?= fm_h((string) ($config['mail_from_name'] ?? 'Filmmaking Team')) ?></td></tr>
    <tr><td>Outgoing server</td><td><?= $host !== ''
        ? fm_h($host . ':' . $port . ' (' . strtolower((string) ($config['smtp_secure'] ?? ($port === 465 ? 'ssl' : 'tls'))) . ')')
        : 'none — using PHP mail()' ?></td></tr>
    <tr><td>Signs in as</td><td><?= $host !== '' ? fm_h((string) ($config['smtp_user'] ?? fm_mail_from())) : '—' ?></td></tr>
    <tr><td>Password (smtp_pass)</td><td><?= $host === '' ? '—' : ((string) ($config['smtp_pass'] ?? '') !== '' ? 'set' : '<span class="tag bad">not set</span>') ?></td></tr>
  </table>

  <h2>Send a test</h2>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= fm_h(fm_csrf_token()) ?>">
    <label>
      <span>Send to</span>
      <input type="email" name="to" value="<?= fm_h($to) ?>" required>
    </label>
    <button type="submit"<?= fm_mail_enabled() ? '' : ' disabled' ?>>Send test email</button>
  </form>

  <h2>Setting it up</h2>
  <p class="note" style="margin-top:0">
    In cPanel → Email Accounts, pick the account to send from (or make one,
    such as slate@creativemedia.church), then open <strong>Connect Devices</strong>
    for its outgoing server and port. Add these to the config file above the
    web root, the one with the database password:
  </p>
  <p><code>'mail_from' =&gt; 'slate@creativemedia.church',</code><br>
     <code>'smtp_host' =&gt; 'mail.creativemedia.church',</code><br>
     <code>'smtp_port' =&gt; 465,</code><br>
     <code>'smtp_pass' =&gt; 'that account’s password',</code></p>
  <p class="hint" style="margin-top:8px">The password belongs only in that file: never in git, chat or an email.</p>
</div>
<?php
fm_page_foot();
