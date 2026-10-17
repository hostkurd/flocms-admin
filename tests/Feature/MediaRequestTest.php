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
        self::assertSame(4, substr_count($body, 'class="fa-mb-link"'));
        self::assertMatchesRegularExpression('~media/edit/4.*media/edit/3.*media/edit/2.*media/edit/1~s', $body);
        // The name is under the thumbnail, so the picture itself is decorative here.
        self::assertStringContainsString('src="/uploads/editor/library/2026/10/thumb/sea.jpg" alt="" loading="lazy" width="400" height="267"', $body);
        self::assertSame(3, substr_count($body, '>Not used</span>'), 'the cover is used');
        self::assertSame(1, substr_count($body, '>No alt text</span>'), 'garden.png has none');
        self::assertMatchesRegularExpression('~<input data-media-files type="file" id="fa-media-files" name="files\[\]"[^>]* multiple>~', $body);
        // The header's numbers, the browser's start state for the script, and the folders.
        self::assertStringContainsString('<span class="fa-mb-stat"><b>4</b> items</span>', $body);
        self::assertStringContainsString('href="https://example.test/en/admin/media?status=no_alt"><b>1</b> without alt text</a>', $body);
        self::assertStringContainsString('data-media-browser="', $body);
        self::assertStringContainsString('aria-current="true"><svg', $body, 'All media is the current folder');
        // Sizes are one left-to-right piece (LRI … PDI), so Arabic text keeps "10 MB" in order.
        self::assertStringContainsString("Images (JPEG, PNG, GIF, WebP) up to \u{2066}10\u{00A0}MB\u{2069}, videos (MP4, WebM) up to \u{2066}200\u{00A0}MB\u{2069}.", $body);
    }

    public function testFiltersSearchAllLanguagesKindAndUnused(): void
    {
        $count = static fn (string $query): int => substr_count(self::request('GET', '/en/admin/media' . $query, self::options())['body'], 'class="fa-mb-link"');
        self::assertSame(1, $count('?q=' . rawurlencode('الغروب')));
        self::assertSame(1, $count('?kind=video'));
        self::assertSame(3, $count('?unused=1'));
        self::assertSame(2, $count('?kind=image&unused=1&q=a'));
        self::assertSame(3, $count('?status=unused'));
        self::assertSame(1, $count('?status=no_alt'));
        self::assertSame(0, $count('?folder=unfiled&q=zzz'));
        self::assertMatchesRegularExpression('~media/edit/1.*media/edit/2.*media/edit/3.*media/edit/4~s', self::request('GET', '/en/admin/media?sort=oldest', self::options())['body']);
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
        foreach (['/en/admin/media/save/2', '/en/admin/media/move', '/en/admin/media/folders/create', '/en/admin/media/pending'] as $url) {
            self::assertSame(403, self::post($url, ['ids' => ['2'], 'name' => 'x'], self::options(['permissions' => $viewOnly]))['status'], $url);
        }
        self::assertStringNotContainsString('data-media-new-folder', $body, 'no folders without update');
        self::assertStringNotContainsString('fa-mb-folder-form', $body);
        $config = json_decode(html_entity_decode((string) preg_replace('~^.*data-admin-media="([^"]*)".*$~s', '$1', $body)), true);
        self::assertSame(['create' => false, 'update' => false, 'delete' => false], $config['can']);

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

        // Saving keeps the stored ids: the field had no input, so it must not write NULL.
        self::pdo()->exec("UPDATE listings SET gallery = '[\"2\"]' WHERE id = 1");
        $saved = self::post('/en/admin/listings/edit/1', ['title' => 'Villa house'], $off);
        self::assertTrue($saved['redirected'], $saved['body']);
        $row = self::row('listings', 1);
        self::assertSame(['Villa house', '1', '["2"]'], [$row['title'], (string) $row['cover_id'], $row['gallery']]);
    }

    public function testArabicPage(): void
    {
        $body = self::request('GET', '/ar/admin/media', self::options())['body'];
        self::assertStringContainsString('<h1 class="fa-page-title">الوسائط</h1>', $body);
        self::assertStringContainsString('<span class="fa-mb-name" dir="auto">Sea view</span>', $body);
        self::assertStringContainsString('كل الوسائط', $body);
    }

    /** @return array<string, mixed> */
    private static function json(string $method, string $uri, array $data = [], array $options = []): array
    {
        $options = self::options($options + ['headers' => ['Accept' => 'application/json']]);
        $response = $method === 'GET' ? self::request('GET', $uri, $options) : self::post($uri, $data, $options);
        $decoded = json_decode($response['body'], true);
        self::assertIsArray($decoded, $response['body']);
        return $decoded + ['_status' => $response['status']];
    }

    public function testLookupHasFoldersStatsSortsAndScopes(): void
    {
        $folders = self::json('POST', '/en/admin/media/folders/create', ['name' => 'Homes']);
        self::assertSame(200, $folders['_status']);
        $homes = $folders['id'];
        self::assertSame('Folder “Homes” created.', $folders['message']);
        self::json('POST', '/en/admin/media/move', ['ids' => ['1', '3'], 'folder' => (string) $homes]);

        $data = self::json('GET', '/en/admin/media/lookup?tree=1&stats=1');
        self::assertSame([['id' => $homes, 'parent' => null, 'name' => 'Homes', 'depth' => 0, 'count' => 2, 'children' => 0]],
            array_map(static fn (array $f): array => array_intersect_key($f, array_flip(['id', 'parent', 'name', 'depth', 'count', 'children'])), $data['folders']['tree']));
        self::assertSame(['all' => 4, 'unfiled' => 2, (string) $homes => 2], $data['folders']['counts']);
        self::assertSame(['items' => 4, 'images' => 3, 'videos' => 1, 'no_alt' => 1], array_intersect_key($data['stats'], array_flip(['items', 'images', 'videos', 'no_alt'])));
        self::assertSame([0, 0, 0, 1], array_column($data['items'], 'used'), 'newest first; the cover is used');

        $ids = static fn (string $query): array => array_column(self::json('GET', '/en/admin/media/lookup' . $query)['items'], 'id');
        self::assertSame([3, 1], $ids('?folder=' . $homes));
        self::assertSame([4, 2], $ids('?folder=unfiled'));
        self::assertSame([1], $ids('?folder=unfiled&q=sea'), 'a search looks everywhere');
        self::assertSame([], $ids('?folder=unfiled&q=sea&scope=folder'), 'unless it stays in the folder');
        self::assertSame([1, 2, 3, 4], $ids('?sort=oldest'));
        self::assertSame([2], $ids('?status=no_alt'));
        self::assertSame([4, 3], $ids('?per=2'));
        self::assertSame([4, 3, 2, 1], $ids('?folder=999'), 'an unknown folder is ignored');
        self::assertArrayNotHasKey('folders', self::json('GET', '/en/admin/media/lookup'));
    }

    public function testDetailsAndSavingFromTheSidePanel(): void
    {
        $homes = self::json('POST', '/en/admin/media/folders/create', ['name' => 'Homes'])['id'];
        $details = self::json('GET', '/en/admin/media/details/1')['item'];
        self::assertSame(['Sea view', 'Sea at sunset', 1, true], [$details['title'], $details['alt'], $details['used'], $details['hasAlt']]);
        self::assertSame('Listings · Villa · Cover', $details['usage'][0]['label']);
        self::assertSame('https://example.test/en/admin/media/save/1', $details['saveUrl']);
        self::assertSame(404, self::request('GET', '/en/admin/media/details/99', self::options(['headers' => ['Accept' => 'application/json'], 'expect_error' => true]))['status']);

        $saved = self::json('POST', '/en/admin/media/save/2', ['title' => 'Garden', 'alt' => ['en' => 'Green garden', 'ar' => '', 'ku' => ''], 'folder' => (string) $homes, 'focal_x' => '30', 'focal_y' => '70']);
        self::assertSame(['Media item saved.', 'Garden', $homes, 30, 70, true], [$saved['message'], $saved['item']['title'], $saved['item']['folder'], $saved['item']['focalX'], $saved['item']['focalY'], $saved['item']['hasAlt']]);
        self::assertSame('Homes', $saved['item']['folderLabel']);

        // Without folder and focal fields, they stay; an empty focal clears it; Unfiled is "".
        self::json('POST', '/en/admin/media/save/2', ['title' => 'Garden 2', 'alt' => ['en' => 'Green garden']]);
        $row = self::row('media', 2);
        self::assertSame(['Garden 2', (string) $homes, '30', '70'], [$row['title'], (string) $row['folder_id'], (string) $row['focal_x'], (string) $row['focal_y']]);
        self::json('POST', '/en/admin/media/save/2', ['title' => 'Garden 2', 'alt' => ['en' => 'Green garden'], 'folder' => '', 'focal_x' => '', 'focal_y' => '']);
        $row = self::row('media', 2);
        self::assertSame([null, null, null], [$row['folder_id'], $row['focal_x'], $row['focal_y']]);
        self::json('POST', '/en/admin/media/save/2', ['title' => 'Garden 2', 'alt' => ['en' => ''], 'focal_x' => '250', 'focal_y' => '-1']);
        self::assertNull(self::row('media', 2)['focal_x'], 'out of range: no point');

        $bad = self::json('POST', '/en/admin/media/save/2', ['title' => str_repeat('x', 191), 'alt' => ['en' => '']]);
        self::assertSame(422, $bad['_status']);
        self::assertArrayHasKey('title', $bad['errors']);
        self::assertSame(422, self::json('POST', '/en/admin/media/save/2', ['title' => 'x', 'alt' => ['en' => ''], 'folder' => '999'])['_status']);
        self::assertSame('Garden 2', self::row('media', 2)['title']);

        // A plain form goes back to the item's page.
        self::assertTrue(self::post('/en/admin/media/save/2', ['title' => 'Plain', 'alt' => ['en' => '']], self::options())['redirected']);
        self::assertSame('Plain', self::row('media', 2)['title']);
    }

    public function testFoldersAreCreatedRenamedMovedAndDeleted(): void
    {
        $homes = self::json('POST', '/en/admin/media/folders/create', ['name' => 'Homes'])['id'];
        $villas = self::json('POST', '/en/admin/media/folders/create', ['name' => 'Villas', 'parent' => (string) $homes])['id'];
        $blog = self::json('POST', '/en/admin/media/folders/create', ['name' => 'Blog'])['id'];
        self::assertSame(['Blog', 'Homes', 'Villas'], array_column(self::json('GET', '/en/admin/media/lookup?tree=1')['folders']['tree'], 'name'));

        self::assertSame(422, self::json('POST', '/en/admin/media/folders/create', ['name' => 'Villas', 'parent' => (string) $homes])['_status'], 'taken');
        self::assertSame(422, self::json('POST', '/en/admin/media/folders/create', ['name' => 'a/b'])['_status']);
        self::assertSame(422, self::json('POST', '/en/admin/media/folders/create', ['name' => ' '])['_status']);
        self::assertSame(422, self::json('POST', '/en/admin/media/folders/create', ['name' => 'X', 'parent' => '999'])['_status']);
        self::assertSame(422, self::json('POST', '/en/admin/media/folders/move/' . $homes, ['parent' => (string) $villas])['_status'], 'not into itself');

        $renamed = self::json('POST', '/en/admin/media/folders/rename/' . $villas, ['name' => 'Big villas']);
        self::assertSame('Folder renamed.', $renamed['message']);
        $moved = self::json('POST', '/en/admin/media/folders/move/' . $villas, ['parent' => (string) $blog]);
        self::assertSame('Folder moved: Blog / Big villas.', $moved['message']);

        self::json('POST', '/en/admin/media/move', ['ids' => ['2'], 'folder' => (string) $villas]);
        $deleted = self::json('POST', '/en/admin/media/folders/delete/' . $villas);
        self::assertSame($blog, $deleted['id'], 'the parent comes back, to open');
        self::assertSame((string) $blog, (string) self::row('media', 2)['folder_id'], 'its items move up');
        self::assertSame(['Blog', 'Homes'], array_column($deleted['folders']['tree'], 'name'));

        self::assertSame(404, self::request('POST', '/en/admin/media/folders/frobnicate', self::options(['post' => ['_token' => 'tok'], 'expect_error' => true]))['status']);
        self::assertSame(405, self::request('GET', '/en/admin/media/folders/create', self::options(['expect_error' => true]))['status']);

        // Without JavaScript: the forms on the page, then back to the new folder.
        $plain = self::post('/en/admin/media/folders/create', ['name' => 'Plain'], self::options());
        self::assertTrue($plain['redirected']);
        $page = self::request('GET', '/en/admin/media?folder=' . $blog, self::options())['body'];
        self::assertStringContainsString('<input type="hidden" name="parent" value="' . $blog . '">', $page);
        self::assertStringContainsString('action="https://example.test/en/admin/media/folders/rename/' . $blog . '"', $page);
        self::assertStringContainsString('action="https://example.test/en/admin/media/folders/delete/' . $blog . '" data-confirm', $page);
        self::assertStringContainsString('style="--depth: 0" href="https://example.test/en/admin/media?folder=' . $blog . '" aria-current="true"', $page);
    }

    public function testMovingItems(): void
    {
        $homes = self::json('POST', '/en/admin/media/folders/create', ['name' => 'Homes'])['id'];
        $moved = self::json('POST', '/en/admin/media/move', ['ids' => ['1', '2', '99'], 'folder' => (string) $homes]);
        self::assertSame([2, '2 moved to Homes.'], [$moved['moved'], $moved['message']]);
        self::assertSame(['all' => 4, 'unfiled' => 2, (string) $homes => 2], $moved['folders']['counts']);
        self::assertSame('1 moved to Unfiled.', self::json('POST', '/en/admin/media/move', ['ids' => ['1'], 'folder' => ''])['message']);
        self::assertSame(422, self::json('POST', '/en/admin/media/move', ['ids' => ['1'], 'folder' => '999'])['_status']);
        self::assertSame(400, self::request('POST', '/en/admin/media/move', self::options(['post' => ['_token' => 'tok', 'ids' => []], 'expect_error' => true]))['status']);

        // The page's batch form without JavaScript goes back to the page it came from.
        $back = 'https://example.test/en/admin/media?folder=unfiled';
        $plain = self::post('/en/admin/media/move', ['ids' => ['3'], 'folder' => (string) $homes, '_back' => $back], self::options());
        self::assertTrue($plain['redirected']);
        self::assertSame((string) $homes, (string) self::row('media', 3)['folder_id']);
    }

    public function testDeletingAsJsonKeepsItemsInUse(): void
    {
        $result = self::json('POST', '/en/admin/media/delete', ['ids' => ['1', '2']]);
        self::assertSame([1, 1], [$result['deleted'], $result['kept']]);
        self::assertSame(['1', '3', '4'], array_map('strval', self::pdo()->query('SELECT id FROM media ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testDuplicateAnswersNeedAValidToken(): void
    {
        self::assertSame(400, self::request('POST', '/en/admin/media/pending', self::options(['post' => ['_token' => 'tok', 'token' => 'x', 'action' => 'maybe'], 'expect_error' => true]))['status']);
        self::assertSame(['ok' => false], array_diff_key(self::json('POST', '/en/admin/media/pending', ['token' => str_repeat('a', 40), 'action' => 'discard']), ['_status' => 1]));
        self::assertSame(404, self::request('POST', '/en/admin/media/pending', self::options(['post' => ['_token' => 'tok', 'token' => str_repeat('a', 40), 'action' => 'keep'], 'expect_error' => true]))['status']);
    }
}
