<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Integration;

use FloCMS\Admin\Table\Filters\{CheckboxListFilter, TagsFilter, TimeRangeFilter};
use FloCMS\Admin\Tests\TestCase;
use FloCMS\Core\Database;

/**
 * The 0.37 filters on a real database: whole tags regardless of capitals in
 * any script, MySQL's JSON spacing, legacy text, LIKE wildcards in tags, and
 * time ranges. The same results on SQLite (always) and MySQL/MariaDB (when configured).
 */
abstract class ChoiceFiltersTestCase extends TestCase
{
    protected Database $db;

    abstract protected function connect(): Database;

    abstract protected function createTable(Database $db): void;

    protected function setUp(): void
    {
        parent::setUp();
        $this->db = $this->connect();
        $this->db->query('DROP TABLE IF EXISTS homes');
        $this->createTable($this->db);
        $rows = [
            1 => ['["pool","garden"]', '["Sea View","villa"]', '09:30:00'],
            2 => ['["parking"]', '["sea","garden"]', '12:15:00'],
            3 => [null, 'villa, sea view', '18:00:00'],
            4 => ['["lift","pool"]', '["Θάλασσα"]', null],
            5 => ['["parking", "lift"]', '["إطلالة بحرية"]', '08:00:00'],
            6 => [null, '["foo\\"sea view"]', null],
            7 => [null, '["50%_off"]', null],
            8 => [null, null, '12:16:00'],
            9 => [null, '["café"]', null],
        ];
        foreach ($rows as $id => [$features, $keywords, $time]) {
            $this->db->query('INSERT INTO homes (id, features, keywords, opens_at) VALUES (?, ?, ?, ?)', [$id, $features, $keywords, $time]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db)) { $this->db->query('DROP TABLE IF EXISTS homes'); }
        parent::tearDown();
    }

    /** @return list<int> */
    private function ids(\FloCMS\Admin\Table\Filters\Filter $filter, string|array $value): array
    {
        $rows = $filter->apply($this->db->table('homes'), $filter->value($value))->orderBy('id')->get();
        return array_map(static fn ($row): int => (int) ((array) $row)['id'], $rows);
    }

    public function testATagMatchesWholeTagsRegardlessOfCapitals(): void
    {
        $filter = TagsFilter::make('keywords');
        self::assertSame([1, 3], $this->ids($filter, 'sea view'), 'not "sea", not a tag that only contains the words');
        self::assertSame([1, 3], $this->ids($filter, 'SEA VIEW'));
        self::assertSame([2], $this->ids($filter, 'sea'));
        self::assertSame([1, 3], $this->ids($filter, 'Villa'), 'legacy plain text too');
        self::assertSame([4], $this->ids($filter, 'θάλασσα'), 'Greek capitals');
        self::assertSame([5], $this->ids($filter, 'إطلالة بحرية'));
        self::assertSame([7], $this->ids($filter, '50%_off'));
        self::assertSame([], $this->ids($filter, '50%'), 'LIKE wildcards are plain text');
        self::assertSame([6], $this->ids($filter, 'foo"sea view'), 'a quote inside a tag');
        self::assertSame([9], $this->ids($filter, 'CAFÉ'));
        self::assertSame([], $this->ids($filter, 'cafe'), 'accents count, on every database');
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $this->ids($filter, ''));
    }

    public function testCheckboxListFilterFindsAChoiceOrAnyOfSeveral(): void
    {
        $options = ['pool' => 'Pool', 'parking' => 'Parking', 'lift' => 'Lift', 'garden' => 'Garden'];
        self::assertSame([1, 4], $this->ids(CheckboxListFilter::make('features')->options($options), 'pool'));
        self::assertSame([4, 5], $this->ids(CheckboxListFilter::make('features')->options($options), 'lift'), 'after ", " as MySQL\'s JSON type writes it');
        self::assertSame([2, 4, 5], $this->ids(CheckboxListFilter::make('features')->options($options)->multiple(), ['o1' => 'parking', 'o2' => 'lift']));
        self::assertSame([1, 2, 3, 4, 5, 6, 7, 8, 9], $this->ids(CheckboxListFilter::make('features')->options($options)->multiple(), []));
    }

    public function testTimeRangeIncludesBothEnds(): void
    {
        $filter = TimeRangeFilter::make('opens_at');
        self::assertSame([1, 2, 5], $this->ids($filter, ['from' => '08:00', 'until' => '12:15']));
        self::assertSame([2, 3, 8], $this->ids($filter, ['from' => '12:00', 'until' => '']));
        self::assertSame([5], $this->ids($filter, ['from' => '', 'until' => '8:00']));
    }
}
