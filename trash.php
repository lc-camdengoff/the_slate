<?php
/**
 * The Slate — the trash.
 *
 *   GET  trash.php                                  deleted boards you may see
 *   POST trash.php?action=restore&id=...&stamp=...  put one back
 *
 * You see what the library would show you — team boards, and your own
 * private ones — and admins see everything, so a board whose owner has left
 * can still be rescued. Entries are removed for good SLATE_TRASH_DAYS after
 * deletion by slate_housekeeping().
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/../auth/auth.php';

fm_error_handler('json');

$method = $_SERVER['REQUEST_METHOD'] ?? '';

if ($method === 'GET' || $method === 'HEAD') {
    $account = fm_require_api_user('slate');
    slate_json(200, [
        'ok' => true,
        'days' => SLATE_TRASH_DAYS,
        'items' => slate_trash_list((string) $account['username'], (bool) $account['is_admin']),
    ]);
}

slate_require_write_request();
$account = fm_require_api_write('slate');

if ((string) ($_GET['action'] ?? '') !== 'restore') {
    slate_fail(400, 'bad_action');
}
$error = slate_restore_board(
    trim((string) ($_GET['id'] ?? '')),
    trim((string) ($_GET['stamp'] ?? '')),
    (string) $account['username'],
    (bool) $account['is_admin']
);
if ($error !== '') {
    slate_fail(['not_found' => 404, 'exists' => 409, 'storage_unavailable' => 500][$error] ?? 500, $error);
}
error_log('slate: ' . $account['username'] . ' restored ' . ($_GET['id'] ?? '') . ' from the trash');
slate_json(200, ['ok' => true, 'id' => (string) $_GET['id']]);
