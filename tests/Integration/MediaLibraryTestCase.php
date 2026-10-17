<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Media\MediaLibrary;
use FloCMS\Admin\Media\MediaStore;
use FloCMS\Admin\Media\MediaUsage;
use FloCMS\Admin\Tests\Fixtures\Media\ListingResource;
use FloCMS\Admin\Tests\Fixtures\Media\MediaTables;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\App;
use FloCMS\Core\Database;
use FloCMS\Core\ValidationException;

/** The media library's store and usage, on SQLite (always) and MySQL/MariaDB (when configured). */
abstract class MediaLibraryTestCase extends TestCase
{
    protected Database $db;
    private ?Database $previous = null;

    abstract protected function connect(): Database;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->connect();
        $this->previous = App::$db;
        App::$db = $this->db;
        Admin::useConfig(['resources' => [ListingResource::class], 'media' => ['root' => sys_get_temp_dir() . '/flo-admin-media-int', 'url' => '/uploads/editor']], true, sys_get_temp_dir());
        $this->db->query('DROP TABLE IF EXISTS listings');
        $id = $this->db->driver() === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT AUTO_INCREMENT PRIMARY KEY';
        $this->db->query('CREATE TABLE listings (id ' . $id . ', title VARCHAR(190) NOT NULL DEFAULT "", cover_id INT NULL, gallery TEXT NULL, video_id INT NULL, body TEXT NULL)');
        MediaTables::create($this->db);
        MediaTables::seed($this->db);
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            foreach (['media_usage', 'media', 'listings'] as $table) { $this->db->query('DROP TABLE IF EXISTS ' . $table); }
        }
        App::$db = $this->previous;
        parent::tearDown();
    }

    private function store(): MediaStore { return MediaLibrary::store(); }

    private function ids(array $result): array
    {
        return array_map(static fn ($item): int => $item->id, $result['items']);
    }

    public function testTheLibraryIsReadyWhenItsTablesExist(): void
    {
        self::assertTrue(MediaLibrary::enabled());
        Admin::useConfig(['media_library' => false], true, sys_get_temp_dir());
        self::assertFalse(MediaLibrary::enabled(), 'switched off in config/admin.php');
        Admin::useConfig(['media_library' => ['table' => 'site_media']], true, sys_get_temp_dir());
        self::assertFalse(MediaLibrary::enabled(), 'renamed but not migrated');
    }

    public function testFindKeepsOrderAndSkipsMissing(): void
    {
        self::assertSame('Sea view', $this->store()->find(1)?->title);
        self::assertNull($this->store()->find(99));
        self::assertSame([3, 1], array_map(static fn ($item): int => $item->id, $this->store()->findMany(['3', 99, '1', 3])));
    }

    public function testSearchByNameTitleAndAltInAnyLanguageAndByKind(): void
    {
        self::assertSame([4, 3, 2, 1], $this->ids($this->store()->search()), 'newest first');
        self::assertSame([1], $this->ids($this->store()->search('الغروب')), 'Arabic alt text');
        self::assertSame([2], $this->ids($this->store()->search('garden')), 'file name');
        self::assertSame([3], $this->ids($this->store()->search('HALL')), 'case-insensitive title');
        self::assertSame([4], $this->ids($this->store()->search('', 'video')));
        self::assertSame([3, 2, 1], $this->ids($this->store()->search('', 'image')));
        self::assertSame([], $this->ids($this->store()->search('100%')), 'LIKE wildcards are literal');
        $page = $this->store()->search('', null, false, 2, 3);
        self::assertSame(['items' => [1], 'total' => 4, 'page' => 2, 'pages' => 2], ['items' => $this->ids($page)] + array_diff_key($page, ['items' => 1]));
    }

    public function testUsageIsRecordedFromPickersAndRichTextAndForgottenOnDelete(): void
    {
        $this->db->query("INSERT INTO listings (title, cover_id, gallery, video_id, body) VALUES ('Villa', 1, '[\"3\",\"2\"]', 4, ?)",
            ['{"en":"<p><img src=\"/uploads/editor/library/2026/10/web/garden.png\"></p>","ar":"<p><img src=\"https://site.test/uploads/editor/library/2026/10/thumb/sea.jpg\"></p>"}']);
        $row = $this->db->table('listings')->where('id', '=', 1)->first();
        MediaUsage::record(new ListingResource(), (new ListingResource())->form(new Form()), $row);
        $usage = fn (int $id): array => array_map(static fn (array $r): string => $r['resource'] . '#' . $r['record_id'] . '.' . $r['field'], $this->store()->usage($id));
        self::assertSame(['listings#1.body', 'listings#1.cover_id'], $usage(1), 'thumb address in the Arabic text counts too');
        self::assertSame(['listings#1.body', 'listings#1.gallery'], $usage(2));
        self::assertSame(['listings#1.gallery'], $usage(3));
        self::assertSame(['listings#1.video_id'], $usage(4));
        self::assertSame([1 => 2, 2 => 2, 3 => 1, 4 => 1], $this->store()->usageCounts([1, 2, 3, 4]));
        self::assertSame([], $this->ids($this->store()->search('', null, true)), 'nothing unused');

        // Saving again replaces the rows of each field
        $this->db->query("UPDATE listings SET gallery = NULL, body = NULL WHERE id = 1");
        MediaUsage::record(new ListingResource(), (new ListingResource())->form(new Form()), $this->db->table('listings')->where('id', '=', 1)->first());
        self::assertSame(['listings#1.cover_id'], $usage(1));
        self::assertSame([3, 2], $this->ids($this->store()->search('', null, true)));

        MediaUsage::forget(new ListingResource(), '1');
        self::assertSame([], $usage(1));
    }

    public function testDeleteAndReplaceAreRefusedWhereTheyWouldBreakPages(): void
    {
        $this->store()->syncUsage('listings', '1', 'cover_id', [1]);
        $this->store()->syncUsage('listings', '1', 'body', [3]);
        try { $this->store()->delete(1); self::fail('deleted an item in use'); }
        catch (ValidationException $error) { self::assertSame(['_form' => 'This item is used, so it cannot be deleted.'], $error->getErrors()); }
        self::assertSame(['library/2026/10/web/garden.png', 'library/2026/10/thumb/garden.png'], $this->store()->delete(2), 'unused: its files are returned for deletion');
        self::assertNull($this->store()->find(2));

        $file = ['kind' => 'image', 'path' => 'library/new/web/n.jpg', 'thumb_path' => 'library/new/thumb/n.jpg', 'mime' => 'image/jpeg', 'bytes' => 5, 'width' => 10, 'height' => 10, 'file_name' => 'n.jpg'];
        try { $this->store()->replace(3, $file); self::fail('replaced an item shown inside rich text'); }
        catch (ValidationException $error) { self::assertStringContainsString('inside rich text', $error->getErrors()['_form']); }
        self::assertSame(['library/2026/10/web/sea.jpg', 'library/2026/10/thumb/sea.jpg'], $this->store()->replace(1, $file), 'a picker usage allows Replace');
        $replaced = $this->store()->find(1);
        self::assertSame(['library/new/web/n.jpg', 'Sea view', 'Sea at sunset', [['resource' => 'listings', 'record_id' => '1', 'field' => 'cover_id']]],
            [$replaced?->path, $replaced?->title, $replaced?->alt('en'), $this->store()->usage(1)], 'same id, title, alt and usage');
        try { $this->store()->replace(4, $file); self::fail('an image replaced a video'); }
        catch (ValidationException $error) { self::assertArrayHasKey('file', $error->getErrors()); }
    }

    public function testDetailsKeepAltPerLanguage(): void
    {
        $this->store()->updateDetails(2, '  Garden  ', ['en' => 'Green garden', 'ar' => ' حديقة ', 'ku' => '']);
        $item = $this->store()->find(2);
        self::assertSame(['Garden', 'Green garden', 'حديقة', 'Green garden'], [$item?->title, $item?->alt('en'), $item?->alt('ar'), $item?->alt('ku')]);
        $this->store()->updateDetails(2, '', []);
        self::assertSame('', $this->store()->find(2)?->alt('en'));
    }

    public function testCreateAndPaths(): void
    {
        $item = $this->store()->create(['kind' => 'video', 'path' => 'library/2026/11/clip.webm', 'thumb_path' => null, 'mime' => 'video/webm', 'bytes' => 9, 'width' => null, 'height' => null, 'file_name' => 'clip.webm'], 7, 'Clip');
        self::assertSame(['video', 'Clip', 7, '/uploads/editor/library/2026/11/clip.webm'], [$item->kind, $item->title, $item->uploadedBy, $item->url('thumb')]);
        self::assertSame([5], $this->store()->idsForPaths(['library/2026/11/clip.webm']));
        self::assertSame([1], $this->store()->idsForPaths(['library/2026/10/thumb/sea.jpg']), 'a thumb path finds its item');
        self::assertTrue($this->store()->hasPath('library/2026/10/web/hall.webp'));
        self::assertFalse($this->store()->hasPath('library/none.jpg'));
    }

    public function testRebuildReadsEveryRecord(): void
    {
        for ($i = 0; $i < 205; $i++) { $this->db->query('INSERT INTO listings (title, cover_id) VALUES (?, ?)', ['L' . $i, $i % 2 === 0 ? 1 : 3]); }
        self::assertSame(['listings' => 205], MediaUsage::rebuild());
        self::assertSame([1 => 103, 3 => 102], array_intersect_key($this->store()->usageCounts([1, 3]), [1 => 0, 3 => 0]));
    }
}
