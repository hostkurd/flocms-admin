<?php
declare(strict_types=1);

namespace FloCMS\Admin\Ui;

use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Str;

/**
 * A file input people can also drop files on (0.18), with a preview of the
 * chosen or current file, progress and type and size checks in the browser.
 * The panel uses it for every upload; your own admin pages can too:
 *
 *     echo FileDrop::make('brochure')->accept(['application/pdf'])->maxKb(5120);
 *
 * It is a real <input type="file"> inside a <label>, so the form posts the file
 * as usual. Without JavaScript the browser's own input shows, styled. Our own
 * code (resources/js/filedrop.ts), no third-party package.
 */
final class FileDrop
{
    /** Short names people know, per MIME type. */
    public const TYPE_NAMES = [
        'image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/gif' => 'GIF', 'image/webp' => 'WebP', 'image/svg+xml' => 'SVG',
        'application/pdf' => 'PDF', 'text/plain' => 'TXT', 'text/csv' => 'CSV', 'video/mp4' => 'MP4', 'video/webm' => 'WebM',
        'application/zip' => 'ZIP',
    ];

    private string $id;
    /** @var list<string> */
    private array $accept = [];
    private ?int $maxBytes = null;
    private bool $multiple = false;
    private bool $large = false;
    private bool $disabled = false;
    private ?string $title = null;
    private ?string $hint = null;
    /** @var array<string, string|int|bool|null> */
    private array $inputAttributes = [];
    /** @var array{url: string, name: string, meta: string, image: bool, extension: ?string}|null */
    private ?array $current = null;
    private ?string $removeName = null;
    private bool $removeChecked = false;

    private function __construct(private readonly string $name)
    {
        $this->id = 'fa-drop-' . substr(hash('sha256', $name), 0, 10);
    }

    /** $name is the input's name ("brochure", "files[]", "_uploads[cover]"). */
    public static function make(string $name): self
    {
        return new self($name);
    }

    public function id(string $id): self { $this->id = $id; return $this; }

    /** @param list<string> $mimeTypes What the input accepts (also checked in the browser before sending). */
    public function accept(array $mimeTypes): self { $this->accept = array_values(array_filter($mimeTypes, 'is_string')); return $this; }

    /** The size limit in KiB, checked in the browser before sending (the server checks again). */
    public function maxKb(int $kib): self { $this->maxBytes = max(1, $kib) * 1024; return $this; }

    public function maxBytes(int $bytes): self { $this->maxBytes = max(1, $bytes); return $this; }

    public function multiple(bool $multiple = true): self { $this->multiple = $multiple; return $this; }

    /** The big drop area (a Media page, an import screen) instead of the one-line form control. */
    public function large(bool $large = true): self { $this->large = $large; return $this; }

    public function disabled(bool $disabled = true): self { $this->disabled = $disabled; return $this; }

    /** Replace the "Drop a file or browse" line; "browse" is not linked then. */
    public function title(string $title): self { $this->title = $title; return $this; }

    /** Replace the line under it (by default the types and the size limit). */
    public function hint(string $hint): self { $this->hint = $hint; return $this; }

    /** @param array<string, string|int|bool|null> $attributes Extra attributes of the <input> (aria-describedby, data-…). */
    public function inputAttributes(array $attributes): self { $this->inputAttributes = $attributes + $this->inputAttributes; return $this; }

    /**
     * The file already saved: shown instead of the drop area until it is replaced or removed.
     * $extension labels a file without a preview ("PDF"); by default it comes from $name.
     */
    public function current(string $url, string $name, string $meta = '', bool $image = false, ?string $extension = null): self
    {
        $this->current = ['url' => $url, 'name' => $name, 'meta' => $meta, 'image' => $image, 'extension' => $extension];
        return $this;
    }

    /** A checkbox posted as $name=1 when people remove the current file (the Remove button ticks it). */
    public function removable(string $name, bool $checked = false): self
    {
        $this->removeName = $name;
        $this->removeChecked = $checked;
        return $this;
    }

    public function __toString(): string
    {
        return $this->render();
    }

    public function render(): string
    {
        $image = $this->accept !== [] && array_filter($this->accept, static fn (string $t): bool => !str_starts_with($t, 'image/')) === [];
        $strings = [
            'drop' => AdminLang::get('filedrop.drop_here'),
            'tooBig' => AdminLang::get('filedrop.too_big'),
            'wrongType' => AdminLang::get('filedrop.wrong_type'),
            'uploading' => AdminLang::get('filedrop.uploading'),
            'ready' => AdminLang::get('filedrop.ready'),
            'removed' => AdminLang::get('filedrop.removed'),
            'files' => AdminLang::get('filedrop.files'),
            'replace' => AdminLang::get('filedrop.replace'),
            'undo' => AdminLang::get('filedrop.undo'),
            'cancel' => AdminLang::get('filedrop.cancel'),
            'remove' => AdminLang::get('filedrop.remove_named'),
        ];
        $attributes = $this->inputAttributes + [
            'type' => 'file',
            'id' => $this->id,
            'name' => $this->name,
            'class' => 'fa-filedrop-input',
            'accept' => $this->accept === [] ? null : implode(',', $this->accept),
            'multiple' => $this->multiple,
            'disabled' => $this->disabled,
        ];
        // ":browse" in the line becomes the underlined word, after the rest is escaped.
        $title = $this->title !== null ? Str::e($this->title)
            : str_replace(':browse', '<u>' . Str::e(AdminLang::get('filedrop.browse')) . '</u>', Str::e(AdminLang::get($this->multiple ? 'filedrop.title_many' : 'filedrop.title')));
        $hint = $this->hint ?? $this->defaultHint();

        $current = $this->current;
        $html = '<div class="fa-filedrop' . ($this->large ? ' is-large' : '') . ($current !== null && !$this->removeChecked ? ' has-file' : '') . '" data-admin-filedrop'
            . ($this->maxBytes !== null ? ' data-max-bytes="' . $this->maxBytes . '"' : '')
            . ' data-accept="' . Str::e(implode(',', $this->accept)) . '" data-image="' . ($image ? 'true' : 'false') . '"'
            . ' data-strings="' . Str::e(json_encode($strings, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)) . '">';

        if ($current !== null) {
            $html .= '<div class="fa-filedrop-file" data-filedrop-current' . ($this->removeChecked ? ' hidden' : '') . '>'
                . self::thumb($current['image'] ? $current['url'] : null, $current['extension'] ?? $current['name'])
                . '<div class="fa-filedrop-info"><a class="fa-filedrop-name" href="' . Str::e($current['url']) . '" data-admin-file-link target="_blank" rel="noopener">' . Str::e($current['name']) . '</a>'
                . ($current['meta'] !== '' ? '<span class="fa-filedrop-meta">' . Str::e($current['meta']) . '</span>' : '') . '</div>'
                . (!$this->disabled ? '<label class="fa-btn fa-btn-sm" for="' . Str::e($this->id) . '">' . Str::e(AdminLang::get('filedrop.replace')) . '</label>' : '')
                . ($this->removeName !== null && !$this->disabled ? '<button type="button" class="fa-icon-btn" data-filedrop-remove aria-label="' . Str::e(AdminLang::get('filedrop.remove_named', ['name' => $current['name']])) . '" hidden>' . self::icon('trash') . '</button>' : '')
                . '</div>';
        }

        $html .= '<label class="fa-filedrop-zone" for="' . Str::e($this->id) . '">'
            . '<span class="fa-filedrop-icon" aria-hidden="true">' . self::icon($this->large || $this->multiple ? 'upload' : ($image ? 'image' : 'file')) . '</span>'
            . '<span class="fa-filedrop-text"><span class="fa-filedrop-title">' . $title . '</span>'
            . ($hint !== '' ? '<span class="fa-filedrop-hint">' . Str::e($hint) . '</span>' : '') . '</span>'
            . '<input' . self::attributes($attributes) . '>'
            . '</label>';

        // Filled in by the script: the chosen file(s), with progress.
        $html .= '<div class="fa-filedrop-chosen" data-filedrop-chosen hidden></div>';
        if ($this->removeName !== null && ($current !== null || $this->removeChecked) && !$this->disabled) {
            $html .= '<label class="fa-filedrop-remove"><input type="checkbox" name="' . Str::e($this->removeName) . '" value="1" data-filedrop-remove-box' . ($this->removeChecked ? ' checked' : '') . '> '
                . Str::e(AdminLang::get('uploads.remove')) . '</label>';
        }
        return $html . '<p class="fa-filedrop-error" role="alert" data-filedrop-error hidden></p></div>';
    }

    /** "JPEG, PNG, WebP · up to 4 MB" from the accepted types and the limit. */
    private function defaultHint(): string
    {
        $names = array_values(array_unique(array_map(static fn (string $type): string => self::TYPE_NAMES[$type] ?? strtoupper((string) preg_replace('~^.*/~', '', $type)), $this->accept)));
        $types = implode(', ', $names);
        if ($this->maxBytes === null) { return $types; }
        $size = self::size($this->maxBytes);
        return $types === '' ? AdminLang::get('filedrop.up_to', ['size' => $size]) : AdminLang::get('filedrop.hint', ['types' => $types, 'size' => $size]);
    }

    /** "1.5 MB", kept in one left-to-right piece (LRI … PDI) so right-to-left text doesn't show "MB 1.5". */
    public static function size(int $bytes): string
    {
        $text = match (true) {
            $bytes >= 1048576 => rtrim(rtrim(number_format($bytes / 1048576, 1, '.', ''), '0'), '.') . "\u{00A0}MB",
            $bytes >= 1024 => max(1, (int) round($bytes / 1024)) . "\u{00A0}KB",
            default => $bytes . "\u{00A0}B",
        };
        return "\u{2066}" . $text . "\u{2069}";
    }

    /** A preview of an image, else a badge with the file type ($name may be the type itself: "PDF"). */
    private static function thumb(?string $imageUrl, string $name): string
    {
        if ($imageUrl !== null) {
            return '<span class="fa-filedrop-thumb"><img src="' . Str::e($imageUrl) . '" alt=""></span>';
        }
        $extension = strtoupper(str_contains($name, '.') ? (string) pathinfo($name, PATHINFO_EXTENSION) : $name);
        return '<span class="fa-filedrop-thumb is-file' . ($extension === 'PDF' ? ' is-pdf' : '') . '" aria-hidden="true">' . Str::e(mb_substr($extension !== '' ? $extension : 'FILE', 0, 4)) . '</span>';
    }

    /** Stroke icons drawn in the text colour. */
    public static function icon(string $name): string
    {
        $paths = [
            'upload' => '<path d="M12 15V4M7 9l5-5 5 5"/><path d="M4 15v3a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-3"/>',
            'image' => '<rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="2"/><path d="m21 15-5-5L5 21"/>',
            'file' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/>',
            'trash' => '<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>',
            'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        ];
        return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? $paths['file']) . '</svg>';
    }

    /** @param array<string, string|int|bool|null> $attributes */
    private static function attributes(array $attributes): string
    {
        $html = '';
        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false || preg_match('/^[a-zA-Z][a-zA-Z0-9_:.-]*$/D', (string) $name) !== 1 || str_starts_with(strtolower((string) $name), 'on')) { continue; }
            $html .= $value === true ? ' ' . $name : ' ' . $name . '="' . Str::e((string) $value) . '"';
        }
        return $html;
    }
}
