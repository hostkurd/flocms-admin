<?php
declare(strict_types=1);

namespace FloCMS\Admin\Trash;

/**
 * A database row as JSON, exactly as it was: numbers stay numbers, NULL stays
 * NULL, and bytes that are not UTF-8 text (a BLOB column) are kept as base64.
 *
 * @internal
 */
final class Snapshot
{
    private const BINARY = '__flo_admin_base64';

    /** @param array<string, mixed> $row */
    public static function encode(array $row): string
    {
        $values = [];
        foreach ($row as $column => $value) {
            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                $value = [self::BINARY => base64_encode($value)];
            } elseif (is_float($value) && !is_finite($value)) {
                $value = null;
            } elseif (!is_scalar($value) && $value !== null) {
                $value = (string) json_encode($value);
            }
            $values[(string) $column] = $value;
        }
        return json_encode((object) $values, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, mixed> */
    public static function decode(string $json): array
    {
        $values = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($values)) { return []; }
        foreach ($values as $column => $value) {
            if (is_array($value) && isset($value[self::BINARY]) && is_string($value[self::BINARY])) {
                $values[$column] = (string) base64_decode($value[self::BINARY], true);
            } elseif (is_array($value)) {
                $values[$column] = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }
        return $values;
    }
}
