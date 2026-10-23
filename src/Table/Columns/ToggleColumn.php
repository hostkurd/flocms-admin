<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Form\Fields\Toggle;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Cells\CellContext;

/**
 * A switch in the list that saves at once (0.38):
 *
 *     ToggleColumn::make('featured')                                  // 1 / 0, a Toggle field
 *     ToggleColumn::make('status')->values('published', 'draft')       // other stored values
 *         ->requiresConfirmation()
 *
 * Works with the form's Toggle field (1 / 0), a Select field offering both
 * values, or no field at all.
 */
final class ToggleColumn extends EditableColumn
{
    private string $on = '1';
    private string $off = '0';
    private bool $customValues = false;

    /** The stored values for on and off (default 1 and 0). */
    public function values(string|int $on, string|int $off): static
    {
        if ((string) $on === (string) $off) { throw new \InvalidArgumentException('ToggleColumn::values() needs two different values.'); }
        $this->on = (string) $on;
        $this->off = (string) $off;
        $this->customValues = true;
        return $this;
    }

    public function getOnValue(): string { return $this->on; }
    public function getOffValue(): string { return $this->off; }

    protected function acceptsField(Field $field): bool
    {
        if ($field instanceof Toggle) { return !$this->customValues || ($this->on === '1' && $this->off === '0'); }
        if ($field instanceof Select) {
            $options = $field->hasDependentOptions() ? [] : $field->getOptions();
            return array_key_exists($this->on, $options) && array_key_exists($this->off, $options);
        }
        return false;
    }

    protected function acceptedFields(): string
    {
        return 'a Toggle field (values 1 and 0) or a Select field offering both values';
    }

    protected function defaultField(): Field
    {
        if (!$this->customValues) { return Toggle::make($this->name); }
        return Select::make($this->name)->options([$this->on => $this->on, $this->off => $this->off])->required();
    }

    /** Whether the record's value is the "on" value. */
    public function isOn(object $record): bool
    {
        $raw = property_exists($record, $this->name) ? $record->{$this->name} : null;
        if (!$this->customValues) { return in_array($raw, [true, 1, '1', 'on', 'yes', 'true'], true); }
        return $raw !== null && (string) $raw === $this->on;
    }

    /** The field input for "on" or "off". @internal */
    public function stateFor(bool $on): string
    {
        return $this->field() instanceof Toggle ? ($on ? '1' : '0') : ($on ? $this->on : $this->off);
    }

    protected function kind(): string
    {
        return 'toggle';
    }

    protected function renderValue(mixed $value): string
    {
        $on = !$this->customValues ? in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true) : ($value !== null && (string) $value === $this->on);
        return '<span class="fa-badge ' . ($on ? 'fa-badge-green' : 'fa-badge-gray') . '">' . Str::e(AdminLang::get($on ? 'table.yes' : 'table.no')) . '</span>';
    }

    protected function control(object $record, string $state, CellContext $context, string $id, string $label): string
    {
        $on = $this->isOn($record);
        // The button sends the other value: without JavaScript a click saves the switched state.
        return '<input type="hidden" name="value" value="' . Str::e($this->stateFor(!$on)) . '" data-admin-cell-next>'
            . '<button type="submit" class="fa-cell-switch" id="' . Str::e($id) . '" role="switch" aria-checked="' . ($on ? 'true' : 'false') . '" aria-label="' . Str::e($label) . '"'
            . ' data-admin-cell-switch data-on="' . Str::e($this->stateFor(true)) . '" data-off="' . Str::e($this->stateFor(false)) . '">'
            . '<span class="fa-cell-switch-knob" aria-hidden="true"></span></button>';
    }

    public function cellClass(): string
    {
        return 'fa-cell-editable fa-cell-toggle-col';
    }
}
