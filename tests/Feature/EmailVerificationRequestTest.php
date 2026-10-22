<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Console\Stub;

/**
 * Email verification (0.35) through whole requests: the banner and its link,
 * a changed address that waits for its link, invitations, the optional
 * "Check your email" step and admins changing an address.
 */
final class EmailVerificationRequestTest extends RequestTestCase
{
    private const ENV = ['APP_KEY' => 'test-app-key-0123456789-abcdefghijklmnop', 'MAIL_TRANSPORT' => 'log', 'MAIL_FROM' => 'panel@example.test', 'APP_URL' => 'https://example.test'];
    private const TABLES = ['admin_two_factor', 'admin_two_factor_roles', 'admin_trusted_devices', 'admin_email_tokens', 'admin_trash', 'admin_activity', 'admin_password_resets'];
    private const GUEST = ['_csrf_token' => 'tok'];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        foreach (self::TABLES as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        foreach ([
            ['login-security-migration', ['two_factor_table' => 'admin_two_factor', 'roles_table' => 'admin_two_factor_roles', 'devices_table' => 'admin_trusted_devices', 'tokens_table' => 'admin_email_tokens']],
            ['trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']],
            ['password-resets-migration', ['table' => 'admin_password_resets']],
        ] as [$stub, $values]) {
            $file = self::$root . '/' . $stub . '.php';
            file_put_contents($file, Stub::render($stub, $values));
            (require $file)->up(new \FloCMS\Core\Database($pdo));
        }
        // Sara, Ali and Root are confirmed; Dara (4) is not.
        $pdo->exec('UPDATE users SET is_verified = 1 WHERE id IN (1, 2, 3)');
        @unlink(self::$root . '/storage/logs/admin-mail.log');
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) { self::pdo()->exec('DROP TABLE IF EXISTS ' . $table); }
        parent::tearDown();
    }

    /** @param array<string, mixed> $settings */
    private static function on(array $settings = [], array $extra = []): array
    {
        return ['admin_config' => ['email_verification' => $settings === [] ? true : $settings, 'password_reset' => true], 'env' => self::ENV, 'user_loader' => true,
            'config' => ['admin_access_roles' => ['1', '2', '3']]] + $extra;
    }

    /** @return list<string> */
    private static function mails(): array
    {
        $file = self::$root . '/storage/logs/admin-mail.log';
        return array_values(array_filter(explode('==== ', is_file($file) ? (string) file_get_contents($file) : '')));
    }

    private static function linkFrom(string $mail): string
    {
        self::assertSame(1, preg_match('#https://example\.test/en/admin/(verify/link/[A-Za-z0-9_-]{43})#', $mail, $match), $mail);
        return '/en/admin/' . $match[1];
    }

    /** Ali (Admin) with an unconfirmed address. */
    private static function unverifiedAli(): array
    {
        self::pdo()->exec('UPDATE users SET is_verified = 0 WHERE id = 2');
        return self::ADMIN;
    }

    public function testAnUnconfirmedAddressShowsTheBannerAndItsLinkConfirmsIt(): void
    {
        $session = self::unverifiedAli();
        $page = self::request('GET', '/en/admin/dashboard', ['session' => $session] + self::on());
        self::assertStringContainsString('Confirm your email address.', $page['body']);
        self::assertStringContainsString('al•••@example.test', $page['body']);

        $sent = self::post('/en/admin/profile/security', ['_action' => 'send_verification'], ['session' => $session] + self::on());
        self::assertTrue($sent['redirected']);
        $mails = self::mails();
        self::assertCount(1, $mails);
        self::assertStringContainsString('To: ali@example.test', $mails[0]);
        self::assertStringContainsString('Confirm your email address for Test Panel', $mails[0]);
        $link = self::linkFrom($mails[0]);
        $token = substr($link, -43);
        $stored = self::pdo()->query('SELECT token_hash FROM admin_email_tokens')->fetchColumn();
        self::assertSame(hash('sha256', $token), $stored, 'only the hash is stored');

        $opened = self::request('GET', $link, ['session' => self::GUEST] + self::on());
        self::assertSame(200, $opened['status']);
        self::assertStringContainsString('Email address confirmed', $opened['body']);
        self::assertSame('1', (string) self::row('users', 2)['is_verified']);

        $again = self::request('GET', $link, ['session' => self::GUEST] + self::on());
        self::assertStringContainsString('This link has expired', $again['body'], 'a link works once');
        $after = self::request('GET', '/en/admin/dashboard', ['session' => $session] + self::on());
        self::assertStringNotContainsString('Confirm your email address.', $after['body']);
    }

    public function testANewAddressWaitsForItsLink(): void
    {
        $data = ['full_name' => 'Ali', 'email' => 'ali.new@example.test', 'login' => 'ali@example.test', 'password' => '', 'current_password' => self::PASSWORD];
        $saved = self::post('/en/admin/profile', $data, ['session' => self::ADMIN] + self::on());
        self::assertTrue($saved['redirected'], $saved['body']);
        self::assertSame('ali@example.test', self::row('users', 2)['email'], 'not changed yet');

        $mails = self::mails();
        self::assertCount(2, $mails);
        self::assertStringContainsString('To: ali.new@example.test', $mails[0]);
        self::assertStringContainsString('To: ali@example.test', $mails[1]);
        self::assertStringContainsString('is changing', $mails[1]);

        $security = self::request('GET', '/en/admin/profile/security', ['session' => self::ADMIN] + self::on());
        self::assertStringContainsString('ali.new@example.test', $security['body']);
        self::assertStringContainsString('Waiting for confirmation', $security['body']);

        $opened = self::request('GET', self::linkFrom($mails[0]), ['session' => self::GUEST] + self::on());
        self::assertStringContainsString('is now the address on your account', $opened['body']);
        self::assertSame('ali.new@example.test', self::row('users', 2)['email']);
        self::assertContains('email_changed', self::pdo()->query('SELECT action FROM admin_activity')->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testACancelledChangeIsNoLongerApplied(): void
    {
        $data = ['full_name' => 'Ali', 'email' => 'ali.new@example.test', 'login' => 'ali@example.test', 'password' => '', 'current_password' => self::PASSWORD];
        self::post('/en/admin/profile', $data, ['session' => self::ADMIN] + self::on());
        $link = self::linkFrom(self::mails()[0]);
        self::post('/en/admin/profile/security', ['_action' => 'cancel_change'], ['session' => self::ADMIN] + self::on());

        $opened = self::request('GET', $link, ['session' => self::GUEST] + self::on());
        self::assertStringContainsString('This link has expired', $opened['body']);
        self::assertSame('ali@example.test', self::row('users', 2)['email']);
    }

    public function testAnInvitationKeepsTheAccountPendingUntilAPasswordIsChosen(): void
    {
        $form = self::request('GET', '/en/admin/accounts/create', ['session' => self::ADMIN] + self::on());
        self::assertStringContainsString('Send an invitation', $form['body']);

        $created = self::post('/en/admin/accounts/create', ['full_name' => 'Lana', 'email' => 'lana@example.test', 'login' => '', 'phone' => '', 'invite' => '1', 'password' => '', 'role' => '1', 'status' => '1', '_close' => '1'],
            ['session' => $form['session']] + self::on());
        self::assertNull($created['error']);
        $user = self::pdo()->query("SELECT * FROM users WHERE email = 'lana@example.test'")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($user, $created['body']);
        self::assertSame('2', (string) $user['status'], 'Pending');
        self::assertSame('0', (string) $user['is_verified']);
        $mails = self::mails();
        self::assertCount(1, $mails);
        self::assertStringContainsString("You've been invited to Test Panel", $mails[0]);
        self::assertStringContainsString('Ali made you an account', $mails[0]);

        $link = self::linkFrom($mails[0]);
        $page = self::request('GET', $link, ['session' => self::GUEST] + self::on());
        self::assertStringContainsString('Welcome, Lana', $page['body']);
        self::assertStringContainsString('name="password"', $page['body']);
        $short = self::post($link, ['password' => 'short', 'password_confirmation' => 'short'], ['session' => self::GUEST] + self::on());
        self::assertSame(422, $short['status']);
        $done = self::post($link, ['password' => 'lana-pass-123', 'password_confirmation' => 'lana-pass-123'], ['session' => self::GUEST] + self::on());
        self::assertTrue($done['redirected']);

        $user = self::row('users', (int) $user['id']);
        self::assertSame('1', (string) $user['status']);
        self::assertSame('1', (string) $user['is_verified']);
        self::assertTrue(password_verify('lana-pass-123', $user['password']));
    }

    public function testWithoutAnInvitationThePasswordIsNeeded(): void
    {
        $form = self::request('GET', '/en/admin/accounts/create', ['session' => self::ADMIN] + self::on());
        $created = self::post('/en/admin/accounts/create', ['full_name' => 'Omar', 'email' => 'omar@example.test', 'login' => '', 'phone' => '', 'invite' => '0', 'password' => '', 'role' => '1', 'status' => '1'],
            ['session' => $form['session']] + self::on());
        self::assertSame(0, (int) self::pdo()->query("SELECT COUNT(*) FROM users WHERE email = 'omar@example.test'")->fetchColumn());
        self::assertStringContainsString('fa-error', $created['body']);
    }

    public function testAnAdminChangingAnAddressClearsItsConfirmation(): void
    {
        $edit = self::request('GET', '/en/admin/accounts/edit/1', ['session' => self::ADMIN] + self::on());
        self::post('/en/admin/accounts/edit/1', ['full_name' => 'Sara', 'email' => 'sara.k@example.test', 'login' => 'sara@example.test', 'phone' => '', 'role' => '1', 'status' => '1', 'password' => '', '_close' => '1'],
            ['session' => $edit['session']] + self::on());
        $sara = self::row('users', 1);
        self::assertSame('sara.k@example.test', $sara['email']);
        self::assertSame('0', (string) $sara['is_verified']);
    }

    public function testAnAdminCanSendAVerificationEmail(): void
    {
        $page = self::request('GET', '/en/admin/accounts/action/row/verify_link/4', ['session' => self::ADMIN] + self::on());
        self::assertSame(200, $page['status'], $page['body']);
        self::assertSame(1, preg_match('/name="_operation" value="([^"]+)"/', $page['body'], $match));
        $done = self::post('/en/admin/accounts/action/row/verify_link/4', ['_operation' => $match[1]], ['session' => $page['session']] + self::on());
        self::assertNull($done['error']);
        self::assertStringContainsString('To: dara@example.test', implode('', self::mails()));
        // Confirmed addresses don't get one.
        self::assertSame(403, self::request('GET', '/en/admin/accounts/action/row/verify_link/1', ['session' => self::ADMIN] + self::on())['status']);
    }

    public function testRequiredVerificationHoldsTheSignInAtCheckYourEmail(): void
    {
        $session = self::unverifiedAli();
        $held = self::request('GET', '/en/admin/dashboard', ['session' => $session] + self::on(['required' => true]))['session'];
        self::assertSame('verify', $held['auth_held']['path']);

        $page = self::request('GET', '/en/admin/verify', ['session' => $held] + self::on(['required' => true]));
        self::assertStringContainsString('Check your email', $page['body']);
        $sent = self::post('/en/admin/verify/send', [], ['session' => $held] + self::on(['required' => true]));
        self::assertTrue($sent['redirected']);
        $link = self::linkFrom(self::mails()[0]);

        $opened = self::request('GET', $link, ['session' => $sent['session']] + self::on(['required' => true]));
        self::assertStringContainsString('Email address confirmed', $opened['body']);
        self::assertTrue($opened['session']['isloggedin'], 'the held sign-in goes on');
        self::assertArrayNotHasKey('auth_held', $opened['session']);
    }

    public function testAPasswordResetConfirmsTheAddress(): void
    {
        self::pdo()->exec('UPDATE users SET is_verified = 0 WHERE id = 1');
        self::post('/en/admin/password/forgot', ['email' => 'sara@example.test'], ['session' => self::GUEST] + self::on());
        self::assertSame(1, preg_match('#/admin/password/reset/([A-Za-z0-9_-]{43})#', self::mails()[0], $match));
        self::post('/en/admin/password/reset/' . $match[1], ['password' => 'new-pass-1234', 'password_confirmation' => 'new-pass-1234'], ['session' => self::GUEST] + self::on());
        self::assertSame('1', (string) self::row('users', 1)['is_verified']);
    }

    public function testLinksAreLimitedPerHour(): void
    {
        $session = self::unverifiedAli();
        for ($i = 0; $i < 3; $i++) {
            self::post('/en/admin/profile/security', ['_action' => 'send_verification'], ['session' => $session] + self::on());
        }
        $fourth = self::post('/en/admin/profile/security', ['_action' => 'send_verification'], ['session' => $session] + self::on());
        self::assertCount(3, self::mails());
        self::assertStringContainsString('Too many links', (string) ($fourth['session']['__flash']['message'] ?? ''));
    }
}
