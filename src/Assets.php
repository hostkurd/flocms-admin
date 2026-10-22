<?php
declare(strict_types=1);
namespace FloCMS\Admin;

use FloCMS\Admin\Support\Str;
use FloCMS\Reactive\Reactive;

final class Assets
{
    /** The login page needs styling and a password toggle, without loading Reactive. */
    public static function loginTags(): string
    {
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        $lang = defined('ACTIVE_LANG') ? rawurlencode((string) ACTIVE_LANG) . '/' : '';
        $url = $base . '/' . $lang . 'flo-admin/asset/admin-v' . Admin::VERSION;
        return '<link rel="stylesheet" href="' . Str::e($url . '.css') . '">' . \FloCMS\Admin\Support\Theme::tag() . '<script defer src="' . Str::e($url . '.js') . '"></script>';
    }
    /** The rich text editor script, loaded on demand by admin.js. */
    public static function editorUrl(): string
    {
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        $lang = defined('ACTIVE_LANG') ? rawurlencode((string) ACTIVE_LANG) . '/' : '';
        return $base . '/' . $lang . 'flo-admin/asset/admin-editor-v' . Admin::VERSION . '.js';
    }

    /** A block's style or script from the package (0.30): /flo-admin/asset/blocks-<name>-v<version>.<css|js>. */
    public static function blockUrl(string $name, string $kind): string
    {
        return preg_replace('~rich-text-v[^/]+\.css$~', 'blocks-' . $name . '-v' . Admin::VERSION . '.' . $kind, self::richTextUrl()) ?? '';
    }

    /** A page's block CSS (0.33): /flo-admin/asset/block-styles-<hash>.css (BlockCss). */
    public static function blockStylesUrl(string $hash): string
    {
        return preg_replace('~rich-text-v[^/]+\.css$~', 'block-styles-' . $hash . '.css', self::richTextUrl()) ?? '';
    }

    /** The rich text stylesheet for the website (RichText::stylesheet() prints its tag). */
    public static function richTextUrl(): string
    {
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        $lang = defined('ACTIVE_LANG') ? rawurlencode((string) ACTIVE_LANG) . '/' : '';
        return $base . '/' . $lang . 'flo-admin/asset/rich-text-v' . Admin::VERSION . '.css';
    }

    public static function tags(): string
    {
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        $lang = defined('ACTIVE_LANG') ? rawurlencode((string) ACTIVE_LANG) . '/' : '';
        $url = $base . '/' . $lang . 'flo-admin/asset/admin-v' . Admin::VERSION;
        // Navigation owns popstate inside the panel and loads before Reactive.
        return '<link rel="stylesheet" href="' . Str::e($url . '.css') . '">' . \FloCMS\Admin\Support\Theme::tag()
            . '<script defer src="' . Str::e($url . '.js') . '"></script>'
            . (Admin::config()->reactiveEnabled() ? Reactive::assets() : '');
    }
}
