<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

/**
 * A small set of line icons for the navigation (24×24, drawn with currentColor).
 */
final class Icons
{
    private const PATHS = [
        'list' => 'M8 6h12M8 12h12M8 18h12M4 6h.01M4 12h.01M4 18h.01',
        'file' => 'M14 3H6a1 1 0 0 0-1 1v16a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8zM14 3v5h5M9 13h6M9 17h6',
        'building' => 'M4 21V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v16M15 9h4a1 1 0 0 1 1 1v11M3 21h18M8 8h3M8 12h3M8 16h3',
        'home' => 'M3 11l9-7 9 7M5 10v10h5v-6h4v6h5V10',
        'users' => 'M16 19v-1a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4v1M9.5 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6M21 19v-1a4 4 0 0 0-3-3.9M16 4.1a3 3 0 0 1 0 5.8',
        'tag' => 'M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9zM7.5 7.5h.01',
        'image' => 'M4 5h16v14H4zM4 16l5-5 4 4 2-2 5 5M15.5 9.5h.01',
        'calendar' => 'M4 6h16v15H4zM4 10h16M8 3v4M16 3v4',
        'mail' => 'M3 6h18v12H3zM3 7l9 6 9-6',
        'box' => 'M21 8l-9-5-9 5 9 5zM3 8v8l9 5 9-5V8M12 13v8',
        'star' => 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z',
        'settings' => 'M12 15a3 3 0 1 0 0-6 3 3 0 0 0 0 6M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1',
        'dashboard' => 'M4 4h7v9H4zM13 4h7v5h-7zM13 11h7v9h-7zM4 15h7v5H4z',
        'logout' => 'M15 4h4a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1h-4M10 16l4-4-4-4M14 12H4',
        'plus' => 'M12 5v14M5 12h14',
        'search' => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14M21 21l-4.3-4.3',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'trash' => 'M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3',
        'pencil' => 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
        'more' => 'M4.5 12a1 1 0 1 0 2 0a1 1 0 1 0-2 0M11 12a1 1 0 1 0 2 0a1 1 0 1 0-2 0M17.5 12a1 1 0 1 0 2 0a1 1 0 1 0-2 0',
        'filter' => 'M3 5h18l-7 8.5V20l-4-2v-4.5z',
        'x' => 'M18 6 6 18M6 6l12 12',
        'chevron-left' => 'M15 18l-6-6 6-6',
        'chevron-right' => 'M9 18l6-6-6-6',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PATHS);
    }

    public static function svg(?string $name, string $class = 'fa-icon'): string
    {
        $path = self::PATHS[$name ?? 'list'] ?? self::PATHS['list'];

        return '<svg class="' . Str::e($class) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"'
            . ' stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<path d="' . $path . '"/></svg>';
    }
}
