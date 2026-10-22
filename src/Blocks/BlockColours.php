<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Support\AdminLang;

/**
 * The theme's colours for block backgrounds (0.33): editors pick a swatch,
 * never a colour code, and the website gets has-bg-<name> (and has-text-light
 * or has-text-dark) on the block.
 *
 *     'block_colours' => [
 *         'brand' => ['label' => 'Brand', 'value' => '#0e6e8c', 'text' => 'light'],
 *         'sand'  => ['label' => 'Sand',  'value' => '#f4ede1', 'text' => 'dark'],
 *     ],
 *
 * Without 'block_colours' the palette is the four colours blocks.css styles
 * with its variables (--flo-brand, --flo-light, --flo-dark, --flo-muted). With
 * it, the panel prints each colour's rule in the page's block stylesheet
 * (BlockCss), so a theme only needs its own CSS for anything more.
 */
final class BlockColours
{
    /** Names a colour may not have (they mean something else to a Section). */
    public const RESERVED = ['none', 'colour', 'image', 'auto'];

    /** The four colours of blocks.css, named as a Section has always named them. */
    private const DEFAULTS = [
        'brand' => ['label' => 'blocks.bg_brand', 'value' => '#0b6e91', 'text' => 'light'],
        'light' => ['label' => 'blocks.bg_light', 'value' => '#f4f6f8', 'text' => 'dark'],
        'dark' => ['label' => 'blocks.bg_dark', 'value' => '#101828', 'text' => 'light'],
        'muted' => ['label' => 'blocks.bg_muted', 'value' => '#667085', 'text' => 'light'],
    ];

    /** Whether config/admin.php lists the colours (then the panel prints their rules). */
    public static function configured(): bool
    {
        return is_array(Admin::config()->blockColours()) && self::fromConfig() !== [];
    }

    /**
     * name => ['label' => words in the panel's language, 'value' => '#rrggbb', 'text' => 'light'|'dark'].
     *
     * @return array<string, array{label: string, value: string, text: string}>
     */
    public static function all(): array
    {
        $list = self::configured() ? self::fromConfig() : self::DEFAULTS;
        $out = [];
        foreach ($list as $name => $colour) {
            $label = $colour['label'];
            $out[$name] = ['label' => BlockStyles::label($label, ucfirst($name)), 'value' => $colour['value'], 'text' => $colour['text']];
        }
        return $out;
    }

    /** @return array{label: string, value: string, text: string}|null */
    public static function find(string $name): ?array
    {
        return self::all()[$name] ?? null;
    }

    /** @return array<string, array{label: string|array<string, string>, value: string, text: string}> */
    private static function fromConfig(): array
    {
        $value = Admin::config()->blockColours();
        $out = [];
        foreach (is_array($value) ? $value : [] as $name => $colour) {
            if (!self::validName($name) || !is_array($colour)) { continue; }
            $hex = self::hex($colour['value'] ?? null);
            if ($hex === null) { continue; }
            $text = in_array($colour['text'] ?? null, ['light', 'dark'], true) ? (string) $colour['text'] : self::textFor($hex);
            $label = $colour['label'] ?? ucfirst((string) $name);
            $out[(string) $name] = ['label' => is_string($label) || is_array($label) ? $label : ucfirst((string) $name), 'value' => $hex, 'text' => $text];
        }
        return $out;
    }

    public static function validName(mixed $name): bool
    {
        return BlockStyles::validName($name) && !in_array($name, self::RESERVED, true);
    }

    /** "#abc" or "#aabbcc" (lower case), else null. */
    public static function hex(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/D', trim($value)) === 1 ? strtolower(trim($value)) : null;
    }

    /** Light text on dark colours, dark text on light ones. */
    public static function textFor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) { $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2]; }
        [$r, $g, $b] = array_map(static fn (string $part): float => hexdec($part) / 255, str_split($hex, 2));
        $linear = static fn (float $c): float => $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        $luminance = 0.2126 * $linear($r) + 0.7152 * $linear($g) + 0.0722 * $linear($b);
        return $luminance > 0.36 ? 'dark' : 'light';
    }

    /**
     * The 'block_colours' entries that can't be used, for admin:doctor.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $value = Admin::config()->blockColours();
        if ($value === null) { return []; }
        if (!is_array($value)) { return ['block_colours must be an array of name => [label, value, text].']; }
        $problems = [];
        foreach ($value as $name => $colour) {
            if (!self::validName($name)) { $problems[] = 'block_colours: "' . $name . '" is not a valid colour name (lower-case letters, digits and -; not none, colour, image or auto).'; continue; }
            if (!is_array($colour) || self::hex($colour['value'] ?? null) === null) { $problems[] = 'block_colours.' . $name . ' needs a value such as "#0e6e8c".'; }
        }
        return $problems;
    }

    /** The label of a colour for a select: its name in the panel's words. */
    public static function label(string $name): string
    {
        return self::find($name)['label'] ?? AdminLang::get('blocks.bg_' . $name);
    }
}
