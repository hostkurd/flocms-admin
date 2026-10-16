<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

use FloCMS\Admin\Admin;

/**
 * The optional brand colour (`primary_color`) as a tiny stylesheet served by the
 * asset route, so pages stay free of inline styles under a strict CSP. Its file
 * name carries a hash of the colour, so a changed colour is a new, cacheable URL.
 *
 * @internal
 */
final class Theme
{
    /** File name for the configured colour, or null when the default colour is used. */
    public static function fileName(): ?string
    {
        $color = Appearance::primaryColor();
        return $color === null ? null : 'theme-' . substr(hash('sha256', $color . '|v1'), 0, 12) . '.css';
    }

    public static function url(): ?string
    {
        $name = self::fileName();
        if ($name === null) { return null; }
        $base = defined('SITE_URI') ? rtrim((string) SITE_URI, '/') : '';
        $lang = defined('ACTIVE_LANG') ? rawurlencode((string) ACTIVE_LANG) . '/' : '';
        return $base . '/' . $lang . 'flo-admin/asset/' . $name;
    }

    public static function tag(): string
    {
        $url = self::url();
        return $url === null ? '' : '<link rel="stylesheet" href="' . Str::e($url) . '">';
    }

    /** Custom properties derived from one #rrggbb colour, for the light and dark themes. */
    public static function css(string $color): string
    {
        $light = "--fa-primary:$color;--fa-primary-hover:color-mix(in srgb,$color 82%,#000);--fa-primary-text:$color;"
            . "--fa-primary-soft:color-mix(in srgb,$color 9%,#fff);--fa-primary-border:color-mix(in srgb,$color 38%,#fff);"
            . "--fa-focus:$color;--fa-chart:$color;--fa-row-selected:color-mix(in srgb,$color 5%,#fff)";
        $dark = "--fa-primary-hover:color-mix(in srgb,$color 80%,#fff);--fa-primary-text:color-mix(in srgb,$color 55%,#fff);"
            . "--fa-primary-soft:color-mix(in srgb,$color 20%,transparent);--fa-primary-border:color-mix(in srgb,$color 50%,transparent);"
            . "--fa-focus:color-mix(in srgb,$color 55%,#fff);--fa-chart:color-mix(in srgb,$color 70%,#fff);--fa-row-selected:color-mix(in srgb,$color 8%,transparent)";
        $scope = '.fa-panel,.fa-login,.fa-toasts';
        $darkScope = '.fa-panel[data-theme="dark"],.fa-login[data-theme="dark"],.fa-toasts[data-theme="dark"]';
        $autoScope = '.fa-panel[data-theme="auto"],.fa-login[data-theme="auto"],.fa-toasts[data-theme="auto"]';
        return "/* flocms-admin brand colour $color */\n$scope{{$light}}\n$darkScope{{$dark}}\n@media (prefers-color-scheme:dark){{$autoScope}{{$dark}}}\n";
    }

    /** WCAG contrast ratio of white text on the colour (buttons use white text). */
    public static function contrastWithWhite(string $color): float
    {
        $channels = array_map(static function (string $pair): float {
            $value = hexdec($pair) / 255;
            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        }, str_split(ltrim($color, '#'), 2));
        $luminance = 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
        return 1.05 / ($luminance + 0.05);
    }
}
