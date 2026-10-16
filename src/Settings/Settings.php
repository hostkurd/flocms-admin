<?php
declare(strict_types=1);

namespace FloCMS\Admin\Settings;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields\FileUpload;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\App;
use Throwable;

/**
 * Reading settings on the website (and in the panel).
 *
 *     Settings::get('site.title')              // current language, then the default one
 *     Settings::get('site.title', '', 'ar')
 *     Settings::page('site')                   // ['title' => …, 'logo' => …]
 *     Settings::translations('site.title')     // ['en' => …, 'ar' => …]
 *
 * The first read of a request loads storage/cache/flocms-admin-settings.php
 * (stored values plus the declared defaults); later reads cost nothing. The
 * file is rebuilt when a settings page is saved, when admin/settings.php
 * changes, and after `php flo cache:clear`. Without the table (migrations
 * not run) or a database, reads return the defaults and never throw.
 */
final class Settings
{
    private const FORMAT = 1;

    /** @var array{values: array<string, mixed>, defaults: array<string, mixed>, files: array<string, true>, pages: array<string, list<string>>}|null */
    private static ?array $data = null;

    /**
     * The value of a setting: text in $lang (default: the current language,
     * then the default language, then the first filled one); a file's public
     * URL; $default when nothing is stored and no default is declared.
     */
    public static function get(string $key, mixed $default = null, ?string $lang = null): mixed
    {
        $data = self::data();
        $value = self::filled($data['values'][$key] ?? null) ? $data['values'][$key] : ($data['defaults'][$key] ?? null);
        if (!self::filled($value)) {
            return $default ?? '';
        }
        if (is_array($value)) {
            $value = self::pick($value, $lang);
        }
        if (isset($data['files'][$key]) && is_string($value)) {
            return self::url($value);
        }
        return $value;
    }

    /**
     * All values of one page in one language, keyed by field name.
     *
     * @return array<string, mixed>
     */
    public static function page(string $page, ?string $lang = null): array
    {
        $values = [];
        foreach (self::data()['pages'][$page] ?? [] as $field) {
            $values[$field] = self::get($page . '.' . $field, '', $lang);
        }
        return $values;
    }

    /**
     * Every language of a translatable setting (files as URLs); a plain value
     * is returned as the default language's.
     *
     * @return array<string, string>
     */
    public static function translations(string $key): array
    {
        $data = self::data();
        $value = self::filled($data['values'][$key] ?? null) ? $data['values'][$key] : ($data['defaults'][$key] ?? null);
        if (!self::filled($value)) { return []; }
        $values = is_array($value) ? array_filter(array_map('strval', $value), static fn (string $v): bool => $v !== '') : [Languages::default() => (string) $value];
        return isset($data['files'][$key]) ? array_map([self::class, 'url'], $values) : $values;
    }

    /** The stored value as it is in the table (null when not stored). */
    public static function stored(string $key): mixed
    {
        return self::data()['values'][$key] ?? null;
    }

    /** Public URL of a stored settings file path ("site/logo/ab12….png"). */
    public static function url(string $path): string
    {
        if (preg_match('~^https?://|^/~i', $path) === 1) { return $path; }
        $base = Admin::config()->settingsUploads()['url'];
        return $base . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    /** Delete the cache file and forget loaded values (after a save, or in tests). */
    public static function forget(): void
    {
        self::$data = null;
        $file = self::cacheFile();
        if (is_file($file)) {
            @unlink($file);
            if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
        }
    }

    /** Forget loaded values only (tests, or after Admin::setRoot()). */
    public static function reset(): void
    {
        self::$data = null;
    }

    public static function cacheFile(): string
    {
        return Admin::config()->root() . '/storage/cache/flocms-admin-settings.php';
    }

    /** The settings store, or null without a database. */
    public static function store(): ?SettingsStore
    {
        $db = App::db();
        return $db === null ? null : new SettingsStore($db, Admin::config()->settingsTable());
    }

    /** Language map → one value. */
    private static function pick(array $values, ?string $lang): string
    {
        $values = array_filter(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', $values), static fn (string $v): bool => $v !== '');
        if ($values === []) { return ''; }
        $lang ??= defined('ACTIVE_LANG') ? strtolower((string) constant('ACTIVE_LANG')) : null;
        foreach ([$lang, Languages::default()] as $candidate) {
            if ($candidate !== null && isset($values[$candidate])) { return $values[$candidate]; }
        }
        return (string) reset($values);
    }

    private static function filled(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) { return false; }
        if (is_array($value)) {
            foreach ($value as $item) { if (is_scalar($item) && (string) $item !== '') { return true; } }
            return false;
        }
        return true;
    }

    /** @return array{values: array<string, mixed>, defaults: array<string, mixed>, files: array<string, true>, pages: array<string, list<string>>} */
    private static function data(): array
    {
        if (self::$data !== null) {
            return self::$data;
        }
        $empty = ['values' => [], 'defaults' => [], 'files' => [], 'pages' => []];
        try {
            if (!Admin::config()->isInstalled()) {
                return self::$data = $empty;
            }
            $stamp = self::stamp();
            $file = self::cacheFile();
            if (is_file($file)) {
                $cached = (static fn (string $__file): mixed => include $__file)($file);
                if (is_array($cached) && ($cached['format'] ?? null) === self::FORMAT && ($cached['stamp'] ?? null) === $stamp) {
                    return self::$data = array_intersect_key($cached, $empty) + $empty;
                }
            }
            return self::$data = self::build($stamp);
        } catch (Throwable) {
            // Reading settings must never break a page
            return self::$data = $empty;
        }
    }

    /** Read the table and the declared defaults; write the cache when the table could be read. */
    private static function build(string $stamp): array
    {
        $data = ['values' => [], 'defaults' => [], 'files' => [], 'pages' => []];
        foreach (Admin::settings()->fields() as $key => [$page, $field]) {
            /** @var Field $field */
            $data['pages'][$page->getName()][] = $field->getName();
            if ($field instanceof FileUpload) { $data['files'][$key] = true; continue; }
            try {
                $default = $field->stateFromRecord(null, Languages::default());
            } catch (Throwable) {
                $default = null;
            }
            if (self::filled($default)) { $data['defaults'][$key] = $default; }
        }

        $store = self::store();
        if ($store === null || !$store->exists()) {
            return $data;
        }
        $data['values'] = $store->all();
        self::write($data + ['format' => self::FORMAT, 'stamp' => $stamp]);

        return $data;
    }

    private static function write(array $data): void
    {
        $file = self::cacheFile();
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return;
        }
        $temp = $dir . '/.flocms-admin-settings-' . bin2hex(random_bytes(6)) . '.tmp';
        $code = "<?php\n// flocms-admin settings cache: rebuilt automatically, safe to delete.\nreturn " . var_export($data, true) . ";\n";
        if (@file_put_contents($temp, $code, LOCK_EX) === false) {
            return;
        }
        if (!@rename($temp, $file)) {
            @unlink($temp);
            return;
        }
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($file, true); }
    }

    /** Changes when the declarations or the table name change. */
    private static function stamp(): string
    {
        $config = Admin::config();
        $file = $config->settingsPath();
        $source = is_file($file) ? filemtime($file) . ':' . filesize($file) : '-';
        return Admin::VERSION . '|' . $config->settingsTable() . '|' . ($config->panelSettingsEnabled() ? 'p' : '-') . '|' . $source;
    }
}
