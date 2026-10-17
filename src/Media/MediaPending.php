<?php
declare(strict_types=1);

namespace FloCMS\Admin\Media;

use FloCMS\Admin\Admin;

/**
 * Uploads waiting for a choice (0.18): a file whose content is already in the
 * library is stored but not added yet. The editor then keeps it ("Upload
 * anyway", no second upload) or discards it ("Use the existing one").
 *
 * Each waiting upload is a small JSON file in storage/admin-uploads/pending,
 * bound to the user and session that uploaded it. Abandoned ones expire after a
 * day; purge() (run on each new hold and each answer) removes them and their files.
 */
final class MediaPending
{
    public const TTL = 86400;

    /**
     * @param array{kind: string, path: string, thumb_path: ?string, mime: string, bytes: int, width: ?int, height: ?int, file_name: string, hash?: ?string} $file
     * @return string The token to keep or discard it with
     */
    public static function hold(array $file, ?int $folderId): string
    {
        self::purge();
        $directory = self::directory();
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) { throw new \RuntimeException('Cannot create the pending uploads folder.'); }
        $token = bin2hex(random_bytes(16));
        $data = ['owner' => MediaFiles::owner(), 'file' => $file, 'folder' => $folderId, 'at' => time()];
        if (file_put_contents($directory . '/' . $token . '.json', json_encode($data, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new \RuntimeException('Cannot write the pending upload.');
        }
        return $token;
    }

    /**
     * The waiting upload of this user, removed from the waiting list (null when unknown or not theirs).
     *
     * @return array{file: array<string, mixed>, folder: ?int}|null
     */
    public static function take(string $token): ?array
    {
        $data = self::read($token);
        if ($data === null) { return null; }
        @unlink(self::directory() . '/' . $token . '.json');
        return ['file' => $data['file'], 'folder' => is_int($data['folder'] ?? null) ? $data['folder'] : null];
    }

    /** Drop a waiting upload and its files. Returns false when it is unknown or not this user's. */
    public static function discard(string $token): bool
    {
        $pending = self::take($token);
        if ($pending === null) { return false; }
        MediaFiles::delete(array_values(array_filter([$pending['file']['path'] ?? null, $pending['file']['thumb_path'] ?? null], 'is_string')));
        return true;
    }

    /** Remove waiting uploads older than a day, with their files. */
    public static function purge(): void
    {
        foreach (glob(self::directory() . '/*.json') ?: [] as $json) {
            if (filemtime($json) !== false && filemtime($json) > time() - self::TTL) { continue; }
            $data = json_decode((string) file_get_contents($json), true);
            if (is_array($data['file'] ?? null)) {
                MediaFiles::delete(array_values(array_filter([$data['file']['path'] ?? null, $data['file']['thumb_path'] ?? null], 'is_string')));
            }
            @unlink($json);
        }
    }

    public static function directory(): string
    {
        return Admin::config()->root() . '/storage/admin-uploads/pending';
    }

    /** @return array<string, mixed>|null */
    private static function read(string $token): ?array
    {
        if (preg_match('/^[0-9a-f]{32}$/D', $token) !== 1) { return null; }
        $json = self::directory() . '/' . $token . '.json';
        if (!is_file($json)) { return null; }
        $data = json_decode((string) file_get_contents($json), true);
        if (!is_array($data) || !is_array($data['file'] ?? null) || !hash_equals((string) ($data['owner'] ?? ''), MediaFiles::owner())) { return null; }
        // Expired: purge() removes it (and its files); it can't be kept any more.
        if ((int) ($data['at'] ?? 0) < time() - self::TTL) { return null; }
        return $data;
    }
}
