<?php
/**
 * The Slate — who a storyboard is shared with.
 *
 *   GET  share.php?id=...                 its sharing, and who it could go to
 *   POST share.php?id=...                 {"visibility": "private"|"team",
 *                                          "shares": {"<account id>": "view"|"edit"}}
 *   POST share.php?id=...&action=leave    take it off your own list
 *
 * Only the owner and admins may change sharing (slate_can_manage). Someone a
 * board was shared with may leave it. Changing who can see a board is not an
 * edit to it, so updatedAt stays put and nobody's open copy is told it
 * changed elsewhere.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/../auth/auth.php';

fm_error_handler('json');

$method = $_SERVER['REQUEST_METHOD'] ?? '';
if ($method === 'GET' || $method === 'HEAD') {
    $account = fm_require_api_user('slate');
} else {
    slate_require_write_request();
    $account = fm_require_api_write('slate');
}
$userId = (int) $account['id'];
$username = (string) $account['username'];

$saved = slate_saved_dir();
if ($saved === null) {
    slate_fail(500, 'storage_unavailable');
}
$id = trim((string) ($_GET['id'] ?? ''));
if (!slate_is_id($id)) {
    slate_fail(400, 'bad_id');
}
if (!is_file(slate_board_path($saved, $id))) {
    slate_fail(404, 'not_found');
}
$meta = slate_read_meta($saved, $id);
if (!slate_can_read($meta, $username, $userId)) {
    slate_fail(404, 'not_found');
}

/** The board's sharing as the Share panel shows it. */
function slate_share_view(?array $meta, array $people): array
{
    $owner = slate_owner($meta);
    $shares = [];
    foreach (slate_shares($meta) as $personId => $level) {
        // Someone who has since lost access to the Slate, or whose account
        // is gone, is left out rather than listed as a mystery.
        if (isset($people[$personId])) {
            $shares[] = $people[$personId] + ['level' => $level];
        }
    }
    $ownerName = $owner;
    foreach ($people as $person) {
        if (strcasecmp($person['username'], $owner) === 0) {
            $ownerName = $person['name'];
        }
    }
    return [
        'visibility' => slate_visibility($meta),
        'owner' => $owner,
        'ownerName' => $ownerName,
        'shares' => $shares,
    ];
}

if ($method === 'GET' || $method === 'HEAD') {
    if (!slate_can_manage($meta, $account)) {
        slate_fail(403, 'not_yours');
    }
    $people = slate_team_people();
    $view = slate_share_view($meta, $people);
    // Everyone it could still be shared with; not its owner.
    $view['people'] = array_values(array_filter(
        $people,
        static fn(array $p) => strcasecmp($p['username'], $view['owner']) !== 0
    ));
    slate_json(200, ['ok' => true, 'id' => $id] + $view);
}

$action = (string) ($_GET['action'] ?? '');
if ($action === 'leave') {
    $lock = slate_lock($saved, $id);
    $meta = slate_read_meta($saved, $id) ?? [];
    $shares = slate_shares($meta);
    if (isset($shares[$userId])) {
        unset($shares[$userId]);
        if (!slate_write_meta($saved, $id, slate_with_shares($meta, $shares))) {
            flock($lock, LOCK_UN);
            fclose($lock);
            slate_fail(500, 'write_failed');
        }
    }
    flock($lock, LOCK_UN);
    fclose($lock);
    slate_json(200, ['ok' => true, 'id' => $id]);
}
if ($action !== '') {
    slate_fail(400, 'bad_action');
}

if (!slate_can_manage($meta, $account)) {
    slate_fail(403, 'not_yours');
}
$body = json_decode((string) file_get_contents('php://input', false, null, 0, 65536), true);
if (!is_array($body)) {
    slate_fail(400, 'bad_request');
}
$visibility = (string) ($body['visibility'] ?? '');
if ($visibility !== SLATE_PRIVATE && $visibility !== SLATE_TEAM) {
    slate_fail(400, 'bad_visibility');
}
$people = slate_team_people();
$shares = [];
foreach ((array) ($body['shares'] ?? []) as $personId => $level) {
    $personId = (int) $personId;
    if (!isset($people[$personId])) {
        slate_fail(400, 'bad_person', ['person' => $personId]);
    }
    if ($level !== SLATE_VIEW && $level !== SLATE_EDIT) {
        slate_fail(400, 'bad_level');
    }
    // The owner has it already; a share would only make them a guest.
    if (strcasecmp($people[$personId]['username'], slate_owner($meta)) !== 0) {
        $shares[$personId] = $level;
    }
}
if (count($shares) > 200) {
    slate_fail(400, 'too_many');
}

$lock = slate_lock($saved, $id);
$meta = slate_read_meta($saved, $id);
if ($meta === null || !is_file(slate_board_path($saved, $id))) {
    flock($lock, LOCK_UN);
    fclose($lock);
    slate_fail(404, 'not_found');
}
$meta['visibility'] = $visibility;
if (trim((string) ($meta['owner'] ?? '')) === '') {
    // A board from before owners: record the one it has been credited to.
    $meta['owner'] = slate_owner($meta) !== '' ? slate_owner($meta) : $username;
}
$meta = slate_with_shares($meta, $shares);
$ok = slate_write_meta($saved, $id, $meta);
flock($lock, LOCK_UN);
fclose($lock);
if (!$ok) {
    slate_fail(500, 'write_failed');
}
error_log('slate: ' . $username . ' set sharing on ' . $id . ': ' . $visibility . ', ' . count($shares) . ' people');
slate_json(200, ['ok' => true, 'id' => $id] + slate_share_view(json_decode((string) json_encode($meta), true), $people));
