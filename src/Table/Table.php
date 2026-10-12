<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table;

use InvalidArgumentException;

/**
 * The list page of a resource: columns, default sort and page size.
 */
final class Table
{
    /** @var array<string, Column> */
    private array $columns = [];
    private ?string $defaultSort = null;
    private string $defaultDirection = 'asc';
    private ?int $perPage = null;

    /** @param list<Column> $columns */
    public function columns(array $columns): self
    {
        foreach ($columns as $column) {
            if (!$column instanceof Column) {
                throw new InvalidArgumentException('Table::columns() expects Column objects.');
            }
            $this->columns[$column->getName()] = $column;
        }

        return $this;
    }

    public function defaultSort(string $column, string $direction = 'asc'): self
    {
        $this->defaultSort = $column;
        $this->defaultDirection = strtolower($direction) === 'desc' ? 'desc' : 'asc';
        return $this;
    }

    public function perPage(int $perPage): self
    {
        $this->perPage = max(1, min(500, $perPage));
        return $this;
    }

    /** @return array<string, Column> */
    public function getColumns(): array
    {
        return $this->columns;
    }

    public function column(string $name): ?Column
    {
        return $this->columns[$name] ?? null;
    }

    /** @return array<string, Column> */
    public function searchableColumns(): array
    {
        return array_filter($this->columns, static fn (Column $c): bool => $c->isSearchable());
    }

    public function sortableColumn(string $name): ?Column
    {
        $column = $this->columns[$name] ?? null;

        return $column !== null && $column->isSortable() ? $column : null;
    }

    public function getDefaultSort(): ?string
    {
        return $this->defaultSort;
    }

    public function getDefaultDirection(): string
    {
        return $this->defaultDirection;
    }

    public function getPerPage(): ?int
    {
        return $this->perPage;
    }
}
