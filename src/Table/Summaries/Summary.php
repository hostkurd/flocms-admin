<?php
declare(strict_types=1);

namespace FloCMS\Admin\Table\Summaries;

use Closure;
use FloCMS\Admin\Support\AdminLang;
use FloCMS\Admin\Support\Numbers;
use FloCMS\Admin\Support\Str;

/**
 * One total under a list column (0.38): Sum, Average, Count, Min or Max of
 * the rows on this page and of all the rows the search and filters match.
 *
 *     TextColumn::make('price')->summarize([Sum::make()->prefix('€ '), Average::make()->decimals(0)])
 */
abstract class Summary
{
    protected ?string $label = null;
    protected ?int $decimals = null;
    protected string $prefix = '';
    protected string $suffix = '';
    protected ?Closure $formatter = null;

    final public function __construct()
    {
    }

    public static function make(): static
    {
        return new static();
    }

    /** The word before the value ("Total"); a lang key or text. */
    public function label(string $label): static
    {
        $this->label = $label;
        return $this;
    }

    /** Digits after the decimal point (default: as the values have them, Average 2). */
    public function decimals(int $decimals): static
    {
        $this->decimals = max(0, min(6, $decimals));
        return $this;
    }

    /** Text before the number, e.g. a currency: '€ '. */
    public function prefix(string $prefix): static
    {
        $this->prefix = $prefix;
        return $this;
    }

    /** Text after the number, e.g. ' m²'. */
    public function suffix(string $suffix): static
    {
        $this->suffix = $suffix;
        return $this;
    }

    /** Your own text for the value: fn (int|float|string|null $value): string (escaped). */
    public function formatUsing(Closure $formatter): static
    {
        $this->formatter = $formatter;
        return $this;
    }

    /** sum, avg, count, min or max. */
    abstract public function kind(): string;

    /** The lang key of the default label. */
    abstract protected function defaultLabel(): string;

    /**
     * The SQL aggregate over $expression (a column of the list's query) and
     * its bindings.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    abstract public function sql(string $expression): array;

    /**
     * The same total in PHP, over the values of the rows on this page.
     *
     * @param list<mixed> $values
     */
    abstract public function compute(array $values): int|float|string|null;

    /** A database result as the value to show (null: nothing to show). */
    public function fromDatabase(mixed $value): int|float|string|null
    {
        return Numbers::normalize($value);
    }

    public function getLabel(): string
    {
        return $this->label !== null ? AdminLang::label($this->label) : AdminLang::get($this->defaultLabel());
    }

    /** The value as the totals row shows it ('' for nothing). */
    public function format(int|float|string|null $value): string
    {
        if ($this->formatter !== null) {
            $text = ($this->formatter)($value);
            return is_scalar($text) ? (string) $text : '';
        }
        if ($value === null) { return ''; }
        if (!Numbers::isNumber($value)) { return AdminLang::label($this->prefix) . (string) $value . AdminLang::label($this->suffix); }
        return Numbers::format($value, $this->decimals ?? $this->defaultDecimals($value), AdminLang::label($this->prefix), AdminLang::label($this->suffix));
    }

    protected function defaultDecimals(int|float|string $value): ?int
    {
        return null;
    }

    /** One line of the totals cell: "Total € 4,750,000" (a dash when there is nothing). */
    public function html(int|float|string|null $value, ?string $column = null): string
    {
        $text = $this->format($value);
        // On phones the totals stack in a card: each line says its column too.
        $prefix = $column !== null && $column !== $this->getLabel() ? '<span class="fa-total-column">' . Str::e(AdminLang::get('totals.of_column', ['column' => $column])) . '</span>' : '';
        return '<span class="fa-total"><span class="fa-total-label">' . $prefix . Str::e($this->getLabel()) . '</span> '
            . ($text === '' ? '<span class="fa-total-value fa-muted">&ndash;</span>' : '<span class="fa-total-value" dir="ltr">' . Str::e($text) . '</span>') . '</span>';
    }

    /** @param list<mixed> $values @return list<int|float> */
    protected static function numbers(array $values): array
    {
        $numbers = [];
        foreach ($values as $value) {
            if (is_bool($value)) { $value = (int) $value; }
            $number = Numbers::normalize($value);
            if ($number !== null) { $numbers[] = $number; }
        }
        return $numbers;
    }
}
