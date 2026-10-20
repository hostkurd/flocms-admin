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

    /** How translatable fields are edited (0.23): one language at a time on the page, or a badge per field. */
    public const TRANSLATION_MODES = ['page', 'field'];

    /**
     * The panel's translation mode: 'page' (an "Editing: language" switch shows
     * the whole form in one language) or 'field' (a language badge per field).
     * Missing or unknown: 'field', so sites from before 0.23 do not change.
     */
    public function translationMode(): string
    {
        $mode = $this->values['translation_mode'] ?? 'field';
        return is_string($mode) && in_array($mode, self::TRANSLATION_MODES, true) ? $mode : 'field';
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

    /**
     * CSV export (0.25): on unless 'export' => false; 'limit' (default 50,000
     * rows, at most 1,000,000) is the most rows one download may hold.
     *
     * @return array{enabled: bool, limit: int}
     */
    public function export(): array
    {
        $value = $this->values['export'] ?? true;
        $options = is_array($value) ? $value : [];
        return [
            'enabled' => $value !== false && ($options['enabled'] ?? true) !== false,
            'limit' => is_int($options['limit'] ?? null) ? max(1, min(1000000, $options['limit'])) : 50000,
        ];
    }

    /**
     * CSV import (0.25): on unless 'import' => false. 'max_bytes' (default 10 MB,
     * at most 64 MB), 'max_rows' (default 10,000, at most 100,000), 'batch_size'
     * (rows per request, default 100, 10–1000), 'keep_hours' (how long an
     * unfinished upload is kept, default 24), 'undo_days' (default 7, 0 = no Undo)
     * and the tables 'table' (admin_imports) and 'rows_table' (admin_import_rows).
     *
     * @return array{enabled: bool, table: string, rows_table: string, max_bytes: int, max_rows: int, batch_size: int, keep_hours: int, undo_days: int}
     */
    /**
     * Emails to users (0.28): on unless 'mailings' => false. Options: the three
     * table names, 'per_batch' (emails per step, default 20), 'per_hour' (the
     * whole site's mailings, default 200 — shared hosts often allow 100–500),
     * 'direct_per_hour' (direct emails per admin, default 50) and 'keep_days'
     * (how long recipient lists are kept after a mailing, default 30).
     *
     * @return array{enabled: bool, table: string, recipients_table: string, optouts_table: string, per_batch: int, per_hour: int, direct_per_hour: int, keep_days: int}
     */
    public function mailings(): array
    {
        $value = $this->values['mailings'] ?? true;
        $options = is_array($value) ? $value : [];
        $int = static fn (string $key, int $default, int $min, int $max): int => is_int($options[$key] ?? null) ? max($min, min($max, $options[$key])) : $default;
        return [
            'enabled' => $value !== false && ($options['enabled'] ?? true) !== false && $this->usersEnabled(),
            'table' => self::tableName($options['table'] ?? null, 'admin_mailings'),
            'recipients_table' => self::tableName($options['recipients_table'] ?? null, 'admin_mailing_recipients'),
            'optouts_table' => self::tableName($options['optouts_table'] ?? null, 'admin_mail_optouts'),
            'per_batch' => $int('per_batch', 20, 1, 200),
            'per_hour' => $int('per_hour', 200, 1, 100000),
            'direct_per_hour' => $int('direct_per_hour', 50, 1, 1000),
            'keep_days' => $int('keep_days', 30, 1, 365),
        ];
    }

    /**
     * The site's own kinds of blocks (0.29) for every Builder field and Pages:
     * 'blocks' => [App\Blocks\LatestPosts::class]. Unknown entries are left out (admin:doctor names them).
     *
     * @return list<class-string<\FloCMS\Admin\Blocks\Block>>
     */
    /** The 'blocks' value as written, for admin:doctor. */
    public function configuredBlocks(): mixed
    {
        return $this->values['blocks'] ?? [];
    }

    public function blocks(): array
    {
        $value = $this->values['blocks'] ?? [];
        return array_values(array_filter(is_array($value) ? $value : [], static fn ($class): bool => is_string($class) && is_a($class, \FloCMS\Admin\Blocks\Block::class, true)));
    }

    public function import(): array
    {
        $value = $this->values['import'] ?? true;
        $options = is_array($value) ? $value : [];
        $int = static fn (string $key, int $default, int $min, int $max): int => is_int($options[$key] ?? null) ? max($min, min($max, $options[$key])) : $default;
        return [
            'enabled' => $value !== false && ($options['enabled'] ?? true) !== false,
            'table' => self::tableName($options['table'] ?? null, 'admin_imports'),
            'rows_table' => self::tableName($options['rows_table'] ?? null, 'admin_import_rows'),
            'max_bytes' => $int('max_bytes', 10485760, 1024, 67108864),
            'max_rows' => $int('max_rows', 10000, 1, 100000),
            'batch_size' => $int('batch_size', 100, 10, 1000),
            'keep_hours' => $int('keep_hours', 24, 1, 720),
            'undo_days' => $int('undo_days', 7, 0, 365),
        ];
    }

    /**
     * Forgot password (0.26): on when config/admin.php has 'password_reset'
     * => true (or an array of options); off without the key, so existing
     * configurations opt in (UPGRADING.md). New configurations have it.
     *
     * @return array{enabled: bool, table: string, expires: int, per_email: int, per_ip: int}
     */
    public function passwordReset(): array
    {
        $value = $this->values['password_reset'] ?? false;
        $options = is_array($value) ? $value : [];
        $int = static fn (string $key, int $default, int $min, int $max): int => is_int($options[$key] ?? null) ? max($min, min($max, $options[$key])) : $default;
        return [
            'enabled' => ($value === true || is_array($value)) && ($options['enabled'] ?? true) !== false,
            'table' => self::tableName($options['table'] ?? null, 'admin_password_resets'),
            'expires' => $int('expires', 60, 5, 1440),
            'per_email' => $int('per_email', 3, 1, 100),
            'per_ip' => $int('per_ip', 10, 1, 1000),
        ];
    }

    /**
     * Pages (0.30): 'pages' => true, or an array. Off by default.
     *   'table'    the pages table (admin_pages)
     *   'missing'  a page not translated: 'default' shows the default language's (with hreflang), '404' does not
     *   'sitemap'  /sitemap.xml lists the published pages (true), unless the site has its own
     *
     * @return array{enabled: bool, table: string, missing: string, sitemap: bool}
     */
    public function pages(): array
    {
        $value = $this->values['pages'] ?? false;
        $options = is_array($value) ? $value : [];
        return [
            'enabled' => ($value === true || is_array($value)) && ($options['enabled'] ?? true) !== false,
            'table' => self::tableName($options['table'] ?? null, 'admin_pages'),
            'missing' => ($options['missing'] ?? 'default') === '404' ? '404' : 'default',
            'sitemap' => ($options['sitemap'] ?? true) !== false,
        ];
    }

    /**
     * Mail settings of config/admin.php (0.26): 'send' replaces sending.
     *
     * @return array{send: ?callable}
     */
    public function mail(): array
    {
        $value = $this->values['mail'] ?? [];
        $send = is_array($value) ? ($value['send'] ?? null) : null;
        return ['send' => is_callable($send) ? $send : null];
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

    /**
     * Where public resource uploads go (0.28: FileUpload::public(), such as
     * avatars): shown on the panel and on the website without a permission check.
     * Defaults: <root>/public/uploads/admin served at <site path>/uploads/admin.
     *
     * @return array{root: string, url: string}
     */
    public function publicUploads(): array
    {
        $uploads = is_array($this->values['public_uploads'] ?? null) ? $this->values['public_uploads'] : [];
        $root = is_string($uploads['root'] ?? null) && trim($uploads['root']) !== '' ? str_replace('\\', '/', trim($uploads['root'])) : 'public/uploads/admin';
        $absolute = str_starts_with($root, '/') || preg_match('/^[A-Za-z]:\//', $root) === 1;
        $root = $absolute ? rtrim($root, '/') : rtrim($this->root, '/\\') . '/' . trim($root, '/');
        $url = $uploads['url'] ?? null;
        if (!is_string($url) || preg_match('~^(https?://[^\s"\'<>/]+)?/[^\s"\'<>]*$~iD', $url = rtrim(trim($url), '/')) !== 1 || str_starts_with($url, '//')) {
            $url = self::sitePath() . '/uploads/admin';
        }
        return ['root' => $root, 'url' => $url];
    }

    /** The site's path below its domain ('' at the root), from SITE_URI. */
    public static function sitePath(): string
    {
        return rtrim(defined('SITE_URI') ? (string) parse_url((string) SITE_URI, PHP_URL_PATH) : '', '/');
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
