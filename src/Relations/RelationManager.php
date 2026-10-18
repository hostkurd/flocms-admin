<?php
declare(strict_types=1);

namespace FloCMS\Admin\Relations;

use FloCMS\Admin\Admin;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Support\AdminLang;
use InvalidArgumentException;

/**
 * A list of related records on a record's edit page (0.22): a property's units,
 * a country's cities. The child resource brings its table, form, permissions,
 * query(), trash and activity log; the manager adds one rule on top: every
 * record belongs to this parent, and new ones get the parent's key on the server.
 *
 *     // PropertyResource
 *     public function relations(): array
 *     {
 *         return [
 *             RelationManager::make(UnitResource::class, 'property_id'),
 *             RelationManager::make(EnquiryResource::class, 'property_id')->readOnly(),
 *         ];
 *     }
 *
 * Has-many relations only: the child's table holds the parent's key.
 */
final class RelationManager
{
    private ?string $name = null;
    private ?string $label = null;
    private ?string $icon = null;
    /** @var list<string>|null */
    private ?array $columns = null;
    private bool $readOnly = false;
    private bool $creates = true;
    private ?int $perPage = null;
    private ?array $sort = null;
    private ?string $emptyText = null;
    /** @var list<string> */
    private array $hidden = [];

    /** @param class-string<Resource> $resource */
    private function __construct(private readonly string $resource, private readonly string $foreignKey, private readonly string $ownerKey)
    {
    }

    /**
     * @param class-string<Resource> $resource the child resource (UnitResource::class)
     * @param string $foreignKey the child's column holding the parent's key ('property_id')
     * @param string|null $ownerKey the parent's column it points to (default: the parent's primary key)
     */
    public static function make(string $resource, string $foreignKey, ?string $ownerKey = null): self
    {
        if (!is_subclass_of($resource, Resource::class)) {
            throw new InvalidArgumentException('RelationManager::make() expects a resource class, got "' . $resource . '".');
        }
        foreach (array_filter([$foreignKey, $ownerKey], 'is_string') as $column) {
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $column) !== 1) { throw new InvalidArgumentException('"' . $column . '" is not a valid column name.'); }
        }
        return new self($resource, $foreignKey, $ownerKey ?? '');
    }

    /** A name for the manager when one parent lists the same child resource twice (default: the child's slug). */
    public function name(string $name): self
    {
        if (preg_match('/^[a-z0-9]+(-[a-z0-9]+)*$/D', $name) !== 1 || strlen($name) > 40) { throw new InvalidArgumentException('Relation manager names use lower case letters, digits and single hyphens.'); }
        $this->name = $name;
        return $this;
    }

    /** The tab's title: plain text or a lang key (default: the child's plural label). */
    public function label(string $label): self { $this->label = $label; return $this; }

    /** The tab's icon (default: the child's navigation icon). */
    public function icon(?string $icon): self { $this->icon = $icon; return $this; }

    /** @param list<string> $columns the child's list columns to show, in the child's order (default: all of them) */
    public function columns(array $columns): self
    {
        foreach ($columns as $column) { if (!is_string($column)) { throw new InvalidArgumentException('columns() takes column names.'); } }
        $this->columns = array_values($columns);
        return $this;
    }

    /** No New, Edit, Delete or other actions here (the records are only listed). */
    public function readOnly(bool $readOnly = true): self { $this->readOnly = $readOnly; return $this; }

    /** false: no New button here (Edit and Delete stay). */
    public function canCreate(bool $creates = true): self { $this->creates = $creates; return $this; }

    public function perPage(int $perPage): self { $this->perPage = max(1, min(100, $perPage)); return $this; }

    public function defaultSort(string $column, string $direction = 'asc'): self
    {
        $this->sort = [$column, strtolower($direction) === 'desc' ? 'desc' : 'asc'];
        return $this;
    }

    /** The text of an empty list: plain text or a lang key (default: "No units yet."). */
    public function emptyText(string $text): self { $this->emptyText = $text; return $this; }

    /** @param list<string> $fields the child's form fields to leave out here (not shown, not saved) */
    public function hideFields(array $fields): self
    {
        foreach ($fields as $field) { if (!is_string($field)) { throw new InvalidArgumentException('hideFields() takes field names.'); } }
        $this->hidden = array_values($fields);
        return $this;
    }

    /* ---------------------------------------------------------------
     * Reading
     * ------------------------------------------------------------- */

    /** @return class-string<Resource> */
    public function getResource(): string { return $this->resource; }
    public function getForeignKey(): string { return $this->foreignKey; }
    /** @param class-string<Resource> $parent */
    public function getOwnerKey(string $parent): string { return $this->ownerKey !== '' ? $this->ownerKey : $parent::getPrimaryKey(); }
    public function getName(): string { return $this->name ?? $this->resource::getSlug(); }
    public function getLabel(): string { return $this->label !== null ? AdminLang::label($this->label) : $this->resource::getPluralLabel(); }
    public function getIcon(): ?string { return $this->icon ?? $this->resource::getIcon(); }
    /** @return list<string>|null */
    public function getColumns(): ?array { return $this->columns; }
    public function isReadOnly(): bool { return $this->readOnly; }
    public function allowsCreate(): bool { return !$this->readOnly && $this->creates; }
    public function getPerPage(): ?int { return $this->perPage; }
    /** @return array{0: string, 1: string}|null */
    public function getDefaultSort(): ?array { return $this->sort; }
    /** @return list<string> */
    public function getHiddenFields(): array { return $this->hidden; }

    public function getEmptyText(): string
    {
        return $this->emptyText !== null ? AdminLang::label($this->emptyText) : AdminLang::get('relations.manager_empty', ['label' => mb_strtolower($this->resource::getPluralLabel())]);
    }

    /**
     * The managers a parent resource declares, by name. A broken declaration
     * (not a RelationManager, the same name twice) is a programming error.
     *
     * @return array<string, self>
     */
    public static function of(Resource $parent): array
    {
        $managers = [];
        foreach ($parent->relations() as $manager) {
            if (!$manager instanceof self) { throw new \LogicException($parent::class . '::relations() must return RelationManager objects.'); }
            if (isset($managers[$manager->getName()])) { throw new \LogicException($parent::class . '::relations() lists "' . $manager->getName() . '" twice: give one a name().'); }
            $managers[$manager->getName()] = $manager;
        }
        return $managers;
    }

    /** Problems admin:doctor reports, or [] (the child isn't registered, or its table lacks the key). */
    public function problems(string $parent, ?\FloCMS\Core\Database $db = null): array
    {
        $problems = [];
        $slug = $this->resource::getSlug();
        if (Admin::registry()->find($slug) !== $this->resource) {
            $problems[] = $this->resource . ' is not a registered resource (add it to "resources" in config/admin.php)';
        }
        if ($db !== null) {
            foreach ([[$this->resource::getTable(), $this->foreignKey], [$parent::getTable(), $this->getOwnerKey($parent)]] as [$table, $column]) {
                try { $db->query('SELECT `' . $column . '` FROM `' . $table . '` WHERE 1 = 0'); }
                catch (\Throwable) { $problems[] = 'table "' . $table . '" has no column "' . $column . '"'; }
            }
        }
        return $problems;
    }

    /**
     * Parents of a child record, for the parent's History: ["properties:5"].
     * Every registered resource whose relations() list this child is a parent.
     *
     * @return list<string>
     */
    public static function parentsOf(Resource $child, object $record): array
    {
        $notes = [];
        foreach (self::parentMap()[$child::class] ?? [] as [$parentSlug, $foreignKey]) {
            $id = $record->{$foreignKey} ?? null;
            if (is_scalar($id) && (string) $id !== '') { $notes[] = $parentSlug . ':' . $id; }
        }
        return array_values(array_unique($notes));
    }

    /** @var array<class-string<Resource>, list<array{0: string, 1: string}>>|null */
    private static ?array $parents = null;

    /** child class => [[parent slug, foreign key], …], built once per request. */
    private static function parentMap(): array
    {
        if (self::$parents !== null) { return self::$parents; }
        self::$parents = [];
        foreach (Admin::registry()->all() as $slug => $class) {
            try { $managers = self::of(new $class()); } catch (\Throwable) { continue; }
            foreach ($managers as $manager) {
                // The note names the parent's key column only when it is the primary key (the History looks records up by it).
                if ($manager->getOwnerKey($class) === $class::getPrimaryKey()) {
                    self::$parents[$manager->getResource()][] = [$slug, $manager->getForeignKey()];
                }
            }
        }
        return self::$parents;
    }

    /** @internal tests and config changes */
    public static function reset(): void { self::$parents = null; }
}
