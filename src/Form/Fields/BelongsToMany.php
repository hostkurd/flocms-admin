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
    /** @var array<string, string> */
    private array $loaded = [];
    /** @var array<string, bool> */
    private array $checked = [];

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
        return $this->loaded[$owner] ??= self::encode($pivot->related($db, [$owner])[$owner] ?? []);
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
        $this->checked[$key] ??= count($this->relation()->existing($db, $ids, $this->conditions())) === count($ids);
        return $this->checked[$key] ? null : ['validation.relation', []];
    }

    public function persist(Database $db, string $id, string|array $state): void
    {
        $this->requirePivot()->sync($db, $id, self::ids($state));
        $this->loaded = [];
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
