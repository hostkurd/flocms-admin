<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields\TextInput;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Numbers;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Cells\CellContext;

/**
 * A short text or a number people change in the list (0.38): the value is
 * shown as text; Enter or a click opens an input in the cell. Enter or
 * leaving the cell saves, Escape cancels.
 *
 *     TextInputColumn::make('price')->numeric()->prefix('€ ')
 *     TextInputColumn::make('stock')->numeric(0)->rules('integer|min:0')
 *
 * Works with the form's TextInput field (not a password), or none.
 */
final class TextInputColumn extends EditableColumn
{
    private bool $numeric = false;
    private ?int $decimals = null;
    private string $prefix = '';
    private string $suffix = '';
    private ?int $limit = null;

    /** A number: shown with thousands separators, typed with a number keyboard, checked as numeric. */
    public function numeric(?int $decimals = null): static
    {
        $this->numeric = true;
        $this->decimals = $decimals === null ? null : max(0, min(6, $decimals));
        return $this;
    }

    public function isNumeric(): bool { return $this->numeric; }

    /** Text before the value, e.g. a currency: '€ '. */
    public function prefix(string $prefix): static { $this->prefix = $prefix; return $this; }

    /** Text after the value, e.g. ' m²'. */
    public function suffix(string $suffix): static { $this->suffix = $suffix; return $this; }

    /** Cut the shown text after $characters (the input keeps the whole value). */
    public function limit(int $characters): static { $this->limit = max(1, $characters); return $this; }

    protected function acceptsField(Field $field): bool
    {
        return $field instanceof TextInput && !$field->isPassword();
    }

    protected function acceptedFields(): string
    {
        return 'a TextInput field (not a password)';
    }

    protected function defaultField(): Field
    {
        $field = TextInput::make($this->name);
        return $this->numeric ? $field->numeric() : $field;
    }

    public function field(): Field
    {
        $field = parent::field();
        // A number column checks the value as a number even when the form's field does not.
        if ($this->numeric && !in_array('numeric', $field->getRules(), true)) { $field->rules('numeric'); }
        return $field;
    }

    protected function kind(): string
    {
        return 'text';
    }

    protected function renderValue(mixed $value): string
    {
        if ($value === null || $value === '' || !is_scalar($value)) { return parent::renderValue(null); }
        return Str::e($this->display($value));
    }

    private function display(mixed $value): string
    {
        if ($this->numeric && Numbers::isNumber($value)) {
            return Numbers::format(is_bool($value) ? (int) $value : $value, $this->decimals, AdminLang::label($this->prefix), AdminLang::label($this->suffix));
        }
        $text = (string) $value;
        if ($this->limit !== null && mb_strlen($text) > $this->limit) { $text = rtrim(mb_substr($text, 0, $this->limit)) . '…'; }
        return AdminLang::label($this->prefix) . $text . AdminLang::label($this->suffix);
    }

    protected function control(object $record, string $state, CellContext $context, string $id, string $label): string
    {
        $raw = property_exists($record, $this->name) ? $record->{$this->name} : null;
        $shown = $raw === null || $raw === '' || !is_scalar($raw) ? '' : $this->display($raw);
        $field = $this->field();
        $max = !$this->numeric && $field instanceof TextInput && !in_array("numeric", $field->getRules(), true) ? self::maxLength($field) : null;
        return '<button type="button" class="fa-cell-display" data-admin-cell-display aria-label="' . Str::e(AdminLang::get('cells.edit', ['label' => $label])) . '">'
            . ($shown === '' ? '<span class="fa-muted">&ndash;</span>' : '<span dir="auto">' . Str::e($shown) . '</span>')
            . \FloCMS\Admin\Support\Icons::svg('pencil', 'fa-icon fa-cell-pen') . '</button>'
            . '<span class="fa-cell-editor" data-admin-cell-editor>'
            . '<label class="fa-sr" for="' . Str::e($id) . '">' . Str::e($label) . '</label>'
            . '<input class="fa-input fa-cell-input" type="text" id="' . Str::e($id) . '" name="value" value="' . Str::e($state) . '" data-admin-cell-input'
            . ($this->numeric ? ' inputmode="decimal" dir="ltr"' : ' dir="auto"') . ($max !== null ? ' maxlength="' . $max . '"' : '') . ' autocomplete="off">'
            . '<span class="fa-cell-hint" aria-hidden="true">' . Str::e(AdminLang::get('cells.hint')) . '</span>'
            . '<span class="fa-cell-actions"><button type="button" class="fa-btn fa-btn-sm" data-admin-cell-cancel>' . Str::e(AdminLang::get('actions.cancel')) . '</button>'
            . $this->saveButton($label) . '</span>'
            . '</span>';
    }

    private static function maxLength(TextInput $field): ?int
    {
        foreach ($field->getRules() as $rule) {
            if (preg_match('/^max:(\d+)$/D', $rule, $m) === 1) { return (int) $m[1]; }
        }
        return null;
    }

    public function cellClass(): string
    {
        return 'fa-cell-editable fa-cell-text-col' . ($this->numeric ? ' fa-cell-number fa-cell-end' : '');
    }
}
