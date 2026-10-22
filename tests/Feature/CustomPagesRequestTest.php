<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\PanelPages\ExchangeRates;
use FloCMS\Admin\Tests\Fixtures\PanelPages\GettingStarted;
use FloCMS\Admin\Tests\Fixtures\PanelPages\HiddenPage;
use FloCMS\Admin\Tests\Fixtures\PanelPages\SalesReport;

/** The site's own panel pages (0.36), through whole requests. */
final class CustomPagesRequestTest extends RequestTestCase
{
    /** Editors (role 1) get the report and the tool; Admins (2) also reports.view; Super Admin everything. */
    private const PAGE_PERMISSIONS = [0 => [], 1 => ['content.*', 'panel.sales-report', 'panel.exchange-rates'], 2 => ['content.*', 'panel.*', 'reports.view'], 3 => ['*']];

    protected function setUp(): void
    {
        parent::setUp();
        file_put_contents(self::$root . '/lang/ar.php', '<?php return ["lng.dir" => "rtl"];');
        @mkdir(self::$root . '/views/admin', 0777, true);
        file_put_contents(self::$root . '/views/admin/sales-report.html', '<div class="fa-card"><h2>{{ $heading }}</h2><p>Status: {{ $status }}</p></div>');
        file_put_contents(self::$root . '/views/admin/getting-started.html', '<ul>@foreach($steps ?? [] as $step)<li>{{ $step }}</li>@endforeach</ul>');
        @unlink(self::$root . '/storage/logs/app.log');
    }

    /** @param array<string, mixed> $options */
    private static function get(string $uri, array $options = []): array
    {
        return self::request('GET', $uri, self::options($options));
    }

    /** @param array<string, mixed> $options */
    private static function options(array $options = []): array
    {
        $config = ($options['admin_config'] ?? []) + ['admin_pages' => [SalesReport::class, ExchangeRates::class, GettingStarted::class, HiddenPage::class]];
        unset($options['admin_config']);
        return $options + ['admin_config' => $config, 'permissions' => self::PAGE_PERMISSIONS];
    }

    public function testReportShowsItsPartsInOrderInThePanel(): void
    {
        $response = self::get('/en/admin/sales-report');
        self::assertSame(200, $response['status'], $response['body']);
        $body = $response['body'];
        foreach (['<h1 class="fa-page-title">Sales report</h1>', 'Bookings and income by month', 'Email this report', 'name="filter[status]"', 'Page stats', 'filter:all', 'Monthly sales', 'sea-view-villa', 'Download CSV'] as $text) {
            self::assertStringContainsString($text, $body);
        }
        // Header, filters, widgets, view, table
        $positions = array_map(static fn (string $text): int => (int) strpos($body, $text), ['fa-page-head', 'fa-page-filters', 'Page stats', 'Monthly sales', 'fa-page-table']);
        $sorted = $positions;
        sort($sorted);
        self::assertSame($sorted, $positions);
        // The row links where table()->url() says; the action with its own permission is not shown.
        self::assertStringContainsString('href="/en/admin/posts/edit/1"', $body);
        self::assertStringNotContainsString('data-admin-action="secret"', $body);
        self::assertStringContainsString('aria-current="page"><svg', $body);
        self::assertMatchesRegularExpression('~<a class="fa-nav-link is-active" href="[^"]*/admin/sales-report"~', $body);
        // Breadcrumbs: the group, then the page.
        self::assertMatchesRegularExpression('~<li><span>Reports</span></li>\s*<li><span aria-current="page">Sales report</span></li>~', $body);
    }

    public function testFiltersReachTheViewTheTableAndWidgetsAndStayInLinks(): void
    {
        $response = self::get('/en/admin/sales-report?filter%5Bstatus%5D=draft');
        self::assertSame(200, $response['status']);
        self::assertStringContainsString('Status: draft', $response['body']);
        self::assertStringContainsString('filter:draft', $response['body']);
        self::assertStringContainsString('mountain-cabin', $response['body']);
        self::assertStringNotContainsString('sea-view-villa', $response['body']);
        // Sort links keep the filter; Reset is offered.
        self::assertMatchesRegularExpression('~href="[^"]*sales-report\?filter%5Bstatus%5D=draft[^"]*sort=slug~', $response['body']);
        self::assertStringContainsString('>Reset</a>', $response['body']);
        // A value the field refuses is shown as an error and left out.
        $bad = self::get('/en/admin/sales-report?filter%5Bstatus%5D=closed');
        self::assertSame(200, $bad['status']);
        self::assertStringContainsString('Status: all', $bad['body']);
        self::assertStringContainsString('has-error', $bad['body']);
    }

    public function testTableSearchSortPagesAndColumns(): void
    {
        $search = self::get('/en/admin/sales-report?q=mountain');
        self::assertStringContainsString('mountain-cabin', $search['body']);
        self::assertStringNotContainsString('sea-view-villa', $search['body']);
        $sorted = self::get('/en/admin/sales-report?sort=slug&dir=desc');
        self::assertLessThan(strpos($sorted['body'], 'mountain-cabin'), strpos($sorted['body'], 'sea-view-villa'));
        // A column not sortable is ignored (the default sort applies), never put into SQL.
        $ignored = self::get('/en/admin/sales-report?sort=title&dir=desc');
        self::assertSame(200, $ignored['status']);
        // Hide the status column for this person.
        $saved = self::post('/en/admin/sales-report', ['_columns' => 'apply', 'columns' => [], '_back' => 'https://example.test/en/admin/sales-report'], self::options());
        self::assertTrue($saved['redirected']);
        $hidden = self::get('/en/admin/sales-report', ['session' => $saved['session']]);
        self::assertStringNotContainsString('data-label="Status"', $hidden['body']);
        // Back to the defaults (on MySQL the preferences table outlives the test).
        self::post('/en/admin/sales-report', ['_columns' => 'apply', '_columns_reset' => '1', '_back' => 'https://example.test/en/admin/sales-report'], self::options(['session' => $saved['session']]));
    }

    public function testCsvDownloadHasTheVisibleColumnsAndTheFilters(): void
    {
        $csv = self::get('/en/admin/sales-report/csv?filter%5Bstatus%5D=published');
        self::assertSame(200, $csv['status']);
        self::assertStringContainsString('"Slug","Status","Title"', $csv['body']);
        self::assertStringContainsString('"sea-view-villa","published","Sea view villa"', $csv['body']);
        self::assertStringNotContainsString('mountain-cabin', $csv['body']);
        $off = self::get('/en/admin/sales-report/csv', ['admin_config' => ['export' => false]]);
        self::assertSame(404, $off['status']);
    }

    public function testPermissionsCanAccessAndUnknownAddresses(): void
    {
        self::assertSame(403, self::get('/en/admin/sales-report', ['permissions' => [1 => ['content.*']]])['status']);
        self::assertSame(403, self::get('/en/admin/sales-report/csv', ['permissions' => [1 => ['content.*']]])['status']);
        self::assertSame(403, self::get('/en/admin/getting-started')['status']);
        // HiddenPage: its own permission, and canAccess() refuses Ali (user 2).
        self::assertSame(403, self::get('/en/admin/hidden-tools', ['session' => self::ADMIN])['status']);
        self::assertSame(200, self::get('/en/admin/hidden-tools', ['session' => self::SUPER])['status']);
        self::assertSame(404, self::get('/en/admin/sales-report/route/nothing')['status']);
        self::assertSame(404, self::get('/en/admin/sales-report/action/nothing')['status']);
        self::assertSame(404, self::get('/en/admin/unknown-page')['status']);
        // Not listed in admin_pages: no page.
        self::assertSame(404, self::get('/en/admin/sales-report', ['admin_config' => ['admin_pages' => []]])['status']);
    }

    public function testSidebarGroupsBadgesAndHiddenPages(): void
    {
        $body = self::get('/en/admin/dashboard', ['session' => self::SUPER])['body'];
        // Content (resources, then pages without a group), then the groups by their lowest sort, then Site.
        $content = strpos($body, '>Content<');
        $help = strpos($body, '>Getting started<');
        $reports = strpos($body, '<p class="fa-nav-heading">Reports</p>');
        $tools = strpos($body, '<p class="fa-nav-heading">Tools</p>');
        $site = strpos($body, '<p class="fa-nav-heading">Site</p>');
        self::assertNotFalse($content);
        self::assertTrue($content < $help && $help < $reports && $reports < $tools && $tools < $site, $body);
        self::assertStringContainsString('<span class="fa-nav-badge">3</span>', $body);
        self::assertStringContainsString('<span class="fa-nav-badge">new</span>', $body);
        self::assertStringNotContainsString('Hidden tools', $body);
        // Editors see only their pages.
        $editor = self::get('/en/admin/dashboard')['body'];
        self::assertStringContainsString('Sales report', $editor);
        self::assertStringNotContainsString('Getting started', $editor);
    }

    public function testToolPageValidatesSavesAndLogs(): void
    {
        $page = self::get('/en/admin/exchange-rates');
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('data-width="narrow"', $page['body']);
        self::assertStringContainsString('value="1460"', $page['body']);
        self::assertStringContainsString('Save rates', $page['body']);
        self::assertStringContainsString('data-admin-record-form', $page['body']);

        $missing = self::post('/en/admin/exchange-rates', ['_page_form' => '1', 'rate' => ''], self::options());
        self::assertSame(200, $missing['status']);
        self::assertStringContainsString('has-error', $missing['body']);
        self::assertArrayNotHasKey('note', $missing['session']);

        $refused = self::post('/en/admin/exchange-rates', ['_page_form' => '1', 'rate' => '-2'], self::options());
        self::assertStringContainsString('Enter a rate above zero.', $refused['body']);

        $saved = self::post('/en/admin/exchange-rates', ['_page_form' => '1', 'rate' => '1500', 'note' => ['en' => 'Weekly', 'ar' => 'أسبوعي']], self::options());
        self::assertTrue($saved['redirected']);
        self::assertSame('1500', (string) $saved['session']['rate']);
        self::assertSame('أسبوعي', $saved['session']['note']['ar']);
        $after = self::get('/en/admin/exchange-rates', ['session' => $saved['session']]);
        self::assertStringContainsString('Rates saved.', $after['body']);
        self::assertStringContainsString('value="1500"', $after['body']);
        // Without CSRF nothing is saved.
        $forged = self::request('POST', '/en/admin/exchange-rates', self::options(['post' => ['_page_form' => '1', 'rate' => '9']]));
        self::assertNotSame(200, $forged['status']);
        self::assertArrayNotHasKey('rate', $forged['session']);
    }

    public function testHeaderActionsOpenRunAndCheckPermissions(): void
    {
        $open = self::get('/en/admin/sales-report/action/send?filter%5Bstatus%5D=draft');
        self::assertSame(200, $open['status']);
        self::assertStringContainsString('name="email"', $open['body']);
        preg_match('/name="_operation" value="([a-f0-9]+)"/', $open['body'], $token);
        self::assertNotEmpty($token[1] ?? '');
        $invalid = self::post('/en/admin/sales-report/action/send', ['_operation' => $token[1], 'email' => 'nope'], self::options(['session' => $open['session']]));
        self::assertSame(200, $invalid['status']);
        self::assertStringContainsString('has-error', $invalid['body']);
        $sent = self::post('/en/admin/sales-report/action/send?filter%5Bstatus%5D=draft', ['_operation' => $token[1], 'email' => 'boss@example.test'], self::options(['session' => $open['session']]));
        self::assertTrue($sent['redirected']);
        self::assertSame('boss@example.test sales-report', $sent['session']['report_sent']);
        // A used or forged operation is refused.
        self::assertSame(409, self::post('/en/admin/sales-report/action/send', ['_operation' => $token[1], 'email' => 'x@example.test'], self::options(['session' => $sent['session']]))['status']);
        // Runs at once from the header (a POST form with its operation).
        $page = self::get('/en/admin/sales-report');
        preg_match('~action="[^"]*/action/recount"><input type="hidden" name="_token" value="tok"[^>]*><input type="hidden" name="_operation" value="([a-f0-9]+)"~', $page['body'], $recount);
        self::assertNotEmpty($recount[1] ?? '', $page['body']);
        $ran = self::post('/en/admin/sales-report/action/recount', ['_operation' => $recount[1]], self::options(['session' => $page['session']]));
        self::assertSame(1, $ran['session']['recounted']);
        // The action's own permission.
        self::assertSame(403, self::get('/en/admin/sales-report/action/secret')['status']);
    }

    public function testDeclaredRoutesAnswerWithThePagePermission(): void
    {
        $feed = self::get('/en/admin/sales-report/route/feed?filter%5Bstatus%5D=published');
        self::assertSame(200, $feed['status']);
        self::assertSame(['ok' => true, 'filters' => ['status' => 'published']], json_decode($feed['body'], true));
        self::assertSame(403, self::get('/en/admin/sales-report/route/feed', ['permissions' => [1 => []]])['status']);
    }

    public function testSearchFindsPagesThePersonMayOpen(): void
    {
        $result = self::get('/en/admin/search?q=report', ['headers' => ['Accept' => 'application/json'], 'session' => self::SUPER]);
        self::assertStringContainsString('Sales report', $result['body']);
        $byGroup = self::get('/en/admin/search?q=tools', ['headers' => ['Accept' => 'application/json'], 'session' => self::SUPER]);
        self::assertStringContainsString('Exchange rates', $byGroup['body']);
        self::assertStringNotContainsString('Hidden tools', $byGroup['body']);
        $editor = self::get('/en/admin/search?q=getting', ['headers' => ['Accept' => 'application/json']]);
        self::assertStringNotContainsString('Getting started', $editor['body']);
    }

    public function testHomePageAndArabic(): void
    {
        self::assertSame('sales-report', self::homePath(SalesReport::class, self::EDITOR));
        self::assertSame('dashboard', self::homePath(SalesReport::class, ['admin_access' => true, 'isloggedin' => true, 'role' => 0, 'user_id' => 4]));
        $ar = self::get('/ar/admin/sales-report');
        self::assertSame(200, $ar['status']);
        self::assertStringContainsString('dir="rtl"', $ar['body']);
    }

    /** Admin::homePath() for a person, in a request process. */
    private static function homePath(string $home, array $session): string
    {
        $script = self::$root . '/home.php';
        file_put_contents($script, '<?php
define("ROOT", ' . var_export(self::$root, true) . ');
require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ';
\FloCMS\Core\Config::set("permissions", ' . var_export(self::PAGE_PERMISSIONS, true) . ');
$_SESSION = ' . var_export($session, true) . ';
\FloCMS\Admin\Admin::useConfig(["admin_pages" => [' . var_export($home, true) . '], "home" => ' . var_export($home, true) . '], true, ROOT);
echo \FloCMS\Admin\Admin::homePath();');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' 2>&1', $output);
        return implode("\n", $output);
    }

    public function testPanelNavigationReturnsTheShell(): void
    {
        $response = self::get('/en/admin/sales-report', ['headers' => ['X-Flo-Admin' => 'navigate']]);
        self::assertSame(200, $response['status']);
        self::assertStringContainsString('fa-custom-page', $response['body']);
    }
}
