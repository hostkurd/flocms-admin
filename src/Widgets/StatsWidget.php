<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

abstract class StatsWidget extends Widget
{
    /**
     * Each statistic as a card of its own (0.42), without the widget's frame or a
     * visible title: the row of headline numbers at the top of a dashboard.
     */
    protected bool $separate = false;
    /** @return list<Stat> */
    abstract protected function stats(WidgetContext $context): array;
    final public function separate(): bool { return $this->separate; }
    final public function render(WidgetContext $context, string $id): string
    {
        $stats = $this->stats($context);
        if (count($stats) > 8 || !array_is_list($stats)) { throw new \InvalidArgumentException('Use a list of at most eight statistics.'); }
        $html = ''; foreach ($stats as $stat) { if (!$stat instanceof Stat) { throw new \InvalidArgumentException('StatsWidget expects Stat objects.'); } $html .= $stat->render($context, $this->separate); }
        return $html !== '' ? '<div class="fa-widget-stats' . ($this->separate ? ' fa-widget-stats-' . min(4, count($stats)) : '') . '">' . $html . '</div>' : '<p class="fa-muted">' . \FloCMS\Admin\Support\Str::e(\FloCMS\Admin\Support\AdminLang::get('widgets.empty')) . '</p>';
    }
}
