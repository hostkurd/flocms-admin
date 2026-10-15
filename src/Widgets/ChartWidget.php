<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Support\AdminLang, Support\Str};

/** Single series bar/line charts, with a native accessible data table. */
abstract class ChartWidget extends Widget
{
    protected string $type = 'bar';
    /** @return array{labels:list<string>, values:list<int|float>} At most 60 finite points. */
    abstract protected function data(WidgetContext $context): array;
    final public function render(WidgetContext $context, string $id): string
    {
        $data = $this->data($context); $labels = $data['labels'] ?? null; $values = $data['values'] ?? null;
        if (!in_array($this->type, ['bar','line'], true) || !is_array($labels) || !is_array($values) || !array_is_list($labels) || !array_is_list($values) || count($labels) !== count($values) || count($values) > 60) { throw new \InvalidArgumentException('Use bar/line charts with matching lists of at most 60 points.'); }
        foreach ($labels as $label) { if (!is_string($label) || strlen($label) > 500) { throw new \InvalidArgumentException('Use bounded text labels.'); } }
        foreach ($values as $value) { if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 1e15) { throw new \InvalidArgumentException('Use finite chart values within ±1e15.'); } }
        if ($values === []) { return '<p class="fa-muted">' . Str::e(AdminLang::get('widgets.empty')) . '</p>'; }
        $minimum = min(0, min($values)); $maximum = max(0, max($values));
        if ($maximum === $minimum) { $maximum = $minimum + 1; }
        $y = static fn (float $value): float => 200 - ($value - $minimum) / ($maximum - $minimum) * 170;
        $n = count($values); $step = 540 / $n; $zero = $y(0); $shapes = ''; $points = []; $rows = '';
        foreach ($values as $index => $value) {
            $x = 65 + ($index + .5) * $step; $top = $y((float) $value); $points[] = round($x, 2) . ',' . round($top, 2);
            if ($this->type === 'bar') { $shapes .= '<rect class="fa-chart-bar" x="' . round($x - $step * .32, 2) . '" y="' . round(min($zero, $top), 2) . '" width="' . round($step * .64, 2) . '" height="' . round(max(.5, abs($zero - $top)), 2) . '"></rect>'; }
            else { $shapes .= '<circle class="fa-chart-point" cx="' . round($x, 2) . '" cy="' . round($top, 2) . '" r="3"></circle>'; }
            $rows .= '<tr><th scope="row">' . Str::e(AdminLang::label($labels[$index])) . '</th><td>' . Str::e(self::number($value)) . '</td></tr>';
        }
        if ($this->type === 'line') { $shapes = '<polyline class="fa-chart-line" points="' . implode(' ', $points) . '"></polyline>' . $shapes; }
        $ticks = '';
        foreach (array_unique([0, (int) floor(($n - 1) / 2), $n - 1]) as $index) { $ticks .= '<text class="fa-chart-label" x="' . round(65 + ($index + .5) * $step, 2) . '" y="225" text-anchor="middle">' . Str::e(mb_strimwidth(AdminLang::label($labels[$index]), 0, 24, '…')) . '</text>'; }
        $svg = '<svg class="fa-widget-chart" viewBox="0 0 640 245" role="img" aria-labelledby="' . Str::e($id . '-chart-title ' . $id . '-chart-description') . '" direction="ltr"><title id="' . Str::e($id . '-chart-title') . '">' . Str::e($this->title()) . '</title><desc id="' . Str::e($id . '-chart-description') . '">' . Str::e(AdminLang::get('widgets.chart_description')) . '</desc>'
            . '<line class="fa-chart-axis" x1="60" x2="610" y1="' . round($zero, 2) . '" y2="' . round($zero, 2) . '"></line>' . $shapes . $ticks
            . '<text class="fa-chart-label" x="55" y="24" text-anchor="end">' . Str::e(self::number($maximum)) . '</text><text class="fa-chart-label" x="55" y="205" text-anchor="end">' . Str::e(self::number($minimum)) . '</text></svg>';
        return $svg . '<details class="fa-chart-data"><summary>' . Str::e(AdminLang::get('widgets.chart_data')) . '</summary><div class="fa-table-wrap"><table class="fa-table"><caption class="fa-sr">' . Str::e($this->title()) . '</caption><thead><tr><th scope="col">' . Str::e(AdminLang::get('widgets.label')) . '</th><th scope="col">' . Str::e(AdminLang::get('widgets.value')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></details>';
    }
    private static function number(int|float $value): string { return number_format($value, floor($value) === (float) $value ? 0 : 2); }
}
