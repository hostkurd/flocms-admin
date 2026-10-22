<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Filters;

use FloCMS\Admin\Support\ListValues;
use FloCMS\Core\Database;

/**
 * "The list in this column holds this item" (0.37), for CheckboxList and
 * TagsInput columns: whole items only ("sea" does not match "sea view") and
 * regardless of capitals in any script ("Sea View" matches "sea view"), the
 * same on MySQL, MariaDB and SQLite.
 *
 * The lists are JSON written by the panel (["a","b"]) or by MySQL's JSON type
 * (["a", "b"]), so an item is its JSON string right after "[" or ",": a quote
 * inside an item is escaped and never ends one. Plain text left from before
 * ("a, b") is matched between commas.
 *
 * @internal
 */
final class ListMatch
{
    /** PDO connections that have the lower-case function. */
    private static ?\WeakMap $registered = null;

    /**
     * The condition for one item, to OR or AND with others.
     *
     * @return array{0: string, 1: list<string>} [sql, bindings]
     */
    public static function condition(Database $query, string $column, string $item): array
    {
        $sqlite = $query->driver() === 'sqlite';
        $col = '`' . $column . '`';
        if ($sqlite) {
            self::register($query);
            $item = ListValues::fold($item);
            $text = 'flo_admin_lower(' . $col . ')';
            $legacy = "(',' || replace(replace(replace({$text}, '،', ','), ', ', ','), ' ,', ',') || ',')";
        } else {
            $text = 'CONVERT(' . $col . ' USING utf8mb4) COLLATE utf8mb4_unicode_ci';
            $legacy = "CONCAT(',', REPLACE(REPLACE(REPLACE(CONVERT({$col} USING utf8mb4), '،', ','), ', ', ','), ' ,', ','), ',') COLLATE utf8mb4_unicode_ci";
        }
        $json = self::like(json_encode($item, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $sql = "({$text} LIKE ? ESCAPE '!' OR {$text} LIKE ? ESCAPE '!' OR {$text} LIKE ? ESCAPE '!'"
            . " OR ({$col} NOT LIKE '[%' AND {$legacy} LIKE ? ESCAPE '!'))";
        return [$sql, ['%[' . $json . '%', '%,' . $json . '%', '%, ' . $json . '%', '%,' . self::like($item) . ',%']];
    }

    /**
     * Rows whose list holds any of the items.
     *
     * @param list<string> $items
     */
    public static function any(Database $query, string $column, array $items): Database
    {
        $parts = [];
        $bindings = [];
        foreach ($items as $item) {
            [$sql, $values] = self::condition($query, $column, $item);
            $parts[] = $sql;
            array_push($bindings, ...$values);
        }
        return $parts === [] ? $query : $query->whereRaw('(' . implode(' OR ', $parts) . ')', $bindings);
    }

    private static function like(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    /** SQLite's lower() folds only A–Z: give it PHP's for Greek, Cyrillic and the rest. */
    private static function register(Database $query): void
    {
        $pdo = $query->pdo();
        self::$registered ??= new \WeakMap();
        if (isset(self::$registered[$pdo])) { return; }
        $lower = static fn ($value): ?string => $value === null ? null : mb_strtolower((string) $value, 'UTF-8');
        if (class_exists(\Pdo\Sqlite::class) && $pdo instanceof \Pdo\Sqlite) {
            $pdo->createFunction('flo_admin_lower', $lower, 1, \Pdo\Sqlite::DETERMINISTIC);
        } else {
            $pdo->sqliteCreateFunction('flo_admin_lower', $lower, 1, \PDO::SQLITE_DETERMINISTIC);
        }
        self::$registered[$pdo] = true;
    }
}
