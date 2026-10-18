<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Roles\PermissionCatalogue;
use FloCMS\Admin\Roles\Role;
use FloCMS\Admin\Roles\RoleEditor;
use FloCMS\Admin\Roles\Roles;
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\Fixtures\Resources\TagResource;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Config;
use FloCMS\Core\Database;

/**
 * The Roles page's rules (0.21) without HTTP.
 */
final class RolesUnitTest extends TestCase
{
    private ?Database $previous = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previous = App::$db;
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC]);
        $pdo->exec('CREATE TABLE admin_roles (role INTEGER NOT NULL PRIMARY KEY, name TEXT NOT NULL, level INTEGER NOT NULL, admin_access SMALLINT NOT NULL DEFAULT 0, permissions TEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, full_name TEXT, email TEXT, role INTEGER, status INTEGER)');
        $pdo->exec("INSERT INTO users (full_name, email, role, status) VALUES ('Root', 'root@example.test', 3, 1), ('Sara', 'sara@example.test', 1, 1)");
        App::$db = new Database($pdo);
        Config::set('roles', [0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin']);
        Config::set('permissions', [0 => [], 1 => ['content.*'], 2 => ['content.*', 'users.manage', 'users.assign_role', 'settings.*'], 3 => ['*']]);
        Config::set('admin_access_roles', ['1', '2', '3']);
        Admin::useConfig(['resources' => [PostResource::class, TagResource::class], 'users' => true, 'activity' => false, 'trash' => false], true, sys_get_temp_dir());
        $_SESSION = ['role' => 3, 'admin_access' => true, 'isloggedin' => true, 'user_id' => 1];
    }

    protected function tearDown(): void
    {
        App::$db = $this->previous;
        $_SESSION = [];
        Config::set('admin_access_roles', null);
        Config::set('roles', null);
        Roles::reset();
        parent::tearDown();
    }

    private function store(int $role, array $names, int $level, bool $access, array $permissions): void
    {
        App::$db->table('admin_roles')->insert(['role' => $role, 'name' => json_encode($names), 'level' => $level, 'admin_access' => $access ? 1 : 0,
            'permissions' => json_encode($permissions), 'created_at' => '2026-10-18 10:00:00', 'updated_at' => '2026-10-18 10:00:00']);
        Roles::reset();
    }

    public function testPatterns(): void
    {
        foreach (['*', 'users.manage', 'content.*', 'content.home-dialogs.view', 'a_b.c-d'] as $good) { self::assertTrue(Roles::validPattern($good), $good); }
        foreach (['', 'a..b', '.a', 'a.', 'content*', '*.x', 'a.*.b', 'a b', 'a/b', str_repeat('a', 121)] as $bad) { self::assertFalse(Roles::validPattern($bad), $bad); }
        self::assertSame(['a.b', 'c.*'], Roles::cleanPatterns(['a.b', ' a.b ', 'c.*', 'bad pattern', 7, null]));
        self::assertTrue(Roles::matches('content.*', 'content.posts.view'));
        self::assertFalse(Roles::matches('content.*', 'contentx.view'));
        self::assertTrue(Roles::matches('*', 'anything'));
    }

    public function testStatus(): void
    {
        self::assertSame('ready', Roles::status());
        Config::set('admin_access_roles', null);
        Roles::reset();
        self::assertSame('unsupported', Roles::status());
        Config::set('admin_access_roles', ['1', '2', '3']);
        Admin::useConfig(['roles' => false], true, sys_get_temp_dir());
        self::assertSame('off', Roles::status());
        Admin::useConfig([], true, sys_get_temp_dir());
        App::$db->query('DROP TABLE admin_roles');
        Roles::reset();
        self::assertSame('missing', Roles::status());
    }

    public function testWithoutRowsCoreKeepsTheConfigRoles(): void
    {
        self::assertNull(Roles::definitions());
        self::assertSame([0 => 'User', 1 => 'Editor', 2 => 'Admin', 3 => 'Super Admin'], Auth::roles());
    }

    public function testStoredRowsReachCore(): void
    {
        $this->store(1, ['en' => 'Writer', 'ar' => 'كاتب'], 1, true, ['content.posts.view']);
        $this->store(4, ['en' => 'Author'], 1, false, ['content.posts.*']);

        $definitions = Roles::definitions();
        self::assertNotNull($definitions);
        self::assertSame('Writer', $definitions['roles'][1]);
        self::assertSame(['content.posts.*'], $definitions['permissions'][4]);
        self::assertSame(1, $definitions['levels'][4]);
        self::assertNotContains('4', $definitions['admin_access_roles']);
        self::assertContains('1', $definitions['admin_access_roles']);

        $_SESSION['role'] = 1;
        self::assertTrue(Auth::can('content.posts.view'));
        self::assertFalse(Auth::can('content.tags.view'), 'content.* replaced by the stored role');
        self::assertSame('Author', Auth::roles()[4]);
        self::assertSame(1, Auth::level(4));
        self::assertFalse(Auth::hasAdminAccess(4));

        $roles = Roles::all();
        self::assertSame(Role::SOURCE_CHANGED, $roles[1]->source);
        self::assertSame(Role::SOURCE_CUSTOM, $roles[4]->source);
        self::assertSame(Role::SOURCE_CONFIG, $roles[2]->source);
        self::assertSame([3, 2, 4, 1, 0], array_keys($roles), 'Highest level first');
    }

    public function testABrokenTableFallsBackToTheConfig(): void
    {
        $this->store(1, ['en' => 'Writer'], 1, true, []);
        App::$db->query('DROP TABLE admin_roles');
        App::$db->query('CREATE TABLE admin_roles (role INTEGER)');
        Roles::reset();
        $_SESSION['role'] = 1;
        self::assertTrue(Auth::can('content.posts.view'));
    }

    public function testTopRoleAndLevels(): void
    {
        self::assertSame(3, Roles::topRole()?->number);
        self::assertTrue(Roles::isLocked(Roles::find(3)));
        self::assertTrue(Roles::canEdit(Roles::find(2)));
        self::assertTrue(Roles::canCreate());
        self::assertSame(4, Roles::nextNumber());

        // An Admin with roles.manage: only roles below level 2, never their own
        Config::set('permissions', [1 => ['content.*'], 2 => ['content.*', 'roles.manage'], 3 => ['*']]);
        Roles::reset();
        $_SESSION['role'] = 2;
        self::assertFalse(Roles::canEdit(Roles::find(2)));
        self::assertFalse(Roles::canEdit(Roles::find(3)));
        self::assertTrue(Roles::canEdit(Roles::find(1)));
    }

    public function testHolds(): void
    {
        $_SESSION['role'] = 2;
        self::assertTrue(Roles::holds('content.posts.view'));
        self::assertTrue(Roles::holds('content.*'));
        self::assertTrue(Roles::holds('content.posts.*'), 'covered by content.*');
        self::assertTrue(Roles::holds('settings.*'));
        self::assertFalse(Roles::holds('users.*'), 'only users.manage and users.assign_role');
        self::assertFalse(Roles::holds('*'));
        self::assertFalse(Roles::holds('users.manage_any'));
        $_SESSION['role'] = 3;
        self::assertTrue(Roles::holds('*'));
    }

    public function testSubmittedPermissions(): void
    {
        $editor = Roles::find(1);
        // A group switch keeps the wildcard only; Edit brings View; invalid names are dropped
        self::assertSame(['content.*', 'reports.export'], RoleEditor::permissionsFrom($editor, ['perm' => ['content.*', 'content.posts.view'], 'perm_add' => 'reports.export a..b']));
        self::assertEqualsCanonicalizing(['taxonomy.tags.update', 'taxonomy.tags.view'], RoleEditor::permissionsFrom($editor, ['perm' => ['taxonomy.tags.update']]));

        // What the current user doesn't hold stays as it was
        $_SESSION['role'] = 2;
        $role = new Role(1, 'Editor', 1, true, ['content.*', 'billing.refund'], Role::SOURCE_CONFIG);
        self::assertEqualsCanonicalizing(['billing.refund', 'content.posts.view'], RoleEditor::permissionsFrom($role, ['perm' => ['content.posts.view', 'users.manage_any']]));
    }

    public function testWildcardsYouDontHoldKeepTheirNames(): void
    {
        $_SESSION['role'] = 2;
        $role = new Role(1, 'Editor', 1, true, ['users.*', 'users.manage'], Role::SOURCE_CONFIG);
        self::assertEqualsCanonicalizing(['users.*', 'users.manage'], RoleEditor::permissionsFrom($role, ['perm' => ['users.manage']]));
    }

    public function testCatalogue(): void
    {
        $groups = PermissionCatalogue::groups();
        $keys = array_column($groups, 'key');
        self::assertSame(['content', 'media', 'settings', 'users', 'system'], $keys);
        $names = PermissionCatalogue::names($groups);
        foreach (['content.posts.view', 'taxonomy.tags.create', 'settings.delete', 'content.media.create', 'settings.panel', 'users.manage_any', 'roles.manage'] as $name) {
            self::assertArrayHasKey($name, $names, $name);
        }
        self::assertSame(['content.*' => true, 'settings.*' => true], PermissionCatalogue::wildcards($groups));
        self::assertSame('Posts → Edit', PermissionCatalogue::describe('content.posts.update', $groups));
        self::assertSame('All content', PermissionCatalogue::describe('content.*', $groups));
        self::assertSame('reports.export', PermissionCatalogue::describe('reports.export', $groups));

        Admin::useConfig(['resources' => [PostResource::class], 'roles' => ['permissions' => ['reports.export' => 'Export reports', 'bad name' => 'x']]], true, sys_get_temp_dir());
        $site = array_values(array_filter(PermissionCatalogue::groups(), static fn (array $g): bool => $g['key'] === 'site'));
        self::assertCount(1, $site);
        self::assertSame([['name' => 'reports.export', 'label' => 'Export reports', 'sub' => '']], $site[0]['items']);
    }

    public function testLockoutCheckCountsActiveUsers(): void
    {
        self::assertTrue(Roles::someoneCanManage(Roles::all()));
        App::$db->query('UPDATE users SET status = 3 WHERE role = 3');
        self::assertFalse(Roles::someoneCanManage(Roles::all()));
        // Users moving to a role that may manage roles count
        App::$db->query('UPDATE users SET status = 1 WHERE role = 1');
        self::assertTrue(Roles::someoneCanManage(Roles::all(), [1 => 3]));
    }
}
