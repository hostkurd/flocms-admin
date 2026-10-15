<?php
declare(strict_types=1);
namespace FloCMS\Admin\Form\Fields;

use FloCMS\Admin\{Form\Field, Support\AdminLang, Support\Str, Uploads\Uploads};
use FloCMS\Uploader\Validation\FileValidator;

/** One privately stored file; the column contains a managed relative path. */
class FileUpload extends Field
{
    public const TYPES = ['application/pdf' => ['pdf'], 'text/plain' => ['txt'], 'text/csv' => ['csv'], 'image/jpeg' => ['jpg', 'jpeg'], 'image/png' => ['png'], 'image/gif' => ['gif'], 'image/webp' => ['webp']];
    protected array $mimeTypes = ['application/pdf', 'text/plain'];
    protected int $maxBytes = 5242880;
    protected array $dimensions = [];
    protected function setUp(): void { $this->nullable = true; }
    public function acceptedFileTypes(array $types): static
    {
        if ($types === [] || array_diff($types, array_keys(self::TYPES)) !== []) { throw new \InvalidArgumentException('Use supported explicit MIME types from FileUpload::TYPES.'); }
        if ($this instanceof ImageUpload && array_filter($types, static fn ($t): bool => !str_starts_with($t, 'image/'))) { throw new \InvalidArgumentException('ImageUpload accepts raster image types only.'); }
        $this->mimeTypes = array_values(array_unique($types)); return $this;
    }
    /** Size in KiB, bounded to 16 MiB per field. */
    public function maxSize(int $kib): static
    {
        if ($kib < 1 || $kib > 16384) { throw new \InvalidArgumentException('Upload size must be 1–16384 KiB.'); }
        $this->maxBytes = $kib * 1024; return $this;
    }
    public function validateFile(array $file): array
    {
        $detectedTypes = $this->mimeTypes;
        if (in_array('text/csv', $detectedTypes, true)) { $detectedTypes[] = 'text/plain'; }
        $metadata = (new FileValidator())->validate($file, ['max_bytes' => $this->maxBytes, 'mime_types' => $detectedTypes, 'image' => $this instanceof ImageUpload, 'image_dimensions' => $this->dimensions]);
        // finfo commonly identifies CSV as plain text. Permit that only for explicitly enabled CSV.
        if ($metadata['mime'] === 'text/plain' && $metadata['extension'] === 'csv' && in_array('text/csv', $this->mimeTypes, true)) { $metadata['mime'] = 'text/csv'; }
        // Pair the detected MIME with its own extension, not the union of all extensions.
        if (!in_array($metadata['mime'], $this->mimeTypes, true) || !in_array($metadata['extension'], self::TYPES[$metadata['mime']] ?? [], true) || $metadata['size'] < 1) { throw new \FloCMS\Uploader\Exceptions\ValidationException('Invalid file extension or empty file.'); }
        return $metadata;
    }
    public function typeError(string $value): ?array { return $value === '@invalid' ? ['uploads.invalid', []] : null; }
    public function renderControl(string $id, string $name, string $value, array $attributes = []): string
    {
        $url = $attributes['data-upload-url'] ?? ''; unset($attributes['data-upload-url']);
        $pending = str_starts_with($value, '@upload:');
        $present = $value !== '' && !in_array($value, ['@remove', '@invalid'], true);
        $html = '<div class="fa-upload" data-admin-upload data-upload-error="' . Str::e(AdminLang::get('uploads.invalid')) . '" data-upload-ready="' . Str::e(AdminLang::get('uploads.ready')) . '" data-upload-remove-text="' . Str::e(AdminLang::get('uploads.remove')) . '" data-upload-loading="' . Str::e(AdminLang::get('uploads.loading')) . '" data-upload-image="' . ($this instanceof ImageUpload ? 'true' : 'false') . '"><input' . self::attributes($this->baseAttributes($id, '_uploads[' . $name . ']', $attributes) + ['type' => 'file', 'class' => 'fa-input', 'accept' => implode(',', $this->mimeTypes), 'data-upload-field' => $name]) . '>';
        $html .= '<input type="hidden" name="_upload_control[' . Str::e($name) . ']" value="1">';
        $html .= '<input type="hidden" data-upload-value name="' . Str::e($name) . '" value="' . Str::e($value === '@invalid' ? '' : $value) . '">';
        $html .= '<p class="fa-help">' . Str::e(AdminLang::get('uploads.limit', ['size' => (string) ($this->maxBytes / 1024)])) . '</p>';
        if ($present && $url !== '') {
            if ($this instanceof ImageUpload) { $html .= '<img class="fa-upload-preview" src="' . Str::e($url) . '" alt="' . Str::e($this->getLabel()) . '">'; }
            $html .= '<a class="fa-btn fa-btn-ghost" href="' . Str::e($url) . '" data-admin-file-link target="_blank" rel="noopener">' . Str::e(AdminLang::get($pending ? 'uploads.pending' : 'uploads.current')) . '</a>';
        }
        if (!$this->isDisabled()) {
            $html .= '<label class="fa-upload-remove"' . (!$present && $value !== '@remove' ? ' hidden' : '') . '><input type="checkbox" name="_remove_upload[' . Str::e($name) . ']" value="1" data-upload-remove' . ($value === '@remove' ? ' checked' : '') . '> ' . Str::e(AdminLang::get('uploads.remove')) . '</label>';
        }
        return $html . '<p class="fa-help" role="status" data-upload-status></p></div>';
    }
}
