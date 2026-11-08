<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Widgets\{ActivityWidget, BarListWidget, ChartWidget, Dashboard, ListWidget, Period, ProgressWidget, Stat, WidgetContext, WidgetRegistry};

/** The dashboard pieces of 0.42: period, headline numbers, charts, the list widgets. */
final class DashboardPiecesTest extends TestCase
{
    protected function tearDown(): void { Admin::reset(); parent::tearDown(); }

    public function testPeriodsComeFromConfigAndTheAddress(): void
    {
        Admin::useConfig([]);
        self::assertSame([7, 30, 90, 365], Admin::config()->dashboardPeriods());
        self::assertSame(30, Admin::config()->dashboardPeriod());
        self::assertSame(7, Period::fromRequest('7')->days());
        self::assertSame(30, Period::fromRequest('8')->days(), 'only the configured periods');
        self::assertSame(30, Period::fromRequest('x')->days());
        Admin::useConfig(['dashboard_periods' => [14, 7, 7, 0, 'x', 99999], 'dashboard_period' => 30]);
        self::assertSame([7, 14], Admin::config()->dashboardPeriods());
        self::assertSame(7, Admin::config()->dashboardPeriod(), 'a default that is not listed falls back to the first');
        Admin::useConfig(['dashboard_periods' => [], 'dashboard_period' => 60]);
        self::assertSame(60, Admin::config()->dashboardPeriod());
    }

    public function testAPeriodHasItsPreviousOneAndChartPoints(): void
    {
        Admin::useConfig([]);
        $end = strtotime('2026-11-08 15:00:00');
        $week = Period::last(7, $end);
        self::assertSame('2026-11-02 00:00:00', $week->from(), 'today and the six days before');
        self::assertSame('2026-11-08 15:00:00', $week->to());
        self::assertSame('2026-10-26 00:00:00', $week->previous()->from());
        self::assertSame('2026-11-01 23:59:59', $week->previous()->to());
        self::assertSame('Last 7 days', $week->label());
        self::assertSame('12 months', Period::last(365)->short());
        $series = $week->series(['2026-11-02' => 2, '2026-11-08' => 5, '2026-10-01' => 9]);
        self::assertCount(7, $series['values']);
        self::assertSame([2, 0, 0, 0, 0, 0, 5], $series['values']);
        self::assertSame('day', $week->unit());
        self::assertSame('week', Period::last(90, $end)->unit());
        $months = Period::last(365, $end)->series(['2026-11-03' => 4, '2026-10-31' => 1]);
        self::assertSame('month', Period::last(365, $end)->unit());
        self::assertSame(4, end($months['values']));
        self::assertLessThanOrEqual(13, count($months['values']));
    }

    public function testHeadlineNumbersShowTrendsChangesAndSparklines(): void
    {
        Admin::useConfig([]);
        $context = new WidgetContext([]);
        $up = Stat::make('Inquiries', 37)->icon('mail')->trend(37, 30)->sparkline([1, 3, 2, 5])->description('<b>vs 30</b>')->descriptionColor('warning')->render($context, true);
        self::assertStringContainsString('class="fa-widget-stat fa-widget-primary fa-card has-icon"', $up);
        self::assertStringContainsString('fa-stat-chip', $up);
        self::assertStringContainsString('fa-trend is-good', $up);
        self::assertStringContainsString('23%', $up);
        self::assertStringContainsString('Up 23%', $up, 'the trend is read out in words');
        self::assertStringContainsString('class="fa-sparkline"', $up);
        self::assertStringContainsString('fa-stat-note-warning', $up);
        self::assertStringContainsString('&lt;b&gt;vs 30&lt;/b&gt;', $up);
        self::assertStringNotContainsString('style=', $up);
        $down = Stat::make('Waiting', 3)->trend(3, 4)->invertTrend()->render($context);
        self::assertStringContainsString('fa-trend is-good', $down, 'fewer is better');
        self::assertStringContainsString('25%', $down);
        self::assertStringContainsString('>4<', Stat::make('x', 1)->change(4)->render($context));
        self::assertStringNotContainsString('fa-trend', Stat::make('x', 1)->trend(5, 0)->render($context), 'nothing to compare with');
        self::assertStringContainsString('No change', Stat::make('x', 1)->trend(5, 5)->render($context));
        self::assertStringContainsString('href="/en/admin/posts?status=draft"', Stat::make('x', 1)->url('/en/admin/posts?status=draft')->render($context));
        foreach ([fn () => Stat::make('x', 1)->icon('nope'), fn () => Stat::make('x', 1)->sparkline(range(1, 31)), fn () => Stat::make('x', 1)->trend(INF, 1),
                  fn () => Stat::make('x', 1)->url('https://evil.example/'), fn () => Stat::make('x', 1)->url('//evil.example/'), fn () => Stat::make('x', 1)->descriptionColor('pink')] as $bad) {
            try { $bad(); self::fail('accepted a bad value'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testLineChartsShowThePreviousPeriodAndDonutsTheirShares(): void
    {
        Admin::useConfig([]);
        $line = new class extends ChartWidget {
            protected string $title = 'Per day'; protected string $type = 'line';
            protected function data(WidgetContext $context): array { return ['labels' => ['1', '2', '3'], 'values' => [1, 4, 2], 'previous' => [0, 1, 1], 'label' => 'This month', 'previousLabel' => 'Last month']; }
        };
        $html = $line->render(new WidgetContext([]), 'w');
        self::assertStringContainsString('class="fa-chart-previous"', $html);
        self::assertStringContainsString('class="fa-chart-area"', $html);
        self::assertStringContainsString('Last month', $html);
        self::assertStringContainsString('<th scope="col">Last month</th>', $html, 'the data table has both');
        self::assertStringNotContainsString('style=', $html);
        $donut = new class extends ChartWidget {
            protected string $title = 'Sources'; protected string $type = 'donut';
            protected function data(WidgetContext $context): array { return ['labels' => ['Property', '<Contact>'], 'values' => [3, 1], 'total' => 'inquiries']; }
        };
        $html = $donut->render(new WidgetContext([]), 'd');
        self::assertStringContainsString('class="fa-donut"', $html);
        self::assertStringContainsString('>75%<', $html);
        self::assertStringContainsString('>25%<', $html);
        self::assertStringContainsString('&lt;Contact&gt;', $html);
        self::assertStringContainsString('>4</text>', $html);
        self::assertStringContainsString('>inquiries</text>', $html);
        $bad = new class extends ChartWidget {
            protected string $type = 'donut';
            protected function data(WidgetContext $context): array { return ['labels' => ['a'], 'values' => [-1]]; }
        };
        $this->expectException(\InvalidArgumentException::class);
        $bad->render(new WidgetContext([]), 'b');
    }

    public function testPreviousNeedsAsManyValues(): void
    {
        Admin::useConfig([]);
        $chart = new class extends ChartWidget {
            protected function data(WidgetContext $context): array { return ['labels' => ['a', 'b'], 'values' => [1, 2], 'previous' => [1]]; }
        };
        $this->expectException(\InvalidArgumentException::class);
        $chart->render(new WidgetContext([]), 'p');
    }

    public function testBarProgressAndListWidgets(): void
    {
        Admin::useConfig([]);
        $bars = new class extends BarListWidget {
            protected function items(WidgetContext $context): array { return [['label' => 'For sale', 'value' => 18], ['label' => '<Sold>', 'value' => 9, 'color' => 'neutral', 'url' => '/en/admin/listings']]; }
            protected function footer(WidgetContext $context): string { return '27 in total'; }
        };
        $html = $bars->render(new WidgetContext([]), 'b');
        self::assertStringContainsString('width="100"', $html);
        self::assertStringContainsString('width="50"', $html, 'against the largest value');
        self::assertStringContainsString('&lt;Sold&gt;', $html);
        self::assertStringContainsString('27 in total', $html);
        self::assertStringNotContainsString('style=', $html);
        $progress = new class extends ProgressWidget {
            protected function items(WidgetContext $context): array { return [['label' => 'Urban86', 'value' => 62, 'meta' => 'Glyfada', 'description' => 'June 2027'], ['label' => 'Over', 'value' => 140]]; }
        };
        $html = $progress->render(new WidgetContext([]), 'p');
        self::assertStringContainsString('62%', $html);
        self::assertStringContainsString('aria-label="100%"', $html, 'kept within 0–100');
        $list = new class extends ListWidget {
            protected function items(WidgetContext $context): array { return [['icon' => 'user-x', 'color' => 'danger', 'title' => 'Without an owner', 'value' => 3, 'url' => '/en/admin/inquiries'], ['title' => 'Nothing here', 'value' => 0]]; }
        };
        $html = $list->render(new WidgetContext([]), 'l');
        self::assertStringContainsString('Without an owner', $html);
        self::assertStringNotContainsString('Nothing here', $html, 'zero items are left out');
        $clear = new class extends ListWidget {
            protected function items(WidgetContext $context): array { return [['title' => 'Nothing', 'value' => 0]]; }
        };
        self::assertStringContainsString('Nothing needs your attention.', $clear->render(new WidgetContext([]), 'c'));
        foreach ([['title' => 'x', 'url' => 'javascript:alert(1)'], ['title' => 'x', 'color' => 'pink'], ['title' => 'x', 'icon' => 'nope'], ['title' => 'x', 'extra' => 1], ['description' => 'no title']] as $item) {
            $bad = new class extends ListWidget {
                public static array $item = [];
                protected function items(WidgetContext $context): array { return [self::$item]; }
            };
            $bad::$item = $item;
            try { $bad->render(new WidgetContext([]), 'x'); self::fail('accepted ' . json_encode($item)); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testTheNewTypesRegisterAndLinksStayOnTheSite(): void
    {
        $registry = WidgetRegistry::fromConfig(Admin::useConfig(['widgets' => [ActivityWidget::class]]));
        self::assertSame([], $registry->problems());
        self::assertCount(1, $registry->all());
        self::assertSame('Recent activity', $registry->all()[0]->title());
        self::assertTrue(Dashboard::safeUrl('/en/admin/inquiries?status=new'));
        foreach (['//evil.example', 'https://evil.example/x', 'javascript:alert(1)', '/\\evil'] as $url) { self::assertFalse(Dashboard::safeUrl($url), $url); }
    }
}
