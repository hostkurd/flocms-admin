<?php
declare(strict_types=1);
namespace FloCMS\Admin;

use FloCMS\Admin\Support\Str;
use FloCMS\Reactive\Reactive;

final class Assets
{
    public static function tags(): string
    {
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        $lang = defined('ACTIVE_LANG') ? rawurlencode((string) ACTIVE_LANG) . '/' : '';
        $url = $base . '/' . $lang . 'flo-admin/asset/admin-v' . Admin::VERSION;
        // Navigation owns popstate inside the panel and loads before Reactive.
        return '<link rel="stylesheet" href="' . Str::e($url . '.css') . '">'
            . '<script defer src="' . Str::e($url . '.js') . '"></script>'
            . (Admin::config()->reactiveEnabled() ? Reactive::assets() : '');
    }
}
