<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Search\GlobalSearch;
use FloCMS\Admin\Search\SearchResult;
use FloCMS\Admin\Tests\Fixtures\Search\HelpSource;
use FloCMS\Admin\Tests\TestCase;

final class SearchUnitTest extends TestCase
{
    protected function tearDown(): void
    {
        Admin::reset();
        parent::tearDown();
    }

    public function testOnlySitePathsAndWebAddressesAreLinks(): void
    {
        self::assertSame('/en/admin/posts', SearchResult::safeUrl('/en/admin/posts'));
        self::assertSame('https://example.test/a', SearchResult::safeUrl('https://example.test/a'));
        foreach (['javascript:alert(1)', 'JaVaScRiPt:x', 'data:text/html,x', '//evil.test/x', '/\\evil', "/a\nb", '', 'mailto:a@b'] as $bad) {
            self::assertNull(SearchResult::safeUrl($bad), $bad);
        }
        self::assertNull(SearchResult::make('x', 'javascript:alert(1)')->toArray());
        self::assertNull(SearchResult::make('  ', '/a')->toArray(), 'no title, no result');
    }

    public function testResultsArePlainOneLineText(): void
    {
        $item = SearchResult::make("<b>Sea</b>\n view", '/a')->detail(str_repeat('x', 300))->type('Post')->icon('home')->toArray();
        self::assertSame('Sea view', $item['title']);
        self::assertSame(200, mb_strlen($item['detail']));
        self::assertStringEndsWith('…', $item['detail']);
        self::assertStringContainsString('<svg', $item['icon']);
        self::assertSame('', $item['thumb']);

        $media = SearchResult::make('Terrace', '/a')->thumbnail('/uploads/t.jpg')->toArray();
        self::assertSame('/uploads/t.jpg', $media['thumb']);
        self::assertSame('', $media['icon'], 'a thumbnail replaces the icon');
        self::assertSame('', SearchResult::make('x', '/a')->thumbnail('javascript:x')->toArray()['thumb']);
    }

    public function testConfiguration(): void
    {
        $defaults = Admin::useConfig([], true, sys_get_temp_dir())->search();
        self::assertSame(['enabled' => true, 'per_group' => 5, 'shortcut' => true, 'sources' => []], $defaults);
        self::assertFalse(Admin::useConfig(['search' => false], true, sys_get_temp_dir())->search()['enabled']);
        $custom = Admin::useConfig(['search' => ['per_group' => 50, 'shortcut' => false, 'sources' => [HelpSource::class, \stdClass::class, 7]]], true, sys_get_temp_dir())->search();
        self::assertSame(10, $custom['per_group']);
        self::assertFalse($custom['shortcut']);
        self::assertSame([HelpSource::class], $custom['sources'], 'only SearchSource classes');
    }

    public function testTheTimeBudgetSkipsTheRestAndSaysSo(): void
    {
        Admin::useConfig(['search' => ['sources' => [HelpSource::class]]], true, sys_get_temp_dir());
        $all = (new GlobalSearch(5, 10.0))->run('gallery');
        self::assertFalse($all['partial']);
        self::assertSame(['Help'], array_column($all['groups'], 'label'));

        $late = (new GlobalSearch(5, 0.0))->run('gallery');
        self::assertTrue($late['partial']);
        self::assertSame([], $late['groups'], 'no source starts once the budget is spent');

        $long = (new GlobalSearch(5, 10.0))->run(str_repeat('a', 150) . "\n");
        self::assertSame(100, mb_strlen($long['query']));
    }
}
