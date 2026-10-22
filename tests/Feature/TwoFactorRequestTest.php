<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Auth\Totp;
use FloCMS\Admin\Console\Stub;

/**
 * Two-factor sign-in (0.35) through whole requests: setting it up on the
 * profile, the held sign-in (flocms-core 2.7), codes, backup codes, wrong
 * codes, remembered devices, roles that require it and an admin's reset.
 */
final class TwoFactorRequestTest extends RequestTestCase
{
    private const KEY = 'test-app-key-0123456789-abcdefghijklmnop';
    private const ENV = ['APP_KEY' => self::KEY, 'MAIL_TRANSPORT' => 'log', 'MAIL_FROM' => 'panel@example.test', 'APP_URL' => 'https://example.test'];
    private const TABLES = ['admin_two_factor', 'admin_two_factor_roles', 'admin_trusted_devices', 'admin_email_tokens', 'admin_trash', 'admin_activity', 'admin_roles'];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        foreach (self::TABLES as $table) { $pdo->exec('DROP TABLE IF EXISTS ' . $table); }
        foreach ([
            ['login-security-migration', ['two_factor_table' => 'admin_two_factor', 'roles_table' => 'admin_two_factor_roles', 'devices_table' => 'admin_trusted_devices', 'tokens_table' => 'admin_email_tokens']],
            ['trash-activity-migration', ['trash_table' => 'admin_trash', 'activity_table' => 'admin_activity']],
            ['roles-migration', ['table' => 'admin_roles']],
        ] as [$stub, $values]) {
            $file = self::$root . '/' . $stub . '.php';
            file_put_contents($file, Stub::render($stub, $values));
            (require $file)->up(new \FloCMS\Core\Database($pdo));
        }
        @unlink(self::$root . '/storage/logs/admin-mail.log');
    }

    protected function tearDown(): void
    {
        foreach (self::TABLES as $table) { self::pdo()->exec('DROP TABLE IF EXISTS ' . $table); }
        parent::tearDown();
    }

    /** Ali, already past this sign-in's steps (flocms-core 2.7 marks the session). */
    private static function signedIn(): array
    {
        return self::ADMIN + ['auth_holds_asked' => hash('sha256', 'holds|2')];
    }

    /** @param array<string, mixed> $extra */
    private static function on(array $extra = []): array
    {
        return ['admin_config' => ['two_factor' => true] + ($extra['admin_config'] ?? []), 'env' => self::ENV, 'user_loader' => true,
            'config' => ['admin_access_roles' => ['1', '2', '3']]] + $extra;
    }

    /** @return list<string> */
    private static function mails(): array
    {
        $file = self::$root . '/storage/logs/admin-mail.log';
        return array_values(array_filter(explode('==== ', is_file($file) ? (string) file_get_contents($file) : '')));
    }

    private static function keyFrom(string $body): string
    {
        self::assertSame(1, preg_match('#<code dir="ltr">([A-Z2-7 ]{20,})</code>#', $body, $match), $body);
        return (string) Totp::base32Decode($match[1]);
    }

    private static function code(string $secret, int $offset = 0): string
    {
        return Totp::code($secret, Totp::step() + $offset);
    }

    /** Turn two-factor on for Ali (user 2) through the profile page; returns the secret and the backup codes. */
    private static function enableForAli(): array
    {
        $start = self::post('/en/admin/profile/security', ['_action' => 'start', 'proof' => self::PASSWORD], ['session' => self::ADMIN] + self::on());
        self::assertTrue($start['redirected']);
        $setup = self::request('GET', '/en/admin/profile/security', ['session' => $start['session']] + self::on());
        self::assertStringContainsString('<svg xmlns="http://www.w3.org/2000/svg"', $setup['body'], 'the QR code');
        $secret = self::keyFrom($setup['body']);
        $done = self::post('/en/admin/profile/security', ['_action' => 'confirm', 'code' => self::code($secret)], ['session' => $setup['session']] + self::on());
        self::assertTrue($done['redirected'], $done['body']);
        $codes = self::request('GET', '/en/admin/profile/security', ['session' => $done['session']] + self::on());
        self::assertSame(10, preg_match_all('#<li>([2-9A-Z]{5}-[2-9A-Z]{5})</li>#', $codes['body'], $found));
        self::assertCount(10, $found[1]);
        return [$secret, $found[1]];
    }

    /** A fresh sign-in of Ali: the first admin request after the password. */
    private static function signIn(array $extra = []): array
    {
        $page = self::request('GET', '/en/admin/dashboard', ['session' => self::ADMIN] + self::on($extra));
        return $page['session'];
    }

    public function testWithoutTwoFactorTheSignInIsNotHeld(): void
    {
        $page = self::request('GET', '/en/admin/dashboard', ['session' => self::ADMIN] + self::on());

        self::assertSame(200, $page['status']);
        self::assertFalse($page['redirected']);
        self::assertArrayNotHasKey('auth_held', $page['session']);
    }

    public function testTurningItOnStoresAnEncryptedSecretAndTenHashedCodes(): void
    {
        [$secret, $codes] = self::enableForAli();

        $row = self::pdo()->query('SELECT * FROM admin_two_factor WHERE user_id = 2')->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertStringStartsWith('v1:', $row['secret']);
        self::assertStringNotContainsString(Totp::base32Encode($secret), $row['secret']);
        $stored = json_decode($row['backup_codes'], true);
        self::assertCount(10, $stored);
        foreach ($codes as $code) { self::assertStringNotContainsString(str_replace('-', '', $code), $row['backup_codes']); }
        $activity = self::pdo()->query("SELECT action FROM admin_activity")->fetchAll(\PDO::FETCH_COLUMN);
        self::assertContains('two_factor_enabled', $activity);

        // The codes are shown once.
        $again = self::request('GET', '/en/admin/profile/security', ['session' => self::signedIn()] + self::on());
        self::assertStringNotContainsString($codes[0], $again['body']);
        self::assertStringContainsString('10 of 10 left', $again['body']);
    }

    public function testAWrongCodeDuringSetupChangesNothing(): void
    {
        $start = self::post('/en/admin/profile/security', ['_action' => 'start', 'proof' => 'wrong'], ['session' => self::ADMIN] + self::on());
        self::assertFalse($start['redirected']);
        self::assertStringContainsString('aria-invalid="true"', $start['body']);
        self::assertArrayNotHasKey('admin_2fa_profile_setup', $start['session']);

        $start = self::post('/en/admin/profile/security', ['_action' => 'start', 'proof' => self::PASSWORD], ['session' => self::ADMIN] + self::on());
        $wrong = self::post('/en/admin/profile/security', ['_action' => 'confirm', 'code' => '000000'], ['session' => $start['session']] + self::on());
        self::assertFalse($wrong['redirected']);
        self::assertStringContainsString("That code doesn&#039;t match", $wrong['body']);
        self::assertSame(0, (int) self::pdo()->query('SELECT COUNT(*) FROM admin_two_factor')->fetchColumn());
    }

    public function testASignInIsHeldUntilTheRightCode(): void
    {
        [$secret] = self::enableForAli();

        $held = self::signIn();
        self::assertSame('two-factor', $held['auth_held']['path']);
        self::assertArrayNotHasKey('isloggedin', $held);

        $other = self::request('GET', '/en/admin/posts', ['session' => $held] + self::on());
        self::assertTrue($other['redirected']);
        self::assertArrayHasKey('auth_held', $other['session']);

        $page = self::request('GET', '/en/admin/two-factor', ['session' => $held] + self::on());
        self::assertSame(200, $page['status']);
        self::assertStringContainsString('Enter your code', $page['body']);
        self::assertStringContainsString('al•••@example.test', $page['body']);
        self::assertStringContainsString('autocomplete="one-time-code"', $page['body']);

        $wrong = self::post('/en/admin/two-factor', ['code' => '000000'], ['session' => $held] + self::on());
        self::assertSame(422, $wrong['status']);
        self::assertStringContainsString('4 tries left', $wrong['body']);

        // The setup used this step's code: the next step's code (one step of drift is allowed).
        $right = self::post('/en/admin/two-factor', ['code' => self::code($secret, 1)], ['session' => $wrong['session']] + self::on());
        self::assertTrue($right['redirected']);
        self::assertTrue($right['session']['isloggedin']);
        self::assertTrue($right['session']['admin_access']);
        self::assertArrayNotHasKey('auth_held', $right['session']);

        $panel = self::request('GET', '/en/admin/posts', ['session' => $right['session']] + self::on());
        self::assertFalse($panel['redirected']);
        self::assertSame(200, $panel['status']);
    }

    public function testACodeWorksOnce(): void
    {
        [$secret] = self::enableForAli();
        $code = self::code($secret, 1);
        $first = self::post('/en/admin/two-factor', ['code' => $code], ['session' => self::signIn()] + self::on());
        self::assertTrue($first['session']['isloggedin']);

        $again = self::post('/en/admin/two-factor', ['code' => $code], ['session' => self::signIn(['session' => self::ADMIN])] + self::on());
        self::assertSame(422, $again['status']);
    }

    public function testBackupCodesWorkOnce(): void
    {
        [, $codes] = self::enableForAli();

        $page = self::request('GET', '/en/admin/two-factor/backup', ['session' => self::signIn()] + self::on());
        self::assertStringContainsString('Use a backup code', $page['body']);
        $used = self::post('/en/admin/two-factor/backup', ['code' => strtolower(str_replace('-', ' ', $codes[3]))], ['session' => self::signIn()] + self::on());
        self::assertTrue($used['session']['isloggedin'] ?? false, $used['body']);

        $again = self::post('/en/admin/two-factor/backup', ['code' => $codes[3]], ['session' => self::signIn()] + self::on());
        self::assertSame(422, $again['status']);
        $security = self::request('GET', '/en/admin/profile/security', ['session' => self::signedIn()] + self::on());
        self::assertStringContainsString('9 of 10 left', $security['body']);
    }

    public function testFiveWrongCodesEndTheHeldSignIn(): void
    {
        self::enableForAli();
        $session = self::signIn();
        for ($i = 1; $i <= 4; $i++) {
            $session = self::post('/en/admin/two-factor', ['code' => '000000'], ['session' => $session] + self::on())['session'];
        }
        $last = self::post('/en/admin/two-factor', ['code' => '000000'], ['session' => $session] + self::on());

        self::assertTrue($last['redirected']);
        self::assertArrayNotHasKey('auth_held', $last['session']);
        self::assertArrayNotHasKey('user_id', $last['session']);
    }

    public function testTenWrongCodesInAnHourPauseCodesAndTellTheOwner(): void
    {
        [$secret] = self::enableForAli();
        self::pdo()->exec("UPDATE admin_two_factor SET failures = 9, failures_since = '" . gmdate('Y-m-d H:i:s') . "' WHERE user_id = 2");

        $locked = self::post('/en/admin/two-factor', ['code' => '000000'], ['session' => self::signIn()] + self::on());
        self::assertStringContainsString('Too many wrong codes. Try again in 15 minutes', $locked['body']);
        self::assertNotNull(self::pdo()->query('SELECT locked_until FROM admin_two_factor WHERE user_id = 2')->fetchColumn());

        $right = self::post('/en/admin/two-factor', ['code' => self::code($secret, 1)], ['session' => self::signIn()] + self::on());
        self::assertStringContainsString('Too many wrong codes', $right['body'], 'even the right code waits');
        $mails = self::mails();
        self::assertCount(1, $mails);
        self::assertStringContainsString('To: ali@example.test', $mails[0]);
        self::assertStringContainsString('Wrong two-factor codes', $mails[0]);
    }

    public function testARememberedDeviceSkipsTheCodeUntilThePasswordChanges(): void
    {
        [$secret] = self::enableForAli();
        $done = self::post('/en/admin/two-factor', ['code' => self::code($secret, 1), 'remember' => '1'], ['session' => self::signIn()] + self::on());
        self::assertTrue($done['session']['isloggedin']);
        $cookies = $done['cookies'];
        self::assertCount(1, $cookies);
        $token = (string) array_values($cookies)[0];
        $row = self::pdo()->query('SELECT * FROM admin_trusted_devices')->fetch(\PDO::FETCH_ASSOC);
        self::assertNotSame($token, $row['token_hash']);
        self::assertStringNotContainsString($token, implode('|', $row));

        $next = self::request('GET', '/en/admin/dashboard', ['session' => self::ADMIN, 'cookies' => $cookies, 'headers' => ['User-Agent' => 'Firefox/140.0']] + self::on());
        self::assertFalse($next['redirected'], 'not held');
        self::assertArrayNotHasKey('auth_held', $next['session']);

        $security = self::request('GET', '/en/admin/profile/security', ['session' => self::ADMIN, 'cookies' => $cookies] + self::on());
        self::assertStringContainsString('This device', $security['body']);

        self::pdo()->exec("UPDATE users SET password = '" . password_hash('another-pass-2', PASSWORD_DEFAULT) . "' WHERE id = 2");
        $after = self::request('GET', '/en/admin/dashboard', ['session' => self::ADMIN, 'cookies' => $cookies] + self::on());
        self::assertSame('two-factor', $after['session']['auth_held']['path'] ?? null, 'a new password forgets the devices');
    }

    public function testARoleCanRequireIt(): void
    {
        $page = self::request('GET', '/en/admin/roles/edit/2', ['session' => self::SUPER] + self::on());
        self::assertStringContainsString('Require two-factor', $page['body']);
        $set = self::post('/en/admin/roles/two_factor/2', ['two_factor' => '1'], ['session' => self::SUPER] + self::on());
        self::assertTrue($set['redirected'], $set['body']);
        self::assertSame(1, (int) self::pdo()->query('SELECT required FROM admin_two_factor_roles WHERE role = 2')->fetchColumn());

        // Editors can't change a higher role.
        $refused = self::post('/en/admin/roles/two_factor/3', ['two_factor' => '1'], ['session' => self::ADMIN + ['role' => 2]] + self::on(['permissions' => [0 => [], 1 => ['content.*'], 2 => ['content.*', 'roles.manage'], 3 => ['*']]]));
        self::assertContains($refused['status'], [403, 302]);

        // Ali's next sign-in sets it up first.
        $held = self::signIn();
        self::assertSame('two-factor', $held['auth_held']['path']);
        $setup = self::request('GET', '/en/admin/two-factor', ['session' => $held] + self::on());
        self::assertStringContainsString('Set up two-factor sign-in', $setup['body']);
        self::assertStringContainsString('Your role, Admin, requires it', $setup['body']);
        $secret = self::keyFrom($setup['body']);
        $confirmed = self::post('/en/admin/two-factor', ['code' => self::code($secret)], ['session' => $setup['session']] + self::on());
        self::assertTrue($confirmed['redirected'], $confirmed['body']);
        $codes = self::request('GET', '/en/admin/two-factor/codes', ['session' => $confirmed['session']] + self::on());
        self::assertSame(10, preg_match_all('#<li>[2-9A-Z]{5}-[2-9A-Z]{5}</li>#', $codes['body']));
        $done = self::post('/en/admin/two-factor/codes', [], ['session' => $codes['session']] + self::on());
        self::assertTrue($done['session']['isloggedin']);

        // And can't turn it off.
        $off = self::post('/en/admin/profile/security', ['_action' => 'disable', 'proof' => self::PASSWORD], ['session' => $done['session']] + self::on());
        self::assertStringContainsString('requires two-factor sign-in, so it can&#039;t be turned off', $off['body']);
    }

    public function testTurningItOffNeedsThePasswordOrACode(): void
    {
        [$secret] = self::enableForAli();
        $wrong = self::post('/en/admin/profile/security', ['_action' => 'disable', 'proof' => 'nope'], ['session' => self::signedIn()] + self::on());
        self::assertStringContainsString('That password or code isn&#039;t right', $wrong['body']);
        $off = self::post('/en/admin/profile/security', ['_action' => 'disable', 'proof' => self::code($secret, 1)], ['session' => self::signedIn()] + self::on());
        self::assertTrue($off['redirected']);
        self::assertSame(0, (int) self::pdo()->query('SELECT COUNT(*) FROM admin_two_factor')->fetchColumn());
    }

    public function testAnAdminCanResetSomeonesTwoFactor(): void
    {
        self::enableForAli();
        $page = self::request('GET', '/en/admin/accounts/action/row/reset_two_factor/2', ['session' => self::SUPER] + self::on());
        self::assertSame(200, $page['status'], $page['body']);
        self::assertStringContainsString('Reset two-factor sign-in for Ali?', $page['body']);
        self::assertSame(1, preg_match('/name="_operation" value="([^"]+)"/', $page['body'], $match));
        $done = self::post('/en/admin/accounts/action/row/reset_two_factor/2', ['_operation' => $match[1], 'notify' => '1'], ['session' => $page['session']] + self::on());
        self::assertNull($done['error']);
        self::assertSame(0, (int) self::pdo()->query('SELECT COUNT(*) FROM admin_two_factor')->fetchColumn());
        self::assertStringContainsString('Two-factor sign-in was reset', implode('', self::mails()));
        self::assertContains('two_factor_reset', self::pdo()->query('SELECT action FROM admin_activity')->fetchAll(\PDO::FETCH_COLUMN));

        // Not for yourself, and only when it's on.
        self::assertSame(403, self::request('GET', '/en/admin/accounts/action/row/reset_two_factor/3', ['session' => self::SUPER] + self::on())['status']);
        self::assertSame(403, self::request('GET', '/en/admin/accounts/action/row/reset_two_factor/2', ['session' => self::SUPER] + self::on())['status']);
    }

    public function testTheStepPagesSendEveryoneElseAway(): void
    {
        $guest = self::request('GET', '/en/admin/two-factor', ['session' => ['_csrf_token' => 'tok']] + self::on());
        self::assertTrue($guest['redirected']);
        $signedIn = self::request('GET', '/en/admin/two-factor', ['session' => self::ADMIN] + self::on());
        self::assertTrue($signedIn['redirected']);
    }

    public function testWithTwoFactorOffInConfigNothingIsHeld(): void
    {
        self::enableForAli();
        $page = self::request('GET', '/en/admin/dashboard', ['session' => self::ADMIN, 'env' => self::ENV, 'user_loader' => true]);
        self::assertFalse($page['redirected']);
        self::assertSame(404, self::request('GET', '/en/admin/two-factor', ['session' => self::ADMIN, 'env' => self::ENV])['status']);
    }
}
