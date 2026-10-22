<?php
declare(strict_types=1);

namespace FloCMS\Admin\Tests\Fixtures\PanelPages;

use FloCMS\Admin\Widgets\Stat;
use FloCMS\Admin\Widgets\StatsWidget;
use FloCMS\Admin\Widgets\WidgetContext;

/** Shows the page's status filter. */
final class PageStats extends StatsWidget
{
    protected string $title = 'Page stats';

    protected function stats(WidgetContext $context): array
    {
        return [Stat::make('Status filter', 'filter:' . ($this->pageFilters()['status'] ?? 'all'))];
    }
}
