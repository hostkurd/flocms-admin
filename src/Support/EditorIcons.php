<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

/**
 * Rich text toolbar icons from Lucide 0.540.0 (ISC licence, see
 * THIRD_PARTY_NOTICES.md), drawn on a 24-unit grid with a 2-unit stroke and
 * shown at 20 px so lines stay sharp.
 *
 * @internal
 */
final class EditorIcons
{
    private const ICONS = [
        'bold' => '<path d="M6 12h9a4 4 0 0 1 0 8H7a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h7a4 4 0 0 1 0 8"/>',
        'italic' => '<line x1="19" x2="10" y1="4" y2="4"/> <line x1="14" x2="5" y1="20" y2="20"/> <line x1="15" x2="9" y1="4" y2="20"/>',
        'underline' => '<path d="M6 4v6a6 6 0 0 0 12 0V4"/> <line x1="4" x2="20" y1="20" y2="20"/>',
        'strike' => '<path d="M16 4H9a3 3 0 0 0-2.83 4"/> <path d="M14 12a4 4 0 0 1 0 8H6"/> <line x1="4" x2="20" y1="12" y2="12"/>',
        'link' => '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/> <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
        'heading2' => '<path d="M4 12h8"/> <path d="M4 18V6"/> <path d="M12 18V6"/> <path d="M21 18h-4c0-4 4-3 4-6 0-1.5-2-2.5-4-1"/>',
        'heading3' => '<path d="M4 12h8"/> <path d="M4 18V6"/> <path d="M12 18V6"/> <path d="M17.5 10.5c1.7-1 3.5 0 3.5 1.5a2 2 0 0 1-2 2"/> <path d="M17 17.5c2 1.5 4 .3 4-1.5a2 2 0 0 0-2-2"/>',
        'bulletList' => '<path d="M3 12h.01"/> <path d="M3 18h.01"/> <path d="M3 6h.01"/> <path d="M8 12h13"/> <path d="M8 18h13"/> <path d="M8 6h13"/>',
        'orderedList' => '<path d="M10 12h11"/> <path d="M10 18h11"/> <path d="M10 6h11"/> <path d="M4 10h2"/> <path d="M4 6h1v4"/> <path d="M6 18H4c0-1 2-2 2-3s-1-1.5-2-1"/>',
        'blockquote' => '<path d="M17 6H3"/> <path d="M21 12H8"/> <path d="M21 18H8"/> <path d="M3 12v6"/>',
        'codeBlock' => '<path d="m18 16 4-4-4-4"/> <path d="m6 8-4 4 4 4"/> <path d="m14.5 4-5 16"/>',
        'horizontalRule' => '<path d="M5 12h14"/>',
        'image' => '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/> <circle cx="9" cy="9" r="2"/> <path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/>',
        'video' => '<path d="m16 13 5.223 3.482a.5.5 0 0 0 .777-.416V7.87a.5.5 0 0 0-.752-.432L16 10.5"/> <rect x="2" y="6" width="14" height="12" rx="2"/>',
        'embed' => '<rect x="3" y="3" width="18" height="18" rx="2"/> <path d="M9 9.003a1 1 0 0 1 1.517-.859l4.997 2.997a1 1 0 0 1 0 1.718l-4.997 2.997A1 1 0 0 1 9 14.996z"/>',
        'undo' => '<path d="M9 14 4 9l5-5"/> <path d="M4 9h10.5a5.5 5.5 0 0 1 5.5 5.5a5.5 5.5 0 0 1-5.5 5.5H11"/>',
        'redo' => '<path d="m15 14 5-5-5-5"/> <path d="M20 9H9.5A5.5 5.5 0 0 0 4 14.5A5.5 5.5 0 0 0 9.5 20H13"/>',
        'upload' => '<path d="M12 3v12"/> <path d="m17 8-5-5-5 5"/> <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>',
        'fromLink' => '<path d="M9 17H7A5 5 0 0 1 7 7h2"/> <path d="M15 7h2a5 5 0 1 1 0 10h-2"/> <line x1="8" x2="16" y1="12" y2="12"/>',
        'close' => '<path d="M18 6 6 18"/> <path d="m6 6 12 12"/>',
        'unlink' => '<path d="m18.84 12.25 1.72-1.71h-.02a5.004 5.004 0 0 0-.12-7.07 5.006 5.006 0 0 0-6.95 0l-1.72 1.71"/> <path d="m5.17 11.75-1.71 1.71a5.004 5.004 0 0 0 .12 7.07 5.006 5.006 0 0 0 6.95 0l1.71-1.71"/> <line x1="8" x2="8" y1="2" y2="5"/> <line x1="2" x2="5" y1="8" y2="8"/> <line x1="16" x2="16" y1="19" y2="22"/> <line x1="19" x2="22" y1="16" y2="16"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
    ];

    public static function svg(string $name): string
    {
        if (!isset(self::ICONS[$name])) { throw new \InvalidArgumentException('Unknown editor icon ' . $name . '.'); }
        return '<svg class="fa-rich-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor"'
            . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . self::ICONS[$name] . '</svg>';
    }
}
