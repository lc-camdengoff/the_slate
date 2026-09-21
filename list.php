<?php
/**
 * The Slate — list the storyboards in the shared team library.
 *
 * GET list.php
 *
 * Reads only the small "<id>.meta.json" sidecars, never the boards themselves,
 * so listing stays fast no matter how many megabytes of reference images the
 * team has saved. The client loads a board by fetching ../saved/<id>.json
 * directly — it is behind the same basic auth, and keeping PHP out of the read
 * path avoids pulling a 20 MB file through memory.
 */

declare(strict_types=1);

require __DIR__ . '/lib.php';
require __DIR__ . '/auth.php';

slate_install_error_handler('json');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    slate_fail(405, 'method_not_allowed');
}

$account = slate_require_api_user();
$user = slate_user();
$response = [
    'ok' => true,
    'user' => $user['name'],
    'username' => $user['username'],
    'isAdmin' => $user['is_admin'],
    // The client echoes this back on writes; see slate_require_api_write().
    'csrf' => slate_csrf_token(),
    'maxBytes' => slate_max_bytes(),
    'versionsKept' => SLATE_VERSIONS_KEPT,
    'boards' => [],
];

$saved = slate_saved_dir();
if ($saved === null) {
    slate_fail(500, 'storage_unavailable');
}

$boards = [];
foreach (glob($saved . '/*.json') ?: [] as $path) {
    $name = basename($path, '.json');
    if (substr($name, -5) === '.meta') {
        continue;
    }
    if (!slate_is_id($name)) {
        // Something hand-uploaded; not ours to manage.
        continue;
    }

    $meta = slate_read_meta($saved, $name);
    $size = filesize($path);
    $boards[] = [
        'id' => $name,
        'title' => (string) ($meta['title'] ?? $name),
        'project' => (string) ($meta['project'] ?? ''),
        'savedBy' => (string) ($meta['savedBy'] ?? 'Unknown'),
        'savedByUser' => (string) ($meta['savedByUser'] ?? ''),
        'updatedAt' => (int) ($meta['updatedAt'] ?? ((int) filemtime($path) * 1000)),
        'shotCount' => (int) ($meta['shotCount'] ?? 0),
        'bytes' => (int) ($meta['bytes'] ?? ($size === false ? 0 : $size)),
        // Reads go through load.php now: ../saved/ is closed to the web.
        'file' => 'load.php?id=' . $name,
    ];
}

usort($boards, static fn(array $a, array $b): int => $b['updatedAt'] <=> $a['updatedAt']);
$response['boards'] = $boards;

slate_json(200, $response);
