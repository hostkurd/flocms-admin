<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

/** @internal Checks the items of the list widgets (0.42). */
final class Items
{
    public const COLORS = ['primary', 'success', 'warning', 'danger', 'neutral', 'chart1', 'chart2', 'chart3', 'chart4', 'chart5', 'chart6'];

    /**
     * @param list<string> $required
     * @param list<string> $optional
     * @return list<array<string, mixed>>
     */
    public static function check(mixed $items, int $max, array $required, array $optional): array
    {
        if (!is_array($items) || !array_is_list($items) || count($items) > $max) { throw new \InvalidArgumentException('Use a list of at most ' . $max . ' items.'); }
        foreach ($items as $index => $item) {
            if (!is_array($item)) { throw new \InvalidArgumentException('Each item is an array.'); }
            foreach ($required as $key) { if (!array_key_exists($key, $item)) { throw new \InvalidArgumentException('Each item needs ' . $key . '.'); } }
            foreach ($item as $key => $value) {
                if (!in_array($key, $required, true) && !in_array($key, $optional, true)) { throw new \InvalidArgumentException('Unknown item key: ' . $key); }
                if ($key === 'value') {
                    if (is_string($value)) { if (strlen($value) > 32) { throw new \InvalidArgumentException('A text value is at most 32 bytes.'); } }
                    elseif ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 1e15) { throw new \InvalidArgumentException('Use finite values within ±1e15.'); }
                } elseif ($key === 'color') {
                    if (!in_array($value, self::COLORS, true)) { throw new \InvalidArgumentException('Unknown color: ' . (is_string($value) ? $value : get_debug_type($value))); }
                } elseif ($key === 'url') {
                    if (!is_string($value) || !Dashboard::safeUrl($value)) { throw new \InvalidArgumentException('Item links go to pages of this site.'); }
                } elseif (is_int($value) && $key !== 'icon') {
                    // A number as a label, e.g. a key of countBy('bedrooms'): PHP makes "3" an integer key
                    $items[$index][$key] = (string) $value;
                } elseif (!is_string($value) || strlen($value) > 500) {
                    throw new \InvalidArgumentException($key . ' must be a short text.');
                }
            }
        }
        return $items;
    }

    /** A site lang key or plain text; the panel's own "widgets.*" words as a fallback. */
    public static function text(string $text): string
    {
        $label = \FloCMS\Admin\Support\AdminLang::label($text);
        return $label === $text && str_starts_with($text, 'widgets.') ? \FloCMS\Admin\Support\AdminLang::get($text) : $label;
    }

    public static function colorClass(string $color): string { return 'fa-tone-' . $color; }

    public static function number(int|float $value): string { return number_format($value, floor((float) $value) === (float) $value ? 0 : 1); }
}
