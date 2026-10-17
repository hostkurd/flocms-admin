<?php
declare(strict_types=1);

namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Relations\DefinesRelation;
use FloCMS\Admin\Relations\DependsOnParent;
use FloCMS\Admin\Relations\RelationWidget;
use FloCMS\Core\App;

/**
 * One related record: its key is stored in this table's column.
 *
 *     BelongsTo::make('city_id')->resource(CityResource::class)->required()
 *     BelongsTo::make('city_id')->resource(CityResource::class)->dependsOn('country_id')
 *     BelongsTo::make('brand_id')->table('brands', 'name')
 *
 * A searchable combobox in the browser; a plain select without JavaScript.
 * On save the key must exist in the related table (within its scope).
 */
class BelongsTo extends Field
{
    use DefinesRelation;
    use DependsOnParent;

    /** @var array<string, bool> */
    private array $checked = [];

    protected function setUp(): void
    {
        $this->nullable = true;
    }

    public function typeError(string $value): ?array
    {
        if ($value === '') {
            return null;
        }
        if ($this->isBlocked()) {
            return ['validation.relation', []];
        }
        $db = App::db();
        if ($db === null) {
            return null;
        }
        $key = $value . '|' . $this->parentValue;
        $this->checked[$key] ??= $this->relation()->existing($db, [$value], $this->conditions()) !== [];
        return $this->checked[$key] ? null : ['validation.relation', []];
    }

    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $lookup = (string) ($attributes['data-lookup-url'] ?? '');
        $except = $attributes['data-relation-except'] ?? null;
        $dependsLabel = (string) ($attributes['data-relation-depends-label'] ?? '');
        unset($attributes['data-lookup-url'], $attributes['data-relation-except'], $attributes['data-relation-depends-label']);
        $selected = $value === '' ? [] : [$value];
        $data = RelationWidget::options($this->relation(), $selected, $this->conditions(), $this->isBlocked(), $except);

        return RelationWidget::render([
            'id' => $id, 'name' => $name, 'multiple' => false, 'selected' => $selected,
            'options' => $data['options'], 'complete' => $data['complete'], 'lookup' => $lookup,
            'depends' => $this->getDependsOn(), 'dependsLabel' => $dependsLabel,
            'disabled' => $this->isDisabled(), 'placeholder' => $this->getPlaceholder(), 'attributes' => $attributes,
        ]);
    }
}
