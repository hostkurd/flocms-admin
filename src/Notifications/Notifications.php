<?php
declare(strict_types=1);
namespace FloCMS\Admin\Notifications;

use FloCMS\Core\Session;

/** Response-only delivery; action transactions stage notifications until success. */
final class Notifications
{
    private const KEY = '_flo_admin_notifications';
    private static array $buffers = [];
    public static function push(array $notification): void
    {
        if (self::$buffers !== []) {
            $index = array_key_last(self::$buffers);
            self::$buffers[$index][] = $notification;
            self::$buffers[$index] = array_slice(self::$buffers[$index], -10);
            return;
        }
        $pending = self::pending();
        $pending[] = $notification;
        Session::set(self::KEY, array_slice($pending, -10));
    }
    private static function pending(): array
    {
        return array_values(array_filter((array) Session::get(self::KEY, []), static fn ($entry): bool =>
            is_array($entry) && is_int($entry['issued'] ?? null) && $entry['issued'] > time() - 3600));
    }
    public static function pull(): array
    {
        $pending = self::pending(); Session::set(self::KEY, []); return $pending;
    }
    /** On failure neither session delivery nor a caller's outer buffer is changed. */
    public static function capture(callable $callback): mixed
    {
        self::$buffers[] = [];
        try { $result = $callback(); }
        catch (\Throwable $error) { array_pop(self::$buffers); throw $error; }
        $notifications = array_pop(self::$buffers);
        foreach ($notifications as $notification) { self::push($notification); }
        return $result;
    }
}
