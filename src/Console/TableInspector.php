<?php
declare(strict_types=1);

namespace FloCMS\Admin\Console;

use FloCMS\Admin\Support\Str;
use FloCMS\Core\Database;

/**
 * Reads a database table for make:admin-page --table (0.36.2): which tables
 * exist, their columns, and a few values, so the page it writes shows columns
 * and filters that fit the table.
 *
 * @internal
 */
final class TableInspector
{
    /** Columns never put in a generated table. */
    private const SECRET = '/(^|_)(password|pass|secret|token|api_key|apikey|remember|salt|hash)(_|$)/i';
    /** Columns that make a good select filter when they hold a few values. */
    private const CHOICE_NAMES = ['status', 'type', 'state', 'kind', 'category', 'role', 'listing_type'];
    /** The most columns a generated table shows. */
    public const MAX_COLUMNS = 6;

    public function __construct(private readonly Database $db)
    {
    }

    /** @return list<string> the tables, sorted; the panel's own (admin_*) and internal ones left out */
    public function tables(): array
    {
        $rows = $this->db->driver() === 'sqlite'
            ? $this->db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(\PDO::FETCH_COLUMN)
            : $this->db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $tables = array_values(array_filter(array_map('strval', $rows), static fn (string $t): bool => !str_starts_with($t, 'admin_') && !in_array($t, ['migrations', 'flo_migrations'], true)));
        sort($tables);
        return $tables;
    }

    /** The closest table name to a misspelt one ("listing" → "listings"), or null. */
    public function closest(string $table): ?string
    {
        $best = null;
        $distance = PHP_INT_MAX;
        foreach ($this->tables() as $candidate) {
            $d = levenshtein(strtolower($table), strtolower($candidate));
            if ($d < $distance) { $distance = $d; $best = $candidate; }
        }
        return $best !== null && $distance <= max(2, intdiv(strlen($table), 3)) ? $best : null;
    }

    /** @return list<array{name: string, type: string, primary: bool}>|null the columns, or null when the table doesn't exist */
    public function columns(string $table): ?array
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $table) !== 1 || !in_array($table, $this->allTables(), true)) { return null; }
        $columns = [];
        if ($this->db->driver() === 'sqlite') {
            foreach ($this->db->query('PRAGMA table_info(`' . $table . '`)')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $columns[] = ['name' => (string) $row['name'], 'type' => strtolower((string) $row['type']), 'primary' => (int) $row['pk'] > 0];
            }
        } else {
            foreach ($this->db->query('SHOW COLUMNS FROM `' . $table . '`')->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $columns[] = ['name' => (string) $row['Field'], 'type' => strtolower((string) $row['Type']), 'primary' => ($row['Key'] ?? '') === 'PRI'];
            }
        }
        return $columns;
    }

    /**
     * What the generated page shows: columns (translatable JSON found in the
     * values), the primary key for sorting, a date filter and a select filter.
     *
     * @return array{table: string, primary: string, columns: list<array{class: string, name: string, searchable: bool, sortable: bool, translatable: bool}>, date: ?string, choice: ?array{column: string, options: array<string, string>}, left: list<string>}
     */
    public function plan(string $table): array
    {
        $columns = $this->columns($table) ?? [];
        $primary = 'id';
        foreach ($columns as $column) { if ($column['primary']) { $primary = $column['name']; break; } }
        $picked = [];
        $left = [];
        $date = null;
        $choice = null;
        foreach ($columns as $column) {
            $name = $column['name'];
            $type = $column['type'];
            if ($column['primary'] || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $name) !== 1) { continue; }
            if (preg_match(self::SECRET, $name) === 1) { $left[] = $name . ' (kept out: it looks secret)'; continue; }
            if (str_ends_with($name, '_id')) { $left[] = $name . ' (a key: a RelationColumn can show its title)'; continue; }
            $kind = self::kind($type, $this->db->driver() === 'sqlite');
            if ($kind === 'date') {
                $date ??= in_array($name, ['created_at', 'paid_at', 'published_on', 'date'], true) ? $name : null;
                $picked[] = ['class' => 'DateColumn', 'name' => $name, 'searchable' => false, 'sortable' => true, 'translatable' => false];
                continue;
            }
            if ($kind === 'bool') {
                $picked[] = ['class' => 'BooleanColumn', 'name' => $name, 'searchable' => false, 'sortable' => true, 'translatable' => false];
                continue;
            }
            if ($kind === 'number') {
                $picked[] = ['class' => 'TextColumn', 'name' => $name, 'searchable' => false, 'sortable' => true, 'translatable' => false];
                continue;
            }
            if ($kind === 'binary') { $left[] = $name . ' (binary)'; continue; }
            $sample = $this->sample($table, $name);
            $translatable = $sample !== [] && array_filter($sample, static fn (string $v): bool => !self::isTranslation($v)) === [];
            $long = $kind === 'long' || max(array_map('mb_strlen', $sample ?: [''])) > 160;
            if ($long && !$translatable) { $left[] = $name . ' (long text)'; continue; }
            if ($choice === null && in_array($name, self::CHOICE_NAMES, true) && !$translatable) {
                $values = $this->distinct($table, $name);
                if ($values !== null && $values !== []) { $choice = ['column' => $name, 'options' => array_combine($values, array_map(static fn (string $v): string => ucfirst(Str::headline($v)), $values))]; }
            }
            $picked[] = ['class' => 'TextColumn', 'name' => $name, 'searchable' => true, 'sortable' => !$translatable, 'translatable' => $translatable];
        }
        // Text first (the first searchable column titles the row), then the rest in table order.
        usort($picked, static fn (array $a, array $b): int => (int) !$a['searchable'] <=> (int) !$b['searchable']);
        foreach (array_slice($picked, self::MAX_COLUMNS) as $extra) { $left[] = $extra['name'] . ' (more than ' . self::MAX_COLUMNS . ' columns)'; }
        $picked = array_slice($picked, 0, self::MAX_COLUMNS);
        if ($picked === []) { $picked[] = ['class' => 'TextColumn', 'name' => $primary, 'searchable' => false, 'sortable' => true, 'translatable' => false]; }
        if ($date === null) {
            foreach ($picked as $column) { if ($column['class'] === 'DateColumn') { $date = $column['name']; break; } }
        }
        return ['table' => $table, 'primary' => $primary, 'columns' => $picked, 'date' => $date, 'choice' => $choice, 'left' => $left];
    }

    /** text, long, number, date, bool or binary */
    public static function kind(string $type, bool $sqlite = false): string
    {
        // SQLite's TEXT is any text: its values decide (sample() length) whether it is long.
        if ($sqlite && ($type === 'text' || $type === '' || str_starts_with($type, 'varchar') || str_starts_with($type, 'char'))) { return 'text'; }
        return match (true) {
            preg_match('/^(tinyint\(1\)|bool|boolean)/', $type) === 1 => 'bool',
            preg_match('/(date|time)/', $type) === 1 => 'date',
            preg_match('/(int|dec|numeric|float|double|real)/', $type) === 1 => 'number',
            preg_match('/(blob|binary)/', $type) === 1 => 'binary',
            preg_match('/^(mediumtext|longtext|text|json)/', $type) === 1 => 'long',
            default => 'text',
        };
    }

    /** A translatable value as the panel stores it: {"en": "…", "ar": "…"}. */
    public static function isTranslation(string $value): bool
    {
        if (!str_starts_with(ltrim($value), '{')) { return false; }
        $decoded = json_decode($value, true);
        if (!is_array($decoded) || $decoded === []) { return false; }
        foreach ($decoded as $key => $item) {
            if (!is_string($key) || preg_match('/^[a-z]{2,3}(?:[-_][A-Za-z]{2,4})?$/D', $key) !== 1 || !(is_string($item) || $item === null)) { return false; }
        }
        return true;
    }

    /** @return list<string> a few non-empty values of a column */
    private function sample(string $table, string $column): array
    {
        try {
            $rows = $this->db->query('SELECT `' . $column . '` FROM `' . $table . '` WHERE `' . $column . "` IS NOT NULL AND `" . $column . "` <> '' LIMIT 5")->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return [];
        }
        return array_values(array_map('strval', array_filter($rows, 'is_scalar')));
    }

    /** @return list<string>|null the column's values when there are a few (at most 12), else null */
    private function distinct(string $table, string $column): ?array
    {
        try {
            $rows = $this->db->query('SELECT DISTINCT `' . $column . '` FROM `' . $table . '` WHERE `' . $column . "` IS NOT NULL AND `" . $column . "` <> '' LIMIT 13")->fetchAll(\PDO::FETCH_COLUMN);
        } catch (\Throwable) {
            return null;
        }
        $values = array_values(array_filter(array_map('strval', $rows), static fn (string $v): bool => mb_strlen($v) <= 40));
        sort($values);
        return count($rows) <= 12 ? $values : null;
    }

    /** @return list<string> every table, including the panel's own */
    private function allTables(): array
    {
        $rows = $this->db->driver() === 'sqlite'
            ? $this->db->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(\PDO::FETCH_COLUMN)
            : $this->db->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        return array_map('strval', $rows);
    }
}
