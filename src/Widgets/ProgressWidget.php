<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Support\AdminLang, Support\Str};

/**
 * Items with a percentage and a progress bar (0.42): projects, goals.
 *
 *     protected function items(WidgetContext $context): array
 *     {
 *         return array_map(static fn ($p): array => [
 *             'label' => $p->title, 'value' => (int) $p->progress, 'meta' => $p->city,
 *             'description' => 'Completion expected ' . $p->expected,
 *         ], $context->query(ProjectResource::class)->records(['title', 'progress', 'city', 'expected'], 'sort', 'asc', 6));
 *     }
 */
abstract class ProgressWidget extends Widget
{
    /**
     * Up to 12 items: label, value (0–100), optional meta (beside the label),
     * description (under the bar), color and url.
     *
     * @return list<array{label: string, value: int|float, meta?: string, description?: string, color?: string, url?: string}>
     */
    abstract protected function items(WidgetContext $context): array;
    final public function render(WidgetContext $context, string $id): string
    {
        $items = Items::check($this->items($context), 12, ['label', 'value'], ['meta', 'description', 'color', 'url']);
        foreach ($items as $item) { if (is_string($item['value'])) { throw new \InvalidArgumentException('Values are numbers.'); } }
        if ($items === []) { return '<p class="fa-muted">' . Str::e(AdminLang::get('widgets.empty')) . '</p>'; }
        $html = '';
        foreach ($items as $item) {
            $value = max(0, min(100, (float) $item['value']));
            $label = '<b dir="auto">' . Str::e(AdminLang::label($item['label'])) . '</b>';
            $html .= '<li class="fa-progress-item"><span class="fa-progress-top">' . (isset($item['url']) ? '<a href="' . Str::e($item['url']) . '">' . $label . '</a>' : $label)
                . (isset($item['meta']) ? '<span class="fa-muted" dir="auto">' . Str::e(AdminLang::label($item['meta'])) . '</span>' : '')
                . '<em>' . Str::e(Items::number($value)) . '%</em></span>'
                . '<svg class="fa-progress-bar" viewBox="0 0 100 8" preserveAspectRatio="none" role="img" aria-label="' . Str::e(Items::number($value)) . '%"><rect class="fa-barlist-track" width="100" height="8"></rect>'
                . ($value > 0 ? '<rect class="fa-barlist-fill ' . Items::colorClass($item['color'] ?? 'primary') . '" width="' . round($value, 2) . '" height="8"></rect>' : '') . '</svg>'
                . (isset($item['description']) ? '<small class="fa-muted" dir="auto">' . Str::e(AdminLang::label($item['description'])) . '</small>' : '') . '</li>';
        }
        return '<ul class="fa-progress-list">' . $html . '</ul>';
    }
}
