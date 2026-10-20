<?php
declare(strict_types=1);
namespace FloCMS\Admin\Uploads;

use FloCMS\Admin\{Admin, Resource, Form\Form, Form\Fields\FileUpload, Translation\Languages};
use FloCMS\Core\{Session, Csrf, HttpException};
use FloCMS\Uploader\{Uploader, Storage\LocalStorage};

/** Session-bound staging. File bytes never enter Reactive snapshots. */
final class Uploads
{
    private const KEY = '_flo_admin_uploads';
    public static function root(): string { return Admin::config()->root() . '/storage/admin-uploads'; }
    public static function storage(): LocalStorage { return new LocalStorage(['root' => self::root(), 'visibility' => 'private']); }
    /** Public uploads (0.28, FileUpload::public()): avatars and the like, served directly. */
    public static function publicStorage(): LocalStorage { return new LocalStorage(['root' => Admin::config()->publicUploads()['root'], 'visibility' => 'public']); }
    public static function storageFor(FileUpload $field): LocalStorage { return $field->isPublic() ? self::publicStorage() : self::storage(); }
    /** The public URL of a stored public upload. */
    public static function publicUrl(string $path): string { return Admin::config()->publicUploads()['url'] . '/' . ltrim($path, '/'); }
    /** Delete a stored file wherever it is (the trash keeps paths, not fields). */
    public static function deleteStored(string $path): void
    {
        foreach ([self::storage(), self::publicStorage()] as $storage) {
            $absolute = $storage->path($path);
            if (is_string($absolute) && is_file($absolute)) { $storage->delete($path); }
        }
    }
    public static function scope(Resource $resource, ?object $record, string $name): string
    {
        return json_encode([$resource::getSlug(), (string) ($record->{$resource::getPrimaryKey()} ?? ''), $name], JSON_THROW_ON_ERROR);
    }
    private static function entries(): array { return (array) Session::get(self::KEY, []); }
    public static function entry(string $value, ?string $scope = null): array
    {
        $token = preg_match('/^@upload:([a-f0-9]{64})$/D', $value, $match) ? $match[1] : '';
        $entry = self::entries()[$token] ?? null;
        if (!is_array($entry) || $entry['issued'] <= time() - 1800 || $entry['actor'] !== (string) Session::get('user_id', '') || !hash_equals($entry['csrf'], hash('sha256', Csrf::token())) || ($scope !== null && $entry['scope'] !== $scope) || !is_file(self::root() . '/' . $entry['path'])) { throw new HttpException(422); }
        return $entry;
    }
    public static function stage(array $file, FileUpload $field, string $scope): string
    {
        foreach (['tmp_name', 'name', 'error'] as $key) { if (!isset($file[$key]) || !is_scalar($file[$key])) { throw new HttpException(422); } }
        if (!is_uploaded_file((string) $file['tmp_name'])) { throw new HttpException(422); }
        $field->validateFile($file);
        if (!is_dir(self::root()) && !mkdir(self::root(), 0700, true) && !is_dir(self::root())) { throw new \RuntimeException('Cannot create private upload storage.'); }
        self::prune();
        $entries = array_filter(self::entries(), static fn ($e): bool => $e['issued'] > time() - 1800);
        if (count($entries) >= 32 || array_sum(array_column($entries, 'size')) + filesize($file['tmp_name']) > 67108864) { throw new HttpException(422); }
        $result = Uploader::make(['default_disk' => 'private', 'disks' => ['private' => ['driver' => 'local', 'root' => self::root(), 'visibility' => 'private']]])->directory('pending')->maxBytes(16777216)->upload($file);
        chmod((string) $result->absolutePath(), 0600);
        $token = bin2hex(random_bytes(32));
        $entries[$token] = ['path' => $result->path(), 'name' => $result->originalName(), 'size' => $result->size(), 'issued' => time(), 'scope' => $scope, 'actor' => (string) Session::get('user_id', ''), 'csrf' => hash('sha256', Csrf::token())];
        Session::set(self::KEY, $entries);
        return '@upload:' . $token;
    }
    /** Accept only the original record's path, removal, or a matching pending token. */
    public static function trusted(FileUpload $field, mixed $value, Resource $resource, ?object $record): string
    {
        $old = (string) $field->stateFromRecord($record, Languages::default());
        if ($value === '' || $value === null || $value === $old) { return $old; }
        if ($value === '@remove') { return '@remove'; }
        if (!is_string($value)) { return '@invalid'; }
        try { $e = self::entry($value, self::scope($resource, $record, $field->getName())); $field->validateFile(['name' => $e['name'], 'tmp_name' => self::root() . '/' . $e['path'], 'error' => UPLOAD_ERR_OK]); return $value; }
        catch (\FloCMS\Core\HttpException|\FloCMS\Uploader\Exceptions\UploadException) { return '@invalid'; }
    }
    public static function state(Form $form, array $state, Resource $resource, ?object $record): array
    {
        foreach ($form->fields() as $name => $field) {
            if ($field instanceof FileUpload && $field->isTranslatable()) { throw new \LogicException('Translatable uploads ("' . $name . '") are supported on settings pages only.'); }
            if ($field instanceof FileUpload && $field->isWritable()) { $state[$name] = self::trusted($field, $state[$name] ?? '', $resource, $record); }
        }
        return $state;
    }
    /** Real multipart inputs only; called after the controller authorizes the record. */
    public static function receive(Form $form, array $input, array $files, Resource $resource, ?object $record): array
    {
        $state = [];
        foreach ($form->fields() as $name => $field) { $state[$name] = $field->stateFromInput($input, Languages::all()); }
        $resolved = $form->evaluate($state, $record);
        foreach ($resolved->writableFields() as $name => $field) {
            if (!$field instanceof FileUpload) { continue; }
            if (($input['_remove_upload'][$name] ?? '') === '1') { $input[$name] = '@remove'; }
            elseif (($input['_upload_control'][$name] ?? '') === '1' && ($input[$name] ?? '') === '@remove') { $input[$name] = ''; }

            if (!isset($files['error'][$name]) || $files['error'][$name] === UPLOAD_ERR_NO_FILE) { continue; }
            try {
                $file = []; foreach (['name', 'tmp_name', 'error'] as $key) { $file[$key] = $files[$key][$name] ?? null; }
                $input[$name] = self::stage($file, $field, self::scope($resource, $record, $name));
            } catch (\FloCMS\Core\HttpException|\FloCMS\Uploader\Exceptions\UploadException) { $input[$name] = '@invalid'; }
        }
        return $input;
    }
    public static function forget(string $value): void
    {
        $token = substr($value, 8); $entries = self::entries(); $entry = $entries[$token] ?? null;
        if (is_array($entry)) { self::storage()->delete($entry['path']); }
        unset($entries[$token]); Session::set(self::KEY, $entries);
    }
    /** Run regularly from an application scheduler; also runs on each upload. */
    public static function prune(): int
    {
        $count = 0;
        foreach (new \GlobIterator(self::root() . '/pending/*', \FilesystemIterator::SKIP_DOTS) as $file) {
            if ($count >= 256) { break; }
            if ($file->isFile() && !$file->isLink() && $file->getMTime() <= time() - 1800) { if (unlink($file->getPathname())) { ++$count; } }
        }
        return $count;
    }
    public static function managed(string $value, Resource $resource, FileUpload $field): bool
    {
        return preg_match('~^' . preg_quote($resource::getSlug() . '/' . $field->getName() . '/', '~') . '[a-f0-9]{40}\.(pdf|txt|csv|jpg|jpeg|png|gif|webp)$~D', $value) === 1;
    }
    public static function url(Resource $resource, ?object $record): \Closure
    {
        return static function (FileUpload $field, string $value) use ($resource, $record): string {
            if (str_starts_with($value, '@upload:')) { return Admin::url($resource::getSlug() . '/file/pending/' . $field->getName(), ['token' => $value]); }
            if ($record !== null && self::managed($value, $resource, $field) && $value === (string) ($record->{$field->getName()} ?? '')) {
                return $field->isPublic() ? self::publicUrl($value) : Admin::url($resource::getSlug() . '/file/' . $record->{$resource::getPrimaryKey()} . '/' . $field->getName());
            }
            return '';
        };
    }
}
