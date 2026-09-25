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

// Prune old copies and month-old trash. Does the work at most once an hour,
// so on almost every call this is one stat() of a marker file.
slate_housekeeping();

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
    $access = slate_access($meta, $me, (int) $user['id']);
    if ($access === '') {
        continue;
    }
    $shares = slate_shares($meta);
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
        // 'owner', 'edit' or 'view': what this person may do with it.
        'access' => $access,
        // Shared with this person by name, rather than theirs or the team's.
        'sharedWithMe' => isset($shares[(int) $user['id']]) && !slate_owns($meta, $me),
        'shareCount' => count($shares),
        'canManage' => slate_can_manage($meta, $user),
        // Waiting on an answer from whoever manages it; nobody else is told.
        'requestCount' => slate_can_manage($meta, $user) ? count(slate_requests($meta)) : 0,
        // Reads go through load.php now: ../saved/ is closed to the web.
        'file' => 'load.php?id=' . $name,
    ];

    // A board you own and have shared belongs in both lists, the same way a
    // local copy and its library entry both showed before boards had owners.
    // One shared with you by name sits with yours too, like a document would.
    if ($entry['isMine'] || $entry['sharedWithMe']) {
        $mine[] = $entry;
    }
    if ($entry['visibility'] === SLATE_TEAM) {
        $boards[] = $entry;
    }
}

// Who shared each one, by name: one lookup for them all.
$owners = [];
foreach ($mine as $entry) {
    if ($entry['sharedWithMe'] && $entry['owner'] !== '') {
        $owners[strtolower($entry['owner'])] = true;
    }
}
if ($owners) {
    $names = slate_display_names(array_keys($owners));
    foreach ($mine as $i => $entry) {
        if ($entry['sharedWithMe']) {
            $mine[$i]['ownerName'] = $names[strtolower($entry['owner'])] ?? $entry['owner'];
        }
    }
}

$newest = static fn(array $a, array $b): int => $b['updatedAt'] <=> $a['updatedAt'];
usort($boards, $newest);
usort($mine, $newest);
$response['boards'] = $boards;
$response['mine'] = $mine;

slate_json(200, $response);
