<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Settings\Settings;
use FloCMS\Admin\Settings\SettingsPage;
use FloCMS\Admin\Settings\SettingsRegistry;
use FloCMS\Admin\Settings\SettingsStore;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\App;
use FloCMS\Core\Database;
use PDO;

final class SettingsTest extends TestCase
{
    private string $root;
    private ?Database $previousDb = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/flo-admin-settings-' . bin2hex(random_bytes(5));
        mkdir($this->root . '/admin', 0777, true);
        $this->previousDb = App::$db;
        App::$db = null;
    }

    protected function tearDown(): void
    {
        App::$db = $this->previousDb;
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    private function declare(string $php): void
    {
        file_put_contents($this->root . '/admin/settings.php', "<?php\nuse FloCMS\\Admin\\Settings\\SettingsPage;\nuse FloCMS\\Admin\\Form\\Fields\\{TextInput, Toggle, ImageUpload, Select};\nreturn " . $php . ";\n");
        clearstatcache();
        Admin::useConfig(['panel_settings' => false], true, $this->root);
    }

    private function database(bool $table = true): Database
    {
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        if ($table) {
            $pdo->exec('CREATE TABLE settings (`key` VARCHAR(190) PRIMARY KEY, `value` TEXT NULL, `updated_at` DATETIME NULL)');
        }
        return App::$db = new Database($pdo);
    }

    public function testPageNamesAndKeys(): void
    {
        $page = SettingsPage::make('site')->schema([TextInput::make('title')]);
        self::assertSame('site.title', $page->key('title'));
        self::assertSame('settings.manage', $page->getPermission());
        self::assertSame(['title'], array_keys($page->storedFields()));

        $this->expectException(\InvalidArgumentException::class);
        SettingsPage::make('Site-Name');
    }

    public function testRegistryRejectsReservedAndDuplicatePages(): void
    {
        $this->declare("[SettingsPage::make('site'), SettingsPage::make('site'), SettingsPage::make('panel'), 'nope']");
        $registry = Admin::settings();
        self::assertSame(['site'], array_keys($registry->all()));
        self::assertCount(3, $registry->problems());
        self::assertTrue($registry->fileFound());
    }

    public function testPanelPageIsAddedUnlessTurnedOff(): void
    {
        Admin::useConfig([], true, $this->root);
        self::assertNotNull(Admin::settings()->find('panel'));
        self::assertSame('settings.panel', Admin::settings()->find('panel')->getPermission());
        Admin::useConfig(['panel_settings' => false], true, $this->root);
        self::assertSame([], Admin::settings()->all());
    }

    public function testStoreKeepsJsonValuesAndRemovesNulls(): void
    {
        $store = new SettingsStore($this->database());
        self::assertTrue($store->exists());
        $store->put(['site.title' => ['en' => 'Acme', 'ar' => 'أكمي'], 'site.phone' => '123', 'site.on' => '1']);
        $store->put(['site.phone' => null, 'site.on' => '0']);
        self::assertSame(['site.title' => ['en' => 'Acme', 'ar' => 'أكمي'], 'site.on' => '0'], $store->all());
        $raw = App::$db->pdo()->query("SELECT value FROM settings WHERE `key` = 'site.title'")->fetchColumn();
        self::assertSame('{"en":"Acme","ar":"أكمي"}', $raw);
        self::assertFalse((new SettingsStore($this->database(false)))->exists());
    }

    public function testGetResolvesLanguagesDefaultsAndFiles(): void
    {
        $this->declare("[SettingsPage::make('site')->schema([TextInput::make('title')->translatable()->default('Default title'), TextInput::make('phone'), Toggle::make('open'), ImageUpload::make('logo')->translatable()])]");
        (new SettingsStore($this->database()))->put(['site.title' => ['en' => 'Acme', 'ku' => 'ئەکمی'], 'site.open' => '0', 'site.logo' => ['en' => 'site/logo/a.png']]);

        self::assertSame('Acme', Settings::get('site.title'));
        self::assertSame('ئەکمی', Settings::get('site.title', null, 'ku'));
        self::assertSame('Acme', Settings::get('site.title', null, 'ar'), 'falls back to the default language');
        self::assertSame('—', Settings::get('site.phone', '—'));
        self::assertSame('', Settings::get('site.phone'));
        self::assertSame('0', Settings::get('site.open'));
        self::assertSame('/uploads/settings/site/logo/a.png', Settings::get('site.logo', null, 'ku'), 'a language without its own image uses the default one');
        self::assertSame(['en' => 'Acme', 'ku' => 'ئەکمی'], Settings::translations('site.title'));
        self::assertSame(['title' => 'Acme', 'phone' => '', 'open' => '0', 'logo' => '/uploads/settings/site/logo/a.png'], Settings::page('site'));
        self::assertSame('Acme', setting('site.title'));
        self::assertSame('ئەکمی', setting('site.title', lang: 'ku'));
    }

    public function testDeclaredDefaultIsUsedUntilAValueIsStored(): void
    {
        $this->declare("[SettingsPage::make('site')->schema([TextInput::make('title')->translatable()->default('Default title'), Toggle::make('open')->default(true), Toggle::make('closed')])]");
        $this->database();
        self::assertSame('Default title', Settings::get('site.title'));
        self::assertSame(1, Settings::get('site.open'), 'a default has the same shape as a saved value');
        self::assertSame(0, Settings::get('site.closed'));
    }

    public function testCacheFileIsWrittenAndRebuiltWhenDeclarationsChange(): void
    {
        $this->declare("[SettingsPage::make('site')->schema([TextInput::make('title')])]");
        $store = new SettingsStore($this->database());
        $store->put(['site.title' => 'One']);
        self::assertSame('One', Settings::get('site.title'));
        self::assertFileExists(Settings::cacheFile());

        // A direct change in the table is not seen until the cache is cleared
        $store->put(['site.title' => 'Two']);
        Settings::reset();
        self::assertSame('One', Settings::get('site.title'));
        Settings::forget();
        self::assertSame('Two', Settings::get('site.title'));

        // Changing admin/settings.php rebuilds the cache (new default)
        sleep(1);
        $this->declare("[SettingsPage::make('site')->schema([TextInput::make('title'), TextInput::make('motto')->default('Hello')])]");
        self::assertSame('Hello', Settings::get('site.motto'));
    }

    public function testWithoutTableOrDatabaseDefaultsAreReturnedAndNothingIsCached(): void
    {
        $this->declare("[SettingsPage::make('site')->schema([TextInput::make('title')->default('Fallback')])]");
        self::assertSame('Fallback', Settings::get('site.title'));
        $this->database(false);
        Settings::reset();
        self::assertSame('Fallback', Settings::get('site.title'));
        self::assertFileDoesNotExist(Settings::cacheFile());
    }

    public function testNotInstalledReturnsCallDefault(): void
    {
        Admin::useConfig([], false, $this->root);
        self::assertSame('x', Settings::get('site.title', 'x'));
    }
}
