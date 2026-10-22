<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Support\AdminLang;

/**
 * Named styles for blocks (0.33): a developer lists them, an editor picks one
 * in the block's Display tab, and the website gets the class is-style-<name>
 * on the block (".flo-block-cards.is-style-shadow").
 *
 * In config/admin.php, per block type, '*' for every kind:
 *
 *     'block_styles' => [
 *         'cards' => ['shadow' => 'With shadow', 'plain' => 'Plain'],
 *         '*'     => ['highlight' => ['en' => 'Highlighted', 'ar' => 'مميّز']],
 *     ],
 *
 * or from PHP (a package, a service provider): BlockStyles::add('cards', 'shadow', 'With shadow');
 * a site block can also return its own from Block::styles(). A label is words,
 * a translation key, or one text per language.
 */
final class BlockStyles
{
    /** @var array<string, array<string, string|array<string, string>>> type or '*' => name => label */
    private static array $added = [];

    /** A style name: lower case, digits and -, as it goes into the class name. */
    public static function validName(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[a-z][a-z0-9-]{0,30}$/D', $name) === 1;
    }

    /** @param string|array<string, string> $label */
    public static function add(string $type, string $name, string|array $label): void
    {
        if ($type !== '*' && preg_match('/^[a-z][a-z0-9_]{0,40}$/D', $type) !== 1) {
            throw new \InvalidArgumentException('A block style needs a block type such as "cards", or "*" for every kind.');
        }
        if (!self::validName($name)) {
            throw new \InvalidArgumentException('Block style "' . $name . '": use lower-case letters, digits and - (e.g. "with-shadow").');
        }
        self::$added[$type][$name] = $label;
    }

    /** @internal tests */
    public static function reset(): void
    {
        self::$added = [];
    }

    /**
     * The styles a block may have, name => label in the panel's language:
     * the ones for every kind first, then the kind's own.
     *
     * @return array<string, string>
     */
    public static function for(Block $block): array
    {
        $type = $block::type();
        $styles = [];
        foreach ([self::configured('*'), self::$added['*'] ?? [], self::configured($type), self::$added[$type] ?? [], $block->styles()] as $list) {
            foreach ($list as $name => $label) {
                if (self::validName($name) && (is_string($label) || is_array($label))) { $styles[(string) $name] = self::label($label, (string) $name); }
            }
        }
        return $styles;
    }

    /**
     * The 'block_styles' entries that can't be used, for admin:doctor.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $value = Admin::config()->blockStyles();
        if (!is_array($value)) { return ['block_styles must be an array of block type => [name => label].']; }
        $problems = [];
        foreach ($value as $type => $styles) {
            if (!is_string($type) || ($type !== '*' && BlockRegistry::find($type) === null)) { $problems[] = 'block_styles: "' . $type . '" is not a block type (or "*").'; continue; }
            if (!is_array($styles)) { $problems[] = 'block_styles.' . $type . ' must be an array of name => label.'; continue; }
            foreach ($styles as $name => $label) {
                if (!self::validName($name)) { $problems[] = 'block_styles.' . $type . ': "' . $name . '" is not a valid style name (lower-case letters, digits and -).'; }
                elseif (!is_string($label) && !is_array($label)) { $problems[] = 'block_styles.' . $type . '.' . $name . ' needs a label.'; }
            }
        }
        return $problems;
    }

    /** @return array<mixed> */
    private static function configured(string $type): array
    {
        $value = Admin::config()->blockStyles();
        $list = is_array($value) ? ($value[$type] ?? []) : [];
        return is_array($list) ? $list : [];
    }

    /** @param string|array<string, string> $label */
    public static function label(string|array $label, string $fallback): string
    {
        if (is_array($label)) {
            $lang = AdminLang::language();
            foreach ([$lang, \FloCMS\Admin\Translation\Languages::default(), 'en'] as $key) {
                if (is_string($label[$key] ?? null) && $label[$key] !== '') { return $label[$key]; }
            }
            $first = array_values(array_filter($label, static fn ($text): bool => is_string($text) && $text !== ''))[0] ?? null;
            return is_string($first) ? $first : $fallback;
        }
        return str_starts_with($label, 'blocks.') ? AdminLang::get($label) : AdminLang::label($label);
    }
}
