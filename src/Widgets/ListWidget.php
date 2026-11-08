<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

use FloCMS\Admin\{Support\AdminLang, Support\Icons, Support\Str};

/**
 * A list of things to look at (0.42), each with an icon, a title, a line and a
 * number, linking to where it is dealt with: "Needs attention".
 *
 *     protected function items(WidgetContext $context): array
 *     {
 *         $count = $context->query(InquiryResource::class)->where('status', '=', 'new')->whereNull('owner_id')->count();
 *         return [['icon' => 'user-x', 'color' => 'danger', 'title' => 'Inquiries without an owner',
 *                  'description' => 'New and not assigned to anyone', 'value' => $count, 'url' => $context->url('inquiries')]];
 *     }
 *
 * Items whose value is 0 are left out unless $showZero is on; with none left
 * the widget says $empty ("Nothing needs your attention").
 */
abstract class ListWidget extends Widget
{
    protected bool $showZero = false;
    /** The text when the list is empty: plain text or a lang key. */
    protected string $empty = 'widgets.all_clear';
    /**
     * Up to 12 items: title, optional description, value (number or short text),
     * icon (a panel icon), color and url.
     *
     * @return list<array{title: string, description?: string, value?: int|float|string, icon?: string, color?: string, url?: string}>
     */
    abstract protected function items(WidgetContext $context): array;
    final public function render(WidgetContext $context, string $id): string
    {
        $items = Items::check($this->items($context), 12, ['title'], ['description', 'value', 'icon', 'color', 'url']);
        $html = '';
        foreach ($items as $item) {
            $value = $item['value'] ?? null;
            if (!$this->showZero && ($value === 0 || $value === 0.0 || $value === '0')) { continue; }
            if (isset($item['icon']) && !in_array($item['icon'], Icons::names(), true)) { throw new \InvalidArgumentException('Unknown icon: ' . $item['icon']); }
            $tag = isset($item['url']) ? 'a' : 'div';
            $html .= '<li><' . $tag . (isset($item['url']) ? ' href="' . Str::e($item['url']) . '"' : '') . ' class="fa-list-item">'
                . (isset($item['icon']) ? '<span class="fa-list-icon ' . Items::colorClass($item['color'] ?? 'primary') . '" aria-hidden="true">' . Icons::svg($item['icon']) . '</span>' : '')
                . '<span class="fa-list-text"><b dir="auto">' . Str::e(AdminLang::label($item['title'])) . '</b>' . (isset($item['description']) ? '<span dir="auto">' . Str::e(AdminLang::label($item['description'])) . '</span>' : '') . '</span>'
                . ($value !== null ? '<span class="fa-list-value">' . Str::e(is_string($value) ? $value : Items::number($value)) . '</span>' : '')
                . '</' . $tag . '></li>';
        }
        return $html === '' ? '<div class="fa-list-clear">' . Icons::svg('check') . '<p>' . Str::e(Items::text($this->empty)) . '</p></div>' : '<ul class="fa-list">' . $html . '</ul>';
    }
}
