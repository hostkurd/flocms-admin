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

    /** An import of posts 1 (updated) and "brand-new" (created), finished. */
    private function finishedImport(): int
    {
        $csv = "id,title.en,slug,status\r\n1,Changed title,sea-view-villa,published\r\n,Brand new,brand-new,draft\r\n";
        self::request('POST', '/en/admin/posts/import', ['post' => ['_token' => 'tok'], 'files' => self::upload($csv)]);
        $id = (int) self::lastRun()['id'];
        self::post('/en/admin/posts/import/' . $id . '/match', ['columns' => ['id', 'title.en', 'slug', 'status'], 'mode' => 'both', 'key' => 'id', 'date_order' => 'dmy']);
        self::step($id);
        self::post('/en/admin/posts/import/' . $id . '/start', []);
        self::assertSame('finished', self::step($id)['status']);
        return $id;
    }

    private static function brandNewExists(): bool
    {
        return (bool) self::pdo()->query("SELECT 1 FROM posts WHERE slug = 'brand-new'")->fetchColumn();
    }

    public function testSomeoneElseUndoesAndFollowsTheProgress(): void
    {
        $id = $this->finishedImport();
        // Ali (Admin) undoes Sara's import: he lands on its progress and may drive it.
        self::assertTrue(self::post('/en/admin/posts/import/' . $id . '/undo', [], ['session' => self::ADMIN])['redirected']);
        self::assertSame(200, self::request('GET', '/en/admin/posts/import/' . $id . '/progress', ['session' => self::ADMIN])['status']);
        self::assertSame('undone', self::step($id, ['session' => self::ADMIN])['status']);
        self::assertFalse(self::brandNewExists());

        // Without delete, an Undo that would delete created records is refused.
        $id = $this->finishedImport();
        $noDelete = [1 => ['content.posts.view', 'content.posts.create', 'content.posts.update', 'content.posts.import']];
        self::assertSame(403, self::post('/en/admin/posts/import/' . $id . '/undo', [], ['permissions' => $noDelete])['status']);
        self::assertStringNotContainsString('/import/' . $id . '/undo', self::request('GET', '/en/admin/posts/import/' . $id . '/result', ['permissions' => $noDelete])['body']);
        self::assertSame('finished', self::lastRun()['status']);
    }

    public function testAnUndoCannotBeDiscardedHalfway(): void
    {
        $id = $this->finishedImport();
        self::post('/en/admin/posts/import/' . $id . '/undo', []);
        self::assertSame('undoing', self::lastRun()['status']);
        self::assertStringNotContainsString('/import/' . $id . '/cancel', self::request('GET', '/en/admin/posts/import')['body'], 'no Discard for an Undo');
        $cancel = self::post('/en/admin/posts/import/' . $id . '/cancel', []);
        self::assertSame('This import is being undone. Let the Undo finish first.', $cancel['session']['__flash']['message']);
        self::assertSame('undoing', self::lastRun()['status']);
        self::assertSame('undone', self::step($id)['status']);
        self::assertSame('Sea view villa', json_decode((string) self::row('posts', 1)['title'], true)['en']);
        self::assertFalse(self::brandNewExists());
    }

    public function testARecordThatCannotGoBackIsKeptAndTheUndoFinishes(): void
    {
        $id = $this->finishedImport();
        // A snapshot with a column the table doesn't have (a query() with a join): only real columns go back.
        $snapshot = json_encode(['row' => ['id' => 1, 'title' => '{"en":"Sea view villa"}', 'joined_name' => 'x'], 'links' => []]);
        self::pdo()->prepare("UPDATE admin_import_rows SET before_values = ? WHERE import_id = ? AND status = 'updated'")->execute([$snapshot, $id]);
        // The created record was edited during the Undo: it is kept.
        self::post('/en/admin/posts/import/' . $id . '/undo', []);
        self::pdo()->exec("UPDATE posts SET views = 5 WHERE slug = 'brand-new'");
        $done = self::step($id);
        self::assertSame('undone', $done['status']);
        self::assertSame(['removed' => 0, 'restored' => 1, 'kept' => 1], array_intersect_key($done['counts'], ['removed' => 1, 'restored' => 1, 'kept' => 1]));
        self::assertSame('Sea view villa', json_decode((string) self::row('posts', 1)['title'], true)['en']);
        self::assertTrue(self::brandNewExists());
        self::assertStringContainsString('Records kept as they are (1)', self::request('GET', '/en/admin/posts/import/' . $id . '/result')['body']);
    }

    public function testACheckBatchRunAgainDoesNotFindItsOwnRows(): void
    {
        $csv = "id,title.en,slug,status\r\n1,Changed title,sea-view-villa,published\r\n";
        self::request('POST', '/en/admin/posts/import', ['post' => ['_token' => 'tok'], 'files' => self::upload($csv)]);
        $id = (int) self::lastRun()['id'];
        self::post('/en/admin/posts/import/' . $id . '/match', ['columns' => ['id', 'title.en', 'slug', 'status'], 'mode' => 'both', 'key' => 'id', 'date_order' => 'dmy']);
        self::step($id);
        // As if the request died after writing its rows, before saving where it was.
        self::pdo()->exec("UPDATE admin_imports SET status = 'checking', position = 0, next_byte = data_start, counts = '{}' WHERE id = " . $id);
        $again = self::step($id);
        self::assertSame(['update' => 1, 'emptied' => 0], $again['counts']);
        self::assertSame(1, (int) self::pdo()->query('SELECT COUNT(*) FROM admin_import_rows WHERE import_id = ' . $id)->fetchColumn());
    }

    public function testAnImportWhoseFileWasRemovedStopsWithAMessage(): void
    {
        $csv = "id,title.en,slug,status\r\n,Brand new,brand-new,draft\r\n";
        self::request('POST', '/en/admin/posts/import', ['post' => ['_token' => 'tok'], 'files' => self::upload($csv)]);
        $run = self::lastRun();
        $id = (int) $run['id'];
        self::post('/en/admin/posts/import/' . $id . '/match', ['columns' => ['id', 'title.en', 'slug', 'status'], 'mode' => 'create', 'key' => 'id', 'date_order' => 'dmy']);
        self::step($id);
        self::post('/en/admin/posts/import/' . $id . '/start', []);
        foreach (glob(self::$root . '/storage/admin-imports/' . $run['file_path'] . '*') ?: [] as $file) { unlink($file); }
        $stopped = self::step($id);
        self::assertSame('failed', $stopped['status']);
        self::assertStringContainsString('The uploaded file was removed', self::request('GET', '/en/admin/posts/import/' . $id . '/result')['body']);
    }

    public function testKeysMatchExactlyAndUploadStepsArePostOnly(): void
    {
        // "1abc" is not record 1 (MySQL would compare it as 1); "01" is record 1.
        $csv = "id,title.en,slug,status\r\n1abc,Wrong,sea-view-villa,published\r\n01,Right,sea-view-villa,published\r\n";
        self::request('POST', '/en/admin/posts/import', ['post' => ['_token' => 'tok'], 'files' => self::upload($csv)]);
        $id = (int) self::lastRun()['id'];
        self::post('/en/admin/posts/import/' . $id . '/match', ['columns' => ['id', 'title.en', 'slug', 'status'], 'mode' => 'update', 'key' => 'id', 'date_order' => 'dmy']);
        $checked = self::step($id);
        self::assertSame(1, $checked['counts']['update']);
        self::assertSame(1, $checked['counts']['errors']);
        self::assertSame('1', (string) self::pdo()->query("SELECT key_value FROM admin_import_rows WHERE import_id = $id AND status = 'update'")->fetchColumn());

        self::assertSame(405, self::request('GET', '/en/admin/posts/import/upload/start?name=a.csv&bytes=10')['status']);
    }

    public function testOldMacLineEndsAndLateWindowsLetters(): void
    {
        self::request('POST', '/en/admin/posts/import', ['post' => ['_token' => 'tok'], 'files' => self::upload("title.en,slug,status\r,Mac one,mac-one,draft\r,Mac two,mac-two,draft\r")]);
        self::assertSame(2, (int) self::lastRun()['rows_total'], 'lines that end in CR alone');

        // Plain ASCII for more than 64 KB, then a Windows-1252 "é".
        $csv = "title.en,slug,status\r\n" . str_repeat("Plain,plain,draft\r\n", 4000) . "Caf\xE9,cafe,draft\r\n";
        self::request('POST', '/en/admin/posts/import', ['post' => ['_token' => 'tok'], 'files' => self::upload($csv)]);
        $run = self::lastRun();
        self::assertSame(4001, (int) $run['rows_total']);
        self::assertSame('windows-1256', $run['encoding'], 'the fallback of a site with Arabic; 0xE9 is é there too');
    }

    public function testRichTextIsImportedAndCleanedLikeTheEditor(): void
    {
        $articles = ['admin_config' => ['resources' => [\FloCMS\Admin\Tests\Fixtures\Resources\ArticleResource::class]]];
        $csv = "\u{FEFF}title.en,body.en,body.ar,note\r\n"
            . "Guide,\"<p>Hi <strong>there</strong></p><script>alert(1)</script><img src=\"\"/uploads/editor/a.jpg\"\" alt=\"\"A\"\"><img src=\"\"https://elsewhere.example/b.jpg\"\">\",\"سطر أول\nسطر ثانٍ\n\nفقرة\",Plain <note> & more\r\n";
        $uploaded = self::request('POST', '/en/admin/articles/import', $articles + ['post' => ['_token' => 'tok'], 'files' => self::upload($csv, 'articles.csv')]);
        self::assertNotSame([], self::lastRun(), $uploaded['status'] . ' ' . strip_tags($uploaded['body']));
        $id = (int) self::lastRun()['id'];
        $match = self::request('GET', '/en/admin/articles/import/' . $id . '/match', $articles);
        self::assertMatchesRegularExpression('/name="columns\[1\]"[^>]*>.*?<option value="body\.en"\s*selected/s', $match['body'], 'rich text has a column now');

        self::assertTrue(self::post('/en/admin/articles/import/' . $id . '/match', ['columns' => ['title.en', 'body.en', 'body.ar', 'note'], 'mode' => 'create', 'key' => 'id', 'date_order' => 'dmy'], $articles)['redirected']);
        $checked = self::post('/en/admin/articles/import/' . $id . '/step', [], $articles + ['headers' => ['Accept' => 'application/json']]);
        $counts = json_decode($checked['body'], true)['counts'];
        self::assertSame(1, $counts['new'], $checked['body']);
        self::assertSame(1, $counts['media_dropped']);
        self::assertStringContainsString('Images and videos from other websites are left out of rich text (1).', self::request('GET', '/en/admin/articles/import/' . $id . '/preview', $articles)['body']);

        self::post('/en/admin/articles/import/' . $id . '/start', [], $articles);
        self::post('/en/admin/articles/import/' . $id . '/step', [], $articles + ['headers' => ['Accept' => 'application/json']]);
        $row = self::pdo()->query("SELECT * FROM articles WHERE title LIKE '%Guide%'")->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        $body = json_decode((string) $row['body'], true);
        self::assertStringContainsString('<p>Hi <strong>there</strong></p>', $body['en']);
        self::assertStringContainsString('src="/uploads/editor/a.jpg"', $body['en']);
        self::assertStringNotContainsString('script', $body['en']);
        self::assertStringNotContainsString('elsewhere.example', $body['en']);
        self::assertSame('<p>سطر أول<br>سطر ثانٍ</p><p>فقرة</p>', $body['ar'], 'plain text becomes paragraphs');
        self::assertSame('<p>Plain &lt;note&gt; &amp; more</p>', $row['note'], 'not HTML: escaped');
    }
}
