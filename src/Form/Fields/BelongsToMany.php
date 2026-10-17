<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Relations\DefinesPivot;
use FloCMS\Admin\Relations\DefinesRelation;
use FloCMS\Admin\Relations\DependsOnParent;
use FloCMS\Admin\Relations\RelationSource;
use FloCMS\Admin\Relations\RelationWidget;
use FloCMS\Core\App;
use FloCMS\Core\Database;

/**
 * Several related records, kept in a pivot table (one row per pair), not in a
 * column of this table:
 *
 *     BelongsToMany::make('features')->resource(FeatureResource::class)
 *         ->pivot('property_feature', 'property_id', 'feature_id')->max(20)
 *
 * The form state is a JSON list of keys ('["3","7"]', or '' when none), so
 * `required` means "at least one". Pivot rows are written in the record's save
 * transaction, only where they changed, and removed when the record is deleted.
 */
class BelongsToMany extends Field
{
    use DefinesRelation;
    use DefinesPivot;
    use DependsOnParent;

    public const MAX = 500;

    private ?int $max = null;
    /** Loaded pivot values and existence checks, shared with the clones resolve() makes. */
    private \ArrayObject $loaded;
    private \ArrayObject $checked;

    protected function setUp(): void
    {
        $this->loaded = new \ArrayObject();
        $this->checked = new \ArrayObject();
    }

    /** The form carries this field (the JSON value, the no-JavaScript select, or its presence marker). */
    public function submitted(array $input): bool
    {
        return array_key_exists($this->name, $input) || array_key_exists($this->name . self::PRESENT, $input);
    }

    /** Hidden input sent with every form that shows the field, so "nothing chosen" differs from "not sent". */
    public const PRESENT = '__present';

    /** Whether $value has the same keys as the record's pivot rows (kept valid even if some left the scope). */
    public function unchanged(string $value, object $record): bool
    {
        $now = self::ids($value);
        $stored = self::ids((string) $this->stateFromRecord($record, ''));
        sort($now); sort($stored);
        return $now === $stored;
    }

    /** The value without keys that do not exist (or no longer match the parent). */
    public function onlyValid(string $value): string
    {
        $ids = self::ids($value);
        $db = App::db();
        if ($ids === [] || $db === null) { return $value; }
        if ($this->isBlocked()) { return ''; }
        $found = $this->relation()->existing($db, $ids, $this->conditions());
        return self::encode(array_values(array_filter($ids, static fn (string $id): bool => in_array($id, $found, true))));
    }

    /** At most this many related records. */
    public function max(int $max): static
    {
        if ($max < 1 || $max > self::MAX) { throw new \InvalidArgumentException('BelongsToMany max must be 1–' . self::MAX . '.'); }
        $this->max = $max;
        return $this;
    }

    public function getMax(): ?int
    {
        return $this->max;
    }

    public function storesInColumn(): bool
    {
        return false;
    }

    /** @return list<string> */
    public static function ids(string|array $state): array
    {
        if (is_array($state)) { return RelationSource::keys($state); }
        if ($state === '') { return []; }
        $decoded = json_decode($state, true);
        return is_array($decoded) && array_is_list($decoded) ? RelationSource::keys($decoded) : [];
    }

    /** @param list<string> $ids */
    public static function encode(array $ids): string
    {
        $ids = array_slice(RelationSource::keys($ids), 0, self::MAX);
        return $ids === [] ? '' : json_encode($ids, JSON_THROW_ON_ERROR);
    }

    public function stateFromRecord(?object $record, string $defaultLang): string|array
    {
        $pivot = $this->getPivot();
        $owner = $record !== null && $pivot !== null ? (string) ($record->{$pivot->parentKey} ?? '') : '';
        if ($owner === '') {
            return $this->defaultState($defaultLang);
        }
        $db = App::db();
        if ($db === null) {
            return '';
        }
        if (!isset($this->loaded[$owner])) { $this->loaded[$owner] = self::encode($pivot->related($db, [$owner])[$owner] ?? []); }
        return $this->loaded[$owner];
    }

    protected function defaultState(string $defaultLang): string|array
    {
        $default = $this->default instanceof \Closure ? ($this->default)() : $this->default;
        return self::encode(is_array($default) ? $default : []);
    }

    public function stateFromInput(array $input, array $languages): string|array
    {
        $value = $input[$this->name] ?? null;
        if (is_array($value)) {
            return self::encode(array_values($value));
        }
        return is_string($value) ? self::encode(self::ids($value)) : '';
    }

    public function typeError(string $value): ?array
    {
        $ids = self::ids($value);
        if ($ids === []) {
            return null;
        }
        if ($this->max !== null && count($ids) > $this->max) {
            return ['validation.relation_max', ['max' => (string) $this->max]];
        }
        if ($this->isBlocked()) {
            return ['validation.relation', []];
        }
        $db = App::db();
        if ($db === null) {
            return null;
        }
        $key = $value . '|' . $this->parentValue;
        if (!isset($this->checked[$key])) { $this->checked[$key] = count($this->relation()->existing($db, $ids, $this->conditions())) === count($ids); }
        return $this->checked[$key] ? null : ['validation.relation', []];
    }

    public function saveRelated(Database $db, object $record, string|array $state): void
    {
        $pivot = $this->requirePivot();
        $owner = (string) ($record->{$pivot->parentKey} ?? '');
        if ($owner === '') {
            throw new \LogicException('The saved record has no "' . $pivot->parentKey . '" column for the pivot table ' . $pivot->table . '.');
        }
        // Canonical keys as the related table stores them ("07" → "7"), in the chosen order
        $ids = self::ids($state);
        $canonical = [];
        foreach ($ids as $id) {
            $found = $this->relation()->existing($db, [$id]);
            $canonical[] = $found[0] ?? $id;
        }
        $pivot->sync($db, $owner, $canonical);
        $this->loaded->exchangeArray([]);
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $lookup = (string) ($attributes['data-lookup-url'] ?? '');
        $except = $attributes['data-relation-except'] ?? null;
        $dependsLabel = (string) ($attributes['data-relation-depends-label'] ?? '');
        unset($attributes['data-lookup-url'], $attributes['data-relation-except'], $attributes['data-relation-depends-label']);
        $selected = self::ids($value);
        $data = RelationWidget::options($this->relation(), $selected, $this->conditions(), $this->isBlocked(), $except);

        return RelationWidget::render([
            'id' => $id, 'name' => $name, 'multiple' => true, 'selected' => $selected, 'jsonName' => $name === '' ? null : $name,
            'options' => $data['options'], 'complete' => $data['complete'], 'lookup' => $lookup,
            'depends' => $this->getDependsOn(), 'dependsLabel' => $dependsLabel, 'max' => $this->max,
            'disabled' => $this->isDisabled(), 'placeholder' => $this->getPlaceholder(), 'attributes' => $attributes,
        ]);
    }
}
