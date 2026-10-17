<?php
declare(strict_types=1);

namespace FloCMS\Admin\Media;

use FloCMS\Admin\Support\Str;
use FloCMS\Admin\Translation\Translations;

/**
 * One image or video of the media library, as the website and the panel use it:
 *
 *     $cover = media($home->cover_id);
 *     $cover?->url();              web version (at most 1920 px)
 *     $cover?->url('thumb');       400 px version (the web one for videos and imported files)
 *     $cover?->alt();              alt text in the current language
 *     echo $cover?->img(['class' => 'cover']);
 *
 * An image with a focal point (0.18) keeps that spot in view when CSS crops it
 * (object-fit: cover): img() adds object-position, focal() gives "62% 40%".
 */
final class MediaItem
{
    public const THUMB = 400;

    public function __construct(
        public readonly int $id,
        public readonly string $kind,
        public readonly string $path,
        public readonly ?string $thumbPath,
        public readonly string $mime,
        public readonly int $bytes,
        public readonly ?int $width,
        public readonly ?int $height,
        public readonly string $fileName,
        public readonly string $title,
        private readonly mixed $alt,
        public readonly ?int $uploadedBy,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
        private readonly string $baseUrl,
        public readonly ?int $folderId = null,
        public readonly ?int $focalX = null,
        public readonly ?int $focalY = null,
        public readonly ?string $hash = null
    ) {
    }

    public static function fromRow(object $row, string $baseUrl): self
    {
        $int = static fn (mixed $value): ?int => $value === null || $value === '' ? null : (int) $value;
        return new self((int) $row->id, (string) $row->kind, (string) $row->path, ($row->thumb_path ?? null) !== null && $row->thumb_path !== '' ? (string) $row->thumb_path : null,
            (string) $row->mime, (int) ($row->bytes ?? 0), $int($row->width ?? null), $int($row->height ?? null), (string) ($row->file_name ?? ''),
            (string) ($row->title ?? ''), $row->alt ?? null, $int($row->uploaded_by ?? null), $row->created_at ?? null, $row->updated_at ?? null, $baseUrl,
            $int($row->folder_id ?? null), $int($row->focal_x ?? null), $int($row->focal_y ?? null), ($row->hash ?? null) !== null && $row->hash !== '' ? (string) $row->hash : null);
    }

    public function isImage(): bool { return $this->kind === 'image'; }
    public function isVideo(): bool { return $this->kind === 'video'; }

    /** Public URL of a version: 'web' (default) or 'thumb'. */
    public function url(string $version = 'web'): string
    {
        $path = $version === 'thumb' && $this->thumbPath !== null ? $this->thumbPath : $this->path;
        return self::urlFor($this->baseUrl, $path);
    }

    public static function urlFor(string $baseUrl, string $path): string
    {
        return rtrim($baseUrl, '/') . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }

    /** Alt text in $lang (default: the current language), falling back to the default language. */
    public function alt(?string $lang = null): string
    {
        return Translations::get($this->alt, $lang);
    }

    /** @return array<string, string> Alt text per language. */
    public function altValues(): array
    {
        return Translations::store()->decode($this->alt, \FloCMS\Admin\Translation\Languages::default());
    }

    /** Whether there is alt text in any language. */
    public function hasAlt(): bool
    {
        return array_filter($this->altValues(), static fn (string $text): bool => trim($text) !== '') !== [];
    }

    /** The focal point as CSS ("62% 40%"), or null when none is set. */
    public function focal(): ?string
    {
        return $this->focalX === null || $this->focalY === null ? null : $this->focalX . '% ' . $this->focalY . '%';
    }

    /** A name for people: the title, else the uploaded file's name. */
    public function label(): string
    {
        return $this->title !== '' ? $this->title : ($this->fileName !== '' ? $this->fileName : '#' . $this->id);
    }

    /** @return array{0: ?int, 1: ?int} Size of the thumb version (images are scaled to fit 400 × 400). */
    public function thumbSize(): array
    {
        if ($this->thumbPath === null || $this->width === null || $this->height === null || $this->width < 1 || $this->height < 1) { return [$this->width, $this->height]; }
        $scale = min(1, self::THUMB / max($this->width, $this->height));
        return [max(1, (int) round($this->width * $scale)), max(1, (int) round($this->height * $scale))];
    }

    /**
     * An <img> for an image (empty for a video), with alt in the current
     * language, width/height, lazy loading and a srcset of both versions, and
     * object-position for a focal point. Attributes you pass win (class, sizes,
     * loading, alt, style => null for a strict Content-Security-Policy…); values are escaped.
     *
     * @param array<string, string|int|bool|null> $attributes
     */
    public function img(array $attributes = []): string
    {
        if (!$this->isImage()) { return ''; }
        $base = ['src' => $this->url(), 'alt' => $this->alt(), 'width' => $this->width, 'height' => $this->height, 'loading' => 'lazy', 'decoding' => 'async'];
        [$thumbWidth] = $this->thumbSize();
        if ($this->thumbPath !== null && $this->width !== null && $thumbWidth !== null && $thumbWidth < $this->width) {
            $base['srcset'] = $this->url('thumb') . ' ' . $thumbWidth . 'w, ' . $this->url() . ' ' . $this->width . 'w';
            $base['sizes'] = '(max-width: ' . $this->width . 'px) 100vw, ' . $this->width . 'px';
        }
        if (($focal = $this->focal()) !== null) { $base['style'] = 'object-position: ' . $focal; }
        return '<img' . self::attributes(array_replace($base, $attributes)) . '>';
    }

    /**
     * A <video> for a video (empty for an image), with controls and metadata preload.
     *
     * @param array<string, string|int|bool|null> $attributes
     */
    public function video(array $attributes = []): string
    {
        if (!$this->isVideo()) { return ''; }
        $base = ['src' => $this->url(), 'controls' => true, 'preload' => 'metadata', 'playsinline' => true, 'width' => $this->width, 'height' => $this->height, 'aria-label' => $this->alt() !== '' ? $this->alt() : null];
        return '<video' . self::attributes(array_replace($base, $attributes)) . '></video>';
    }

    /** img() or video(), whichever fits. */
    public function html(array $attributes = []): string
    {
        return $this->isImage() ? $this->img($attributes) : $this->video($attributes);
    }

    /** @return array<string, mixed> What the picker and the Media page's scripts receive. */
    public function toArray(): array
    {
        [$tw, $th] = $this->thumbSize();
        return ['id' => $this->id, 'kind' => $this->kind, 'url' => $this->url(), 'thumb' => $this->url('thumb'), 'title' => $this->title, 'label' => $this->label(),
            'alt' => $this->alt(), 'alts' => (object) $this->altValues(), 'name' => $this->fileName, 'mime' => $this->mime, 'bytes' => $this->bytes, 'width' => $this->width, 'height' => $this->height,
            'thumbWidth' => $tw, 'thumbHeight' => $th, 'folder' => $this->folderId, 'focalX' => $this->focalX, 'focalY' => $this->focalY, 'hasAlt' => $this->hasAlt(),
            'created' => $this->createdAt, 'uploadedBy' => $this->uploadedBy];
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
