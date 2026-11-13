<?php
declare(strict_types=1);

namespace FloCMS\Admin;

use FloCMS\Admin\Blocks\Block;
use FloCMS\Admin\Settings\SettingsPage;

/**
 * What packages add to the panel (0.43), collected from Admin::extend():
 *
 *     Admin::extend(static function (Extension $panel): void {
 *         $panel->blocks([new MyComponentBlock('pricing'), LatestPosts::class]);
 *         $panel->resources([FormEntriesResource::class]);
 *         $panel->pages([MenusPage::class]);
 *         $panel->settings([SettingsPage::make('site_style')->label('Site style')->schema([...])]);
 *         $panel->sitemap(static fn (): iterable => [['loc' => '/blog', 'lastmod' => time()]]);
 *     });
 *
 * Everything is optional and adds to what config/admin.php names; the site's
 * own config wins where both name the same thing (a block type, a slug).
 */
final class Extension
{
    /** @var list<Block|class-string<Block>> */
    private array $blocks = [];
    /** @var list<string> */
    private array $resources = [];
    /** @var list<string> */
    private array $pages = [];
    /** @var list<SettingsPage> */
    private array $settings = [];
    /** @var list<callable(): iterable<array{loc: string, lastmod?: int|string|null, alternates?: array<string, string>}>> */
    private array $sitemaps = [];
    /** @var list<string> */
    private array $problems = [];

    /**
     * Block kinds for the Builder: Block objects (one class can stand for many
     * kinds by overriding blockType(), blockLabel() …) or Block class names.
     *
     * @param iterable<Block|class-string<Block>> $blocks
     */
    public function blocks(iterable $blocks): self
    {
        foreach ($blocks as $block) {
            if ($block instanceof Block || (is_string($block) && is_a($block, Block::class, true))) {
                $this->blocks[] = $block;
                continue;
            }
            $this->problems[] = 'blocks(): ' . (is_string($block) ? $block : get_debug_type($block)) . ' is not a ' . Block::class;
        }
        return $this;
    }

    /** @param iterable<class-string<Resource>> $classes */
    public function resources(iterable $classes): self
    {
        foreach ($classes as $class) {
            if (is_string($class)) {
                $this->resources[] = ltrim($class, '\\');
                continue;
            }
            $this->problems[] = 'resources(): list classes as Name::class strings (got ' . get_debug_type($class) . ')';
        }
        return $this;
    }

    /** Custom panel pages (AdminPage classes, 0.36). @param iterable<class-string<AdminPage>> $classes */
    public function pages(iterable $classes): self
    {
        foreach ($classes as $class) {
            if (is_string($class)) {
                $this->pages[] = ltrim($class, '\\');
                continue;
            }
            $this->problems[] = 'pages(): list classes as Name::class strings (got ' . get_debug_type($class) . ')';
        }
        return $this;
    }

    /** Settings pages, as admin/settings.php returns them. @param iterable<SettingsPage> $pages */
    public function settings(iterable $pages): self
    {
        foreach ($pages as $page) {
            if ($page instanceof SettingsPage) {
                $this->settings[] = $page;
                continue;
            }
            $this->problems[] = 'settings(): ' . get_debug_type($page) . ' is not a ' . SettingsPage::class;
        }
        return $this;
    }

    /**
     * More addresses for /sitemap.xml (when the panel's Pages serve it). The
     * function runs when the sitemap is built and returns entries:
     * ['loc' => '/blog/my-post' or a full URL, 'lastmod' => timestamp or date,
     *  'alternates' => ['en' => url, 'ar' => url]].
     *
     * @param callable(): iterable<array{loc: string, lastmod?: int|string|null, alternates?: array<string, string>}> $entries
     */
    public function sitemap(callable $entries): self
    {
        $this->sitemaps[] = $entries;
        return $this;
    }

    /** @internal */
    public function problem(string $problem): void
    {
        if (!in_array($problem, $this->problems, true)) { $this->problems[] = $problem; }
    }

    /** @return list<Block|class-string<Block>> */
    public function getBlocks(): array { return $this->blocks; }

    /** @return list<string> */
    public function getResources(): array { return $this->resources; }

    /** @return list<string> */
    public function getPages(): array { return $this->pages; }

    /** @return list<SettingsPage> */
    public function getSettings(): array { return $this->settings; }

    /** @return list<callable> */
    public function getSitemaps(): array { return $this->sitemaps; }

    /** What went wrong while packages extended the panel (admin:doctor lists it). @return list<string> */
    public function getProblems(): array { return $this->problems; }
}
