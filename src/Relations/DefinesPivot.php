<?php
declare(strict_types=1);

namespace FloCMS\Admin\Relations;

/**
 * ->pivot('property_feature', 'property_id', 'feature_id') for many-to-many
 * relations: the pivot table, the column holding this record's key, the
 * column holding the related key, and this record's key column (default id).
 */
trait DefinesPivot
{
    private ?Pivot $pivot = null;
    private ?string $pivotOrder = null;

    public function pivot(string $table, string $ownerKey, string $relatedKey, string $parentKey = 'id'): static
    {
        $this->pivot = new Pivot($table, $ownerKey, $relatedKey, $parentKey, $this->pivotOrder);
        return $this;
    }

    /** Keep the chosen order in this pivot column (1, 2, 3…). */
    public function orderColumn(string $column): static
    {
        $this->pivotOrder = RelationSource::identifier($column);
        $this->pivot = $this->pivot?->withOrder($column);
        return $this;
    }

    public function getPivot(): ?Pivot
    {
        return $this->pivot;
    }

    protected function requirePivot(): Pivot
    {
        return $this->pivot ?? throw new \LogicException(static::class . ' "' . $this->getName() . '" needs ->pivot(\'table\', \'owner_key\', \'related_key\').');
    }
}
