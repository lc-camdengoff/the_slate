<?php
/**
 * The Slate — save a storyboard to the shared team library.
 *
 * POST save.php?title=...&project=...&shots=12[&id=...&rev=...&force=1][&as=...]
 * with the storyboard JSON as the raw request body.
 *
 * The client never supplies a filename or an extension: the server derives the
 * id from the title, appends a random suffix and always writes ".json".
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/../auth/auth.php';

fm_error_handler('json');

slate_require_write_request();
$account = fm_require_api_write('slate');

$saved = slate_saved_dir();
if ($saved === null) {
    slate_fail(500, 'storage_unavailable');
}

$maxBytes = slate_max_bytes();
$declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($declared > $maxBytes) {
    slate_fail(413, 'too_large', ['maxBytes' => $maxBytes, 'bytes' => $declared]);
}

// The signed-in account is the author: the client no longer gets a say in
// who a save is attributed to.
$savedBy = slate_clean_text((string) $account['display_name'], 64);
$savedByUser = (string) $account['username'];
if ($savedBy === '') {
    $savedBy = $savedByUser;
}

$title = slate_clean_text((string) ($_GET['title'] ?? ''), 120);
if ($title === '') {
    $title = 'Untitled Storyboard';
}
$project = slate_clean_text((string) ($_GET['project'] ?? ''), 120);
$shotCount = max(0, min(100000, (int) ($_GET['shots'] ?? 0)));
$force = ($_GET['force'] ?? '') === '1';
// Absent means "leave as it is" on an update, and private on a new board.
$wanted = strtolower(trim((string) ($_GET['visibility'] ?? '')));
if ($wanted !== SLATE_PRIVATE && $wanted !== SLATE_TEAM) {
    $wanted = '';
}

$id = trim((string) ($_GET['id'] ?? ''));
if ($id !== '' && !slate_is_id($id)) {
    slate_fail(400, 'bad_id');
}

$tmpDir = slate_internal_dir($saved, '.tmp');
if ($tmpDir === null) {
    slate_fail(500, 'storage_unavailable');
}

// Stream the body to disk in chunks rather than buffering it whole, and stop
// the moment it exceeds the cap.
$tmpPath = $tmpDir . '/' . bin2hex(random_bytes(8)) . '.part';
$in = fopen('php://input', 'rb');
$out = fopen($tmpPath, 'wb');
if ($in === false || $out === false) {
    @unlink($tmpPath);
    slate_fail(500, 'storage_unavailable');
}

$bytes = 0;
while (!feof($in)) {
    $chunk = fread($in, 1048576);
    if ($chunk === false) {
        break;
    }
    $bytes += strlen($chunk);
    if ($bytes > $maxBytes) {
        fclose($in);
        fclose($out);
        @unlink($tmpPath);
        slate_fail(413, 'too_large', ['maxBytes' => $maxBytes]);
    }
    if ($chunk !== '' && fwrite($out, $chunk) === false) {
        fclose($in);
        fclose($out);
        @unlink($tmpPath);
        slate_fail(500, 'write_failed');
    }
}
fclose($in);
fclose($out);

if ($bytes === 0) {
    @unlink($tmpPath);
    // An empty body usually means the request outran post_max_size, in which
    // case PHP throws the body away before we ever see it.
    slate_fail(400, 'empty_body', ['maxBytes' => $maxBytes]);
}

// Only ever store something that is valid JSON. json_validate() checks the
// syntax without building the object graph, which matters for boards in the
// tens of megabytes; on older PHP we decode and check the shape as well.
$raw = file_get_contents($tmpPath);
if ($raw === false) {
    @unlink($tmpPath);
    slate_fail(500, 'write_failed');
}
if (function_exists('json_validate')) {
    $valid = json_validate($raw);
} else {
    $decoded = json_decode($raw, true);
    $valid = is_array($decoded) && isset($decoded['frames']) && is_array($decoded['frames']);
    unset($decoded);
}
unset($raw);
if (!$valid) {
    @unlink($tmpPath);
    slate_fail(400, 'not_a_storyboard');
}

$isNew = ($id === '');
if ($isNew) {
    try {
        $id = slate_new_id($saved, $title);
    } catch (RuntimeException $e) {
        @unlink($tmpPath);
        slate_fail(500, 'storage_unavailable');
    }
}

$lock = slate_lock($saved, $id);
$path = slate_board_path($saved, $id);
$existing = slate_read_meta($saved, $id);

// Someone else's private board is not yours to overwrite.
if (!$isNew && $existing !== null && !slate_can_write($existing, $savedByUser, (int) $account['id'])) {
    @unlink($tmpPath);
    flock($lock, LOCK_UN);
    fclose($lock);
    slate_fail(403, 'not_yours');
}
// Someone the board is shared with can edit it, but whether the whole team
// sees it is its owner's call.
if (!$isNew && $existing !== null && !slate_can_manage($existing, $account)) {
    $wanted = '';
}

// Last-writer-wins is fine as long as the writer knows they are last: if the
// board moved on since this client loaded it, stop and let them choose.
if (!$isNew && !$force && $existing !== null) {
    $rev = (int) ($_GET['rev'] ?? 0);
    $serverRev = (int) ($existing['updatedAt'] ?? 0);
    if ($rev !== $serverRev) {
        @unlink($tmpPath);
        flock($lock, LOCK_UN);
        fclose($lock);
        slate_fail(409, 'conflict', [
            'id' => $id,
            'savedBy' => (string) ($existing['savedBy'] ?? 'someone'),
            'updatedAt' => $serverRev,
            'title' => (string) ($existing['title'] ?? ''),
        ]);
    }
}

// Keep a few previous copies. Hard-linking costs nothing and leaves the live
// file in place, so a reader never sees a gap while the new one lands.
if (file_exists($path)) {
    $versions = slate_internal_dir($saved, '.versions');
    if ($versions !== null) {
        $stamp = (int) ($existing['updatedAt'] ?? (filemtime($path) * 1000));
        $versionPath = $versions . '/' . $id . '.' . $stamp . '.json';
        if (!file_exists($versionPath) && !@link($path, $versionPath)) {
            @copy($path, $versionPath);
        }
        $kept = glob($versions . '/' . $id . '.*.json') ?: [];
        sort($kept, SORT_NATURAL);
        foreach (array_slice($kept, 0, max(0, count($kept) - SLATE_VERSIONS_KEPT)) as $old) {
            @unlink($old);
        }
    }
}

if (!@rename($tmpPath, $path)) {
    @unlink($tmpPath);
    flock($lock, LOCK_UN);
    fclose($lock);
    slate_fail(500, 'write_failed');
}
@chmod($path, 0644);

$updatedAt = slate_now_ms();
$meta = [
    'id' => $id,
    'title' => $title,
    'project' => $project,
    'savedBy' => $savedBy,
    'savedByUser' => $savedByUser,
    // The creator keeps ownership even when a team-mate edits a shared board.
    'owner' => $isNew ? $savedByUser : slate_owner($existing),
    'visibility' => $wanted !== '' ? $wanted : ($isNew ? SLATE_PRIVATE : slate_visibility($existing)),
    'updatedAt' => $updatedAt,
    'createdAt' => (int) ($existing['createdAt'] ?? $updatedAt),
    'createdByUser' => (string) ($existing['createdByUser'] ?? $savedByUser),
    'shotCount' => $shotCount,
    'bytes' => $bytes,
];
// Rebuilt fresh above, so carry over who it is shared with.
$meta = slate_with_shares($meta, slate_shares($existing));
@file_put_contents(slate_meta_path($saved, $id), json_encode($meta, JSON_UNESCAPED_SLASHES));
@chmod(slate_meta_path($saved, $id), 0644);

flock($lock, LOCK_UN);
fclose($lock);

slate_json(200, ['ok' => true] + $meta + ['file' => 'load.php?id=' . $id]);
