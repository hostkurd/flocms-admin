<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

use FloCMS\Admin\Activity\Activity;
use FloCMS\Admin\Admin;
use FloCMS\Admin\Trash\Trash;
use FloCMS\Core\Session;

/**
 * Small jobs done on panel pages, so sites without a cron job stay tidy:
 * at most once an hour, records older than the trash's days are deleted for
 * good and activity entries older than the log's days are removed
 * (php flo admin:trash-purge and admin:activity-purge do the same from cron).
 *
 * @internal
 */
final class Housekeeping
{
    public const INTERVAL = 3600;
    private const LOGIN_MARKER = '_flo_admin_login_logged';

    /** Purge what has expired, at most once per INTERVAL for the whole site. */
    public static function run(): void
    {
        try {
            $imports = \FloCMS\Admin\ImportExport\ImportStore::status() === 'ready';
            if (!Trash::enabled() && !Activity::enabled() && !$imports) { return; }
            $file = self::stampFile();
            $last = is_file($file) ? (int) @filemtime($file) : 0;
            if ($last > time() - self::INTERVAL) { return; }
            // Claim the slot first: other requests in the next hour skip the work.
            if (@touch($file) === false) { return; }
            Trash::purgeExpired();
            Activity::purgeExpired();
            // Old import uploads and Undo snapshots (0.25)
            if ($imports) { \FloCMS\Admin\ImportExport\ImportStore::make()->purge(); }
        } catch (\Throwable $error) {
            error_log('flocms-admin: housekeeping failed: ' . $error->getMessage());
        }
    }

    /** With 'activity' => ['logins' => true]: one "Signed in" entry for each new session that opens the panel. */
    public static function noteLogin(): void
    {
        try {
            if (!Admin::config()->activity()['logins'] || !isset($_SESSION) || Session::get(self::LOGIN_MARKER) === true) { return; }
            if (!Activity::enabled() || Actor::id() === null) { return; }
            Session::set(self::LOGIN_MARKER, true);
            Activity::record('login', 'accounts', (string) Actor::id(), Actor::name());
        } catch (\Throwable $error) {
            error_log('flocms-admin: could not log the sign-in: ' . $error->getMessage());
        }
    }

    private static function stampFile(): string
    {
        $cache = Admin::root() . '/storage/cache';
        if (is_dir($cache) && is_writable($cache)) { return $cache . '/flocms-admin-housekeeping'; }
        return sys_get_temp_dir() . '/flocms-admin-housekeeping-' . substr(hash('sha256', Admin::root()), 0, 16);
    }
}
