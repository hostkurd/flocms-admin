<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

use FloCMS\Admin\Admin;
use FloCMS\Admin\AdminConfig;
use FloCMS\Admin\Preferences\Preferences;
use FloCMS\Admin\Settings\PanelSettings;
use FloCMS\Admin\Settings\Settings;

/**
 * How the panel looks for the current user. Each value comes from, in order:
 * the user's own preference (theme, density), the saved "Admin panel"
 * settings (Settings → Admin panel), config/admin.php, the built-in default.
 *
 * @internal
 */
final class Appearance
{
    public static function brand(): string
    {
        $saved = self::panel('brand', AdminLang::language());
        return is_string($saved) && trim($saved) !== '' ? trim($saved) : AdminLang::label(Admin::config()->brand());
    }

    /** Logo URL for the admin language (falls back to the default language's), or null for the brand letter. */
    public static function logo(): ?string
    {
        $saved = self::panel('logo', AdminLang::language());
        return is_string($saved) && $saved !== '' ? $saved : Admin::config()->logo();
    }

    public static function primaryColor(): ?string
    {
        $saved = self::panel('primary_color');
        return is_string($saved) && preg_match('/^#[0-9a-f]{6}$/D', $saved) === 1 ? $saved : Admin::config()->primaryColor();
    }

    public static function theme(): string
    {
        return self::choose('theme', AdminConfig::THEMES, Admin::config()->theme(), true);
    }

    public static function sidebar(): string
    {
        return self::choose('sidebar', AdminConfig::SIDEBARS, Admin::config()->sidebar(), false);
    }

    public static function contentWidth(): string
    {
        return self::choose('content_width', AdminConfig::WIDTHS, Admin::config()->contentWidth(), false);
    }

    public static function density(): string
    {
        return self::choose('density', PanelSettings::DENSITIES, Admin::config()->density(), true);
    }

    /** The panel-wide value only (no personal preference): used as the "Panel default" label. */
    public static function panelDefault(string $name): string
    {
        return match ($name) {
            'theme' => self::choose('theme', AdminConfig::THEMES, Admin::config()->theme(), false),
            'density' => self::choose('density', PanelSettings::DENSITIES, Admin::config()->density(), false),
            default => '',
        };
    }

    /** @param list<string> $allowed */
    private static function choose(string $name, array $allowed, string $fallback, bool $personal): string
    {
        if ($personal) {
            $mine = Preferences::current()?->get($name);
            if (is_string($mine) && in_array($mine, $allowed, true)) { return $mine; }
        }
        $saved = self::panel($name);
        return is_string($saved) && in_array($saved, $allowed, true) ? $saved : $fallback;
    }

    private static function panel(string $field, ?string $lang = null): mixed
    {
        if (!Admin::config()->panelSettingsEnabled()) {
            return null;
        }
        $value = Settings::get('panel.' . $field, null, $lang);
        return $value === '' ? null : $value;
    }
}
