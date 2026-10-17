<?php
declare(strict_types=1);

namespace FloCMS\Admin\Media;

use FloCMS\Admin\Admin;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Database;

/**
 * The media library (0.17): public images and videos uploaded once and reused
 * in records (MediaPicker), rich text and on the website (media()), sorted in
 * folders (0.18).
 *
 * It is on when config/admin.php does not switch it off and its tables exist
 * (php flo admin:install writes the migrations, php flo migrate creates them).
 */
final class MediaLibrary
{
    private static ?MediaStore $store = null;
    private static ?MediaFolders $folders = null;
    private static ?string $status = null;
    private static ?Database $statusFor = null;

    /** The library can be used: switched on and its tables are there, up to date. */
    public static function enabled(): bool
    {
        return self::status() === 'ready';
    }

    /**
     * 'off' (config), 'missing' (no tables: php flo admin:install, php flo migrate),
     * 'upgrade' (the 0.17 tables without the 0.18 folders migration) or 'ready'.
     */
    public static function status(): string
    {
        if (!Admin::config()->mediaLibrary()['enabled']) { return 'off'; }
        $db = App::db();
        if ($db === null) { return 'missing'; }
        if (self::$status === null || self::$statusFor !== $db) {
            self::$statusFor = $db;
            try {
                $db->query('SELECT 1 FROM `' . self::table() . '` WHERE 1 = 0');
                $db->query('SELECT 1 FROM `' . self::usageTable() . '` WHERE 1 = 0');
            } catch (\Throwable) {
                return self::$status = 'missing';
            }
            try {
                $db->query('SELECT `folder_id`, `hash`, `focal_x`, `focal_y` FROM `' . self::table() . '` WHERE 1 = 0');
                $db->query('SELECT 1 FROM `' . self::foldersTable() . '` WHERE 1 = 0');
                self::$status = 'ready';
            } catch (\Throwable) {
                self::$status = 'upgrade';
            }
        }
        return self::$status;
    }

    public static function table(): string { return Admin::config()->mediaLibrary()['table']; }
    public static function usageTable(): string { return Admin::config()->mediaLibrary()['usage_table']; }
    public static function foldersTable(): string { return Admin::config()->mediaLibrary()['folders_table']; }
    public static function maxImageBytes(): int { return Admin::config()->mediaLibrary()['max_image_bytes']; }
    public static function maxVideoBytes(): int { return Admin::config()->mediaLibrary()['max_video_bytes']; }

    /** "<permission_prefix>.media.<view|create|update|delete>", so the skeleton's Editor role (content.*) has them. */
    public static function permission(string $ability): string
    {
        return Admin::config()->permissionPrefix() . '.media.' . $ability;
    }

    public static function can(string $ability): bool
    {
        return Auth::can(self::permission($ability));
    }

    /** The store on the current connection; throws when the library is off. */
    public static function store(): MediaStore
    {
        $db = App::db();
        if ($db === null || !self::enabled()) { throw new \RuntimeException('The media library is not set up: run php flo admin:install and php flo migrate.'); }
        if (self::$store === null || !self::$store->uses($db)) {
            self::$store = new MediaStore($db, self::table(), self::usageTable(), Admin::config()->media()['url'], self::foldersTable());
        }
        return self::$store;
    }

    /** The folders on the current connection; throws when the library is off. */
    public static function folders(): MediaFolders
    {
        $db = self::store()->connection();
        if (self::$folders === null || !self::$folders->uses($db)) {
            self::$folders = new MediaFolders($db, self::foldersTable(), self::table());
        }
        return self::$folders;
    }

    /**
     * A new item for files just stored (MediaFiles::image() or video()). When the row can't be
     * written, the files are deleted again so nothing is left behind.
     *
     * @param array{kind: string, path: string, thumb_path: ?string, mime: string, bytes: int, width: ?int, height: ?int, file_name: string} $file
     */
    public static function add(array $file, ?int $uploadedBy, string $title = '', ?int $folderId = null): MediaItem
    {
        try {
            return self::store()->create($file, $uploadedBy, $title, $folderId);
        } catch (\Throwable $error) {
            MediaFiles::delete(array_values(array_filter([$file['path'], $file['thumb_path'] ?? null])));
            throw $error;
        }
    }

    /** The store, or null when the library is off (website helpers). */
    public static function storeOrNull(): ?MediaStore
    {
        return self::enabled() ? self::store() : null;
    }

    public static function reset(): void
    {
        self::$store = null;
        self::$folders = null;
        self::$status = null;
        self::$statusFor = null;
    }
}
