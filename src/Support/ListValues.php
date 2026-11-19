<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

/**
 * Lists of strings kept in one column (0.37): CheckboxList keys and TagsInput
 * tags, stored as a JSON list (["pool","parking"]). Plain text left in a column
 * from before ("pool, parking") is read as a list too, so a site can switch an
 * existing text column to these fields without migrating it.
 */
final class ListValues
{
    /** More items than any form keeps (a guard against huge input). */
    public const LIMIT = 500;

    /**
     * The strings of a stored or submitted list, in order, without empty ones.
     *
     * @return list<string>
     */
    public static function decode(mixed $value): array
    {
        if ($value === null || is_bool($value)) { return []; }
        if (is_array($value)) {
            $items = [];
            foreach ($value as $item) {
                if (is_scalar($item) && !is_bool($item)) { $items[] = (string) $item; }
                if (count($items) >= self::LIMIT) { break; }
            }
            return self::clean($items);
        }
        if (!is_scalar($value)) { return []; }
        $text = trim((string) $value);
        if ($text === '') { return []; }
        if ($text[0] === '[') {
            $decoded = json_decode($text, true);
            if (is_array($decoded) && array_is_list($decoded)) { return self::decode($decoded); }
        }
        return self::split($text, [',']);
    }

    /**
     * Text split on the separators and line breaks; the Arabic comma counts as
     * a comma (Arabic and Kurdish keyboards type it).
     *
     * @param list<string> $separators
     * @return list<string>
     */
    public static function split(string $text, array $separators): array
    {
        if (in_array(',', $separators, true)) { $separators[] = '،'; }
        $separators = array_merge($separators, ["\r\n", "\n", "\r"]);
        $text = str_replace($separators, "\n", $text);
        return self::clean(array_slice(explode("\n", $text), 0, self::LIMIT));
    }

    /** @param list<string> $items @return list<string> */
    private static function clean(array $items): array
    {
        $clean = [];
        foreach ($items as $item) {
            $item = trim($item);
            if ($item !== '') { $clean[] = $item; }
        }
        return $clean;
    }

    /** The JSON list for the form state and the column, or '' for an empty list. @param list<string> $items */
    public static function encode(array $items): string
    {
        $items = array_values($items);
        return $items === [] ? '' : json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Lists per language (0.44: translatable tags), {"en":[...],"ar":[...]}, as
     * an array of lists; null when the value is not such an object.
     *
     * @return array<string, list<string>>|null
     */
    public static function languageMap(mixed $value): ?array
    {
        if (is_string($value)) {
            $text = trim($value);
            if ($text === '' || $text[0] !== '{') { return null; }
            $value = json_decode($text, true);
        }
        if (!is_array($value) || $value === [] || array_is_list($value)) { return null; }
        $map = [];
        foreach ($value as $lang => $items) {
            if (!is_string($lang) || preg_match('/^[a-z]{2,3}(?:[-_][a-z0-9]{2,8})?$/iD', $lang) !== 1) { return null; }
            if (!is_array($items) && !is_string($items) && $items !== null) { return null; }
            $map[strtolower($lang)] = self::decode($items);
        }
        return $map;
    }

    /**
     * One language's items (0.44): from a per-language object the language's
     * list, else the default language's (else none); a plain list or text is
     * the same in every language.
     *
     * @return list<string>
     */
    public static function forLanguage(mixed $value, ?string $lang = null): array
    {
        $map = self::languageMap($value);
        if ($map === null) { return self::decode($value); }
        $lang ??= AdminLang::language();
        $default = \FloCMS\Admin\Translation\Languages::default();
        return ($map[$lang] ?? []) !== [] ? $map[$lang] : ($map[$default] ?? []);
    }

    /** Lower-case text for comparing tags ("Sea View" = "sea view"), in any script. */
    public static function fold(string $value): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)), 'UTF-8');
    }
}
