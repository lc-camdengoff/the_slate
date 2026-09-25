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
        return ['name' => null, 'username' => null, 'is_admin' => false];
    }
    return [
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

/** May this account read the board? */
function slate_can_read(?array $meta, string $username): bool
{
    return slate_visibility($meta) === SLATE_TEAM || slate_owns($meta, $username);
}

/**
 * May this account write to the board?
 *
 * Team boards stay editable by anyone, as they were before ownership existed
 * — the conflict check is what stops two people clobbering each other, not a
 * permission. A private board is only its owner's.
 */
function slate_can_write(?array $meta, string $username): bool
{
    return slate_visibility($meta) === SLATE_TEAM || slate_owns($meta, $username);
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
function slate_trash_board(string $saved, string $id): bool
{
    $trash = slate_internal_dir($saved, '.trash');
    if ($trash === null) {
        return false;
    }
    $prefix = $trash . '/' . $id . '.' . slate_now_ms();
    if (!@rename(slate_board_path($saved, $id), $prefix . '.json')) {
        return false;
    }
    @touch($prefix . '.json'); // the 30-day clock starts now, not at last save
    if (@rename(slate_meta_path($saved, $id), $prefix . '.meta.json')) {
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
            $when = (int) @filemtime($file);
            if (preg_match('/\.(\d{13})(\.meta)?\.json$/', basename($file), $m)) {
                $when = max($when, intdiv((int) $m[1], 1000));
            }
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
function slate_each_board_of(string $username, callable $change): array
{
    $saved = slate_saved_dir();
    if ($saved === null || $username === '') {
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
        $names = false;
        foreach (SLATE_PERSON_FIELDS as $field) {
            if (strcasecmp(trim((string) ($meta[$field] ?? '')), $username) === 0) {
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
    return slate_each_board_of($old, static function (string $saved, string $id, array $meta) use ($old, $new) {
        foreach (SLATE_PERSON_FIELDS as $field) {
            if (strcasecmp(trim((string) ($meta[$field] ?? '')), $old) === 0) {
                $meta[$field] = $new;
            }
        }
        return $meta;
    });
}

/**
 * Someone's account is gone.
 *
 * Their private boards go to the trash: nobody else could open them anyway,
 * and leaving them would hand them to whoever is next given that username.
 * Team boards stay in the library, still credited to them by name, but the
 * username on them becomes one no account can have, for the same reason.
 */
function slate_account_deleted(string $username): array
{
    $gone = '(deleted) ' . $username;
    return slate_each_board_of($username, static function (string $saved, string $id, array $meta) use ($username, $gone) {
        if (slate_visibility($meta) === SLATE_PRIVATE && slate_owns($meta, $username)) {
            return slate_trash_board($saved, $id);
        }
        foreach (SLATE_PERSON_FIELDS as $field) {
            if (strcasecmp(trim((string) ($meta[$field] ?? '')), $username) === 0) {
                $meta[$field] = $gone;
            }
        }
        return $meta;
    });
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
