<?php
declare(strict_types=1);

namespace FloCMS\Admin\Data;

use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\TranslationStore;
use FloCMS\Core\Database;

/**
 * Database access for one resource, through FloCMS's query builder.
 * Column names only ever come from the resource's code; values are bound.
 */
final class RecordRepository
{
    public function __construct(
        private readonly Resource $resource,
        private readonly Database $db,
        private readonly TranslationStore $store
    ) {
    }

    public function newQuery(): Database
    {
        return $this->resource->query($this->db->table($this->resource::getTable()));
    }

    /**
     * One page of the list.
     *
     * @return array{data: list<object>, pagination: array<string, mixed>, sort: ?string, dir: string}
     */
    public function paginate(Table $table, string $search, ?string $sort, ?string $dir, int $page, int $perPage, string $lang): array
    {
        $query = $this->newQuery();
        $default = Languages::default();

        $search = trim($search);
        $searchable = $table->searchableColumns();
        if ($search !== '' && $searchable !== []) {
            $languages = Languages::all();
            $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';

            $query = $query->where(function (Database $group) use ($searchable, $languages, $like, $search, $default): void {
                foreach ($searchable as $column) {
                    if ($column->isTranslatable()) {
                        $this->store->whereContains($group, $column->getName(), $languages, $search, $default);
                    } else {
                        $group->orWhereRaw('`' . $column->getName() . "` LIKE ? ESCAPE '!'", [$like]);
                    }
                }
            });
        }

        $sortColumn = $sort !== null ? $table->sortableColumn($sort) : null;
        if ($sortColumn === null && $table->getDefaultSort() !== null) {
            // The requested column is not sortable: use the default sort and its direction
            $sortColumn = $table->column((string) $table->getDefaultSort());
            $dir = $table->getDefaultDirection();
        }
        $dir = strtolower((string) $dir) === 'desc' ? 'desc' : 'asc';

        if ($sortColumn !== null) {
            if ($sortColumn->isTranslatable()) {
                $sortLang = in_array($lang, Languages::all(), true) ? $lang : $default;
                $query = $query->orderByRaw($this->store->sortExpression($query, $sortColumn->getName(), $sortLang, $default) . ' ' . strtoupper($dir));
            } else {
                $query = $query->orderBy($sortColumn->getName(), $dir);
            }
        }

        // A stable order for rows with equal sort values
        $pk = $this->resource::getPrimaryKey();
        if ($sortColumn === null || $sortColumn->getName() !== $pk) {
            $query = $query->orderBy($pk, $sortColumn === null ? 'desc' : $dir);
        }

        $result = $query->paginate(max(1, $page), $perPage);

        return [
            'data' => $result['data'],
            'pagination' => $result['pagination'],
            'sort' => $sortColumn?->getName(),
            'dir' => $dir,
        ];
    }

    public function find(string $id): ?object
    {
        return $this->newQuery()->where($this->resource::getPrimaryKey(), '=', $id)->first();
    }

    /** @param array<string, mixed> $data */
    public function insert(array $data): string
    {
        $id = $this->db->table($this->resource::getTable())->insert($data);
        $pk = $this->resource::getPrimaryKey();

        // Tables whose key is not auto-increment get the submitted key back
        return $id > 0 ? (string) $id : (string) ($data[$pk] ?? '');
    }

    /** @param array<string, mixed> $data */
    public function update(string $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $this->newQuery()->where($this->resource::getPrimaryKey(), '=', $id)->update($data);
    }

    public function delete(string $id): void
    {
        $this->newQuery()->where($this->resource::getPrimaryKey(), '=', $id)->delete();
    }

    /** Whether $value exists in $column in a row other than $exceptId. */
    public function exists(string $column, string $value, ?string $exceptId): bool
    {
        $query = $this->db->table($this->resource::getTable())->where($column, '=', $value);
        if ($exceptId !== null) {
            $query = $query->where($this->resource::getPrimaryKey(), '!=', $exceptId);
        }

        return $query->count() > 0;
    }
}
