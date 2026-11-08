<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Tests\Fixtures\Dashboard\{Attention, Headline, Languages, LatestPosts, PostStatuses, PostsPerDay, Projects};
use FloCMS\Admin\Tests\Fixtures\Resources\{PostResource, TagResource};
use FloCMS\Admin\Tests\Fixtures\Widgets\WidgetPostResource;
use FloCMS\Admin\Widgets\{ActivityWidget, Period, WidgetContext};
use FloCMS\Core\{App, Auth, Config, Database, Session};

/** The 0.42 dashboard through whole requests: header, period, quick buttons, the new widget types, countBy() and countByDay(). */
final class DashboardRequestTest extends RequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $day = static fn (int $daysAgo): string => date('Y-m-d H:i:s', strtotime('today 10:00') - $daysAgo * 86400);
        $pdo->exec("UPDATE posts SET featured = 1, views = 7");
        $insert = $pdo->prepare('INSERT INTO posts (title, slug, status, featured, views, created_at) VALUES (?, ?, ?, 1, 3, ?)');
        foreach ([[0, 'draft'], [0, 'published'], [2, 'draft'], [40, 'draft'], [45, 'published']] as $i => [$ago, $status]) {
            $insert->execute(['{"en":"Extra ' . $i . '"}', 'extra-' . $i, $status, $day($ago)]);
        }
        $pdo->exec("UPDATE posts SET created_at = '" . $day(1) . "' WHERE id IN (1, 2)");
    }

    protected function tearDown(): void
    {
        Admin::reset();
        App::$db = null;
        parent::tearDown();
    }

    private function options(array $extra = []): array
    {
        return ['admin_config' => $extra + [
            'resources' => [PostResource::class, TagResource::class, WidgetPostResource::class],
            'widgets' => [Headline::class, PostsPerDay::class, PostStatuses::class, LatestPosts::class, Attention::class, Languages::class, Projects::class, ActivityWidget::class],
            'widget_columns' => 3,
            'dashboard_actions' => [WidgetPostResource::class, [TagResource::class, 'label' => 'New tag', 'icon' => 'tag'], 'Not\\A\\Class'],
            'reactive' => false,
        ]];
    }

    public function testTheDashboardHasAGreetingPeriodsQuickButtonsAndTheNewWidgets(): void
    {
        $page = self::request('GET', '/en/admin/dashboard', $this->options() + ['session' => ['fullname' => 'Sabat Aziz'] + self::SUPER]);
        self::assertSame(200, $page['status'], (string) ($page['error'] ?? ''));
        $body = $page['body'];
        self::assertMatchesRegularExpression('#<h1 class="fa-page-title">Good (morning|afternoon|evening), Sabat</h1>#', $body, 'the first name');
        self::assertStringContainsString('class="fa-period is-current" href="https://example.test/en/admin/dashboard?period=30" aria-current="true">30 days</a>', $body);
        self::assertStringContainsString('>12 months</a>', $body);
        self::assertStringContainsString('<input type="hidden" name="period" value="30">', $body);
        self::assertMatchesRegularExpression('#<a class="fa-btn fa-btn-primary" href="https://example.test/en/admin/widget-posts/create">.*?<span>New Post</span></a>#s', $body);
        self::assertStringContainsString('<span>New tag</span>', $body);
        // Headline numbers as separate cards
        self::assertStringContainsString('class="fa-widget-cards fa-widget-span-3"', $body);
        self::assertStringContainsString('class="fa-widget-stats fa-widget-stats-4"', $body);
        self::assertStringContainsString('class="fa-sparkline"', $body);
        // 4 posts made in the last 30 days (2 today, 1 two days ago, 2 yesterday... ) against 2 before
        self::assertMatchesRegularExpression('#New posts</span></span><span class="fa-stat-line"><span class="fa-stat-value" dir="auto">5</span>#', $body);
        self::assertStringContainsString('fa-trend is-good', $body);
        // The chart with the previous period, the donut, lists
        self::assertStringContainsString('<span class="fa-widget-sub">Last 30 days</span>', $body);
        self::assertStringContainsString('class="fa-chart-previous"', $body);
        self::assertStringContainsString('class="fa-donut"', $body);
        self::assertStringContainsString('class="fa-barlist"', $body);
        self::assertStringContainsString('class="fa-progress-list"', $body);
        self::assertStringContainsString('Draft posts', $body);
        self::assertStringNotContainsString('Posts without a title', $body, 'a zero item is left out');
        self::assertMatchesRegularExpression('#Latest posts</h2><a class="fa-widget-more" href="https://example.test/en/admin/widget-posts">See all#', $body);
        self::assertStringNotContainsString('Recent activity', $body, 'no activity log in this site');
        self::assertStringNotContainsString('currently unavailable', $body, (string) @file_get_contents(self::$root . '/storage/logs/app.log'));
        self::assertStringNotContainsString(' style="', substr($body, (int) strpos($body, 'fa-dash-head')));
    }

    public function testThePeriodComesFromTheAddressAndChangesTheNumbers(): void
    {
        $week = self::request('GET', '/en/admin/dashboard?period=7', $this->options())['body'];
        self::assertStringContainsString('class="fa-period is-current" href="https://example.test/en/admin/dashboard?period=7"', $week);
        self::assertStringContainsString('Last 7 days', $week);
        self::assertMatchesRegularExpression('#New posts</span></span><span class="fa-stat-line"><span class="fa-stat-value" dir="auto">5</span>#', $week);
        $year = self::request('GET', '/en/admin/dashboard?period=365', $this->options())['body'];
        self::assertMatchesRegularExpression('#New posts</span></span><span class="fa-stat-line"><span class="fa-stat-value" dir="auto">7</span>#', $year);
        $odd = self::request('GET', '/en/admin/dashboard?period=12', $this->options())['body'];
        self::assertStringContainsString('class="fa-period is-current" href="https://example.test/en/admin/dashboard?period=30"', $odd, 'only the configured periods');
        $none = self::request('GET', '/en/admin/dashboard', ['admin_config' => ['dashboard_periods' => []] + $this->options()['admin_config']])['body'];
        self::assertStringNotContainsString('class="fa-periods"', $none);
    }

    public function testQuickButtonsFollowPermissionsAndTheCardsDashboardGreetsToo(): void
    {
        // An editor (content.*) may create posts but not tags
        $editor = self::request('GET', '/en/admin/dashboard', $this->options() + ['session' => self::EDITOR])['body'];
        self::assertStringContainsString('/en/admin/widget-posts/create', $editor);
        self::assertStringNotContainsString('<span>New tag</span>', $editor);
        // No widgets: the cards, with the greeting and the quick buttons, without period buttons
        $cards = self::request('GET', '/en/admin/dashboard', ['admin_config' => ['resources' => [PostResource::class], 'dashboard_actions' => [PostResource::class]]])['body'];
        self::assertStringContainsString('fa-dash-card', $cards);
        self::assertStringContainsString('/en/admin/posts/create', $cards);
        self::assertStringNotContainsString('fa-periods', $cards);
        self::assertMatchesRegularExpression('#<h1 class="fa-page-title">Good (morning|afternoon|evening)#', $cards);
    }

    public function testArabicIsRightToLeft(): void
    {
        file_put_contents(self::$root . '/lang/ar.php', '<?php return ["lng.dir"=>"rtl"];');
        $body = self::request('GET', '/ar/admin/dashboard', $this->options())['body'];
        self::assertStringContainsString('dir="rtl"', $body);
        self::assertStringContainsString('30 يومًا', $body);
        self::assertStringContainsString('عرض الكل', $body);
    }

    public function testCountByAndCountByDayOnThisDatabase(): void
    {
        App::$db = new Database(self::pdo());
        Admin::useConfig(['resources' => [PostResource::class]], true, self::$root);
        Session::set('isloggedin', true); Session::set('admin_access', true); Session::set('role', 3); Session::set('user_id', 3);
        Config::set('permissions', self::PERMISSIONS);
        $context = new WidgetContext([PostResource::class], Period::last(30));
        $posts = $context->query(PostResource::class);
        self::assertSame(['draft' => 4, 'published' => 3], $posts->countBy('status'));
        self::assertSame(['draft' => 4], $posts->countBy('status', 1), 'the most first');
        self::assertSame(['published' => 3], $posts->where('status', '=', 'published')->countBy('status'));
        $period = $context->period();
        $days = $posts->countByDay('created_at', $period->from(), $period->to());
        self::assertSame(2, $days[date('Y-m-d')]);
        self::assertSame(2, $days[date('Y-m-d', strtotime('-1 day'))]);
        self::assertSame(1, $days[date('Y-m-d', strtotime('-2 days'))]);
        self::assertSame(5, array_sum($days));
        self::assertSame(5, array_sum($period->series($days)['values']));
        self::assertSame(2, $posts->where('title', 'NOT LIKE', '%Extra%')->count());
        self::assertSame(7, $posts->whereNotNull('created_at')->count());
        $this->expectException(\InvalidArgumentException::class);
        $posts->countByDay('created_at', 'yesterday', 'now');
    }
}
