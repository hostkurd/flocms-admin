<?php
declare(strict_types=1);

/*
 * Since 0.43 the panel uses core's mail (flocms-core 2.10). The panel's old
 * names stay as aliases of core's classes for code written against the panel.
 *
 * Loaded by Composer's "files" (not PSR-4): a catch block or a type hint never
 * autoloads a class, and an alias in a PSR-4 file is invisible to an
 * authoritative class map (composer dump-autoload -a).
 */

foreach (['Message', 'MailException', 'SmtpTransport'] as $name) {
    if (!class_exists('FloCMS\\Admin\\Mail\\' . $name, false) && class_exists('FloCMS\\Core\\Mail\\' . $name)) {
        class_alias('FloCMS\\Core\\Mail\\' . $name, 'FloCMS\\Admin\\Mail\\' . $name);
    }
}
