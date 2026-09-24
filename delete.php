<?php
/**
 * The Slate — remove a storyboard from the shared team library.
 *
 * POST delete.php?id=...
 *
 * Soft delete: the board, its sidecar and its kept versions move into
 * saved/.trash/ rather than being unlinked, so a mis-click is recoverable over
 * FTP or the cPanel file manager. Nothing else backs these files up.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/../auth/auth.php';

fm_error_handler('json');

slate_require_write_request();
fm_require_api_write();

$saved = slate_saved_dir();
if ($saved === null) {
    slate_fail(500, 'storage_unavailable');
}

$id = trim((string) ($_GET['id'] ?? ''));
if (!slate_is_id($id)) {
    slate_fail(400, 'bad_id');
}

$path = slate_board_path($saved, $id);
if (!file_exists($path)) {
    slate_fail(404, 'not_found');
}

$trash = slate_internal_dir($saved, '.trash');
if ($trash === null) {
    slate_fail(500, 'storage_unavailable');
}

$lock = slate_lock($saved, $id);
$stamp = slate_now_ms();
$prefix = $trash . '/' . $id . '.' . $stamp;

if (!@rename($path, $prefix . '.json')) {
    flock($lock, LOCK_UN);
    fclose($lock);
    slate_fail(500, 'delete_failed');
}
@rename(slate_meta_path($saved, $id), $prefix . '.meta.json');

$versions = $saved . '/.versions';
if (is_dir($versions)) {
    foreach (glob($versions . '/' . $id . '.*.json') ?: [] as $old) {
        @rename($old, $trash . '/' . basename($old));
    }
}

flock($lock, LOCK_UN);
fclose($lock);

slate_json(200, ['ok' => true, 'id' => $id]);
