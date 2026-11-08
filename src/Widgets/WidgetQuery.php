<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Core\Database;

/** Immutable filters, bound values, validated identifiers, and bounded result sets. */
final class WidgetQuery
{
    private array $filters = [];
    public function __construct(private readonly \Closure $factory) {}
    private static function column(string $column): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) { throw new \InvalidArgumentException('Use a simple column name.'); } return $column;
    }
    public function where(string $column, string $operator, string|int|float|bool|null $value): self
    {
        self::column($column); $operator = strtoupper($operator);
        if (!in_array($operator, ['=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'], true)) { throw new \InvalidArgumentException('Unsupported widget filter operator.'); }
        if (is_float($value) && !is_finite($value)) { throw new \InvalidArgumentException('Use finite filter values.'); }
        $copy = clone $this; $copy->filters[] = ['where', [$column, $operator, $value]]; return $copy;
    }
    public function whereIn(string $column, array $values): self
    {
        self::column($column); if (count($values) > 100) { throw new \InvalidArgumentException('At most 100 filter values.'); }
        foreach ($values as $v) { if (!is_string($v) && !is_int($v) && !is_bool($v)) { throw new \InvalidArgumentException('Use scalar filter values.'); } }
        $copy = clone $this; $copy->filters[] = ['whereIn', [$column, $values]]; return $copy;
    }
    public function whereNull(string $column): self
    {
        self::column($column); $copy = clone $this; $copy->filters[] = ['whereNull', [$column]]; return $copy;
    }
    /** Rows where the column has a value (0.42). */
    public function whereNotNull(string $column): self
    {
        self::column($column); $copy = clone $this; $copy->filters[] = ['whereNotNull', [$column]]; return $copy;
    }
    private function builder(): Database
    {
        $query = ($this->factory)();
        foreach ($this->filters as [$method, $arguments]) { $query = $method === 'whereIn' && $arguments[1] === [] ? $query->whereRaw('1 = 0', []) : $query->{$method}(...$arguments); }
        return $query;
    }
    public function count(): int { return $this->builder()->count(); }
    public function sum(string $column): float
    {
        self::column($column); $row = $this->builder()->selectRaw('COALESCE(SUM(`' . $column . '`), 0) AS widget_total')->first();
        $value = (float) ($row->widget_total ?? 0); if (!is_finite($value)) { throw new \RuntimeException('Widget sum is not finite.'); } return $value;
    }
    /**
     * How many rows hold each value of a column (0.42), most first: value => count,
     * at most $limit values (1–100). Empty values are counted under ''.
     *
     * @return array<string, int>
     */
    public function countBy(string $column, int $limit = 20): array
    {
        self::column($column);
        if ($limit < 1 || $limit > 100) { throw new \InvalidArgumentException('countBy() takes 1–100 values.'); }
        $rows = $this->builder()->selectRaw('`' . $column . '` AS widget_key, COUNT(*) AS widget_count')->groupBy($column)
            ->orderByRaw('widget_count DESC')->limit($limit)->get();
        $counts = [];
        foreach ($rows as $row) { $key = (string) ($row->widget_key ?? ''); $counts[$key] = ($counts[$key] ?? 0) + (int) $row->widget_count; }
        return $counts;
    }
    /**
     * Rows per day of a date column between two moments (0.42): "Y-m-d" => count,
     * days without rows left out. Period::series() turns it into chart points.
     *
     * @return array<string, int>
     */
    public function countByDay(string $column, string $from, string $to): array
    {
        self::column($column);
        foreach ([$from, $to] as $moment) { if (preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/D', $moment) !== 1) { throw new \InvalidArgumentException('Use "Y-m-d H:i:s" moments.'); } }
        if (strtotime($to) - strtotime($from) > 3661 * 86400) { throw new \InvalidArgumentException('countByDay() covers at most 3660 days.'); }
        $rows = $this->where($column, '>=', $from)->where($column, '<=', $to)->builder()
            ->selectRaw('DATE(`' . $column . '`) AS widget_day, COUNT(*) AS widget_count')->groupBy('widget_day')->get();
        $counts = [];
        foreach ($rows as $row) { $day = substr((string) $row->widget_day, 0, 10); if ($day !== '') { $counts[$day] = ($counts[$day] ?? 0) + (int) $row->widget_count; } }
        ksort($counts);
        return $counts;
    }
    /** Read-only tables are capped at 20 rows; no unbounded get() is exposed. */
    public function records(array $columns, string $sort = 'id', string $direction = 'desc', int $limit = 5): array
    {
        if ($columns === [] || count($columns) > 8 || $limit < 1 || $limit > 20 || !in_array(strtolower($direction), ['asc','desc'], true)) { throw new \InvalidArgumentException('Use 1–8 columns, 1–20 rows and asc/desc ordering.'); }
        foreach ($columns as $column) { self::column($column); } self::column($sort);
        return $this->builder()->select($columns)->orderBy($sort, $direction)->limit($limit)->get();
    }
}
