<?php
declare(strict_types=1);

namespace FloCMS\Admin\Uploads;

use FloCMS\Admin\{Admin, Form\Fields\RichEditor};
use FloCMS\Core\{Csrf, HttpException, Session};
use FloCMS\Uploader\Uploader;
use FloCMS\Uploader\Chunked\ChunkedUploader;
use FloCMS\Uploader\Exceptions\ValidationException;

/**
 * Images and videos inserted into rich text. They are public (the website shows
 * them), stored under the configured media folder as
 * <resource>/<field>/<yyyy>/<mm>/<random>.<ext>, and checked by content, not by
 * name: only JPEG, PNG, GIF and WebP images and MP4 and WebM videos are kept.
 * All checks and storage are flocms-uploader's (Uploader::image(), ::video()).
 *
 * Videos arrive in parts of at most 2 MiB (ChunkedUploader), so they are not
 * limited by PHP's upload_max_filesize. A part upload belongs to the user and
 * session that started it and expires after a day without activity.
 *
 * @internal
 */
final class EditorMedia
{
    public const CHUNK_BYTES = 1048576;
    public const MAX_CHUNK_BYTES = 2097152;

    /** Largest side of the stored web version of an uploaded image. */
    public const IMAGE_SIZE = 1920;

    /**
     * An image through flocms-uploader's image uploader: it checks the content
     * type and dimensions and stores one web version (at most 1920 px, the
     * original is not kept), so a 12-megapixel phone photo does not reach pages.
     *
     * @return array{url: string, width: ?int, height: ?int}
     */
    public static function image(array $file, RichEditor $field, string $slug): array
    {
        foreach (['tmp_name', 'name', 'error'] as $key) { if (!isset($file[$key]) || !is_scalar($file[$key])) { throw new ValidationException('The image did not arrive.'); } }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) { throw new ValidationException('The image did not arrive.'); }
        $result = Uploader::image(self::config())->onDisk('editor')->directory($slug . '/' . $field->getName())->useDatePath()
            ->maxBytes($field->getMaxImageBytes())->imageDimensions(['max_width' => 8192, 'max_height' => 8192])
            ->keepOriginal(false)->versions(['web' => ['resize' => [self::IMAGE_SIZE, self::IMAGE_SIZE]]])
            ->upload($file);
        $web = $result->version('web') ?? throw new \RuntimeException('The image was not stored.');
        return ['url' => self::path((string) $web['path']), 'width' => $web['width'] ?? null, 'height' => $web['height'] ?? null];
    }

    /** @return array{upload_id: string, chunk_size: int, received: int} */
    public static function start(RichEditor $field, string $slug, string $filename, int $bytes): array
    {
        // The uploader rejects a wrong extension or size here, before any data is sent.
        $status = self::chunks($slug, $field)->start($filename, $bytes, self::owner());
        return ['upload_id' => $status['upload_id'], 'chunk_size' => self::CHUNK_BYTES, 'received' => $status['received_bytes']];
    }

    /** @return array{received: int, complete: bool} */
    public static function append(RichEditor $field, string $slug, string $id, int $offset, mixed $chunk): array
    {
        $status = self::chunks($slug, $field)->append($id, $offset, $chunk, self::owner());
        return ['received' => $status['received_bytes'], 'complete' => $status['complete']];
    }

    /** @return array{url: string} */
    public static function complete(RichEditor $field, string $slug, string $id): array
    {
        $result = self::chunks($slug, $field)->complete($id, self::owner());
        return ['url' => self::path((string) $result->path())];
    }

    public static function abort(RichEditor $field, string $slug, string $id): void
    {
        self::chunks($slug, $field)->abort($id, self::owner());
    }

    /** Folder for unfinished video parts (private, outside the web root). */
    public static function chunkDirectory(): string
    {
        return Admin::config()->root() . '/storage/admin-uploads/chunks';
    }

    private static function chunks(string $slug, RichEditor $field): ChunkedUploader
    {
        $directory = self::chunkDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Cannot create the video upload folder.'); }
        $video = Uploader::video(self::config())->onDisk('editor')->directory($slug . '/' . $field->getName())->useDatePath()->maxBytes($field->getMaxVideoBytes());
        return $video->chunked($directory)->maxChunkBytes(self::MAX_CHUNK_BYTES)->chunkSize(self::CHUNK_BYTES);
    }

    private static function config(): array
    {
        $media = Admin::config()->media();
        if (!is_dir($media['root']) && !mkdir($media['root'], 0755, true) && !is_dir($media['root'])) { throw new \RuntimeException('Cannot create the media folder ' . $media['root'] . '.'); }
        return ['default_disk' => 'editor', 'disks' => ['editor' => ['driver' => 'local', 'root' => $media['root'], 'url' => $media['url'], 'visibility' => 'public']],
            'video' => ['allow_mov' => false]];
    }

    /** Only the user and session that started a part upload can continue it. */
    private static function owner(): string
    {
        $user = (string) Session::get('user_id', '');
        if ($user === '') { throw new HttpException(401); }
        return $user . '|' . hash('sha256', Csrf::token());
    }

    /** Public URL of a stored path, relative to the site so content survives a domain change. */
    private static function path(string $path): string
    {
        $media = Admin::config()->media();
        if ($path === '' || str_contains($path, '..')) { throw new \RuntimeException('Unexpected media path.'); }
        return $media['url'] . '/' . implode('/', array_map('rawurlencode', explode('/', ltrim($path, '/'))));
    }
}
