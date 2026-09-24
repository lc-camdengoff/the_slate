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
 */

declare(strict_types=1);

function fm_tools(): array
{
    return [
        ['label' => 'The Slate', 'path' => 'slate/', 'blurb' => 'Storyboards'],
        ['label' => 'The Cage', 'url' => 'https://cage.creativemedia.church/', 'blurb' => 'Gear checkout'],
    ];
}

/**
 * The registry as absolute URLs, with the current tool marked.
 *
 * @param string $current the 'path' of the tool being viewed, if any
 */
function fm_tool_links(string $current = ''): array
{
    $base = fm_base_path();
    $links = [];
    foreach (fm_tools() as $tool) {
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
