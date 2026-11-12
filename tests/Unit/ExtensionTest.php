<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Blocks\BlockRegistry;
use FloCMS\Admin\Blocks\BlockRenderer;
use FloCMS\Admin\Extension;
use FloCMS\Admin\Settings\SettingsPage;
use FloCMS\Admin\Tests\Fixtures\Extension\ComponentBlock;
use FloCMS\Admin\Tests\Fixtures\Extension\SiteHero;
use FloCMS\Admin\Tests\Fixtures\Extension\TypeOnlyBlock;
use FloCMS\Admin\Tests\Fixtures\PanelPages\GettingStarted;
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\Fixtures\Resources\TagResource;
use FloCMS\Admin\Tests\TestCase;

/** Packages adding to the panel with Admin::extend() (0.43), and blocks as objects. */
final class ExtensionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Admin::clearExtensions();
        BlockRegistry::reset();
    }

    protected function tearDown(): void
    {
        Admin::clearExtensions();
        BlockRegistry::reset();
        parent::tearDown();
    }

    public function testOneBlockClassCanStandForManyKinds(): void
    {
        Admin::extend(static function (Extension $panel): void {
            $panel->blocks([new ComponentBlock('hero', 'Hero'), new ComponentBlock('pricing', 'Pricing', 'content')]);
        });

        $all = BlockRegistry::all();
        self::assertArrayHasKey('site_hero', $all);
        self::assertArrayHasKey('site_pricing', $all);
        self::assertArrayHasKey('heading', $all, 'the core blocks stay');
        self::assertSame('Pricing', $all['site_pricing']->blockLabel());
        self::assertSame('content', $all['site_pricing']->blockCategory());
        self::assertSame('Hero', $all['site_hero']->title([]), 'the title falls back to the kind\'s own label');
        self::assertSame(['site_hero'], array_keys(BlockRegistry::only(['site_hero'])));

        $html = BlockRenderer::render([['id' => 'b1', 'type' => 'site_pricing', 'data' => ['heading' => 'Plans <3>']]]);
        self::assertStringContainsString('<p class="component-pricing">Plans &lt;3&gt;</p>', $html);
        self::assertStringContainsString('data-block="site_pricing"', $html);
    }

    public function testTheSitesOwnBlocksWinOverAPackagesOfTheSameType(): void
    {
        Admin::extend(static fn (Extension $panel) => $panel->blocks([new ComponentBlock('hero', 'Package hero')]));
        self::assertSame('Package hero', BlockRegistry::find('site_hero')?->blockLabel());

        Admin::useConfig(['blocks' => [SiteHero::class]], true, sys_get_temp_dir());
        BlockRegistry::reset();
        self::assertInstanceOf(SiteHero::class, BlockRegistry::find('site_hero'), 'config/admin.php comes last');
    }

    public function testResourcesPagesAndSettingsAreAdded(): void
    {
        Admin::useConfig(['resources' => [PostResource::class]], true, sys_get_temp_dir());
        Admin::extend(static function (Extension $panel): void {
            $panel->resources([TagResource::class, PostResource::class])
                ->pages([GettingStarted::class])
                ->settings([SettingsPage::make('site_style')->label('Site style')->schema([\FloCMS\Admin\Form\Fields\TextInput::make('accent')])]);
        });

        $slugs = array_keys(Admin::registry()->all());
        self::assertContains('posts', $slugs);
        self::assertContains('post-tags', $slugs, 'the package\'s resource');
        self::assertSame(1, count(array_filter($slugs, static fn (string $slug): bool => $slug === 'posts')), 'a class listed twice is added once');
        self::assertSame([], Admin::registry()->problems());
        self::assertNotNull(Admin::panelPages()->findClass(GettingStarted::class), implode("\n", Admin::panelPages()->problems()));
        self::assertNotNull(Admin::settings()->find('site_style'), implode("\n", Admin::settings()->problems()));
    }

    public function testMistakesBecomeProblemsInsteadOfErrors(): void
    {
        Admin::extend(static fn (Extension $panel) => $panel->blocks(['NotAClass', new \stdClass()])->resources([42])->settings(['x']));
        Admin::extend(static function (): void { throw new \RuntimeException('package broke'); });
        Admin::extend(static fn (Extension $panel) => $panel->blocks([new ComponentBlock('ok', 'Still here')]));

        $problems = Admin::extension()->getProblems();
        self::assertCount(5, $problems);
        self::assertStringContainsString('NotAClass', $problems[0] . $problems[1]);
        self::assertStringContainsString('Admin::extend(): package broke', implode("\n", $problems));
        self::assertNotNull(BlockRegistry::find('site_ok'), 'the next package still runs');
    }

    public function testExtendingAgainRebuildsWhatWasCollected(): void
    {
        self::assertNull(BlockRegistry::find('site_late'));
        Admin::extend(static fn (Extension $panel) => $panel->blocks([new ComponentBlock('late', 'Late')]));
        self::assertNotNull(BlockRegistry::find('site_late'), 'a package registered after the first look');
        Admin::clearExtensions();
        BlockRegistry::reset();
        self::assertNull(BlockRegistry::find('site_late'));
    }

    public function testKindsOfOneClassKeepTheirOwnFormsAndDefaults(): void
    {
        Admin::extend(static fn (Extension $panel) => $panel->blocks([new ComponentBlock('hero', 'Hero'), new ComponentBlock('pricing', 'Pricing')]));
        $builder = \FloCMS\Admin\Form\Fields\Builder::make('content');
        $names = static fn (string $type): array => array_map(static fn ($field): string => $field->getName(), $builder->blockForm(BlockRegistry::find($type))->fields());
        self::assertContains('hero_note', $names('site_hero'));
        self::assertContains('pricing_note', $names('site_pricing'), 'not the form of the first kind of the class');
        self::assertNotContains('hero_note', $names('site_pricing'));

        $defaults = BlockRenderer::withDefaults(BlockRegistry::find('site_hero'), []);
        self::assertSame('hero default', $defaults['hero_note']);
        $defaults = BlockRenderer::withDefaults(BlockRegistry::find('site_pricing'), []);
        self::assertSame('pricing default', $defaults['pricing_note']);
        self::assertArrayNotHasKey('hero_note', $defaults);
    }

    public function testAComponentWithOnlyATypeIsNamedAfterIt(): void
    {
        $block = new TypeOnlyBlock();
        self::assertSame('Price table', $block->blockLabel());
        self::assertSame('Price table', $block->title([]));
    }

    public function testABrokenPackageBlockIsReportedAndTheOthersStay(): void
    {
        Admin::extend(static fn (Extension $panel) => $panel->blocks([new TypeOnlyBlock('Bad-Type'), new TypeOnlyBlock('fine'), SiteHero::class, \FloCMS\Admin\Tests\Fixtures\Extension\ComponentBlock::class]));
        $all = BlockRegistry::all();
        self::assertArrayHasKey('fine', $all);
        self::assertArrayHasKey('site_hero', $all);
        self::assertArrayHasKey('heading', $all);
        $problems = implode("\n", Admin::extension()->getProblems());
        self::assertStringContainsString('type "Bad-Type"', $problems);
        self::assertStringContainsString('ComponentBlock', $problems, 'a class that needs constructor arguments');
        BlockRegistry::reset();
        BlockRegistry::all();
        self::assertCount(2, Admin::extension()->getProblems(), 'each problem once');
    }

    public function testAPackageMayAskThePanelWhileItExtendsIt(): void
    {
        $seen = null;
        Admin::extend(static function (Extension $panel) use (&$seen): void {
            $seen = count(BlockRegistry::all());
            $panel->blocks([new TypeOnlyBlock('asked')]);
        });
        self::assertNotNull(BlockRegistry::find('asked'));
        self::assertGreaterThan(0, $seen);
    }

    public function testUseConfigRunsThePackagesAgain(): void
    {
        $runs = 0;
        Admin::extend(static function () use (&$runs): void { $runs++; });
        Admin::extension();
        Admin::useConfig([], true, sys_get_temp_dir());
        Admin::extension();
        self::assertSame(2, $runs);
    }
}
