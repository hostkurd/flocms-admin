<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Support\AdminLang, Support\Icons, Support\Str};

final class Stat
{
    private ?string $description = null;
    private string $color = 'primary';
    private ?string $resource = null;
    private ?string $icon = null;
    private ?string $descriptionColor = null;
    /** @var array{0: int|float, 1: int|float}|null */
    private ?array $trend = null;
    private int|float|null $change = null;
    private bool $invert = false;
    /** @var list<int|float> */
    private array $sparkline = [];
    private ?string $url = null;
    private function __construct(private readonly string $label, private readonly string|int|float $value)
    {
        if ((is_float($value) && !is_finite($value)) || strlen((string) $value) > 256 || strlen($label) > 500) { throw new \InvalidArgumentException('Use finite, bounded statistic values and labels.'); }
    }
    public static function make(string $label, string|int|float $value): self { return new self($label, $value); }
    public function description(string $text): self { if (strlen($text) > 1000) { throw new \InvalidArgumentException('Statistic description is too long.'); } $this->description = $text; return $this; }
    public function color(string $color): self { $this->color = self::checked($color); return $this; }
    public function resource(string $class): self { $this->resource = $class; return $this; }
    /** An icon beside the label (0.42), one of the panel's icons; it takes the stat's colour. */
    public function icon(string $name): self
    {
        if (!in_array($name, Icons::names(), true)) { throw new \InvalidArgumentException('Unknown statistic icon: ' . $name); }
        $this->icon = $name; return $this;
    }
    /** The description in a colour (0.42), e.g. 'warning' for "2 waiting more than 48 hours". */
    public function descriptionColor(string $color): self { $this->descriptionColor = self::checked($color); return $this; }
    /**
     * The change against an earlier value as a percentage (0.42): ▲ 23% green when
     * it went up, ▼ red when down (invertTrend() swaps the colours, for costs or
     * waiting times). Nothing shows when $before is 0.
     */
    public function trend(int|float $now, int|float $before): self
    {
        if (!is_finite((float) $now) || !is_finite((float) $before)) { throw new \InvalidArgumentException('Use finite trend values.'); }
        $this->trend = [$now, $before]; return $this;
    }
    /** The change as a number (0.42): ▲ 4, ▼ 2. */
    public function change(int|float $delta): self
    {
        if (!is_finite((float) $delta)) { throw new \InvalidArgumentException('Use a finite change.'); }
        $this->change = $delta; return $this;
    }
    /** Going down is good (0.42): ▼ shows green, ▲ red. */
    public function invertTrend(bool $invert = true): self { $this->invert = $invert; return $this; }
    /**
     * A small line of the last values (0.42), oldest first, 2–30 finite numbers.
     *
     * @param list<int|float> $values
     */
    public function sparkline(array $values): self
    {
        if (!array_is_list($values) || count($values) > 60) { throw new \InvalidArgumentException('A sparkline takes a list of at most 60 values.'); }
        foreach ($values as $value) { if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value)) { throw new \InvalidArgumentException('Use finite sparkline values.'); } }
        $this->sparkline = $values; return $this;
    }
    /** A link to any panel page (0.42), for example the list already filtered; resource() links to the list. */
    public function url(string $url): self
    {
        if (!Dashboard::safeUrl($url)) { throw new \InvalidArgumentException('A statistic links to a page of this site.'); }
        $this->url = $url; return $this;
    }
    public function render(WidgetContext $context, bool $card = false): string
    {
        $href = $this->url ?? ($this->resource === null ? null : $context->resourceUrl($this->resource));
        $tag = $href === null ? 'div' : 'a';
        $value = is_string($this->value) ? $this->value : number_format($this->value, is_float($this->value) && floor($this->value) !== $this->value ? 2 : 0);
        $icon = $this->icon === null ? '' : '<span class="fa-stat-chip" aria-hidden="true">' . Icons::svg($this->icon) . '</span>';
        return '<' . $tag . ($href === null ? '' : ' href="' . Str::e($href) . '"') . ' class="fa-widget-stat fa-widget-' . $this->color . ($card ? ' fa-card' : '') . ($this->icon !== null ? ' has-icon' : '') . '">'
            . '<span class="fa-stat-label">' . $icon . '<span>' . Str::e(AdminLang::label($this->label)) . '</span></span>'
            . '<span class="fa-stat-line"><span class="fa-stat-value" dir="auto">' . Str::e($value) . '</span>' . $this->trendHtml() . '</span>'
            . ($this->description === null ? '' : '<span dir="auto" class="fa-help' . ($this->descriptionColor !== null ? ' fa-stat-note-' . $this->descriptionColor : '') . '">' . Str::e(AdminLang::label($this->description)) . '</span>')
            . $this->sparklineHtml() . '</' . $tag . '>';
    }
    private function trendHtml(): string
    {
        if ($this->trend !== null) {
            [$now, $before] = $this->trend;
            if ((float) $before === 0.0) { return ''; }
            $delta = ($now - $before) / abs($before) * 100;
            $text = number_format(abs($delta), abs($delta) < 10 && floor(abs($delta)) !== abs($delta) ? 1 : 0) . '%';
        } elseif ($this->change !== null) {
            $delta = $this->change;
            $text = number_format(abs($delta), is_float($delta) && floor(abs($delta)) !== abs($delta) ? 1 : 0);
        } else {
            return '';
        }
        if ($delta == 0) { return '<span class="fa-trend is-flat">' . Str::e(AdminLang::get('widgets.trend_same')) . '</span>'; }
        $up = $delta > 0;
        $good = $this->invert ? !$up : $up;
        $words = AdminLang::get($up ? 'widgets.trend_up' : 'widgets.trend_down', ['value' => $text]);
        return '<span class="fa-trend ' . ($good ? 'is-good' : 'is-bad') . '" title="' . Str::e($words) . '"><span class="fa-sr">' . Str::e($words) . '</span>'
            . '<span aria-hidden="true">' . Icons::svg($up ? 'chevron-up' : 'chevron-down', 'fa-icon fa-icon-sm') . Str::e($text) . '</span></span>';
    }
    private function sparklineHtml(): string
    {
        $n = count($this->sparkline);
        if ($n < 2) { return ''; }
        $min = min($this->sparkline); $max = max($this->sparkline); $range = $max - $min ?: 1;
        $points = [];
        foreach ($this->sparkline as $i => $v) { $points[] = [round($i * 100 / ($n - 1), 2), round(28 - ($v - $min) / $range * 24, 2)]; }
        return '<svg class="fa-sparkline" viewBox="0 0 100 30" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path d="' . ChartWidget::curve($points) . '"></path></svg>';
    }
    private static function checked(string $color): string
    {
        if (!in_array($color, ['primary','success','warning','danger','neutral'], true)) { throw new \InvalidArgumentException('Unknown statistic color.'); }
        return $color;
    }
}
