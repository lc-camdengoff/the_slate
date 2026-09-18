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
// base64 JPEG data URLs (1600px, q0.82 — roughly 200-600 KB per shot), so a
// 30-shot board lands in the high single-digit megabytes.
const SLATE_MAX_BYTES = 33554432; // 32 MB

// Previous copies kept per board when a save overwrites an existing one.
const SLATE_VERSIONS_KEPT = 3;

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
 * The basic-auth user the web server already authenticated.
 *
 * Which variable carries it depends on the SAPI and on how LiteSpeed passes
 * the header through, so try the usual suspects and report which one worked —
 * list.php hands that back to the client, which falls back to a typed name
 * when none of them are populated.
 */
function slate_user(): array
{
    $candidates = ['PHP_AUTH_USER', 'REMOTE_USER', 'REDIRECT_REMOTE_USER'];
    foreach ($candidates as $key) {
        if (!empty($_SERVER[$key])) {
            return ['name' => slate_clean_text((string) $_SERVER[$key], 64), 'source' => $key];
        }
    }
    return ['name' => null, 'source' => null];
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
