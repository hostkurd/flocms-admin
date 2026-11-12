<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks;

use FloCMS\Admin\Admin;

/**
 * The kinds of blocks there are (0.29): the core ones, those packages add
 * with Admin::extend() (0.43), and the site's own from config/admin.php
 * ('blocks' => [App\Blocks\LatestPosts::class]); a later one of the same type
 * replaces an earlier one. A Builder field offers all of them, or the ones it
 * names.
 */
final class BlockRegistry
{
    /** @var list<class-string<Block>> */
    public const CORE = [
        Core\Slider::class, Core\Hero::class, Core\Image::class, Core\Gallery::class, Core\Video::class,
        Core\Heading::class, Core\RichText::class, Core\Cards::class,
        Core\Section::class, Core\Columns::class,
        Core\CallToAction::class, Core\Html::class,
        // 0.34
        Core\Contact::class, Core\Faq::class, Core\Testimonials::class, Core\Stats::class, Core\Team::class, Core\Logos::class,
    ];

    /** @var array<string, Block>|null */
    private static ?array $all = null;

    /** What a block's type must look like (as Block::type() checks the static $type). */
    public const TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,40}$/D';

    /**
     * Every kind of block, by type.
     *
     * @return array<string, Block>
     */
    public static function all(): array
    {
        if (self::$all !== null) { return self::$all; }
        $blocks = [];
        foreach (self::CORE as $class) {
            $block = self::instance($class);
            $blocks[$block->blockType()] = $block;
        }
        // A package's block (Admin::extend(), 0.43) of the same type replaces the core one;
        // one that can't be used is reported (admin:doctor) instead of breaking every Builder.
        $extension = Admin::extension();
        foreach ($extension->getBlocks() as $kind) {
            $name = is_string($kind) ? $kind : get_class($kind);
            try {
                $block = $kind instanceof Block ? $kind : self::instance($kind);
                $type = $block->blockType();
                if (preg_match(self::TYPE_PATTERN, $type) !== 1) {
                    $extension->problem('block ' . $name . ': type "' . $type . '" must be lower-case letters, digits and _, starting with a letter (at most 41)');
                    continue;
                }
                $blocks[$type] = $block;
            } catch (\Throwable $error) {
                $extension->problem('block ' . $name . ': ' . $error->getMessage());
            }
        }
        // The site's own (config/admin.php) come last and win.
        foreach (Admin::config()->blocks() as $class) {
            $block = self::instance($class);
            $blocks[$block->blockType()] = $block;
        }
        return self::$all = $blocks;
    }

    /**
     * The blocks named, in their order (types or classes); all when none are named.
     *
     * @param list<string> $names
     * @return array<string, Block>
     */
    public static function only(array $names): array
    {
        $all = self::all();
        if ($names === []) { return $all; }
        $blocks = [];
        foreach ($names as $name) {
            if (isset($all[$name])) { $blocks[$name] = $all[$name]; continue; }
            if (is_a($name, Block::class, true)) {
                $block = self::instance($name);
                $blocks[$block->blockType()] = $all[$block->blockType()] ?? $block;
                continue;
            }
            throw new \InvalidArgumentException('Unknown block "' . $name . '". Use a type such as "slider" or a class extending ' . Block::class . '.');
        }
        return $blocks;
    }

    public static function find(string $type): ?Block
    {
        return self::all()[$type] ?? null;
    }

    public static function reset(): void
    {
        self::$all = null;
    }

    private static function instance(string $class): Block
    {
        if (!is_a($class, Block::class, true)) {
            throw new \InvalidArgumentException($class . ' must extend ' . Block::class . '.');
        }
        return new $class();
    }
}
