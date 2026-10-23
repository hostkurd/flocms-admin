<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Summaries;

/** The average of the numbers (empty values left out); nothing when there are none. */
final class Average extends Summary
{
    public function kind(): string { return 'avg'; }

    protected function defaultLabel(): string { return 'totals.average'; }

    public function sql(string $expression): array
    {
        return ['AVG(' . $expression . ')', []];
    }

    public function compute(array $values): int|float|null
    {
        $numbers = self::numbers($values);
        return $numbers === [] ? null : round(array_sum($numbers) / count($numbers), 10);
    }

    protected function defaultDecimals(int|float|string $value): ?int
    {
        // Two places unless the average is a whole number
        return abs((float) $value - round((float) $value)) < 0.000001 ? 0 : 2;
    }
}
