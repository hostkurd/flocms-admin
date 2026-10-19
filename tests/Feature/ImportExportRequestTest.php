<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Console\Stub;

/**
 * CSV export and import through whole requests (0.25): the download, its
 * permission, the four import steps, Undo, another person's import and the
 * Imports page.
 */
final class ImportExportRequestTest extends RequestTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS admin_import_rows');
        $pdo->exec('DROP TABLE IF EXISTS admin_imports');
        $file = self::$root . '/import-migration.php';
        file_put_contents($file, Stub::render('import-migration', ['imports_table' => 'admin_imports', 'rows_table' => 'admin_import_rows']));
        (require $file)->up(new \FloCMS\Core\Database($pdo));
    }

    protected function tearDown(): void
    {
        // Other tests share the MySQL database: without these tables imports are off for them.
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS admin_import_rows');
        $pdo->exec('DROP TABLE IF EXISTS admin_imports');
        parent::tearDown();
    }

    /** A file as PHP hands an upload to the page. @return array<string, mixed> */
    private static function upload(string $csv, string $name = 'posts.csv'): array
    {
        $path = self::$root . '/upload-' . bin2hex(random_bytes(4)) . '.csv';
        file_put_contents($path, $csv);
        return ['file' => ['name' => $name, 'type' => 'text/csv', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => strlen($csv)]];
    }

    /** @return array<string, mixed> the step's JSON */
    private static function step(int $run, array $options = []): array
    {
        $result = self::post('/en/admin/posts/import/' . $run . '/step', [], $options + ['headers' => ['Accept' => 'application/json']]);
        self::assertSame(200, $result['status'], $result['body']);
        $json = json_decode($result['body'], true);
        self::assertIsArray($json, $result['body']);
        return $json;
    }

    private static function lastRun(): array
    {
        return self::pdo()->query('SELECT * FROM admin_imports ORDER BY id DESC')->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    public function testExportDownloadsTheListAsCsv(): void
    {
        $all = self::request('GET', '/en/admin/posts/export');
        self::assertSame(200, $all['status']);
        self::assertStringStartsWith("\u{FEFF}\"id\",\"title.en\",\"title.ar\",\"title.ku\",\"slug\"", $all['body']);
        self::assertStringContainsString('"Sea view villa","فيلا"', $all['body']);
        self::assertStringContainsString('"Mountain cabin"', $all['body']);
        self::assertStringContainsString('"body.en","body.ar","body.ku","status"', $all['body'], 'a translatable textarea: one column per language');

        $search = self::request('GET', '/en/admin/posts/export?q=cabin');
        self::assertStringNotContainsString('Sea view villa', $search['body']);
        self::assertStringContainsString('Mountain cabin', $search['body']);

        $selected = self::request('GET', '/en/admin/posts/export?ids=1');
        self::assertStringContainsString('Sea view villa', $selected['body']);
        self::assertStringNotContainsString('Mountain cabin', $selected['body']);

        $readable = self::request('GET', '/en/admin/posts/export?headings=readable');
        self::assertStringContainsString('"Published"', $readable['body'], 'option labels');
        self::assertStringContainsString('"Yes"', $readable['body']);

        $template = self::request('GET', '/en/admin/posts/export?template=1');
        self::assertSame("\u{FEFF}\"title.en\",\"title.ar\",\"title.ku\",\"slug\",\"body.en\",\"body.ar\",\"body.ku\",\"status\",\"views\",\"featured\",\"published_on\"\r\n", $template['body']);

        // More rows than the limit: back to the list with a message, no file.
        $tooMany = self::request('GET', '/en/admin/posts/export', ['admin_config' => ['export' => ['limit' => 1]]]);
        self::assertTrue($tooMany['redirected']);
        self::assertStringNotContainsString('Mountain cabin', $tooMany['body']);
        self::assertSame('danger', $tooMany['session']['__flash']['type']);

        $viewer = [1 => ['content.posts.view']];
        self::assertSame(403, self::request('GET', '/en/admin/posts/export', ['permissions' => $viewer])['status']);
        $list = self::request('GET', '/en/admin/posts', ['permissions' => $viewer]);
        self::assertStringNotContainsString('/admin/posts/export', $list['body'], 'no Export menu without the permission');
        self::assertStringNotContainsString('/admin/posts/import', $list['body'], 'no Import button without the permission');
    }

    public function testImportTheFourStepsThenUndo(): void
    {
        $csv = "\u{FEFF}id,Title (English),title.ar,slug,status,views,featured,published_on\r\n"
            . "1,Sea view villa XL,,sea-view-villa,Published,12,yes,19/10/2026\r\n"
            . ",New lake house,بيت,new-lake-house,draft,,no,\r\n"
            . ",,,bad,unknown,abc,maybe,31/31/2026\r\n";
        $uploaded = self::request('POST', '/en/admin/posts/import', ['post' => ['_token' => 'tok'], 'files' => self::upload($csv)]);
        $run = self::lastRun();
        self::assertNotSame([], $run, $uploaded['body']);
        self::assertSame('uploaded', $run['status']);
        self::assertSame('Sara', $run['user_name']);
        self::assertSame(3, (int) $run['rows_total']);
        $id = (int) $run['id'];

        $match = self::request('GET', '/en/admin/posts/import/' . $id . '/match');
        self::assertSame(200, $match['status']);
        self::assertStringContainsString('posts.csv', $match['body']);
        self::assertMatchesRegularExpression('/name="columns\[1\]"[^>]*>.*?<option value="title\.en"\s*selected/s', $match['body']);

        $columns = ['id', 'title.en', 'title.ar', 'slug', 'status', 'views', 'featured', 'published_on'];
        $saved = self::post('/en/admin/posts/import/' . $id . '/match', ['columns' => $columns, 'mode' => 'both', 'key' => 'id', 'date_order' => 'dmy']);
        self::assertTrue($saved['redirected'], $saved['body']);
        $checked = self::step($id);
        self::assertSame('checked', $checked['status']);
        self::assertSame(['update' => 1, 'new' => 1, 'errors' => 1], array_intersect_key($checked['counts'], ['update' => 1, 'new' => 1, 'errors' => 1]));
        self::assertSame('Sea view villa', json_decode((string) self::row('posts', 1)['title'], true)['en'], 'nothing saved while checking');

        $preview = self::request('GET', '/en/admin/posts/import/' . $id . '/preview');
        self::assertStringContainsString('Status must be one of the listed values.', $preview['body']);
        self::assertStringContainsString('Use yes or no (or 1 and 0).', $preview['body']);
        $problems = self::request('GET', '/en/admin/posts/import/' . $id . '/problems');
        self::assertStringContainsString('"bad","unknown"', $problems['body']);
        self::assertStringContainsString('"Errors"', $problems['body']);

        self::assertTrue(self::post('/en/admin/posts/import/' . $id . '/start', [])['redirected']);
        $done = self::step($id);
        self::assertSame('finished', $done['status']);
        self::assertSame(1, $done['counts']['created']);
        self::assertSame(1, $done['counts']['updated']);
        $villa = self::row('posts', 1);
        $title = json_decode((string) $villa['title'], true);
        self::assertSame('Sea view villa XL', $title['en']);
        self::assertSame('', $title['ar'] ?? '', 'an empty cell empties the language, as the preview warned');
        self::assertSame('2026-10-19', substr((string) $villa['published_on'], 0, 10));
        self::assertSame(12, (int) $villa['views']);
        $new = self::pdo()->query("SELECT * FROM posts WHERE slug = 'new-lake-house'")->fetch(\PDO::FETCH_ASSOC);
        self::assertNotFalse($new);
        self::assertSame('draft', $new['status']);

        $result = self::request('GET', '/en/admin/posts/import/' . $id . '/result');
        self::assertStringContainsString('Imported 2 Posts', $result['body']);
        self::assertStringContainsString('/en/admin/posts/import/' . $id . '/undo', $result['body']);

        // Someone else edits the new post: Undo is refused, so that edit is not lost.
        self::pdo()->exec("UPDATE posts SET views = 99 WHERE slug = 'new-lake-house'");
        $refused = self::post('/en/admin/posts/import/' . $id . '/undo', []);
        self::assertStringContainsString('Records edited after the import (1): New lake house.', (string) json_encode($refused['session']) . $refused['body']);
        self::assertSame('finished', self::lastRun()['status']);
        self::pdo()->exec("UPDATE posts SET views = NULL WHERE slug = 'new-lake-house'");

        self::assertTrue(self::post('/en/admin/posts/import/' . $id . '/undo', [])['redirected']);
        self::assertSame('undone', self::step($id)['status']);
        self::assertSame('Sea view villa', json_decode((string) self::row('posts', 1)['title'], true)['en']);
        self::assertSame('فيلا', json_decode((string) self::row('posts', 1)['title'], true)['ar']);
        self::assertFalse(self::pdo()->query("SELECT * FROM posts WHERE slug = 'new-lake-house'")->fetch());

        $imports = self::request('GET', '/en/admin/imports');
        self::assertSame(200, $imports['status']);
        self::assertStringContainsString('posts.csv', $imports['body']);
        self::assertStringContainsString('Undone', $imports['body']);
    }

    public function testPermissionsAndAnotherPersonsImport(): void
    {
        $csv = "title.en,slug,status\r\nGarden flat,garden-flat,draft\r\n";
        $viewer = [1 => ['content.posts.view', 'content.posts.import']];
        self::assertSame(403, self::request('GET', '/en/admin/posts/import', ['permissions' => $viewer])['status'], 'import needs create too');

        $creator = [1 => ['content.posts.view', 'content.posts.create', 'content.posts.import']];
        self::request('POST', '/en/admin/posts/import', ['post' => ['_token' => 'tok'], 'files' => self::upload($csv), 'permissions' => $creator]);
        $id = (int) self::lastRun()['id'];
        $match = self::request('GET', '/en/admin/posts/import/' . $id . '/match', ['permissions' => $creator]);
        self::assertStringNotContainsString('name="mode" value="both"', $match['body'], 'no update modes without the update permission');
        $forced = self::post('/en/admin/posts/import/' . $id . '/match', ['columns' => ['title.en', 'slug', 'status'], 'mode' => 'both', 'key' => 'id', 'date_order' => 'dmy'], ['permissions' => $creator]);
        self::assertTrue($forced['redirected'], $forced['body']);
        self::assertSame('create', self::lastRun()['mode'], 'a posted update mode is not taken without the update permission');

        // Ali did not upload it: he cannot see or run its steps.
        self::assertSame(403, self::request('GET', '/en/admin/posts/import/' . $id . '/match', ['session' => self::ADMIN])['status']);
        self::assertSame(403, self::post('/en/admin/posts/import/' . $id . '/step', [], ['session' => self::ADMIN])['status']);

        // A file that is not CSV text is refused.
        $png = self::request('POST', '/en/admin/posts/import', ['post' => ['_token' => 'tok'], 'files' => self::upload("\x89PNG\r\n\x1a\n\0\0\0", 'photo.png')]);
        self::assertSame($id, (int) self::lastRun()['id'], 'no run for it');
        self::assertStringContainsString('CSV', $png['body']);

        // Without the import tables the page says what to run.
        self::pdo()->exec('DROP TABLE admin_import_rows');
        self::pdo()->exec('DROP TABLE admin_imports');
        $list = self::request('GET', '/en/admin/posts');
        self::assertStringNotContainsString('/admin/posts/import', $list['body']);
        self::assertStringContainsString('/admin/posts/export', $list['body'], 'export needs no table');
    }
}
