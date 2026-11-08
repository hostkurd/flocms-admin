<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Support\AdminLang, Support\Str};

/**
 * Labels with a value and a bar (0.42): properties by status, visitors by
 * language. Bars are measured against the largest value, or against 100 when
 * $percent is on (the values are percentages).
 *
 *     protected function items(WidgetContext $context): array
 *     {
 *         $listings = $context->query(ListingResource::class);
 *         return [
 *             ['label' => 'For sale', 'value' => $listings->where('status', '=', 'for_sale')->count(), 'color' => 'primary'],
 *             ['label' => 'Sold', 'value' => $listings->where('status', '=', 'sold')->count(), 'color' => 'neutral'],
 *         ];
 *     }
 */
abstract class BarListWidget extends Widget
{
    /** The values are percentages: bars against 100, shown with "%". */
    protected bool $percent = false;
    /**
     * Up to 12 items: label, value (0 or more), optional color (primary, success,
     * warning, danger, neutral, or chart1–chart6) and url (a page of this site).
     *
     * @return list<array{label: string, value: int|float, color?: string, url?: string}>
     */
    abstract protected function items(WidgetContext $context): array;
    /** A line under the bars, e.g. "35 properties in total"; '' for none. */
    protected function footer(WidgetContext $context): string { return ''; }
    final public function render(WidgetContext $context, string $id): string
    {
        $items = Items::check($this->items($context), 12, ['label', 'value'], ['color', 'url']);
        foreach ($items as $item) { if (is_string($item['value'])) { throw new \InvalidArgumentException('Values are numbers.'); } }
        if ($items === []) { return '<p class="fa-muted">' . Str::e(AdminLang::get('widgets.empty')) . '</p>'; }
        $max = $this->percent ? 100 : max(1, max(array_map(static fn (array $item): float => (float) $item['value'], $items)));
        $html = '';
        foreach ($items as $item) {
            if ($item['value'] < 0) { throw new \InvalidArgumentException('Bar values are 0 or more.'); }
            $width = round(min(100, $item['value'] / $max * 100), 2);
            $value = Items::number($item['value']) . ($this->percent ? '%' : '');
            $label = Str::e(AdminLang::label($item['label']));
            $html .= '<li class="fa-barlist-row">' . (isset($item['url']) ? '<a href="' . Str::e($item['url']) . '">' . $label . '</a>' : '<span>' . $label . '</span>')
                . '<svg class="fa-barlist-bar" viewBox="0 0 100 8" preserveAspectRatio="none" aria-hidden="true" focusable="false"><rect class="fa-barlist-track" width="100" height="8"></rect>'
                . ($width > 0 ? '<rect class="fa-barlist-fill ' . Items::colorClass($item['color'] ?? 'primary') . '" width="' . $width . '" height="8"></rect>' : '') . '</svg>'
                . '<b>' . Str::e($value) . '</b></li>';
        }
        $footer = $this->footer($context);
        return '<ul class="fa-barlist">' . $html . '</ul>' . ($footer !== '' ? '<p class="fa-widget-foot">' . Str::e(AdminLang::label($footer)) . '</p>' : '');
    }
}
