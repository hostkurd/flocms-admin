<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

/**
 * The panel's own pages: dashboard, users management (/admin/accounts) and
 * the profile page, with the skeleton's role rules.
 *
 * Users in the fixture: 1 Sara (Editor), 2 Ali (Admin), 3 Root (Super Admin), 4 Dara (User).
 */
final class AdminAreaTest extends RequestTestCase
{
    private static function user(int $id): ?array
    {
        return self::row('users', $id);
    }

    private static function userCount(): int
    {
        return (int) self::pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /* ---------------------------------------------------------------
     * Dashboard
     * ------------------------------------------------------------- */

    public function testDashboardShowsTheResourcesTheUserMayView(): void
    {
        $body = self::request('GET', '/en/admin/dashboard')['body'];

        self::assertStringContainsString('Welcome, Sara.', $body);
        self::assertMatchesRegularExpression('#href="https://example.test/en/admin/posts">.*?<span class="fa-stat-value">2</span>#s', $body);
        self::assertStringNotContainsString('/en/admin/post-tags', $body, 'editors lack taxonomy.tags.view');
        self::assertStringNotContainsString('/en/admin/accounts', $body, 'editors lack users.manage');
        self::assertMatchesRegularExpression('#class="fa-nav-link is-active" href="https://example.test/en/admin/dashboard"#', $body);
    }

    public function testDashboardShowsUsersToAdmins(): void
    {
        $body = self::request('GET', '/en/admin/dashboard', ['session' => self::ADMIN])['body'];

        self::assertMatchesRegularExpression('#href="https://example.test/en/admin/accounts">.*?<span class="fa-stat-value">4</span>#s', $body);
        self::assertStringContainsString('href="https://example.test/en/admin/profile"', $body, 'the name in the top bar opens the profile');
    }

    public function testDashboardNeedsLogin(): void
    {
        self::assertTrue(self::request('GET', '/admin/dashboard', ['session' => []])['redirected']);
    }

    /* ---------------------------------------------------------------
     * Users management
     * ------------------------------------------------------------- */

    public function testUsersNeedUsersManage(): void
    {
        self::assertSame(403, self::request('GET', '/admin/accounts')['status']);
        self::assertSame(200, self::request('GET', '/admin/accounts', ['session' => self::ADMIN])['status']);
    }

    public function testAdminsOnlyManageLowerRoles(): void
    {
        $body = self::request('GET', '/en/admin/accounts', ['session' => self::ADMIN])['body'];

        self::assertStringContainsString('Root', $body);
        self::assertStringContainsString('/en/admin/accounts/edit/1"', $body, 'Editor: lower role');
        self::assertStringContainsString('/en/admin/accounts/edit/4"', $body, 'User: lower role');
        self::assertStringNotContainsString('/en/admin/accounts/edit/3"', $body, 'Super Admin: higher role');
        self::assertStringNotContainsString('/en/admin/accounts/edit/2"', $body, 'yourself: same role');
        self::assertStringNotContainsString('/en/admin/accounts/delete/2"', $body);

        self::assertSame(403, self::request('GET', '/admin/accounts/edit/3', ['session' => self::ADMIN])['status']);
        self::assertSame(403, self::post('/admin/accounts/delete/3', [], ['session' => self::ADMIN])['status']);
        self::assertNotNull(self::user(3));
    }

    public function testRoleOptionsStopAtYourOwnRole(): void
    {
        $body = self::request('GET', '/admin/accounts/create', ['session' => self::ADMIN])['body'];

        self::assertStringContainsString('<option value="2">Admin</option>', $body);
        self::assertStringNotContainsString('<option value="3">Super Admin</option>', $body);
    }

    public function testCreateUser(): void
    {
        $result = self::post('/admin/accounts/create', [
            'full_name' => 'New Editor',
            'email' => 'new@example.test',
            'login' => '',
            'password' => 'long-enough-1',
            'role' => '1',
            'status' => '1',
            'phone' => '',
        ], ['session' => self::ADMIN]);

        self::assertTrue($result['redirected']);
        $row = self::user(5);
        self::assertSame('new@example.test', $row['login'], 'login defaults to the email');
        self::assertTrue(password_verify('long-enough-1', $row['password']));
        self::assertSame('1', (string) $row['role']);
        self::assertSame('1', (string) $row['is_verified']);
        self::assertSame('2', (string) $row['verify_type']);
    }

    public function testCreateUserChecks(): void
    {
        $result = self::post('/admin/accounts/create', [
            'full_name' => 'X',
            'email' => 'sara@example.test',
            'password' => 'short',
            'role' => '3',
            'status' => '1',
        ], ['session' => self::ADMIN]);

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('This Email is already used.', $result['body']);
        self::assertStringContainsString('Password must be at least 8 characters.', $result['body']);
        self::assertStringContainsString('Role must be one of the listed values.', $result['body']);
        self::assertStringNotContainsString('value="short"', $result['body'], 'passwords are never sent back');
        self::assertSame(4, self::userCount());

        $missing = self::post('/admin/accounts/create', ['full_name' => 'Y', 'email' => 'y@example.test', 'password' => '', 'role' => '0', 'status' => '1'], ['session' => self::ADMIN]);
        self::assertStringContainsString('Password is required.', $missing['body']);
    }

    public function testEditKeepsThePasswordWhenLeftEmpty(): void
    {
        $before = self::user(1)['password'];
        $form = self::request('GET', '/admin/accounts/edit/1', ['session' => self::ADMIN])['body'];
        self::assertStringContainsString('value="Sara"', $form);
        self::assertStringNotContainsString($before, $form);

        $result = self::post('/admin/accounts/edit/1', [
            'full_name' => 'Sara K',
            'email' => 'sara@example.test',
            'login' => 'sara@example.test',
            'password' => '',
            'role' => '0',
            'status' => '3',
        ], ['session' => self::ADMIN]);

        self::assertTrue($result['redirected']);
        $row = self::user(1);
        self::assertSame('Sara K', $row['full_name']);
        self::assertSame($before, $row['password']);
        self::assertSame('3', (string) $row['status'], 'suspended');
    }

    public function testNobodyCanChangeTheirOwnRoleOrSuspendThemselves(): void
    {
        $result = self::post('/admin/accounts/edit/3', [
            'full_name' => 'Root',
            'email' => 'root@example.test',
            'login' => 'root@example.test',
            'password' => '',
            'role' => '1',
            'status' => '3',
        ], ['session' => self::SUPER]);

        self::assertSame(200, $result['status']);
        self::assertStringContainsString('You cannot change your own role.', $result['body']);
        self::assertStringContainsString('You cannot suspend your own account.', $result['body']);
        self::assertSame('3', (string) self::user(3)['role']);

        self::assertSame(403, self::post('/admin/accounts/delete/3', [], ['session' => self::SUPER])['status']);
        self::assertNotNull(self::user(3));
    }

    public function testSuperAdminsManageEveryoneElse(): void
    {
        $result = self::post('/admin/accounts/delete/2', [], ['session' => self::SUPER]);

        self::assertTrue($result['redirected']);
        self::assertNull(self::user(2));
    }

    public function testUsersCanBeSwitchedOff(): void
    {
        $config = ['resources' => [\FloCMS\Admin\Tests\Fixtures\Resources\PostResource::class], 'users' => false];

        self::assertSame(404, self::request('GET', '/admin/accounts', ['session' => self::SUPER, 'admin_config' => $config])['status']);
        self::assertSame(404, self::request('GET', '/admin/profile', ['session' => self::SUPER, 'admin_config' => $config])['status']);
        self::assertSame(200, self::request('GET', '/admin/dashboard', ['session' => self::SUPER, 'admin_config' => $config])['status']);
    }

    /* ---------------------------------------------------------------
     * Profile
     * ------------------------------------------------------------- */

    public function testProfileShowsYourDetails(): void
    {
        $body = self::request('GET', '/en/admin/profile', ['session' => self::ADMIN])['body'];

        self::assertStringContainsString('My profile', $body);
        self::assertStringContainsString('value="Ali"', $body);
        self::assertStringContainsString('<div class="fa-static" id="f-role_info">Admin</div>', $body);
        self::assertStringNotContainsString('name="role"', $body, 'the role is not editable');
    }

    public function testProfileNameChangeNeedsNoPassword(): void
    {
        $result = self::post('/admin/profile', ['full_name' => 'Ali B', 'email' => 'ali@example.test', 'login' => 'ali@example.test', 'password' => '', 'current_password' => ''], ['session' => self::ADMIN]);

        self::assertTrue($result['redirected']);
        self::assertSame('Ali B', self::user(2)['full_name']);
        self::assertSame('Ali B', $result['session']['fullname'], 'the top bar shows the new name at once');
    }

    public function testProfileEmailAndPasswordNeedTheCurrentPassword(): void
    {
        $data = ['full_name' => 'Ali', 'email' => 'ali2@example.test', 'login' => 'ali@example.test', 'password' => 'new-password-2'];

        $none = self::post('/admin/profile', $data + ['current_password' => ''], ['session' => self::ADMIN]);
        self::assertStringContainsString('Enter your current password', $none['body']);

        $wrong = self::post('/admin/profile', $data + ['current_password' => 'nope'], ['session' => self::ADMIN]);
        self::assertStringContainsString('The current password is not correct.', $wrong['body']);
        self::assertSame('ali@example.test', self::user(2)['email']);

        $right = self::post('/admin/profile', $data + ['current_password' => self::PASSWORD], ['session' => self::ADMIN]);
        self::assertTrue($right['redirected']);
        self::assertSame('ali2@example.test', self::user(2)['email']);
        self::assertTrue(password_verify('new-password-2', self::user(2)['password']));
        self::assertSame('ali2@example.test', $right['session']['email']);
    }

    public function testProfileRefusesAnEmailUsedByAnotherUser(): void
    {
        $result = self::post('/admin/profile', ['full_name' => 'Ali', 'email' => 'root@example.test', 'login' => '', 'password' => '', 'current_password' => self::PASSWORD], ['session' => self::ADMIN]);

        self::assertStringContainsString('This Email is already used.', $result['body']);
    }
}
