<?php
/**
 * The Slate — the front door.
 *
 * Serves the app bundle to signed-in users and sends everyone else to the
 * sign-in page. The bundle itself is app.html, which .htaccess blocks from
 * being requested directly, so the only way to the tool is through here.
 */

declare(strict_types=1);

const SLATE_BUNDLE = __DIR__ . '/app.html';

/** A readable page when the server is not configured yet, instead of a 500. */
function slate_setup_error(string $detail): void
{
    http_response_code(503);
    require __DIR__ . '/../auth/page.php';
    fm_page_head('Setup needed');
    echo '<div class="card"><div class="eyebrow">The Slate</div>'
        . '<h1>Not set up yet</h1>'
        . '<div class="msg bad">' . fm_h($detail) . '</div>'
        . '<p class="note">See DEPLOY.md for the database and config steps.</p>'
        . '</div>';
    fm_page_foot();
    exit;
}

try {
    require __DIR__ . '/../auth/auth.php';
    // Touch the database before deciding anything. Without this, a missing
    // config or an unreachable server would just bounce to the sign-in page,
    // which fails with a generic error instead of saying what is wrong.
    fm_db();
// PDOException extends RuntimeException, so it has to be caught first or the
// branch below would swallow it and print the driver message.
} catch (PDOException $e) {
    error_log('slate: ' . $e->getMessage());
    slate_setup_error('Cannot reach the database. Check the details in '
        . '.filmmaking-config.php and that the PostgreSQL user has access to the database.');
} catch (RuntimeException $e) {
    error_log('slate: ' . $e->getMessage());
    slate_setup_error($e->getMessage() === 'no_config'
        ? 'The configuration file is missing. Copy auth/config.sample.php to '
          . '.filmmaking-config.php above the web root and fill in the database details.'
        : 'The server is not configured correctly yet.');
}

// Shared sign-in: sends people to /filmmaking/auth/login.php and back here.
// This is all a new tool needs to join the same login.
fm_require_login('slate');

if (!is_readable(SLATE_BUNDLE)) {
    slate_setup_error('app.html is missing from this folder — the deploy may not have finished.');
}

// Conditional request support matters here: the bundle is around 5 MB, and
// without it every page load would push the whole thing through PHP again.
$stamp = (int) filemtime(SLATE_BUNDLE);
$size = (int) filesize(SLATE_BUNDLE);
$etag = '"' . $stamp . '-' . $size . '"';

header('ETag: ' . $etag);
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $stamp) . ' GMT');
header('Cache-Control: private, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

$since = strtotime((string) ($_SERVER['HTTP_IF_MODIFIED_SINCE'] ?? '')) ?: 0;
if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag || ($since && $since >= $stamp)) {
    http_response_code(304);
    exit;
}

header('Content-Type: text/html; charset=utf-8');
header('Content-Length: ' . $size);

// Stream it: no buffering, no compression pass over 5 MB.
while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile(SLATE_BUNDLE);
