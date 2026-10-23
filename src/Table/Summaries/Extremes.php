<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Summaries;

use FloCMS\Admin\Support\Numbers;

/** @internal Min and Max: numbers compare as numbers, other values (dates) as text. */
final class Extremes
{
    /** @param list<mixed> $values */
    public static function pick(array $values, int $direction): int|float|string|null
    {
        $best = null;
        foreach ($values as $value) {
            if ($value === null || $value === '' || !is_scalar($value)) { continue; }
            $value = Numbers::isNumber($value) ? Numbers::normalize($value) : (string) $value;
            if ($best === null) { $best = $value; continue; }
            $compare = is_string($value) || is_string($best) ? strcmp((string) $value, (string) $best) : $value <=> $best;
            if ($compare * $direction > 0) { $best = $value; }
        }
        return $best;
    }

    public static function read(mixed $value): int|float|string|null
    {
        if ($value === null || $value === '' || !is_scalar($value)) { return null; }
        return Numbers::isNumber($value) ? Numbers::normalize($value) : (string) $value;
    }
}
