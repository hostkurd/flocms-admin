<?php
declare(strict_types=1);
namespace FloCMS\Admin\Form;

use FloCMS\Admin\{Resource, Data\RecordRepository, Translation\Languages, Translation\Translations, Validation\FormValidator};

/** Shared by full-page forms and Reactive actions; accepts declared fields only. */
final class FormSubmission
{
    public function __construct(private readonly Resource $resource, private readonly RecordRepository $repository) {}

    public function state(Form $form, array $input, ?object $record): array
    {
        $state = [];
        foreach ($form->fields() as $name => $field) {
            $state[$name] = $field->isWritable() && $field->submitted($input)
                ? $field->stateFromInput($input, Languages::all())
                : $field->stateFromRecord($record, Languages::default());
        }
        $state = \FloCMS\Admin\Uploads\Uploads::state($form, $state, $this->resource, $record);
        $form->evaluate($state, $record);
        foreach ($form->fields() as $field) { $state = $field->prepare($state, Languages::default()); }
        $state = $this->resource->normalizeState($state, $record);
        $state = \FloCMS\Admin\Uploads\Uploads::state($form, $state, $this->resource, $record);
        $form->evaluate($state, $record);
        return $state;
    }

    public function errors(Form $form, array $state, ?object $record): array
    {
        $form = $form->evaluate($state, $record);
        // Checkbox lists and tags (0.37): a removed option the record already has stays valid
        foreach ($form->writableFields() as $field) {
            if ($field instanceof \FloCMS\Admin\Form\Fields\ListField) { $field->allowStored($record !== null ? $field->values($field->stateFromRecord($record, Languages::default())) : []); }
        }
        $pk = $this->resource::getPrimaryKey();
        $except = $record === null ? null : (string) $record->{$pk};
        $validator = new FormValidator(Languages::all(), Languages::default(),
            fn (Field $field, string $value, ?string $language = null): bool =>
                $this->repository->exists($field->getName(), $value, $except, $language), $record !== null);
        $errors = $validator->validate($form, $state, $this->skip($form, $state, $record));
        $db = $this->repository->connection();
        foreach ($form->writableFields() as $name => $field) {
            if (!($field instanceof \FloCMS\Admin\Form\Fields\BelongsTo || $field instanceof \FloCMS\Admin\Form\Fields\BelongsToMany) || !$field->hasRelation()) { continue; }
            // An unchanged value stays valid even if the related record left the scope since (archived)
            if (isset($errors[$name]) && $record !== null && $field->unchanged((string) ($state[$name] ?? ''), $record)) { unset($errors[$name]); }
            // A record cannot be its own parent (a category tree)
            if ($record !== null && $field instanceof \FloCMS\Admin\Form\Fields\BelongsTo && !isset($errors[$name])
                && $field->relation()->table() === $this->resource::getTable() && ($state[$name] ?? '') !== ''
                && ($field->relation()->existing($db, [(string) $state[$name]])[0] ?? $state[$name]) === (string) ($record->{$pk} ?? '')) {
                $errors[$name] = \FloCMS\Admin\Support\AdminLang::get('validation.relation_self', ['attribute' => $field->getLabel()]);
            }
        }
        return $errors !== [] ? $errors : $this->resource->validate($state, $record);
    }

    /** Optional original record enables atomic optimistic concurrency for Reactive saves. */
    /**
     * The values a save would write, without writing them (a page's preview,
     * 0.30): each writable field as stored, after beforeSave(). Uploads are
     * not handled here.
     *
     * @return array<string, mixed>
     */
    public function values(Form $form, array $state, ?object $record): array
    {
        $form = $form->evaluate($state, $record);
        $skip = $this->skip($form, $state, $record);
        $data = [];
        foreach ($form->writableFields() as $name => $field) {
            if (in_array($name, $skip, true) || !$field->storesInColumn() || $field instanceof \FloCMS\Admin\Form\Fields\FileUpload) { continue; }
            $value = $state[$name];
            if ($record !== null && $field->isTranslatable()) {
                $stored = Translations::store()->decode($record->{$name} ?? null, Languages::default());
                $editable = array_fill_keys(Languages::all(), '');
                $value = array_replace($stored, $editable, array_intersect_key(is_array($value) ? $value : [], $editable));
            }
            $data[$name] = $field->dehydrate($value);
        }
        return $this->resource->beforeSave($data, $record);
    }

    /**
     * @param array<string, mixed> $extra other columns to write with the form's values
     *        (a list cell's beforeUpdate(), 0.38); the form's own values win
     */
    public function save(Form $form, array $state, ?object $record, bool $guarded = false, ?\FloCMS\Admin\Uploads\UploadBatch $batch = null, array $extra = []): string
    {
        foreach (array_keys($extra) as $column) {
            if (!is_string($column) || preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $column) !== 1) { throw new \InvalidArgumentException('Extra values must be keyed by column names.'); }
        }
        $form = $form->evaluate($state, $record);
        $owns = $batch === null;
        if ($owns && $this->repository->inTransaction()) { throw new \LogicException('Supply the outer transaction upload batch.'); }
        $batch ??= new \FloCMS\Admin\Uploads\UploadBatch();
        try {
        $id = $this->repository->transaction(function () use ($form, $state, $record, $guarded, $batch, $extra): string {
            // The activity log compares the record before and after the save (fresh forms: no cached values).
            $logging = \FloCMS\Admin\Activity\Activity::enabled($this->repository->connection());
            $before = $logging && $record !== null ? \FloCMS\Admin\Activity\ActivityChanges::state($this->resource->form(new Form()), $record) : [];
            $state = $batch->prepare($form, $state, $this->resource, $record);
            $class = $this->resource;
            $skip = $this->skip($form, $state, $record);
            $data = [];
            foreach ($form->writableFields() as $name => $field) {
                if (in_array($name, $skip, true) || !$field->storesInColumn()) { continue; }
                $value = $state[$name];
                if ($record !== null && $field->isTranslatable()) {
                    $stored = Translations::store()->decode($record->{$name} ?? null, Languages::default());
                    $editable = array_fill_keys(Languages::all(), '');
                    $value = array_replace($stored, $editable, array_intersect_key(is_array($value) ? $value : [], $editable));
                }
                $data[$name] = $field->dehydrate($value);
            }
            $data = array_replace($extra, $data);
            $pk = $class::getPrimaryKey();
            if ($record !== null) { unset($data[$pk]); }
            unset($data['created_at'], $data['updated_at']);
            // Inside a relation manager beforeSave() sees the parent's key too (0.22).
            if (($scope = $class->parentScope()) !== null) { $data[$scope->foreignKey()] = $scope->key(); }
            $prepared = $data;
            $data = $class->beforeSave($data, $record);
            // Inside a relation manager the parent's key is always the parent (0.22), whatever was sent.
            if (($scope = $class->parentScope()) !== null) { $data[$scope->foreignKey()] = $scope->key(); }
            foreach ($form->writableFields() as $name => $field) {
                if ($field instanceof \FloCMS\Admin\Form\Fields\FileUpload && ($data[$name] ?? null) !== ($prepared[$name] ?? null)) { throw new \LogicException('beforeSave must preserve managed upload columns.'); }
            }
            if ($class::usesTimestamps()) {
                $now = date('Y-m-d H:i:s');
                if ($record === null) { $data['created_at'] = $now; }
                $data['updated_at'] = $now;
            }
            if ($record === null) {
                // A reorderable resource puts a new record at the end (or the start) of the order.
                $table = $class->table(new \FloCMS\Admin\Table\Table());
                $column = $table->getReorderColumn();
                if ($column !== null && (($data[$column] ?? null) === null || $data[$column] === '')) {
                    $data[$column] = $this->repository->order($table)->nextPosition();
                }
                $id = $this->repository->insert($data);
            }
            else {
                $id = (string) $record->{$pk};
                if ($guarded) { $this->repository->updateUnchanged($id, $data, $record); }
                else { $this->repository->update($id, $data); }
            }
            $saved = $id !== '' ? $this->repository->find($id) : null;
            // Values kept outside the row (pivot tables), in the same transaction
            foreach ($form->writableFields() as $name => $field) {
                if (!$field->storesInColumn() && !in_array($name, $skip, true) && $saved !== null) {
                    $field->saveRelated($this->repository->connection(), $saved, $state[$name] ?? '');
                }
            }
            // Where library items are used (pickers and rich text), in the same transaction
            if ($saved !== null) { \FloCMS\Admin\Media\MediaUsage::record($class, $form, $saved); }
            if ($saved !== null) { $class->afterSave($saved); }
            if ($logging && $saved !== null) { $this->log($record, $saved, $before); }
            return $id;
        });
        } catch (\Throwable $error) { if ($owns) { $batch->rollback(); } throw $error; }
        if ($owns) { $batch->commit(); }
        return $id;
    }

    /** "created", or "updated" with the changed fields (nothing when no field changed). */
    private function log(?object $record, object $saved, array $before): void
    {
        $class = $this->resource;
        $id = (string) ($saved->{$class::getPrimaryKey()} ?? '');
        $title = \FloCMS\Admin\Trash\Trash::titleOf($class, $saved);
        $db = $this->repository->connection();
        if ($record === null) {
            \FloCMS\Admin\Activity\Activity::record('created', $class::getSlug(), $id, $title, meta: \FloCMS\Admin\Activity\Activity::parents($class, $saved), db: $db);
            return;
        }
        $form = $class->form(new Form());
        $changes = \FloCMS\Admin\Activity\ActivityChanges::diff($form, $before, \FloCMS\Admin\Activity\ActivityChanges::state($form, $saved), $class::activityExcept(), $db);
        // Both the old and the new parent hear of a record that moved.
        $parents = array_values(array_unique([...(\FloCMS\Admin\Activity\Activity::parents($class, $record)['parents'] ?? []), ...(\FloCMS\Admin\Activity\Activity::parents($class, $saved)['parents'] ?? [])]));
        if ($changes !== []) { \FloCMS\Admin\Activity\Activity::record('updated', $class::getSlug(), $id, $title, $changes, $parents === [] ? [] : ['parents' => $parents], $db); }
    }

    private function skip(Form $form, array $state, ?object $record): array
    {
        $skip = [];
        foreach ($form->writableFields() as $name => $field) {
            if ($field->isOmitted() || $field->skipsWrite($state[$name], $record !== null)) { $skip[] = $name; }
        }
        return $skip;
    }
}
