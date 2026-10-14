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

    public function testGuardedSaveAcceptsNoOpAndNullColumnsThenRejectsCaseOnlyChanges(): void
    {
        $original = $this->repository->find('1');
        $this->repository->transaction(function () use ($original): void {
            $this->repository->updateUnchanged('1', ['slug' => $original->slug], $original);
            $this->repository->updateUnchanged('1', [], $original);
        });
        self::assertSame($original->slug, $this->repository->find('1')->slug);
        $this->repository->update('1', ['slug' => strtoupper($original->slug)]);
        try {
            $this->repository->updateUnchanged('1', ['status' => 'published'], $original);
            self::fail('Case-insensitive collations must not accept stale originals.');
        } catch (\FloCMS\Core\ValidationException $e) {
            self::assertArrayHasKey('_form', $e->getErrors());
        }
        self::assertSame('draft', $this->repository->find('1')->status);
    }

    public function testGuardedDeleteRejectsChangedOrDeletedRows(): void
    {
        $original = $this->repository->find('1');
        $this->repository->update('1', ['views' => 1]);
        try {
            $this->repository->deleteUnchanged('1', $original);
            self::fail('Stale deletion must fail.');
        } catch (\FloCMS\Core\ValidationException $e) {
            self::assertArrayHasKey('_form', $e->getErrors());
        }
        $current = $this->repository->find('1');
        $this->repository->deleteUnchanged('1', $current);
        self::assertNull($this->repository->find('1'));
        $this->expectException(\FloCMS\Core\ValidationException::class);
        $this->repository->updateUnchanged('1', [], $current);
    }

    public function testGuardedWriteAndRelatedWritesRollBackTogether(): void
    {
        $original = $this->repository->find('1');
        try {
            $this->repository->transaction(function () use ($original): void {
                $this->repository->updateUnchanged('1', ['slug' => 'changed'], $original);
                $this->repository->insert(['slug' => 'hook-write']);
                throw new \RuntimeException('afterSave failure');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('afterSave failure', $e->getMessage());
        }
        self::assertSame($original->slug, $this->repository->find('1')->slug);
        self::assertFalse($this->repository->exists('slug', 'hook-write', null));
    }

    public function testActionLockDoesNotFireUpdateTriggersAndCommitDeliversNotification(): void
    {
        $previous = $_SESSION ?? [];
        $_SESSION = ['role' => 3];
        \FloCMS\Core\Config::set('permissions', [3 => ['*']]);
        $this->db->query('DROP TABLE IF EXISTS action_audit');
        $this->db->query('CREATE TABLE action_audit (message VARCHAR(40))');
        $this->db->query($this->db->driver() === 'sqlite'
            ? "CREATE TRIGGER action_update_audit AFTER UPDATE ON posts BEGIN INSERT INTO action_audit (message) VALUES ('changed'); END"
            : "CREATE TRIGGER action_update_audit AFTER UPDATE ON posts FOR EACH ROW INSERT INTO action_audit (message) VALUES ('changed')");
        try {
            $resource = new PostResource();
            $executor = new \FloCMS\Admin\Actions\ActionExecutor($resource, $this->repository);
            $action = \FloCMS\Admin\Actions\Action::make('publish')->action(static function (\FloCMS\Admin\Actions\ActionContext $context): void {
                $context->update(['status' => 'published']);
                \FloCMS\Admin\Notifications\Notification::make()->title('Committed')->success()->send();
            });
            $opened = $executor->open($action, 'row', '1'); $accepted = [];
            $executor->execute($action, 'row', '1', $opened['operation'], [], $accepted);
            self::assertSame('published', $this->repository->find('1')->status);
            self::assertSame(1, $this->db->table('action_audit')->count(), 'Locking must not create a second trigger delivery.');
            self::assertSame(['Committed'], array_column(\FloCMS\Admin\Notifications\Notifications::pull(), 'title'));
        } finally {
            $this->db->query('DROP TRIGGER IF EXISTS action_update_audit');
            $this->db->query('DROP TABLE IF EXISTS action_audit');
            $_SESSION = $previous;
        }
    }

    public function testActionDispatchRefusesAnOuterTransactionBeforeInvokingCallback(): void
    {
        $previous = $_SESSION ?? [];
        $_SESSION = ['role' => 3];
        \FloCMS\Core\Config::set('permissions', [3 => ['*']]);
        try {
            $executor = new \FloCMS\Admin\Actions\ActionExecutor(new PostResource(), $this->repository);
            $ran = false;
            $action = \FloCMS\Admin\Actions\Action::make('test')->action(static function () use (&$ran): void { $ran = true; });
            $opened = $executor->open($action, 'row', '1'); $accepted = [];
            $this->repository->transaction(function () use ($executor, $action, $opened, &$accepted): void {
                try {
                    $executor->execute($action, 'row', '1', $opened['operation'], [], $accepted);
                    self::fail('An outer transaction would deliver notifications/consume operations before the actual commit.');
                } catch (\LogicException $error) { self::assertStringContainsString('owns its transaction', $error->getMessage()); }
            });
            self::assertFalse($ran);
            self::assertSame([], \FloCMS\Admin\Notifications\Notifications::pull());
            self::assertNotEmpty(\FloCMS\Admin\Actions\OperationStore::get($opened['operation'], 'posts', 'row', 'test', '1'));
        } finally { $_SESSION = $previous; }
    }

    public function testUnicodeIsStoredReadably(): void
    {
        $id = $this->repository->insert(['title' => (new JsonColumnStore())->encode(['ar' => 'فيلا', 'en' => 'a/b']), 'slug' => 'u', 'status' => 'draft', 'featured' => 0]);
        $raw = (string) $this->repository->find($id)->title;

        self::assertStringContainsString('فيلا', $raw);
        self::assertSame(['ar' => 'فيلا', 'en' => 'a/b'], (new JsonColumnStore())->decode($raw, 'en'));
    }
}
