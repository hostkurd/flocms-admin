<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Auth\PasswordStamp;
use FloCMS\Admin\Console\Stub;

/**
 * Forgot password (0.26) through whole requests: the request form, the same
 * answer for every address, the limits, the one-time link, the new password
 * and the end of the account's other sessions. Emails go to the "log" transport.
 */
final class PasswordResetRequestTest extends RequestTestCase
{
    private const GUEST = ['_csrf_token' => 'tok'];
    private const ON = ['admin_config' => ['password_reset' => true, 'login_theme' => true], 'env' => ['MAIL_TRANSPORT' => 'log', 'MAIL_FROM' => 'panel@example.test']];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        foreach (['admin_password_resets', 'admin_trash', 'admin_activity'] as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        foreach ([['password-resets-migration', ['table' => 'admin_password_resets']], ['trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']]] as [$stub, $values]) {
            $file = self::$root . '/' . $stub . '.php';
            file_put_contents($file, Stub::render($stub, $values));
            (require $file)->up(new \FloCMS\Core\Database($pdo));
        }
        @unlink(self::mailLog());
    }

    protected function tearDown(): void
    {
        $pdo = self::pdo();
        foreach (['admin_password_resets', 'admin_trash', 'admin_activity'] as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        parent::tearDown();
    }

    private static function mailLog(): string
    {
        return self::$root . '/storage/logs/admin-mail.log';
    }

    /** @return list<string> */
    private static function mails(): array
    {
        $log = is_file(self::mailLog()) ? (string) file_get_contents(self::mailLog()) : '';
        return array_values(array_filter(explode('==== ', $log)));
    }

    private static function tokenFrom(string $mail): string
    {
        self::assertSame(1, preg_match('#/admin/password/reset/([A-Za-z0-9_-]{43})#', $mail, $match), $mail);
        return $match[1];
    }

    private static function ask(string $email, array $options = []): array
    {
        return self::post('/en/admin/password/forgot', ['email' => $email], $options + ['session' => self::GUEST] + self::ON);
    }

    /** @return list<array<string, mixed>> */
    private static function activity(): array
    {
        return self::pdo()->query('SELECT * FROM admin_activity ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
    }

    public function testGuestsReachTheRequestForm(): void
    {
        $page = self::request('GET', '/en/admin/password/forgot', ['session' => self::GUEST] + self::ON);

        self::assertSame(200, $page['status']);
        self::assertFalse($page['redirected']);
        self::assertStringContainsString('<!doctype html>', $page['body']);
        self::assertStringContainsString('Reset your password', $page['body']);
        self::assertStringContainsString('name="email"', $page['body']);
        self::assertStringContainsString('noindex', $page['body']);
    }

    public function testOtherAdminPagesStillNeedASignIn(): void
    {
        $page = self::request('GET', '/en/admin/password/other', ['session' => self::GUEST] + self::ON);
        self::assertTrue($page['redirected']);
        $dashboard = self::request('GET', '/en/admin/dashboard', ['session' => self::GUEST] + self::ON);
        self::assertTrue($dashboard['redirected']);
    }

    public function testAKnownAddressGetsALinkAndTheSameAnswerAsAnUnknownOne(): void
    {
        $known = self::ask('Sara@Example.test');
        $unknown = self::ask('nobody@example.test', ['ip' => '198.51.100.9']);

        self::assertTrue($known['redirected']);
        self::assertTrue($unknown['redirected']);
        self::assertSame('Sara@Example.test', $known['session']['admin_reset_sent']);
        self::assertSame('nobody@example.test', $unknown['session']['admin_reset_sent']);

        $mails = self::mails();
        self::assertCount(1, $mails);
        self::assertStringContainsString('To: sara@example.test', $mails[0]);
        self::assertStringContainsString('Reset your Test Panel password', $mails[0]);
        self::assertStringContainsString('expires in 60 minutes', $mails[0]);
        $token = self::tokenFrom($mails[0]);

        $rows = self::pdo()->query('SELECT * FROM admin_password_resets ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(2, $rows);
        self::assertSame(hash('sha256', $token), $rows[0]['token_hash'], 'only the hash of the token is stored');
        self::assertNull($rows[1]['token_hash']);
        foreach ($rows as $row) {
            self::assertStringNotContainsString('example.test', implode('|', array_map('strval', $row)));
            self::assertStringNotContainsString('203.0.113.7', implode('|', array_map('strval', $row)));
        }

        $sent = self::request('GET', '/en/admin/password/forgot/sent', ['session' => $known['session']] + self::ON);
        self::assertStringContainsString('Check your email', $sent['body']);
        self::assertStringContainsString('Sara@Example.test', $sent['body']);

        $activity = self::activity();
        self::assertSame(['password_reset_requested', 'password_reset_requested'], array_column($activity, 'action'));
        self::assertSame('1', (string) $activity[0]['user_id']);
        self::assertSame('Sara', $activity[0]['user_name']);
        self::assertNull($activity[1]['user_id']);
        self::assertStringContainsString('unknown', (string) $activity[1]['meta']);
    }

    public function testAnInvalidAddressIsAnsweredOnTheForm(): void
    {
        $page = self::ask('not-an-email');

        self::assertSame(422, $page['status']);
        self::assertStringContainsString('Enter a valid email address.', $page['body']);
        self::assertStringContainsString('aria-invalid="true"', $page['body']);
        self::assertSame(0, (int) self::pdo()->query('SELECT COUNT(*) FROM admin_password_resets')->fetchColumn());
    }

    public function testRequestsAreLimitedPerAddressAndPerIp(): void
    {
        for ($i = 0; $i < 5; $i++) {
            self::assertTrue(self::ask('sara@example.test', ['ip' => '198.51.100.' . $i])['redirected']);
        }
        self::assertCount(3, self::mails(), 'three emails per address per hour');
        self::assertSame(1, count(array_filter(self::activity(), static fn (array $row): bool => $row['action'] === 'password_reset_limited')), 'logged once');

        for ($i = 0; $i < 11; $i++) {
            self::ask('person' . $i . '@example.test', ['ip' => '192.0.2.50']);
        }
        self::ask('ali@example.test', ['ip' => '192.0.2.50']);
        self::assertCount(3, self::mails(), 'no email from an IP over its limit');
        self::ask('ali@example.test', ['ip' => '192.0.2.51']);
        self::assertCount(4, self::mails());
    }

    public function testInactiveAccountsGetNoEmail(): void
    {
        self::pdo()->exec('UPDATE users SET status = 3 WHERE id = 1');
        self::assertTrue(self::ask('sara@example.test')['redirected']);
        self::assertSame([], self::mails());
        self::assertStringContainsString('inactive', (string) self::activity()[0]['meta']);
    }

    public function testTheLinkOpensTheNewPasswordForm(): void
    {
        self::ask('sara@example.test');
        $token = self::tokenFrom(self::mails()[0]);

        $page = self::request('GET', '/en/admin/password/reset/' . $token, ['session' => self::GUEST] + self::ON);
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Choose a new password', $page['body']);
        self::assertStringContainsString('sara@example.test', $page['body']);
        self::assertStringContainsString('autocomplete="new-password"', $page['body']);

        foreach (['', 'short', str_repeat('A', 43), substr($token, 0, 42) . '!'] as $wrong) {
            $invalid = self::request('GET', '/en/admin/password/reset/' . rawurlencode($wrong), ['session' => self::GUEST] + self::ON);
            self::assertStringContainsString('This link no longer works', $invalid['body'], $wrong);
        }
    }

    public function testTheNewPasswordIsChecked(): void
    {
        self::ask('sara@example.test');
        $token = self::tokenFrom(self::mails()[0]);
        $url = '/en/admin/password/reset/' . $token;

        $short = self::post($url, ['password' => 'short', 'password_confirmation' => 'short'], ['session' => self::GUEST] + self::ON);
        self::assertSame(422, $short['status']);
        self::assertStringContainsString('Use at least 8 characters.', $short['body']);

        $mismatch = self::post($url, ['password' => 'correct-horse', 'password_confirmation' => 'correct-hors'], ['session' => self::GUEST] + self::ON);
        self::assertSame(422, $mismatch['status']);
        self::assertStringContainsString('The two passwords don’t match.', $mismatch['body']);
        self::assertTrue(password_verify(self::PASSWORD, (string) self::row('users', 1)['password']), 'unchanged');
    }

    public function testSavingTheNewPasswordUsesUpTheLinkAndSendsANote(): void
    {
        self::ask('sara@example.test');
        $token = self::tokenFrom(self::mails()[0]);
        $url = '/en/admin/password/reset/' . $token;

        $saved = self::post($url, ['password' => 'correct-horse', 'password_confirmation' => 'correct-horse'], ['session' => self::GUEST] + self::ON);
        self::assertTrue($saved['redirected']);
        self::assertSame(['message' => 'Your password has been changed. You can sign in now.', 'type' => 'success'], $saved['session']['__flash'] ?? null);
        self::assertTrue(password_verify('correct-horse', (string) self::row('users', 1)['password']));

        $mails = self::mails();
        self::assertCount(2, $mails);
        self::assertStringContainsString('Your Test Panel password was changed', $mails[1]);
        self::assertSame('password_reset', array_column(self::activity(), 'action')[1]);

        $again = self::post($url, ['password' => 'another-one', 'password_confirmation' => 'another-one'], ['session' => self::GUEST] + self::ON);
        self::assertStringContainsString('This link no longer works', $again['body']);
        self::assertTrue(password_verify('correct-horse', (string) self::row('users', 1)['password']));
    }

    public function testANewerLinkReplacesOlderOnesAndLinksExpire(): void
    {
        self::ask('sara@example.test');
        self::ask('sara@example.test');
        [$first, $second] = array_map(self::tokenFrom(...), self::mails());

        self::assertStringContainsString('This link no longer works', self::request('GET', '/en/admin/password/reset/' . $first, ['session' => self::GUEST] + self::ON)['body']);
        self::assertStringContainsString('Choose a new password', self::request('GET', '/en/admin/password/reset/' . $second, ['session' => self::GUEST] + self::ON)['body']);

        self::pdo()->exec("UPDATE admin_password_resets SET expires_at = '2000-01-01 00:00:00'");
        self::assertStringContainsString('This link no longer works', self::request('GET', '/en/admin/password/reset/' . $second, ['session' => self::GUEST] + self::ON)['body']);
    }

    public function testWrongLinksAreLimitedPerIp(): void
    {
        self::ask('sara@example.test');
        $token = self::tokenFrom(self::mails()[0]);
        for ($i = 0; $i < 10; $i++) {
            self::request('GET', '/en/admin/password/reset/' . str_repeat('x', 43), ['session' => self::GUEST, 'ip' => '192.0.2.9'] + self::ON);
        }
        self::assertStringContainsString('This link no longer works', self::request('GET', '/en/admin/password/reset/' . $token, ['session' => self::GUEST, 'ip' => '192.0.2.9'] + self::ON)['body']);
        self::assertStringContainsString('Choose a new password', self::request('GET', '/en/admin/password/reset/' . $token, ['session' => self::GUEST, 'ip' => '192.0.2.10'] + self::ON)['body']);
    }

    public function testANewPasswordEndsTheAccountsOtherSessions(): void
    {
        $session = self::EDITOR + ['email' => 'sara@example.test'];
        $first = self::request('GET', '/en/admin/dashboard', ['session' => $session, 'user_loader' => true] + self::ON);
        self::assertFalse($first['redirected']);
        $stamp = $first['session'][PasswordStamp::SESSION_KEY] ?? null;
        self::assertIsString($stamp, 'a session gets its fingerprint on the next request');

        self::ask('sara@example.test');
        self::post('/en/admin/password/reset/' . self::tokenFrom(self::mails()[0]), ['password' => 'correct-horse', 'password_confirmation' => 'correct-horse'], ['session' => self::GUEST] + self::ON);

        $after = self::request('GET', '/en/admin/dashboard', ['session' => $first['session'], 'user_loader' => true] + self::ON);
        self::assertTrue($after['redirected']);
        self::assertArrayNotHasKey('user_id', $after['session']);

        $other = self::request('GET', '/en/admin/dashboard', ['session' => self::ADMIN, 'user_loader' => true] + self::ON);
        self::assertFalse($other['redirected'], 'other accounts are not affected');
    }

    public function testSignedInAdminsAreSentToTheDashboard(): void
    {
        self::assertTrue(self::request('GET', '/en/admin/password/forgot', self::ON)['redirected']);
    }

    public function testThePagesAreOffWhenNotConfiguredOrWithoutTheTable(): void
    {
        $off = self::request('GET', '/en/admin/password/forgot', ['session' => self::GUEST, 'expect_error' => true]);
        self::assertSame(404, $off['status']);

        self::pdo()->exec('DROP TABLE admin_password_resets');
        $missing = self::request('GET', '/en/admin/password/forgot', ['session' => self::GUEST] + self::ON);
        self::assertStringContainsString('Password reset is not available', $missing['body']);
    }

    public function testRightToLeftLanguagesGetRightToLeftPages(): void
    {
        // The test site marks ku as rtl in lang/ku.php
        $page = self::request('GET', '/ku/admin/password/forgot', ['session' => self::GUEST] + self::ON);
        self::assertStringContainsString('<html lang="ku" dir="rtl">', $page['body']);
        self::assertStringContainsString('ڕێکخستنەوەی وشەی نهێنی', $page['body']);
    }
}
