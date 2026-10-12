<?php
declare(strict_types=1);

namespace FloCMS\Admin\Translation;

/**
 * Reading translatable values on the website.
 *
 *     Translations::get($property->title)        // current language (ACTIVE_LANG)
 *     Translations::get($property->title, 'el')  // a given language
 *     Translations::all($property->title)        // ['en' => ..., 'ar' => ...]
 */
final class Translations
{
    private static ?TranslationStore $store = null;

    /**
     * The value for $lang (default: the current language), else the default
     * language, else the first filled language, else ''.
     */
    public static function get(mixed $value, ?string $lang = null): string
    {
        $values = self::all($value);
        if ($values === []) {
            return '';
        }

        $lang ??= self::currentLanguage();

        foreach ([$lang, Languages::default()] as $candidate) {
            if ($candidate !== null && isset($values[$candidate])) {
                return $values[$candidate];
            }
        }

        return (string) reset($values);
    }

    /** @return array<string, string> */
    public static function all(mixed $value): array
    {
        return self::store()->decode($value, Languages::default());
    }

    public static function store(): TranslationStore
    {
        return self::$store ??= new JsonColumnStore();
    }

    public static function useStore(?TranslationStore $store): void
    {
        self::$store = $store;
    }

    private static function currentLanguage(): ?string
    {
        if (defined('ACTIVE_LANG')) {
            $lang = strtolower((string) constant('ACTIVE_LANG'));
            return $lang === '' ? null : $lang;
        }

        return null;
    }
}
