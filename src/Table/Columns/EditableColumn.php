<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Columns;

use Closure;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Table\Cells\CellContext;
use FloCMS\Admin\Table\Column;
use FloCMS\Admin\Translation\Languages;
use LogicException;

/**
 * A column whose cell people change straight from the list (0.38). Each cell
 * saves on its own through the same checks as the edit page: the Edit
 * permission and canEdit(), the form field's rules, a check that nobody
 * changed the value meanwhile, beforeSave()/afterSave(), updated_at and the
 * activity log.
 *
 * The value goes through the resource form's field of the same name (its
 * rules, options and stored shape). A column without a form field uses its
 * own rules().
 */
abstract class EditableColumn extends Column
{
    protected ?Field $formField = null;
    /** @var list<string> */
    protected array $ownRules = [];
    protected string|Closure|null $confirmHeading = null;
    protected string|Closure|null $confirmText = null;
    protected ?string $confirmButton = null;
    protected bool $confirms = false;
    protected bool $confirmDanger = false;
    protected ?Closure $beforeUpdate = null;
    protected ?Closure $afterUpdate = null;
    protected bool $prepared = false;

    /** Rules when the form has no field of this name, e.g. 'required|max:120'. */
    public function rules(string|array $rules): static
    {
        foreach (is_array($rules) ? $rules : explode('|', $rules) as $rule) {
            $rule = trim((string) $rule);
            if ($rule !== '' && !in_array($rule, $this->ownRules, true)) { $this->ownRules[] = $rule; }
        }
        return $this;
    }

    /**
     * Ask before saving: a dialog with JavaScript, a confirm page without.
     * Heading and text may be fn (object $record): string.
     */
    public function requiresConfirmation(string|Closure|null $heading = null, string|Closure|null $description = null, ?string $button = null, bool $danger = false): static
    {
        $this->confirms = true;
        $this->confirmDanger = $danger;
        $this->confirmHeading = $heading;
        $this->confirmText = $description;
        $this->confirmButton = $button;
        return $this;
    }

    /**
     * Change the values written with the cell's value: fn (mixed $value, object $record): array
     * returns other columns of the table to write too, e.g. ['published_at' => date('Y-m-d H:i:s')].
     */
    public function beforeUpdate(Closure $callback): static
    {
        $this->beforeUpdate = $callback;
        return $this;
    }

    /** Runs after the save, in its transaction: fn (mixed $value, object $savedRecord): void. */
    public function afterUpdate(Closure $callback): static
    {
        $this->afterUpdate = $callback;
        return $this;
    }

    public function getBeforeUpdate(): ?Closure { return $this->beforeUpdate; }
    public function getAfterUpdate(): ?Closure { return $this->afterUpdate; }

    public function isEditable(): bool
    {
        return true;
    }

    /**
     * Fit the column to the resource's form (Table::prepare()): use its field
     * of the same name, after checking that the field can be edited in a cell.
     *
     * @internal
     */
    public function useFormField(?Field $field): void
    {
        $this->prepared = true;
        if ($field === null) { $this->formField = null; $this->defaultField(); return; }
        if ($field->isTranslatable() || $this->isTranslatable()) {
            throw new LogicException('Column "' . $this->name . '" cannot be edited in the list: its field is translatable.');
        }
        if (!$this->acceptsField($field)) {
            throw new LogicException('Column "' . $this->name . '" cannot be edited in the list with a ' . (new \ReflectionClass($field))->getShortName() . ' field: '
                . static::class . ' works with ' . $this->acceptedFields() . '.');
        }
        if ($field->isOmitted()) { $this->formField = null; return; }
        $this->formField = $field;
    }

    public function detectTranslatable(bool $translatable): void
    {
        parent::detectTranslatable($translatable);
        if ($this->isTranslatable()) {
            throw new LogicException('Column "' . $this->name . '" cannot be edited in the list: it is translatable.');
        }
    }

    /** Whether the form field can be edited in this kind of cell. */
    abstract protected function acceptsField(Field $field): bool;

    /** The accepted field types, for the error message. */
    abstract protected function acceptedFields(): string;

    /** A field for a column the form does not have. */
    abstract protected function defaultField(): Field;

    /** The cell's control (switch, select, input), inside the cell's form. */
    abstract protected function control(object $record, string $state, CellContext $context, string $id, string $label): string;

    /** The cell's kind, for its CSS class: toggle, select, text. */
    abstract protected function kind(): string;

    /**
     * The field that reads, checks and writes the value: the form's field
     * with the column's own rules added, or a field made for the column.
     *
     * @internal
     */
    public function field(): Field
    {
        $field = $this->formField !== null ? clone $this->formField : $this->defaultField();
        if ($this->ownRules !== []) { $field->rules($this->ownRules); }
        return $field;
    }

    /** Whether the form had a field of this name (else the column's own rules apply). @internal */
    public function hasFormField(): bool
    {
        return $this->formField !== null;
    }

    /** The value as the field shows it in its input. @internal */
    public function stateOf(object $record): string
    {
        $state = $this->field()->stateFromRecord($record, Languages::default());
        return is_string($state) ? $state : '';
    }

    /** What the cell sends as the input of the field for a submitted value. @internal */
    public function input(string $submitted): array
    {
        return [$this->name => $submitted];
    }

    /**
     * The stored value as a token, sent with the cell so the server can tell
     * whether somebody changed it since the list was drawn.
     *
     * @internal
     */
    public static function token(mixed $raw): string
    {
        if ($raw === null) { return 'n'; }
        if (is_bool($raw)) { return 'v' . ($raw ? '1' : '0'); }
        if (is_float($raw)) { $raw = rtrim(rtrim(sprintf('%.10F', $raw), '0'), '.'); }
        return 'v' . (is_scalar($raw) ? (string) $raw : '');
    }

    /** Whether a save of this value has to ask first (the switch asks only when it is set to). @internal */
    public function needsConfirmation(): bool
    {
        return $this->confirms;
    }

    /** Whether the confirm button is red (a change that is hard to take back). @internal */
    public function confirmsDanger(): bool
    {
        return $this->confirmDanger;
    }

    /** @return array{title: string, text: string, button: string} @internal */
    public function confirmation(object $record, CellContext $context): array
    {
        $title = $context->title($record);
        $heading = $this->confirmHeading instanceof Closure ? ($this->confirmHeading)($record) : $this->confirmHeading;
        $text = $this->confirmText instanceof Closure ? ($this->confirmText)($record) : $this->confirmText;
        return [
            'title' => is_string($heading) && $heading !== '' ? AdminLang::label($heading) : AdminLang::get('cells.confirm_title', ['label' => $this->getLabel(), 'title' => $title]),
            'text' => is_string($text) && $text !== '' ? AdminLang::label($text) : AdminLang::get('cells.confirm_text'),
            'button' => $this->confirmButton !== null ? AdminLang::label($this->confirmButton) : AdminLang::get('cells.confirm_button'),
        ];
    }

    public function renderCell(object $record, ?CellContext $context = null): string
    {
        if ($context === null || !$context->mayEdit($record)) {
            return $this->render($record);
        }
        $pk = $context->resource()::getPrimaryKey();
        $id = (string) ($record->{$pk} ?? '');
        $raw = property_exists($record, $this->name) ? $record->{$this->name} : null;
        $controlId = $context->controlId($id, $this->name);
        $label = AdminLang::get('cells.label', ['label' => $this->getLabel(), 'title' => $context->title($record)]);
        $confirm = '';
        if ($this->needsConfirmation()) {
            $words = $this->confirmation($record, $context);
            $confirm = ' data-confirm data-confirm-title="' . Str::e($words['title']) . '" data-confirm-text="' . Str::e($words['text']) . '" data-confirm-button="' . Str::e($words['button']) . '"' . ($this->confirmDanger ? '' : ' data-confirm-tone="primary"');
        }
        return '<form class="fa-cell fa-cell-' . $this->kind() . '" method="post" action="' . Str::e($context->url($id)) . '" data-admin-local data-admin-cell data-prefix="' . Str::e($context->idPrefix()) . '" novalidate' . $confirm . '>'
            . \FloCMS\Core\Csrf::field()
            . '<input type="hidden" name="column" value="' . Str::e($this->name) . '">'
            . '<input type="hidden" name="was" value="' . Str::e(self::token($raw)) . '" data-admin-cell-was>'
            . '<input type="hidden" name="_back" value="' . Str::e($context->back()) . '">'
            . $this->control($record, $this->stateOf($record), $context, $controlId, $label)
            . '<span class="fa-cell-status" data-admin-cell-status role="status"></span>'
            . '</form>';
    }

    /** The Save button a form without JavaScript needs (hidden once scripts run). */
    protected function saveButton(string $label): string
    {
        return '<button type="submit" class="fa-btn fa-btn-sm fa-cell-save" data-admin-cell-save>' . Str::e(AdminLang::get('cells.save')) . '<span class="fa-sr"> ' . Str::e($label) . '</span></button>';
    }

    public function cellClass(): string
    {
        return 'fa-cell-editable';
    }
}
