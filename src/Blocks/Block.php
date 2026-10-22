<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Layout\Tab;
use FloCMS\Admin\Support\AdminLang;

/**
 * One kind of block a Builder field offers (0.29): its fields in the
 * inspector, and what its template receives on the website.
 *
 *     final class LatestPosts extends Block
 *     {
 *         protected static string $type = 'latest_posts';
 *         protected static string $category = 'site';
 *         protected static string $icon = 'grid';
 *
 *         public function fields(): array
 *         {
 *             return [TextInput::make('heading')->required(), Select::make('count')->options(['3' => '3', '6' => '6'])->default('3')];
 *         }
 *
 *         public function data(array $fields): array
 *         {
 *             return ['posts' => Post::latest((int) $fields['count'])];
 *         }
 *     }
 *
 * fields() returns the block's fields, or Tab layouts for several inspector
 * tabs; every block also gets the shared settings (published, schedule, and in
 * the Display tab style, colours, devices, width, spacing, anchor, CSS class and
 * its own CSS). styles() and sample() (0.33) are optional. The template is
 * views/blocks/<type>.html in the site, else the package's default.
 */
abstract class Block
{
    /** Stable name in the stored JSON and of the template: lower case, digits, _ */
    protected static string $type = '';
    /** media | text | layout | marketing | site | other */
    protected static string $category = 'site';
    /** An icon name from the panel's set (Icons::svg). */
    protected static string $icon = 'square';
    /** Words or a translation key; default: the type as words. */
    protected static ?string $label = null;
    protected static ?string $description = null;

    public const CATEGORIES = ['media', 'text', 'layout', 'marketing', 'site', 'other'];

    final public static function type(): string
    {
        $type = static::$type;
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/D', $type) !== 1) {
            throw new \LogicException(static::class . ' needs a $type of lower-case letters, digits and _ (e.g. "latest_posts").');
        }
        return $type;
    }

    public static function category(): string
    {
        return in_array(static::$category, self::CATEGORIES, true) ? static::$category : 'other';
    }

    public static function icon(): string { return static::$icon; }

    public static function label(): string
    {
        return static::$label !== null ? self::words(static::$label) : ucfirst(str_replace('_', ' ', static::type()));
    }

    public static function description(): string
    {
        return static::$description !== null ? self::words(static::$description) : '';
    }

    /** The panel's own words for the core blocks ("blocks.slider"), else the site's (or the text itself). */
    protected static function words(string $text): string
    {
        return str_starts_with($text, 'blocks.') ? AdminLang::get($text) : AdminLang::label($text);
    }

    /**
     * The block's fields (or Tab layouts). Field names must be unique within
     * the block and other than the shared settings' names.
     *
     * @return list<Field|Tab>
     */
    abstract public function fields(): array;

    /**
     * What the template receives besides the stored fields: extra data read
     * when the page is shown (posts, projects). Default: nothing.
     *
     * @param array<string, mixed> $fields the stored values
     * @return array<string, mixed>
     */
    public function data(array $fields): array
    {
        return [];
    }

    /**
     * The block's name in the structure ("Home slider"), from its fields.
     * Default: the first text field with a value, else the kind's label.
     *
     * @param array<string, mixed> $fields state values
     */
    public function title(array $fields): string
    {
        foreach (['title', 'heading', 'name', 'label', 'text', 'body', 'caption'] as $name) {
            $value = $fields[$name] ?? null;
            if (is_string($value) && ($text = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'))) !== '') {
                return mb_strimwidth(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 80, '…');
            }
        }
        return static::label();
    }

    /**
     * The block's state on its way into the inspector (the HTML block adds a signature).
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function prepareState(array $state, string $id = ''): array { return $state; }

    /**
     * The submitted state of the block, before it is validated and stored
     * (the HTML block keeps a locked copy unchanged).
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function acceptInput(array $state, string $id = ''): array { return $state; }

    /**
     * A field of the block's own that names it for editors (0.31): shown in the
     * block panel in place of the panel's own Name (Section, Columns and Slider
     * return 'name'). null: the panel's Name.
     */
    public function nameField(): ?string { return null; }

    /**
     * Checks that need more than one field (0.32), after each field's own rules:
     * field name (or "list.<index>.<field>" inside a list) => message. The fields
     * are as the editor keeps them (lists as JSON).
     *
     * @param array<string, string> $fields
     * @return array<string, string>
     */
    public function check(array $fields): array { return []; }

    /**
     * A button's text and its link need each other: an error on the one that is missing.
     *
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    protected static function buttonPair(array $values, string $button, string $link, string $prefix = ''): array
    {
        $text = trim((string) ($values[$button] ?? ''));
        $url = trim((string) ($values[$link] ?? ''));
        if ($text !== '' && $url === '') { return [$prefix . $link => \FloCMS\Admin\Support\AdminLang::get('blocks.needs_link')]; }
        if ($text === '' && $url !== '') { return [$prefix . $button => \FloCMS\Admin\Support\AdminLang::get('blocks.needs_button')]; }
        return [];
    }

    /**
     * The block's own styles (0.33), name => label, besides those in
     * config/admin.php ('block_styles') and BlockStyles::add(). The website
     * gets the class is-style-<name>.
     *
     * @return array<string, string|array<string, string>>
     */
    public function styles(): array { return []; }

    /**
     * Content for the blocks preview (0.33): the block's fields as stored,
     * e.g. ['heading' => 'What we do']. [] (the default): the preview says
     * "No sample yet". Layout blocks may add 'children' => [[type, data], …].
     *
     * @return array<string, mixed>
     */
    public function sample(): array
    {
        // The core blocks' samples (BlockSamples) use the newest images of the media library.
        return str_starts_with(static::class, __NAMESPACE__ . '\\Core\\') ? BlockSamples::for(static::type()) : [];
    }

    /** Layout blocks hold other blocks (Section, Columns). */
    public function holdsBlocks(): bool { return false; }

    /** How many columns a layout block has for these fields (Columns); 1 otherwise. */
    public function columnCount(array $fields): int { return 1; }

    /** Script and style names the website needs for this block ("slider"). */
    public function assets(): array { return []; }

    /** The width a new block of this kind gets: 'boxed' (in the site's container) or 'full'. */
    public function defaultWidth(): string { return 'boxed'; }

    /**
     * Whether the printed HTML may be cached until the content changes. True
     * unless the block has its own data() (which may read the database, as
     * "latest posts" does); override to say so either way.
     */
    public function cacheable(): bool
    {
        return (new \ReflectionMethod($this, 'data'))->getDeclaringClass()->getName() === self::class || str_starts_with(static::class, __NAMESPACE__ . '\\Core\\');
    }

    /** Whether this block may sit inside a block of the given type ('' = the top level). */
    public function allowedIn(string $parentType): bool
    {
        return $parentType === '' || $parentType === 'section' || $parentType === 'columns';
    }

    /**
     * The HTML of a block that builds its markup in PHP instead of a template.
     * null = use the template (the default).
     *
     * @param array<string, mixed> $vars the template's variables
     */
    public function render(array $vars): ?string
    {
        return null;
    }
}
