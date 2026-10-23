<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Summaries;

/** The smallest value (numbers, or dates as stored); nothing when there are none. */
final class Min extends Summary
{
    public function kind(): string { return 'min'; }

    protected function defaultLabel(): string { return 'totals.min'; }

    public function sql(string $expression): array
    {
        return ['MIN(' . $expression . ')', []];
    }

    public function compute(array $values): int|float|string|null
    {
        return Extremes::pick($values, -1);
    }

    public function fromDatabase(mixed $value): int|float|string|null
    {
        return Extremes::read($value);
    }
}
