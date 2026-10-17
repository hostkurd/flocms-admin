<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Table\FilterSet;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Tests\Fixtures\Reorder\FaqResource;
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Admin\Translation\JsonColumnStore;
use FloCMS\Core\Database;
use FloCMS\Core\ValidationException;

/**
 * RecordOrder on SQLite (always) and on MySQL/MariaDB (when configured).
 * Rows: 1 shipping, 2 billing, 3 billing (archived, outside the scope), 4 billing, 5 shipping.
 */
abstract class RecordOrderTestCase extends TestCase
{
    protected Database $db;

    abstract protected function connect(): Database;

    abstract protected function createTable(Database $db): void;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->connect();
        $this->db->query('DROP TABLE IF EXISTS faqs');
        $this->createTable($this->db);
        foreach ([['shipping', 0], ['billing', 0], ['billing', 1], ['billing', 0], ['shipping', 0]] as $i => [$category, $archived]) {
            $this->db->query('INSERT INTO faqs (question, category, archived, sort, updated_at) VALUES (?, ?, ?, ?, ?)',
                ['{"en":"Q' . ($i + 1) . '"}', $category, $archived, 0, '2026-01-01 10:00:00']);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) { $this->db->query('DROP TABLE IF EXISTS faqs'); }
        parent::tearDown();
    }

    private function table(): Table
    {
        return (new FaqResource())->table(new Table());
    }

    private function order(?Table $table = null): \FloCMS\Admin\Data\RecordOrder
    {
        return (new RecordRepository(new FaqResource(), $this->db, new JsonColumnStore()))->order($table ?? $this->table());
    }

    private function filters(array $values = []): FilterSet
    {
        return new FilterSet($this->table(), json_encode((object) $values));
    }

    /** @param array<int, ?int> $positions id => position */
    private function set(array $positions): void
    {
        foreach ($positions as $id => $position) { $this->db->query('UPDATE faqs SET sort = ? WHERE id = ?', [$position, $id]); }
    }

    /** @return array<int, ?int> */
    private function positions(): array
    {
        $rows = $this->db->query('SELECT id, sort FROM faqs ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        $positions = [];
        foreach ($rows as $row) { $positions[(int) $row['id']] = $row['sort'] === null ? null : (int) $row['sort']; }
        return $positions;
    }

    public function testRecordsFollowTheSavedOrderWithinScopeAndFilters(): void
    {
        $this->set([1 => 4, 2 => 2, 3 => 1, 4 => 2, 5 => 1]);
        $ids = static fn (array $result): array => array_map(static fn (object $row): int => (int) $row->id, $result['data']);
        self::assertSame([5, 2, 4, 1], $ids($this->order()->records($this->filters())), 'equal positions fall back to the key; archived row 3 is out');
        self::assertSame([2, 4], $ids($this->order()->records($this->filters(['category' => 'billing']))));
        self::assertSame(4, $this->order()->records($this->filters())['total']);
    }

    public function testRecordsAreWithheldAboveTheLimit(): void
    {
        $result = $this->order($this->table()->reorderLimit(3))->records($this->filters());
        self::assertSame(['total' => 4, 'data' => []], $result);
    }

    public function testFirstSaveNumbersTheWholeScopeThenAppliesTheNewOrder(): void
    {
        $changed = $this->order()->reorder($this->filters(), ['5', '4', '2', '1']);
        self::assertSame([1 => 4, 2 => 3, 3 => 0, 4 => 2, 5 => 1], $this->positions());
        self::assertSame(4, $changed);
        self::assertSame('2026-01-01 10:00:00', $this->db->query('SELECT updated_at FROM faqs WHERE id = 1')->fetchColumn());
    }

    public function testRepeatedAndMissingPositionsAreNumberedInTheirCurrentOrder(): void
    {
        $this->set([1 => 7, 2 => null, 4 => 7, 5 => 3]);
        // Current order: 2 (NULL first), 5 (3), 1 (7), 4 (7) → numbered 1..4, then the same order is kept.
        $this->order()->reorder($this->filters(), ['2', '5', '1', '4']);
        self::assertSame([1 => 3, 2 => 1, 3 => 0, 4 => 4, 5 => 2], $this->positions());
    }

    public function testAFilteredSetReusesItsOwnPositionsAndNothingElseMoves(): void
    {
        $this->set([1 => 10, 2 => 20, 4 => 40, 5 => 50]);
        $changed = $this->order()->reorder($this->filters(['category' => 'billing']), ['4', '2']);
        self::assertSame([1 => 10, 2 => 40, 3 => 0, 4 => 20, 5 => 50], $this->positions());
        self::assertSame(2, $changed);
    }

    public function testRepeatsOutsideTheSetDoNotRenumberTheScope(): void
    {
        // Shipping rows share a position (inserted by the website with DEFAULT 0); billing rows are distinct.
        $this->set([1 => 0, 2 => 20, 4 => 40, 5 => 0]);
        $this->order()->reorder($this->filters(['category' => 'billing']), ['4', '2']);
        self::assertSame([1 => 0, 2 => 40, 3 => 0, 4 => 20, 5 => 0], $this->positions());
    }

    public function testRepeatsInsideAFilteredSetNumberTheScopeOnce(): void
    {
        $this->set([1 => 5, 2 => 7, 4 => 7, 5 => 1]);
        // Scope order: 5 (1), 1 (5), 2 (7), 4 (7) → 1..4; billing then holds 3 and 4.
        $this->order()->reorder($this->filters(['category' => 'billing']), ['4', '2']);
        self::assertSame([1 => 2, 2 => 4, 3 => 0, 4 => 3, 5 => 1], $this->positions());
    }

    public function testUnchangedOrderWritesNothing(): void
    {
        $this->set([1 => 1, 2 => 2, 4 => 3, 5 => 4]);
        self::assertSame(0, $this->order()->reorder($this->filters(), ['1', '2', '4', '5']));
    }

    public function testAnotherSetIsRefusedAndRolledBack(): void
    {
        $this->set([1 => 1, 2 => 2, 4 => 3, 5 => 4]);
        foreach ([['1', '2', '4'], ['1', '2', '4', '5', '3'], ['1', '2', '4', '9']] as $ids) {
            try {
                $this->order()->reorder($this->filters(), $ids);
                self::fail('Expected a refusal for ' . implode(',', $ids));
            } catch (ValidationException $error) {
                self::assertSame(['_form' => 'The list changed, so it was reloaded. Try again.'], $error->getErrors());
            }
        }
        self::assertSame([1 => 1, 2 => 2, 3 => 0, 4 => 3, 5 => 4], $this->positions());
    }

    public function testMoveSwapsWithTheNeighbourInTheSet(): void
    {
        $this->set([1 => 1, 2 => 2, 4 => 3, 5 => 4]);
        $this->order()->move($this->filters(), '4', 'up');
        self::assertSame([1 => 1, 2 => 3, 3 => 0, 4 => 2, 5 => 4], $this->positions());
        $this->order()->move($this->filters(['category' => 'billing']), '2', 'up');   // billing: 4 (2), 2 (3)
        self::assertSame([1 => 1, 2 => 2, 3 => 0, 4 => 3, 5 => 4], $this->positions());
        self::assertSame(0, $this->order()->move($this->filters(), '5', 'down'), 'the last row stays last');
        $this->expectException(ValidationException::class);
        $this->order()->move($this->filters(), '3', 'up');   // archived: not in the set
    }

    public function testNextPositionGoesAfterTheLastOrBeforeTheFirstWithinScope(): void
    {
        $this->set([1 => 4, 2 => 9, 3 => 50, 4 => -2, 5 => 1]);
        self::assertSame(10, $this->order()->nextPosition(), 'archived row 3 is outside the scope');
        self::assertSame(-3, $this->order($this->table()->newRecordsFirst())->nextPosition());
        $this->db->query('DELETE FROM faqs');
        self::assertSame(1, $this->order()->nextPosition());
        self::assertSame(1, $this->order($this->table()->newRecordsFirst())->nextPosition());
    }

    public function testListDefaultsToTheEditorsOrder(): void
    {
        $this->set([1 => 3, 2 => 1, 4 => 1, 5 => 2]);
        $repository = new RecordRepository(new FaqResource(), $this->db, new JsonColumnStore());
        $result = $repository->paginate($this->table(), '', null, null, 1, 10, 'en');
        self::assertSame([2, 4, 5, 1], array_map(static fn (object $row): int => (int) $row->id, $result['data']));
        $result = $repository->paginate($this->table(), '', 'category', 'desc', 1, 10, 'en');
        self::assertSame('category', $result['sort'], 'a sortable column still sorts');
    }
}
