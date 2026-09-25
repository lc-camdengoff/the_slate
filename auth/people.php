<?php
/**
 * Filmmaking tools — people, for the admin pages.
 *
 * Creating an account on someone's behalf, and bringing a whole team over
 * from a CSV export (Cheqroom's, in the first instance, but nothing here is
 * specific to it: the admin matches columns to fields on the import page).
 *
 * An account made this way has no password. Its owner gets a one-time setup
 * code from the admin and chooses their own on reset.php, so nobody — the
 * admin included — ever knows it. Until then the account cannot sign in.
 */

declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/email.php';

const FM_IMPORT_MAX_BYTES = 2 * 1024 * 1024;
const FM_IMPORT_MAX_ROWS = 3000;
const FM_IMPORT_MAX_COLS = 80;
const FM_IMPORT_MAX_CELL = 500;

/**
 * The details an admin can keep on a person, with the longest each may be.
 * Username and email are handled separately: both have rules of their own.
 */
function fm_person_fields(): array
{
    return [
        'display_name' => ['label' => 'Name', 'max' => 80],
        'phone' => ['label' => 'Phone', 'max' => 40],
        'department' => ['label' => 'Department', 'max' => 80],
        'notes' => ['label' => 'Notes', 'max' => 1000],
    ];
}

/** Trim, drop control characters, and cap the length of free text. */
function fm_clean_text(string $value, int $max): string
{
    // Tabs and newlines survive only where they could mean something (notes).
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value) ?? '';
    $value = trim($value);
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

/** Every username in use, lowercased, as a set. */
function fm_taken_usernames(): array
{
    $taken = [];
    foreach (fm_db()->query('SELECT username_ci FROM users')->fetchAll() as $row) {
        $taken[(string) $row['username_ci']] = true;
    }
    return $taken;
}

/**
 * Why this address can't become someone's username, or '' if it can.
 *
 * Every username is the part of the email before the @, so the address has
 * to be usable as one and the result must not already be someone else's.
 *
 * @param int $exceptId an account allowed to have it already (the one being
 *   edited)
 */
function fm_username_problem(string $email, int $exceptId = 0): string
{
    $username = fm_username_for_email($email);
    if ($username === '') {
        return 'A username can\'t be made from that address. It needs to start with a letter or number.';
    }
    $stmt = fm_db()->prepare('SELECT id, email FROM users WHERE username_ci = ? AND id <> ?');
    $stmt->execute([$username, $exceptId]);
    $other = $stmt->fetch();
    if ($other) {
        return 'The username ' . $username . ' already belongs to another account'
            . ($other['email'] ? ' (' . $other['email'] . ')' : '') . '.';
    }
    return '';
}

/**
 * Run every tool's hook for an account change. See 'hooks' in tools.php.
 *
 * @return list<string> problems worth telling the admin about
 */
function fm_run_account_hooks(string $event, array $args): array
{
    $problems = [];
    foreach (fm_tools() as $tool) {
        $file = trim((string) ($tool['hooks'] ?? ''));
        if ($file === '') {
            continue;
        }
        $path = dirname(__DIR__) . '/' . ltrim($file, '/');
        if (!is_file($path)) {
            continue; // tool not installed on this server
        }
        require_once $path;
        $fn = $tool['key'] . '_account_' . $event;
        if (!function_exists($fn)) {
            continue;
        }
        try {
            [$changed, $failed] = $fn(...$args) + [0, 0];
            if ($failed > 0) {
                $problems[] = $tool['label'] . ': ' . $failed . ' item' . ($failed === 1 ? '' : 's')
                    . ' could not be updated. Try again; if it keeps failing, check the error log.';
            }
        } catch (Throwable $e) {
            error_log("slate: $fn failed: " . $e->getMessage());
            $problems[] = $tool['label'] . ' could not be updated. Check the error log.';
        }
    }
    return $problems;
}

/**
 * Change someone's username, and everything that records it.
 *
 * The account row changes first, where the unique index settles any clash;
 * then each tool is told so it can follow. Sessions are keyed by account, not
 * name, so nobody is signed out by this.
 *
 * @return array{0: string, 1: list<string>} [error, tool problems]
 */
function fm_rename_user(int $userId, string $newUsername): array
{
    if (!fm_valid_username($newUsername)) {
        return ['That is not a usable username.', []];
    }
    $stmt = fm_db()->prepare('SELECT username FROM users WHERE id = ?');
    $stmt->execute([$userId]);
    $old = (string) ($stmt->fetch()['username'] ?? '');
    if ($old === '') {
        return ['No such account.', []];
    }
    if ($old === $newUsername) {
        return ['', []];
    }
    try {
        fm_db()->prepare('UPDATE users SET username = ?, username_ci = ? WHERE id = ?')
            ->execute([$newUsername, strtolower($newUsername), $userId]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            return ['The username ' . $newUsername . ' already belongs to another account.', []];
        }
        throw $e;
    }
    // A rename that only changes case is the same name to every tool.
    if (strcasecmp($old, $newUsername) === 0) {
        return ['', []];
    }
    return ['', fm_run_account_hooks('renamed', [$old, $newUsername])];
}

/**
 * Delete an account for good.
 *
 * Its sessions, codes and access go with it (ON DELETE CASCADE); invite codes
 * it made stay, credited to nobody. Then each tool is told: the Slate moves
 * the person's private storyboards to its trash and keeps their team ones.
 *
 * @return list<string> tool problems
 */
function fm_delete_user(int $userId): array
{
    $stmt = fm_db()->prepare('DELETE FROM users WHERE id = ? RETURNING username');
    $stmt->execute([$userId]);
    $username = (string) ($stmt->fetch()['username'] ?? '');
    if ($username === '') {
        return [];
    }
    error_log("slate: account $username deleted");
    return fm_run_account_hooks('deleted', [$username, $userId]);
}

/**
 * Accounts whose username is not what their email address makes, with the
 * username it should be. Those without an email keep theirs until one is added.
 *
 * @return list<array{id: int, display_name: string, email: string, from: string, to: string, problem: string}>
 */
function fm_username_mismatches(): array
{
    $out = [];
    foreach (fm_db()->query(
        'SELECT id, username, display_name, email FROM users WHERE email IS NOT NULL ORDER BY lower(display_name)'
    )->fetchAll() as $u) {
        $want = fm_username_for_email((string) $u['email']);
        if ($want === (string) $u['username']) {
            continue;
        }
        $out[] = [
            'id' => (int) $u['id'],
            'display_name' => (string) $u['display_name'],
            'email' => (string) $u['email'],
            'from' => (string) $u['username'],
            'to' => $want,
            'problem' => fm_username_problem((string) $u['email'], (int) $u['id']),
        ];
    }
    return $out;
}

/**
 * Make an account with no password yet.
 *
 * $person holds display_name, email and optionally phone, department and
 * notes, already cleaned. Tool roles are set separately.
 *
 * @return array{0: int, 1: string} [new user id or 0, error key]
 */
function fm_create_pending_user(string $username, array $person): array
{
    if (!fm_valid_username($username)) {
        return [0, 'bad_username'];
    }
    $email = strtolower(trim((string) ($person['email'] ?? '')));
    $opt = static fn(string $k) => trim((string) ($person[$k] ?? '')) === '' ? null : (string) $person[$k];
    $name = trim((string) ($person['display_name'] ?? '')) ?: $username;

    try {
        $stmt = fm_db()->prepare(
            "INSERT INTO users (username, username_ci, display_name, password_hash, email,
                                phone, department, notes)
             VALUES (?, ?, ?, '', ?, ?, ?, ?) RETURNING id"
        );
        $stmt->execute([
            $username, strtolower($username), $name, $email === '' ? null : $email,
            $opt('phone'), $opt('department'), $opt('notes'),
        ]);
        return [(int) $stmt->fetch()['id'], ''];
    } catch (PDOException $e) {
        if ($e->getCode() === '23505') {
            // Which of the two unique rules it was matters to whoever reads it.
            return [0, str_contains($e->getMessage(), 'email') ? 'email_taken' : 'username_taken'];
        }
        throw $e;
    }
}

// ---------------------------------------------------------------------------
// CSV
// ---------------------------------------------------------------------------

/**
 * Read an uploaded CSV into a header row and data rows.
 *
 * Copes with what spreadsheet exports actually look like: a byte-order mark,
 * semicolons or tabs instead of commas, and Windows-1252 rather than UTF-8.
 *
 * @return array{0: list<string>, 1: list<list<string>>, 2: string} [header, rows, error]
 */
function fm_csv_read(string $path): array
{
    $raw = @file_get_contents($path, false, null, 0, FM_IMPORT_MAX_BYTES + 1);
    if ($raw === false || $raw === '') {
        return [[], [], 'That file is empty.'];
    }
    if (strlen($raw) > FM_IMPORT_MAX_BYTES) {
        return [[], [], 'That file is over 2 MB. Export only the people, not the whole account.'];
    }
    if (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    }
    if (!preg_match('//u', $raw)) {
        $converted = function_exists('mb_convert_encoding')
            ? @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252')
            : (function_exists('iconv') ? @iconv('Windows-1252', 'UTF-8//IGNORE', $raw) : false);
        if (!is_string($converted) || !preg_match('//u', $converted)) {
            return [[], [], 'That file is not text this page can read. Export it as CSV.'];
        }
        $raw = $converted;
    }

    // The delimiter is whichever of the usual three the header line uses most.
    $firstLine = strtok($raw, "\r\n") ?: '';
    $delimiter = ',';
    $best = substr_count($firstLine, ',');
    foreach ([';', "\t"] as $d) {
        if (substr_count($firstLine, $d) > $best) {
            $best = substr_count($firstLine, $d);
            $delimiter = $d;
        }
    }

    $stream = fopen('php://temp', 'r+');
    fwrite($stream, $raw);
    rewind($stream);

    $header = null;
    $rows = [];
    while (($cells = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
        if ($cells === [null] || implode('', array_map('strval', $cells)) === '') {
            continue; // blank line
        }
        $cells = array_slice(array_map(
            static fn($c) => fm_clean_text((string) $c, FM_IMPORT_MAX_CELL),
            $cells
        ), 0, FM_IMPORT_MAX_COLS);
        if ($header === null) {
            $header = $cells;
            continue;
        }
        if (count($rows) >= FM_IMPORT_MAX_ROWS) {
            fclose($stream);
            return [[], [], 'That file has more than ' . FM_IMPORT_MAX_ROWS . ' people in it.'];
        }
        $rows[] = array_pad($cells, count($header), '');
    }
    fclose($stream);

    if ($header === null || $rows === []) {
        return [[], [], 'No people found. The first row should be column names, then one person per row.'];
    }
    return [$header, $rows, ''];
}

/** The fields a CSV column can be matched to on the import page. */
function fm_import_fields(): array
{
    return [
        'name' => 'Full name',
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email',
        'phone' => 'Phone',
        'department' => 'Department',
        'role' => 'Role',
        'notes' => 'Notes',
    ];
}

/**
 * Best guess at which column holds what, from the header names.
 *
 * @return array<string, int> field => column index, -1 for none
 */
function fm_import_guess(array $header): array
{
    $names = [
        'name' => ['name', 'fullname', 'contactname', 'displayname', 'contact', 'person'],
        'first_name' => ['firstname', 'first', 'givenname', 'forename'],
        'last_name' => ['lastname', 'last', 'surname', 'familyname'],
        'email' => ['email', 'emailaddress', 'mail', 'workemail', 'primaryemail'],
        'phone' => ['phone', 'phonenumber', 'mobile', 'mobilephone', 'cell', 'cellphone', 'telephone', 'tel'],
        'department' => ['department', 'dept', 'team', 'group', 'company', 'organization', 'organisation', 'location', 'campus'],
        'role' => ['role', 'userrole', 'accesslevel', 'permission', 'permissions', 'kind', 'type', 'usertype'],
        'notes' => ['notes', 'note', 'comments', 'comment', 'description'],
    ];
    $normal = array_map(static fn($h) => strtolower((string) preg_replace('/[^a-z0-9]/i', '', $h)), $header);

    $guess = [];
    $used = [];
    foreach ($names as $field => $options) {
        $guess[$field] = -1;
        foreach ($options as $option) {
            $i = array_search($option, $normal, true);
            if ($i !== false && !isset($used[$i])) {
                $guess[$field] = (int) $i;
                $used[$i] = true;
                break;
            }
        }
    }
    return $guess;
}

/**
 * Suggested Cage role for a role value from the export.
 *
 * Deliberately stingy: only something that plainly says admin becomes one.
 * Everything else starts as the least it could be, and the import page shows
 * every value found so the admin can raise it before anything is saved.
 */
function fm_import_role_guess(string $value): string
{
    $v = strtolower($value);
    if (preg_match('/\b(admin|administrator|root|owner)\b/', $v)) {
        return 'admin';
    }
    if (preg_match('/\b(manager|staff|operator|technician)\b/', $v)) {
        return 'manager';
    }
    return 'member';
}

/** Distinct non-empty values in one column, in first-seen order. */
function fm_import_values(array $rows, int $column): array
{
    if ($column < 0) {
        return [];
    }
    $seen = [];
    foreach ($rows as $row) {
        $v = trim((string) ($row[$column] ?? ''));
        if ($v !== '' && !isset($seen[strtolower($v)])) {
            $seen[strtolower($v)] = $v;
        }
    }
    return array_values($seen);
}

/**
 * Work out what importing these rows would do, without doing any of it.
 *
 * The same function decides the preview and the real import, so what the
 * admin approved is what happens — and it runs again at import time rather
 * than trusting the preview, since accounts may have changed in between.
 *
 * @param array $opts [
 *   'map' => field => column,
 *   'role_map' => lowercased role value => cage role,
 *   'roles' => tool key => default role for new people,
 *   'exclude' => set of row indexes the admin unticked,
 *   'fill_existing' => bool, 'roles_existing' => bool ]
 * @return list<array> one plan per row: action create|update|same|skip, plus
 *   reason, username, person fields, roles and existing user id
 */
function fm_import_plan(array $rows, array $opts): array
{
    $map = $opts['map'];
    $cell = static fn(array $row, string $field): string =>
        ($map[$field] ?? -1) >= 0 ? trim((string) ($row[$map[$field]] ?? '')) : '';

    $existing = [];
    foreach (fm_db()->query(
        'SELECT id, username, lower(email) AS email, display_name, phone, department, notes
           FROM users WHERE email IS NOT NULL'
    )->fetchAll() as $u) {
        $existing[(string) $u['email']] = $u;
    }
    $taken = fm_taken_usernames();
    $fields = fm_person_fields();
    $seenEmails = [];
    $plans = [];

    foreach ($rows as $i => $row) {
        $name = $cell($row, 'name');
        if ($name === '') {
            $name = trim($cell($row, 'first_name') . ' ' . $cell($row, 'last_name'));
        }
        $email = strtolower($cell($row, 'email'));
        if ($name === '' && $email !== '' && strpos($email, '@') !== false) {
            // camden.goff@ → Camden Goff: better than a blank on the Slate.
            $name = ucwords(str_replace(['.', '_', '-'], ' ', strstr($email, '@', true)));
        }
        $person = [
            'display_name' => fm_clean_text($name, $fields['display_name']['max']),
            'email' => $email,
            'phone' => fm_clean_text($cell($row, 'phone'), $fields['phone']['max']),
            'department' => fm_clean_text($cell($row, 'department'), $fields['department']['max']),
            'notes' => fm_clean_text($cell($row, 'notes'), $fields['notes']['max']),
        ];

        $roles = $opts['roles'];
        $csvRole = strtolower($cell($row, 'role'));
        if ($csvRole !== '' && isset($opts['role_map'][$csvRole])) {
            $roles['cage'] = $opts['role_map'][$csvRole];
        }

        $plan = ['row' => $i, 'person' => $person, 'roles' => $roles, 'csv_role' => $cell($row, 'role'),
                 'username' => '', 'user_id' => 0, 'action' => 'skip', 'reason' => '', 'changes' => [],
                 'set_roles' => false];

        // Any domain: an admin chose to import these people.
        $problem = $email === '' ? 'No email address.' : fm_email_problem($email, true);
        if ($problem !== '') {
            $plan['reason'] = $problem === 'Enter your email address.' ? 'No email address.' : $problem;
        } elseif (isset($seenEmails[$email])) {
            $plan['reason'] = 'Same email as row ' . ($seenEmails[$email] + 2) . '.';
        } elseif (isset($opts['exclude'][$i])) {
            $plan['reason'] = 'Left out.';
            $seenEmails[$email] = $i;
        } elseif (isset($existing[$email])) {
            $seenEmails[$email] = $i;
            $have = $existing[$email];
            $plan['user_id'] = (int) $have['id'];
            $plan['username'] = (string) $have['username'];
            if (!empty($opts['fill_existing'])) {
                foreach (['phone', 'department', 'notes'] as $k) {
                    if ($person[$k] !== '' && trim((string) ($have[$k] ?? '')) === '') {
                        $plan['changes'][$k] = $person[$k];
                    }
                }
            }
            // Roles on an existing account are only touched when asked: they
            // may have been set by hand here since the last export.
            $plan['set_roles'] = !empty($opts['roles_existing']);
            $plan['action'] = ($plan['changes'] || $plan['set_roles']) ? 'update' : 'same';
            $plan['reason'] = $plan['action'] === 'same' ? 'Already has an account.' : '';
        } else {
            $seenEmails[$email] = $i;
            // The username is always the part of the email before the @.
            // A clash is shown rather than worked around with a number on
            // the end, so nobody signs in as a name they wouldn't guess.
            $username = fm_username_for_email($email);
            if ($username === '') {
                $plan['reason'] = 'A username can\'t be made from this address.';
            } elseif (isset($taken[$username])) {
                $plan['username'] = $username;
                $plan['reason'] = 'The username ' . $username . ' already belongs to another account.';
            } else {
                $taken[$username] = true;
                $plan['username'] = $username;
                $plan['action'] = 'create';
            }
        }
        $plans[] = $plan;
    }
    return $plans;
}

/**
 * Carry out a plan from fm_import_plan().
 *
 * One transaction, with a savepoint per person: a clash on one row (someone
 * signed up with that address a moment ago) skips that row and keeps the
 * rest, but a crash part way through leaves nothing half-imported — which
 * matters because setup codes are shown once, at the end.
 *
 * @return list<array> the plans, with action/reason updated and 'code' set
 *   on every account created
 */
function fm_import_run(array $plans, int $adminId): array
{
    $db = fm_db();
    $db->beginTransaction();
    try {
        foreach ($plans as &$plan) {
            if ($plan['action'] === 'create') {
                $db->exec('SAVEPOINT person');
                [$id, $err] = fm_create_pending_user($plan['username'], $plan['person']);
                if ($id === 0) {
                    $db->exec('ROLLBACK TO SAVEPOINT person');
                    $plan['action'] = 'skip';
                    $plan['reason'] = $err === 'email_taken'
                        ? 'That email now belongs to another account.'
                        : 'That username was taken in the meantime.';
                    continue;
                }
                $db->exec('RELEASE SAVEPOINT person');
                $plan['user_id'] = $id;
                foreach ($plan['roles'] as $tool => $role) {
                    fm_set_tool_role($id, (string) $tool, (string) $role);
                }
                $plan['code'] = fm_issue_code($id, $adminId, FM_SETUP_CODE_HOURS);
            } elseif ($plan['action'] === 'update') {
                foreach ($plan['changes'] as $k => $v) {
                    // $k is one of three fixed names from fm_import_plan(),
                    // never from the request, so naming the column is safe.
                    $db->prepare("UPDATE users SET $k = ? WHERE id = ?")->execute([$v, $plan['user_id']]);
                }
                if (!empty($plan['set_roles'])) {
                    foreach ($plan['roles'] as $tool => $role) {
                        fm_set_tool_role($plan['user_id'], (string) $tool, (string) $role);
                    }
                }
            }
        }
        unset($plan);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    return $plans;
}

/**
 * Text that is about to be pasted into a spreadsheet: a leading = + - or @
 * would be run as a formula, so it gets an apostrophe in front.
 */
function fm_sheet_safe(string $value): string
{
    return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value;
}

/**
 * Gate an admin page: sign-in first, then admin rights. Returns the admin.
 */
function fm_require_admin(): array
{
    $me = fm_current_user();
    if ($me === null) {
        header('Location: ' . fm_auth_url_with_next('login.php', fm_current_url()));
        exit;
    }
    if (!$me['is_admin']) {
        require_once __DIR__ . '/page.php';
        http_response_code(403);
        fm_page_head('Not allowed');
        echo '<div class="card"><h1>Not allowed</h1>'
            . '<p class="note">This page is for admins. Use the bar above to get '
            . 'back to the tools.</p></div>';
        fm_page_foot();
        exit;
    }
    return $me;
}

/** Absolute address of the page where a setup code is redeemed. */
function fm_setup_url(string $username): string
{
    $url = fm_auth_url('reset.php') . '?setup=1&u=' . rawurlencode($username);
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    return $host === '' ? $url : (fm_is_https() ? 'https://' : 'http://') . $host . $url;
}
