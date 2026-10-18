<?php
declare(strict_types=1);

namespace FloCMS\Admin\Settings;

use Closure;
use FloCMS\Admin\Form\Field;
use FloCMS\Admin\Form\Fields\FileUpload;
use FloCMS\Admin\Form\Form;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Admin\Validation\FormValidator;

/**
 * Loads, validates and saves one settings page. Used by the settings page
 * controller; works without HTTP so it can be tested on its own.
 *
 * @internal
 */
final class SettingsEditor
{
    private readonly Form $form;
    /** @var array<string, mixed>|null stored values of this page by field name */
    private ?array $stored = null;

    public function __construct(private readonly SettingsPage $page, private readonly ?SettingsStore $store)
    {
        $this->form = $page->form();
    }

    public function form(): Form
    {
        return $this->form;
    }

    /** Whether values can be saved (database and table present). */
    public function ready(): bool
    {
        return $this->store !== null && $this->store->exists();
    }

    /** @return array<string, mixed> stored values of this page, by field name */
    public function stored(): array
    {
        if ($this->stored !== null) { return $this->stored; }
        $this->stored = [];
        if (!$this->ready()) { return $this->stored; }
        $all = $this->store->all();
        foreach ($this->page->storedFields() as $name => $field) {
            $key = $this->page->key($name);
            if (array_key_exists($key, $all)) { $this->stored[$name] = $all[$key]; }
        }
        return $this->stored;
    }

    /** The stored values as a record for Field::stateFromRecord(). */
    public function record(): object
    {
        return (object) $this->stored();
    }

    /** @return array<string, string|array<string, string>> */
    public function initialState(): array
    {
        $state = [];
        $record = $this->record();
        foreach ($this->form->fields() as $name => $field) {
            $state[$name] = $field->stateFromRecord($record, Languages::default());
        }
        return $state;
    }

    /**
     * A repeater button sent without JavaScript ("rooms:add", 0.24): the posted
     * values with the list changed, not validated and not saved. Null when the
     * operation is not for a list on this page. Chosen files are not kept.
     *
     * @param array<string, mixed> $input submitted fields
     * @return array<string, string|array<string, string>>|null
     */
    public function operate(array $input, mixed $operation): ?array
    {
        $record = $this->record();
        $state = [];
        foreach ($this->form->fields() as $name => $field) {
            $state[$name] = $field->isWritable() && !$field instanceof FileUpload ? $field->stateFromInput($input, Languages::all()) : $field->stateFromRecord($record, Languages::default());
        }
        return $this->form->evaluate($state, $record)->applyRepeaterOperation($state, $operation) ? $state : null;
    }

    /**
     * Validate the submitted page; when valid, store it.
     *
     * @param array<string, mixed> $input submitted fields
     * @param array<string, mixed> $files $_FILES['_uploads']
     * @param Closure(string): bool|null $isUploaded defaults to is_uploaded_file
     * @return array{state: array<string, string|array<string, string>>, errors: array<string, string>, saved: bool}
     */
    public function submit(array $input, array $files, ?Closure $isUploaded = null): array
    {
        $record = $this->record();
        $uploadFields = array_filter($this->form->fields(), static fn (Field $f): bool => $f instanceof FileUpload && $f->isWritable());
        $state = [];
        foreach ($this->form->fields() as $name => $field) {
            if (isset($uploadFields[$name])) { continue; }
            $state[$name] = $field->isWritable() ? $field->stateFromInput($input, Languages::all()) : $field->stateFromRecord($record, Languages::default());
        }
        $uploads = SettingsUploads::receive($uploadFields, $this->stored(), $input, $files, $isUploaded);
        $state += $uploads['state'];

        $resolved = $this->form->evaluate($state, $record);
        foreach ($resolved->fields() as $field) { $state = $field->prepare($state, Languages::default()); }
        $resolved = $this->form->evaluate($state, $record);

        $skip = [];
        foreach ($resolved->writableFields() as $name => $field) {
            if ($field->skipsWrite($state[$name] ?? '', true)) { $skip[] = $name; }
        }
        $errors = (new FormValidator(Languages::all(), Languages::default(), null, true))->validate($resolved, $state, $skip);
        $errors = array_replace($errors, $uploads['errors']);
        if ($errors !== [] || !$this->ready()) {
            return ['state' => $state, 'errors' => $errors, 'saved' => false];
        }

        $batch = new SettingsUploads();
        try {
            $state = $batch->store($this->page, array_intersect_key($uploadFields, $resolved->writableFields()), $state, $uploads['files'], $this->stored());
            $values = [];
            foreach ($resolved->writableFields() as $name => $field) {
                if (in_array($name, $skip, true) || $field instanceof \FloCMS\Admin\Form\Fields\Placeholder) { continue; }
                $values[$name] = self::storable($field, $state[$name] ?? '');
            }
            $values = $this->page->runBeforeSave($values);
            $rows = [];
            foreach ($values as $name => $value) {
                if (!isset($this->page->storedFields()[$name])) { continue; }
                $rows[$this->page->key((string) $name)] = $value;
            }
            $batch->replaced($this->page, $uploadFields, $this->stored(), $values);
            if (\FloCMS\Admin\Media\MediaLibrary::enabled()) {
                // The values and where they use library items, in one transaction.
                \FloCMS\Admin\Media\MediaLibrary::store()->connection()->transaction(function () use ($rows): void {
                    $this->store->put($rows);
                    $this->stored = null;
                    \FloCMS\Admin\Media\MediaUsage::recordValues('settings', $this->page->getName(), $this->page->form(), $this->stored(), true);
                });
            } else {
                $this->store->put($rows);
            }
        } catch (\FloCMS\Core\ValidationException $error) {
            // A chosen media item was deleted meanwhile: nothing was saved.
            $batch->rollback();
            $this->stored = null;
            $errors = [];
            foreach ($error->getErrors() as $field => $messages) { $errors[(string) $field] = implode(' ', (array) $messages); }
            return ['state' => $state, 'errors' => $errors, 'saved' => false];
        } catch (\Throwable $error) {
            $batch->rollback();
            $this->stored = null;
            throw $error;
        }
        $batch->commit();
        Settings::forget();
        $this->stored = null;
        $this->logChanges($record);
        $this->page->runAfterSave($values);

        return ['state' => $state, 'errors' => [], 'saved' => true];
    }

    /** One "settings saved" activity entry with the fields that changed (none: nothing is written). */
    private function logChanges(object $before): void
    {
        try {
            if (!\FloCMS\Admin\Activity\Activity::enabled()) { return; }
            $form = $this->page->form();
            $changes = \FloCMS\Admin\Activity\ActivityChanges::diff($form,
                \FloCMS\Admin\Activity\ActivityChanges::state($form, $before),
                \FloCMS\Admin\Activity\ActivityChanges::state($form, $this->record()));
            if ($changes === []) { return; }
            \FloCMS\Admin\Activity\Activity::record('settings_saved', 'settings', $this->page->getName(), strip_tags($this->page->getLabel()), $changes);
        } catch (\Throwable $error) {
            error_log('flocms-admin: could not log the settings change: ' . $error->getMessage());
        }
    }

    /** The value kept in the table: null for empty (the default applies again). */
    public static function storable(Field $field, string|array $state): mixed
    {
        if ($field instanceof FileUpload) {
            if ($field->isTranslatable()) {
                $values = array_filter(array_map('strval', is_array($state) ? $state : []), static fn (string $v): bool => $v !== '' && $v !== SettingsUploads::PENDING);
                return $values === [] ? null : $values;
            }
            return is_string($state) && $state !== '' && $state !== SettingsUploads::PENDING ? $state : null;
        }
        if ($field instanceof \FloCMS\Admin\Form\Fields\Repeater) {
            // Stored as the list itself (0.24), so setting('contact.links') is an array.
            $encoded = $field->dehydrate(is_string($state) ? $state : '');
            $list = is_string($encoded) ? json_decode($encoded, true) : null;
            return is_array($list) && $list !== [] ? $list : null;
        }
        if ($field->isTranslatable()) {
            // The field's own dehydrate() (rich text is cleaned there) returns the JSON text.
            $encoded = $field->dehydrate(array_map('strval', is_array($state) ? $state : []));
            $decoded = is_string($encoded) ? json_decode($encoded, true) : null;
            $values = array_filter(array_map(static fn ($v): string => is_scalar($v) ? (string) $v : '', is_array($decoded) ? $decoded : []), static fn (string $v): bool => $v !== '');
            return $values === [] ? null : $values;
        }
        $value = $field->dehydrate(is_array($state) ? '' : $state);
        return $value === null || $value === '' ? null : $value;
    }
}
