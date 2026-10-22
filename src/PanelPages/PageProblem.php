<?php
declare(strict_types=1);

namespace FloCMS\Admin\PanelPages;

use FloCMS\Admin\AdminPage;
use FloCMS\Admin\Support\AdminLang;

/**
 * Why a part of a panel page could not be shown, in plain words (0.36.1):
 * "The table "bookings" was not found in the database. Check table() in
 * admin/Pages/SalesReport.php." Common setup mistakes get their own message;
 * anything else names the method to look at.
 *
 * @internal
 */
final class PageProblem
{
    /** The method of the page each part comes from. */
    private const METHODS = ['table' => 'table()', 'filters' => 'filters()', 'widgets' => 'widgets()', 'view' => 'view() / data()', 'form' => 'form() / fill()', 'actions' => 'actions()'];

    public static function describe(\Throwable $error, AdminPage $page, string $part): string
    {
        $method = self::METHODS[$part] ?? $part . '()';
        $file = 'admin/Pages/' . (new \ReflectionClass($page))->getShortName() . '.php';
        $replace = ['method' => $method, 'file' => $file];
        $message = $error->getMessage();

        // MySQL/MariaDB "Table 'db.bookings' doesn't exist"; SQLite "no such table: bookings"
        if (preg_match("~Table '(?:[^'.]+\\.)?([^']+)' doesn't exist~i", $message, $m) === 1 || preg_match('~no such table: ([A-Za-z0-9_.]+)~i', $message, $m) === 1) {
            return AdminLang::get('pages_api.problem_table', $replace + ['table' => $m[1]]);
        }
        // MySQL/MariaDB "Unknown column 'x' in 'field list'"; SQLite "no such column: x"
        if (preg_match("~Unknown column '([^']+)'~i", $message, $m) === 1 || preg_match('~no such column: ([A-Za-z0-9_.]+)~i', $message, $m) === 1) {
            return AdminLang::get('pages_api.problem_column', $replace + ['column' => $m[1]]);
        }
        if (str_contains($message, 'needs the database')) {
            return AdminLang::get('pages_api.problem_database');
        }
        if ($error instanceof \PDOException) {
            return AdminLang::get('pages_api.problem_query', $replace);
        }
        // The package's own messages about a page's setup (thrown in src/) are written for people: show them as they are.
        // A site's own exceptions may hold anything, so only APP_DEBUG shows those.
        if (get_class($error) === \LogicException::class && str_starts_with(str_replace('\\', '/', $error->getFile()), str_replace('\\', '/', dirname(__DIR__)) . '/')) {
            return AdminLang::get('pages_api.problem_setup', $replace + ['message' => $message]);
        }
        return AdminLang::get('pages_api.problem_other', $replace);
    }
}
