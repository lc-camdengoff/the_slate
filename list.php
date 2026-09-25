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
require __DIR__ . '/../auth/auth.php';

fm_error_handler('json');

if (!in_array($_SERVER['REQUEST_METHOD'] ?? '', ['GET', 'HEAD'], true)) {
    header('Allow: GET, HEAD');
    slate_fail(405, 'method_not_allowed');
}

$account = fm_require_api_user('slate');
$user = slate_user();
$response = [
    'ok' => true,
    'user' => $user['name'],
    'username' => $user['username'],
    'isAdmin' => $user['is_admin'],
    // The client echoes this back on writes; see fm_require_api_write().
    'csrf' => fm_csrf_token(),
    // The shared account pages live outside this tool, so the server hands
    // over the URLs rather than the client guessing at the layout.
    'accountUrl' => fm_auth_url_with_next('account.php', fm_base_path() . 'slate/'),
    'adminUrl' => fm_auth_url_with_next('admin.php', fm_base_path() . 'slate/'),
    'logoutUrl' => fm_auth_url('logout.php'),
    // The same registry the account pages use, so a new tool shows up in the
    // app's header without touching the app.
    'homeUrl' => fm_base_path(),
    'tools' => fm_tool_links('slate/', $account),
    'maxBytes' => slate_max_bytes(),
    'versionsKept' => SLATE_VERSIONS_KEPT,
    'mine' => [],
    'boards' => [],
];

$saved = slate_saved_dir();
if ($saved === null) {
    slate_fail(500, 'storage_unavailable');
}

$boards = [];
$mine = [];
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
    $me = (string) $user['username'];
    if (!slate_can_read($meta, $me)) {
        continue;
    }
    $size = filesize($path);
    $entry = [
        'id' => $name,
        'title' => (string) ($meta['title'] ?? $name),
        'project' => (string) ($meta['project'] ?? ''),
        'savedBy' => (string) ($meta['savedBy'] ?? 'Unknown'),
        'savedByUser' => (string) ($meta['savedByUser'] ?? ''),
        'updatedAt' => (int) ($meta['updatedAt'] ?? ((int) filemtime($path) * 1000)),
        'shotCount' => (int) ($meta['shotCount'] ?? 0),
        'bytes' => (int) ($meta['bytes'] ?? ($size === false ? 0 : $size)),
        'owner' => slate_owner($meta),
        'visibility' => slate_visibility($meta),
        'isMine' => slate_owns($meta, $me),
        // Reads go through load.php now: ../saved/ is closed to the web.
        'file' => 'load.php?id=' . $name,
    ];

    // A board you own and have shared belongs in both lists, the same way a
    // local copy and its library entry both showed before boards had owners.
    if ($entry['isMine']) {
        $mine[] = $entry;
    }
    if ($entry['visibility'] === SLATE_TEAM) {
        $boards[] = $entry;
    }
}

$newest = static fn(array $a, array $b): int => $b['updatedAt'] <=> $a['updatedAt'];
usort($boards, $newest);
usort($mine, $newest);
$response['boards'] = $boards;
$response['mine'] = $mine;

slate_json(200, $response);
