<?php
/**
 * The Slate — read a storyboard from the team library.
 *
 * GET load.php?id=<board-id>
 *
 * Boards used to be fetched straight out of ../saved/ as static files, which
 * was safe only because basic auth sat in front of the whole folder. With
 * accounts, that folder is closed to the web entirely and reads come through
 * here, behind a session check.
 *
 * readfile() streams, so a 20 MB board still costs almost no memory.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/../auth/auth.php';

fm_error_handler('json');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    slate_fail(405, 'method_not_allowed');
}

$account = fm_require_api_user();

$saved = slate_saved_dir();
if ($saved === null) {
    slate_fail(500, 'storage_unavailable');
}

$id = trim((string) ($_GET['id'] ?? ''));
if (!slate_is_id($id)) {
    slate_fail(400, 'bad_id');
}

$path = slate_board_path($saved, $id);
// realpath containment: the id pattern already rules out traversal, but the
// file being served has to be provably inside the shared folder.
$real = realpath($path);
if ($real === false || strpos($real, $saved . '/') !== 0 || !is_file($real)) {
    slate_fail(404, 'not_found');
}

// Someone else's private board reads as missing rather than forbidden, so the
// listing cannot be used to discover what other people are working on.
if (!slate_can_read(slate_read_meta($saved, $id), (string) $account['username'])) {
    slate_fail(404, 'not_found');
}

$stamp = (int) filemtime($real);
$size = (int) filesize($real);
$etag = '"' . $id . '-' . $stamp . '-' . $size . '"';

header('ETag: ' . $etag);
header('Cache-Control: private, no-cache, must-revalidate');
header('X-Content-Type-Options: nosniff');

if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
    http_response_code(304);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Content-Length: ' . $size);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'HEAD') {
    exit;
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
readfile($real);
