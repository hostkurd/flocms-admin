<?php
declare(strict_types=1);

namespace FloCMS\Admin\ImportExport;

use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\FilterSet;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\Database;

/**
 * Writes a resource's records as CSV (0.25): the list as filtered and sorted,
 * or the chosen records, page by page of 500 rows straight to the output, so a
 * large export never sits in memory.
 */
final class Exporter
{
    public const PAGE = 500;

    private Schema $schema;
    private Export $export;

    public function __construct(private readonly Resource $resource, private readonly Database $db)
    {
        $this->schema = Schema::for($resource, $db);
        $this->export = $resource->export(new Export());
    }

    /** The most rows this resource's export may hold. */
    public function limit(): int
    {
        return min($this->export->getLimit() ?? PHP_INT_MAX, \FloCMS\Admin\Admin::config()->export()['limit']);
    }

    /** The headings, as field names or readable labels. @return list<string> */
    public function headings(bool $readable): array
    {
        return array_map(static fn (array $c): string => $readable ? $c['label'] : $c['heading'], $this->schema->exportColumns($this->export));
    }

    /** The headings an import reads (the template). @return list<string> */
    public function templateHeadings(): array
    {
        // New records have no id yet: the template leaves it out.
        return array_values(array_diff(array_keys($this->schema->importTargets()), [$this->resource::getPrimaryKey()]));
    }

    /**
     * How many rows the list holds as filtered (or of $ids), to show in the menu
     * and to refuse an export over the limit before it starts.
     *
     * @param list<string>|null $ids
     */
    public function count(Table $table, string $search, ?FilterSet $filters, ?array $ids): int
    {
        return $this->query($table, $search, null, null, $filters, $ids)->count();
    }

    /**
     * Writes the CSV; returns the number of rows written.
     *
     * @param list<string>|null $ids the chosen records, or null for the list as filtered
     */
    public function write(CsvWriter $writer, Table $table, string $search, ?string $sort, ?string $dir, ?FilterSet $filters, ?array $ids, bool $readable): int
    {
        $columns = $this->schema->exportColumns($this->export);
        $fields = $this->schema->exportFields($this->export);
        $writer->row(array_map(static fn (array $c): string => $readable ? $c['label'] : $c['heading'], $columns));
        $limit = $this->limit();
        $written = 0;
        for ($offset = 0; $written < $limit; $offset += self::PAGE) {
            $rows = $this->query($table, $search, $sort, $dir, $filters, $ids)->limit(min(self::PAGE, $limit - $written))->offset($offset)->get();
            if ($rows === []) { break; }
            $batch = new ExportBatch($fields, $rows, $this->db, $readable);
            foreach ($rows as $row) {
                $writer->row(array_map(static fn (array $c) => ($c['cell'])($row, $batch), $columns));
                $written++;
            }
            if (count($rows) < self::PAGE) { break; }
        }
        $writer->close();
        return $written;
    }

    /** Only the heading row: an empty file to fill in and import. */
    public function writeTemplate(CsvWriter $writer): void
    {
        $writer->row($this->templateHeadings());
        $writer->close();
    }

    /** The list's query (search, filters, sort), or the chosen records in the list's order. @param list<string>|null $ids */
    private function query(Table $table, string $search, ?string $sort, ?string $dir, ?FilterSet $filters, ?array $ids): Database
    {
        $repository = new RecordRepository($this->resource, $this->db, Translations::store());
        $query = $repository->newQuery();
        $pk = $this->resource::getPrimaryKey();
        if ($ids !== null) {
            return $query->whereIn($pk, $ids === [] ? [''] : $ids)->orderBy($pk, 'asc');
        }
        if ($filters !== null) { $query = $filters->apply($query); }
        $query = $repository->whereSearch($query, $table, $search);
        $column = $sort !== null ? $table->sortableColumn($sort) : null;
        if ($column === null && $table->getDefaultSort() !== null) {
            $column = $table->column((string) $table->getDefaultSort());
            $dir = $table->getDefaultDirection();
        }
        $dir = strtolower((string) $dir) === 'desc' ? 'desc' : 'asc';
        if ($column === null && $table->isReorderable()) {
            $query = $query->orderBy((string) $table->getReorderColumn(), 'asc');
        } elseif ($column !== null && !$column->isTranslatable()) {
            $query = $query->orderBy($column->getName(), $dir);
        } elseif ($column !== null) {
            $lang = \FloCMS\Admin\Support\AdminLang::language();
            $query = $query->orderByRaw(Translations::store()->sortExpression($query, $column->getName(), in_array($lang, \FloCMS\Admin\Translation\Languages::all(), true) ? $lang : \FloCMS\Admin\Translation\Languages::default(), \FloCMS\Admin\Translation\Languages::default()) . ' ' . strtoupper($dir));
        }
        // A stable order, so pages never repeat or skip a row.
        return $query->orderBy($pk, $column === null && !$table->isReorderable() ? 'desc' : 'asc');
    }

    /** "listings-2026-10-19.csv" */
    public function fileName(string $suffix = ''): string
    {
        $slug = preg_replace('/[^a-z0-9-]+/', '-', strtolower($this->resource::getSlug())) ?: 'export';
        return $slug . ($suffix !== '' ? '-' . $suffix : '') . '-' . date('Y-m-d') . '.csv';
    }
}
