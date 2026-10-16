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
        // Plot area 0..100 high, 10 units per point. The SVG stretches to the
        // widget's width (preserveAspectRatio="none") while every label is HTML
        // text, so labels keep one size however wide the widget is.
        $y = static fn (float $value): float => round(100 - ($value - $minimum) / ($maximum - $minimum) * 100, 2);
        $n = count($values); $width = $n * 10; $zero = $y(0); $shapes = ''; $points = []; $rows = ''; $xLabels = '';
        $shown = $n <= 8 ? range(0, $n - 1) : array_unique([0, (int) floor(($n - 1) / 2), $n - 1]);
        foreach ($values as $index => $value) {
            $x = $index * 10 + 5; $top = $y((float) $value); $points[] = $x . ',' . $top;
            if ($this->type === 'bar') { $shapes .= '<rect class="fa-chart-bar" x="' . ($index * 10 + 2) . '" y="' . min($zero, $top) . '" width="6" height="' . max(.5, round(abs($zero - $top), 2)) . '"></rect>'; }
            else { $shapes .= '<line class="fa-chart-point" x1="' . $x . '" y1="' . $top . '" x2="' . $x . '" y2="' . $top . '"></line>'; }
            $label = AdminLang::label($labels[$index]);
            $rows .= '<tr><th scope="row">' . Str::e($label) . '</th><td>' . Str::e(self::number($value)) . '</td></tr>';
            $xLabels .= '<span>' . (in_array($index, $shown, true) ? Str::e(mb_strimwidth($label, 0, 24, '…')) : '') . '</span>';
        }
        if ($this->type === 'line') { $shapes = '<polyline class="fa-chart-line" points="' . implode(' ', $points) . '"></polyline>' . $shapes; }
        $grid = '<line class="fa-chart-grid" x1="0" x2="' . $width . '" y1="0" y2="0"></line><line class="fa-chart-grid" x1="0" x2="' . $width . '" y1="50" y2="50"></line>'
            . '<line class="fa-chart-axis" x1="0" x2="' . $width . '" y1="' . $zero . '" y2="' . $zero . '"></line>' . ($zero !== 100.0 ? '<line class="fa-chart-grid" x1="0" x2="' . $width . '" y1="100" y2="100"></line>' : '');
        $svg = '<svg class="fa-widget-chart" viewBox="0 0 ' . $width . ' 100" preserveAspectRatio="none" role="img" aria-labelledby="' . Str::e($id . '-chart-title ' . $id . '-chart-description') . '" direction="ltr"><title id="' . Str::e($id . '-chart-title') . '">' . Str::e($this->title()) . '</title><desc id="' . Str::e($id . '-chart-description') . '">' . Str::e(AdminLang::get('widgets.chart_description')) . '</desc>'
            . $grid . $shapes . '</svg>';
        $chart = '<div class="fa-chart" dir="ltr"><div class="fa-chart-y" aria-hidden="true"><span>' . Str::e(self::number($maximum)) . '</span><span>' . Str::e(self::number(($maximum + $minimum) / 2)) . '</span><span>' . Str::e(self::number($minimum)) . '</span></div>'
            . $svg . '<div class="fa-chart-x" aria-hidden="true">' . $xLabels . '</div></div>';
        return $chart . '<details class="fa-chart-data"><summary>' . Str::e(AdminLang::get('widgets.chart_data')) . '</summary><div class="fa-table-wrap"><table class="fa-table"><caption class="fa-sr">' . Str::e($this->title()) . '</caption><thead><tr><th scope="col">' . Str::e(AdminLang::get('widgets.label')) . '</th><th scope="col">' . Str::e(AdminLang::get('widgets.value')) . '</th></tr></thead><tbody>' . $rows . '</tbody></table></div></details>';
    }
    private static function number(int|float $value): string { return number_format($value, floor($value) === (float) $value ? 0 : 2); }
}
