<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

use FloCMS\Core\Lang;

/**
 * The panel's own words ("Create", "Search", validation messages).
 *
 * Looked up as "admin.<key>" in the site's lang/<code>.php first, so a site can
 * change any of them, then in this package's resources/lang/<code>.php, then
 * in English.
 */
final class AdminLang
{
    /** @var array<string, array<string, string>> */
    private static array $files = [];
    private static ?string $language = null;

    /** @param array<string, string|int|float> $replace */
    public static function get(string $key, array $replace = []): string
    {
        $line = self::site('admin.' . $key);

        if ($line === null) {
            $lang = self::language();
            $line = self::file($lang)[$key] ?? self::file('en')[$key] ?? $key;
        }

        return $replace === [] ? $line : Lang::replace($line, array_map('strval', $replace));
    }

    /**
     * A label written by a developer: a key of the site's lang file
     * ("admin.properties") is translated, any other text is shown as written.
     */
    public static function label(string $text): string
    {
        return self::site($text) ?? $text;
    }

    /** The admin interface language (the language segment of the URL). */
    public static function language(): string
    {
        if (self::$language !== null) {
            return self::$language;
        }

        $lang = defined('ACTIVE_LANG') ? strtolower((string) constant('ACTIVE_LANG')) : 'en';

        return preg_match('/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/', $lang) === 1 ? $lang : 'en';
    }

    public static function setLanguage(?string $lang): void
    {
        self::$language = $lang;
    }

    public static function reset(): void
    {
        self::$files = [];
        self::$language = null;
    }

    private static function site(string $key): ?string
    {
        if ($key === '' || preg_match('/\s/', $key) === 1) {
            return null;
        }

        $value = Lang::get($key, '');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array<string, string> */
    private static function file(string $lang): array
    {
        if (!isset(self::$files[$lang])) {
            $path = dirname(__DIR__, 2) . '/resources/lang/' . $lang . '.php';
            $data = is_file($path) ? require $path : [];
            self::$files[$lang] = is_array($data) ? $data : [];
        }

        return self::$files[$lang];
    }
}
