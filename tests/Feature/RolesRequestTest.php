<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use PDO;

/**
 * The Roles page (0.21) through whole requests: the list, editing, creating,
 * resetting and deleting roles, the safety rules, and flocms-core reading the
 * stored roles (Auth::loadRolesUsing) on the next request.
 */
final class RolesRequestTest extends RequestTestCase
{
    /** config/config.php of the skeleton: admin_access_roles is what the page needs besides permissions. */
    private const CONFIG = ['admin_access_roles' => ['1', '2', '3']];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS admin_roles');
        self::createTable($pdo, 'CREATE TABLE admin_roles (role INTEGER NOT NULL PRIMARY KEY, name TEXT NOT NULL, level INTEGER NOT NULL,
            admin_access SMALLINT NOT NULL DEFAULT 0, permissions TEXT NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL)');
        $pdo->exec('DROP TABLE IF EXISTS admin_activity');
        self::createTable($pdo, 'CREATE TABLE admin_activity (id INTEGER PRIMARY KEY AUTOINCREMENT, action VARCHAR(40) NOT NULL, resource VARCHAR(64) NULL,
            record_id VARCHAR(191) NULL, title TEXT NULL, user_id INTEGER NULL, user_name VARCHAR(190) NULL, changes TEXT NULL, meta TEXT NULL,
            batch CHAR(32) NULL, created_at DATETIME NOT NULL)');
    }

    /** @param array<string, mixed> $options */
    private static function as(array $session, array $options = []): array
    {
        return ['session' => $session, 'config' => ($options['config'] ?? []) + self::CONFIG] + $options;
    }

    /** @return array<string, mixed>|null */
    private static function stored(int $role): ?array
    {
        $row = self::pdo()->query('SELECT * FROM admin_roles WHERE role = ' . $role)->fetch(PDO::FETCH_ASSOC);
        if ($row === false) { return null; }
        $row['permissions'] = json_decode((string) $row['permissions'], true);
        $row['name'] = json_decode((string) $row['name'], true);
        return $row;
    }

    private static function storeRole(int $role, array $names, int $level, bool $access, array $permissions): void
    {
        $insert = self::pdo()->prepare('INSERT INTO admin_roles (role, name, level, admin_access, permissions, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
        $insert->execute([$role, json_encode($names), $level, $access ? 1 : 0, json_encode($permissions), '2026-10-18 10:00:00', '2026-10-18 10:00:00']);
    }

    public function testOnlyRolesManageOpensThePage(): void
    {
        self::assertSame(403, self::request('GET', '/en/admin/roles', self::as(self::EDITOR))['status']);
        self::assertSame(403, self::request('GET', '/en/admin/roles', self::as(self::ADMIN))['status']);

        $page = self::request('GET', '/en/admin/roles', self::as(self::SUPER));
        self::assertSame(200, $page['status']);
        foreach (['Super Admin', 'Admin', 'Editor', 'User', 'From config', 'Locked', 'Everything (*)'] as $text) {
            self::assertStringContainsString($text, $page['body']);
        }
        self::assertStringContainsString('href="https://example.test/en/admin/roles"', $page['body'], 'Sidebar link');
        self::assertStringContainsString('href="https://example.test/en/admin/roles/create"', $page['body']);

        $editor = self::request('GET', '/en/admin/dashboard', self::as(self::EDITOR));
        self::assertStringNotContainsString('/admin/roles', $editor['body']);
    }

    public function testNoticeWithoutTableOrConfigMaps(): void
    {
        self::pdo()->exec('DROP TABLE admin_roles');
        $missing = self::request('GET', '/en/admin/roles', self::as(self::SUPER));
        self::assertSame(200, $missing['status']);
        self::assertStringContainsString('php flo admin:install', $missing['body']);

        $unsupported = self::request('GET', '/en/admin/roles', ['session' => self::SUPER]);
        self::assertStringContainsString('flocms-core 2.4', $unsupported['body']);

        self::assertSame(404, self::request('GET', '/en/admin/roles', self::as(self::SUPER, ['admin_config' => ['roles' => false]]))['status']);
    }

    public function testEditingARoleChangesWhatItsUsersMay(): void
    {
        $form = self::request('GET', '/en/admin/roles/edit/1', self::as(self::SUPER));
        self::assertSame(200, $form['status']);
        self::assertStringContainsString('All content', $form['body']);
        self::assertStringContainsString('value="content.posts.update"', $form['body']);

        // Before: Editors have content.* (posts), not the tags (taxonomy.tags.*)
        self::assertSame(200, self::request('GET', '/en/admin/posts', self::as(self::EDITOR))['status']);
        self::assertSame(403, self::request('GET', '/en/admin/post-tags', self::as(self::EDITOR))['status']);

        // Editors get the tags only: All content off
        $saved = self::post('/en/admin/roles/edit/1', ['name' => ['en' => 'Editor'], 'level' => '1', 'admin_access' => '1',
            'perm' => ['taxonomy.tags.view', 'taxonomy.tags.update']], self::as(self::SUPER));
        self::assertTrue($saved['redirected']);
        $row = self::stored(1);
        self::assertNotNull($row);
        self::assertSame(['taxonomy.tags.view', 'taxonomy.tags.update'], $row['permissions']);

        // flocms-core reads the stored role on the next request
        self::assertSame(403, self::request('GET', '/en/admin/posts', self::as(self::EDITOR))['status']);
        self::assertSame(200, self::request('GET', '/en/admin/post-tags', self::as(self::EDITOR))['status']);

        $list = self::request('GET', '/en/admin/roles', self::as(self::SUPER));
        self::assertStringContainsString('From config · changed', $list['body']);
    }

    public function testTickingEditAlsoGivesView(): void
    {
        self::post('/en/admin/roles/edit/1', ['name' => ['en' => 'Editor'], 'level' => '1', 'admin_access' => '1',
            'perm' => ['content.posts.update', 'taxonomy.tags.create']], self::as(self::SUPER));
        self::assertEqualsCanonicalizing(['content.posts.update', 'content.posts.view', 'taxonomy.tags.create', 'taxonomy.tags.view'], self::stored(1)['permissions']);
    }

    public function testGroupSwitchKeepsOnlyTheWildcard(): void
    {
        self::post('/en/admin/roles/edit/1', ['name' => ['en' => 'Writer', 'ar' => 'كاتب'], 'level' => '1', 'admin_access' => '1',
            'perm' => ['content.*', 'content.posts.view', 'settings.manage'], 'perm_add' => 'reports.export, pattern*, a..b'], self::as(self::SUPER));
        $row = self::stored(1);
        self::assertEqualsCanonicalizing(['content.*', 'settings.manage', 'reports.export'], $row['permissions']);
        self::assertSame(['en' => 'Writer', 'ar' => 'كاتب'], $row['name']);
    }

    public function testSavingTheConfigValuesBackRemovesTheChange(): void
    {
        self::storeRole(1, ['en' => 'Editor'], 1, true, ['content.posts.view']);
        self::post('/en/admin/roles/edit/1', ['name' => ['en' => 'Editor'], 'level' => '1', 'admin_access' => '1', 'perm' => ['content.*']], self::as(self::SUPER));
        self::assertNull(self::stored(1));
    }

    public function testTheTopRoleAndOwnRoleAreLocked(): void
    {
        self::assertSame(403, self::post('/en/admin/roles/edit/3', ['name' => ['en' => 'Boss'], 'level' => '1', 'admin_access' => '1', 'perm' => []], self::as(self::SUPER))['status']);
        self::assertNull(self::stored(3));

        $read = self::request('GET', '/en/admin/roles/edit/3', self::as(self::SUPER));
        self::assertSame(200, $read['status']);
        self::assertStringContainsString('The top role is locked', $read['body']);
        self::assertStringNotContainsString('name="_save"', $read['body']);

        // An Admin who may manage roles: own role and higher are read-only, lower ones editable
        $permissions = self::PERMISSIONS;
        $permissions[2][] = 'roles.manage';
        $admin = self::as(self::ADMIN, ['permissions' => $permissions]);
        self::assertSame(403, self::post('/en/admin/roles/edit/2', ['name' => ['en' => 'Admin'], 'level' => '1', 'admin_access' => '1', 'perm' => ['*']], $admin)['status']);
        self::assertTrue(self::post('/en/admin/roles/edit/1', ['name' => ['en' => 'Editor'], 'level' => '1', 'admin_access' => '1', 'perm' => ['content.posts.view']], $admin)['redirected']);
        self::assertSame(['content.posts.view'], self::stored(1)['permissions']);
    }

    public function testNobodyGivesWhatTheyDontHold(): void
    {
        $permissions = self::PERMISSIONS;
        $permissions[2][] = 'roles.manage';
        $permissions[1][] = 'reports.export';
        $admin = self::as(self::ADMIN, ['permissions' => $permissions]);

        self::post('/en/admin/roles/edit/1', ['name' => ['en' => 'Editor'], 'level' => '1', 'admin_access' => '1',
            'perm' => ['content.posts.view', 'users.manage_any', 'activity.view', '*']], $admin);
        $row = self::stored(1);
        self::assertNotNull($row);
        self::assertNotContains('users.manage_any', $row['permissions'], 'The Admin does not hold it');
        self::assertNotContains('*', $row['permissions']);
        self::assertNotContains('activity.view', $row['permissions']);
        self::assertContains('reports.export', $row['permissions'], 'Patterns the Admin does not hold stay as they were');

        $form = self::request('GET', '/en/admin/roles/edit/1', $admin);
        self::assertMatchesRegularExpression('/value="users\.manage_any"[^>]*disabled/', $form['body']);
    }

    public function testCreatingARoleAndUsingIt(): void
    {
        $created = self::post('/en/admin/roles/create', ['name' => ['en' => 'Author', 'ku' => 'نووسەر'], 'level' => '1', 'admin_access' => '1',
            'perm' => ['content.posts.view', 'content.posts.create']], self::as(self::SUPER));
        self::assertTrue($created['redirected']);
        $row = self::stored(4);
        self::assertNotNull($row, 'The next free number');
        self::assertSame(1, (int) $row['level']);
        self::assertSame(1, (int) $row['admin_access']);

        $author = ['role' => 4, 'user_id' => 4] + self::EDITOR;
        self::assertSame(200, self::request('GET', '/en/admin/posts', self::as($author))['status']);
        self::assertSame(403, self::request('GET', '/en/admin/post-tags', self::as($author))['status']);

        $list = self::request('GET', '/en/admin/roles', self::as(self::SUPER));
        self::assertStringContainsString('Author', $list['body']);
        self::assertStringContainsString('Custom', $list['body']);
        self::assertStringContainsString('نووسەر', self::request('GET', '/ku/admin/roles', self::as(self::SUPER))['body']);

        // The Users page offers the new role
        $users = self::request('GET', '/en/admin/accounts/edit/4', self::as(self::SUPER));
        self::assertMatchesRegularExpression('/<option value="4"[^>]*>Author</', $users['body']);
    }

    public function testLevelMustStayBelowYours(): void
    {
        $result = self::post('/en/admin/roles/create', ['name' => ['en' => 'Boss'], 'level' => '3', 'admin_access' => '1', 'perm' => []], self::as(self::SUPER));
        self::assertFalse($result['redirected']);
        self::assertNull(self::stored(4));
        self::assertStringContainsString('fa-field', $result['body']);
    }

    public function testDeletingARoleMovesItsUsers(): void
    {
        self::storeRole(4, ['en' => 'Author'], 1, true, ['content.posts.view']);
        self::pdo()->exec('UPDATE users SET role = 4 WHERE id = 4');

        $page = self::request('GET', '/en/admin/roles/delete/4', self::as(self::SUPER));
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('1 user has this role', $page['body']);

        $refused = self::post('/en/admin/roles/delete/4', ['move_to' => '4'], self::as(self::SUPER));
        self::assertNotNull(self::stored(4), 'A role cannot take its own users');
        self::assertTrue($refused['redirected']);

        self::post('/en/admin/roles/delete/4', ['move_to' => '1'], self::as(self::SUPER));
        self::assertNull(self::stored(4));
        self::assertSame(1, (int) self::row('users', 4)['role']);

        // Config roles are reset, never deleted
        self::assertSame(403, self::post('/en/admin/roles/delete/1', ['move_to' => '0'], self::as(self::SUPER))['status']);
    }

    public function testResetGivesTheConfigValuesBack(): void
    {
        self::storeRole(1, ['en' => 'Writer'], 1, true, ['content.posts.view']);
        self::assertStringContainsString('Writer', self::request('GET', '/en/admin/roles', self::as(self::SUPER))['body']);
        self::post('/en/admin/roles/reset/1', [], self::as(self::SUPER));
        self::assertNull(self::stored(1));
    }

    public function testASaveThatShutsEveryoneOutIsRefused(): void
    {
        // Root (the only Super Admin) is suspended: nobody active could manage roles afterwards
        self::pdo()->exec('UPDATE users SET status = 3 WHERE id = 3');
        $result = self::post('/en/admin/roles/edit/1', ['name' => ['en' => 'Editor'], 'level' => '1', 'admin_access' => '1', 'perm' => ['content.posts.view']], self::as(self::SUPER));
        self::assertFalse($result['redirected']);
        self::assertNull(self::stored(1));
        self::assertStringContainsString('nobody active could open the panel', $result['body']);
    }

    public function testChangesAreLogged(): void
    {
        self::post('/en/admin/roles/edit/1', ['name' => ['en' => 'Editor'], 'level' => '1', 'admin_access' => '1',
            'perm' => ['content.posts.view', 'settings.manage']], self::as(self::SUPER));
        $entry = self::pdo()->query("SELECT * FROM admin_activity WHERE action = 'role_updated'")->fetch(PDO::FETCH_ASSOC);
        self::assertNotFalse($entry);
        self::assertSame('roles', $entry['resource']);
        self::assertSame('1', (string) $entry['record_id']);
        self::assertStringContainsString('Posts → View', (string) $entry['changes']);
        self::assertStringContainsString('All content', (string) $entry['changes'], 'Taken away');

        $activity = self::request('GET', '/en/admin/activity', self::as(self::SUPER));
        self::assertStringContainsString('Role changed', $activity['body']);
    }

    public function testCtrlKFindsRoles(): void
    {
        $result = self::request('GET', '/en/admin/search?q=edit', self::as(self::SUPER, ['headers' => ['Accept' => 'application/json']]));
        self::assertSame(200, $result['status']);
        $data = json_decode($result['body'], true);
        $titles = [];
        foreach ($data['groups'] as $group) { foreach ($group['items'] as $item) { $titles[] = $group['label'] . ':' . $item['title']; } }
        self::assertContains('Roles:Editor', $titles);
    }
    public function testResetCannotGiveBackMoreThanYouHold(): void
    {
        // A Super Admin lowered Admin (config level 2) to level 0; an Editor who may manage roles must not reset it
        self::storeRole(2, ['en' => 'Admin'], 0, true, ['content.posts.view']);
        $permissions = self::PERMISSIONS;
        $permissions[1] = ['content.*', 'roles.manage'];
        $editor = self::as(self::EDITOR, ['permissions' => $permissions]);

        $page = self::request('GET', '/en/admin/roles', $editor);
        self::assertStringNotContainsString('/admin/roles/reset/2', $page['body']);
        self::post('/en/admin/roles/reset/2', [], $editor);
        self::assertNotNull(self::stored(2), 'still lowered');
        self::assertSame(0, (int) self::stored(2)['level']);
    }

    public function testDeleteNeedsATargetBelowYourLevelAndLeavesNobodyBehind(): void
    {
        self::storeRole(4, ['en' => 'Author'], 1, true, ['content.posts.view']);
        self::pdo()->exec('UPDATE users SET role = 4 WHERE id = 4');

        // No target: refused, the role and its user stay
        self::post('/en/admin/roles/delete/4', [], self::as(self::SUPER));
        self::assertNotNull(self::stored(4));
        // The Super Admin's own level is not a target (strictly below)
        self::post('/en/admin/roles/delete/4', ['move_to' => '3'], self::as(self::SUPER));
        self::assertNotNull(self::stored(4));
        self::assertSame(4, (int) self::row('users', 4)['role']);

        // The number of a deleted role is not reused while a user still holds it
        self::pdo()->exec('UPDATE users SET role = 9 WHERE id = 4');
        self::storeRole(8, ['en' => 'Eight'], 1, true, []);
        self::post('/en/admin/roles/create', ['name' => ['en' => 'Next'], 'level' => '1', 'admin_access' => '1', 'perm' => []], self::as(self::SUPER));
        self::assertNull(self::stored(9), 'users hold 9');
        self::assertNotNull(self::stored(10));
    }

    public function testCoveredBoxesSurviveTurningTheSwitchOffWithoutJavaScript(): void
    {
        $form = self::request('GET', '/en/admin/roles/edit/1', self::as(self::SUPER));
        self::assertStringContainsString('<input type="hidden" name="perm[]" value="content.posts.view" data-role-keep>', $form['body']);
        // The browser sends the hidden copies, not the disabled boxes; the switch is unticked
        self::post('/en/admin/roles/edit/1', ['name' => ['en' => 'Editor'], 'level' => '1', 'admin_access' => '1',
            'perm' => ['content.posts.view', 'content.posts.create', 'content.posts.update', 'content.posts.delete', 'content.media.view']], self::as(self::SUPER));
        self::assertEqualsCanonicalizing(['content.posts.view', 'content.posts.create', 'content.posts.update', 'content.posts.delete', 'content.media.view'], self::stored(1)['permissions']);
    }

    public function testAnUnreadableUsersTableRefusesTheSave(): void
    {
        self::pdo()->exec('ALTER TABLE users RENAME TO users_away');
        try {
            $result = self::post('/en/admin/roles/edit/1', ['name' => ['en' => 'Editor'], 'level' => '1', 'admin_access' => '1', 'perm' => ['content.posts.view']], self::as(self::SUPER));
            self::assertFalse($result['redirected']);
            self::assertNull(self::stored(1));
            self::assertStringContainsString('users table could not be read', $result['body']);
        } finally {
            self::pdo()->exec('ALTER TABLE users_away RENAME TO users');
        }
    }
}
