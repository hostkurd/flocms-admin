<?php
declare(strict_types=1);
namespace FloCMS\Admin\Tests\Fixtures\Dashboard;
use FloCMS\Admin\Tests\Fixtures\Widgets\WidgetPostResource;
use FloCMS\Admin\Widgets\{StatsWidget, Stat, WidgetContext};
/** Headline numbers as separate cards: icon, trend, change, sparkline, coloured note (0.42). */
final class Headline extends StatsWidget
{
    protected string $title = 'At a glance';
    protected array $resources = [WidgetPostResource::class];
    protected int|string $columnSpan = 'full';
    protected bool $separate = true;
    protected int $sort = -20;
    protected function stats(WidgetContext $context): array
    {
        $posts = $context->query(WidgetPostResource::class);
        $period = $context->period();
        $now = $posts->where('created_at', '>=', $period->from())->count();
        $before = $posts->where('created_at', '>=', $period->previous()->from())->where('created_at', '<', $period->from())->count();
        $drafts = $posts->where('status', '=', 'draft')->count();
        return [
            Stat::make('Drafts', $drafts)->icon('pencil')->color('danger')->description($drafts . ' waiting for review')->descriptionColor('warning')->url($context->url('widget-posts', ['status' => 'draft']))->sparkline([1, 0, 2, 1, 3, 2, $drafts]),
            Stat::make('New posts', $now)->icon('file')->trend($now, $before)->description('vs ' . $before . ' in the previous period')->sparkline(array_values($period->series($posts->countByDay('created_at', $period->from(), $period->to()))['values'])),
            Stat::make('Published', $posts->where('status', '=', 'published')->count())->icon('check')->color('success')->change(2)->description('Live on the website'),
            Stat::make('Views', $posts->sum('views'))->icon('eye')->color('warning')->trend(80, 100)->invertTrend()->description('Fewer is better here'),
        ];
    }
}
