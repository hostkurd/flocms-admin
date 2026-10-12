<?php
declare(strict_types=1);

namespace FloCMS\Admin\Translation;

use FloCMS\Core\Config;
use FloCMS\Core\Lang;

/**
 * The site's content languages: Config 'languages' (LANGUAGES in .env) and
 * Config 'default_language' (DEFAULT_LANG), exactly as the skeleton sets them.
 */
final class Languages
{
    /** Language codes accepted by the admin: "en", "el", "ckb", "pt-br". */
    public const CODE_PATTERN = '/^[a-z]{2,3}(-[a-z0-9]{2,8})?$/';

    /** Written right to left unless the admin config says otherwise. */
    public const DEFAULT_RTL = ['ar', 'ku', 'ckb', 'fa', 'he', 'ur'];

    /** Names in their own language, used when the site's lang file has no lng.<code> key. */
    private const ENDONYMS = [
        'ar' => 'العربية', 'ckb' => 'کوردی', 'de' => 'Deutsch', 'el' => 'Ελληνικά',
        'en' => 'English', 'es' => 'Español', 'fa' => 'فارسی', 'fr' => 'Français',
        'he' => 'עברית', 'it' => 'Italiano', 'ku' => 'کوردی', 'nl' => 'Nederlands',
        'pt' => 'Português', 'ru' => 'Русский', 'sv' => 'Svenska', 'tr' => 'Türkçe',
        'ur' => 'اردو',
    ];

    /** @var list<string>|null */
    private static ?array $languages = null;
    private static ?string $default = null;
    /** @var list<string>|null */
    private static ?array $rtl = null;

    /**
     * Content languages, default language first.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        if (self::$languages !== null) {
            return self::$languages;
        }

        $configured = Config::get('languages');
        $codes = [];
        foreach (is_array($configured) ? $configured : [] as $code) {
            $code = strtolower(trim((string) $code));
            if (preg_match(self::CODE_PATTERN, $code) === 1 && !in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        $default = self::default();
        $codes = array_values(array_diff($codes, [$default]));
        array_unshift($codes, $default);

        return self::$languages = $codes;
    }

    public static function default(): string
    {
        if (self::$default !== null) {
            return self::$default;
        }

        $default = strtolower(trim((string) Config::get('default_language', '')));
        if (preg_match(self::CODE_PATTERN, $default) !== 1) {
            $configured = Config::get('languages');
            $first = is_array($configured) && $configured !== [] ? strtolower(trim((string) reset($configured))) : '';
            $default = preg_match(self::CODE_PATTERN, $first) === 1 ? $first : 'en';
        }

        return self::$default = $default;
    }

    public static function isValid(string $code): bool
    {
        return preg_match(self::CODE_PATTERN, $code) === 1;
    }

    public static function isRtl(string $code): bool
    {
        return in_array($code, self::$rtl ?? self::DEFAULT_RTL, true);
    }

    public static function direction(string $code): string
    {
        return self::isRtl($code) ? 'rtl' : 'ltr';
    }

    /** Display name: the site's lng.<code> key, else the name in its own language, else the code. */
    public static function name(string $code): string
    {
        $name = Lang::get('lng.' . $code, '');
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return self::ENDONYMS[$code] ?? strtoupper($code);
    }

    /** @param list<string> $codes */
    public static function setRtl(array $codes): void
    {
        self::$rtl = array_values(array_map(static fn ($c): string => strtolower((string) $c), $codes));
    }

    /** Forget cached values (tests, or after Config changed). */
    public static function reset(): void
    {
        self::$languages = null;
        self::$default = null;
        self::$rtl = null;
    }
}
