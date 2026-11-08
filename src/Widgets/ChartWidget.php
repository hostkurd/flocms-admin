<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Support\AdminLang, Support\Str};

/**
 * Bar, line and donut charts drawn as SVG by the server, with a native
 * accessible data table. A line or bar chart may also show the previous
 * period as a dashed line (0.42): 'previous' => [...] beside 'values'.
 */
abstract class ChartWidget extends Widget
{
    /** 'bar', 'line' or 'donut' (0.42). */
    protected string $type = 'bar';
    /**
     * labels and values, at most 60 points (a donut: 1–8 parts, none below 0).
     * Optional (0.42): 'previous' (as many values, a dashed line), 'label' and
     * 'previousLabel' (the legend), 'total' (a donut's word under its sum, e.g. 'inquiries').
     *
     * @return array{labels: list<string>, values: list<int|float>, previous?: list<int|float>, label?: string, previousLabel?: string, total?: string}
     */
    abstract protected function data(WidgetContext $context): array;
    final public function render(WidgetContext $context, string $id): string
    {
        $data = $this->data($context); $labels = $data['labels'] ?? null; $values = $data['values'] ?? null; $previous = $data['previous'] ?? null;
        if (!in_array($this->type, ['bar', 'line', 'donut'], true) || !is_array($labels) || !is_array($values) || !array_is_list($labels) || !array_is_list($values) || count($labels) !== count($values) || count($values) > 60) { throw new \InvalidArgumentException('Use bar, line or donut charts with matching lists of at most 60 points.'); }
        foreach ($labels as $label) { if (!is_string($label) || strlen($label) > 500) { throw new \InvalidArgumentException('Use bounded text labels.'); } }
        self::check($values);
        if ($previous !== null) {
            if ($this->type === 'donut' || !is_array($previous) || !array_is_list($previous) || count($previous) !== count($values)) { throw new \InvalidArgumentException('previous needs as many values as values (bar and line charts).'); }
            self::check($previous);
        }
        foreach (['label', 'previousLabel', 'total'] as $key) { if (isset($data[$key]) && (!is_string($data[$key]) || strlen($data[$key]) > 200)) { throw new \InvalidArgumentException($key . ' must be a short text.'); } }
        if ($values === []) { return '<p class="fa-muted">' . Str::e(AdminLang::get('widgets.empty')) . '</p>'; }
        $labels = array_map(static fn (string $label): string => AdminLang::label($label), $labels);
        return $this->type === 'donut'
            ? $this->donut($labels, $values, isset($data['total']) ? AdminLang::label($data['total']) : AdminLang::get('widgets.total'), $id)
            : $this->xy($labels, $values, $previous, isset($data['label']) ? AdminLang::label($data['label']) : '', isset($data['previousLabel']) ? AdminLang::label($data['previousLabel']) : AdminLang::get('widgets.previous'), $id);
    }
    /** @param list<mixed> $values */
    private static function check(array $values): void
    {
        foreach ($values as $value) { if ((!is_int($value) && !is_float($value)) || !is_finite((float) $value) || abs($value) > 1e15) { throw new \InvalidArgumentException('Use finite chart values within ±1e15.'); } }
    }
    /**
     * @param list<string> $labels
     * @param list<int|float> $values
     * @param list<int|float>|null $previous
     */
    private function xy(array $labels, array $values, ?array $previous, string $label, string $previousLabel, string $id): string
    {
        $all = $previous === null ? $values : array_merge($values, $previous);
        $minimum = min(0, min($all)); $maximum = max(0, max($all));
        if ($maximum === $minimum) { $maximum = $minimum + 1; }
        // Plot area 0..100 high, 10 units per point. The SVG stretches to the
        // widget's width (preserveAspectRatio="none") while every label is HTML
        // text, so labels keep one size however wide the widget is.
        $y = static fn (float $value): float => round(100 - ($value - $minimum) / ($maximum - $minimum) * 100, 2);
        $n = count($values); $width = $n * 10; $zero = $y(0); $shapes = ''; $points = []; $rows = ''; $xLabels = '';
        $shown = $n <= 8 ? range(0, $n - 1) : array_values(array_unique([0, (int) round(($n - 1) / 4), (int) round(($n - 1) / 2), (int) round(3 * ($n - 1) / 4), $n - 1]));
        foreach ($values as $index => $value) {
            $x = $index * 10 + 5; $top = $y((float) $value); $points[] = [$x, $top];
            if ($this->type === 'bar') { $shapes .= '<rect class="fa-chart-bar" x="' . ($index * 10 + 2) . '" y="' . min($zero, $top) . '" width="6" height="' . max(.5, round(abs($zero - $top), 2)) . '"></rect>'; }
            $rows .= '<tr><th scope="row">' . Str::e($labels[$index]) . '</th><td>' . Str::e(self::number($value)) . '</td>' . ($previous !== null ? '<td>' . Str::e(self::number($previous[$index])) . '</td>' : '') . '</tr>';
            $xLabels .= '<span>' . (in_array($index, $shown, true) ? Str::e(mb_strimwidth($labels[$index], 0, 24, '…')) : '') . '</span>';
        }
        $before = '';
        if ($previous !== null) {
            $prev = [];
            foreach ($previous as $index => $value) { $prev[] = [$index * 10 + 5, $y((float) $value)]; }
            $before = '<path class="fa-chart-previous" d="' . self::curve($prev) . '"></path>';
        }
        if ($this->type === 'line') {
            $line = self::curve($points);
            $shapes = ($n > 1 ? '<path class="fa-chart-area" d="' . $line . ' L' . $points[$n - 1][0] . ',' . $zero . ' L' . $points[0][0] . ',' . $zero . ' Z"></path>' : '')
                . '<path class="fa-chart-line" d="' . $line . '"></path>'
                . '<line class="fa-chart-point" x1="' . $points[$n - 1][0] . '" y1="' . $points[$n - 1][1] . '" x2="' . $points[$n - 1][0] . '" y2="' . $points[$n - 1][1] . '"></line>';
        }
        $grid = '<line class="fa-chart-grid" x1="0" x2="' . $width . '" y1="0" y2="0"></line><line class="fa-chart-grid" x1="0" x2="' . $width . '" y1="50" y2="50"></line>'
            . '<line class="fa-chart-axis" x1="0" x2="' . $width . '" y1="' . $zero . '" y2="' . $zero . '"></line>' . ($zero !== 100.0 ? '<line class="fa-chart-grid" x1="0" x2="' . $width . '" y1="100" y2="100"></line>' : '');
        $svg = '<svg class="fa-widget-chart" viewBox="0 0 ' . $width . ' 100" preserveAspectRatio="none" role="img" aria-labelledby="' . Str::e($id . '-chart-title ' . $id . '-chart-description') . '" direction="ltr"><title id="' . Str::e($id . '-chart-title') . '">' . Str::e($this->title()) . '</title><desc id="' . Str::e($id . '-chart-description') . '">' . Str::e(AdminLang::get('widgets.chart_description')) . '</desc>'
            . $grid . $before . $shapes . '</svg>';
        $legend = $previous === null ? '' : '<div class="fa-chart-legend"><span><i class="fa-legend-key is-current" aria-hidden="true"></i>' . Str::e($label !== '' ? $label : AdminLang::get('widgets.current')) . '</span><span><i class="fa-legend-key is-previous" aria-hidden="true"></i>' . Str::e($previousLabel) . '</span></div>';
        $chart = $legend . '<div class="fa-chart" dir="ltr"><div class="fa-chart-y" aria-hidden="true"><span>' . Str::e(self::number($maximum)) . '</span><span>' . Str::e(self::number(($maximum + $minimum) / 2)) . '</span><span>' . Str::e(self::number($minimum)) . '</span></div>'
            . $svg . '<div class="fa-chart-x" aria-hidden="true">' . $xLabels . '</div></div>';
        $head = '<th scope="col">' . Str::e(AdminLang::get('widgets.label')) . '</th><th scope="col">' . Str::e($label !== '' && $previous !== null ? $label : AdminLang::get('widgets.value')) . '</th>' . ($previous !== null ? '<th scope="col">' . Str::e($previousLabel) . '</th>' : '');
        return $chart . self::table($this->title(), $head, $rows);
    }
    /**
     * @param list<string> $labels
     * @param list<int|float> $values
     */
    private function donut(array $labels, array $values, string $totalLabel, string $id): string
    {
        if (count($values) > 8) { throw new \InvalidArgumentException('A donut takes at most 8 parts.'); }
        foreach ($values as $value) { if ($value < 0) { throw new \InvalidArgumentException('A donut has no values below 0.'); } }
        $sum = array_sum($values);
        $r = 46; $c = 2 * M_PI * $r; $offset = 0.0; $parts = ''; $legend = ''; $rows = '';
        foreach ($values as $index => $value) {
            $share = $sum > 0 ? $value / $sum : 0;
            $length = $share * $c;
            if ($length > 0) {
                $gap = $length > 3 && count(array_filter($values)) > 1 ? 1.5 : 0;
                $parts .= '<circle class="fa-donut-part fa-chart-c' . ($index + 1) . '" cx="60" cy="60" r="' . $r . '" stroke-dasharray="' . round($length - $gap, 2) . ' ' . round($c - $length + $gap, 2) . '" stroke-dashoffset="' . round(-$offset, 2) . '" transform="rotate(-90 60 60)"></circle>';
            }
            $offset += $length;
            $percent = $sum > 0 ? round($share * 100) : 0;
            $legend .= '<li><i class="fa-legend-key fa-chart-c' . ($index + 1) . '" aria-hidden="true"></i><span class="fa-donut-label">' . Str::e($labels[$index]) . '</span><b>' . Str::e(self::number($value)) . '</b><small>' . $percent . '%</small></li>';
            $rows .= '<tr><th scope="row">' . Str::e($labels[$index]) . '</th><td>' . Str::e(self::number($value)) . '</td><td>' . $percent . '%</td></tr>';
        }
        $svg = '<svg class="fa-donut" viewBox="0 0 120 120" role="img" aria-labelledby="' . Str::e($id . '-chart-title ' . $id . '-chart-description') . '"><title id="' . Str::e($id . '-chart-title') . '">' . Str::e($this->title()) . '</title><desc id="' . Str::e($id . '-chart-description') . '">' . Str::e(AdminLang::get('widgets.chart_description')) . '</desc>'
            . '<circle class="fa-donut-track" cx="60" cy="60" r="' . $r . '"></circle>' . $parts
            . '<text class="fa-donut-total" x="60" y="60" text-anchor="middle">' . Str::e(self::number($sum)) . '</text><text class="fa-donut-caption" x="60" y="77" text-anchor="middle">' . Str::e(mb_strimwidth($totalLabel, 0, 18, '…')) . '</text></svg>';
        $head = '<th scope="col">' . Str::e(AdminLang::get('widgets.label')) . '</th><th scope="col">' . Str::e(AdminLang::get('widgets.value')) . '</th><th scope="col">' . Str::e(AdminLang::get('widgets.share')) . '</th>';
        return '<div class="fa-donut-wrap">' . $svg . '<ul class="fa-donut-legend">' . $legend . '</ul></div>' . self::table($this->title(), $head, $rows);
    }
    private static function table(string $title, string $head, string $rows): string
    {
        return '<details class="fa-chart-data"><summary>' . Str::e(AdminLang::get('widgets.chart_data')) . '</summary><div class="fa-table-wrap"><table class="fa-table"><caption class="fa-sr">' . Str::e($title) . '</caption><thead><tr>' . $head . '</tr></thead><tbody>' . $rows . '</tbody></table></div></details>';
    }
    /**
     * @internal A smooth line through the points that never overshoots them (monotone cubic); also the sparklines'.
     *
     * @param list<array{0: int|float, 1: float}> $points
     */
    public static function curve(array $points): string
    {
        $n = count($points);
        if ($n === 1) { return 'M' . $points[0][0] . ',' . $points[0][1]; }
        $slopes = [];
        for ($i = 0; $i < $n - 1; $i++) { $slopes[] = ($points[$i + 1][1] - $points[$i][1]) / ($points[$i + 1][0] - $points[$i][0]); }
        $tangents = [$slopes[0]];
        for ($i = 1; $i < $n - 1; $i++) { $tangents[] = $slopes[$i - 1] * $slopes[$i] <= 0 ? 0.0 : 2 / (1 / $slopes[$i - 1] + 1 / $slopes[$i]); }
        $tangents[] = $slopes[$n - 2];
        $path = 'M' . $points[0][0] . ',' . $points[0][1];
        for ($i = 0; $i < $n - 1; $i++) {
            $h = ($points[$i + 1][0] - $points[$i][0]) / 3;
            $path .= ' C' . round($points[$i][0] + $h, 2) . ',' . round($points[$i][1] + $tangents[$i] * $h, 2) . ' ' . round($points[$i + 1][0] - $h, 2) . ',' . round($points[$i + 1][1] - $tangents[$i + 1] * $h, 2) . ' ' . $points[$i + 1][0] . ',' . $points[$i + 1][1];
        }
        return $path;
    }
    private static function number(int|float $value): string { return number_format($value, floor($value) === (float) $value ? 0 : 2); }
}
