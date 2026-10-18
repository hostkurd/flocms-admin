<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Unit;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Relations\RelationManager;
use FloCMS\Admin\Table\Columns\CountColumn;
use FloCMS\Admin\Table\Columns\TextColumn;
use FloCMS\Admin\Table\Filters\SelectFilter;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Tests\Fixtures\Managers\{EnquiryResource, EstateResource, UnitResource};
use FloCMS\Admin\Tests\Fixtures\Resources\PostResource;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\App;
use FloCMS\Core\Database;

/** Relation managers (0.22) without HTTP: the declaration, the table inside a parent, counts. */
final class RelationManagersUnitTest extends TestCase
{
    public function testDeclaration(): void
    {
        $manager = RelationManager::make(UnitResource::class, 'estate_id');
        self::assertSame('units', $manager->getName());
        self::assertSame('Units', $manager->getLabel());
        self::assertSame('building', $manager->getIcon());
        self::assertSame('id', $manager->getOwnerKey(EstateResource::class));
        self::assertTrue($manager->allowsCreate());
        self::assertSame('No units yet.', $manager->getEmptyText());
        self::assertFalse($manager->canCreate(false)->allowsCreate());
        self::assertFalse(RelationManager::make(UnitResource::class, 'estate_id')->readOnly()->allowsCreate());
        $custom = RelationManager::make(UnitResource::class, 'estate_id', 'code')->name('flats')->label('Flats')->perPage(500)->defaultSort('name', 'DESC')->emptyText('None');
        self::assertSame(['flats', 'Flats', 'code', 100, ['name', 'desc'], 'None'], [$custom->getName(), $custom->getLabel(), $custom->getOwnerKey(EstateResource::class), $custom->getPerPage(), $custom->getDefaultSort(), $custom->getEmptyText()]);

        foreach ([static fn () => RelationManager::make(\stdClass::class, 'x'), static fn () => RelationManager::make(UnitResource::class, 'estate id'),
            static fn () => RelationManager::make(UnitResource::class, 'estate_id')->name('Flats'), static fn () => RelationManager::make(UnitResource::class, 'estate_id')->columns([1])] as $bad) {
            try { $bad(); self::fail('accepted'); } catch (\InvalidArgumentException) { self::assertTrue(true); }
        }
    }

    public function testTwiceTheSameChildNeedsAName(): void
    {
        self::assertCount(2, RelationManager::of(new EstateResource()));
        $twice = new class extends \FloCMS\Admin\Resource {
            protected static string $table = 'estates';
            protected static string $slug = 'twice';
            public function form(\FloCMS\Admin\Form\Form $form): \FloCMS\Admin\Form\Form { return $form; }
            public function table(Table $table): Table { return $table; }
            public function relations(): array { return [RelationManager::make(UnitResource::class, 'estate_id'), RelationManager::make(UnitResource::class, 'other_id')]; }
        };
        $this->expectException(\LogicException::class);
        RelationManager::of($twice);
    }

    public function testTheTableInsideAParent(): void
    {
        $table = (new Table())->columns([TextColumn::make('name'), TextColumn::make('estate_id'), TextColumn::make('email')])
            ->filters([SelectFilter::make('estate_id'), SelectFilter::make('kind'), SelectFilter::make('owner')->column('estate_id')])->perPage(25)->defaultSort('name');
        $inside = $table->forRelation(['name', 'estate_id'], 'estate_id', 5, ['email', 'desc']);
        self::assertSame(['name'], array_keys($inside->getColumns()));
        self::assertSame(['kind'], array_keys($inside->getFilters()));
        self::assertSame([5, 'email', 'desc'], [$inside->getPerPage(), $inside->getDefaultSort(), $inside->getDefaultDirection()]);
        self::assertSame(['name', 'estate_id', 'email'], array_keys($table->getColumns()), 'the child\'s own table is unchanged');
        $all = $table->forRelation(null, 'estate_id', null, null);
        self::assertSame(['name', 'email'], array_keys($all->getColumns()));
        self::assertSame([25, 'name'], [$all->getPerPage(), $all->getDefaultSort()]);
    }

    public function testParentsOfARecordAndTheSidebar(): void
    {
        Admin::useConfig(['resources' => [EstateResource::class, UnitResource::class, EnquiryResource::class, PostResource::class]], true, sys_get_temp_dir());
        self::assertSame(['estates:7'], RelationManager::parentsOf(new UnitResource(), (object) ['id' => 1, 'estate_id' => 7]));
        self::assertSame([], RelationManager::parentsOf(new UnitResource(), (object) ['id' => 1, 'estate_id' => null]));
        self::assertSame([], RelationManager::parentsOf(new PostResource(), (object) ['id' => 1]));
        self::assertFalse(UnitResource::showsInNavigation());
        self::assertTrue(EstateResource::showsInNavigation());
        self::assertSame([], RelationManager::make(UnitResource::class, 'estate_id')->problems(EstateResource::class));
        self::assertCount(1, RelationManager::make(\FloCMS\Admin\Tests\Fixtures\Resources\TagResource::class, 'estate_id')->problems(EstateResource::class), 'not registered');
    }

    public function testCountColumnUsesOneGroupedQueryThroughTheChildQuery(): void
    {
        $previous = App::$db;
        $pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ]);
        $pdo->exec('CREATE TABLE units (id INTEGER PRIMARY KEY, name TEXT, estate_id INTEGER, archived INTEGER NOT NULL DEFAULT 0)');
        $pdo->exec("INSERT INTO units (name, estate_id, archived) VALUES ('a', 1, 0), ('b', 1, 0), ('c', 1, 1), ('d', 2, 0)");
        App::$db = new Database($pdo);
        try {
            $column = CountColumn::make('units')->relation(UnitResource::class, 'estate_id');
            $records = [(object) ['id' => 1], (object) ['id' => 2], (object) ['id' => 3]];
            $column->prepareRecords($records);
            self::assertSame([2, 1, 0], array_map(static fn (object $r): int => $column->value($r), $records));
            self::assertStringContainsString('is-zero', $column->render($records[2]));
            self::assertFalse($column->isDatabaseColumn());
            self::assertFalse($column->isSortable());
            $this->expectException(\LogicException::class);
            $column->sortable();
        } finally {
            App::$db = $previous;
        }
    }
}
