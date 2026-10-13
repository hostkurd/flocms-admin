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

    /** Database writes in the callback share this connection's transaction/savepoint. */
    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback);
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

    /** A fingerprint of the complete row, sealed in the component snapshot. */
    public static function fingerprint(object $record): string
    {
        $values = (array) $record;
        ksort($values);
        return hash('sha256', serialize($values));
    }

    /** Compare every original column in the UPDATE itself, including tenant/owner fields. */
    public function updateUnchanged(string $id, array $data, object $original): void
    {
        [$where, $bindings] = $this->originalCondition($id, $original);
        // Even an empty save must prove that the original row still exists.
        if ($data === []) { $data = [$this->resource::getPrimaryKey() => $id]; }
        $set = implode(', ', array_map(fn (string $column): string => self::quote($column) . ' = ?', array_keys($data)));
        $sql = 'UPDATE ' . self::quote($this->resource::getTable()) . ' SET ' . $set . ' WHERE ' . $where;
        $statement = $this->db->pdo()->prepare($sql);
        $statement->execute([...array_values($data), ...$bindings]);
        // MySQL reports zero changed rows for a genuine no-op. A matching original
        // still counts as success; missing/changed rows are conflicts.
        if ($statement->rowCount() === 0) {
            $check = $this->db->pdo()->prepare('SELECT ' . self::quote($this->resource::getPrimaryKey()) . ' FROM ' . self::quote($this->resource::getTable()) . ' WHERE ' . $where . ($this->db->driver() === 'sqlite' ? '' : ' FOR UPDATE'));
            $check->execute($bindings);
            if ($check->fetchColumn() === false) { $this->conflict(); }
        }
    }

    public function deleteUnchanged(string $id, object $original): void
    {
        [$where, $bindings] = $this->originalCondition($id, $original);
        $statement = $this->db->pdo()->prepare('DELETE FROM ' . self::quote($this->resource::getTable()) . ' WHERE ' . $where);
        $statement->execute($bindings);
        if ($statement->rowCount() !== 1) { $this->conflict(); }
    }

    private function originalCondition(string $id, object $original): array
    {
        $where = [self::quote($this->resource::getPrimaryKey()) . ' = ?'];
        $bindings = [$id];
        foreach ((array) $original as $column => $value) {
            $name = self::quote($column);
            $where[] = $this->db->driver() === 'sqlite'
                ? $name . ' COLLATE BINARY IS ?'
                : 'CAST(' . $name . ' AS BINARY) <=> CAST(? AS BINARY)';
            $bindings[] = $value;
        }
        return [implode(' AND ', $where), $bindings];
    }

    private static function quote(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $identifier) !== 1) { throw new \InvalidArgumentException('Invalid database identifier.'); }
        return '`' . $identifier . '`';
    }

    private function conflict(): never
    {
        throw new \FloCMS\Core\ValidationException(['_form' => \FloCMS\Admin\Support\AdminLang::get('reactive.conflict')]);
    }

    public function delete(string $id): void
    {
        $this->newQuery()->where($this->resource::getPrimaryKey(), '=', $id)->delete();
    }

    /** Whether $value exists in $column in a row other than $exceptId. */
    public function exists(string $column, string $value, ?string $exceptId, ?string $language = null): bool
    {
        $query = $this->db->table($this->resource::getTable());
        if ($language === null) {
            $query = $query->where($column, '=', $value);
        } else {
            $expression = $this->store->sortExpression($query, $column, $language, Languages::default());
            // Exact, case-sensitive translation equality on SQLite and MySQL/MariaDB.
            if ($query->driver() !== 'sqlite') {
                $expression = 'CAST(' . $expression . ' AS BINARY)';
            }
            $query = $query->whereRaw($expression . ' = ?', [$value]);
        }
        if ($exceptId !== null) {
            $query = $query->where($this->resource::getPrimaryKey(), '!=', $exceptId);
        }

        return $query->count() > 0;
    }
}
