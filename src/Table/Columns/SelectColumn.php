<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields\Select;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Cells\CellContext;

/**
 * A select in the list that saves on change (0.38):
 *
 *     SelectColumn::make('status')                                      // options from the form's Select
 *         ->colors(['published' => 'green', 'draft' => 'yellow'])
 *     SelectColumn::make('size')->options(['s' => 'Small', 'l' => 'Large'])   // no form field
 *
 * The form's field must be a plain Select (not one whose options depend on
 * another field).
 */
final class SelectColumn extends EditableColumn
{
    /** @var array<string, string> */
    private array $options = [];
    /** @var array<string, string> */
    private array $colors = [];

    /** @param array<int|string, string> $options value => label, when the form has no Select of this name */
    public function options(array $options): static
    {
        $this->options = [];
        foreach ($options as $value => $label) { $this->options[(string) $value] = (string) $label; }
        return $this;
    }

    /** @param array<int|string, string> $colors value => gray|green|blue|yellow|red|purple */
    public function colors(array $colors): static
    {
        foreach ($colors as $value => $color) {
            $this->colors[(string) $value] = in_array($color, BadgeColumn::COLORS, true) ? $color : 'gray';
        }
        return $this;
    }

    protected function acceptsField(Field $field): bool
    {
        return $field instanceof Select && !$field->hasDependentOptions() && $field->getDisplay() === 'select';
    }

    protected function acceptedFields(): string
    {
        return 'a Select field whose options do not depend on another field';
    }

    protected function defaultField(): Field
    {
        if ($this->options === [] && $this->prepared) { throw new \LogicException('SelectColumn "' . $this->name . '" needs options(): the form has no Select of this name.'); }
        return Select::make($this->name)->options($this->options);
    }

    /** @return array<string, string> value => label */
    public function getOptions(): array
    {
        $field = $this->field();
        return $field instanceof Select ? $field->getOptions() : [];
    }

    /** @return array<string, string> value => colour */
    public function getColors(): array { return $this->colors; }

    protected function kind(): string
    {
        return 'select';
    }

    private function color(string $value): string
    {
        return $this->colors[$value] ?? 'gray';
    }

    protected function renderValue(mixed $value): string
    {
        if ($value === null || $value === '' || !is_scalar($value)) { return parent::renderValue(null); }
        $key = (string) $value;
        $options = $this->getOptions();
        return '<span class="fa-badge fa-badge-' . $this->color($key) . '">' . Str::e($options[$key] ?? $key) . '</span>';
    }

    protected function control(object $record, string $state, CellContext $context, string $id, string $label): string
    {
        $options = $this->getOptions();
        $html = '<label class="fa-sr" for="' . Str::e($id) . '">' . Str::e($label) . '</label>'
            . '<span class="fa-cell-select-wrap fa-badge-' . $this->color($state) . '" data-admin-cell-select-wrap>'
            . '<select class="fa-cell-select" id="' . Str::e($id) . '" name="value" data-admin-cell-select>';
        if (!$this->field()->isRequired() || !array_key_exists($state, $options)) {
            $html .= '<option value=""' . ($state === '' ? ' selected' : '') . '>' . Str::e(AdminLang::get('cells.none')) . '</option>';
        }
        // A stored value that is no longer an option stays shown (saving it again is refused).
        if ($state !== '' && !array_key_exists($state, $options)) {
            $html .= '<option value="' . Str::e($state) . '" selected>' . Str::e($state) . '</option>';
        }
        foreach ($options as $value => $text) {
            $html .= '<option value="' . Str::e((string) $value) . '" data-color="' . $this->color((string) $value) . '"' . ((string) $value === $state ? ' selected' : '') . '>' . Str::e($text) . '</option>';
        }
        return $html . '</select>' . \FloCMS\Admin\Support\Icons::svg('chevron-down', 'fa-icon fa-cell-select-caret') . '</span>' . $this->saveButton($label);
    }

    public function cellClass(): string
    {
        return 'fa-cell-editable fa-cell-select-col';
    }
}
