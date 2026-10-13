<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Translation\JsonColumnStore;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Database;

/**
 * The same tests run on SQLite (always) and on MySQL/MariaDB (when configured).
 */
abstract class RepositoryTestCase extends TestCase
{
    protected Database $db;
    protected RecordRepository $repository;
    protected Table $table;

    abstract protected function connect(): Database;

    abstract protected function createTable(Database $db): void;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->connect();
        $this->db->query('DROP TABLE IF EXISTS posts');
        $this->createTable($this->db);

        $resource = new PostResource();
        $this->repository = new RecordRepository($resource, $this->db, new JsonColumnStore());
        $this->table = $resource->table(new Table());
        $translatable = $resource->form(new Form())->translatableNames();
        foreach ($this->table->getColumns() as $name => $column) {
            $column->detectTranslatable(in_array($name, $translatable, true));
        }

        $rows = [
            ['title' => '{"en":"Sea view villa","ar":"فيلا بإطلالة على البحر","ku":"ڤیلای ڕووەو دەریا"}', 'slug' => 'sea-view-villa'],
            ['title' => '{"en":"Mountain cabin","ar":"كوخ جبلي"}', 'slug' => 'mountain-cabin'],
            ['title' => 'Old plain title', 'slug' => 'old-plain'],
            ['title' => '{"en":"100% sale_now","ku":"ئەلف"}', 'slug' => 'sale'],
            ['title' => null, 'slug' => 'untitled'],
        ];
        foreach ($rows as $row) {
            $this->repository->insert($row + ['status' => 'draft', 'featured' => 0]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) {
            $this->db->query('DROP TABLE IF EXISTS posts');
        }
        parent::tearDown();
    }

    /** @return list<string> */
    private function slugs(string $search, ?string $sort = null, ?string $dir = null, int $page = 1, int $perPage = 10, string $lang = 'en'): array
    {
        $result = $this->repository->paginate($this->table, $search, $sort, $dir, $page, $perPage, $lang);

        return array_map(static fn (object $row): string => $row->slug, $result['data']);
    }

    public function testSearchFindsAnyLanguage(): void
    {
        self::assertSame(['sea-view-villa'], $this->slugs('إطلالة'));
        self::assertSame(['sea-view-villa'], $this->slugs('ڕووەو'));
        self::assertSame(['mountain-cabin'], $this->slugs('cabin'));
    }

    public function testSearchIsCaseInsensitive(): void
    {
        self::assertSame(['mountain-cabin'], $this->slugs('MOUNTAIN'));
    }

    public function testLegacyPlainValuesAreSearchedAsTheDefaultLanguage(): void
    {
        self::assertSame(['old-plain'], $this->slugs('plain title'));
    }

    public function testSearchTreatsWildcardsLiterally(): void
    {
        self::assertSame(['sale'], $this->slugs('100%'));
        self::assertSame(['sale'], $this->slugs('sale_now'));
        self::assertSame([], $this->slugs('_x%'));
    }

    public function testSearchAlsoCoversPlainColumns(): void
    {
        self::assertSame(['untitled'], $this->slugs('untitled'));
    }

    public function testSortByTranslatableColumnUsesTheLanguage(): void
    {
        self::assertSame(['sale', 'mountain-cabin', 'old-plain', 'sea-view-villa'], array_values(array_diff($this->slugs('', 'title', 'asc'), ['untitled'])));
        self::assertSame(['sea-view-villa', 'old-plain', 'mountain-cabin', 'sale'], array_values(array_diff($this->slugs('', 'title', 'desc'), ['untitled'])));

        // Kurdish: only two rows have Kurdish titles; the others are NULL in that language
        $kurdish = array_values(array_diff($this->slugs('', 'title', 'asc', 1, 10, 'ku'), ['untitled', 'mountain-cabin', 'old-plain']));
        self::assertSame(['sale', 'sea-view-villa'], $kurdish);
    }

    public function testUnsortableColumnsFallBackToTheDefaultSort(): void
    {
        // slug is searchable but not sortable: default sort is id desc
        self::assertSame(['untitled', 'sale', 'old-plain', 'mountain-cabin', 'sea-view-villa'], $this->slugs('', 'slug', 'asc'));
    }

    public function testPagination(): void
    {
        $result = $this->repository->paginate($this->table, '', null, null, 2, 2, 'en');

        self::assertSame(5, $result['pagination']['items_count']);
        self::assertSame(3, $result['pagination']['last_page']);
        self::assertSame(['old-plain', 'mountain-cabin'], array_map(static fn (object $r): string => $r->slug, $result['data']));
    }

    public function testFindUpdateDeleteAndExists(): void
    {
        $id = $this->repository->insert(['title' => '{"en":"New"}', 'slug' => 'new', 'status' => 'draft', 'featured' => 1]);

        self::assertSame('new', $this->repository->find($id)->slug);
        self::assertTrue($this->repository->exists('slug', 'new', null));
        self::assertFalse($this->repository->exists('slug', 'new', $id), 'the record itself does not count');

        $this->repository->update($id, ['slug' => 'renamed']);
        self::assertSame('renamed', $this->repository->find($id)->slug);

        $this->repository->delete($id);
        self::assertNull($this->repository->find($id));
    }

    public function testTranslatedUniquenessUsesExactLanguageAndBoundValues(): void
    {
        $form = (new Form())->schema([\FloCMS\Admin\Form\Fields\TextInput::make('title')->translatable()->unique()]);
        $validator = new \FloCMS\Admin\Validation\FormValidator(['en', 'ar', 'ku'], 'en',
            fn ($field, $value, $lang) => $this->repository->exists($field->getName(), $value, null, $lang));
        $errors = $validator->validate($form, ['title' => ['en' => 'Sea view villa', 'ar' => 'فيلا بإطلالة على البحر']]);
        self::assertArrayHasKey('title', $errors);
        self::assertArrayHasKey('title.ar', $errors);
        self::assertFalse($this->repository->exists('title', 'Sea view villa', '1', 'en'));
        self::assertFalse($this->repository->exists('title', 'Sea view villa', null, 'ar'));
        self::assertFalse($this->repository->exists('title', 'SEA VIEW VILLA', null, 'en'));
        self::assertTrue($this->repository->exists('title', 'Old plain title', null, 'en'));
        self::assertFalse($this->repository->exists('title', 'Old plain title', null, 'ar'));
        self::assertFalse($this->repository->exists('title', "' OR 1=1 --", null, 'en'));
        $value = "It's 100%_exact";
        $this->repository->insert(['title' => (new JsonColumnStore())->encode(['en' => $value, 'en-us' => 'Regional'])]);
        self::assertTrue($this->repository->exists('title', $value, null, 'en'));
        self::assertTrue($this->repository->exists('title', 'Regional', null, 'en-us'));
        self::assertFalse($this->repository->exists('title', '100%', null, 'en'));
    }

    public function testTranslatedUniquenessRejectsUnsafePaths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->exists('title', 'value', null, "en' OR 1=1");
    }

    public function testTranslatedUniquenessRejectsUnsafeColumns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->repository->exists('title`)', 'value', null, 'en');
    }

    public function testNestedSaveTransactionRollsBackWithoutCommittingOuterWork(): void
    {
        $this->db->transaction(function (): void {
            $outer = $this->repository->insert(['slug' => 'outer']);
            try {
                $this->repository->transaction(function (): void {
                    $this->repository->insert(['slug' => 'inner']);
                    throw new \RuntimeException('rollback');
                });
                self::fail('Expected rollback');
            } catch (\RuntimeException $e) {
                self::assertSame('rollback', $e->getMessage());
            }
            self::assertNotNull($this->repository->find($outer));
            self::assertFalse($this->repository->exists('slug', 'inner', null));
        });
        self::assertTrue($this->repository->exists('slug', 'outer', null));
    }

    public function testUnicodeIsStoredReadably(): void
    {
        $id = $this->repository->insert(['title' => (new JsonColumnStore())->encode(['ar' => 'فيلا', 'en' => 'a/b']), 'slug' => 'u', 'status' => 'draft', 'featured' => 0]);
        $raw = (string) $this->repository->find($id)->title;

        self::assertStringContainsString('فيلا', $raw);
        self::assertSame(['ar' => 'فيلا', 'en' => 'a/b'], (new JsonColumnStore())->decode($raw, 'en'));
    }
}
