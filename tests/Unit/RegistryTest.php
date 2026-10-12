<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Http\ResourceController;
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\Fixtures\Resources\TagResource;
use FloCMS\Admin\Tests\TestCase;

final class RegistryTest extends TestCase
{
    private function useFixtures(array $resources = []): void
    {
        Admin::useConfig([
            'resources' => $resources,
            'resources_path' => dirname(__DIR__) . '/Fixtures/Resources',
            'resources_namespace' => 'FloCMS\\Admin\\Tests\\Fixtures\\Resources',
        ], true, dirname(__DIR__, 2));
    }

    public function testDiscoveryLoadsUsableResourcesAndReportsTheOthers(): void
    {
        $this->useFixtures();
        $registry = Admin::registry();

        self::assertSame(['posts' => PostResource::class, 'post-tags' => TagResource::class], $registry->all());

        $problems = implode("\n", $registry->problems());
        self::assertStringContainsString('slug "news" is taken by the application controller FloCMS\\Controllers\\NewsController', $problems);
        self::assertStringContainsString('slug "Bad_Slug" is not valid', $problems);
    }

    public function testAnExplicitListLimitsTheResources(): void
    {
        $this->useFixtures([TagResource::class, 'App\\Missing\\Resource']);

        self::assertSame(['post-tags' => TagResource::class], Admin::registry()->all());
        self::assertSame(['App\\Missing\\Resource: class not found'], Admin::registry()->problems());
    }

    public function testReservedSlugsAndDuplicates(): void
    {
        $this->useFixtures([PostResource::class, PostResource::class]);

        self::assertStringContainsString('already used by', Admin::registry()->problems()[0]);
    }

    public function testResolverOnlyAnswersAdminRoutesForRegisteredSlugs(): void
    {
        $this->useFixtures();

        self::assertSame(ResourceController::class, Admin::resolve('posts', 'admin'));
        self::assertSame(ResourceController::class, Admin::resolve('post-tags', 'admin'));
        self::assertNull(Admin::resolve('posts', 'default'));
        self::assertNull(Admin::resolve('unknown', 'admin'));
        self::assertNull(Admin::resolve('news', 'admin'), 'colliding slug is skipped');
    }

    public function testWithoutConfigFileTheResolverStaysOutOfTheWay(): void
    {
        Admin::useConfig([], false, sys_get_temp_dir());

        self::assertNull(Admin::resolve('posts', 'admin'));
    }

    public function testConfigIsReadFromTheProjectRoot(): void
    {
        $root = sys_get_temp_dir() . '/flo-admin-root-' . bin2hex(random_bytes(4));
        mkdir($root . '/config', 0777, true);
        file_put_contents($root . '/config/admin.php', "<?php return ['brand' => 'Panel', 'per_page' => 10, 'permission_prefix' => 'cms'];");

        Admin::setRoot($root);
        self::assertTrue(Admin::config()->isInstalled());
        self::assertSame('Panel', Admin::config()->brand());
        self::assertSame(10, Admin::config()->perPage());
        self::assertSame($root . '/admin/Resources', Admin::config()->resourcesPath());
        self::assertSame('cms.posts.update', PostResource::permission('update'));

        unlink($root . '/config/admin.php');
        rmdir($root . '/config');
        rmdir($root);
    }

    public function testPermissionNames(): void
    {
        self::assertSame('content.posts.view', PostResource::permission('view'));
        self::assertSame('content.posts.delete', PostResource::permission('delete'));
        self::assertSame('taxonomy.tags.update', TagResource::permission('update'));
        self::assertSame('settings.delete', TagResource::permission('delete'));
    }

    public function testUrls(): void
    {
        self::assertMatchesRegularExpression('#/admin/posts/edit/5$#', Admin::url('posts/edit/5'));
        self::assertStringEndsWith('/admin/posts?q=a%20b&page=2', Admin::url('posts', ['q' => 'a b', 'sort' => null, 'page' => 2]));
    }

    public function testPackagePagesAreReserved(): void
    {
        $this->useFixtures([\FloCMS\Admin\Tests\Fixtures\Reserved\DashboardResource::class]);

        self::assertStringContainsString('slug "dashboard" is reserved', implode("\n", Admin::registry()->problems()));
        self::assertSame(\FloCMS\Admin\Http\DashboardController::class, Admin::resolve('dashboard', 'admin'));
    }

    public function testUsersAndProfileFollowTheUsersSetting(): void
    {
        $this->useFixtures([TagResource::class]);
        self::assertSame(\FloCMS\Admin\Users\UsersResource::class, Admin::registry()->find('accounts'));
        self::assertSame(ResourceController::class, Admin::resolve('accounts', 'admin'));
        self::assertSame(\FloCMS\Admin\Http\ProfileController::class, Admin::resolve('profile', 'admin'));
        self::assertArrayNotHasKey('accounts', Admin::registry()->all(), 'built-in resources are not listed under Content');

        Admin::useConfig(['resources' => [TagResource::class], 'users' => false], true, dirname(__DIR__, 2));
        self::assertNull(Admin::registry()->find('accounts'));
        self::assertNull(Admin::resolve('accounts', 'admin'));
        self::assertNull(Admin::resolve('profile', 'admin'));
    }

    public function testAdminHomeSetting(): void
    {
        $home = \FloCMS\Core\Config::get('admin.home');
        self::assertIsCallable($home, 'set by bootstrap.php when Composer loads the package');

        Admin::useConfig([], true, sys_get_temp_dir());
        self::assertSame('dashboard', $home());

        Admin::useConfig([], false, sys_get_temp_dir());
        self::assertNull($home(), 'without config/admin.php the skeleton keeps its own /admin page');
    }
}
