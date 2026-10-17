<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Feature;

use FloCMS\Admin\Tests\Fixtures\Media\ListingResource;
use FloCMS\Admin\Tests\Fixtures\Media\MediaTables;

/** The media library through whole requests (uploads themselves are in the browser tests). */
final class MediaRequestTest extends RequestTestCase
{
    private static function options(array $extra = []): array
    {
        return ['admin_config' => ($extra['admin_config'] ?? []) + ['resources' => [ListingResource::class], 'media' => ['root' => self::$root . '/public/uploads/editor', 'url' => '/uploads/editor']]] + $extra;
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::pdo();
        $pdo->exec('DROP TABLE IF EXISTS listings');
        self::createTable($pdo, 'CREATE TABLE listings (id INTEGER PRIMARY KEY AUTOINCREMENT, title VARCHAR(190) NOT NULL DEFAULT "", cover_id INTEGER NULL, gallery TEXT NULL, video_id INTEGER NULL, body TEXT NULL)');
        $db = new \FloCMS\Core\Database($pdo);
        MediaTables::create($db);
        MediaTables::seed($db, self::$root . '/public/uploads/editor');
        $pdo->exec("INSERT INTO listings (title, cover_id) VALUES ('Villa', 1)");
        $pdo->exec("INSERT INTO media_usage (media_id, resource, record_id, field) VALUES (1, 'listings', '1', 'cover_id')");
    }

    /** @return list<string> */
    private static function usage(int $id): array
    {
        return array_map(static fn (array $r): string => $r['resource'] . '#' . $r['record_id'] . '.' . $r['field'],
            self::pdo()->query('SELECT resource, record_id, field FROM media_usage WHERE media_id = ' . $id . ' ORDER BY field')->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function testMediaPageShowsTheGridNewestFirstWithUnusedMarks(): void
    {
        $response = self::request('GET', '/en/admin/media', self::options());
        self::assertSame(200, $response['status'], (string) $response['error']);
        $body = $response['body'];
        self::assertStringContainsString('<h1 class="fa-page-title">Media</h1>', $body);
        self::assertStringContainsString('href="https://example.test/en/admin/media" aria-current="page"', $body, 'the sidebar entry');
        self::assertSame(4, substr_count($body, 'class="fa-media-link"'));
        self::assertMatchesRegularExpression('~media/edit/4.*media/edit/3.*media/edit/2.*media/edit/1~s', $body);
        self::assertStringContainsString('src="/uploads/editor/library/2026/10/thumb/sea.jpg" alt="Sea at sunset" loading="lazy" width="400" height="267"', $body);
        self::assertSame(3, substr_count($body, '>Not used</span>'), 'the cover is used');
        self::assertStringContainsString('name="files[]" multiple', $body);
        // Sizes are one left-to-right piece (LRI … PDI), so Arabic text keeps "10 MB" in order.
        self::assertStringContainsString("Images (JPEG, PNG, GIF, WebP) up to \u{2066}10\u{00A0}MB\u{2069}, videos (MP4, WebM) up to \u{2066}200\u{00A0}MB\u{2069}.", $body);
    }

    public function testFiltersSearchAllLanguagesKindAndUnused(): void
    {
        $count = static fn (string $query): int => substr_count(self::request('GET', '/en/admin/media' . $query, self::options())['body'], 'class="fa-media-link"');
        self::assertSame(1, $count('?q=' . rawurlencode('الغروب')));
        self::assertSame(1, $count('?kind=video'));
        self::assertSame(3, $count('?unused=1'));
        self::assertSame(2, $count('?kind=image&unused=1&q=a'));
        self::assertStringContainsString('Nothing matches these filters.', self::request('GET', '/en/admin/media?q=zzz', self::options())['body']);
        self::assertSame(200, self::request('GET', '/en/admin/media?kind=../x&page=-3', self::options())['status']);
    }

    public function testPermissions(): void
    {
        $viewOnly = [0 => [], 1 => ['content.media.view']];
        $body = self::request('GET', '/en/admin/media', self::options(['permissions' => $viewOnly]))['body'];
        self::assertStringNotContainsString('name="files[]"', $body, 'no upload without create');
        self::assertStringNotContainsString('name="ids[]"', $body, 'no delete without delete');
        $page = self::request('GET', '/en/admin/media/edit/2', self::options(['permissions' => $viewOnly]))['body'];
        self::assertStringContainsString('<fieldset class="fa-media-fields" disabled', $page);
        self::assertStringNotContainsString('fa-media-replace', $page);
        self::assertSame(403, self::post('/en/admin/media/edit/2', ['title' => 'x', 'alt' => ['en' => '']], self::options(['permissions' => $viewOnly]))['status']);
        self::assertSame(403, self::post('/en/admin/media/delete/2', [], self::options(['permissions' => $viewOnly]))['status']);
        self::assertSame(403, self::post('/en/admin/media/upload', [], self::options(['permissions' => $viewOnly]))['status']);

        $none = [0 => [], 1 => ['content.listings.view']];
        self::assertSame(403, self::request('GET', '/en/admin/media', self::options(['permissions' => $none]))['status']);
        self::assertSame(403, self::request('GET', '/en/admin/media/lookup', self::options(['permissions' => $none]))['status']);
        self::assertStringNotContainsString('/admin/media"', self::request('GET', '/en/admin/listings', self::options(['permissions' => $none]))['body'], 'no sidebar entry');
    }

    public function testLookupForThePicker(): void
    {
        $data = json_decode(self::request('GET', '/en/admin/media/lookup?kind=image&q=sea', self::options())['body'], true);
        self::assertSame(['page' => 1, 'pages' => 1, 'total' => 1], array_diff_key($data, ['items' => 1]));
        self::assertSame([1, 'image', '/uploads/editor/library/2026/10/web/sea.jpg', '/uploads/editor/library/2026/10/thumb/sea.jpg', 'Sea view', 'Sea at sunset'],
            [$data['items'][0]['id'], $data['items'][0]['kind'], $data['items'][0]['url'], $data['items'][0]['thumb'], $data['items'][0]['label'], $data['items'][0]['alt']]);
        self::assertSame([4], array_column(json_decode(self::request('GET', '/en/admin/media/lookup?kind=video', self::options())['body'], true)['items'], 'id'));
        self::assertSame(405, self::post('/en/admin/media/lookup', [], self::options())['status']);
    }

    public function testDetailsPageSavesTitleAndAltPerLanguage(): void
    {
        $page = self::request('GET', '/en/admin/media/edit/1', self::options())['body'];
        self::assertStringContainsString('<h1 class="fa-page-title" dir="auto">Sea view</h1>', $page);
        self::assertStringContainsString('1200 × 800 px', $page);
        self::assertStringContainsString('value="https://example.test/uploads/editor/library/2026/10/web/sea.jpg"', $page);
        self::assertStringContainsString('>Listings · Villa · Cover</a>', $page);
        self::assertStringContainsString('This item is used, so it cannot be deleted.', $page);
        self::assertSame(404, self::request('GET', '/en/admin/media/edit/99', self::options())['status']);

        $saved = self::post('/en/admin/media/edit/2', ['title' => ' Garden ', 'alt' => ['en' => 'Green garden', 'ar' => 'حديقة خضراء', 'ku' => '']], self::options());
        self::assertTrue($saved['redirected']);
        $row = self::pdo()->query('SELECT title, alt FROM media WHERE id = 2')->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['Garden', '{"en":"Green garden","ar":"حديقة خضراء"}'], [$row['title'], $row['alt']]);
        $long = self::post('/en/admin/media/edit/2', ['title' => str_repeat('x', 191), 'alt' => ['en' => '']], self::options());
        self::assertFalse($long['redirected']);
        self::assertSame(200, $long['status']);
    }

    public function testDeletingRefusesItemsInUseAndRemovesFilesOfOthers(): void
    {
        $media = self::$root . '/public/uploads/editor';
        $kept = self::post('/en/admin/media/delete/1', [], self::options());
        self::assertTrue($kept['redirected']);
        self::assertNotNull(self::pdo()->query('SELECT id FROM media WHERE id = 1')->fetchColumn() ?: null);
        self::assertFileExists($media . '/library/2026/10/web/sea.jpg');

        $bulk = self::post('/en/admin/media/delete', ['ids' => ['1', '2', '3']], self::options());
        self::assertTrue($bulk['redirected']);
        self::assertSame(['1', '4'], array_map('strval', self::pdo()->query('SELECT id FROM media ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)));
        self::assertFileDoesNotExist($media . '/library/2026/10/web/garden.png');
        self::assertFileDoesNotExist($media . '/library/2026/10/thumb/garden.png');
        self::assertFileExists($media . '/library/2026/10/web/sea.jpg');
        self::assertSame(400, self::post('/en/admin/media/delete', ['ids' => ['x']], self::options())['status']);
    }

    public function testPickerFieldsSaveIdsCheckKindsAndRecordUsage(): void
    {
        $form = self::request('GET', '/en/admin/listings/edit/1', self::options())['body'];
        self::assertStringContainsString('data-admin-media-field data-kind="image" data-multiple="false"', $form);
        self::assertStringContainsString('data-admin-media-field data-kind="image" data-multiple="true" data-max="3"', $form);
        self::assertStringContainsString('data-admin-media-field data-kind="video"', $form);
        self::assertMatchesRegularExpression('~<option value="1" selected>Sea view \(#1\)</option>~', $form, 'the select without JavaScript');
        self::assertStringContainsString('data-admin-media="', $form, 'the panel carries the picker settings');

        $saved = self::post('/en/admin/listings/edit/1', ['title' => 'Villa', 'cover_id' => '3', 'gallery' => '["2","1"]', 'video_id' => '4',
            'body' => ['en' => '<p><img src="/uploads/editor/library/2026/10/web/hall.webp" alt=""></p>']], self::options());
        self::assertTrue($saved['redirected'], $saved['body']);
        $row = self::row('listings', 1);
        self::assertSame(['3', '["2","1"]', '4'], [(string) $row['cover_id'], $row['gallery'], (string) $row['video_id']]);
        self::assertSame(['listings#1.gallery'], self::usage(1));
        self::assertSame(['listings#1.gallery'], self::usage(2));
        self::assertSame(['listings#1.body', 'listings#1.cover_id'], self::usage(3));
        self::assertSame(['listings#1.video_id'], self::usage(4));

        foreach ([['cover_id' => '4'], ['video_id' => '1'], ['cover_id' => '99'], ['gallery' => '["1","2","3","4"]']] as $bad) {
            $response = self::post('/en/admin/listings/edit/1', $bad + ['title' => 'Villa'], self::options());
            self::assertFalse($response['redirected'], json_encode($bad));
        }
        self::assertSame('3', (string) self::row('listings', 1)['cover_id']);
    }

    public function testDeletingARecordForgetsItsUsage(): void
    {
        self::post('/en/admin/listings/delete/1', [], self::options());
        self::assertSame([], self::usage(1));
    }

    public function testListColumnShowsThumbnails(): void
    {
        self::pdo()->exec("UPDATE listings SET gallery = '[\"2\",\"3\",\"1\"]' WHERE id = 1");
        $body = self::request('GET', '/en/admin/listings', self::options())['body'];
        self::assertStringContainsString('<img class="fa-media-thumb" src="/uploads/editor/library/2026/10/thumb/sea.jpg" alt="Sea at sunset" loading="lazy" width="400" height="267">', $body);
        self::assertStringContainsString('src="/uploads/editor/library/2026/10/web/hall.webp"', $body, 'no thumb: the web version');
        self::assertStringContainsString('+1', $body);
    }

    public function testSwitchedOffTheLibraryHasNoPageAndTheFieldSaysSo(): void
    {
        $off = self::options(['admin_config' => ['media_library' => false]]);
        self::assertSame(404, self::request('GET', '/en/admin/media', $off)['status']);
        $form = self::request('GET', '/en/admin/listings/edit/1', $off)['body'];
        self::assertStringContainsString('The media library is not set up', $form);
        self::assertStringNotContainsString('data-admin-media="', $form);
    }

    public function testArabicPage(): void
    {
        $body = self::request('GET', '/ar/admin/media', self::options())['body'];
        self::assertStringContainsString('<h1 class="fa-page-title">الوسائط</h1>', $body);
        self::assertStringContainsString('alt="البحر عند الغروب"', $body);
    }
}
