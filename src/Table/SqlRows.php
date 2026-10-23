<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table;

use FloCMS\Core\Database;
use PDO;

/**
 * Runs a SELECT the query builder cannot write (totals and group subtotals
 * over a list's rows, 0.38), binding values the way FloCMS does: on SQLite an
 * integer is bound as an integer, so it equals a number.
 *
 * @internal
 */
final class SqlRows
{
    /** @return list<object> */
    public static function select(Database $db, string $sql, array $params): array
    {
        $statement = $db->pdo()->prepare($sql);
        foreach (array_values($params) as $i => $value) {
            if (is_bool($value)) { $value = $value ? 1 : 0; }
            if ($value instanceof \DateTimeInterface) { $value = $value->format('Y-m-d H:i:s'); }
            if ($db->driver() !== 'sqlite') {
                $statement->bindValue($i + 1, $value === null ? null : (is_scalar($value) ? (string) $value : json_encode($value)), $value === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                continue;
            }
            $statement->bindValue($i + 1, $value, match (true) { is_int($value) => PDO::PARAM_INT, $value === null => PDO::PARAM_NULL, default => PDO::PARAM_STR });
        }
        $statement->execute();
        return array_values($statement->fetchAll(PDO::FETCH_OBJ));
    }

    /**
     * The list's query as a derived table "flo_t": its SQL without order or
     * limit, and its bindings.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function derived(Database $query): array
    {
        [$sql, $params] = $query->toSql();
        // toSql() includes ORDER BY / LIMIT when set; the list's base query has none.
        return ['(' . $sql . ') flo_t', array_values($params)];
    }
}
