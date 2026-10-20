<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks;

use FloCMS\Admin\Admin;

/**
 * The kinds of blocks there are (0.29): the core ones, and the site's own
 * from config/admin.php ('blocks' => [App\Blocks\LatestPosts::class]).
 * A Builder field offers all of them, or the ones it names.
 */
final class BlockRegistry
{
    /** @var list<class-string<Block>> */
    public const CORE = [
        Core\Slider::class, Core\Hero::class, Core\Image::class, Core\Gallery::class, Core\Video::class,
        Core\Heading::class, Core\RichText::class, Core\Cards::class,
        Core\Section::class, Core\Columns::class,
        Core\CallToAction::class, Core\Html::class,
    ];

    /** @var array<string, Block>|null */
    private static ?array $all = null;

    /**
     * Every kind of block, by type.
     *
     * @return array<string, Block>
     */
    public static function all(): array
    {
        if (self::$all !== null) { return self::$all; }
        $classes = self::CORE;
        foreach (Admin::config()->blocks() as $class) { $classes[] = $class; }
        $blocks = [];
        foreach ($classes as $class) {
            $block = self::instance($class);
            $type = $block::type();
            // A site's block of the same type replaces the core one.
            $blocks[$type] = $block;
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
                $blocks[$block::type()] = $all[$block::type()] ?? $block;
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
