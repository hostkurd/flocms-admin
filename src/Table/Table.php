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
    private ?array $actions = null;
    private array $headerActions = [];
    private array $filters = [];
    private array $bulkActions = [];

    public function filters(array $filters): self
    {
        if (count($filters) > 20) { throw new InvalidArgumentException('Too many filters.'); }
        $this->filters = [];
        foreach ($filters as $filter) {
            if (!$filter instanceof \FloCMS\Admin\Table\Filters\Filter || isset($this->filters[$filter->getName()])) { throw new InvalidArgumentException('Filters must have unique names.'); }
            $this->filters[$filter->getName()] = $filter;
        }
        return $this;
    }
    public function getFilters(): array { return $this->filters; }
    public function bulkActions(array $actions): self { $this->bulkActions = $actions; return $this; }
    public function getBulkActions(): array { return $this->bulkActions; }

    /** Replace the default Edit/Delete row actions. */
    public function actions(array $actions): self { $this->actions = $actions; return $this; }
    public function headerActions(array $actions): self { $this->headerActions = $actions; return $this; }
    public function getActions(): array
    {
        return $this->actions ?? [\FloCMS\Admin\Actions\EditAction::make(), \FloCMS\Admin\Actions\DeleteAction::make()];
    }
    public function getHeaderActions(): array { return $this->headerActions; }

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
    /** The column that titles a row on phones: the first searchable column, else the first column. */
    public function primaryColumn(): ?string
    {
        foreach ($this->getColumns() as $name => $column) {
            if ($column->isSearchable()) { return $name; }
        }
        return array_key_first($this->getColumns());
    }

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
