<?php
declare(strict_types=1);

namespace FloCMS\Admin\Data;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Table\FilterSet;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\Database;
use PDO;

/**
 * The editor-defined order of a reorderable resource, kept in an integer column.
 *
 * Reorder mode works on a set: the rows of the resource's query() scope that
 * match the active filters. A new order hands out the positions those rows
 * already have, so rows outside the filter never move. Positions are numbered
 * 1, 2, 3… across the whole scope first when some repeat or are NULL.
 */
final class RecordOrder
{
    private readonly string $column;
    private readonly string $key;

    public function __construct(
        private readonly Resource $resource,
        private readonly Database $db,
        private readonly Table $table
    ) {
        $column = $table->getReorderColumn();
        if ($column === null) { throw new \LogicException('The table is not reorderable.'); }
        $this->column = $column;
        $this->key = $resource::getPrimaryKey();
    }

    /**
     * The rows Reorder mode shows, in their saved order, or none when there are
     * more than the table's limit.
     *
     * @return array{total: int, data: list<object>}
     */
    public function records(FilterSet $filters): array
    {
        $total = $filters->apply($this->scope())->count();
        if ($total > $this->table->getReorderLimit()) {
            return ['total' => $total, 'data' => []];
        }
        $data = $filters->apply($this->scope())->orderBy($this->column, 'asc')->orderBy($this->key, 'asc')->get();
        return ['total' => $total, 'data' => $data];
    }

    /**
     * Save a new order of the set. $ids must be exactly the set's keys.
     *
     * @param list<string> $ids
     * @return int The number of rows whose position changed.
     */
    public function reorder(FilterSet $filters, array $ids): int
    {
        return (int) $this->db->transaction(fn (): int => $this->apply($filters, $ids));
    }

    /** Move one row of the set a place up or down (the buttons shown without JavaScript). */
    public function move(FilterSet $filters, string $id, string $direction): int
    {
        return (int) $this->db->transaction(function () use ($filters, $id, $direction): int {
            $ids = array_map('strval', array_keys($this->lockedSet($filters)));
            $index = array_search($id, $ids, true);
            if ($index === false) { $this->changed(); }
            $other = $direction === 'up' ? $index - 1 : $index + 1;
            if ($other < 0 || $other >= count($ids)) { return 0; }
            [$ids[$index], $ids[$other]] = [$ids[$other], $ids[$index]];
            return $this->apply($filters, $ids);
        });
    }

    /** The position for a new record: after the last one, or before the first with newRecordsFirst(). */
    public function nextPosition(): int
    {
        $first = $this->table->addsNewRecordsFirst();
        $row = $this->aggregate(($first ? 'MIN' : 'MAX') . '(s.' . $this->quote($this->column) . ') AS edge, COUNT(*) AS total');
        if ((int) ($row->total ?? 0) === 0 || $row->edge === null) { return 1; }
        return $first ? (int) $row->edge - 1 : (int) $row->edge + 1;
    }

    /** @param list<string> $ids */
    private function apply(FilterSet $filters, array $ids): int
    {
        $current = $this->lockedSet($filters);
        if (count($current) > $this->table->getReorderLimit()) {
            throw $this->refusal(AdminLang::get('reorder.too_many', ['count' => (string) count($current), 'limit' => (string) $this->table->getReorderLimit()]));
        }
        $sent = $ids;
        $known = array_map('strval', array_keys($current));
        sort($sent, SORT_STRING);
        sort($known, SORT_STRING);
        if ($sent !== $known) { $this->changed(); }

        if ($this->needsNumbering()) {
            $this->number();
            $current = $this->lockedSet($filters);
        }

        $positions = array_values($current);
        sort($positions, SORT_NUMERIC);
        $changed = 0;
        foreach (array_values($ids) as $index => $id) {
            $position = (int) $positions[$index];
            if ($current[$id] !== $position) {
                $this->write($id, $position);
                $changed++;
            }
        }
        if ($changed > 0) { $this->resource->afterReorder(array_values($ids)); }
        return $changed;
    }

    /**
     * Keys and positions of the set, in their saved order, locked for this
     * transaction (FOR UPDATE on MySQL/MariaDB, a write lock on SQLite).
     *
     * @return array<string, ?int>
     */
    private function lockedSet(FilterSet $filters): array
    {
        $this->writeIntent();
        $query = $filters->apply($this->scope())->select([$this->key, $this->column])
            ->orderBy($this->column, 'asc')->orderBy($this->key, 'asc');
        [$sql, $params] = $query->toSql();
        $rows = $this->db->query($sql . ($this->db->driver() === 'sqlite' ? '' : ' FOR UPDATE'), $params)->fetchAll(PDO::FETCH_ASSOC);
        $set = [];
        foreach ($rows as $row) {
            $set[(string) $row[$this->key]] = $row[$this->column] === null ? null : (int) $row[$this->column];
        }
        return $set;
    }

    /** Whether positions in the scope repeat or are missing. */
    private function needsNumbering(): bool
    {
        $column = 's.' . $this->quote($this->column);
        $row = $this->aggregate('COUNT(*) AS total, COUNT(DISTINCT ' . $column . ') AS uniq, COUNT(' . $column . ') AS filled');
        $total = (int) ($row->total ?? 0);
        return (int) ($row->uniq ?? 0) !== $total || (int) ($row->filled ?? 0) !== $total;
    }

    /** Number the whole scope 1, 2, 3… in its current order. */
    private function number(): void
    {
        [$sql, $params] = $this->scope()->select([$this->key, $this->column])
            ->orderBy($this->column, 'asc')->orderBy($this->key, 'asc')->toSql();
        $rows = $this->db->query($sql . ($this->db->driver() === 'sqlite' ? '' : ' FOR UPDATE'), $params)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $index => $row) {
            $position = $index + 1;
            if ($row[$this->column] === null || (int) $row[$this->column] !== $position) {
                $this->write((string) $row[$this->key], $position);
            }
        }
    }

    /** Only the order column changes: updated_at and the save hooks stay untouched. */
    private function write(string $id, int $position): void
    {
        $this->scope()->where($this->key, '=', $id)->update([$this->column => $position]);
    }

    private function scope(): Database
    {
        return $this->resource->query($this->db->table($this->resource::getTable()));
    }

    /** One row of aggregates over the scope (alias s). */
    private function aggregate(string $select): object
    {
        [$sql, $params] = $this->scope()->select([$this->key, $this->column])->toSql();
        $row = $this->db->query('SELECT ' . $select . ' FROM (' . $sql . ') s', $params)->fetch(PDO::FETCH_OBJ);
        return $row === false ? (object) [] : $row;
    }

    /** SQLite has no FOR UPDATE: take the write lock before reading, as RecordRepository does. */
    private function writeIntent(): void
    {
        if ($this->db->driver() !== 'sqlite' || !$this->db->pdo()->inTransaction()) { return; }
        $key = $this->quote($this->key);
        $this->db->pdo()->exec('UPDATE ' . $this->quote($this->resource::getTable()) . ' SET ' . $key . ' = ' . $key . ' WHERE 0');
    }

    private function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) { throw new \InvalidArgumentException('Invalid database identifier.'); }
        return '`' . $identifier . '`';
    }

    private function changed(): never
    {
        throw $this->refusal(AdminLang::get('reorder.changed'));
    }

    private function refusal(string $message): \FloCMS\Core\ValidationException
    {
        if (!class_exists(\FloCMS\Core\ValidationException::class)) { class_exists(\FloCMS\Core\Validator::class); }
        return new \FloCMS\Core\ValidationException(['_form' => $message]);
    }
}
