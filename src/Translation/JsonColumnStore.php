<?php
declare(strict_types=1);

namespace FloCMS\Admin\Translation;

use FloCMS\Core\Database;
use InvalidArgumentException;

/**
 * One JSON object per row in the field's own column:
 *
 *     {"en":"Sea view villa","ar":"فيلا بإطلالة على البحر","el":"Βίλα με θέα στη θάλασσα"}
 *
 * Column type: JSON (MySQL 5.7+; on MariaDB an alias of LONGTEXT with a
 * JSON_VALID check) or any text column. Plain text already in the column
 * (a column switched to translatable later) is read as the default language
 * and rewritten as JSON on the next save, so no data migration is needed.
 */
final class JsonColumnStore implements TranslationStore
{
    public function decode(mixed $raw, string $defaultLang): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return self::clean($raw);
        }

        if (!is_scalar($raw)) {
            return [];
        }

        $raw = (string) $raw;
        $trimmed = ltrim($raw);

        if ($trimmed !== '' && $trimmed[0] === '{') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return self::clean($decoded);
            }
        }

        return [$defaultLang => $raw];
    }

    public function encode(array $values): ?string
    {
        $values = self::clean($values);

        if ($values === []) {
            return null;
        }

        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    public function whereContains(Database $query, string $column, array $languages, string $term, string $defaultLang): Database
    {
        $languages = array_values(array_filter($languages, [Languages::class, 'isValid']));
        if ($languages === []) {
            return $query;
        }

        $like = '%' . self::escapeLike($term) . '%';
        $mysql = self::isMysql($query);

        return $query->orWhere(function (Database $group) use ($column, $languages, $like, $mysql, $defaultLang, $query): void {
            foreach ($languages as $lang) {
                $expression = $this->valueExpression($query, $column, $lang, $defaultLang);
                if ($mysql) {
                    $expression .= ' COLLATE utf8mb4_unicode_ci';
                }
                $group->orWhereRaw($expression . " LIKE ? ESCAPE '!'", [$like]);
            }
        });
    }

    public function sortExpression(Database $query, string $column, string $lang, string $defaultLang): string
    {
        return $this->valueExpression($query, $column, $lang, $defaultLang);
    }

    /**
     * SQL for the text of one language. Plain (non-JSON) values count as the
     * default language, so legacy rows search and sort correctly; the CASE
     * order keeps JSON functions away from text that is not JSON (MySQL 8
     * raises an error for that).
     */
    public function valueExpression(Database $query, string $column, string $lang, string $defaultLang): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $column) !== 1) {
            throw new InvalidArgumentException('Invalid column name: ' . $column);
        }
        if (!Languages::isValid($lang)) {
            throw new InvalidArgumentException('Invalid language code: ' . $lang);
        }

        $col = '`' . $column . '`';
        $plain = $lang === $defaultLang ? $col : 'NULL';
        $path = "'$.\"" . $lang . "\"'";

        if (self::isMysql($query)) {
            return "(CASE WHEN {$col} IS NULL THEN NULL"
                . " WHEN JSON_VALID({$col}) = 0 THEN {$plain}"
                . " WHEN JSON_TYPE({$col}) = 'OBJECT' THEN JSON_UNQUOTE(JSON_EXTRACT({$col}, {$path}))"
                . " ELSE {$plain} END)";
        }

        // SQLite (tests); json1 is built into PHP's SQLite
        return "(CASE WHEN {$col} IS NULL THEN NULL"
            . " WHEN json_valid({$col}) = 0 THEN {$plain}"
            . " WHEN json_type({$col}) = 'object' THEN json_extract({$col}, {$path})"
            . " ELSE {$plain} END)";
    }

    /** @return array<string, string> */
    private static function clean(array $values): array
    {
        $clean = [];
        foreach ($values as $lang => $value) {
            $lang = strtolower(trim((string) $lang));
            if (!Languages::isValid($lang) || $value === null || is_array($value) || is_object($value)) {
                continue;
            }
            $value = (string) $value;
            if (trim($value) === '') {
                continue;
            }
            $clean[$lang] = $value;
        }

        return $clean;
    }

    private static function escapeLike(string $term): string
    {
        return strtr($term, ['!' => '!!', '%' => '!%', '_' => '!_']);
    }

    private static function isMysql(Database $query): bool
    {
        return $query->driver() !== 'sqlite';
    }
}
