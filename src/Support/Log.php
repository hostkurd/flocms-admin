<?php
declare(strict_types=1);

namespace FloCMS\Admin\Support;

/**
 * Writes to the site's log (storage/logs/app.log) without ever breaking a page.
 *
 * @internal
 */
final class Log
{
    public static function error(string $message): void
    {
        try {
            \FloCMS\Core\App::logger()->log('error', '[flocms-admin] ' . $message);
        } catch (\Throwable) {
            // Logging must never break a page
        }
    }
}
