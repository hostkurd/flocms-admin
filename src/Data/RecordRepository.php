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

    /** The database connection (relation fields write pivot rows through it, in the same transaction). */
    public function connection(): Database
    {
        return $this->db;
    }

    /** Database writes in the callback share this connection's transaction/savepoint. */
    public function transaction(callable $callback): mixed
    {
        return $this->db->transaction($callback);
    }

    public function newQuery(): Database
    {
        $query = $this->resource->query($this->db->table($this->resource::getTable()));
        // Inside a relation manager: only this parent's records (0.22).
        $scope = $this->resource->parentScope();
        return $scope !== null ? $query->where($scope->foreignKey(), '=', $scope->key()) : $query;
    }

    /**
     * One page of the list.
     *
     * @return array{data: list<object>, pagination: array<string, mixed>, sort: ?string, dir: string}
     */
    public function paginate(Table $table, string $search, ?string $sort, ?string $dir, int $page, int $perPage, string $lang, ?\FloCMS\Admin\Table\FilterSet $filters = null): array
    {
        $query = $this->newQuery();
        if ($filters !== null) { $query = $filters->apply($query); }
        $default = Languages::default();

        $query = $this->whereSearch($query, $table, $search);

        $sortColumn = $sort !== null ? $table->sortableColumn($sort) : null;
        // A reorderable list without its own default sort shows the editors' order.
        $ordered = $sortColumn === null && $table->getDefaultSort() === null && $table->isReorderable();
        if ($ordered) {
            $query = $query->orderBy((string) $table->getReorderColumn(), 'asc');
        }
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
            $query = $query->orderBy($pk, $sortColumn === null ? ($ordered ? 'asc' : 'desc') : $dir);
        }

        $result = $query->paginate(max(1, $page), $perPage);

        return [
            'data' => $result['data'],
            'pagination' => $result['pagination'],
            'sort' => $sortColumn?->getName(),
            'dir' => $dir,
        ];
    }

    /** The editors' order of a reorderable table (Reorder mode, new-record positions). */
    public function order(Table $table): RecordOrder
    {
        return new RecordOrder($this->resource, $this->db, $table);
    }

    /**
     * Rows whose searchable columns contain $search (every language of a translated
     * column), as the list's search box finds them. Nothing searchable: unchanged.
     */
    public function whereSearch(Database $query, Table $table, string $search, bool $matchId = false): Database
    {
        $search = trim($search);
        $searchable = $table->searchableColumns();
        if ($search === '' || ($searchable === [] && !$matchId)) {
            return $query;
        }
        $languages = Languages::all();
        $default = Languages::default();
        $like = '%' . strtr($search, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
        // "#42" or "42" also finds record 42 (0.20); "#42" finds only it.
        $id = $matchId && preg_match('/^#?(\d{1,18})$/D', $search, $m) === 1 ? $m[1] : null;
        $onlyId = $id !== null && str_starts_with($search, '#');
        if ($searchable === [] && $id === null) {
            return $query->whereRaw('1 = 0', []);
        }

        return $query->where(function (Database $group) use ($searchable, $languages, $like, $search, $default, $id, $onlyId): void {
            if ($id !== null) {
                $group->orWhere($this->resource::getPrimaryKey(), '=', $id);
            }
            if ($onlyId) {
                return;
            }
            foreach ($searchable as $column) {
                if ($column->isTranslatable()) {
                    $this->store->whereContains($group, $column->getName(), $languages, $search, $default);
                } else {
                    $group->orWhereRaw('`' . $column->getName() . "` LIKE ? ESCAPE '!'", [$like]);
                }
                foreach ($column->getAlsoSearched() as $other) {
                    $group->orWhereRaw('`' . $other . "` LIKE ? ESCAPE '!'", [$like]);
                }
            }
        });
    }

    /**
     * Global search (0.20): up to $limit rows matching $search, within the resource's
     * query(); a title that starts with the words first, then the newest.
     *
     * @return list<object>
     */
    public function search(Table $table, string $search, int $limit, string $lang): array
    {
        $query = $this->whereSearch($this->newQuery(), $table, $search, true);
        $title = $table->primaryColumn();
        $column = $title !== null ? $table->column($title) : null;
        if ($column !== null && $column->isSearchable() && trim($search) !== '') {
            $expression = $column->isTranslatable()
                ? $this->store->sortExpression($query, $column->getName(), in_array($lang, Languages::all(), true) ? $lang : Languages::default(), Languages::default())
                : '`' . $column->getName() . '`';
            if ($column->isTranslatable() && $query->driver() !== 'sqlite') {
                // JSON_UNQUOTE() compares case-sensitively on MySQL/MariaDB
                $expression = 'CONVERT(' . $expression . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci';
            }
            $prefix = strtr(trim($search), ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query = $query->orderByRaw('CASE WHEN ' . $expression . " LIKE ? ESCAPE '!' THEN 0 ELSE 1 END", [$prefix]);
        }
        return array_values($query->orderBy($this->resource::getPrimaryKey(), 'desc')->limit(max(1, $limit))->get());
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

    /** Whether a caller already owns a transaction on this connection. */
    public function inTransaction(): bool { return $this->db->pdo()->inTransaction(); }

    /** Lock the expected row without firing its UPDATE triggers. */
    public function lockUnchanged(string $id, object $original): void
    {
        if (!$this->inTransaction()) { throw new \LogicException('Record locks require a transaction.'); }
        [$where, $bindings] = $this->originalCondition($id, $original);
        $table = self::quote($this->resource::getTable());
        $pk = self::quote($this->resource::getPrimaryKey());
        if ($this->db->driver() === 'sqlite') {
            // SQLite has no SELECT FOR UPDATE. Acquire write intent without
            // touching any rows, then check the original while holding it.
            try { $this->db->pdo()->exec('UPDATE ' . $table . ' SET ' . $pk . ' = ' . $pk . ' WHERE 0'); }
            catch (\PDOException $error) {
                if (((int) ($error->errorInfo[1] ?? 0) & 255) === 5) { $this->conflict(); }
                throw $error;
            }
        }
        $statement = $this->db->pdo()->prepare('SELECT ' . $pk . ' FROM ' . $table . ' WHERE ' . $where
            . ($this->db->driver() === 'sqlite' ? '' : ' FOR UPDATE'));
        $statement->execute($bindings);
        if ($statement->fetchColumn() === false) { $this->conflict(); }
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
        $this->db->transaction(function () use ($id, $original): void {
            $trashed = $this->toTrash($original);
            $this->detachRelations($original);
            // A trashed record keeps its media usage until it is deleted for good.
            if (!$trashed) { \FloCMS\Admin\Media\MediaUsage::forget($this->resource, $id); }
            [$where, $bindings] = $this->originalCondition($id, $original);
            $statement = $this->db->pdo()->prepare('DELETE FROM ' . self::quote($this->resource::getTable()) . ' WHERE ' . $where);
            $this->refusedWhenInUse(static fn () => $statement->execute($bindings));
            if ($statement->rowCount() !== 1) { $this->conflict(); }
            $this->resource->afterDelete($original);
            $this->logDeleted($id, $original);
        });
    }

    /** Copy the record into the trash when it is on for this resource; whether it was. */
    private function toTrash(object $record): bool
    {
        if (!\FloCMS\Admin\Trash\Trash::enabledFor($this->resource, $this->db)) { return false; }
        \FloCMS\Admin\Trash\Trash::capture($this->resource, $this->db, $record);
        return true;
    }

    private function logDeleted(string $id, object $record): void
    {
        \FloCMS\Admin\Search\RecentRecords::forget($this->resource::getSlug(), $id);
        \FloCMS\Admin\Activity\Activity::record('deleted', $this->resource::getSlug(), $id, \FloCMS\Admin\Trash\Trash::titleOf($this->resource, $record),
            meta: (\FloCMS\Admin\Trash\Trash::enabledFor($this->resource, $this->db) ? ['trash' => 1] : []) + \FloCMS\Admin\Activity\Activity::parents($this->resource, $record), db: $this->db);
    }

    /** Pivot rows of the record's many-to-many fields go with it (before the row, for foreign keys). */
    private function detachRelations(object $record): void
    {
        foreach ($this->resource->form(new \FloCMS\Admin\Form\Form())->fields() as $field) {
            if ($field instanceof \FloCMS\Admin\Form\Fields\BelongsToMany && ($pivot = $field->getPivot()) !== null) {
                $owner = (string) ($record->{$pivot->parentKey} ?? '');
                if ($owner !== '') { $pivot->detach($this->db, $owner); }
            }
        }
    }

    /**
     * A delete the database refuses because other records still point to this
     * one (a foreign key) becomes a readable message instead of an error page.
     */
    private function refusedWhenInUse(\Closure $delete): void
    {
        try {
            $delete();
        } catch (\PDOException $error) {
            $driverCode = (int) ($error->errorInfo[1] ?? 0);
            $foreignKey = in_array($driverCode, [1451, 1217], true) || ($error->getCode() === '23000' && stripos($error->getMessage(), 'foreign key') !== false);
            if (!$foreignKey) { throw $error; }
            if (!class_exists(\FloCMS\Core\ValidationException::class)) { class_exists(\FloCMS\Core\Validator::class); }
            throw new \FloCMS\Core\ValidationException(['_form' => \FloCMS\Admin\Support\AdminLang::get('delete.in_use', ['label' => $this->resource::getLabel()])]);
        }
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
        if (!class_exists(\FloCMS\Core\ValidationException::class)) { class_exists(\FloCMS\Core\Validator::class); }
        throw new \FloCMS\Core\ValidationException(['_form' => \FloCMS\Admin\Support\AdminLang::get('reactive.conflict')]);
    }

    public function delete(string $id): void
    {
        $this->db->transaction(function () use ($id): void {
            $record = $this->find($id);
            $trashed = $record !== null && $this->toTrash($record);
            if ($record !== null) { $this->detachRelations($record); }
            if (!$trashed) { \FloCMS\Admin\Media\MediaUsage::forget($this->resource, $id); }
            $this->refusedWhenInUse(fn () => $this->newQuery()->where($this->resource::getPrimaryKey(), '=', $id)->delete());
            if ($record !== null) { $this->resource->afterDelete($record); $this->logDeleted($id, $record); }
        });
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
