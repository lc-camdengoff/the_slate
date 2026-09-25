<?php
/**
 * The Slate — storage, for admins.
 *
 * How much the team's storyboards take up on the server, and two ways to
 * make it less:
 *
 *   - Shrink older pictures: boards saved before uploads were shrunk (1280px
 *     WebP) still carry 1600px JPEGs. This re-encodes them in place, a batch
 *     at a time, and marks each board done so it is never redone.
 *   - Clean up now: prune previous copies to SLATE_VERSIONS_KEPT and empty
 *     trash older than SLATE_TRASH_DAYS. This also runs by itself, at most
 *     hourly, whenever someone opens the Slate (list.php).
 *
 * Linked from Team admin through the 'admin' entry in auth/tools.php.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/../auth/auth.php';
require __DIR__ . '/../auth/people.php';
require __DIR__ . '/../auth/page.php';

fm_error_handler('html');

$me = fm_require_admin();

$encoder = slate_image_encoder();
$notice = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!fm_check_csrf($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Try again.';
    } elseif ($action === 'shrink') {
        if ($encoder === '') {
            $error = 'This server\'s PHP has no image support (the GD extension), so pictures can\'t be re-encoded here.';
        } else {
            // A big board can need a lot of memory to decode, and the batch
            // runs for up to ~20 seconds; ask for room, but carry on if the
            // host says no.
            @ini_set('memory_limit', '512M');
            @set_time_limit(90);
            $r = slate_shrink_boards($encoder, 20.0);
            $notice = sprintf(
                'Checked %d storyboard%s, shrank %d picture%s, freed %s.',
                $r['boards'], $r['boards'] === 1 ? '' : 's',
                $r['pictures'], $r['pictures'] === 1 ? '' : 's',
                slate_format_bytes($r['bytes'])
            );
            $more = $r['remaining'] - $r['skipped'];
            if ($more > 0) {
                $notice .= ' ' . $more . ' still to do — press the button again to continue.';
            }
            if ($r['skipped'] > 0) {
                $notice .= ' ' . $r['skipped'] . ' saved in the last ten minutes '
                    . ($r['skipped'] === 1 ? 'was' : 'were') . ' left alone, as someone may be working on '
                    . ($r['skipped'] === 1 ? 'it' : 'them') . '; try again later.';
            }
        }
    } elseif ($action === 'cleanup') {
        $r = slate_housekeeping(true);
        $notice = sprintf(
            'Removed %d older cop%s and %d trashed file%s, freed %s.',
            $r['versions'], $r['versions'] === 1 ? 'y' : 'ies',
            $r['trash'], $r['trash'] === 1 ? '' : 's',
            slate_format_bytes($r['bytes'])
        );
    }
}

$stats = slate_storage_stats();
$total = $stats['boards']['bytes'] + $stats['versions']['bytes'] + $stats['trash']['bytes'];
$csrf = fm_h(fm_csrf_token());

fm_page_head('Storage');
?>
<div class="card wide">
  <div class="eyebrow"><a href="<?= fm_h(fm_auth_url('admin.php')) ?>" style="color:inherit">Team admin</a> / The Slate</div>
  <h1>Storage</h1>

  <?php if ($error !== ''): ?><div class="msg bad"><?= fm_h($error) ?></div><?php endif; ?>
  <?php if ($notice !== ''): ?><div class="msg good"><?= fm_h($notice) ?></div><?php endif; ?>

  <table>
    <tr><th>What</th><th>Files</th><th>Size</th></tr>
    <tr><td>Storyboards</td><td><?= $stats['boards']['files'] ?></td><td><?= fm_h(slate_format_bytes($stats['boards']['bytes'])) ?></td></tr>
    <tr><td>Previous copies (<?= SLATE_VERSIONS_KEPT ?> per storyboard)</td><td><?= $stats['versions']['files'] ?></td><td><?= fm_h(slate_format_bytes($stats['versions']['bytes'])) ?></td></tr>
    <tr><td>Trash (kept <?= SLATE_TRASH_DAYS ?> days)</td><td><?= $stats['trash']['files'] ?></td><td><?= fm_h(slate_format_bytes($stats['trash']['bytes'])) ?></td></tr>
    <tr><th>Total</th><th></th><th><?= fm_h(slate_format_bytes($total)) ?></th></tr>
  </table>

  <h2>Shrink older pictures</h2>
  <p class="note" style="margin-top:0">
    New pictures are stored at 1280px as WebP, about a third of the old size.
    This does the same to pictures added before that change, in every
    storyboard including private ones, and in their previous copy. It changes
    nothing else: not the date, not who saved it last.
  </p>
  <?php if ($stats['unshrunk']['files'] > 0): ?>
    <p class="note"><strong><?= $stats['unshrunk']['files'] ?></strong> storyboard<?= $stats['unshrunk']['files'] === 1 ? '' : 's' ?>
      (<?= fm_h(slate_format_bytes($stats['unshrunk']['bytes'])) ?>) not checked yet.</p>
  <?php else: ?>
    <p class="note">Every storyboard has been checked.</p>
  <?php endif; ?>
  <?php if ($encoder === ''): ?>
    <div class="msg bad">This server's PHP has no image support (the GD extension), so this isn't available.
      Ask the host to enable GD, or it can stay as it is: new pictures are small either way.</div>
  <?php else: ?>
    <form method="post" style="margin-top:12px">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="action" value="shrink">
      <button type="submit"<?= $stats['unshrunk']['files'] > 0 ? '' : ' disabled' ?>>Shrink older pictures</button>
    </form>
    <p class="hint" style="margin-top:8px">
      Works for up to about 20 seconds at a time; press again if it says there's
      more. Saves as <?= $encoder === 'webp' ? 'WebP' : 'JPEG (this server can\'t write WebP)' ?>.
      Someone with a storyboard open while it's shrunk is told it changed
      elsewhere, and gets the new copy when they reopen it.
    </p>
  <?php endif; ?>

  <h2>Clean up</h2>
  <p class="note" style="margin-top:0">
    Each storyboard keeps <?= SLATE_VERSIONS_KEPT ?> previous cop<?= SLATE_VERSIONS_KEPT === 1 ? 'y' : 'ies' ?>
    to undo a bad save, and deleted storyboards stay in the trash for
    <?= SLATE_TRASH_DAYS ?> days before they're removed for good. That happens
    by itself about once an hour; this runs it now.
  </p>
  <form method="post" style="margin-top:12px">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="cleanup">
    <button class="ghost" type="submit">Clean up now</button>
  </form>
</div>
<?php
fm_page_foot();
