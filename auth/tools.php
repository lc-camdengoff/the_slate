<?php
/**
 * Filmmaking tools — the registry.
 *
 * Everything hosted under /filmmaking/, in the order it should appear in the
 * nav. Adding a tool is one line here: the nav bar on every account page picks
 * it up, and so does The Slate's own header, which reads this list over
 * list.php.
 *
 * 'path' is relative to the tools folder and should end in a slash.
 */

declare(strict_types=1);

function fm_tools(): array
{
    return [
        ['label' => 'The Slate', 'path' => 'slate/', 'blurb' => 'Storyboards'],
        // ['label' => 'The Cage', 'path' => 'cage/', 'blurb' => 'Gear checkout'],
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
        $links[] = [
            'label' => (string) $tool['label'],
            'blurb' => (string) ($tool['blurb'] ?? ''),
            'url' => $base . ltrim((string) $tool['path'], '/'),
            'current' => $current !== '' && trim((string) $tool['path'], '/') === trim($current, '/'),
        ];
    }
    return $links;
}
