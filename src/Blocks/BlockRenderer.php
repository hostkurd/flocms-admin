<?php
declare(strict_types=1);

namespace FloCMS\Admin\Blocks;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Blocks\Core\Columns;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Core\TemplateEngine;

/**
 * Prints a record's blocks on the website (0.29) with the site's own
 * templates, views/blocks/<type>.html, or the package's default ones
 * (resources/blocks). See blocks() in src/helpers.php.
 *
 * Only blocks that are published and in their time window are printed. Each
 * block is wrapped in <div class="flo-block flo-block-<type> …"> carrying its
 * shared settings (width, spacing, screens, anchor, classes); a template
 * prints the inside, with the block's fields as variables and $block for the
 * rest. Layout blocks print their children: $content (Section) or $columns
 * (Columns).
 *
 * The printed HTML of a list is cached (storage/cache/blocks) until its
 * content, a template or the language changes, or a block's time window
 * opens or closes; a site block whose data() reads the database is never
 * cached unless it says so (Block::cacheable()).
 */
final class BlockRenderer
{
    public const MAX_DEPTH = 4;
    public const CACHE_SECONDS = 21600;

    /** @var array<string, true> the kinds printed on this page, for blocks_assets() */
    private static array $printed = [];
    /** @var list<\Closure(array<string, mixed>, Block): ?array<string, mixed>> */
    private static array $listeners = [];
    private static int $now = 0;

    /**
     * Before each block is printed: fn (array $vars, Block $block): ?array returns changed
     * variables (or null to keep them). For sites that add data or count blocks.
     */
    public static function rendering(\Closure $listener): void
    {
        self::$listeners[] = $listener;
    }

    /** @internal tests: the clock, and a fresh page */
    public static function reset(?int $now = null): void
    {
        self::$printed = [];
        self::$listeners = [];
        self::$now = $now ?? 0;
    }

    /** The page's language (ACTIVE_LANG), else the default one. */
    public static function language(): string
    {
        $lang = defined('ACTIVE_LANG') ? strtolower((string) constant('ACTIVE_LANG')) : '';
        return $lang !== '' ? $lang : Languages::default();
    }

    private static function now(): int
    {
        return self::$now > 0 ? self::$now : time();
    }

    /**
     * The blocks of a stored value in a language: a list, or one list per
     * language ({"en": […], "ar": […]}), where a language with no blocks
     * shows the default language's.
     *
     * @return list<array<string, mixed>>
     */
    public static function nodes(mixed $value, ?string $lang = null): array
    {
        if (is_string($value)) { $value = trim($value) === '' ? [] : json_decode($value, true); }
        if (!is_array($value) || $value === []) { return []; }
        if (array_is_list($value)) { return array_values(array_filter($value, 'is_array')); }
        $lang ??= self::language();
        foreach ([$lang, Languages::default(), '_'] as $key) {
            if (is_array($value[$key] ?? null) && $value[$key] !== []) { return array_values(array_filter($value[$key], 'is_array')); }
        }
        return [];
    }

    /** The HTML of the blocks shown now. */
    public static function render(mixed $value, ?string $lang = null): string
    {
        $lang ??= self::language();
        $nodes = self::nodes($value, $lang);
        if ($nodes === []) { return ''; }
        // Listeners may add data for this visitor: their pages are never cached.
        $cache = self::$listeners === [] ? self::cacheFile($nodes, $lang) : null;
        if ($cache !== null && is_file($cache)) {
            $cached = json_decode((string) @file_get_contents($cache), true);
            if (is_array($cached) && (int) ($cached['until'] ?? 0) > self::now() && is_string($cached['html'] ?? null)) {
                foreach ((array) ($cached['types'] ?? []) as $type) { self::$printed[(string) $type] = true; }
                return $cached['html'];
            }
        }
        $types = [];
        $cacheable = true;
        $html = self::list($nodes, $lang, 0, $types, $cacheable);
        foreach (array_keys($types) as $type) { self::$printed[$type] = true; }
        if ($cache !== null && $cacheable) {
            // At most a few hours, so a changed image or alt text in the library shows the same day.
            $until = min(self::nextChange($nodes) ?? PHP_INT_MAX, self::now() + self::CACHE_SECONDS);
            @file_put_contents($cache, json_encode(['until' => $until, 'types' => array_keys($types), 'html' => $html], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
            // Now and then, files of old versions of pages go.
            if (random_int(1, 50) === 1) {
                foreach (glob(dirname($cache) . '/*.json') ?: [] as $old) { if ((int) @filemtime($old) < time() - self::CACHE_SECONDS * 2) { @unlink($old); } }
            }
        }
        return $html;
    }

    /**
     * The blocks shown now as plain data (a site that renders them in
     * JavaScript, or an API): type, id, fields, settings, children.
     *
     * @return list<array<string, mixed>>
     */
    public static function data(mixed $value, ?string $lang = null): array
    {
        $out = [];
        foreach (self::nodes($value, $lang) as $node) {
            if (!self::visible($node)) { continue; }
            $block = BlockRegistry::find((string) ($node['type'] ?? ''));
            $fields = is_array($node['data'] ?? null) ? $node['data'] : [];
            $fields = $block !== null ? self::withDefaults($block, $fields) : self::safeLinks($fields);
            $extra = $block !== null ? $block->data($fields) : [];
            array_walk_recursive($extra, static function (mixed &$item): void { if (is_object($item) && method_exists($item, 'toArray')) { $item = $item->toArray(); } });
            $entry = ['id' => (string) ($node['id'] ?? ''), 'type' => (string) ($node['type'] ?? ''), 'fields' => $extra + $fields,
                'settings' => self::publicSettings($node['settings'] ?? null)];
            if ((int) ($node['column'] ?? 0) > 0) { $entry['column'] = (int) $node['column']; }
            if (is_array($node['children'] ?? null)) { $entry['children'] = self::data($node['children'], $lang); }
            $out[] = $entry;
        }
        return $out;
    }

    /** Whether a block is published and in its time window now. */
    public static function visible(array $node): bool
    {
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
        if (($settings['status'] ?? 'published') === 'draft') { return false; }
        $now = self::now();
        $starts = self::time($settings['starts'] ?? null);
        $ends = self::time($settings['ends'] ?? null);
        return ($starts === null || $starts <= $now) && ($ends === null || $ends > $now);
    }

    private static function time(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') { return null; }
        $time = strtotime($value);
        return $time === false ? null : $time;
    }

    /** When the next block appears or goes (a start or end in the future), or null. */
    private static function nextChange(array $nodes): ?int
    {
        $next = null;
        foreach ($nodes as $node) {
            $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
            foreach (['starts', 'ends'] as $key) {
                $time = self::time($settings[$key] ?? null);
                if ($time !== null && $time > self::now()) { $next = $next === null ? $time : min($next, $time); }
            }
            if (is_array($node['children'] ?? null) && ($child = self::nextChange($node['children'])) !== null) { $next = $next === null ? $child : min($next, $child); }
        }
        return $next;
    }

    /** @param array<string, true> $types */
    private static function list(array $nodes, string $lang, int $depth, array &$types, bool &$cacheable): string
    {
        if ($depth >= self::MAX_DEPTH) { return ''; }
        $html = '';
        foreach ($nodes as $node) {
            if (!is_array($node) || !self::visible($node)) { continue; }
            $html .= self::block($node, $lang, $depth, $types, $cacheable);
        }
        return $html;
    }

    /** @param array<string, true> $types */
    private static function block(array $node, string $lang, int $depth, array &$types, bool &$cacheable): string
    {
        $type = (string) ($node['type'] ?? '');
        $block = BlockRegistry::find($type);
        if ($block === null) { return ''; }
        $types[$type] = true;
        if (!$block->cacheable()) { $cacheable = false; }
        $fields = is_array($node['data'] ?? null) ? $node['data'] : [];
        $settings = is_array($node['settings'] ?? null) ? $node['settings'] : [];
        $children = is_array($node['children'] ?? null) ? $node['children'] : [];
        $fields = self::withDefaults($block, $fields);
        $vars = $block->data($fields) + $fields;
        $info = [
            'id' => (string) ($node['id'] ?? ''), 'type' => $type, 'settings' => self::publicSettings($settings), 'lang' => $lang,
            'anchor' => BlockSettings::anchor((string) ($settings['anchor'] ?? '')),
        ];
        if ($block instanceof Columns) {
            $shares = Columns::shares((string) ($fields['layout'] ?? '50-50'));
            $columns = [];
            foreach ($shares as $index => $share) {
                $in = array_values(array_filter($children, static fn ($kid): bool => is_array($kid) && min((int) ($kid['column'] ?? 0), count($shares) - 1) === $index));
                $columns[] = ['share' => $share, 'html' => self::list($in, $lang, $depth + 1, $types, $cacheable)];
            }
            $vars['columns'] = $columns;
        } elseif ($block->holdsBlocks()) {
            $vars['content'] = self::list($children, $lang, $depth + 1, $types, $cacheable);
        }
        $vars['block'] = (object) $info;
        foreach (self::$listeners as $listener) {
            $changed = $listener($vars, $block);
            if (is_array($changed)) { $vars = $changed; }
        }
        $inner = $block->render($vars) ?? self::template($type, $vars);
        if (trim($inner) === '') { return ''; }
        return '<div' . self::wrapper($block, $type, $settings, $info['anchor']) . '>' . $inner . '</div>' . "\n";
    }

    /** @var array<string, array<string, mixed>> */
    private static array $defaults = [];

    /** A block's settings for the website: without the editors' own (_label, _note). */
    private static function publicSettings(mixed $settings): array
    {
        if (!is_array($settings)) { return []; }
        return array_filter($settings, static fn (mixed $key): bool => !is_string($key) || !str_starts_with($key, '_'), ARRAY_FILTER_USE_KEY);
    }

    /**
     * A block's stored fields with every field present: missing ones get their
     * default (as stored), so templates can use $heading without checks; toggles
     * and numbers become strings ("1", "0").
     *
     * @return array<string, mixed>
     */
    public static function withDefaults(Block $block, array $fields): array
    {
        if (!isset(self::$defaults[$block::class])) {
            $builder = \FloCMS\Admin\Form\Fields\Builder::make('blocks');
            $defaults = [];
            foreach ($builder->blockForm($block)->fields() as $name => $field) {
                if (in_array($name, $builder->settingNames($block), true) || str_starts_with($name, '_')) { continue; }
                $value = $field->dehydrate($field->stateFromRecord(null, Languages::default()));
                if (is_string($value) && ($value[0] ?? '') === '[') { $value = json_decode($value, true) ?? []; }
                $defaults[$name] = $value ?? '';
            }
            self::$defaults[$block::class] = $defaults;
        }
        $fields += self::$defaults[$block::class];
        $fields = self::safeLinks($fields);
        foreach ($fields as $name => $value) {
            if (is_bool($value)) { $fields[$name] = $value ? '1' : '0'; }
            elseif (is_int($value) || is_float($value)) { $fields[$name] = (string) $value; }
            elseif ($value === null) { $fields[$name] = ''; }
        }
        return $fields;
    }

    /**
     * Links typed in blocks ("link", "link2", also inside a list's items) as
     * the website may print them: http(s), mailto:, tel:, or an address on
     * the site (/about, #team, ?page=2, contact); anything else (javascript:,
     * data:) becomes ''.
     */
    public static function safeLinks(array $fields): array
    {
        foreach ($fields as $name => $value) {
            // A list kept as JSON (written by hand or imported): its links are cleaned too.
            if (is_string($value) && str_starts_with(ltrim($value), '[') && is_array($list = json_decode($value, true))) { $value = $list; }
            if (is_array($value)) { $fields[$name] = self::safeLinks($value); continue; }
            if (($name === 'link' || $name === 'link2') && is_string($value)) { $fields[$name] = self::safeUrl($value); }
        }
        return $fields;
    }

    public static function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') { return ''; }
        // Browsers ignore tabs and newlines inside a scheme ("java\tscript:").
        $plain = strtolower(preg_replace('/[\x00-\x20]+/', '', $url) ?? '');
        if (preg_match('/^[a-z][a-z0-9+.-]*:/', $plain, $m) === 1) {
            return in_array($m[0], ['http:', 'https:', 'mailto:', 'tel:'], true) ? $url : '';
        }
        return $url;
    }

    /** The wrapper's attributes: its classes from the shared settings, and the anchor. */
    private static function wrapper(Block $block, string $type, array $settings, string $anchor): string
    {
        $default = BlockSettings::defaultSpace($block);
        $space = static fn (string $key): string => in_array($settings[$key] ?? $default, ['none', 'small', 'medium', 'large'], true) ? (string) ($settings[$key] ?? $default) : $default;
        $classes = ['flo-block', 'flo-block-' . str_replace('_', '-', $type)];
        if (!$block instanceof Core\Section) { $classes[] = ($settings['width'] ?? $block->defaultWidth()) === 'full' ? 'is-full' : 'is-boxed'; }
        $classes[] = 'flo-space-above-' . $space('space_above');
        $classes[] = 'flo-space-below-' . $space('space_below');
        $devices = (string) ($settings['devices'] ?? 'all');
        if ($devices === 'desktop' || $devices === 'phone') { $classes[] = 'flo-only-' . $devices; }
        $extra = BlockSettings::classes((string) ($settings['class'] ?? ''));
        if ($extra !== '') { $classes[] = $extra; }
        return ' class="' . htmlspecialchars(implode(' ', $classes), ENT_QUOTES, 'UTF-8') . '"' . ($anchor !== '' ? ' id="' . htmlspecialchars($anchor, ENT_QUOTES, 'UTF-8') . '"' : '');
    }

    /** A slider outside the blocks: a Slider field's slides and the slider block's settings. */
    public static function slider(mixed $slides, array $options = []): string
    {
        $defaults = ['name' => '', 'height' => 'screen', 'height_px' => '560', 'height_phone' => '420', 'effect' => 'fade', 'interval' => '6', 'autoplay' => '1', 'arrows' => '1', 'dots' => '1', 'fit' => 'cover', 'overlay' => '35'];
        $vars = array_map(static fn ($value): string => is_bool($value) ? ($value ? '1' : '0') : (string) $value, array_intersect_key($options, $defaults)) + $defaults;
        $vars['slides'] = Core\Slider::shownSlides(self::safeLinks(['slides' => is_string($slides) ? (json_decode($slides, true) ?? []) : $slides])['slides']);
        if ($vars['slides'] === []) { return ''; }
        $vars['block'] = (object) ['id' => 's' . substr(hash('sha256', json_encode($slides) . json_encode($options)), 0, 8), 'type' => 'slider', 'settings' => [], 'lang' => self::language(), 'anchor' => ''];
        self::$printed['slider'] = true;
        return '<div class="flo-block flo-block-slider is-full">' . self::template('slider', $vars) . '</div>';
    }

    /** The site's template for a kind, else the package's. */
    public static function templatePath(string $type): ?string
    {
        if (preg_match('/^[a-z][a-z0-9_]{0,40}$/D', $type) !== 1) { return null; }
        $views = defined('VIEWS_PATH') ? (string) constant('VIEWS_PATH') : Admin::root() . DIRECTORY_SEPARATOR . 'views';
        foreach ([$views . '/blocks/' . $type . '.html', self::packagePath('resources/blocks/' . $type . '.html')] as $path) {
            if (is_file($path)) { return $path; }
        }
        return null;
    }

    /** Render a block template with variables (also the slider() helper's). */
    public static function template(string $type, array $vars): string
    {
        $path = self::templatePath($type);
        if ($path === null) { return ''; }
        if (defined('VIEWS_PATH') && defined('DS')) { return TemplateEngine::renderFile($path, $vars); }
        return self::evaluate($path, $vars);
    }

    /**
     * A template rendered outside a site (tests, a command): the same syntax,
     * without the view cache.
     *
     * @internal
     */
    public static function evaluate(string $path, array $vars): string
    {
        return (static function (string $__path, array $__vars): string {
            extract($__vars, EXTR_SKIP);
            $__level = ob_get_level();
            ob_start();
            try {
                eval('?>' . TemplateEngine::Decode((string) file_get_contents($__path)));
            } catch (\Throwable $e) {
                while (ob_get_level() > $__level) { ob_end_clean(); }
                throw $e;
            }
            return (string) ob_get_clean();
        })($path, $vars);
    }

    public static function packagePath(string $relative): string
    {
        return dirname(__DIR__, 2) . '/' . $relative;
    }

    private static function cacheFile(array $nodes, string $lang): ?string
    {
        $directory = Admin::root() . '/storage/cache/blocks';
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) { return null; }
        if (!is_writable($directory)) { return null; }
        // The templates in use (the site's or the package's) and their versions are part of the key.
        $templates = [];
        $walk = static function (array $nodes) use (&$walk, &$templates): void {
            foreach ($nodes as $node) {
                $type = (string) ($node['type'] ?? '');
                if (!isset($templates[$type])) { $path = self::templatePath($type); $templates[$type] = $path === null ? '' : $path . '@' . (int) @filemtime($path); }
                if (is_array($node['children'] ?? null)) { $walk($node['children']); }
            }
        };
        $walk($nodes);
        ksort($templates);
        $key = hash('sha256', $lang . '|' . Admin::VERSION . '|' . json_encode($nodes) . '|' . json_encode($templates));
        return $directory . '/' . substr($key, 0, 40) . '.json';
    }

    /**
     * The kinds printed so far on this page.
     *
     * @return list<string>
     */
    public static function printed(): array
    {
        return array_keys(self::$printed);
    }

    /**
     * The styles and scripts the printed blocks need: the base styles, then
     * each kind's own (Block::assets()). From public/assets/flo-blocks when the
     * site copied them (php flo admin:blocks), else from the package, inline.
     */
    public static function assets(): string
    {
        if (self::$printed === []) { return ''; }
        $names = ['blocks'];
        foreach (array_keys(self::$printed) as $type) {
            $block = BlockRegistry::find($type);
            foreach ($block?->assets() ?? [] as $name) { if (preg_match('/^[a-z][a-z0-9-]{0,40}$/D', $name) === 1) { $names[] = $name; } }
        }
        $html = '';
        foreach (array_values(array_unique($names)) as $name) {
            foreach (['css', 'js'] as $kind) {
                $public = Admin::root() . '/public/assets/flo-blocks/' . $name . '.' . $kind;
                $package = self::packagePath('resources/site/' . $name . '.' . $kind);
                if (is_file($public)) {
                    $url = '/assets/flo-blocks/' . $name . '.' . $kind . '?v=' . substr(hash('sha256', (string) @filemtime($public) . (string) @filesize($public)), 0, 10);
                    $html .= $kind === 'css' ? '<link rel="stylesheet" href="' . $url . '">' . "\n" : '<script src="' . $url . '" defer></script>' . "\n";
                } elseif (is_file($package)) {
                    // From the package until the site copies them: a file of its own (cached, and fine with a strict Content-Security-Policy).
                    $url = htmlspecialchars(\FloCMS\Admin\Assets::blockUrl($name, $kind), ENT_QUOTES, 'UTF-8');
                    $html .= $kind === 'css' ? '<link rel="stylesheet" href="' . $url . '">' . "\n" : '<script src="' . $url . '" defer></script>' . "\n";
                }
            }
        }
        return $html;
    }
}
