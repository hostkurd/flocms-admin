<?php
declare(strict_types=1);

namespace FloCMS\Admin\Settings;

use Closure;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Form\Fields\FileUpload;
use FloCMS\Admin\Form\Fields\ImageUpload;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Languages;
use FloCMS\Uploader\Storage\LocalStorage;
use FloCMS\Uploader\Uploader;
use Throwable;

/**
 * Files uploaded on settings pages. Unlike resource uploads they are public
 * (a logo is shown on the website and on the login page): flocms-uploader
 * stores them under the settings uploads folder (default public/uploads/settings)
 * as <page>/<field>/<random>.<ext>. A translatable upload keeps one file per
 * language: {"en":"site/logo/….png","ku":"site/logo/….png"}.
 *
 * Files arrive with the page's form (multipart POST): they are checked first,
 * and stored only when the whole page is valid. Replaced files are deleted
 * after the values are saved; new files are deleted again if saving fails.
 *
 * @internal
 */
final class SettingsUploads
{
    /** Marks a value that will be a newly uploaded file (for validation). */
    public const PENDING = '@upload:new';

    /** @var list<string> paths written during this save */
    private array $created = [];
    /** @var list<string> paths to delete once the save is committed */
    private array $replaced = [];

    public static function storage(): LocalStorage
    {
        return new LocalStorage(['root' => Admin::config()->settingsUploads()['root'], 'visibility' => 'public']);
    }

    /** Whether a stored value is a file this package wrote for the field. */
    public static function managed(string $path, SettingsPage $page, FileUpload $field): bool
    {
        return preg_match('~^' . preg_quote($page->getName() . '/' . $field->getName() . '/', '~') . '[a-f0-9]{40}\.(pdf|txt|csv|jpg|jpeg|png|gif|webp)$~D', $path) === 1;
    }

    /**
     * The control for one file (one language of a translatable upload).
     *
     * @param string $name input name: "logo" or "logo[ku]"
     * @param string|null $fallback the default language's file shown when this language has none
     * @param array<string, string> $attributes
     */
    public static function control(FileUpload $field, string $id, string $name, string $path, ?string $fallback, array $attributes = []): string
    {
        $isImage = $field instanceof ImageUpload;
        $present = $path !== '' && $path !== self::PENDING;
        $file = '<input type="file" id="' . Str::e($id) . '" name="_uploads' . self::bracket($name) . '" class="fa-input"'
            . ' accept="' . Str::e(implode(',', $field->getAcceptedFileTypes())) . '"' . ($field->isDisabled() ? ' disabled' : '');
        foreach ($attributes as $key => $value) {
            if (in_array($key, ['aria-describedby', 'aria-invalid', 'lang', 'dir'], true)) { $file .= ' ' . $key . '="' . Str::e($value) . '"'; }
        }
        $file .= '>';

        $html = '<div class="fa-upload fa-settings-upload" data-settings-upload>';
        if ($present) {
            $url = Settings::url($path);
            $html .= $isImage
                ? '<img class="fa-upload-preview" src="' . Str::e($url) . '" alt="' . Str::e($field->getLabel()) . '">'
                : '<a class="fa-btn fa-btn-ghost" href="' . Str::e($url) . '" target="_blank" rel="noopener">' . Str::e(AdminLang::get('uploads.current')) . '</a>';
        } elseif ($fallback !== null && $fallback !== '') {
            $html .= $isImage ? '<img class="fa-upload-preview is-fallback" src="' . Str::e(Settings::url($fallback)) . '" alt="">' : '';
            $html .= '<p class="fa-help fa-upload-fallback">' . Str::e(AdminLang::get('settings.uses_default_file', ['language' => Languages::name(Languages::default())])) . '</p>';
        }
        $html .= $file . '<p class="fa-help">' . Str::e(AdminLang::get('uploads.limit', ['size' => (string) intdiv($field->getMaxBytes(), 1024)])) . '</p>';
        if ($present && !$field->isDisabled()) {
            $html .= '<label class="fa-upload-remove"><input type="checkbox" name="_remove_upload' . self::bracket($name) . '" value="1"> ' . Str::e(AdminLang::get('uploads.remove')) . '</label>';
        }

        return $html . '</div>';
    }

    /**
     * Read the page's files: the new state of every upload field (stored path,
     * '' when removed, PENDING for a new file), the files to store and errors.
     *
     * @param array<string, FileUpload> $fields upload fields of the page by name
     * @param array<string, mixed> $stored current stored values by field name
     * @param Closure(string): bool|null $isUploaded defaults to is_uploaded_file
     * @return array{state: array<string, string|array<string, string>>, files: array<string, array>, errors: array<string, string>}
     */
    public static function receive(array $fields, array $stored, array $input, array $files, ?Closure $isUploaded = null): array
    {
        $isUploaded ??= static fn (string $tmp): bool => is_uploaded_file($tmp);
        $result = ['state' => [], 'files' => [], 'errors' => []];
        foreach ($fields as $name => $field) {
            $languages = $field->isTranslatable() ? Languages::all() : [null];
            $current = $stored[$name] ?? null;
            $values = [];
            foreach ($languages as $lang) {
                $key = $lang === null ? $name : $name . '.' . $lang;
                $old = $lang === null ? (is_string($current) ? $current : '') : (is_array($current) && is_string($current[$lang] ?? null) ? $current[$lang] : '');
                $value = self::pick($input['_remove_upload'] ?? null, $name, $lang) === '1' ? '' : $old;
                $file = [];
                foreach (['name', 'tmp_name', 'error', 'size'] as $part) { $file[$part] = self::pick($files[$part] ?? null, $name, $lang); }
                if ($file['error'] !== null && (int) $file['error'] !== UPLOAD_ERR_NO_FILE) {
                    try {
                        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_string($file['tmp_name']) || !is_string($file['name']) || !$isUploaded($file['tmp_name'])) {
                            throw new \FloCMS\Uploader\Exceptions\ValidationException('The file did not arrive.');
                        }
                        $field->validateFile($file);
                        $result['files'][$key] = $file;
                        $value = self::PENDING;
                    } catch (Throwable) {
                        $result['errors'][$lang === null || $lang === Languages::default() ? $name : $key] = AdminLang::get('uploads.invalid');
                    }
                }
                if ($lang === null) { $values = $value; } else { $values[$lang] = $value; }
            }
            $result['state'][$name] = $values;
        }
        return $result;
    }

    /**
     * Store the pending files and put their paths in the state.
     *
     * @param array<string, FileUpload> $fields
     * @param array<string, string|array<string, string>> $state
     * @param array<string, array> $files from receive()
     * @param array<string, mixed> $stored current stored values by field name
     * @return array<string, string|array<string, string>>
     */
    public function store(SettingsPage $page, array $fields, array $state, array $files, array $stored): array
    {
        $config = Admin::config()->settingsUploads();
        if (!is_dir($config['root']) && !@mkdir($config['root'], 0755, true) && !is_dir($config['root'])) {
            throw new \RuntimeException('Cannot create the settings uploads folder ' . $config['root'] . '.');
        }
        foreach ($fields as $name => $field) {
            $languages = $field->isTranslatable() ? Languages::all() : [null];
            foreach ($languages as $lang) {
                $key = $lang === null ? $name : $name . '.' . $lang;
                $value = $lang === null ? $state[$name] : ($state[$name][$lang] ?? '');
                if ($value === self::PENDING && isset($files[$key])) {
                    $metadata = $field->validateFile($files[$key]);
                    $result = Uploader::make(['default_disk' => 'settings', 'disks' => ['settings' => ['driver' => 'local', 'root' => $config['root'], 'url' => $config['url'], 'visibility' => 'public']]])
                        ->onDisk('settings')->directory($page->getName() . '/' . $name)
                        ->allowMimeTypes($field->getAcceptedFileTypes())->allowExtensions(FileUpload::TYPES[$metadata['mime']] ?? [$metadata['extension']])
                        ->maxBytes($field->getMaxBytes())->filename(bin2hex(random_bytes(20)) . '.' . $metadata['extension'])
                        ->upload($files[$key]);
                    $value = (string) $result->path();
                    $this->created[] = $value;
                }
                $old = $lang === null ? ($stored[$name] ?? '') : ($stored[$name][$lang] ?? '');
                if (is_string($old) && $old !== '' && $old !== $value && self::managed($old, $page, $field)) {
                    $this->replaced[] = $old;
                }
                if ($lang === null) { $state[$name] = $value; } else { $state[$name][$lang] = $value; }
            }
        }
        return $state;
    }

    /** The save is committed: delete replaced and removed files. */
    public function commit(): void
    {
        foreach ($this->replaced as $path) {
            try { self::storage()->delete($path); } catch (Throwable $e) { error_log('Settings upload cleanup failed: ' . $e->getMessage()); }
        }
        $this->created = $this->replaced = [];
    }

    /** The save failed: delete the files written for it. */
    public function rollback(): void
    {
        foreach ($this->created as $path) {
            try { self::storage()->delete($path); } catch (Throwable) { }
        }
        $this->created = $this->replaced = [];
    }

    private static function pick(mixed $tree, string $name, ?string $lang): mixed
    {
        if (!is_array($tree)) { return null; }
        $value = $tree[$name] ?? null;
        if ($lang === null) { return is_array($value) ? null : $value; }
        return is_array($value) ? ($value[$lang] ?? null) : null;
    }

    /** "logo[ku]" → "[logo][ku]" */
    private static function bracket(string $name): string
    {
        return preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\[([a-z0-9-]+)\]$/D', $name, $m) === 1 ? '[' . $m[1] . '][' . $m[2] . ']' : '[' . $name . ']';
    }
}
