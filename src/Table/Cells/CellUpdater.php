<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Cells;

use FloCMS\Admin\Data\RecordRepository;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Form\FormSubmission;
use FloCMS\Admin\Resource;
use FloCMS\Admin\Table\Columns\EditableColumn;
use FloCMS\Admin\Table\Table;
use FloCMS\Core\HttpException;
use FloCMS\Core\ValidationException;

/**
 * Saves one cell of the list (0.38), with the rules of the edit page:
 *
 *  - only an editable column of the resource's own list, never a name the browser makes up;
 *  - Resource::mayEdit() for the record (the Edit permission, canEdit(), visible(), authorize());
 *  - nobody changed this column since the list was drawn ($was), checked again under a row lock;
 *  - the form field's rules (required, max, unique…), then Resource::validate();
 *  - FormSubmission::save(): beforeSave(), updated_at, afterSave(), media usage and the activity log.
 *
 * @internal
 */
final class CellUpdater
{
    public const MAX_VALUE_LENGTH = 65535;

    public function __construct(
        private readonly Resource $resource,
        private readonly RecordRepository $repository,
        private readonly Table $table,
        private readonly Form $form,
    ) {
    }

    /** The editable column of this list named $name, or 404. */
    public function column(string $name): EditableColumn
    {
        $column = preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $name) === 1 ? $this->table->column($name) : null;
        if (!$column instanceof EditableColumn) { throw new HttpException(404); }
        return $column;
    }

    /**
     * Save the cell; returns the saved record.
     *
     * @throws CellConflict when the value changed since the list was drawn
     * @throws ValidationException when the value breaks the field's rules
     */
    public function update(string $id, string $name, mixed $value, string $was): object
    {
        $column = $this->column($name);
        if (!is_string($value) || strlen($value) > self::MAX_VALUE_LENGTH || strlen($was) > self::MAX_VALUE_LENGTH + 1) { throw new HttpException(400); }
        if ($this->repository->inTransaction()) { throw new \LogicException('A cell save owns its transaction.'); }
        $batch = new \FloCMS\Admin\Uploads\UploadBatch();
        $saved = null;
        try {
            $this->repository->transaction(function () use ($id, $name, $column, $value, $was, $batch, &$saved): void {
                $pk = $this->resource::getPrimaryKey();
                $record = $this->repository->find($id) ?? throw new HttpException(404);
                if (!$this->resource->mayEdit($record)) { throw new HttpException(403); }
                $this->checkUnchanged($record, $name, $was);
                // Lock the row while this column still holds the value the list showed.
                $raw = property_exists($record, $name) ? $record->{$name} : null;
                try {
                    $this->repository->lockUnchanged($id, (object) [$pk => $record->{$pk}, $name => $raw]);
                } catch (ValidationException) {
                    throw new CellConflict($this->repository->find($id));
                }
                $record = $this->repository->find($id) ?? throw new CellConflict(null);
                $this->checkUnchanged($record, $name, $was);

                $form = $this->cellForm($column);
                // Fields whose rules depend on this one (requiredWhen, visibleWhen) are checked
                // too, with the record's values, as the edit page would; they are not written.
                [$checked, $input] = $this->checkedForm($column, $record, $column->input($value));
                $submission = new FormSubmission($this->resource, $this->repository);
                $state = $submission->state($checked, $input, $record);
                $errors = $submission->errors($checked, $state, $record);
                if ($errors !== []) {
                    if (!class_exists(ValidationException::class)) { class_exists(\FloCMS\Core\Validator::class); }
                    throw new ValidationException($errors);
                }
                $stored = $column->field()->dehydrate($state[$name] ?? '');
                $extra = [];
                if (($before = $column->getBeforeUpdate()) !== null) {
                    $extra = $before($stored, $record);
                    if (!is_array($extra)) { throw new \LogicException('beforeUpdate() must return an array of column => value.'); }
                    unset($extra[$name], $extra[$pk]);
                }
                $submission->save($form, $state, $record, true, $batch, $extra);
                $saved = $this->repository->find($id) ?? throw new HttpException(404);
                if (($after = $column->getAfterUpdate()) !== null) { $after($stored, $saved); }
            });
        } catch (\Throwable $error) {
            $batch->rollback();
            throw $error;
        }
        $batch->commit();
        return $saved;
    }

    private function checkUnchanged(object $record, string $name, string $was): void
    {
        $raw = property_exists($record, $name) ? $record->{$name} : null;
        if (!hash_equals(EditableColumn::token($raw), $was)) { throw new CellConflict($record); }
    }

    /**
     * The form the value is checked with: the cell's own form plus the
     * fields that depend on this column, filled with the record's values.
     *
     * @return array{0: Form, 1: array<string, mixed>}
     */
    private function checkedForm(EditableColumn $column, object $record, array $input): array
    {
        $name = $column->getName();
        $fields = $this->cellForm($column)->fields();
        foreach ($this->form->fields() as $other => $field) {
            if ($other === $name || isset($fields[$other]) || !in_array($name, $field->dependencies(), true) || !$field->storesInColumn()) { continue; }
            $fields[$other] = clone $field;
            $input[$other] = $field->stateFromRecord($record, \FloCMS\Admin\Translation\Languages::default());
        }
        return [(new Form())->schema(array_values($fields)), $input];
    }

    /**
     * A form of the one field, plus the fields it depends on (visibleWhen,
     * requiredWhen, optionsFor) as read-only copies, so its conditions see
     * the record's values without writing them.
     */
    private function cellForm(EditableColumn $column): Form
    {
        $field = $column->field();
        $fields = [$field];
        foreach ($field->dependencies() as $dependency) {
            $other = $this->form->field($dependency);
            if ($other !== null && $dependency !== $field->getName()) { $fields[] = (clone $other)->disabled(); }
        }
        return (new Form())->schema($fields);
    }
}
