<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Console\Stub;
use FloCMS\Admin\Tests\Fixtures\Choices\JournalResource;

/** Tags per language (0.44): the form, the list, the view page, export and import. */
final class TranslatableTagsRequestTest extends RequestTestCase
{
    private static function options(array $extra = []): array
    {
        return ['admin_config' => ['resources' => [JournalResource::class]]] + $extra;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS journal');
        self::createTable($pdo, 'CREATE TABLE journal (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NULL, tags TEXT NULL)');
        $insert = $pdo->prepare('INSERT INTO journal (title, tags) VALUES (?, ?)');
        $insert->execute(['{"en":"Choosing a stone","ar":"اختيار الحجر"}', '{"en":["stone","courtyard"],"ar":["حجر"]}']);
        $insert->execute(['{"en":"Lime plaster"}', '["lime"]']);
        $pdo->exec('DROP TABLE IF EXISTS admin_import_rows');
        $pdo->exec('DROP TABLE IF EXISTS admin_imports');
        $file = self::$root . '/import-migration.php';
        file_put_contents($file, Stub::render('import-migration', ['imports_table' => 'admin_imports', 'rows_table' => 'admin_import_rows']));
        (require $file)->up(new \FloCMS\Core\Database($pdo));
    }

    protected function tearDown(): void
    {
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS admin_import_rows');
        $pdo->exec('DROP TABLE IF EXISTS admin_imports');
        $pdo->exec('DROP TABLE IF EXISTS journal');
        parent::tearDown();
    }

    public function testTheFormHasTagsInEachLanguageAndSavesThem(): void
    {
        $page = self::request('GET', '/en/admin/journal/edit/1', self::options());
        self::assertSame(200, $page['status'], (string) $page['error']);
        self::assertStringContainsString('name="tags[en]"', $page['body']);
        self::assertStringContainsString('name="tags[ar]"', $page['body']);
        self::assertMatchesRegularExpression('~name="tags\[ar\]"[^>]*value="حجر"~u', $page['body'], 'one text box per language without JavaScript');

        $saved = self::post('/en/admin/journal/edit/1', [
            'title' => ['en' => 'Choosing a stone', 'ar' => 'اختيار الحجر', 'ku' => ''],
            'tags' => ['en' => 'stone, Courtyard, outdoor', 'ar' => 'حجر، فناء', 'ku' => ''],
        ], self::options());
        self::assertTrue($saved['redirected'], $saved['body']);
        self::assertSame(['en' => ['stone', 'Courtyard', 'outdoor'], 'ar' => ['حجر', 'فناء']], json_decode((string) self::row('journal', 1)['tags'], true));

        $tooMany = self::post('/en/admin/journal/edit/1', [
            'title' => ['en' => 'Choosing a stone'],
            'tags' => ['en' => 'a', 'ar' => 'ا, ب, ج, د'],
        ], self::options());
        self::assertFalse($tooMany['redirected']);
        self::assertStringContainsString('at most 3 tags', $tooMany['body']);
    }

    public function testAListFromBeforeIsTheDefaultLanguages(): void
    {
        $page = self::request('GET', '/en/admin/journal/edit/2', self::options());
        self::assertMatchesRegularExpression('~name="tags\[en\]"[^>]*value="lime"~', $page['body']);
        self::post('/en/admin/journal/edit/2', ['title' => ['en' => 'Lime plaster'], 'tags' => ['en' => 'lime', 'ar' => 'جير']], self::options());
        self::assertSame(['en' => ['lime'], 'ar' => ['جير']], json_decode((string) self::row('journal', 2)['tags'], true), 'rewritten per language on save');
        // A language the panel doesn't edit any more keeps its tags
        self::pdo()->exec('UPDATE journal SET tags = \'{"en":["lime"],"fr":["chaux"]}\' WHERE id = 2');
        self::post('/en/admin/journal/edit/2', ['title' => ['en' => 'Lime plaster'], 'tags' => ['en' => 'lime, walls']], self::options());
        self::assertSame(['en' => ['lime', 'walls'], 'fr' => ['chaux']], json_decode((string) self::row('journal', 2)['tags'], true));
    }

    public function testTheListTheViewPageAndTheFilter(): void
    {
        $list = self::request('GET', '/en/admin/journal', self::options());
        self::assertStringContainsString('<span class="fa-tag">stone</span><span class="fa-tag">courtyard</span>', $list['body']);
        self::assertStringContainsString('<span class="fa-tag">lime</span>', $list['body']);
        $arabic = self::request('GET', '/ar/admin/journal', self::options());
        self::assertStringContainsString('<span class="fa-tag">حجر</span>', $arabic['body'], 'the page\'s language');
        $filtered = self::request('GET', '/en/admin/journal?filter%5Btags%5D=courtyard', self::options());
        self::assertStringContainsString('Choosing a stone', $filtered['body']);
        self::assertStringNotContainsString('Lime plaster', $filtered['body']);
        $byKey = self::request('GET', '/en/admin/journal?filter%5Btags%5D=ar', self::options());
        self::assertStringNotContainsString('Choosing a stone', $byKey['body'], 'a language code is not a tag');
        $view = self::request('GET', '/ar/admin/journal/view/1', self::options());
        self::assertStringContainsString('<span class="fa-view-chips"><span>حجر</span></span>', $view['body']);
    }

    public function testExportAndImportPerLanguage(): void
    {
        $csv = self::request('GET', '/en/admin/journal/export', self::options());
        self::assertSame(200, $csv['status'], (string) $csv['error']);
        self::assertStringContainsString('"stone | courtyard","حجر",""', $csv['body']);

        $file = self::$root . '/upload-tags.csv';
        file_put_contents($file, "id,title.en,tags.en,tags.ar\r\n2,Lime plaster,lime | walls,جير | جدران\r\n");
        $uploaded = self::request('POST', '/en/admin/journal/import', self::options(['post' => ['_token' => 'tok'],
            'files' => ['file' => ['name' => 'journal.csv', 'type' => 'text/csv', 'tmp_name' => $file, 'error' => UPLOAD_ERR_OK, 'size' => filesize($file)]]]));
        $run = self::pdo()->query('SELECT * FROM admin_imports ORDER BY id DESC')->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($run, $uploaded['body']);
        $id = (int) $run['id'];
        $matched = self::post('/en/admin/journal/import/' . $id . '/match', ['columns' => ['id', 'title.en', 'tags.en', 'tags.ar'], 'mode' => 'both', 'key' => 'id', 'date_order' => 'dmy'], self::options());
        self::assertTrue($matched['redirected'], $matched['body']);
        $step = fn (): array => json_decode(self::post('/en/admin/journal/import/' . $id . '/step', [], self::options(['headers' => ['Accept' => 'application/json']]))['body'], true);
        self::assertSame('checked', $step()['status']);
        self::assertTrue(self::post('/en/admin/journal/import/' . $id . '/start', [], self::options())['redirected']);
        self::assertSame('finished', $step()['status']);
        self::assertSame(['en' => ['lime', 'walls'], 'ar' => ['جير', 'جدران']], json_decode((string) self::row('journal', 2)['tags'], true));
    }
}
