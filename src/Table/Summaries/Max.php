<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Summaries;

/** The largest value (numbers, or dates as stored); nothing when there are none. */
final class Max extends Summary
{
    public function kind(): string { return 'max'; }

    protected function defaultLabel(): string { return 'totals.max'; }

    public function sql(string $expression): array
    {
        return ['MAX(' . $expression . ')', []];
    }

    public function compute(array $values): int|float|string|null
    {
        return Extremes::pick($values, 1);
    }

    public function fromDatabase(mixed $value): int|float|string|null
    {
        return Extremes::read($value);
    }
}
