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
        // Blocks (0.29)
        'slides' => 'M6 5h12v11H6zM2 7v7M22 7v7M9 20h.01M12 20h.01M15 20h.01',
        'hero' => 'M3 4h18v12H3zM7 9h7M7 12h4M3 20h18',
        'heading' => 'M6 4v16M18 4v16M6 12h12',
        'text' => 'M4 6h16M4 10h16M4 14h16M4 18h10',
        'section' => 'M3 4h18M3 20h18M5 8h14v8H5z',
        'arrow-right' => 'M5 12h14M13 6l6 6-6 6',
        'cards' => 'M3 5h8v14H3zM13 5h8v14h-8zM5 15h4M15 15h4',
        'square' => 'M5 5h14v14H5z',
        // Rich text toolbar
        'bold' => 'M7 5h6a3.5 3.5 0 0 1 0 7H7zM7 12h7a3.5 3.5 0 0 1 0 7H7z',
        'italic' => 'M10 5h8M6 19h8M14 5l-4 14',
        'underline' => 'M7 4v7a5 5 0 0 0 10 0V4M5 20h14',
        'strike' => 'M5 12h14M16 7.5C15.4 6 13.9 5 12 5c-2.2 0-4 1.2-4 3 0 1.3.9 2.3 2.4 3M8 16.5c.6 1.5 2.1 2.5 4 2.5 2.2 0 4-1.2 4-3 0-.6-.2-1.1-.5-1.6',
        'list-ordered' => 'M10 6h10M10 12h10M10 18h10M4 5l1.5-1v5M4 14.5a1.5 1.5 0 0 1 3 .5c0 .8-3 2.5-3 4h3',
        'quote' => 'M4 7h6v6H7l-3 4zM14 7h6v6h-3l-3 4z',
        'code' => 'M8 8l-4 4 4 4M16 8l4 4-4 4M13.5 5l-3 14',
        'minus' => 'M5 12h14',
        'play' => 'M3 6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2zM10 9l5 3-5 3z',
        'undo' => 'M9 14L4 9l5-5M4 9h10.5a5.5 5.5 0 0 1 0 11H11',
        'redo' => 'M15 14l5-5-5-5M20 9H9.5a5.5 5.5 0 0 0 0 11H13',
        'upload' => 'M12 16V4M7 9l5-5 5 5M4 16v4h16v-4',
        'download' => 'M12 4v12M7 11l5 5 5-5M4 16v4h16v-4',
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
        'copy' => 'M9 9h11v11H9zM5 15H4V4h11v1',
        'arrow-up' => 'M12 19V5M6 11l6-6 6 6',
        'arrow-down' => 'M12 5v14M6 13l6 6 6-6',
        'search' => 'M11 18a7 7 0 1 0 0-14 7 7 0 0 0 0 14M21 21l-4.3-4.3',
        'menu' => 'M4 6h16M4 12h16M4 18h16',
        'trash' => 'M4 7h16M10 11v6M14 11v6M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12M9 7V4h6v3',
        'globe' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18',
        'chevron-down' => 'M6 9l6 6 6-6',
        'external' => 'M15 3h6v6M10 14L21 3M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6',
        'user' => 'M20 21v-1a5 5 0 0 0-5-5H9a5 5 0 0 0-5 5v1M12 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8',
        'newspaper' => 'M4 5h13v14a2 2 0 0 0 2 2H6a2 2 0 0 1-2-2zM17 9h3v10a2 2 0 0 1-2 2M8 9h5M8 13h5M8 17h3',
        'folder' => 'M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z',
        'map-pin' => 'M12 21s-7-6.2-7-12a7 7 0 0 1 14 0c0 5.8-7 12-7 12M12 11.5a2.5 2.5 0 1 0 0-5 2.5 2.5 0 0 0 0 5',
        'cart' => 'M3 4h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.5L21 8H6M10 20.5h.01M17 20.5h.01',
        'credit-card' => 'M3 6h18v12H3zM3 10h18M7 15h4',
        'chart' => 'M4 20V10M10 20V4M16 20v-7M22 20H2',
        'message' => 'M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.4A8 8 0 1 1 21 12',
        'bell' => 'M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.9 1.9 0 0 0 3.4 0',
        'key' => 'M15.5 7.5a3.5 3.5 0 1 1-7 0 3.5 3.5 0 0 1 7 0M12 11v10M12 17h3M12 14h2',
        'shield' => 'M12 21s8-3.5 8-10V5l-8-3-8 3v6c0 6.5 8 10 8 10',
        'layers' => 'M12 3l9 5-9 5-9-5zM3 13l9 5 9-5M3 17l9 5 9-5',
        'briefcase' => 'M3 7h18v13H3zM8 7V4h8v3M3 13h18',
        'book' => 'M4 4h10a4 4 0 0 1 4 4v13H8a4 4 0 0 1-4-4zM18 21H8a4 4 0 0 1-4-4',
        'camera' => 'M3 8h4l2-3h6l2 3h4v12H3zM12 17a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7',
        'video' => 'M3 6h12v12H3zM15 10l6-3v10l-6-3',
        'heart' => 'M12 20s-8-5.2-8-11a4.5 4.5 0 0 1 8-2.8A4.5 4.5 0 0 1 20 9c0 5.8-8 11-8 11',
        'link' => 'M10 14a4 4 0 0 0 6 0l3-3a4 4 0 0 0-6-6l-1 1M14 10a4 4 0 0 0-6 0l-3 3a4 4 0 0 0 6 6l1-1',
        'phone' => 'M5 3h3l2 5-2.5 1.5a11 11 0 0 0 7 7L16 14l5 2v3a2 2 0 0 1-2 2A16 16 0 0 1 3 5a2 2 0 0 1 2-2',
        'truck' => 'M3 6h11v10H3zM14 9h4l3 3v4h-7M7 19.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3M17 19.5a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3',
        'grid' => 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z',
        'gallery' => 'M8 7h11a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H8a1 1 0 0 1-1-1V8a1 1 0 0 1 1-1zM4 16V5a1 1 0 0 1 1-1h11M7 17l4-4 3 3 2-2 4 4M15 10.5a1 1 0 1 0 2 0a1 1 0 1 0-2 0',
        'refresh' => 'M20 11a8 8 0 0 0-14.7-4.3L4 9M4 4v5h5M4 13a8 8 0 0 0 14.7 4.3L20 15M20 20v-5h-5',
        'pencil' => 'M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4z',
        'more' => 'M4.5 12a1 1 0 1 0 2 0a1 1 0 1 0-2 0M11 12a1 1 0 1 0 2 0a1 1 0 1 0-2 0M17.5 12a1 1 0 1 0 2 0a1 1 0 1 0-2 0',
        'filter' => 'M3 5h18l-7 8.5V20l-4-2v-4.5z',
        'columns' => 'M5 4h14a1 1 0 0 1 1 1v14a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1zM9.5 4v16M14.5 4v16',
        'x' => 'M18 6 6 18M6 6l12 12',
        'chevron-left' => 'M15 18l-6-6 6-6',
        'chevron-right' => 'M9 18l6-6-6-6',
        'chevron-up' => 'M6 15l6-6 6 6',
        'arrows-up-down' => 'M7 20V4M3 8l4-4 4 4M17 4v16M13 16l4 4 4-4',
        'grip' => 'M9 6h.01M9 12h.01M9 18h.01M15 6h.01M15 12h.01M15 18h.01',
        'clock' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M12 7v5l3 2',
        'history' => 'M3 12a9 9 0 1 0 2.6-6.4L3 8M3 3v5h5M12 7v5l3 2',
        'calendar-range' => 'M4 6h16v15H4zM4 10h16M8 3v4M16 3v4M8 14h3M13 17h3',
        'crown' => 'M3 8l4 4 5-7 5 7 4-4-2 11H5z',
        'lock' => 'M6 11h12v10H6zM8 11V7a4 4 0 0 1 8 0v4',
        'check' => 'M5 12l5 5L20 7',
        'ban' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M5.6 5.6l12.8 12.8',
        'check-circle' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M8.5 12l2.5 2.5 4.5-5',
        'send' => 'M22 2 11 13M22 2l-7 20-4-9-9-4z',
        'pause' => 'M8 5v14M16 5v14',
        'play' => 'M7 4v16l13-8z',
        'eye' => 'M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12zM9 12a3 3 0 1 0 6 0a3 3 0 1 0-6 0',
        'clipboard' => 'M9 4h6v3H9zM9 5H6v16h12V5h-3',
        'eye-off' => 'M3 3l18 18M10.6 5.1A10 10 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.2 4.2M6.6 6.6A17 17 0 0 0 2 12s3.5 7 10 7a9.6 9.6 0 0 0 5.4-1.6M9.9 9.9a3 3 0 0 0 4.2 4.2',
        'printer' => 'M6 9V3h12v6M6 18H4v-7h16v7h-2M7 14h10v7H7z',
        'info' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M12 11v5M12 8h.01',
        // 0.33: a warning, and the blocks preview's screens
        'alert-triangle' => 'M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0zM12 9v4M12 17h.01',
        'monitor' => 'M3 4h18v12H3zM8 21h8M12 16v5',
        'tablet' => 'M5 2h14v20H5zM11 18h2',
        'smartphone' => 'M7 2h10v20H7zM11 18h2',
        // 0.34: the FAQ block
        'help-circle' => 'M12 21a9 9 0 1 0 0-18 9 9 0 0 0 0 18M9.5 9.5a2.5 2.5 0 1 1 3.5 2.3c-.6.3-1 .9-1 1.6v.4M12 17h.01',
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PATHS);
    }

    /** An icon by exact name (no fallback); for toolbar buttons. */
    public static function svgFor(string $name): string
    {
        if (!isset(self::PATHS[$name])) { throw new \InvalidArgumentException('Unknown icon ' . $name . '.'); }
        return self::svg($name);
    }

    public static function svg(?string $name, string $class = 'fa-icon'): string
    {
        $path = self::PATHS[$name ?? 'list'] ?? self::PATHS['list'];

        return '<svg class="' . Str::e($class) . '" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"'
            . ' stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
            . '<path d="' . $path . '"/></svg>';
    }
}
