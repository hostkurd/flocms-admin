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
            $state[$name] = $field->isWritable()
                ? $field->stateFromInput($input, Languages::all())
                : $field->stateFromRecord($record, Languages::default());
        }
        foreach ($form->fields() as $field) { $state = $field->prepare($state, Languages::default()); }
        return $this->resource->normalizeState($state, $record);
    }

    public function errors(Form $form, array $state, ?object $record): array
    {
        $pk = $this->resource::getPrimaryKey();
        $except = $record === null ? null : (string) $record->{$pk};
        $validator = new FormValidator(Languages::all(), Languages::default(),
            fn (Field $field, string $value, ?string $language = null): bool =>
                $this->repository->exists($field->getName(), $value, $except, $language), $record !== null);
        $errors = $validator->validate($form, $state, $this->skip($form, $state, $record));
        return $errors !== [] ? $errors : $this->resource->validate($state, $record);
    }

    /** Optional original record enables atomic optimistic concurrency for Reactive saves. */
    public function save(Form $form, array $state, ?object $record, bool $guarded = false): string
    {
        return $this->repository->transaction(function () use ($form, $state, $record, $guarded): string {
            $class = $this->resource;
            $skip = $this->skip($form, $state, $record);
            $data = [];
            foreach ($form->writableFields() as $name => $field) {
                if (in_array($name, $skip, true)) { continue; }
                $value = $state[$name];
                if ($record !== null && $field->isTranslatable()) {
                    $stored = Translations::store()->decode($record->{$name} ?? null, Languages::default());
                    $editable = array_fill_keys(Languages::all(), '');
                    $value = array_replace($stored, $editable, array_intersect_key(is_array($value) ? $value : [], $editable));
                }
                $data[$name] = $field->dehydrate($value);
            }
            $pk = $class::getPrimaryKey();
            if ($record !== null) { unset($data[$pk]); }
            unset($data['created_at'], $data['updated_at']);
            $data = $class->beforeSave($data, $record);
            if ($class::usesTimestamps()) {
                $now = date('Y-m-d H:i:s');
                if ($record === null) { $data['created_at'] = $now; }
                $data['updated_at'] = $now;
            }
            if ($record === null) { $id = $this->repository->insert($data); }
            else {
                $id = (string) $record->{$pk};
                if ($guarded) { $this->repository->updateUnchanged($id, $data, $record); }
                else { $this->repository->update($id, $data); }
            }
            $saved = $id !== '' ? $this->repository->find($id) : null;
            if ($saved !== null) { $class->afterSave($saved); }
            return $id;
        });
    }

    private function skip(Form $form, array $state, ?object $record): array
    {
        $skip = [];
        foreach ($form->writableFields() as $name => $field) {
            if ($field->skipsWrite($state[$name], $record !== null)) { $skip[] = $name; }
        }
        return $skip;
    }
}
