<?php
/**
 * Filmmaking tools — import people from a CSV (e.g. a Cheqroom export).
 *
 * Three steps, and nothing is saved until the last:
 *
 *   1. Upload the file.
 *   2. Check the preview: which column is which, what each role in the file
 *      becomes in The Cage, and who will be created, updated or skipped.
 *   3. Import. Every new account gets a one-time setup code, shown once.
 *
 * The parsed file rides along between steps in a hidden field rather than
 * being kept on the server, so there is no half-finished import lying around
 * with people's details in it. Everything is re-checked at step 3.
 */

declare(strict_types=1);

require __DIR__ . '/auth.php';
require __DIR__ . '/people.php';
require __DIR__ . '/page.php';

fm_error_handler('html');

$me = fm_require_admin();

$error = '';
$header = [];
$rows = [];
$step = 'upload';
$results = null;

/** Rebuild the file from the hidden field, trusting none of it. */
$decode = static function (string $blob): ?array {
    $data = json_decode((string) base64_decode($blob, true), true);
    if (!is_array($data) || !is_array($data['h'] ?? null) || !is_array($data['r'] ?? null)) {
        return null;
    }
    $clean = static fn($c) => fm_clean_text(is_scalar($c) ? (string) $c : '', FM_IMPORT_MAX_CELL);
    $h = array_slice(array_map($clean, array_values($data['h'])), 0, FM_IMPORT_MAX_COLS);
    $r = [];
    foreach (array_slice(array_values($data['r']), 0, FM_IMPORT_MAX_ROWS) as $row) {
        if (is_array($row)) {
            $r[] = array_pad(array_slice(array_map($clean, array_values($row)), 0, count($h)), count($h), '');
        }
    }
    return $h && $r ? [$h, $r] : null;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    if (!fm_check_csrf($_POST['csrf'] ?? null)) {
        $error = 'That form expired. Start the import again.';
    } elseif ($action === 'upload') {
        $file = $_FILES['csv'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_uploaded_file((string) $file['tmp_name'])) {
            $error = ($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($file['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
                ? 'That file is too big.'
                : 'Choose a CSV file to upload.';
        } else {
            [$header, $rows, $error] = fm_csv_read((string) $file['tmp_name']);
            $step = $error === '' ? 'preview' : 'upload';
        }
    } elseif ($action === 'preview' || $action === 'import') {
        $decoded = $decode((string) ($_POST['data'] ?? ''));
        if ($decoded === null) {
            $error = 'The import data was lost. Upload the file again.';
        } else {
            [$header, $rows] = $decoded;
            $step = $action;
        }
    }
}

// ------------------------------------------------------------ options
// Guessed on upload; read back from the form after that.

$posted = $step !== 'upload' && ($_POST['action'] ?? '') !== 'upload';
$map = fm_import_guess($header);
if ($posted) {
    foreach (array_keys(fm_import_fields()) as $field) {
        $col = (int) ($_POST['map'][$field] ?? -1);
        $map[$field] = $col >= 0 && $col < count($header) ? $col : -1;
    }
}

$defaults = [];
foreach (fm_tools() as $tool) {
    $key = $tool['key'];
    $want = (string) ($_POST['default_role'][$key] ?? $tool['default_role']);
    $defaults[$key] = fm_valid_tool_role($key, $want) ? $want : (string) $tool['default_role'];
}

$roleValues = fm_import_values($rows, $map['role']);
$postedRoles = [];
foreach ((array) ($_POST['role_value'] ?? []) as $i => $value) {
    $postedRoles[strtolower((string) $value)] = (string) ($_POST['role_map'][$i] ?? '');
}
$roleMap = [];
foreach ($roleValues as $value) {
    $want = $postedRoles[strtolower($value)] ?? fm_import_role_guess($value);
    $roleMap[strtolower($value)] = fm_valid_tool_role('cage', $want) ? $want : 'member';
}

$exclude = [];
if ($posted) {
    $included = array_flip(array_map('intval', (array) ($_POST['include'] ?? [])));
    foreach ((array) ($_POST['shown'] ?? []) as $i) {
        if (!isset($included[(int) $i])) {
            $exclude[(int) $i] = true;
        }
    }
}

$fillExisting = $posted ? !empty($_POST['fill_existing']) : true;
$rolesExisting = $posted ? !empty($_POST['roles_existing']) : false;

$plans = [];
if ($step !== 'upload') {
    $plans = fm_import_plan($rows, [
        'map' => $map,
        'role_map' => $roleMap,
        'roles' => $defaults,
        'exclude' => $exclude,
        'fill_existing' => $fillExisting,
        'roles_existing' => $rolesExisting,
    ]);
    if ($step === 'import') {
        $results = fm_import_run($plans, (int) $me['id']);
    }
}

$count = static function (array $list, string $action): int {
    return count(array_filter($list, static fn($p) => $p['action'] === $action));
};
$csrf = fm_h(fm_csrf_token());
$blob = $header ? base64_encode((string) json_encode(['h' => $header, 'r' => $rows], JSON_UNESCAPED_UNICODE)) : '';

/** A select of this tool's roles plus "No access". */
$roleSelect = static function (string $name, string $toolKey, string $current): string {
    $tool = fm_tool($toolKey);
    $html = '<select name="' . fm_h($name) . '">';
    foreach ([FM_NO_ACCESS => 'No access'] + (array) ($tool['roles'] ?? []) as $role => $label) {
        $html .= '<option value="' . fm_h((string) $role) . '"' . ($role === $current ? ' selected' : '') . '>'
            . fm_h((string) $label) . '</option>';
    }
    return $html . '</select>';
};

fm_page_head('Import people');
?>
<div class="card wider">
  <div class="eyebrow"><a href="admin.php" style="color:inherit">Team admin</a> / Import</div>
  <h1>Import people</h1>

  <?php if ($error !== ''): ?><div class="msg bad"><?= fm_h($error) ?></div><?php endif; ?>

<?php if ($results !== null): ?>
  <?php
    $made = array_values(array_filter($results, static fn($p) => $p['action'] === 'create'));
    $sheet = "Name\tEmail\tUsername\tSetup code\tSet up at\n";
    foreach ($made as $p) {
        $sheet .= implode("\t", array_map(static fn($v) => fm_sheet_safe(str_replace(["\t", "\n", "\r"], ' ', (string) $v)), [
            $p['person']['display_name'], $p['person']['email'], $p['username'], $p['code'], fm_setup_url($p['username']),
        ])) . "\n";
    }
  ?>
  <div class="msg good">
    Imported. <?= count($made) ?> new account<?= count($made) === 1 ? '' : 's' ?>,
    <?= $count($results, 'update') ?> updated,
    <?= $count($results, 'same') ?> already here,
    <?= $count($results, 'skip') ?> skipped.
  </div>

  <?php if ($made): ?>
    <h2>Setup codes</h2>
    <div class="msg">
      <strong>These codes are shown once.</strong> Copy them out now. Each person
      opens their link, enters their code and chooses a password. Codes work once
      and expire in <?= intdiv(FM_SETUP_CODE_HOURS, 24) ?> days. You can issue a new
      one from their page on Team admin.
    </div>
    <label>
      <span>Copy into a spreadsheet (tab separated)</span>
      <textarea class="mono" rows="<?= min(18, count($made) + 2) ?>" readonly><?= fm_h($sheet) ?></textarea>
    </label>
    <div class="scroll">
    <table>
      <tr><th>Name</th><th>Email</th><th>Username</th><th>Setup code</th></tr>
      <?php foreach ($made as $p): ?>
        <tr>
          <td><a href="person.php?id=<?= (int) $p['user_id'] ?>"><?= fm_h($p['person']['display_name']) ?></a></td>
          <td><?= fm_h($p['person']['email']) ?></td>
          <td><?= fm_h($p['username']) ?></td>
          <td><code><?= fm_h($p['code']) ?></code></td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
  <?php endif; ?>

  <?php $skipped = array_filter($results, static fn($p) => $p['action'] === 'skip'); ?>
  <?php if ($skipped): ?>
    <h2>Skipped</h2>
    <table>
      <tr><th>Row</th><th>Name</th><th>Email</th><th>Why</th></tr>
      <?php foreach ($skipped as $p): ?>
        <tr class="skip">
          <td><?= $p['row'] + 2 ?></td>
          <td><?= fm_h($p['person']['display_name']) ?></td>
          <td><?= fm_h($p['person']['email']) ?></td>
          <td><?= fm_h($p['reason']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
  <p class="note"><a href="admin.php">Back to Team admin</a></p>

<?php elseif ($step === 'upload'): ?>
  <p class="note" style="margin-top:0">
    Export your people from Cheqroom (or any spreadsheet) as a CSV, with column
    names in the first row. You'll see a preview before anything is saved.
  </p>
  <form method="post" enctype="multipart/form-data" style="margin-top:18px">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="upload">
    <label>
      <span>CSV file</span>
      <input type="file" name="csv" accept=".csv,.tsv,.txt,text/csv" required>
    </label>
    <button type="submit">Upload and preview</button>
  </form>
  <p class="note">
    Nobody's password comes across. Each new person gets a one-time setup code
    to choose their own. Anyone whose email already has an account is matched
    to it rather than duplicated.
  </p>

<?php else: ?>
  <form method="post">
    <input type="hidden" name="csrf" value="<?= $csrf ?>">
    <input type="hidden" name="data" value="<?= fm_h($blob) ?>">

    <div class="msg">
      <?= count($rows) ?> rows read.
      <strong><?= $count($plans, 'create') ?> new</strong>,
      <?= $count($plans, 'update') ?> to update,
      <?= $count($plans, 'same') ?> already have accounts,
      <?= $count($plans, 'skip') ?> skipped.
      Nothing is saved until you press Import.
    </div>

    <h2>Columns</h2>
    <div class="grid2">
      <?php foreach (fm_import_fields() as $field => $label): ?>
        <label>
          <span><?= fm_h($label) ?></span>
          <select name="map[<?= fm_h($field) ?>]">
            <option value="-1">— not in file —</option>
            <?php foreach ($header as $i => $name): ?>
              <option value="<?= (int) $i ?>"<?= $map[$field] === $i ? ' selected' : '' ?>>
                <?= fm_h($name !== '' ? $name : 'Column ' . ($i + 1)) ?><?php
                  $sample = trim((string) ($rows[0][$i] ?? ''));
                  echo $sample !== '' ? ' (e.g. ' . fm_h(function_exists('mb_strimwidth') ? mb_strimwidth($sample, 0, 28, '…', 'UTF-8') : substr($sample, 0, 28)) . ')' : '';
                ?>
              </option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="hint" style="margin-top:0">
      Use Full name, or First and Last name. Email is required: it's how
      existing accounts are matched, The Cage needs it for reminders, and each
      person's username is the part before the @ (first.last).
    </div>

    <h2>Access for new people</h2>
    <div class="grid2">
      <?php foreach (fm_tools() as $tool): ?>
        <label>
          <span><?= fm_h($tool['label']) ?></span>
          <?= $roleSelect('default_role[' . $tool['key'] . ']', $tool['key'], $defaults[$tool['key']]) ?>
        </label>
      <?php endforeach; ?>
    </div>

    <?php if ($roleValues): ?>
      <h2>Roles in the file → The Cage</h2>
      <table style="max-width:560px">
        <tr><th>In the file</th><th>Becomes</th></tr>
        <?php foreach ($roleValues as $i => $value): ?>
          <tr>
            <td><?= fm_h($value) ?><input type="hidden" name="role_value[<?= (int) $i ?>]" value="<?= fm_h($value) ?>"></td>
            <td><?= $roleSelect('role_map[' . $i . ']', 'cage', $roleMap[strtolower($value)]) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <div class="hint" style="margin-top:8px">
        Anyone with no role in the file gets the Cage access chosen above.
        Nobody becomes an admin of this panel through an import.
      </div>
    <?php endif; ?>

    <h2>People already here</h2>
    <label class="check"><input type="checkbox" name="fill_existing" value="1"<?= $fillExisting ? ' checked' : '' ?>>
      <span>Fill in phone, department and notes where their account has none</span></label>
    <label class="check"><input type="checkbox" name="roles_existing" value="1"<?= $rolesExisting ? ' checked' : '' ?>>
      <span>Also set their access from this file (replaces what they have now)</span></label>

    <div class="row" style="margin:6px 0 18px">
      <button class="ghost" type="submit" name="action" value="preview">Update preview</button>
      <button type="submit" name="action" value="import">Import <?= $count($plans, 'create') + $count($plans, 'update') ?> people</button>
    </div>

    <h2>Preview</h2>
    <div class="scroll">
    <table>
      <tr><th></th><th>Row</th><th>Status</th><th>Name</th><th>Email</th><th>Username</th>
          <th>Department</th><th>Phone</th><th>Cage</th><th></th></tr>
      <?php foreach ($plans as $p): ?>
        <?php $live = $p['action'] === 'create' || $p['action'] === 'update' || $p['reason'] === 'Left out.'; ?>
        <tr class="<?= $p['action'] === 'skip' || $p['action'] === 'same' ? 'skip' : '' ?>">
          <td>
            <?php if ($live): ?>
              <input type="hidden" name="shown[]" value="<?= (int) $p['row'] ?>">
              <input type="checkbox" name="include[]" value="<?= (int) $p['row'] ?>"<?= $p['reason'] === 'Left out.' ? '' : ' checked' ?>
                     aria-label="Include row <?= $p['row'] + 2 ?>">
            <?php endif; ?>
          </td>
          <td class="small"><?= $p['row'] + 2 ?></td>
          <td><span class="tag <?= $p['action'] === 'create' ? 'good' : ($p['action'] === 'skip' ? 'bad' : '') ?>"><?=
            ['create' => 'New', 'update' => 'Update', 'same' => 'Here', 'skip' => 'Skip'][$p['action']] ?></span></td>
          <td><?= fm_h($p['person']['display_name']) ?></td>
          <td><?= fm_h($p['person']['email']) ?></td>
          <td><?= fm_h($p['username']) ?></td>
          <td><?= fm_h($p['person']['department']) ?></td>
          <td class="small"><?= fm_h($p['person']['phone']) ?></td>
          <td class="small"><?= $p['action'] === 'create' || !empty($p['set_roles'])
              ? fm_h(fm_role_label('cage', (string) ($p['roles']['cage'] ?? FM_NO_ACCESS))) : '' ?></td>
          <td class="small"><?= fm_h($p['reason'] !== '' ? $p['reason']
              : ($p['changes'] ? 'Adds ' . implode(', ', array_keys($p['changes'])) : '')) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
    </div>
  </form>
<?php endif; ?>
</div>
<?php
fm_page_foot();
