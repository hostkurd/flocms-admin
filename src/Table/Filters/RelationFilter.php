<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Filters;

use FloCMS\Admin\Relations\DefinesPivot;
use FloCMS\Admin\Relations\DefinesRelation;
use FloCMS\Admin\Relations\RelationWidget;
use FloCMS\Core\App;
use FloCMS\Core\Database;

/**
 * Filter a list by a related record, with the same searchable control as the
 * relation fields:
 *
 *     RelationFilter::make('city_id')->resource(CityResource::class)        // records of one city
 *     RelationFilter::make('features')->resource(FeatureResource::class)
 *         ->pivot('property_feature', 'property_id', 'feature_id')           // records having any of the chosen features
 */
final class RelationFilter extends Filter
{
    use DefinesRelation;
    use DefinesPivot;

    public const MAX = 20;

    /** @var array<string, string> */
    private array $titles = [];

    public function emptyValue(): string|array
    {
        return $this->getPivot() === null ? '' : [];
    }

    public function value(mixed $value): string|array
    {
        if ($this->getPivot() === null) {
            if (!is_string($value) || strlen($value) > 190) { throw new \InvalidArgumentException('Invalid filter value.'); }
            $value = trim($value);
            if ($value !== '') { $this->check([$value]); }
            return $value;
        }
        if (is_string($value)) {
            $decoded = $value === '' ? [] : json_decode($value, true);
            $value = is_array($decoded) ? $decoded : null;
        }
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX) { throw new \InvalidArgumentException('Invalid filter value.'); }
        $ids = \FloCMS\Admin\Relations\RelationSource::keys($value);
        if ($ids !== []) { $this->check($ids); }
        return $ids;
    }

    public function apply(Database $query, string|array $value): Database
    {
        $pivot = $this->getPivot();
        if ($pivot === null) {
            return $value === '' || is_array($value) ? $query : $query->where($this->column, '=', $value);
        }
        if (!is_array($value) || $value === []) {
            return $query;
        }
        [$sql, $bindings] = $pivot->hasAny($value);
        return $query->whereRaw($sql, $bindings);
    }

    public function describe(string|array $value): ?string
    {
        $ids = is_array($value) ? $value : [$value];
        $titles = $this->titlesOf($ids);
        return implode(', ', array_map(static fn (string $id): string => $titles[$id] ?? '#' . $id, $ids));
    }

    public function renderInput(string $id, string|array $value, string $invalid, string $prefix): ?string
    {
        $multiple = $this->getPivot() !== null;
        $selected = is_array($value) ? $value : ($value === '' ? [] : [$value]);
        $data = RelationWidget::options($this->relation(), $selected);
        $attributes = $invalid === '' ? [] : ['aria-invalid' => 'true', 'aria-describedby' => $id . '-error'];
        if (!$multiple) { $attributes['data-admin-filter'] = $this->getName(); }

        return RelationWidget::render([
            'id' => $id, 'name' => 'filter[' . $this->getName() . ']', 'multiple' => $multiple, 'selected' => $selected,
            'jsonName' => $multiple ? 'filter[' . $this->getName() . ']' : null, 'filterKey' => $multiple ? $this->getName() : null,
            'options' => $data['options'], 'complete' => $data['complete'], 'lookup' => $this->lookupUrl,
            'empty' => \FloCMS\Admin\Support\AdminLang::get('filters.all'), 'max' => $multiple ? self::MAX : null, 'attributes' => $attributes,
        ]);
    }

    private string $lookupUrl = '';

    /** The list page's lookup address (".../admin/<slug>/lookup"); this filter searches there. */
    public function lookupFrom(string $base): static
    {
        $this->lookupUrl = rtrim($base, '/') . '/' . rawurlencode($this->getName()) . '?for=filter';
        return $this;
    }

    /** @param list<string> $ids */
    private function check(array $ids): void
    {
        $db = App::db();
        if ($db !== null && count($this->relation()->existing($db, $ids)) !== count($ids)) {
            throw new \InvalidArgumentException('Unknown related record.');
        }
    }

    /** @param list<string> $ids @return array<string, string> */
    private function titlesOf(array $ids): array
    {
        $missing = array_diff($ids, array_keys($this->titles));
        $db = App::db();
        if ($missing !== [] && $db !== null) {
            $this->titles += $this->relation()->titles($db, array_values($missing));
        }
        return $this->titles;
    }
}
