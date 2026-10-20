<?php
declare(strict_types=1);
namespace FloCMS\Admin\Uploads;

use FloCMS\Admin\{Resource, Form\Form, Form\Fields\FileUpload};

/** Compensate file writes on DB rollback; delete replaced files after commit only. */
final class UploadBatch
{
    private array $created = [];
    private array $removed = [];
    private array $tokens = [];
    public function prepare(Form $form, array $state, Resource $resource, ?object $record): array
    {
        foreach ($form->writableFields() as $name => $field) {
            if (!$field instanceof FileUpload) { continue; }
            $value = $state[$name]; if ($value === '@remove') { $state[$name] = ''; } $old = (string) ($record->{$name} ?? '');
            if ($value === '@invalid') { throw new \LogicException('Validate uploads before saving.'); }
            if (str_starts_with($value, '@upload:')) {
                $entry = Uploads::entry($value, Uploads::scope($resource, $record, $name));
                $metadata = $field->validateFile(['name' => $entry['name'], 'tmp_name' => Uploads::root() . '/' . $entry['path'], 'error' => UPLOAD_ERR_OK]);
                $path = $resource::getSlug() . '/' . $name . '/' . bin2hex(random_bytes(20)) . '.' . $metadata['extension'];
                $storage = Uploads::storageFor($field);
                $this->created[] = [$storage, $path];
                $source = Uploads::root() . '/' . $entry['path'];
                // A square image (0.28, ImageUpload::square()): cropped and resized before it is stored.
                if ($field instanceof \FloCMS\Admin\Form\Fields\ImageUpload && $field->getSquare() !== null) { \FloCMS\Admin\Support\ImageResize::square($source, $field->getSquare(), $metadata['mime']); }
                $storage->put($path, $source);
                if (!$field->isPublic()) { chmod((string) $storage->path($path), 0600); }
                $this->tokens[] = $value; $state[$name] = $path;
            }
            if ($state[$name] !== $old && Uploads::managed($old, $resource, $field)) { $this->removed[] = [Uploads::storageFor($field), $old]; }
        }
        return $state;
    }
    public function deleting(Resource $resource, object $record): void
    {
        // A trashed record keeps its files until it is deleted for good (Trash::purge()).
        if (\FloCMS\Admin\Trash\Trash::enabledFor($resource)) { return; }
        foreach ($resource->form(new Form())->fields() as $name => $field) {
            if ($field instanceof FileUpload && Uploads::managed((string) ($record->{$name} ?? ''), $resource, $field)) { $this->removed[] = [Uploads::storageFor($field), (string) $record->{$name}]; }
        }
    }
    public function commit(): void
    {
        foreach ($this->removed as [$storage, $path]) { try { $storage->delete($path); } catch (\Throwable $e) { error_log('Admin upload cleanup failed: ' . $e->getMessage()); } }
        foreach ($this->tokens as $token) { try { Uploads::forget($token); } catch (\Throwable $e) { error_log('Admin pending upload cleanup failed: ' . $e->getMessage()); } }
        $this->created = $this->removed = $this->tokens = [];
    }
    public function rollback(): void
    {
        foreach ($this->created as [$storage, $path]) { $storage->delete($path); }
        $this->created = $this->removed = $this->tokens = [];
    }
}
