<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Core\Database;

/**
 * The totals row under a list (0.38): "This page" from the rows already
 * loaded (no query), and "All rows" from one aggregate query over the list's
 * own rows: the resource's query(), a relation manager's parent, the tabs,
 * filters and search.
 *
 * @internal
 */
final class Totals
{
    /** @var array<string, Column> visible columns, in order */
    private array $columns;

    /** @param array<string, Column> $columns the visible columns */
    public function __construct(array $columns)
    {
        $this->columns = $columns;
    }

    /** Whether any visible column has totals. */
    public function has(): bool
    {
        foreach ($this->columns as $column) { if ($column->getSummaries() !== []) { return true; } }
        return false;
    }

    /**
     * Totals of the rows on this page.
     *
     * @param list<object> $rows
     * @return array<string, list<int|float|string|null>> column => one value per summary
     */
    public function page(array $rows): array
    {
        $values = [];
        foreach ($this->columns as $name => $column) {
            if ($column->getSummaries() === []) { continue; }
            $cells = array_map(static fn (object $row): mixed => $column->summaryValue($row), $rows);
            $values[$name] = array_map(static fn (Summaries\Summary $summary): int|float|string|null => $summary->compute($cells), $column->getSummaries());
        }
        return $values;
    }

    /**
     * Totals of every row of $query (the list's rows, unordered), in one query.
     *
     * @param array<string, list<mixed>>|null $groups for subtotals: group key expression => [sql, bindings, keys]
     * @return array<string, list<int|float|string|null>>
     */
    public function all(Database $db, Database $query): array
    {
        [$select, $bindings, $map] = $this->selectList();
        if ($select === []) { return []; }
        [$from, $params] = SqlRows::derived($query);
        $rows = SqlRows::select($db, 'SELECT ' . implode(', ', $select) . ' FROM ' . $from, [...$bindings, ...$params]);
        return $this->read($rows[0] ?? null, $map);
    }

    /**
     * The aggregate expressions of every summary: SELECT parts, their
     * bindings, and alias => [column, summary index].
     *
     * @return array{0: list<string>, 1: list<mixed>, 2: array<string, array{0: string, 1: int}>}
     * @internal
     */
    public function selectList(): array
    {
        $select = [];
        $bindings = [];
        $map = [];
        $n = 0;
        foreach ($this->columns as $name => $column) {
            if ($column->getSummaries() === []) { continue; }
            $sql = $column->aggregateSql();
            if ($sql === null) { continue; }
            foreach ($column->getSummaries() as $index => $summary) {
                [$aggregate, $own] = $summary->sql($sql[0]);
                $alias = 'flo_s' . $n++;
                $select[] = $aggregate . ' AS ' . $alias;
                array_push($bindings, ...$sql[1], ...$own);
                $map[$alias] = [$name, $index];
            }
        }
        return [$select, $bindings, $map];
    }

    /**
     * @param array<string, array{0: string, 1: int}> $map
     * @return array<string, list<int|float|string|null>>
     * @internal
     */
    public function read(?object $row, array $map): array
    {
        $values = [];
        foreach ($map as $alias => [$name, $index]) {
            $summary = $this->columns[$name]->getSummaries()[$index];
            $values[$name][$index] = $summary->fromDatabase($row?->{$alias} ?? null);
        }
        return $values;
    }

    /**
     * One cell per visible column for a totals line: the line's label in the
     * first column, each column's summaries in its own cell.
     *
     * @param array<string, list<int|float|string|null>> $values
     * @return list<array{html: string, class: string, label: string}>
     */
    public function cells(array $values, string $label, string $note = ''): array
    {
        $cells = [];
        $first = true;
        foreach ($this->columns as $name => $column) {
            $html = '';
            if ($first) {
                $html .= '<span class="fa-totals-label">' . Str::e($label) . ($note !== '' ? '<small>' . Str::e($note) . '</small>' : '') . '</span>';
                $first = false;
            }
            foreach ($column->getSummaries() as $index => $summary) {
                $html .= $summary->html($values[$name][$index] ?? null, $column->getLabel());
            }
            $cells[] = ['html' => $html, 'class' => trim($column->cellClass() . ' fa-totals-cell' . ($column->getSummaries() !== [] ? ' has-totals' : '')), 'label' => $column->getLabel()];
        }
        return $cells;
    }

    /**
     * The lines under the list: "This page" (only when there is more than one
     * page) and "All rows", as $mode allows ('both', 'page' or 'all').
     *
     * @param list<object> $rows the page's rows
     * @return list<array{kind: string, cells: list<array{html: string, class: string, label: string}>}>
     */
    public function lines(array $rows, ?Database $db, ?Database $query, int $total, int $lastPage, bool $filtered, string $mode = 'both'): array
    {
        if (!$this->has() || $total === 0) { return []; }
        $lines = [];
        if ($lastPage > 1 && $mode !== 'all') {
            $lines[] = ['kind' => 'page', 'cells' => $this->cells($this->page($rows), AdminLang::get('totals.page'), AdminLang::get('totals.page_note', ['count' => count($rows), 'total' => $total]))];
        }
        if ($mode !== 'page' || $lastPage <= 1) {
            $values = $lastPage <= 1 || $db === null || $query === null ? $this->page($rows) : $this->all($db, $query);
            $lines[] = ['kind' => 'all', 'cells' => $this->cells($values, AdminLang::get($total === 1 ? 'totals.all_one' : 'totals.all', ['total' => $total]), $filtered ? AdminLang::get('totals.all_note') : '')];
        }
        return $lines;
    }
}
