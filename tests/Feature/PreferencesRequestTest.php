<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

final class PreferencesRequestTest extends RequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS admin_user_preferences');
        $pdo->exec('CREATE TABLE admin_user_preferences (`user_id` INTEGER NOT NULL, `key` VARCHAR(100) NOT NULL, `value` TEXT NULL, `updated_at` DATETIME NULL, PRIMARY KEY (`user_id`, `key`))');
    }

    private static function preferences(int $user): array
    {
        $statement = self::pdo()->prepare('SELECT `key`, `value` FROM admin_user_preferences WHERE user_id = ? ORDER BY `key`');
        $statement->execute([$user]);
        $values = [];
        foreach ($statement->fetchAll(\PDO::FETCH_NUM) as [$key, $value]) { $values[$key] = json_decode((string) $value, true); }
        return $values;
    }

    public function testProfileHasAccountAndPreferencesTabs(): void
    {
        $body = self::request('GET', '/en/admin/profile/preferences', ['session' => self::ADMIN])['body'];
        self::assertStringContainsString('My preferences', $body);
        self::assertStringContainsString('href="https://example.test/en/admin/profile"', $body);
        self::assertStringContainsString('name="theme"', $body);
        self::assertStringContainsString('name="language"', $body);
        self::assertStringContainsString('Panel default (Light)', $body);
        self::assertStringNotContainsString('kept until you log out', $body);

        $account = self::request('GET', '/en/admin/profile', ['session' => self::ADMIN])['body'];
        self::assertStringContainsString('name="current_password"', $account);
        self::assertStringContainsString('aria-current="page"', $account);
    }

    public function testThemeAndDensityApplyToTheUserOnly(): void
    {
        $saved = self::post('/en/admin/profile/preferences', ['theme' => 'dark', 'density' => 'compact', 'language' => ''], ['session' => self::ADMIN]);
        self::assertTrue($saved['redirected']);
        self::assertSame(['density' => 'compact', 'theme' => 'dark'], self::preferences(2));

        $mine = self::request('GET', '/en/admin/dashboard', ['session' => self::ADMIN])['body'];
        self::assertStringContainsString('data-theme="dark"', $mine);
        self::assertStringContainsString('data-density="compact"', $mine);
        $theirs = self::request('GET', '/en/admin/dashboard', ['session' => self::SUPER])['body'];
        self::assertStringContainsString('data-theme="light"', $theirs);

        self::post('/en/admin/profile/preferences', ['theme' => '', 'density' => ''], ['session' => self::ADMIN]);
        self::assertSame([], self::preferences(2), 'Panel default removes the preference');
        self::assertFalse(self::post('/en/admin/profile/preferences', ['theme' => 'neon'], ['session' => self::ADMIN])['redirected'], 'an unknown theme is refused');
        self::assertSame([], self::preferences(2));
    }

    public function testPreferredLanguageRedirectsAndTheMenuChangesIt(): void
    {
        $saved = self::post('/en/admin/profile/preferences', ['language' => 'ku'], ['session' => self::ADMIN]);
        self::assertTrue($saved['redirected']);
        self::assertSame(['language' => 'ku'], self::preferences(2));

        $dashboard = self::request('GET', '/en/admin/posts?search=villa', ['session' => self::ADMIN]);
        self::assertTrue($dashboard['redirected'], 'a page in another language goes to the preferred one');
        self::assertStringContainsString('/ku/admin/', self::request('GET', '/ku/admin/dashboard', ['session' => self::ADMIN])['body']);
        self::assertFalse(self::request('GET', '/ku/admin/dashboard', ['session' => self::ADMIN])['redirected']);

        // A URL without a language segment (the default language) redirects once, not in a loop.
        $bare = self::request('GET', '/admin/dashboard', ['session' => self::ADMIN]);
        self::assertTrue($bare['redirected']);

        // Language links in the top bar carry the marker; following one updates the preference.
        self::assertStringContainsString('/en/admin/dashboard?switch_language=1', self::request('GET', '/ku/admin/dashboard', ['session' => self::ADMIN])['body']);
        $switched = self::request('GET', '/en/admin/dashboard?switch_language=1', ['session' => self::ADMIN]);
        self::assertTrue($switched['redirected']);
        self::assertSame(['language' => 'en'], self::preferences(2));
        self::assertFalse(self::request('GET', '/en/admin/dashboard', ['session' => self::ADMIN])['redirected']);

        // Without a preferred language the menu only navigates.
        $other = self::request('GET', '/ar/admin/dashboard?switch_language=1', ['session' => self::SUPER]);
        self::assertTrue($other['redirected']);
        self::assertSame([], self::preferences(3));
        self::assertFalse(self::request('GET', '/ar/admin/dashboard', ['session' => self::SUPER])['redirected']);
    }

    public function testWithoutTheTablePreferencesLastForTheSession(): void
    {
        self::pdo()->exec('DROP TABLE admin_user_preferences');
        $body = self::request('GET', '/en/admin/profile/preferences', ['session' => self::ADMIN])['body'];
        self::assertStringContainsString('kept until you log out', $body);
        $saved = self::post('/en/admin/profile/preferences', ['theme' => 'dark'], ['session' => self::ADMIN]);
        self::assertStringContainsString('data-theme="dark"', self::request('GET', '/en/admin/dashboard', ['session' => $saved['session']])['body']);
        self::assertStringContainsString('data-theme="light"', self::request('GET', '/en/admin/dashboard', ['session' => self::ADMIN])['body']);
    }

    public function testDeletingAUserRemovesTheirPreferences(): void
    {
        self::pdo()->exec("INSERT INTO admin_user_preferences (user_id, `key`, `value`) VALUES (4, 'theme', '\"dark\"'), (3, 'theme', '\"dark\"')");
        $deleted = self::post('/en/admin/accounts/delete/4', [], ['session' => self::SUPER]);
        self::assertTrue($deleted['redirected'], $deleted['body']);
        self::assertNull(self::row('users', 4));
        self::assertSame([], self::preferences(4));
        self::assertSame(['theme' => 'dark'], self::preferences(3));
    }
}
