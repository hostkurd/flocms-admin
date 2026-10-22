<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Http\CustomPageController;
use FloCMS\Admin\PanelPages\PageFilters;
use FloCMS\Admin\PanelPages\PageForm;
use FloCMS\Admin\Tests\Fixtures\PanelPages\BrokenDefinition;
use FloCMS\Admin\Tests\Fixtures\PanelPages\ExchangeRates;
use FloCMS\Admin\Tests\Fixtures\PanelPages\GettingStarted;
use FloCMS\Admin\Tests\Fixtures\PanelPages\HiddenPage;
use FloCMS\Admin\Tests\Fixtures\PanelPages\NewsPage;
use FloCMS\Admin\Tests\Fixtures\PanelPages\PostsPage;
use FloCMS\Admin\Tests\Fixtures\PanelPages\SalesReport;
use FloCMS\Admin\Tests\Fixtures\PanelPages\SettingsClash;
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\Config;

require_once dirname(__DIR__) . '/Fixtures/PanelPages/BadPages.php';

/** The panel pages API (0.36) without HTTP. */
final class CustomPagesUnitTest extends TestCase
{
    private function useConfig(array $pages, array $extra = []): void
    {
        Admin::useConfig($extra + ['resources' => [PostResource::class], 'admin_pages' => $pages], true, dirname(__DIR__, 2));
    }

    public function testDefinitionDefaults(): void
    {
        self::assertSame('getting-started', GettingStarted::getSlug());
        self::assertSame('Getting started', GettingStarted::getLabel());
        self::assertSame('panel.getting-started', GettingStarted::permission());
        self::assertNull(GettingStarted::getGroup());
        self::assertSame('file', GettingStarted::getIcon());
        self::assertSame('default', GettingStarted::getWidth());
        self::assertSame('reports.view', HiddenPage::permission());
        self::assertSame('Save rates', ExchangeRates::getSubmitLabel());
        self::assertSame([], SalesReport::configurationErrors());
        $errors = implode("\n", BrokenDefinition::configurationErrors());
        self::assertStringContainsString('width "huge" is not valid', $errors);
        self::assertStringContainsString('route "Bad-Route" is not valid', $errors);
    }

    public function testRegistryKeepsUsablePagesAndExplainsTheOthers(): void
    {
        $this->useConfig([SalesReport::class, PostsPage::class, SettingsClash::class, NewsPage::class, BrokenDefinition::class, 'App\\Admin\\Pages\\Missing', SalesReport::class, ExchangeRates::class]);
        $registry = Admin::panelPages();
        self::assertSame(['sales-report' => SalesReport::class, 'exchange-rates' => ExchangeRates::class], $registry->all());
        $problems = implode("\n", $registry->problems());
        self::assertStringContainsString('slug "posts" is used by the resource', $problems);
        self::assertStringContainsString('slug "settings" is one of the panel\'s own pages', $problems);
        self::assertStringContainsString('taken by the application controller FloCMS\\Controllers\\NewsController', $problems);
        self::assertStringContainsString('width "huge"', $problems);
        self::assertStringContainsString('expected the file admin/Pages/Missing.php (create it with: php flo make:admin-page Missing)', $problems);
        self::assertStringContainsString('listed twice', $problems);
        self::assertSame(\FloCMS\Admin\Http\CustomPageController::class, Admin::resolve('sales-report', 'admin'));
        self::assertSame(\FloCMS\Admin\Http\ResourceController::class, Admin::resolve('posts', 'admin'));
        self::assertNull(Admin::resolve('sales-report', 'default'));
        self::assertSame(SalesReport::class, $registry->findClass('\\' . SalesReport::class));
    }

    public function testHomeFollowsThePermission(): void
    {
        Config::set('permissions', [1 => ['panel.sales-report'], 2 => []]);
        $this->useConfig([SalesReport::class], ['home' => SalesReport::class]);
        $_SESSION = ['admin_access' => true, 'isloggedin' => true, 'role' => 1, 'user_id' => 1];
        self::assertSame('sales-report', Admin::homePath());
        $_SESSION['role'] = 2;
        self::assertSame('dashboard', Admin::homePath());
        $this->useConfig([], ['home' => SalesReport::class]);
        $_SESSION['role'] = 1;
        self::assertSame('dashboard', Admin::homePath());
        $_SESSION = [];
    }

    public function testRolesGridHasAPanelPagesGroup(): void
    {
        $this->useConfig([SalesReport::class, ExchangeRates::class, HiddenPage::class]);
        $groups = array_column(\FloCMS\Admin\Roles\PermissionCatalogue::groups(), null, 'key');
        self::assertArrayHasKey('panel_pages', $groups);
        self::assertSame('panel.*', $groups['panel_pages']['wildcard']);
        self::assertSame(['reports.view', 'panel.sales-report', 'panel.exchange-rates'], array_column($groups['panel_pages']['items'], 'name'));
        self::assertSame('Panel pages → Sales report', \FloCMS\Admin\Roles\PermissionCatalogue::describe('panel.sales-report'));
    }

    public function testFiltersReadValidValuesFromTheAddress(): void
    {
        $page = new SalesReport();
        $filters = new PageFilters($page, ['status' => 'draft', 'min_views' => '']);
        self::assertSame(['status' => 'draft'], $filters->values());
        self::assertSame(['status' => 'draft'], $filters->active());
        self::assertStringContainsString('name="filter[status]"', $filters->render());
        $bad = new PageFilters($page, ['status' => 'closed', 'min_views' => 'many']);
        self::assertSame([], $bad->values());
        self::assertStringContainsString('has-error', $bad->render());
        self::assertFalse((new PageFilters(new ExchangeRates(), []))->exists());
    }

    public function testFormValuesAndValidation(): void
    {
        $_SESSION = [];
        $form = new PageForm(new ExchangeRates());
        self::assertSame('1460', $form->initialState()['rate']);
        self::assertSame('Daily', $form->initialState()['note']['en']);
        $result = $form->submit(['rate' => '0']);
        self::assertFalse($result['saved']);
        self::assertSame('Enter a rate above zero.', $result['errors']['rate']);
        $result = $form->submit(['rate' => '12.5', 'note' => ['en' => 'x', 'ar' => 'y']]);
        self::assertTrue($result['saved']);
        self::assertSame('Rates saved.', $result['message']);
        self::assertSame(['en' => 'x', 'ar' => 'y', 'ku' => ''], $_SESSION['note']);
        $_SESSION = [];
    }

    public function testViewNamesStayInTheViewsFolder(): void
    {
        self::assertNull(CustomPageController::viewFile('../config/admin'));
        self::assertNull(CustomPageController::viewFile('admin/missing'));
        self::assertNull(CustomPageController::viewFile('/etc/passwd'));
    }

    public function testRowLinksAcceptOnlyWebAddresses(): void
    {
        self::assertTrue(\FloCMS\Admin\PanelPages\PageTable::safeUrl('/en/admin/posts/edit/1'));
        self::assertTrue(\FloCMS\Admin\PanelPages\PageTable::safeUrl('https://example.test/x'));
        self::assertFalse(\FloCMS\Admin\PanelPages\PageTable::safeUrl('javascript:alert(1)'));
        self::assertFalse(\FloCMS\Admin\PanelPages\PageTable::safeUrl('//evil.test'));
    }
}
