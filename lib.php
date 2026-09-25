<?php
/**
 * The Slate — shared helpers for the team library endpoints.
 *
 * Storyboards are written to ../saved/, a sibling of this directory that is
 * deliberately outside the deploy target so a deploy can never delete the
 * team's work. Everything under /filmmaking/ already sits behind HTTP basic
 * auth, so these endpoints authenticate by reading the user the web server
 * already authenticated — they never handle credentials themselves.
 */

declare(strict_types=1);

// Hard ceiling on a single storyboard. Boards inline their reference images as
// base64 data URLs. New pictures are 1280px WebP at q0.72 (roughly 70-210 KB
// per shot); boards from before that carry 1600px JPEGs at q0.82 (200-600 KB),
// so an older 30-shot board can reach the high single-digit megabytes.
const SLATE_MAX_BYTES = 33554432; // 32 MB

// Previous copies kept per board when a save overwrites an existing one. One
// is enough to undo a bad save; each extra copy costs a whole board's worth
// of pictures. Older copies beyond this are pruned by slate_housekeeping().
const SLATE_VERSIONS_KEPT = 1;

// Deleted boards stay recoverable in saved/.trash for this long, then go.
const SLATE_TRASH_DAYS = 30;

// Pictures are re-encoded to this by the Storage page, matching what the app
// does to new uploads (shrinkImage() in src/template.html).
const SLATE_IMAGE_MAX_PX = 1280;
const SLATE_IMAGE_QUALITY = 72;
// Marker in a board's sidecar once its pictures have been through that.
const SLATE_IMAGES_SHRUNK = 1;

const SLATE_ID_RE = '/^[a-z0-9](?:[a-z0-9-]{0,51}[a-z0-9])?-[0-9a-f]{6}$/';

/** Absolute path to the shared storyboard folder, or null if unusable. */
function slate_saved_dir(): ?string
{
    $dir = realpath(__DIR__ . '/../saved');
    return ($dir !== false && is_dir($dir) && is_writable($dir)) ? $dir : null;
}

/** Path to an internal subfolder of saved/ (.versions, .trash, .tmp). */
function slate_internal_dir(string $saved, string $name): ?string
{
    $path = $saved . '/' . $name;
    if (!is_dir($path) && !@mkdir($path, 0755) && !is_dir($path)) {
        return null;
    }
    return $path;
}

/**
 * Who is making this request.
 *
 * Accounts live in PostgreSQL now (see auth.php), so this is the signed-in
 * user rather than a guess at whichever server variable happened to carry the
 * basic-auth username. Every endpoint requires a session, so there is no
 * fallback and no typed-name path left: attribution is always exact.
 */
function slate_user(): array
{
    $user = fm_current_user();
    if ($user === null) {
        return ['id' => 0, 'name' => null, 'username' => null, 'is_admin' => false];
    }
    return [
        'id' => (int) $user['id'],
        'name' => slate_clean_text((string) $user['display_name'], 64),
        'username' => (string) $user['username'],
        'is_admin' => (bool) $user['is_admin'],
    ];
}

/** Collapse untrusted text to something safe to store and display. */
function slate_clean_text(string $value, int $maxLen): string
{
    $value = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '';
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLen);
    }
    return substr($value, 0, $maxLen);
}

/** Filename-safe slug derived from a storyboard title. */
function slate_slug(string $title): string
{
    $slug = $title;
    if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $slug);
        if ($converted !== false) {
            $slug = $converted;
        }
    }
    $slug = strtolower($slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    $slug = substr($slug, 0, 45);
    $slug = trim($slug, '-');
    return $slug !== '' ? $slug : 'storyboard';
}

/**
 * A fresh board id: title slug plus a short random suffix.
 *
 * The suffix makes the id stable and collision-free, so renaming a board later
 * changes only its display title and two people can both save "Easter Promo".
 */
function slate_new_id(string $saved, string $title): string
{
    for ($i = 0; $i < 12; $i++) {
        $id = slate_slug($title) . '-' . bin2hex(random_bytes(3));
        if (!file_exists($saved . '/' . $id . '.json')) {
            return $id;
        }
    }
    throw new RuntimeException('could not allocate a board id');
}

function slate_is_id(string $id): bool
{
    // basename() as well as the pattern: belt and braces against traversal.
    return $id === basename($id) && preg_match(SLATE_ID_RE, $id) === 1;
}

function slate_board_path(string $saved, string $id): string
{
    return $saved . '/' . $id . '.json';
}

function slate_meta_path(string $saved, string $id): string
{
    return $saved . '/' . $id . '.meta.json';
}

/** Stored metadata for a board, or null when there is no readable sidecar. */
function slate_read_meta(string $saved, string $id): ?array
{
    $raw = @file_get_contents(slate_meta_path($saved, $id));
    if ($raw === false) {
        return null;
    }
    $meta = json_decode($raw, true);
    return is_array($meta) ? $meta : null;
}

/** Replace a board's sidecar in one step, so a reader never sees half of it. */
function slate_write_meta(string $saved, string $id, array $meta): bool
{
    $path = slate_meta_path($saved, $id);
    $tmp = $path . '.tmp';
    $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        return false;
    }
    @chmod($path, 0644);
    return true;
}

function slate_now_ms(): int
{
    return (int) round(microtime(true) * 1000);
}

/** Convert a php.ini shorthand size ("64M") to bytes. */
function slate_ini_bytes(string $key): int
{
    $value = trim((string) ini_get($key));
    if ($value === '' || $value === '-1') {
        return PHP_INT_MAX;
    }
    $unit = strtolower(substr($value, -1));
    $number = (int) $value;
    switch ($unit) {
        case 'g': return $number * 1024 * 1024 * 1024;
        case 'm': return $number * 1024 * 1024;
        case 'k': return $number * 1024;
        default: return $number;
    }
}

/**
 * Largest board this server will actually accept.
 *
 * When a request body exceeds post_max_size PHP discards it and the request
 * arrives looking like an empty success, so the client asks for this number up
 * front and refuses oversized saves itself with a message that makes sense.
 */
function slate_max_bytes(): int
{
    // Leave room for the request line, headers and the query string.
    $post = slate_ini_bytes('post_max_size') - 65536;
    return (int) max(0, min(SLATE_MAX_BYTES, $post));
}

function slate_json(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload, JSON_UNESCAPED_SLASHES);
    exit;
}

function slate_fail(int $status, string $error, array $extra = []): void
{
    slate_json($status, array_merge(['ok' => false, 'error' => $error], $extra));
}

/**
 * Require a same-origin write request.
 *
 * Basic auth means a browser will attach credentials to any cross-site form
 * post, so check the method and the Origin/Referer before mutating anything.
 */
function slate_require_write_request(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        header('Allow: POST');
        slate_fail(405, 'method_not_allowed');
    }

    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    if ($origin === '' && !empty($_SERVER['HTTP_REFERER'])) {
        $parts = parse_url((string) $_SERVER['HTTP_REFERER']);
        if (!empty($parts['scheme']) && !empty($parts['host'])) {
            $origin = $parts['scheme'] . '://' . $parts['host']
                . (isset($parts['port']) ? ':' . $parts['port'] : '');
        }
    }
    if ($origin === '') {
        // Same-origin fetch() sends no Origin on some browsers; a request with
        // neither header is not a cross-site form post either.
        return;
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    $expected = ($https ? 'https://' : 'http://') . $host;
    if (strcasecmp($origin, $expected) !== 0
        && strcasecmp($origin, 'http://' . $host) !== 0
        && strcasecmp($origin, 'https://' . $host) !== 0) {
        slate_fail(403, 'cross_origin');
    }
}

/**
 * Serialise writes to one board so two people saving at once cannot interleave
 * a version rotation with a rename. Returns the open lock handle.
 *
 * @return resource
 */
function slate_lock(string $saved, string $id)
{
    $dir = slate_internal_dir($saved, '.tmp');
    if ($dir === null) {
        slate_fail(500, 'storage_unavailable');
    }
    $handle = @fopen($dir . '/' . $id . '.lock', 'c');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        slate_fail(503, 'busy');
    }
    return $handle;
}

// ---------------------------------------------------------------------------
// Ownership and visibility
//
// A board is owned by the account that created it and is private by default.
// "Save to Library" promotes it to team-visible: same file, same id, the
// sidecar's visibility flips. Nothing moves on disk, so versions and trash
// keep working untouched.
//
// Boards that predate this are treated as team-visible with no owner, which
// is what they effectively were — everything in saved/ was visible to
// everyone. Being permissive about the old ones is deliberate: the alternative
// hides the team's existing work behind an owner they never had.
// ---------------------------------------------------------------------------

const SLATE_PRIVATE = 'private';
const SLATE_TEAM = 'team';

function slate_visibility(?array $meta): string
{
    $value = strtolower(trim((string) ($meta['visibility'] ?? '')));
    if ($value === SLATE_PRIVATE) {
        return SLATE_PRIVATE;
    }
    // Missing means it was saved before boards had owners.
    return SLATE_TEAM;
}

function slate_owner(?array $meta): string
{
    $owner = trim((string) ($meta['owner'] ?? ''));
    if ($owner !== '') {
        return $owner;
    }
    // Fall back to whoever last wrote it, which is the best we know.
    return trim((string) ($meta['savedByUser'] ?? ''));
}

function slate_owns(?array $meta, string $username): bool
{
    $owner = slate_owner($meta);
    return $owner !== '' && strcasecmp($owner, $username) === 0;
}

// A private board can also be shared with particular people, like a document:
// the sidecar's 'shares' maps account ids to SLATE_VIEW or SLATE_EDIT. Ids
// rather than usernames, so a rename changes nothing and a username given to
// someone new later never inherits another person's access.
const SLATE_VIEW = 'view';
const SLATE_EDIT = 'edit';

/** @return array<int, string> account id => SLATE_VIEW|SLATE_EDIT */
function slate_shares(?array $meta): array
{
    $out = [];
    foreach ((array) ($meta['shares'] ?? []) as $id => $level) {
        if ((int) $id > 0 && ($level === SLATE_VIEW || $level === SLATE_EDIT)) {
            $out[(int) $id] = $level;
        }
    }
    return $out;
}

/** $meta with its shares replaced; none at all leaves the key out. */
function slate_with_shares(array $meta, array $shares): array
{
    unset($meta['shares']);
    $clean = [];
    foreach ($shares as $id => $level) {
        if ((int) $id > 0 && ($level === SLATE_VIEW || $level === SLATE_EDIT)) {
            $clean[(string) (int) $id] = $level;
        }
    }
    if ($clean) {
        // An object in the JSON even for one id, never a list.
        $meta['shares'] = (object) $clean;
    }
    return $meta;
}

/**
 * People who opened a link to the board without access and asked for it:
 * account id => ['level' => SLATE_VIEW|SLATE_EDIT, 'at' => ms]. The owner
 * answers them in the Share panel; being given access clears the request.
 *
 * @return array<int, array{level: string, at: int}>
 */
function slate_requests(?array $meta): array
{
    $out = [];
    foreach ((array) ($meta['requests'] ?? []) as $id => $request) {
        $request = (array) $request;
        $level = (string) ($request['level'] ?? '');
        if ((int) $id > 0 && ($level === SLATE_VIEW || $level === SLATE_EDIT)) {
            $out[(int) $id] = ['level' => $level, 'at' => (int) ($request['at'] ?? 0)];
        }
    }
    return $out;
}

/** $meta with its requests replaced; none at all leaves the key out. */
function slate_with_requests(array $meta, array $requests): array
{
    unset($meta['requests']);
    $clean = [];
    foreach ($requests as $id => $request) {
        if ((int) $id > 0) {
            $clean[(string) (int) $id] = ['level' => (string) $request['level'], 'at' => (int) $request['at']];
        }
    }
    if ($clean) {
        $meta['requests'] = (object) $clean;
    }
    return $meta;
}

// More waiting than this on one board and the oldest make way.
const SLATE_MAX_REQUESTS = 50;

/**
 * Run $work once the response has gone to the browser, so sending email
 * never makes anyone wait. Where the server cannot let go of the request
 * early it still runs, just before the connection closes.
 */
function slate_after_response(callable $work): void
{
    register_shutdown_function(static function () use ($work) {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }
        try {
            $work();
        } catch (Throwable $e) {
            error_log('slate: after-response work failed: ' . $e->getMessage());
        }
    });
}

/** Account id for a username, or 0. */
function slate_account_id(string $username): int
{
    if ($username === '') {
        return 0;
    }
    $stmt = fm_db()->prepare('SELECT id FROM users WHERE username_ci = ?');
    $stmt->execute([strtolower($username)]);
    return (int) ($stmt->fetch()['id'] ?? 0);
}

/** The Slate's own address for a board, from an email. */
function slate_board_link(string $id, bool $openShare = false): string
{
    return fm_absolute_url(fm_base_path() . 'slate/#board=' . $id . ($openShare ? '&share=1' : ''));
}

/** Tell the people just given access. Needs auth/mailer.php. */
function slate_mail_shared(array $meta, array $added, string $byName): void
{
    if (!$added || !fm_mail_enabled()) {
        return;
    }
    $title = trim((string) ($meta['title'] ?? '')) ?: 'Untitled Storyboard';
    $link = slate_board_link((string) $meta['id']);
    foreach (fm_mail_recipients(array_keys($added)) as $personId => $to) {
        $can = $added[$personId] === SLATE_EDIT ? 'edit' : 'view';
        $lines = [
            $byName . ' shared the storyboard “' . $title . '” with you. You can ' . $can . ' it.',
            'It’s in Your Storyboards in the Slate.',
        ];
        fm_send_mail($to['email'], $byName . ' shared “' . $title . '” with you',
            implode("\n\n", $lines) . "\n\nOpen it: " . $link . "\n",
            fm_mail_html('Shared with you', $lines, 'Open storyboard', $link));
    }
}

/** Tell a board's owner someone is asking for access. Needs auth/mailer.php. */
function slate_mail_request(array $meta, string $level, string $byName, string $byUsername): void
{
    if (!fm_mail_enabled()) {
        return;
    }
    $owner = slate_account_id(slate_owner($meta));
    $to = fm_mail_recipients([$owner])[$owner] ?? null;
    if ($to === null) {
        return;
    }
    $title = trim((string) ($meta['title'] ?? '')) ?: 'Untitled Storyboard';
    $link = slate_board_link((string) $meta['id'], true);
    $what = $level === SLATE_EDIT ? 'edit' : 'view';
    $lines = [
        $byName . ' (' . $byUsername . ') opened a link to your storyboard “' . $title . '” and is asking to ' . $what . ' it.',
        'Open the Share panel to allow or decline.',
    ];
    fm_send_mail($to['email'], $byName . ' is asking to ' . $what . ' “' . $title . '”',
        implode("\n\n", $lines) . "\n\nAnswer the request: " . $link . "\n",
        fm_mail_html('Access request', $lines, 'Answer the request', $link));
}

/**
 * Display names for usernames, keyed by lower-case username. One query.
 *
 * @param list<string> $usernames
 * @return array<string, string>
 */
function slate_display_names(array $usernames): array
{
    $keys = array_values(array_unique(array_map('strtolower', $usernames)));
    if (!$keys) {
        return [];
    }
    $stmt = fm_db()->prepare('SELECT username_ci, display_name FROM users WHERE username_ci IN ('
        . implode(',', array_fill(0, count($keys), '?')) . ')');
    $stmt->execute($keys);
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(string) $row['username_ci']] = (string) $row['display_name'];
    }
    return $out;
}

/**
 * Everyone a board can be shared with: active accounts that may use the
 * Slate, by name. Accounts an admin added that nobody has signed in to yet
 * are included, marked pending; sharing waits for them.
 *
 * @return array<int, array{id: int, name: string, username: string, pending: bool}>
 */
function slate_team_people(): array
{
    $tool = fm_tool('slate');
    $stmt = fm_db()->prepare(
        "SELECT u.id, u.username, u.display_name, (u.password_hash = '') AS pending, r.role
           FROM users u
           LEFT JOIN user_tool_roles r ON r.user_id = u.id AND r.tool = 'slate'
          WHERE u.is_active
          ORDER BY lower(u.display_name), u.id"
    );
    $stmt->execute();
    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        // The same rule as fm_tool_roles(), for everyone at once.
        $role = $row['role'] === null ? (string) ($tool['default_role'] ?? FM_NO_ACCESS) : (string) $row['role'];
        if ($role === FM_NO_ACCESS || !isset($tool['roles'][$role])) {
            continue;
        }
        $out[(int) $row['id']] = [
            'id' => (int) $row['id'],
            'name' => (string) $row['display_name'],
            'username' => (string) $row['username'],
            'pending' => (bool) $row['pending'],
        ];
    }
    return $out;
}

/**
 * What this account may do with the board: 'owner', SLATE_EDIT, SLATE_VIEW,
 * or '' for nothing. $userId 0 leaves shares out of it.
 */
function slate_access(?array $meta, string $username, int $userId = 0): string
{
    if (slate_owns($meta, $username)) {
        return 'owner';
    }
    $shared = $userId > 0 ? (slate_shares($meta)[$userId] ?? '') : '';
    if (slate_visibility($meta) === SLATE_TEAM || $shared === SLATE_EDIT) {
        return SLATE_EDIT;
    }
    return $shared;
}

/** May this account read the board? */
function slate_can_read(?array $meta, string $username, int $userId = 0): bool
{
    return slate_access($meta, $username, $userId) !== '';
}

/**
 * May this account write to the board?
 *
 * Team boards stay editable by anyone, as they were before ownership existed
 * — the conflict check is what stops two people clobbering each other, not a
 * permission. A private board is its owner's, and whoever they gave edit to.
 */
function slate_can_write(?array $meta, string $username, int $userId = 0): bool
{
    return in_array(slate_access($meta, $username, $userId), ['owner', SLATE_EDIT], true);
}

/**
 * May this account change who the board is shared with, or delete it while
 * it is private? Its owner, and admins. A board from before owners existed
 * has nobody to ask, so on those anyone who can write may.
 */
function slate_can_manage(?array $meta, array $user): bool
{
    return !empty($user['is_admin'])
        || slate_owns($meta, (string) $user['username'])
        || (slate_owner($meta) === '' && slate_visibility($meta) === SLATE_TEAM);
}

// ---------------------------------------------------------------------------
// Trash
// ---------------------------------------------------------------------------

/**
 * Move a board, its sidecar and its old versions into saved/.trash.
 *
 * Nothing is destroyed: a board in the trash can be put back by moving its
 * files out again. The caller holds the board's lock.
 */
function slate_trash_board(string $saved, string $id, string $byUser = '', string $byName = ''): bool
{
    $trash = slate_internal_dir($saved, '.trash');
    if ($trash === null) {
        return false;
    }
    $stamp = slate_now_ms();
    $prefix = $trash . '/' . $id . '.' . $stamp;
    $meta = slate_read_meta($saved, $id);
    if (!@rename(slate_board_path($saved, $id), $prefix . '.json')) {
        return false;
    }
    @touch($prefix . '.json'); // the 30-day clock starts now, not at last save
    // Who deleted it and when, for the Trash list. Written fresh rather than
    // moved, so a board with no sidecar still gets one there.
    $meta = is_array($meta) ? $meta : ['id' => $id];
    $meta['deletedAt'] = $stamp;
    if ($byUser !== '') {
        $meta['deletedByUser'] = $byUser;
        $meta['deletedBy'] = $byName !== '' ? $byName : $byUser;
    }
    if (@file_put_contents($prefix . '.meta.json', json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
        @unlink(slate_meta_path($saved, $id));
    } elseif (@rename(slate_meta_path($saved, $id), $prefix . '.meta.json')) {
        @touch($prefix . '.meta.json');
    }

    $versions = $saved . '/.versions';
    if (is_dir($versions)) {
        foreach (glob($versions . '/' . $id . '.*.json') ?: [] as $old) {
            $to = $trash . '/' . basename($old);
            if (@rename($old, $to)) {
                @touch($to);
            }
        }
    }
    return true;
}

// ---------------------------------------------------------------------------
// Trash: listing and restoring
//
// A deleted board is <id>.<deleted-ms>.json with <id>.<deleted-ms>.meta.json
// beside it; its previous copy moved in as <id>.<saved-ms>.json with no
// sidecar. Who may see an entry is the library's rule — team boards, and
// your own private ones — plus admins, who see all so they can rescue any.
// ---------------------------------------------------------------------------

/** When a trash file was deleted, in seconds: the later of its time and name. */
function slate_trash_time(string $file): int
{
    $when = (int) @filemtime($file);
    if (preg_match('/\.(\d{13})(\.meta)?\.json$/', basename($file), $m)) {
        $when = max($when, intdiv((int) $m[1], 1000));
    }
    return $when;
}

/**
 * Deleted boards this person may see, newest first.
 *
 * @return list<array{id: string, stamp: string, title: string, owner: string, visibility: string,
 *   shotCount: int, deletedAt: int, deletedBy: string, purgeAt: int}>
 */
function slate_trash_list(string $username, bool $isAdmin): array
{
    $saved = slate_saved_dir();
    $trash = $saved === null ? '' : $saved . '/.trash';
    if ($trash === '' || !is_dir($trash)) {
        return [];
    }
    $out = [];
    foreach (glob($trash . '/*.meta.json') ?: [] as $metaFile) {
        if (!preg_match('/^(.+)\.(\d{13})\.meta\.json$/', basename($metaFile), $m) || !slate_is_id($m[1])) {
            continue;
        }
        [$all, $id, $stamp] = $m;
        if (!is_file($trash . '/' . $id . '.' . $stamp . '.json')) {
            continue;
        }
        $meta = json_decode((string) @file_get_contents($metaFile), true);
        $meta = is_array($meta) ? $meta : [];
        if (!$isAdmin && !slate_can_read($meta, $username)) {
            continue;
        }
        $deleted = isset($meta['deletedAt']) ? intdiv((int) $meta['deletedAt'], 1000) : slate_trash_time($metaFile);
        $out[] = [
            'id' => $id,
            'stamp' => $stamp,
            'title' => (string) ($meta['title'] ?? '') !== '' ? (string) $meta['title'] : 'Untitled Storyboard',
            'owner' => slate_owner($meta),
            'visibility' => slate_visibility($meta),
            'shotCount' => (int) ($meta['shotCount'] ?? 0),
            'deletedAt' => $deleted * 1000,
            'deletedBy' => (string) ($meta['deletedBy'] ?? ''),
            'purgeAt' => ($deleted + SLATE_TRASH_DAYS * 86400) * 1000,
        ];
    }
    usort($out, static fn($a, $b) => $b['deletedAt'] <=> $a['deletedAt']);
    return $out;
}

/**
 * Put a deleted board back where it was, with its previous copy.
 *
 * @return string '' on success, otherwise an error key: not_found,
 *   not_yours, exists, storage_unavailable, restore_failed
 */
function slate_restore_board(string $id, string $stamp, string $username, bool $isAdmin): string
{
    $saved = slate_saved_dir();
    if ($saved === null) {
        return 'storage_unavailable';
    }
    if (!slate_is_id($id) || !preg_match('/^\d{13}$/', $stamp)) {
        return 'not_found';
    }
    $trash = $saved . '/.trash';
    $board = $trash . '/' . $id . '.' . $stamp . '.json';
    $metaFile = $trash . '/' . $id . '.' . $stamp . '.meta.json';
    if (!is_file($board)) {
        return 'not_found';
    }
    $meta = json_decode((string) @file_get_contents($metaFile), true);
    $meta = is_array($meta) ? $meta : ['id' => $id];
    if (!$isAdmin && !slate_can_read($meta, $username)) {
        return 'not_found'; // someone else's private board: not even acknowledged
    }

    $lockDir = slate_internal_dir($saved, '.tmp');
    $lock = $lockDir === null ? false : @fopen($lockDir . '/' . $id . '.lock', 'c');
    if ($lock === false || !flock($lock, LOCK_EX)) {
        return 'restore_failed';
    }
    try {
        if (file_exists(slate_board_path($saved, $id))) {
            return 'exists'; // restored already, from another tab
        }
        if (!@rename($board, slate_board_path($saved, $id))) {
            return 'restore_failed';
        }
        unset($meta['deletedAt'], $meta['deletedBy'], $meta['deletedByUser']);
        // Moved on, so every browser treats it as new and fetches it.
        $meta['updatedAt'] = max(slate_now_ms(), (int) ($meta['updatedAt'] ?? 0) + 1);
        @file_put_contents(slate_meta_path($saved, $id), json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        @unlink($metaFile);

        // Its previous copy: the newest trashed <id>.<ms>.json with no sidecar.
        $versions = slate_internal_dir($saved, '.versions');
        $copies = [];
        foreach (glob($trash . '/' . $id . '.*.json') ?: [] as $file) {
            if (preg_match('/^' . preg_quote($id, '/') . '\.(\d{13})\.json$/', basename($file), $v)
                && !is_file($trash . '/' . $id . '.' . $v[1] . '.meta.json')) {
                $copies[(int) $v[1]] = $file;
            }
        }
        krsort($copies, SORT_NUMERIC);
        foreach (array_slice($copies, 0, SLATE_VERSIONS_KEPT, true) as $file) {
            if ($versions !== null) {
                @rename($file, $versions . '/' . basename($file));
            }
        }
        return '';
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

// ---------------------------------------------------------------------------
// Housekeeping
// ---------------------------------------------------------------------------

/**
 * Prune previous copies beyond SLATE_VERSIONS_KEPT and empty trash older than
 * SLATE_TRASH_DAYS. Cheap to call often: it does the work at most once an
 * hour unless forced, using a marker file's age.
 *
 * @return array{ran: bool, versions: int, trash: int, bytes: int}
 */
function slate_housekeeping(bool $force = false): array
{
    $result = ['ran' => false, 'versions' => 0, 'trash' => 0, 'bytes' => 0];
    $saved = slate_saved_dir();
    if ($saved === null) {
        return $result;
    }
    $tmp = slate_internal_dir($saved, '.tmp');
    $marker = $tmp === null ? null : $tmp . '/housekeeping';
    if (!$force && $marker !== null && is_file($marker) && filemtime($marker) > time() - 3600) {
        return $result;
    }
    if ($marker !== null) {
        @touch($marker);
    }
    $result['ran'] = true;

    // Previous copies: newest SLATE_VERSIONS_KEPT per board survive.
    $versions = $saved . '/.versions';
    if (is_dir($versions)) {
        $byBoard = [];
        foreach (glob($versions . '/*.json') ?: [] as $file) {
            if (preg_match('/^(.+)\.(\d+)\.json$/', basename($file), $m) && slate_is_id($m[1])) {
                $byBoard[$m[1]][(int) $m[2]] = $file;
            }
        }
        foreach ($byBoard as $files) {
            krsort($files, SORT_NUMERIC);
            foreach (array_slice($files, SLATE_VERSIONS_KEPT, null, true) as $file) {
                $size = (int) @filesize($file);
                if (@unlink($file)) {
                    $result['versions']++;
                    $result['bytes'] += $size;
                }
            }
        }
    }

    // Trash: by when it was deleted. Files trashed before trashing touched
    // them still carry the deletion time in their name, so take the later of
    // the two rather than purge a board deleted yesterday but last saved in
    // the spring.
    $trash = $saved . '/.trash';
    if (is_dir($trash)) {
        $cutoff = time() - SLATE_TRASH_DAYS * 86400;
        foreach (glob($trash . '/*.json') ?: [] as $file) {
            $when = slate_trash_time($file);
            if ($when > 0 && $when < $cutoff) {
                $size = (int) @filesize($file);
                if (@unlink($file)) {
                    $result['trash']++;
                    $result['bytes'] += $size;
                }
            }
        }
    }
    return $result;
}

/**
 * What saved/ holds, for the Storage page.
 *
 * @return array<string, array{files: int, bytes: int}> boards, versions, trash
 */
function slate_storage_stats(): array
{
    $out = ['boards' => ['files' => 0, 'bytes' => 0], 'versions' => ['files' => 0, 'bytes' => 0],
            'trash' => ['files' => 0, 'bytes' => 0], 'unshrunk' => ['files' => 0, 'bytes' => 0]];
    $saved = slate_saved_dir();
    if ($saved === null) {
        return $out;
    }
    foreach (glob($saved . '/*.json') ?: [] as $file) {
        if (substr($file, -10) === '.meta.json') {
            continue;
        }
        $size = (int) @filesize($file);
        $out['boards']['files']++;
        $out['boards']['bytes'] += $size;
        $id = basename($file, '.json');
        $meta = slate_is_id($id) ? slate_read_meta($saved, $id) : null;
        if ((int) ($meta['imagesShrunk'] ?? 0) < SLATE_IMAGES_SHRUNK) {
            $out['unshrunk']['files']++;
            $out['unshrunk']['bytes'] += $size;
        }
    }
    foreach (['versions' => '.versions', 'trash' => '.trash'] as $key => $dir) {
        foreach (glob($saved . '/' . $dir . '/*.json') ?: [] as $file) {
            $out[$key]['files']++;
            $out[$key]['bytes'] += (int) @filesize($file);
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Shrinking pictures already stored
// ---------------------------------------------------------------------------

/** 'webp', 'jpeg' or '' — what this server's PHP can re-encode pictures as. */
function slate_image_encoder(): string
{
    if (!function_exists('imagecreatefromstring') || !function_exists('imagecopyresampled')) {
        return '';
    }
    if (function_exists('imagewebp') && (gd_info()['WebP Support'] ?? false)) {
        return 'webp';
    }
    return function_exists('imagejpeg') ? 'jpeg' : '';
}

/**
 * A picture re-encoded to SLATE_IMAGE_MAX_PX / SLATE_IMAGE_QUALITY, or null
 * when it is already that small, not a picture we understand, or would not
 * get any smaller.
 */
function slate_shrink_data_url(string $url, string $encoder): ?string
{
    if ($encoder === '' || !preg_match('#^data:image/(jpeg|jpg|png|webp|gif);base64,#i', $url, $m)) {
        return null;
    }
    $bytes = base64_decode(substr($url, strlen($m[0])), true);
    if ($bytes === false || $bytes === '') {
        return null;
    }
    $info = @getimagesizefromstring($bytes);
    if (!$info || $info[0] < 1 || $info[1] < 1) {
        return null;
    }
    [$w, $h] = $info;
    $mime = strtolower((string) ($info['mime'] ?? ''));
    $longest = max($w, $h);
    if ($longest <= SLATE_IMAGE_MAX_PX && $mime === 'image/' . $encoder) {
        return null; // already what a new upload would be
    }
    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        return null;
    }
    $scale = min(1, SLATE_IMAGE_MAX_PX / $longest);
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    // White under anything transparent, as the app does, rather than black.
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    ob_start();
    $ok = $encoder === 'webp' ? imagewebp($dst, null, SLATE_IMAGE_QUALITY) : imagejpeg($dst, null, SLATE_IMAGE_QUALITY);
    $out = (string) ob_get_clean();
    imagedestroy($dst);
    if (!$ok || $out === '' || strlen($out) >= strlen($bytes)) {
        return null;
    }
    return 'data:image/' . $encoder . ';base64,' . base64_encode($out);
}

/**
 * Re-encode every oversized picture in one stored board file, in place.
 *
 * @return array{0: int, 1: int, 2: int} [pictures changed, bytes before, bytes after]
 */
function slate_shrink_board_file(string $path, string $encoder): array
{
    $raw = @file_get_contents($path);
    $before = $raw === false ? 0 : strlen($raw);
    $board = $raw === false ? null : json_decode($raw, true);
    if (!is_array($board) || !is_array($board['frames'] ?? null)) {
        return [0, $before, $before];
    }
    $changed = 0;
    foreach ($board['frames'] as &$frame) {
        if (is_array($frame) && is_string($frame['image'] ?? null)) {
            $smaller = slate_shrink_data_url($frame['image'], $encoder);
            if ($smaller !== null) {
                $frame['image'] = $smaller;
                $changed++;
            }
        }
    }
    unset($frame);
    if ($changed === 0) {
        return [0, $before, $before];
    }
    $json = json_encode($board, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $tmp = $path . '.shrink.tmp';
    if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $path)) {
        @unlink($tmp);
        return [0, $before, $before];
    }
    @chmod($path, 0644);
    return [$changed, $before, strlen($json)];
}

/**
 * Work through boards whose pictures predate the smaller format, until done
 * or out of time. Resumable: each finished board is marked in its sidecar.
 *
 * A board is locked while it is rewritten, the same lock save.php takes. Its
 * revision moves on by one millisecond — the date people see is unchanged —
 * so a browser holding the old copy fetches the new one, and anyone with it
 * open is told it changed elsewhere rather than silently saving the big
 * pictures back. Boards saved in the last ten minutes are left for a later
 * run, since someone is probably still working on them.
 *
 * @return array{boards: int, pictures: int, bytes: int, remaining: int, skipped: int}
 */
function slate_shrink_boards(string $encoder, float $seconds): array
{
    $out = ['boards' => 0, 'pictures' => 0, 'bytes' => 0, 'remaining' => 0, 'skipped' => 0];
    $saved = slate_saved_dir();
    if ($saved === null || $encoder === '') {
        return $out;
    }
    $deadline = microtime(true) + $seconds;
    $lockDir = slate_internal_dir($saved, '.tmp');
    $recent = slate_now_ms() - 10 * 60 * 1000;

    foreach (glob($saved . '/*.meta.json') ?: [] as $metaPath) {
        $id = substr(basename($metaPath), 0, -strlen('.meta.json'));
        $meta = slate_is_id($id) ? slate_read_meta($saved, $id) : null;
        if (!$meta || (int) ($meta['imagesShrunk'] ?? 0) >= SLATE_IMAGES_SHRUNK) {
            continue;
        }
        if ((int) ($meta['updatedAt'] ?? 0) > $recent) {
            $out['skipped']++;
            $out['remaining']++;
            continue;
        }
        if (microtime(true) > $deadline) {
            $out['remaining']++;
            continue;
        }
        $lock = $lockDir === null ? false : @fopen($lockDir . '/' . $id . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            $out['remaining']++;
            continue;
        }
        try {
            $meta = slate_read_meta($saved, $id); // again, under the lock
            if (!$meta) {
                continue;
            }
            [$pics, $before, $after] = slate_shrink_board_file(slate_board_path($saved, $id), $encoder);
            // Its previous copy too: otherwise the old pictures live on there.
            foreach (glob($saved . '/.versions/' . $id . '.*.json') ?: [] as $version) {
                [$vp, $vb, $va] = slate_shrink_board_file($version, $encoder);
                $out['bytes'] += $vb - $va;
            }
            $meta['imagesShrunk'] = SLATE_IMAGES_SHRUNK;
            if ($pics > 0) {
                $meta['updatedAt'] = (int) ($meta['updatedAt'] ?? 0) + 1;
                $meta['bytes'] = $after;
                $out['pictures'] += $pics;
                $out['bytes'] += $before - $after;
            }
            $tmp = $metaPath . '.tmp';
            if (@file_put_contents($tmp, json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)) !== false) {
                @rename($tmp, $metaPath);
            }
            $out['boards']++;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    return $out;
}

// ---------------------------------------------------------------------------
// Account changes
//
// Boards record people by username (owner, savedByUser, createdByUser), so
// when an account is renamed or deleted on the Team admin page, the boards
// have to follow. auth/ calls these through the 'hooks' entry in tools.php;
// they never run from a request to the Slate itself.
// ---------------------------------------------------------------------------

/** The username fields a sidecar can carry. */
const SLATE_PERSON_FIELDS = ['owner', 'savedByUser', 'createdByUser'];

/**
 * Apply $change to the sidecar of every board that names $username.
 *
 * $change gets the decoded sidecar and returns the new one, or null to leave
 * that board alone. Each board is locked while it is rewritten, the same lock
 * save.php takes, so this cannot interleave with somebody saving.
 *
 * @return array{0: int, 1: int} [boards changed, boards that failed]
 */
function slate_each_board_of(string $username, callable $change, int $userId = 0): array
{
    $saved = slate_saved_dir();
    if ($saved === null || ($username === '' && $userId <= 0)) {
        return [0, 0];
    }
    $lockDir = slate_internal_dir($saved, '.tmp');
    $changed = 0;
    $failed = 0;

    foreach (glob($saved . '/*.meta.json') ?: [] as $metaPath) {
        $id = substr(basename($metaPath), 0, -strlen('.meta.json'));
        if (!slate_is_id($id)) {
            continue;
        }
        $meta = slate_read_meta($saved, $id);
        // Shared with them counts as naming them, when their id is given.
        $names = $userId > 0 && (isset(slate_shares($meta)[$userId]) || isset(slate_requests($meta)[$userId]));
        foreach (SLATE_PERSON_FIELDS as $field) {
            if ($username !== '' && strcasecmp(trim((string) ($meta[$field] ?? '')), $username) === 0) {
                $names = true;
            }
        }
        if (!$meta || !$names) {
            continue;
        }

        $lock = $lockDir === null ? false : @fopen($lockDir . '/' . $id . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            $failed++;
            continue;
        }
        try {
            // Read again under the lock: a save may have landed in between.
            $meta = slate_read_meta($saved, $id);
            $next = $meta === null ? null : $change($saved, $id, $meta);
            if ($next === true) {
                $changed++; // handled it itself (moved to trash)
            } elseif (is_array($next)) {
                $tmp = $metaPath . '.tmp';
                $json = json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json !== false && @file_put_contents($tmp, $json) !== false && @rename($tmp, $metaPath)) {
                    $changed++;
                } else {
                    @unlink($tmp);
                    $failed++;
                }
            } elseif ($next === false) {
                $failed++;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    return [$changed, $failed];
}

/** Rename a person on every board that names them. */
function slate_account_renamed(string $old, string $new): array
{
    $result = slate_each_board_of($old, static function (string $saved, string $id, array $meta) use ($old, $new) {
        foreach (SLATE_PERSON_FIELDS as $field) {
            if (strcasecmp(trim((string) ($meta[$field] ?? '')), $old) === 0) {
                $meta[$field] = $new;
            }
        }
        return $meta;
    });
    $result[1] += slate_rename_in_trash($old, $new);
    return $result;
}

/**
 * The same rename, for boards already in the trash: otherwise whoever is
 * next given the old username would see its owner's deleted private boards
 * in their Trash, and could restore them.
 *
 * @return int sidecars that could not be rewritten
 */
function slate_rename_in_trash(string $from, string $to): int
{
    $saved = slate_saved_dir();
    if ($saved === null || !is_dir($saved . '/.trash')) {
        return 0;
    }
    $failed = 0;
    foreach (glob($saved . '/.trash/*.meta.json') ?: [] as $file) {
        $meta = json_decode((string) @file_get_contents($file), true);
        if (!is_array($meta)) {
            continue;
        }
        $changed = false;
        foreach (array_merge(SLATE_PERSON_FIELDS, ['deletedByUser']) as $field) {
            if (strcasecmp(trim((string) ($meta[$field] ?? '')), $from) === 0) {
                $meta[$field] = $to;
                $changed = true;
            }
        }
        if ($changed) {
            // Rewriting must not restart the 30-day clock: keep when it was
            // deleted, both in the sidecar and as the file's own time.
            $when = slate_trash_time($file);
            if (!isset($meta['deletedAt']) && $when > 0) {
                $meta['deletedAt'] = $when * 1000;
            }
            $tmp = $file . '.tmp';
            $json = json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false || @file_put_contents($tmp, $json) === false || !@rename($tmp, $file)) {
                @unlink($tmp);
                $failed++;
            } elseif ($when > 0) {
                @touch($file, $when);
            }
        }
    }
    return $failed;
}

/**
 * Someone's account is gone.
 *
 * Their private boards go to the trash: nobody else could open them anyway,
 * and leaving them would hand them to whoever is next given that username.
 * Team boards stay in the library, still credited to them by name, but the
 * username on them becomes one no account can have, for the same reason.
 * Boards shared with them just stop being.
 */
function slate_account_deleted(string $username, int $userId = 0): array
{
    $gone = '(deleted) ' . $username;
    $result = slate_each_board_of($username, static function (string $saved, string $id, array $meta) use ($username, $userId, $gone) {
        if (slate_visibility($meta) === SLATE_PRIVATE && slate_owns($meta, $username)) {
            return slate_trash_board($saved, $id, $username, 'Account removal');
        }
        foreach (SLATE_PERSON_FIELDS as $field) {
            if (strcasecmp(trim((string) ($meta[$field] ?? '')), $username) === 0) {
                $meta[$field] = $gone;
            }
        }
        if ($userId > 0 && isset($meta['shares'])) {
            $meta = slate_with_shares($meta, array_diff_key(slate_shares($meta), [$userId => true]));
        }
        if ($userId > 0 && isset($meta['requests'])) {
            $meta = slate_with_requests($meta, array_diff_key(slate_requests($meta), [$userId => true]));
        }
        return $meta;
    }, $userId);
    // Including what was just trashed above, so its owner is a username no
    // account can have and only an admin can see or restore it.
    $result[1] += slate_rename_in_trash($username, $gone);
    return $result;
}

/** 12.3 MB, for people. */
function slate_format_bytes(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    }
    $units = ['KB', 'MB', 'GB', 'TB'];
    $value = $bytes / 1024;
    $i = 0;
    while ($value >= 1024 && $i < count($units) - 1) {
        $value /= 1024;
        $i++;
    }
    return ($value >= 100 ? round($value) : round($value, 1)) . ' ' . $units[$i];
}
