<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

/**
 * Numbers as the list shows them (0.38): thousands and decimal separators of
 * the panel language (lang keys numbers.thousands / numbers.decimal), Western
 * digits, an optional prefix and suffix such as a currency.
 *
 * @internal
 */
final class Numbers
{
    /**
     * Whether a stored value is a number (int, float or a numeric string).
     */
    public static function isNumber(mixed $value): bool
    {
        return is_int($value) || is_float($value) || (is_string($value) && is_numeric(trim($value)));
    }

    /**
     * A database aggregate (MySQL returns decimals as strings, SQLite floats)
     * as an int or a float; null stays null.
     */
    public static function normalize(mixed $value): int|float|null
    {
        if ($value === null || $value === '' || !self::isNumber($value)) { return null; }
        if (is_int($value)) { return $value; }
        $float = (float) $value;
        if (is_string($value) && preg_match('/^-?\d+$/D', trim($value)) === 1 && abs($float) < 9.0E15) { return (int) $value; }
        return $float;
    }

    /**
     * "1,250,000" or "1,250,000.50". $decimals null: as many as the value has
     * (at most 6, trailing zeros of a stored decimal like 450000.00 kept).
     */
    public static function format(int|float|string $value, ?int $decimals = null, string $prefix = '', string $suffix = ''): string
    {
        if (!self::isNumber($value)) { return $prefix . (string) $value . $suffix; }
        if ($decimals === null) {
            $text = is_string($value) ? trim($value) : (is_float($value) ? rtrim(rtrim(sprintf('%.6F', $value), '0'), '.') : (string) $value);
            $decimals = str_contains($text, '.') ? min(6, strlen(substr($text, strpos($text, '.') + 1))) : 0;
        }
        $decimals = max(0, min(6, $decimals));
        $point = AdminLang::get('numbers.decimal');
        $thousands = AdminLang::get('numbers.thousands');
        $number = number_format((float) $value, $decimals, $point === 'numbers.decimal' ? '.' : $point, $thousands === 'numbers.thousands' ? ',' : $thousands);
        if ($number === '-0' || preg_match('/^-0([.,]0+)?$/D', $number) === 1) { $number = ltrim($number, '-'); }
        return $prefix . $number . $suffix;
    }
}
