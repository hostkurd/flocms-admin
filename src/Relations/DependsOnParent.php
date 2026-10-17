<?php
declare(strict_types=1);

namespace FloCMS\Admin\Relations;

/**
 * ->dependsOn('country_id') offers only related records whose column matches
 * another field of the form (cities of the chosen country).
 */
trait DependsOnParent
{
    private ?string $dependsField = null;
    private ?string $dependsColumn = null;
    private string $parentValue = '';

    /**
     * @param string $field the field of this form holding the parent value
     * @param string|null $column the related table's column to match (default: the same name)
     */
    public function dependsOn(string $field, ?string $column = null): static
    {
        $this->dependsField = RelationSource::identifier($field);
        $this->dependsColumn = RelationSource::identifier($column ?? $field);
        return $this;
    }

    public function getDependsOn(): ?string
    {
        return $this->dependsField;
    }

    public function dependencies(): array
    {
        return array_values(array_unique(array_merge(parent::dependencies(), $this->dependsField === null ? [] : [$this->dependsField])));
    }

    public function resolve(array $state): static
    {
        $field = parent::resolve($state);
        if ($this->dependsField !== null) {
            $value = $state[$this->dependsField] ?? '';
            $field->parentValue = is_scalar($value) ? trim((string) $value) : '';
        }
        return $field;
    }

    /** No parent chosen yet: nothing can be offered or accepted. */
    public function isBlocked(): bool
    {
        return $this->dependsField !== null && $this->parentValue === '';
    }

    /** @return array<string, string> conditions on the related table */
    public function conditions(): array
    {
        return $this->dependsField === null || $this->parentValue === '' ? [] : [(string) $this->dependsColumn => $this->parentValue];
    }

    /** The conditions for a given parent value (lookups). @return array<string, string> */
    public function conditionsFor(string $parent): array
    {
        return $this->dependsField === null ? [] : [(string) $this->dependsColumn => $parent];
    }
}
