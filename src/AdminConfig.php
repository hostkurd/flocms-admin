<?php
declare(strict_types=1);

namespace FloCMS\Admin;

use FloCMS\Admin\Translation\Languages;

/**
 * Values of config/admin.php (written by `php flo admin:install`), with defaults.
 */
final class AdminConfig
{
    /** @param array<string, mixed> $values */
    public function __construct(
        private readonly array $values,
        private readonly string $root,
        private readonly bool $installed
    ) {
    }

    /** Whether config/admin.php exists: without it the package stays out of the way. */
    public function isInstalled(): bool
    {
        return $this->installed;
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return list<class-string<Resource>> classes listed explicitly; [] means "scan resources_path" */
    public function resources(): array
    {
        $list = $this->values['resources'] ?? [];

        return array_values(array_filter(is_array($list) ? $list : [], 'is_string'));
    }

    public function resourcesPath(): string
    {
        $path = (string) ($this->values['resources_path'] ?? 'admin/Resources');
        $path = str_replace('\\', '/', $path);

        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;

        return $absolute ? rtrim($path, '/') : rtrim($this->root, '/\\') . '/' . trim($path, '/');
    }

    public function resourcesNamespace(): string
    {
        return trim((string) ($this->values['resources_namespace'] ?? 'App\\Admin\\Resources'), '\\');
    }

    public function brand(): string
    {
        $brand = $this->values['brand'] ?? null;

        return is_string($brand) && $brand !== '' ? $brand : 'Admin';
    }

    public function perPage(): int
    {
        return max(1, min(500, (int) ($this->values['per_page'] ?? 25)));
    }

    /** @return list<string> */
    public function rtlLanguages(): array
    {
        $list = $this->values['rtl_languages'] ?? Languages::DEFAULT_RTL;

        return array_values(array_map('strval', is_array($list) ? $list : Languages::DEFAULT_RTL));
    }

    /** Whether the built-in users management (/admin/accounts) and profile page are on. */
    public function usersEnabled(): bool
    {
        return ($this->values['users'] ?? true) !== false;
    }

    /** Disable progressive navigation and components for a legacy application. */
    public function reactiveEnabled(): bool { return ($this->values['reactive'] ?? true) !== false; }
    /** Opt-in for existing installations; fresh installs include this setting. */
    public function loginThemeEnabled(): bool { return ($this->values['login_theme'] ?? false) === true; }

    public const THEMES = ['light', 'dark', 'auto'];
    public const SIDEBARS = ['light', 'dark'];
    public const WIDTHS = ['full', 'boxed'];

    /** Page content width: 'full' (default, uses the whole window) or 'boxed' (centred, at most 1280 px). */
    public function contentWidth(): string
    {
        $width = $this->values['content_width'] ?? 'full';
        return is_string($width) && in_array($width, self::WIDTHS, true) ? $width : 'full';
    }

    /**
     * Ctrl+K search (0.20). On unless 'search' => false; 'per_group' (1-10, default 5),
     * 'shortcut' (default true: Ctrl+K, Cmd+K and / open it) and 'sources' (classes
     * implementing FloCMS\Admin\Search\SearchSource, for a site's own results).
     *
     * @return array{enabled: bool, per_group: int, shortcut: bool, sources: list<class-string>}
     */
    public function search(): array
    {
        $value = $this->values['search'] ?? true;
        $options = is_array($value) ? $value : [];
        $per = $options['per_group'] ?? 5;
        $sources = is_array($options['sources'] ?? null) ? $options['sources'] : [];
        return [
            'enabled' => $value !== false && ($options['enabled'] ?? true) !== false,
            'per_group' => is_int($per) ? max(1, min(10, $per)) : 5,
            'shortcut' => ($options['shortcut'] ?? true) !== false,
            'sources' => array_values(array_filter($sources, static fn ($class): bool => is_string($class) && is_subclass_of($class, \FloCMS\Admin\Search\SearchSource::class))),
        ];
    }

    /**
     * The least height of the body of the panel's form and action dialogs (0.19.1):
     * 'dialog_min_height' => 240 (pixels) or a CSS length such as '20rem'. Unset,
     * a dialog fits its content.
     */
    public function dialogMinHeight(): ?string
    {
        return self::cssLength($this->values['dialog_min_height'] ?? null);
    }

    /** A non-negative CSS length (an int is pixels), or null when unset or invalid. */
    public static function cssLength(mixed $value): ?string
    {
        if (is_string($value) && preg_match('/^\d{1,4}(?:\.\d{1,3})?$/D', trim($value)) === 1) { $value = (float) trim($value); }
        if ((is_int($value) || is_float($value)) && is_finite((float) $value) && $value >= 0 && $value <= 2000) {
            return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.') . 'px';
        }
        if (is_string($value) && preg_match('/^\d{1,4}(?:\.\d{1,3})?(?:px|rem|em|vh|dvh|svh|lvh)$/D', trim($value)) === 1) { return trim($value); }
        return null;
    }

    /** Brand colour as #rrggbb (from #rgb or #rrggbb), or null for the default blue. Invalid values are ignored; admin:doctor reports them. */
    public function primaryColor(): ?string
    {
        $color = $this->values['primary_color'] ?? null;
        if (!is_string($color) || preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/iD', trim($color)) !== 1) { return null; }
        $hex = strtolower(ltrim(trim($color), '#'));
        if (strlen($hex) === 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
        return '#' . $hex;
    }

    /** URL of a logo image for the sidebar and login page, or null to show the brand's first letter. */
    public function logo(): ?string
    {
        $logo = $this->values['logo'] ?? null;
        if (!is_string($logo) || ($logo = trim($logo)) === '' || preg_match('~^(https?://|/)[^\s"\'<>]*$~iD', $logo) !== 1 || str_starts_with($logo, '//')) { return null; }
        return $logo;
    }

    /** List page density: comfortable (default) or compact. */
    public function density(): string
    {
        $density = $this->values['density'] ?? 'comfortable';
        return is_string($density) && in_array($density, \FloCMS\Admin\Settings\PanelSettings::DENSITIES, true) ? $density : 'comfortable';
    }

    /** Sidebar style: light (default) or dark. */
    public function sidebar(): string
    {
        $sidebar = $this->values['sidebar'] ?? 'light';
        return is_string($sidebar) && in_array($sidebar, self::SIDEBARS, true) ? $sidebar : 'light';
    }

    /** Colour theme: light (default), dark, or auto (follows the operating system). Unknown values fall back to light; admin:doctor reports them. */
    public function theme(): string
    {
        $theme = $this->values['theme'] ?? 'light';

        return is_string($theme) && in_array($theme, self::THEMES, true) ? $theme : 'light';
    }

    /**
     * Where rich text editor uploads (images, videos) are stored and their public
     * URL. Defaults: <root>/public/uploads/editor served at <site path>/uploads/editor.
     *
     * @return array{root: string, url: string}
     */
    public function media(): array
    {
        $media = is_array($this->values['media'] ?? null) ? $this->values['media'] : [];
        $root = is_string($media['root'] ?? null) && trim($media['root']) !== '' ? str_replace('\\', '/', trim($media['root'])) : 'public/uploads/editor';
        $absolute = str_starts_with($root, '/') || preg_match('/^[A-Za-z]:\//', $root) === 1;
        $root = $absolute ? rtrim($root, '/') : rtrim($this->root, '/\\') . '/' . trim($root, '/');
        $url = $media['url'] ?? null;
        if (!is_string($url) || preg_match('~^(https?://[^\s"\'<>/]+)?/[^\s"\'<>]*$~iD', $url = rtrim(trim($url), '/')) !== 1 || str_starts_with($url, '//')) {
            $path = defined('SITE_URI') ? (string) parse_url((string) SITE_URI, PHP_URL_PATH) : '';
            $url = rtrim($path, '/') . '/uploads/editor';
        }
        return ['root' => $root, 'url' => $url];
    }

    /**
     * The media library (0.17): on unless 'media_library' => false. Its tables
     * default to media, media_usage and media_folders (0.18); ['table' => …,
     * 'usage_table' => …, 'folders_table' => …] renames them (for a site that
     * already has a media table of its own).
     * Upload limits: 'max_image_kb' (default 10240) and 'max_video_mb' (default 200).
     *
     * @return array{enabled: bool, table: string, usage_table: string, folders_table: string, max_image_bytes: int, max_video_bytes: int}
     */
    public function mediaLibrary(): array
    {
        $value = $this->values['media_library'] ?? true;
        $options = is_array($value) ? $value : [];
        $name = static function (mixed $candidate, string $default): string {
            return is_string($candidate) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,47}$/D', $candidate) === 1 ? $candidate : $default;
        };
        $image = is_int($options['max_image_kb'] ?? null) ? max(1, min(65536, $options['max_image_kb'])) : 10240;
        $video = is_int($options['max_video_mb'] ?? null) ? max(1, min(4096, $options['max_video_mb'])) : 200;
        return [
            'enabled' => $value !== false && ($options['enabled'] ?? true) !== false,
            'table' => $name($options['table'] ?? null, 'media'),
            'usage_table' => $name($options['usage_table'] ?? null, 'media_usage'),
            'folders_table' => $name($options['folders_table'] ?? null, 'media_folders'),
            'max_image_bytes' => $image * 1024,
            'max_video_bytes' => $video * 1024 * 1024,
        ];
    }

    /**
     * The trash (0.19): deleted records wait here before they are deleted for good.
     * On unless 'trash' => false; 'days' (default 30, 0 = until emptied by hand)
     * and 'table' (default admin_trash) can be set: ['days' => 14].
     *
     * @return array{enabled: bool, table: string, days: int}
     */
    public function trash(): array
    {
        $value = $this->values['trash'] ?? true;
        $options = is_array($value) ? $value : [];
        $days = is_int($options['days'] ?? null) ? max(0, min(3650, $options['days'])) : 30;
        return [
            'enabled' => $value !== false && ($options['enabled'] ?? true) !== false,
            'table' => self::tableName($options['table'] ?? null, 'admin_trash'),
            'days' => $days,
        ];
    }

    /**
     * The activity log (0.19): who changed what, and when. On unless 'activity' => false;
     * 'days' (default 365, 0 = forever), 'table' (default admin_activity) and
     * 'logins' (default false: sign-ins are not recorded).
     *
     * @return array{enabled: bool, table: string, days: int, logins: bool}
     */
    public function activity(): array
    {
        $value = $this->values['activity'] ?? true;
        $options = is_array($value) ? $value : [];
        $days = is_int($options['days'] ?? null) ? max(0, min(36500, $options['days'])) : 365;
        return [
            'enabled' => $value !== false && ($options['enabled'] ?? true) !== false,
            'table' => self::tableName($options['table'] ?? null, 'admin_activity'),
            'days' => $days,
            'logins' => ($options['logins'] ?? false) === true,
        ];
    }

    /**
     * The Roles page (0.21): roles and permissions edited in the panel and stored
     * in a table, on top of config/config.php. On unless 'roles' => false (the
     * emergency switch: the config roles apply again at once). 'table' (default
     * admin_roles) renames the table; 'permissions' lists the site's own permission
     * names with a label (a lang key or text) so they get a row in the grid:
     * ['permissions' => ['reports.export' => 'Export reports']].
     *
     * @return array{enabled: bool, table: string, permissions: array<string, string>}
     */
    public function roles(): array
    {
        $value = $this->values['roles'] ?? true;
        $options = is_array($value) ? $value : [];
        $permissions = [];
        foreach (is_array($options['permissions'] ?? null) ? $options['permissions'] : [] as $name => $label) {
            if (is_string($name) && \FloCMS\Admin\Roles\Roles::validPattern($name) && !str_contains($name, '*') && is_scalar($label)) {
                $permissions[$name] = (string) $label;
            }
        }
        return [
            'enabled' => $value !== false && ($options['enabled'] ?? true) !== false,
            'table' => self::tableName($options['table'] ?? null, 'admin_roles'),
            'permissions' => $permissions,
        ];
    }

    private static function tableName(mixed $candidate, string $default): string
    {
        return is_string($candidate) && preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,47}$/D', $candidate) === 1 ? $candidate : $default;
    }

    /** Absolute path of the file that declares the settings pages (default admin/settings.php). */
    public function settingsPath(): string
    {
        $path = str_replace('\\', '/', (string) ($this->values['settings_path'] ?? 'admin/settings.php'));
        $absolute = str_starts_with($path, '/') || preg_match('/^[A-Za-z]:\//', $path) === 1;
        return $absolute ? $path : rtrim($this->root, '/\\') . '/' . ltrim($path, '/');
    }

    /** Whether the built-in "Admin panel" settings page is offered (default yes). */
    public function panelSettingsEnabled(): bool
    {
        return ($this->values['panel_settings'] ?? true) !== false;
    }

    /**
     * Where files uploaded on settings pages are stored and their public URL.
     * They are public (a logo is shown on the website and the login page).
     * Defaults: <root>/public/uploads/settings served at <site path>/uploads/settings.
     *
     * @return array{root: string, url: string}
     */
    public function settingsUploads(): array
    {
        $uploads = is_array($this->values['settings_uploads'] ?? null) ? $this->values['settings_uploads'] : [];
        $root = is_string($uploads['root'] ?? null) && trim($uploads['root']) !== '' ? str_replace('\\', '/', trim($uploads['root'])) : 'public/uploads/settings';
        $absolute = str_starts_with($root, '/') || preg_match('/^[A-Za-z]:\//', $root) === 1;
        $root = $absolute ? rtrim($root, '/') : rtrim($this->root, '/\\') . '/' . trim($root, '/');
        $url = $uploads['url'] ?? null;
        if (!is_string($url) || preg_match('~^(https?://[^\s"\'<>/]+)?/[^\s"\'<>]*$~iD', $url = rtrim(trim($url), '/')) !== 1 || str_starts_with($url, '//')) {
            $path = defined('SITE_URI') ? (string) parse_url((string) SITE_URI, PHP_URL_PATH) : '';
            $url = rtrim($path, '/') . '/uploads/settings';
        }
        return ['root' => $root, 'url' => $url];
    }

    public function permissionPrefix(): string
    {
        $prefix = trim((string) ($this->values['permission_prefix'] ?? 'content'), '.');

        return $prefix === '' ? 'content' : $prefix;
    }

    public function widgetColumns(): int
    {
        $columns = $this->values['widget_columns'] ?? 2;
        if (!is_int($columns) || $columns < 1 || $columns > 4) { throw new \InvalidArgumentException('widget_columns must be 1–4.'); }
        return $columns;
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->values;
    }
}
