<?php
declare(strict_types=1);

namespace FloCMS\Admin\Relations;

/**
 * Where a relation field, column or filter finds its records:
 *
 *     ->resource(CityResource::class)            table, key, title, scope and translations of a resource
 *     ->resource(AgentResource::class)->titleColumn('full_name')
 *     ->table('brands', 'name')                  a table without a resource (title column, key 'id')
 */
trait DefinesRelation
{
    /** @var class-string<\FloCMS\Admin\Resource>|null */
    private ?string $relationResource = null;
    private ?string $relationTable = null;
    private string $relationKey = 'id';
    private ?string $relationTitle = null;
    private bool $relationTranslatable = false;
    private ?RelationSource $relationSource = null;

    /** @param class-string<\FloCMS\Admin\Resource> $class */
    public function resource(string $class): static
    {
        if (!is_subclass_of($class, \FloCMS\Admin\Resource::class)) {
            throw new \InvalidArgumentException('->resource() expects a Resource class, got "' . $class . '".');
        }
        $this->relationResource = $class;
        $this->relationTable = null;
        $this->relationSource = null;
        return $this;
    }

    /** A related table without a resource: its title column and key (no scope). */
    public function table(string $table, string $titleColumn, string $key = 'id', bool $translatable = false): static
    {
        RelationSource::identifier($table); RelationSource::identifier($titleColumn); RelationSource::identifier($key);
        $this->relationTable = $table;
        $this->relationTitle = $titleColumn;
        $this->relationKey = $key;
        $this->relationTranslatable = $translatable;
        $this->relationResource = null;
        $this->relationSource = null;
        return $this;
    }

    /** The column shown as the related record's title (default: the resource's title column). */
    public function titleColumn(string $column): static
    {
        $this->relationTitle = RelationSource::identifier($column);
        $this->relationSource = null;
        return $this;
    }

    public function hasRelation(): bool
    {
        return $this->relationResource !== null || $this->relationTable !== null;
    }

    public function relation(): RelationSource
    {
        if ($this->relationSource !== null) {
            return $this->relationSource;
        }
        if ($this->relationResource !== null) {
            return $this->relationSource = RelationSource::forResource($this->relationResource, $this->relationTitle);
        }
        if ($this->relationTable !== null) {
            return $this->relationSource = RelationSource::forTable($this->relationTable, (string) $this->relationTitle, $this->relationKey, $this->relationTranslatable);
        }
        RelationSource::missing(static::class . ' "' . $this->getName() . '"');
    }
}
