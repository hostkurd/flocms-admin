<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

final class SettingsRequestTest extends RequestTestCase
{
    private const CONFIG = ['admin_config' => ['settings_path' => __DIR__ . '/../Fixtures/Settings/settings.php']];

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS settings');
        $pdo->exec('CREATE TABLE settings (`param` VARCHAR(190) NOT NULL, `value` TEXT NULL, `lang` VARCHAR(10) NOT NULL DEFAULT \'\', PRIMARY KEY (`param`, `lang`))');
        exec('rm -rf ' . escapeshellarg(self::$root . '/storage/cache'));
    }

    private static function value(string $key): mixed
    {
        $statement = self::pdo()->prepare("SELECT `value` FROM settings WHERE `param` = ? AND `lang` = ''");
        $statement->execute([$key]);
        $value = $statement->fetchColumn();
        return $value === false ? null : json_decode((string) $value, true);
    }

    public function testIndexOpensTheFirstAllowedPage(): void
    {
        $response = self::request('GET', '/en/admin/settings', self::CONFIG + ['session' => self::ADMIN]);
        self::assertTrue($response['redirected']);
        self::assertSame(403, self::request('GET', '/en/admin/settings/edit/site', self::CONFIG)['status'], 'Editors have no settings permission');
    }

    public function testPageShowsVerticalTabsLanguageSwitchAndPanes(): void
    {
        $body = self::request('GET', '/en/admin/settings/edit/site', self::CONFIG + ['session' => self::ADMIN])['body'];
        self::assertStringContainsString('data-admin-settings', $body);
        self::assertStringContainsString('aria-current="page"', $body);
        self::assertStringContainsString('General', $body);
        self::assertStringContainsString('Name, tagline and logo', $body);
        self::assertStringContainsString('Contact &amp; locations', $body, 'settings.* covers every page');
        self::assertStringContainsString('Admin panel', $body);
        self::assertStringContainsString('id="fa-settings-switch" data-lang-switch', $body, 'the same switch as record pages (0.23.1)');
        self::assertStringContainsString('class="fa-seg-btn is-default" data-lang="en" aria-pressed="true"', $body);
        self::assertStringContainsString('name="title[ku]"', $body);
        self::assertStringContainsString('name="_uploads[logo][ar]"', $body);
        self::assertStringContainsString('data-same-all', $body);
        self::assertStringContainsString('Homes by the sea', $body, 'the declared default is shown');
        self::assertStringContainsString('href="https://example.test/en/admin/settings"', $body, 'sidebar entry');
    }

    public function testSavingStoresJsonValuesAndKeepsTheEditingLanguage(): void
    {
        $saved = self::post('/en/admin/settings/edit/site', ['_editing' => 'ku', 'title' => ['en' => 'Acme', 'ku' => 'ئەکمی', 'ar' => ''],
            'tagline' => ['en' => '', 'ar' => '', 'ku' => ''], 'phone' => '+30 210', 'open' => '1', 'currency' => 'eur'], self::CONFIG + ['session' => self::ADMIN]);
        self::assertTrue($saved['redirected'], (string) $saved['body']);
        self::assertSame(['en' => 'Acme', 'ku' => 'ئەکمی'], self::value('site.title'));
        self::assertNull(self::value('site.tagline'), 'empty values are not stored: the default applies');
        self::assertSame('+30 210', self::value('site.phone'));
        self::assertSame(1, self::value('site.open'));

        $page = self::request('GET', '/en/admin/settings/edit/site?editing=ku', self::CONFIG + ['session' => $saved['session']]);
        self::assertStringContainsString('data-editing="ku"', $page['body']);
        self::assertStringContainsString('Settings saved.', $page['body']);
        self::assertStringContainsString('value="ئەکمی"', $page['body']);
    }

    public function testErrorsShowTheLanguageThatNeedsFixing(): void
    {
        $response = self::post('/en/admin/settings/edit/site', ['_editing' => 'en', 'title' => ['en' => 'Acme', 'ku' => str_repeat('x', 70)],
            'currency' => 'gbp'], self::CONFIG + ['session' => self::ADMIN]);
        self::assertFalse($response['redirected']);
        self::assertStringContainsString('data-editing="en"', $response['body'], 'the currency error is in the default language');
        self::assertNull(self::value('site.title'));

        $response = self::post('/en/admin/settings/edit/site', ['_editing' => 'en', 'title' => ['en' => 'Acme', 'ku' => str_repeat('x', 70)]], self::CONFIG + ['session' => self::ADMIN]);
        self::assertStringContainsString('data-editing="ku"', $response['body']);
    }

    public function testPermissionPerPageAndBadgePages(): void
    {
        $permissions = self::PERMISSIONS;
        $permissions[1] = ['content.*', 'settings.contact'];
        $body = self::request('GET', '/en/admin/settings/edit/contact', self::CONFIG + ['permissions' => $permissions])['body'];
        self::assertStringContainsString('name="email"', $body);
        self::assertStringNotContainsString('>General<', $body);

        $seo = self::request('GET', '/en/admin/settings/edit/seo', self::CONFIG + ['session' => self::ADMIN])['body'];
        self::assertStringContainsString('fa-lang-badge', $seo);
        self::assertStringNotContainsString('data-lang-switch', $seo);
        self::assertSame(404, self::request('GET', '/en/admin/settings/edit/nope', self::CONFIG + ['session' => self::ADMIN])['status']);
    }

    public function testKeyValueListsOnSettingsPages(): void
    {
        $links = ['_' => '', 'k1' => ['_key' => 'k1', 'key' => 'Instagram', 'value' => '@acme'], 'k2' => ['_key' => 'k2', 'key' => 'Facebook', 'value' => 'fb.com/acme']];
        // Without JavaScript the list buttons change the list and save nothing
        $moved = self::post('/en/admin/settings/edit/contact', ['email' => '', 'links' => $links, '_repeater' => 'links:up:k2'], self::CONFIG + ['session' => self::ADMIN]);
        self::assertFalse($moved['redirected']);
        self::assertLessThan(strpos($moved['body'], 'value="Instagram"'), strpos($moved['body'], 'value="Facebook"'));
        self::assertStringNotContainsString('Please', $moved['body']);
        self::assertNull(self::value('contact.links'));
        // Saved as the list itself; the website reads it with key_values()
        $saved = self::post('/en/admin/settings/edit/contact', ['email' => '', 'links' => $links], self::CONFIG + ['session' => self::ADMIN]);
        self::assertTrue($saved['redirected'], (string) $saved['body']);
        self::assertSame([['key' => 'Instagram', 'value' => '@acme'], ['key' => 'Facebook', 'value' => 'fb.com/acme']], self::value('contact.links'));
        self::assertSame(['Instagram' => '@acme', 'Facebook' => 'fb.com/acme'], key_values(self::value('contact.links')));
        $page = self::request('GET', '/en/admin/settings/edit/contact', self::CONFIG + ['session' => $saved['session']])['body'];
        self::assertStringContainsString('name="links[k0][key]" type="text" value="Instagram"', $page);
        // A duplicate network is refused
        $links['k2']['key'] = 'instagram';
        $refused = self::post('/en/admin/settings/edit/contact', ['email' => '', 'links' => $links], self::CONFIG + ['session' => self::ADMIN]);
        self::assertFalse($refused['redirected']);
        self::assertStringContainsString('class="fa-repeater-item has-error" role="listitem" data-repeater-item data-key="k2"', $refused['body']);
        self::assertStringContainsString('already has this Network.', $refused['body']);
    }

    public function testWithoutTableTheFormExplainsAndCannotSave(): void
    {
        self::pdo()->exec('DROP TABLE settings');
        $body = self::request('GET', '/en/admin/settings/edit/site', self::CONFIG + ['session' => self::ADMIN])['body'];
        self::assertStringContainsString('php flo migrate', $body);
        $saved = self::post('/en/admin/settings/edit/site', ['title' => ['en' => 'Acme']], self::CONFIG + ['session' => self::ADMIN]);
        self::assertTrue($saved['redirected']);
    }

    public function testWebsiteHelperReadsSavedValues(): void
    {
        self::post('/en/admin/settings/edit/site', ['title' => ['en' => 'Acme', 'ku' => 'ئەکمی']], self::CONFIG + ['session' => self::ADMIN]);
        $script = self::$root . '/read.php';
        file_put_contents($script, '<?php define("ROOT", __DIR__); require $argv[1] . "/vendor/autoload.php"; define("ACTIVE_LANG", "ku");'
            . ' \FloCMS\Core\Config::set("languages", ["en","ar","ku"]); \FloCMS\Core\Config::set("default_language", "en");'
            . ' \FloCMS\Admin\Admin::useConfig(["settings_path" => ' . var_export(__DIR__ . '/../Fixtures/Settings/settings.php', true) . '], true, ROOT);'
            . ' \FloCMS\Core\App::$db = new \FloCMS\Core\Database(' . (getenv('FLO_ADMIN_TEST_DB_NAME') ? 'new PDO(' . var_export(sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', getenv('FLO_ADMIN_TEST_DB_HOST') ?: '127.0.0.1', getenv('FLO_ADMIN_TEST_DB_NAME')), true) . ', ' . var_export(getenv('FLO_ADMIN_TEST_DB_USER') ?: 'root', true) . ', ' . var_export(getenv('FLO_ADMIN_TEST_DB_PASS') ?: '', true) . ')' : 'new PDO("sqlite:" . ROOT . "/db.sqlite")') . ');'
            . ' echo setting("site.title"), "|", setting("site.title", lang: "en"), "|", setting("site.tagline"), "|", setting("contact.email", "none");');
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(dirname(__DIR__, 2)) . ' 2>&1', $output);
        self::assertSame('ئەکمی|Acme|Homes by the sea|none', implode("\n", $output));
        self::assertFileExists(self::$root . '/storage/cache/flocms-admin-settings.php');
    }

    public function testPanelSettingsOverrideTheConfigUntilCleared(): void
    {
        $before = self::request('GET', '/en/admin/dashboard', self::CONFIG + ['session' => self::ADMIN])['body'];
        self::assertStringContainsString('data-theme="light"', $before);
        self::assertStringContainsString('data-density="comfortable"', $before);
        self::assertStringContainsString('Test Panel', $before);

        $saved = self::post('/en/admin/settings/edit/panel', ['brand' => ['en' => 'Acme Admin', 'ku' => 'ئەکمی'], 'primary_color' => '#0a5', 'theme' => 'dark',
            'sidebar' => 'dark', 'content_width' => 'boxed', 'density' => 'compact'], self::CONFIG + ['session' => self::ADMIN]);
        self::assertTrue($saved['redirected'], $saved['body']);
        self::assertSame('#00aa55', self::value('panel.primary_color'));

        $after = self::request('GET', '/en/admin/dashboard', self::CONFIG + ['session' => self::ADMIN])['body'];
        foreach (['data-theme="dark"', 'data-sidebar="dark"', 'data-width="boxed"', 'data-density="compact"', 'Acme Admin', '/flo-admin/asset/theme-'] as $expected) {
            self::assertStringContainsString($expected, $after);
        }
        self::assertStringContainsString('ئەکمی', self::request('GET', '/ku/admin/dashboard', self::CONFIG + ['session' => self::ADMIN])['body']);

        // An empty value falls back to config/admin.php again
        self::post('/en/admin/settings/edit/panel', ['brand' => ['en' => ''], 'theme' => '', 'density' => ''], self::CONFIG + ['session' => self::ADMIN]);
        $cleared = self::request('GET', '/en/admin/dashboard', self::CONFIG + ['session' => self::ADMIN]);
        self::assertStringContainsString('data-theme="light"', $cleared['body']);
        self::assertStringContainsString('Test Panel', $cleared['body']);

        // Turned off: the page is gone and saved values are ignored
        self::post('/en/admin/settings/edit/panel', ['theme' => 'dark'], self::CONFIG + ['session' => self::ADMIN]);
        $off = ['admin_config' => self::CONFIG['admin_config'] + ['panel_settings' => false], 'session' => self::ADMIN];
        self::assertStringContainsString('data-theme="light"', self::request('GET', '/en/admin/dashboard', $off)['body']);
        self::assertSame(404, self::request('GET', '/en/admin/settings/edit/panel', $off)['status']);
    }

    public function testPanelPageNeedsSettingsPanelAndRejectsBadValues(): void
    {
        $permissions = self::PERMISSIONS;
        $permissions[1] = ['content.*', 'settings.manage'];
        self::assertSame(403, self::request('GET', '/en/admin/settings/edit/panel', self::CONFIG + ['permissions' => $permissions])['status']);
        $body = self::request('GET', '/en/admin/settings/edit/site', self::CONFIG + ['permissions' => $permissions])['body'];
        self::assertStringNotContainsString('Admin panel', $body);

        $response = self::post('/en/admin/settings/edit/panel', ['primary_color' => 'blue', 'theme' => 'neon'], self::CONFIG + ['session' => self::ADMIN]);
        self::assertFalse($response['redirected']);
        self::assertStringContainsString('must be a colour such as', $response['body']);
        self::assertNull(self::value('panel.theme'));
    }
}
