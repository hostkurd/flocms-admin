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
        private readonly string $baseUrl
    ) {
    }

    public static function fromRow(object $row, string $baseUrl): self
    {
        $int = static fn (mixed $value): ?int => $value === null || $value === '' ? null : (int) $value;
        return new self((int) $row->id, (string) $row->kind, (string) $row->path, ($row->thumb_path ?? null) !== null && $row->thumb_path !== '' ? (string) $row->thumb_path : null,
            (string) $row->mime, (int) ($row->bytes ?? 0), $int($row->width ?? null), $int($row->height ?? null), (string) ($row->file_name ?? ''),
            (string) ($row->title ?? ''), $row->alt ?? null, $int($row->uploaded_by ?? null), $row->created_at ?? null, $row->updated_at ?? null, $baseUrl);
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
     * language, width/height, lazy loading and a srcset of both versions.
     * Attributes you pass win (class, sizes, loading, alt…); values are escaped.
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
            'thumbWidth' => $tw, 'thumbHeight' => $th];
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
