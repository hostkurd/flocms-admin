<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Summaries;

/** The sum of the numbers; 0 when there are none. */
final class Sum extends Summary
{
    public function kind(): string { return 'sum'; }

    protected function defaultLabel(): string { return 'totals.sum'; }

    public function sql(string $expression): array
    {
        return ['SUM(' . $expression . ')', []];
    }

    public function compute(array $values): int|float|null
    {
        $numbers = self::numbers($values);
        $sum = array_sum($numbers);
        return is_float($sum) ? round($sum, 10) : $sum;
    }

    public function fromDatabase(mixed $value): int|float|string|null
    {
        return parent::fromDatabase($value) ?? 0;
    }
}
