<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Fields\ImageUpload;
use FloCMS\Admin\Form\Fields\RichEditor;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Settings\Settings;
use FloCMS\Admin\Settings\SettingsEditor;
use FloCMS\Admin\Settings\SettingsPage;
use FloCMS\Admin\Settings\SettingsStore;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\App;
use FloCMS\Core\Database;
use PDO;

final class SettingsEditorTest extends TestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
    private string $root;
    private ?Database $previousDb = null;
    private SettingsStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/flo-admin-editor-' . bin2hex(random_bytes(5));
        mkdir($this->root, 0777, true);
        Admin::useConfig(['panel_settings' => false], true, $this->root);
        $this->previousDb = App::$db;
        $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('CREATE TABLE settings (`key` VARCHAR(190) PRIMARY KEY, `value` TEXT NULL, `updated_at` DATETIME NULL)');
        App::$db = new Database($pdo);
        $this->store = new SettingsStore(App::$db);
        Admin::settings()->add($this->page());
    }

    protected function tearDown(): void
    {
        App::$db = $this->previousDb;
        exec('rm -rf ' . escapeshellarg($this->root));
        parent::tearDown();
    }

    private function page(): SettingsPage
    {
        return SettingsPage::make('site')->schema([
            TextInput::make('title')->translatable()->required(),
            ImageUpload::make('logo')->translatable(),
            RichEditor::make('about')->translatable(),
        ]);
    }

    private function png(): array
    {
        $file = $this->root . '/upload-' . bin2hex(random_bytes(4)) . '.png';
        file_put_contents($file, base64_decode(self::PNG));
        return ['name' => 'logo.png', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)];
    }

    /** @param array<string, array<string, array>> $languages field => lang => file */
    private static function files(array $languages): array
    {
        $files = [];
        foreach ($languages as $field => $byLang) {
            foreach ($byLang as $lang => $file) {
                foreach ($file as $part => $value) { $files[$part][$field][$lang] = $value; }
            }
        }
        return $files;
    }

    private function submit(array $input, array $files = []): array
    {
        return (new SettingsEditor($this->page(), $this->store))->submit($input, $files, static fn (string $tmp): bool => is_file($tmp));
    }

    public function testUploadsOneImagePerLanguageAndFallsBack(): void
    {
        $result = $this->submit(['title' => ['en' => 'Acme']], self::files(['logo' => ['en' => $this->png(), 'ku' => $this->png()]]));
        self::assertTrue($result['saved'], json_encode($result['errors']));
        $logo = $this->store->all()['site.logo'];
        self::assertSame(['en', 'ku'], array_keys($logo));
        self::assertMatchesRegularExpression('~^site/logo/[a-f0-9]{40}\.png$~', $logo['en']);
        self::assertFileExists($this->root . '/public/uploads/settings/' . $logo['ku']);
        self::assertSame('/uploads/settings/' . $logo['en'], Settings::get('site.logo', null, 'ar'), 'Arabic falls back to the English logo');
        self::assertSame('/uploads/settings/' . $logo['ku'], Settings::get('site.logo', null, 'ku'));
    }

    public function testReplacingAndRemovingDeleteOnlyThatLanguagesFile(): void
    {
        $this->submit(['title' => ['en' => 'Acme']], self::files(['logo' => ['en' => $this->png(), 'ku' => $this->png()]]));
        $first = $this->store->all()['site.logo'];

        $this->submit(['title' => ['en' => 'Acme']], self::files(['logo' => ['ku' => $this->png()]]));
        $second = $this->store->all()['site.logo'];
        self::assertSame($first['en'], $second['en']);
        self::assertNotSame($first['ku'], $second['ku']);
        self::assertFileDoesNotExist($this->root . '/public/uploads/settings/' . $first['ku']);
        self::assertFileExists($this->root . '/public/uploads/settings/' . $first['en']);

        $this->submit(['title' => ['en' => 'Acme'], '_remove_upload' => ['logo' => ['ku' => '1']]]);
        self::assertSame(['en' => $first['en']], $this->store->all()['site.logo']);
        self::assertFileDoesNotExist($this->root . '/public/uploads/settings/' . $second['ku']);
    }

    public function testInvalidFileIsReportedOnItsLanguageAndNothingIsStored(): void
    {
        $bad = $this->png();
        file_put_contents($bad['tmp_name'], 'not an image');
        $result = $this->submit(['title' => ['en' => 'Acme']], self::files(['logo' => ['ar' => $bad]]));
        self::assertFalse($result['saved']);
        self::assertArrayHasKey('logo.ar', $result['errors']);
        self::assertSame([], $this->store->all());
        self::assertDirectoryDoesNotExist($this->root . '/public/uploads/settings/site');
    }

    public function testRequiredDefaultLanguageAndRichTextIsCleaned(): void
    {
        $result = $this->submit(['title' => ['en' => '', 'ku' => 'ئەکمی']]);
        self::assertSame(['title'], array_keys($result['errors']));

        $result = $this->submit(['title' => ['en' => 'Acme'], 'about' => ['en' => '<p>Hi<script>alert(1)</script></p>', 'ar' => '<p></p>']]);
        self::assertTrue($result['saved']);
        self::assertSame(['en' => '<p>Hi</p>'], $this->store->all()['site.about']);
    }

    public function testHooksAndUntouchedKeysOfOtherPages(): void
    {
        $this->store->put(['contact.email' => 'a@b.test']);
        $seen = null;
        $page = SettingsPage::make('site')->schema([TextInput::make('title')])
            ->beforeSave(static fn (array $values): array => ['title' => strtoupper((string) $values['title'])])
            ->afterSave(static function (array $values) use (&$seen): void { $seen = $values; });
        $result = (new SettingsEditor($page, $this->store))->submit(['title' => 'acme'], []);
        self::assertTrue($result['saved']);
        self::assertSame(['contact.email' => 'a@b.test', 'site.title' => 'ACME'], $this->store->all());
        self::assertSame(['title' => 'ACME'], $seen);
    }
}
