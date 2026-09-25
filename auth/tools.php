<?php
/**
 * Filmmaking tools — the registry.
 *
 * Everything hosted under /filmmaking/, in the order it should appear in the
 * nav. Adding a tool is one line here: the nav bar on every account page picks
 * it up, and so does The Slate's own header, which reads this list over
 * list.php.
 *
 * 'path' is relative to the tools folder and should end in a slash. A tool on
 * its own host uses 'url' with a full https:// address instead — the shared
 * sign-in will then accept a return to that origin, and nothing else.
 *
 * 'key' is how permissions refer to the tool, so never change it once people
 * have roles in it. 'hooks' is an optional PHP file, relative to the tools
 * folder, defining <key>_account_renamed($old, $new) and/or
 * <key>_account_deleted($username), for a tool that stores usernames of its
 * own and has to follow when an admin renames or deletes someone. 'admin'
 * lists admin-only pages of the tool (label, path, blurb) for Team admin. 'roles' is the tool's own list, least to most powerful;
 * the admin page offers these plus "No access". 'default_role' is what
 * someone gets in the tool until an admin says otherwise.
 */

declare(strict_types=1);

/** The role meaning "cannot use this tool at all". */
const FM_NO_ACCESS = 'none';

function fm_tools(): array
{
    return [
        [
            'key' => 'slate', 'label' => 'The Slate', 'path' => 'slate/', 'blurb' => 'Storyboards',
            'roles' => ['member' => 'Member'],
            'default_role' => 'member',
            // Storyboards record their owner by username; see people.php.
            'hooks' => 'slate/lib.php',
            // Admin-only pages the tool has, linked from Team admin.
            'admin' => [['label' => 'Storage', 'path' => 'slate/storage.php',
                         'blurb' => 'Space used, shrink older pictures, clean up']],
        ],
        [
            'key' => 'cage', 'label' => 'The Cage', 'url' => 'https://cage.creativemedia.church/',
            'blurb' => 'Gear checkout',
            'roles' => ['member' => 'Member', 'manager' => 'Gear manager', 'admin' => 'Admin'],
            'default_role' => 'member',
        ],
    ];
}

/** One tool from the registry by key, or null. */
function fm_tool(string $key): ?array
{
    foreach (fm_tools() as $tool) {
        if ($tool['key'] === $key) {
            return $tool;
        }
    }
    return null;
}

/**
 * The registry as absolute URLs, with the current tool marked.
 *
 * @param string $current the 'path' of the tool being viewed, if any
 * @param ?array $user when given, tools this person has no access to are left
 *   out — a link that only leads to "not allowed" is worse than no link
 */
function fm_tool_links(string $current = '', ?array $user = null): array
{
    $base = fm_base_path();
    $links = [];
    foreach (fm_tools() as $tool) {
        if ($user !== null && !fm_can_use($user, $tool['key'])) {
            continue;
        }
        $absolute = trim((string) ($tool['url'] ?? ''));
        $path = trim((string) ($tool['path'] ?? ''), '/');
        $links[] = [
            'label' => (string) $tool['label'],
            'blurb' => (string) ($tool['blurb'] ?? ''),
            'url' => $absolute !== '' ? $absolute : $base . $path . '/',
            'current' => $current !== '' && (
                ($path !== '' && $path === trim($current, '/'))
                || ($absolute !== '' && rtrim($absolute, '/') === rtrim($current, '/'))
            ),
        ];
    }
    return $links;
}

/**
 * Origins a tool may be returned to after signing in.
 *
 * Only the hosts registered above, never an arbitrary one from the request.
 * This is what keeps ?next= from becoming an open redirect once tools are
 * allowed to live off this host.
 */
function fm_tool_origins(): array
{
    $origins = [];
    foreach (fm_tools() as $tool) {
        $url = trim((string) ($tool['url'] ?? ''));
        if ($url === '') {
            continue;
        }
        $parts = parse_url($url);
        if (empty($parts['scheme']) || empty($parts['host'])) {
            continue;
        }
        $origins[] = strtolower($parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : ''));
    }
    return array_unique($origins);
}

// ---------------------------------------------------------------------------
// Who may use what
// ---------------------------------------------------------------------------

/**
 * Every tool's role for one person, keyed by tool, defaults filled in.
 * Cached for the request; pass $fresh after changing them.
 *
 * @return array<string, string> e.g. ['slate' => 'member', 'cage' => 'none']
 */
function fm_tool_roles(int $userId, bool $fresh = false): array
{
    static $cache = [];
    if (!$fresh && isset($cache[$userId])) {
        return $cache[$userId];
    }
    $stored = [];
    $stmt = fm_db()->prepare('SELECT tool, role FROM user_tool_roles WHERE user_id = ?');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll() as $row) {
        $stored[(string) $row['tool']] = (string) $row['role'];
    }

    $roles = [];
    foreach (fm_tools() as $tool) {
        $role = $stored[$tool['key']] ?? (string) $tool['default_role'];
        // A role the tool no longer lists (renamed in tools.php since) is
        // treated as no access rather than guessed at.
        if ($role !== FM_NO_ACCESS && !isset($tool['roles'][$role])) {
            $role = FM_NO_ACCESS;
        }
        $roles[$tool['key']] = $role;
    }
    return $cache[$userId] = $roles;
}

/** This person's role in one tool, or FM_NO_ACCESS. */
function fm_tool_role(array $user, string $key): string
{
    return fm_tool_roles((int) $user['id'])[$key] ?? FM_NO_ACCESS;
}

function fm_can_use(array $user, string $key): bool
{
    return fm_tool_role($user, $key) !== FM_NO_ACCESS;
}

/** Is this a role the tool actually has (or "no access")? */
function fm_valid_tool_role(string $key, string $role): bool
{
    $tool = fm_tool($key);
    return $tool !== null && ($role === FM_NO_ACCESS || isset($tool['roles'][$role]));
}

/** Set one person's role in one tool. Unknown tools or roles are ignored. */
function fm_set_tool_role(int $userId, string $key, string $role): void
{
    if (!fm_valid_tool_role($key, $role)) {
        return;
    }
    fm_db()->prepare(
        'INSERT INTO user_tool_roles (user_id, tool, role) VALUES (?, ?, ?)
         ON CONFLICT (user_id, tool) DO UPDATE SET role = EXCLUDED.role'
    )->execute([$userId, $key, $role]);
}

/** "Member", "Gear manager", "No access" — for showing a role to people. */
function fm_role_label(string $key, string $role): string
{
    if ($role === FM_NO_ACCESS) {
        return 'No access';
    }
    return (string) (fm_tool($key)['roles'][$role] ?? $role);
}
