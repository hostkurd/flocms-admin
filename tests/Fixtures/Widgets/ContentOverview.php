<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Widgets;
use FloCMS\Admin\Widgets\{StatsWidget, Stat, WidgetContext};
final class ContentOverview extends StatsWidget
{
    protected string $title = 'Content overview';
    protected array $resources = [WidgetPostResource::class];
    protected int|string $columnSpan = 'full';
    protected int $sort = -10;
    protected function stats(WidgetContext $context): array
    {
        \FloCMS\Core\Session::set('widget_overview_calls', (int) \FloCMS\Core\Session::get('widget_overview_calls', 0) + 1);
        $posts = $context->query(WidgetPostResource::class);
        return [Stat::make('Total posts', $posts->count())->resource(WidgetPostResource::class),
            Stat::make('Published posts', $posts->where('status', '=', 'published')->count())->color('success'),
            Stat::make('Views', $posts->sum('views'))->description('Featured content only')];
    }
}
