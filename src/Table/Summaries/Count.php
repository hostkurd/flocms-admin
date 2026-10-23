<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Summaries;

/**
 * How many rows have a value (not NULL), or with where() how many hold one
 * value: Count::make()->where(1)->label('Featured').
 */
final class Count extends Summary
{
    private bool $matching = false;
    private string|int|null $value = null;

    public function where(string|int $value): static
    {
        $this->matching = true;
        $this->value = $value;
        return $this;
    }

    public function kind(): string { return 'count'; }

    protected function defaultLabel(): string { return 'totals.count'; }

    public function sql(string $expression): array
    {
        if ($this->matching) { return ['SUM(CASE WHEN ' . $expression . ' = ? THEN 1 ELSE 0 END)', [$this->value]]; }
        return ['COUNT(' . $expression . ')', []];
    }

    public function compute(array $values): int
    {
        $count = 0;
        foreach ($values as $value) {
            if (is_bool($value)) { $value = (int) $value; }
            if ($this->matching ? ($value !== null && (string) $value === (string) $this->value) : $value !== null) { $count++; }
        }
        return $count;
    }

    public function fromDatabase(mixed $value): int
    {
        return (int) (parent::fromDatabase($value) ?? 0);
    }
}
