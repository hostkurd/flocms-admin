<?php
declare(strict_types=1);

namespace FloCMS\Admin\Media;

use FloCMS\Admin\Admin;
use FloCMS\Core\App;
use FloCMS\Core\Auth;
use FloCMS\Core\Database;

/**
 * The media library (0.17): public images and videos uploaded once and reused
 * in records (MediaPicker), rich text and on the website (media()).
 *
 * It is on when config/admin.php does not switch it off and its table exists
 * (php flo admin:install writes the migration, php flo migrate creates it).
 */
final class MediaLibrary
{
    private static ?MediaStore $store = null;
    private static ?bool $ready = null;
    private static ?Database $readyFor = null;

    /** The library can be used: switched on and its table is there. */
    public static function enabled(): bool
    {
        if (!Admin::config()->mediaLibrary()['enabled']) { return false; }
        $db = App::db();
        if ($db === null) { return false; }
        if (self::$ready === null || self::$readyFor !== $db) {
            self::$readyFor = $db;
            try {
                $db->query('SELECT 1 FROM `' . self::table() . '` WHERE 1 = 0');
                $db->query('SELECT 1 FROM `' . self::usageTable() . '` WHERE 1 = 0');
                self::$ready = true;
            } catch (\Throwable) {
                self::$ready = false;
            }
        }
        return self::$ready;
    }

    public static function table(): string { return Admin::config()->mediaLibrary()['table']; }
    public static function usageTable(): string { return Admin::config()->mediaLibrary()['usage_table']; }
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
            self::$store = new MediaStore($db, self::table(), self::usageTable(), Admin::config()->media()['url']);
        }
        return self::$store;
    }

    /** The store, or null when the library is off (website helpers). */
    public static function storeOrNull(): ?MediaStore
    {
        return self::enabled() ? self::store() : null;
    }

    public static function reset(): void
    {
        self::$store = null;
        self::$ready = null;
        self::$readyFor = null;
    }
}
