<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

use Closure;

/**
 * How a resource's CSV export looks (0.25), returned by Resource::export():
 *
 *     return $export
 *         ->except(['internal_notes'])                         // fields left out
 *         ->column('agent', fn (object $row) => $row->agent_name) // an extra, export-only column
 *         ->limit(20000);
 *
 * By default every stored field of the form is exported, in form order, after
 * `id` and followed by `created_at` / `updated_at` when the table has them.
 */
final class Export
{
    /** @var list<string> */
    private array $except = [];
    /** @var array<string, array{0: string, 1: Closure}> name => [heading, value] */
    private array $columns = [];
    private ?int $limit = null;

    /** @param list<string> $fields */
    public function except(array $fields): self
    {
        $this->except = array_values(array_unique(array_merge($this->except, array_map('strval', $fields))));
        return $this;
    }

    /**
     * An extra column: its heading (also used as its readable heading unless
     * $label is given) and its value for each record (the raw row).
     *
     * @param Closure(object): (string|int|float|null) $value
     */
    public function column(string $name, Closure $value, ?string $label = null): self
    {
        if (trim($name) === '') { throw new \InvalidArgumentException('An export column needs a name.'); }
        $this->columns[$name] = [$label ?? $name, $value];
        return $this;
    }

    public function limit(int $rows): self
    {
        if ($rows < 1) { throw new \InvalidArgumentException('The export limit is at least 1 row.'); }
        $this->limit = $rows;
        return $this;
    }

    /** @return list<string> */
    public function getExcept(): array { return $this->except; }
    /** @return array<string, array{0: string, 1: Closure}> */
    public function getColumns(): array { return $this->columns; }
    public function getLimit(): ?int { return $this->limit; }
}
