<?php
declare(strict_types=1);
namespace FloCMS\Admin\Widgets;

abstract class StatsWidget extends Widget
{
    /** @return list<Stat> */
    abstract protected function stats(WidgetContext $context): array;
    final public function render(WidgetContext $context, string $id): string
    {
        $stats = $this->stats($context);
        if (count($stats) > 8 || !array_is_list($stats)) { throw new \InvalidArgumentException('Use a list of at most eight statistics.'); }
        $html = ''; foreach ($stats as $stat) { if (!$stat instanceof Stat) { throw new \InvalidArgumentException('StatsWidget expects Stat objects.'); } $html .= $stat->render($context); }
        return $html !== '' ? '<div class="fa-widget-stats">' . $html . '</div>' : '<p class="fa-muted">' . \FloCMS\Admin\Support\Str::e(\FloCMS\Admin\Support\AdminLang::get('widgets.empty')) . '</p>';
    }
}
