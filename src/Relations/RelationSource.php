<?php
declare(strict_types=1);

namespace FloCMS\Admin\Relations;

use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Table\Table;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Translation\Translations;
use FloCMS\Core\Database;
use InvalidArgumentException;
use LogicException;

/**
 * The records a relation points to: a table, its key and title column and,
 * when it comes from a resource, that resource's query() scope.
 *
 *     RelationSource::forResource(CityResource::class)        // table, key, title, scope, translations
 *     RelationSource::forTable('brands', 'name', 'id')
 *
 * Every identifier comes from PHP code, is checked here and quoted in SQL;
 * values are always bound.
 *
 * @internal
 */
final class RelationSource
{
    /** Search results per request. */
    public const LIMIT = 20;

    /** Related tables up to this size are sent to the browser whole and filtered there. */
    public const PRELOAD = 50;

    /** Options offered without JavaScript (plus the chosen ones). */
    public const PLAIN = 200;

    /** @var array<class-string, array{title: string, translatable: bool}> */
    private static array $resourceInfo = [];

    private function __construct(
        private readonly string $table,
        private readonly string $key,
        private readonly string $titleColumn,
        private readonly bool $translatable,
        private readonly ?string $resourceClass
    ) {
        foreach ([$table, $key, $titleColumn] as $identifier) { self::identifier($identifier); }
    }

    /** @param class-string<Resource> $class */
    public static function forResource(string $class, ?string $titleColumn = null): self
    {
        if (!is_subclass_of($class, Resource::class)) {
            throw new InvalidArgumentException('Relations need a Resource class: ' . $class . ' is not one.');
        }
        $info = self::$resourceInfo[$class] ??= self::inspect($class);
        $title = $titleColumn ?? $info['title'];
        return new self($class::getTable(), $class::getPrimaryKey(), $title, $titleColumn === null || $titleColumn === $info['title'] ? $info['translatable'] : self::fieldIsTranslatable($class, $title), $class);
    }

    public static function forTable(string $table, string $titleColumn, string $key = 'id', bool $translatable = false): self
    {
        return new self($table, $key, $titleColumn, $translatable, null);
    }

    public function table(): string { return $this->table; }
    public function key(): string { return $this->key; }
    public function titleColumn(): string { return $this->titleColumn; }
    public function isTranslatable(): bool { return $this->translatable; }
    /** @return class-string<Resource>|null */
    public function resourceClass(): ?string { return $this->resourceClass; }

    /** A query on the related table, narrowed by the related resource's query() scope. */
    public function query(Database $db): Database
    {
        $query = $db->table($this->table);
        if ($this->resourceClass !== null) {
            $query = (new $this->resourceClass())->query($query);
        }
        return $query;
    }

    /**
     * Titles of the given keys in the admin's language; missing keys are left out.
     *
     * @param list<string> $ids
     * @return array<string, string> key => title
     */
    public function titles(Database $db, array $ids): array
    {
        $ids = self::keys($ids);
        $titles = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            $rows = $this->query($db)->select([$this->column($this->key), $this->column($this->titleColumn)])->whereIn($this->column($this->key), $chunk)->get();
            foreach ($rows as $row) {
                $titles[(string) $row->{$this->key}] = $this->label($row);
            }
        }
        return $titles;
    }

    /**
     * Records whose title contains $term (every language of a translatable
     * title), or whose key is $term, ordered by title.
     *
     * @param array<string, string> $where extra column => value conditions (dependent relations)
     * @return list<array{id: string, label: string}>
     */
    public function search(Database $db, string $term, int $limit = self::LIMIT, array $where = [], ?string $except = null): array
    {
        $term = mb_substr(trim($term), 0, 100);
        $query = $this->filtered($db, $where, $except);
        if ($term !== '') {
            $like = '%' . strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']) . '%';
            $query = $query->where(function (Database $group) use ($term, $like, $db): void {
                if ($this->translatable) {
                    Translations::store()->whereContains($group, $this->titleColumn, Languages::all(), $term, Languages::default());
                } else {
                    $group->orWhereRaw('`' . $this->table . '`.`' . $this->titleColumn . "` LIKE ? ESCAPE '!'", [$like]);
                }
                if (preg_match('/^\d{1,18}$/D', $term) === 1) {
                    $group->orWhere($this->column($this->key), '=', $term);
                }
            });
        }
        return $this->rows($this->ordered($query)->select([$this->column($this->key), $this->column($this->titleColumn)])->limit(max(1, min(500, $limit)))->get());
    }

    /**
     * The first $limit records by title.
     *
     * @param array<string, string> $where
     * @return list<array{id: string, label: string}>
     */
    public function options(Database $db, int $limit, array $where = [], ?string $except = null): array
    {
        return $this->rows($this->ordered($this->filtered($db, $where, $except))->select([$this->column($this->key), $this->column($this->titleColumn)])->limit(max(1, $limit))->get());
    }

    /**
     * Which of the given keys exist (within the scope and conditions).
     *
     * @param list<string> $ids
     * @param array<string, string> $where
     * @return list<string>
     */
    public function existing(Database $db, array $ids, array $where = []): array
    {
        $ids = self::keys($ids);
        $found = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            foreach ($this->filtered($db, $where, null)->select([$this->column($this->key)])->whereIn($this->column($this->key), $chunk)->get() as $row) {
                $found[] = (string) $row->{$this->key};
            }
        }
        return $found;
    }

    /** "table.column", so a scope that joins other tables stays unambiguous. */
    private function column(string $column): string
    {
        return $this->table . '.' . $column;
    }

    /** @param array<string, string> $where */
    private function filtered(Database $db, array $where, ?string $except): Database
    {
        $query = $this->query($db);
        foreach ($where as $column => $value) {
            $query = $query->where($this->column(self::identifier((string) $column)), '=', $value);
        }
        if ($except !== null && $except !== '') {
            $query = $query->where($this->column($this->key), '!=', $except);
        }
        return $query;
    }

    private function ordered(Database $query): Database
    {
        $query = $this->translatable
            ? $query->orderByRaw(Translations::store()->sortExpression($query, $this->titleColumn, in_array(AdminLang::language(), Languages::all(), true) ? AdminLang::language() : Languages::default(), Languages::default()) . ' ASC')
            : $query->orderBy($this->column($this->titleColumn), 'asc');
        return $query->orderBy($this->column($this->key), 'asc');
    }

    /** @return list<array{id: string, label: string}> */
    private function rows(array $rows): array
    {
        return array_map(fn (object $row): array => ['id' => (string) $row->{$this->key}, 'label' => $this->label($row)], $rows);
    }

    public function label(object $row): string
    {
        $value = $row->{$this->titleColumn} ?? null;
        $text = $this->translatable ? Translations::get($value, AdminLang::language()) : (is_scalar($value) ? (string) $value : '');
        return trim($text) !== '' ? $text : '#' . (string) ($row->{$this->key} ?? '');
    }

    /** @return array{title: string, translatable: bool} */
    private static function inspect(string $class): array
    {
        /** @var Resource $resource */
        $resource = new $class();
        $title = $resource->table(new Table())->primaryColumn() ?? $class::getPrimaryKey();
        return ['title' => $title, 'translatable' => self::fieldIsTranslatable($class, $title)];
    }

    private static function fieldIsTranslatable(string $class, string $name): bool
    {
        try {
            return (new $class())->form(new Form())->field($name)?->isTranslatable() ?? false;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Distinct non-empty string keys.
     *
     * @param array<mixed> $ids
     * @return list<string>
     */
    public static function keys(array $ids): array
    {
        $keys = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && ($id = trim((string) $id)) !== '' && strlen($id) <= 190) { $keys[$id] = true; }
        }
        return array_map('strval', array_keys($keys));
    }

    public static function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $name) !== 1) {
            throw new InvalidArgumentException('Invalid relation identifier "' . $name . '": use letters, digits and "_".');
        }
        return $name;
    }

    /** Forget cached resource details (tests). */
    public static function reset(): void
    {
        self::$resourceInfo = [];
    }

    /** @return never */
    public static function missing(string $what): never
    {
        throw new LogicException($what . ' has no related records: call ->resource(CityResource::class) or ->table(\'cities\', \'name\').');
    }
}
