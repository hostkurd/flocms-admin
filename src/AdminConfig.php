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
