<?php
declare(strict_types=1);

namespace FloCMS\Admin\Translation;

use FloCMS\Admin\Preferences\Preferences;
use Throwable;

/**
 * Which language a page-level translation form opens in (0.23): the one asked
 * for (?editing=ar, or the form's own _editing after a submit), else the last
 * one the user saved in (remembered per user), else the default language. A
 * new record starts in the default language; after a failed save the form
 * opens on the language of the first error.
 */
final class EditingLanguage
{
    public const PREFERENCE = 'editing_language';

    /** The code when it is one of the site's languages, else null. */
    public static function valid(mixed $lang): ?string
    {
        if (!is_string($lang)) { return null; }
        $lang = strtolower(trim($lang));
        return in_array($lang, Languages::all(), true) ? $lang : null;
    }

    /** $requested when valid; else the remembered language for an existing record, the default for a new one. */
    public static function resolve(mixed $requested, bool $existing): string
    {
        return self::valid($requested) ?? ($existing ? self::remembered() : Languages::default());
    }

    public static function remembered(): string
    {
        try {
            return self::valid(Preferences::current()?->get(self::PREFERENCE)) ?? Languages::default();
        } catch (Throwable) {
            return Languages::default();
        }
    }

    /** Remember the language a record was saved in (the default language clears the preference). */
    public static function remember(string $lang): void
    {
        $lang = self::valid($lang);
        if ($lang === null) { return; }
        try {
            $preferences = Preferences::current();
            if ($preferences !== null && $preferences->get(self::PREFERENCE) !== ($lang === Languages::default() ? null : $lang)) {
                $preferences->set(self::PREFERENCE, $lang === Languages::default() ? null : $lang);
            }
        } catch (Throwable) {
            // Preferences are a convenience: a failure never stops a save.
        }
    }

    /**
     * After a failed save: the language being edited when it has an error,
     * else the default language when it has one, else the first language with one.
     *
     * @param array<string, mixed> $errors keyed "title" (default language) or "title.ar"
     */
    public static function forErrors(array $errors, string $editing): string
    {
        $languages = [];
        foreach (array_keys($errors) as $key) {
            if (str_starts_with((string) $key, '_')) { continue; }
            $parts = explode('.', (string) $key, 2);
            $languages[] = isset($parts[1]) && in_array($parts[1], Languages::all(), true) ? $parts[1] : Languages::default();
        }
        if ($languages === [] || in_array($editing, $languages, true)) {
            return $editing;
        }
        return in_array(Languages::default(), $languages, true) ? Languages::default() : $languages[0];
    }
}
