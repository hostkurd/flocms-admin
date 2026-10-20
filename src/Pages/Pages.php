<?php
declare(strict_types=1);

namespace FloCMS\Admin\Pages;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\App;
use FloCMS\Core\Database;

/**
 * The website's pages (0.30): which page answers an address, the home page,
 * the menu. Reads the pages table once per request (without the content);
 * a page's content is read when it is shown.
 */
final class Pages
{
    public const STATUSES = ['draft', 'published', 'scheduled'];
    /** First address segments a page may not take: the panel's and the framework's own. */
    public const RESERVED = ['admin', 'login', 'api', 'v1', 'flo-admin', 'flo-preview', 'sitemap.xml', 'robots.txt', 'assets', 'uploads', 'public'];
    public const MAX_DEPTH = 6;

    /** @var array<int, object>|null id => row (without the content) */
    private static ?array $rows = null;
    private static ?Page $current = null;
    private static int $now = 0;

    public static function enabled(): bool
    {
        return Admin::config()->pages()['enabled'];
    }

    public static function table(): string
    {
        return Admin::config()->pages()['table'];
    }

    /** @internal tests: the clock, and a fresh read */
    public static function reset(?int $now = null): void
    {
        self::$rows = null;
        self::$current = null;
        self::$now = $now ?? 0;
    }

    public static function now(): int
    {
        return self::$now > 0 ? self::$now : time();
    }

    private static function db(): ?Database
    {
        try { return App::$db ?? App::db(); } catch (\Throwable) { return null; }
    }

    /**
     * Every page, without its content.
     *
     * @return array<int, object>
     */
    public static function rows(): array
    {
        if (self::$rows !== null) { return self::$rows; }
        $db = self::db();
        if ($db === null || !self::enabled()) { return self::$rows = []; }
        try {
            $rows = $db->table(self::table())->select(['id', 'title', 'slug', 'type', 'status', 'publish_at', 'unpublish_at', 'parent_id', 'menu', 'sort', 'noindex', 'is_home', 'template', 'updated_at'])
                ->orderBy('sort')->orderBy('id')->get();
        } catch (\Throwable $e) {
            error_log('flocms-admin: pages could not be read: ' . $e->getMessage());
            $rows = [];
        }
        $byId = [];
        foreach ($rows as $row) { $byId[(int) $row->id] = $row; }
        return self::$rows = $byId;
    }

    /** The whole row of a page (with its content), or null. */
    public static function load(int $id): ?object
    {
        $db = self::db();
        if ($db === null) { return null; }
        try { return $db->table(self::table())->where('id', '=', $id)->first(); } catch (\Throwable) { return null; }
    }

    public static function time(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') { return null; }
        $time = strtotime($value);
        return $time === false ? null : $time;
    }

    /** Whether visitors see the page now: published (or scheduled) and inside its dates. */
    public static function isLive(object $row): bool
    {
        $status = (string) ($row->status ?? 'draft');
        if ($status !== 'published' && $status !== 'scheduled') { return false; }
        $from = self::time($row->publish_at ?? null);
        $until = self::time($row->unpublish_at ?? null);
        return ($from === null || $from <= self::now()) && ($until === null || $until > self::now());
    }

    /** The status as people read it: a scheduled page whose time came is published. */
    public static function status(object $row): string
    {
        $status = (string) ($row->status ?? 'draft');
        if ($status === 'scheduled' && ($from = self::time($row->publish_at ?? null)) !== null && $from <= self::now()) { return 'published'; }
        return in_array($status, self::STATUSES, true) ? $status : 'draft';
    }

    /** Whether the page has its own title in a language. */
    public static function isTranslated(object $row, string $lang): bool
    {
        $titles = Translations::all($row->title ?? '');
        return trim((string) ($titles[$lang] ?? '')) !== '';
    }

    /** The page's address segment in a language (its own, else the default language's). */
    public static function slug(object $row, string $lang): string
    {
        $slugs = Translations::all($row->slug ?? '');
        $slug = trim((string) ($slugs[$lang] ?? ''));
        return $slug !== '' ? $slug : trim((string) ($slugs[Languages::default()] ?? ''));
    }

    /** The page's path in a language: its parents' slugs and its own ("services/web-design"); '' for the home page. */
    public static function path(object $row, string $lang): string
    {
        if ((int) ($row->is_home ?? 0) === 1) { return ''; }
        $rows = self::rows();
        $parts = [self::slug($row, $lang)];
        $seen = [(int) $row->id => true];
        $parent = (int) ($row->parent_id ?? 0);
        for ($i = 0; $parent > 0 && isset($rows[$parent]) && !isset($seen[$parent]) && $i < self::MAX_DEPTH; $i++) {
            $seen[$parent] = true;
            array_unshift($parts, self::slug($rows[$parent], $lang));
            $parent = (int) ($rows[$parent]->parent_id ?? 0);
        }
        return implode('/', array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /** The site's address of a page in a language: /<lang>/<path>. */
    public static function url(object $row, string $lang): string
    {
        $base = defined('SITE_URI') ? rtrim((string) constant('SITE_URI'), '/') : '';
        $path = self::path($row, $lang);
        return $base . '/' . $lang . '/' . ($path === '' ? '' : implode('/', array_map('rawurlencode', explode('/', $path))));
    }

    /** Whether a page answers in a language (translated, or the default language stands in). */
    public static function answersIn(object $row, string $lang): bool
    {
        return self::isTranslated($row, $lang) || (Admin::config()->pages()['missing'] === 'default' && self::isTranslated($row, Languages::default()));
    }

    /** The live page at a path in a language, or null. */
    public static function find(string $path, string $lang): ?Page
    {
        $path = mb_strtolower(trim($path, '/'));
        if ($path === '') { return self::home($lang); }
        foreach (self::rows() as $row) {
            if (!self::isLive($row) || (int) ($row->is_home ?? 0) === 1 || !self::answersIn($row, $lang)) { continue; }
            // Each ancestor must be live too: a page under a draft is not shown.
            if (mb_strtolower(self::path($row, $lang)) === $path && self::ancestorsLive($row)) {
                $full = self::load((int) $row->id);
                return $full === null ? null : new Page($full, $lang);
            }
        }
        return null;
    }

    /** Whether visitors can open the page: live, and every page above it live too. */
    public static function isVisible(object $row): bool
    {
        return self::isLive($row) && self::ancestorsLive($row);
    }

    public static function ancestorsLive(object $row): bool
    {
        $rows = self::rows();
        $parent = (int) ($row->parent_id ?? 0);
        for ($i = 0; $parent > 0 && $i < self::MAX_DEPTH; $i++) {
            if (!isset($rows[$parent]) || !self::isLive($rows[$parent])) { return false; }
            $parent = (int) ($rows[$parent]->parent_id ?? 0);
        }
        return true;
    }

    /** The live home page in a language, or null (the site's own home page then shows). */
    public static function home(?string $lang = null): ?Page
    {
        $lang ??= self::language();
        foreach (self::rows() as $row) {
            if ((int) ($row->is_home ?? 0) === 1 && self::isLive($row) && self::answersIn($row, $lang)) {
                $full = self::load((int) $row->id);
                return $full === null ? null : new Page($full, $lang);
            }
        }
        return null;
    }

    public static function language(): string
    {
        $lang = defined('ACTIVE_LANG') ? strtolower((string) constant('ACTIVE_LANG')) : '';
        return $lang !== '' ? $lang : Languages::default();
    }

    /** The page being shown on this request, for page_meta() in the layout. */
    public static function current(): ?Page
    {
        return self::$current;
    }

    public static function setCurrent(?Page $page): void
    {
        self::$current = $page;
    }

    /**
     * The live pages marked "Show in menu", in their order and nesting:
     * [['title' => …, 'url' => …, 'current' => bool, 'children' => [...]], …].
     *
     * @return list<array{id: int, title: string, url: string, current: bool, children: list<array<string, mixed>>}>
     */
    public static function menu(?string $lang = null, int $parent = 0, int $depth = 0): array
    {
        $lang ??= self::language();
        if ($depth > self::MAX_DEPTH) { return []; }
        $current = self::$current?->id();
        $items = [];
        foreach (self::rows() as $row) {
            if ((int) ($row->parent_id ?? 0) !== $parent || (int) ($row->menu ?? 0) !== 1 || !self::isLive($row) || !self::answersIn($row, $lang)) { continue; }
            $items[] = [
                'id' => (int) $row->id,
                'title' => Translations::get($row->title ?? '', $lang),
                'url' => self::url($row, $lang),
                'current' => $current === (int) $row->id,
                'children' => self::menu($lang, (int) $row->id, $depth + 1),
            ];
        }
        return $items;
    }

    /**
     * A first segment no page may take because something else answers it: the
     * panel, the framework, or the site's own controller of that name.
     */
    public static function reservedSegment(string $segment): ?string
    {
        $segment = mb_strtolower($segment);
        if (in_array($segment, self::RESERVED, true) || in_array($segment, Languages::all(), true)) { return $segment; }
        if (class_exists(\FloCMS\Admin\Support\Str::appControllerClass($segment))) { return \FloCMS\Admin\Support\Str::appControllerClass($segment); }
        return null;
    }

    /**
     * The other languages a page answers in, for hreflang: lang => address.
     *
     * @return array<string, string>
     */
    public static function alternates(object $row): array
    {
        $urls = [];
        foreach (Languages::all() as $lang) {
            if (self::isTranslated($row, $lang)) { $urls[$lang] = self::url($row, $lang); }
        }
        return $urls;
    }
}
