<?php
declare(strict_types=1);

namespace FloCMS\Admin\Media;

use FloCMS\Admin\Admin;
use FloCMS\Core\Csrf;
use FloCMS\Core\HttpException;
use FloCMS\Core\Session;
use FloCMS\Uploader\Chunked\ChunkedUploader;
use FloCMS\Uploader\Exceptions\ValidationException;
use FloCMS\Uploader\Storage\StorageManager;
use FloCMS\Uploader\Uploader;
use FloCMS\Uploader\UploadResult;

/**
 * Files of the media library, always through flocms-uploader: in the public
 * media folder under library/<yyyy>/<mm>/ with random names, checked by content.
 * Images keep a web version (at most 1920 px) and a thumb (400 px); the
 * original is not kept. Videos (MP4, WebM) arrive in parts.
 *
 * @internal
 */
final class MediaFiles
{
    public const DIRECTORY = 'library';
    public const WEB = 1920;
    public const CHUNK_BYTES = 1048576;
    public const MAX_CHUNK_BYTES = 2097152;

    /**
     * @return array{kind: string, path: string, thumb_path: ?string, mime: string, bytes: int, width: ?int, height: ?int, file_name: string}
     */
    public static function image(array $file, int $maxBytes): array
    {
        foreach (['tmp_name', 'name', 'error'] as $key) { if (!isset($file[$key]) || !is_scalar($file[$key])) { throw new ValidationException('The image did not arrive.'); } }
        if ((int) $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) { throw new ValidationException('The image did not arrive.'); }
        $result = Uploader::image(self::config())->onDisk('media')->directory(self::DIRECTORY)->useDatePath()
            ->maxBytes($maxBytes)->imageDimensions(['max_width' => 8192, 'max_height' => 8192])
            ->keepOriginal(false)->versions(['web' => ['resize' => [self::WEB, self::WEB]], 'thumb' => ['resize' => [MediaItem::THUMB, MediaItem::THUMB]]])
            ->upload($file);
        $web = $result->version('web') ?? throw new \RuntimeException('The image was not stored.');
        $thumb = $result->version('thumb');
        $absolute = (string) ($web['absolute_path'] ?? '');
        return ['kind' => 'image', 'path' => (string) $web['path'], 'thumb_path' => $thumb !== null ? (string) $thumb['path'] : null,
            'mime' => (string) ($web['mime'] ?? $result->mime() ?? 'image/jpeg'), 'bytes' => $absolute !== '' && is_file($absolute) ? (int) filesize($absolute) : (int) ($result->size() ?? 0),
            'width' => isset($web['width']) ? (int) $web['width'] : null, 'height' => isset($web['height']) ? (int) $web['height'] : null,
            'file_name' => self::name((string) ($result->originalName() ?? ''))];
    }

    /** Parts of a video upload, owned by the user and session that started it. */
    public static function chunks(int $maxBytes): ChunkedUploader
    {
        $directory = self::chunkDirectory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Cannot create the video upload folder.'); }
        $video = Uploader::video(self::config())->onDisk('media')->directory(self::DIRECTORY)->useDatePath()->maxBytes($maxBytes);
        return $video->chunked($directory)->maxChunkBytes(self::MAX_CHUNK_BYTES)->chunkSize(self::CHUNK_BYTES);
    }

    /** @return array{kind: string, path: string, thumb_path: ?string, mime: string, bytes: int, width: ?int, height: ?int, file_name: string} */
    public static function video(UploadResult $result, string $fileName): array
    {
        $path = (string) $result->path();
        if ($path === '' || str_contains($path, '..')) { throw new \RuntimeException('The video was not stored.'); }
        return ['kind' => 'video', 'path' => $path, 'thumb_path' => null, 'mime' => (string) ($result->mime() ?? 'video/mp4'),
            'bytes' => (int) ($result->size() ?? 0), 'width' => $result->width(), 'height' => $result->height(), 'file_name' => self::name($fileName)];
    }

    /** Delete stored files (after the database change committed); failures are logged, not thrown. */
    public static function delete(array $paths): void
    {
        $disk = (new StorageManager(self::config()))->disk('media');
        foreach (array_unique(array_filter($paths, static fn ($path): bool => is_string($path) && $path !== '' && !str_contains($path, '..'))) as $path) {
            try { $disk->delete($path); } catch (\Throwable $error) { error_log('flocms-admin: could not delete media file ' . $path . ': ' . $error->getMessage()); }
        }
    }

    public static function owner(): string
    {
        $user = (string) Session::get('user_id', '');
        if ($user === '') { throw new HttpException(401); }
        return $user . '|' . hash('sha256', Csrf::token());
    }

    public static function chunkDirectory(): string
    {
        return Admin::config()->root() . '/storage/admin-uploads/chunks';
    }

    /** @return array<string, mixed> flocms-uploader configuration of the public media folder. */
    public static function config(): array
    {
        $media = Admin::config()->media();
        if (!is_dir($media['root']) && !mkdir($media['root'], 0755, true) && !is_dir($media['root'])) { throw new \RuntimeException('Cannot create the media folder ' . $media['root'] . '.'); }
        return ['default_disk' => 'media', 'disks' => ['media' => ['driver' => 'local', 'root' => $media['root'], 'url' => $media['url'], 'visibility' => 'public']],
            'video' => ['allow_mov' => false]];
    }

    /** The uploaded file's name, for display and search only (never used as a path). */
    private static function name(string $name): string
    {
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', basename(str_replace('\\', '/', $name))) ?? '');
        return mb_substr($name, 0, 190);
    }
}
