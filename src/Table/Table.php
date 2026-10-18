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
    private ?string $reorderColumn = null;
    private bool $newRecordsFirst = false;
    private int $reorderLimit = 500;

    /** The most rows Reorder mode loads at once. */
    public const MAX_REORDER_LIMIT = 2000;

    /**
     * Let editors drag records into their own order, kept in an integer column
     * of the resource's table. The list then shows a Reorder button.
     */
    public function reorderable(string $column = 'sort'): self
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) {
            throw new InvalidArgumentException('Table::reorderable() expects a column name.');
        }
        $this->reorderColumn = $column;
        return $this;
    }

    /** New records go to the top of the order instead of the bottom. */
    public function newRecordsFirst(bool $first = true): self
    {
        $this->newRecordsFirst = $first;
        return $this;
    }

    /** How many rows Reorder mode accepts (default 500, at most 2000). */
    public function reorderLimit(int $limit): self
    {
        $this->reorderLimit = max(1, min(self::MAX_REORDER_LIMIT, $limit));
        return $this;
    }

    public function isReorderable(): bool { return $this->reorderColumn !== null; }
    public function getReorderColumn(): ?string { return $this->reorderColumn; }
    public function addsNewRecordsFirst(): bool { return $this->newRecordsFirst; }
    public function getReorderLimit(): int { return $this->reorderLimit; }

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

    /**
     * Fit the list to its resource's form: columns whose field is translatable
     * read JSON values, a TranslationsColumn counts the translatable fields and
     * adds its "Missing in" filter (0.23).
     *
     * @internal
     */
    public function prepare(\FloCMS\Admin\Form\Form $form): self
    {
        $translatable = $form->translatableNames();
        $rich = array_values(array_filter($translatable, static fn (string $name): bool => $form->field($name) instanceof \FloCMS\Admin\Form\Fields\RichEditor));
        $missing = null;
        foreach ($this->columns as $name => $column) {
            $column->detectTranslatable(in_array($name, $translatable, true));
            if ($column instanceof Columns\TranslationsColumn) {
                $column->useFormFields($translatable, $rich);
                if ($column->addsFilter() && $missing === null) { $missing = $column; }
            }
        }
        $languages = count(\FloCMS\Admin\Translation\Languages::all()) > 1;
        foreach ($this->filters as $filter) {
            if ($filter instanceof Filters\MissingTranslationFilter) {
                if ($filter->getFields() === []) { $filter->fields($missing?->countedFields() ?? $translatable, $rich); }
                $missing = null;
            }
        }
        if ($missing !== null && $languages && $missing->countedFields() !== [] && !isset($this->filters['missing_in'])) {
            $this->filters['missing_in'] = Filters\MissingTranslationFilter::make('missing_in')->fields($missing->countedFields(), $rich);
        }
        return $this;
    }

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

    /**
     * The list inside a relation manager (0.22): only $columns (null: all),
     * never the parent's key column or a filter on it, and the manager's page
     * size and default sort when it sets them.
     *
     * @internal
     * @param list<string>|null $columns
     * @param array{0: string, 1: string}|null $sort
     */
    public function forRelation(?array $columns, string $foreignKey, ?int $perPage, ?array $sort): self
    {
        $table = clone $this;
        $table->columns = array_filter($this->columns, static fn (Column $column, string $name): bool =>
            $name !== $foreignKey && ($columns === null || in_array($name, $columns, true)), ARRAY_FILTER_USE_BOTH);
        unset($table->filters[$foreignKey]);
        $table->filters = array_filter($table->filters, static fn (Filters\Filter $filter): bool => $filter->getColumn() !== $foreignKey);
        if ($perPage !== null) { $table->perPage = $perPage; }
        if ($sort !== null) { [$table->defaultSort, $table->defaultDirection] = $sort; }
        return $table;
    }
}
